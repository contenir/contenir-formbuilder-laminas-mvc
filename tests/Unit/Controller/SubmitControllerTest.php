<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\SubmissionResult;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Form\Form;
use Laminas\Http\Header\Location;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SubmitControllerTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ACCEPT']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function referrerProvider(): array
    {
        return [
            'plain referrer'           => [
                'https://site.example/contact',
                'contact-form',
                'https://site.example/contact?submit=contact#contact-form',
            ],
            'existing submit replaced' => [
                'https://site.example/p?a=1&submit=old#top',
                '',
                'https://site.example/p?a=1&submit=contact',
            ],
            'local path'               => ['/p', '', '/p?submit=contact'],
            'unsafe anchor dropped'    => ['/p', '1bad/../x', '/p?submit=contact'],
            'no referrer'              => ['', '', '/?submit=contact'],
            'host matched case-blind'  => ['https://SITE.example/p', '', 'https://site.example/p?submit=contact'],
            'same host, explicit port' => [
                'https://site.example:443/p',
                '',
                'https://site.example:443/p?submit=contact',
            ],
            'other host'               => ['https://evil.example/p', '', '/?submit=contact'],
            'lookalike host'           => ['https://site.example.evil.example/p', '', '/?submit=contact'],
            'protocol-relative'        => ['//evil.example/p', '', '/?submit=contact'],
            'backslash path'           => ['/\\evil.example/p', '', '/?submit=contact'],
            'javascript scheme'        => ['javascript:alert(1)', '', '/?submit=contact'],
            'same host, other port'    => ['https://site.example:8443/p', '', '/?submit=contact'],
            'control character'        => ["/p\r\nLocation: https://evil.example", '', '/?submit=contact'],
            'tab in path'              => ["/\t/evil.example/p", '', '/?submit=contact'],
            'unparseable URL'          => ['http:///p', '', '/?submit=contact'],
        ];
    }

    #[DataProvider('referrerProvider')]
    public function testRedirectsOnlyToASameSiteReferrer(string $referer, string $anchor, string $expected): void
    {
        if ($referer !== '') {
            $_SERVER['HTTP_REFERER'] = $referer;
        }

        $location = $this->submit('https://site.example/forms/submit/contact', $anchor);

        self::assertSame($expected, $location);
    }

    public function testAbsoluteReferrerIsRefusedWhenTheRequestHostIsUnknown(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://site.example/p';

        self::assertSame('/?submit=contact', $this->submit('/forms/submit/contact', ''));
    }

    public function testFailedSubmissionAlsoRedirectsOnlyToASameSiteReferrer(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/p';

        self::assertSame('/', $this->submit('https://site.example/forms/submit/contact', '', false));
    }

    /**
     * Post a submission to the controller and return the redirect target.
     */
    private function submit(string $requestUri, string $anchor, bool $valid = true): string
    {
        $loader = $this->createStub(LaminasDbFormLoader::class);
        $loader->method('loadBySlug')->willReturn(new FormDefinition(id: 1, slug: 'contact', title: 'Contact'));
        $service = $this->createStub(FormSubmissionService::class);
        $service->method('submit')->willReturn(new SubmissionResult($valid, new Form()));

        $controller = new SubmitController(
            $loader,
            $service,
            $this->createStub(FormStateStash::class),
            new TokenReplacer(),
        );
        $controller->setPluginManager(new PluginManager(new ServiceManager()));
        $event = new MvcEvent();
        $event->setRouteMatch(new RouteMatch(['action' => 'submit', 'slug' => 'contact']));
        $controller->setEvent($event);

        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setUri($requestUri);
        $request->setPost(new Parameters($anchor === '' ? [] : ['_anchor' => $anchor]));
        $response = new Response();

        $controller->dispatch($request, $response);

        $location = $response->getHeaders()->get('Location');
        self::assertInstanceOf(Location::class, $location);

        return $location->getUri();
    }
}
