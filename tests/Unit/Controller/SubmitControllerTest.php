<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\SubmissionResult;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Form\Form;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\Session\Container;
use Laminas\Stdlib\Parameters;
use Laminas\Stdlib\Request as StdlibRequest;
use Laminas\Stdlib\Response as StdlibResponse;
use Laminas\View\Model\JsonModel;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dispatches the controller with a stubbed loader and submission service.
 */
#[Group('unit')]
#[Group('handler')]
final class SubmitControllerTest extends TestCase
{
    use InMemorySessionTrait;

    private const array ACCEPT_JSON = ['HTTP_ACCEPT' => 'application/json'];

    private FormStateStash $stash;

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function jsonSuccessProvider(): array
    {
        return [
            'referrer mode'       => [[], ['ok' => true, 'mode' => 'redirect_referrer']],
            'unknown mode'        => [
                ['success' => ['mode' => 'teleport']],
                ['ok' => true, 'mode' => 'redirect_referrer'],
            ],
            'settings not array'  => [['success' => 'x'], ['ok' => true, 'mode' => 'redirect_referrer']],
            'redirect url'        => [
                ['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks/{field:name}?e={entry:id}']],
                ['ok' => true, 'mode' => 'redirect_url', 'url' => '/thanks/Ann%20Lee?e=42'],
            ],
            'redirect url empty'  => [
                ['success' => ['mode' => 'redirect_url', 'redirect_url' => '']],
                ['ok' => true, 'mode' => 'redirect_url'],
            ],
            'inline message'      => [
                ['success' => [
                    'mode'    => 'inline_message',
                    'title'   => 'Thanks {field:name}',
                    'message' => 'Entry {entry:id}',
                ]],
                ['ok' => true, 'mode' => 'inline_message', 'title' => 'Thanks Ann Lee', 'message' => 'Entry 42'],
            ],
            'inline without text' => [
                ['success' => ['mode' => 'inline_message', 'title' => 5]],
                ['ok' => true, 'mode' => 'inline_message', 'title' => '', 'message' => ''],
            ],
        ];
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
            'unsafe anchor dropped'    => ['/p', '1bad/../x', '/p?submit=contact'],
            'no referrer'              => ['', '', '/?submit=contact'],
        ];
    }

    #[Test]
    public function attachesTheGivenObserversToTheService(): void
    {
        $observer = new RecordingObserver();
        $service  = $this->createMock(FormSubmissionService::class);
        $service->expects($this->once())->method('attach')->with($observer);

        new SubmitController(
            $this->loaderReturning($this->form()),
            $service,
            $this->stash,
            new TokenReplacer(),
            [$observer],
        );
    }

    #[Test]
    public function invalidJsonSubmissionAnswers422WithErrors(): void
    {
        $service = $this->serviceReturning($this->submission(
            valid: false,
            errors: ['name' => ['isEmpty' => 'Required']],
        ));

        [$result, $response] = $this->dispatch(
            $this->controller(service: $service),
            $this->request(server: self::ACCEPT_JSON),
        );

        static::assertSame(
            [422, ['ok' => false, 'errors' => ['name' => ['isEmpty' => 'Required']]]],
            [$response->getStatusCode(), $this->json($result)],
        );
    }

    #[Test]
    public function invalidSubmissionStashesValuesAndRedirectsBack(): void
    {
        $service = $this->serviceReturning($this->submission(
            valid: false,
            errors: ['name' => ['isEmpty' => 'Required']],
        ));
        $request = $this->request(
            post: ['name' => '', '_anchor' => 'contact-form'],
            server: ['HTTP_REFERER' => '/contact?submit=old#x'],
        );

        [$result] = $this->dispatch($this->controller(service: $service), $request);

        static::assertSame('/contact#contact-form', $this->location($result));
        static::assertSame(
            ['values' => ['name' => ''], 'errors' => ['name' => ['isEmpty' => 'Required']]],
            $this->stash->consume('contact'),
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('jsonSuccessProvider')]
    public function jsonSuccessDescribesTheConfiguredMode(array $settings, array $expected): void
    {
        $controller = $this->controller(
            loader: $this->loaderReturning($this->form($settings)),
            service: $this->serviceReturning($this->submission(
                valid: true,
                entryId: 42,
            )),
        );

        [$result, $response] = $this->dispatch($controller, $this->request(server: self::ACCEPT_JSON));

        static::assertSame([200, $expected], [$response->getStatusCode(), $this->json($result)]);
    }

    #[Test]
    public function passesPostFilesAndRequestContextToTheService(): void
    {
        $service = $this->createMock(FormSubmissionService::class);
        $service->expects($this->once())
            ->method('submit')
            ->with(
                $this->isInstanceOf(FormDefinition::class),
                ['name' => 'Ann', '_anchor' => ['x']],
                ['cv' => ['name' => 'cv.pdf']],
                [
                    'ip'      => '10.0.0.1',
                    'user_id' => null,
                    'meta'    => ['user_agent' => 'UA/1', 'referer' => '/contact'],
                ],
            )
            ->willReturn($this->submission(valid: true));
        $request = $this->request(
            post: ['name' => 'Ann', '_anchor' => ['x']],
            server: [
                'REMOTE_ADDR'     => '10.0.0.1',
                'HTTP_USER_AGENT' => 'UA/1',
                'HTTP_REFERER'    => '/contact',
            ],
        );
        $request->setFiles(new Parameters(['cv' => ['name' => 'cv.pdf']]));

        [$result] = $this->dispatch($this->controller(service: $service), $request);

        static::assertInstanceOf(Response::class, $result);
    }

    #[Test]
    public function plainHttpRequestsAndNonScalarServerValuesUseDefaults(): void
    {
        $request = new HttpRequest();
        $request->setMethod('POST');

        [$result] = $this->dispatch($this->controller(), $request);
        $odd = $this->request(server: ['HTTP_REFERER' => ['x']]);
        [$second] = $this->dispatch($this->controller(), $odd);

        static::assertSame(['/?submit=contact', '/?submit=contact'], [
            $this->location($result),
            $this->location($second),
        ]);
    }

    #[Test]
    public function redirectUrlModeRedirectsToTheExpandedUrl(): void
    {
        $form = $this->form(['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks?n={field:name}']]);

        [$result] = $this->dispatch(
            $this->controller(
                loader: $this->loaderReturning($form),
                service: $this->serviceReturning($this->submission(valid: true)),
            ),
            $this->request(),
        );

        static::assertSame('/thanks?n=Ann%20Lee', $this->location($result));
    }

    #[Test]
    public function rejectsAMissingSlug(): void
    {
        [$result, $response] = $this->dispatch($this->controller(), $this->request(), slug: null);

        static::assertSame([400, ['error' => 'Missing slug']], [$response->getStatusCode(), $this->json($result)]);
    }

    #[Test]
    public function rejectsAnUnknownForm(): void
    {
        $loader = $this->createStub(LaminasDbFormLoader::class);
        $loader->method('loadBySlug')->willReturn(null);

        [$result, $response] = $this->dispatch($this->controller(loader: $loader), $this->request());

        static::assertSame([404, ['error' => 'Form not found']], [$response->getStatusCode(), $this->json($result)]);
    }

    #[Test]
    public function rejectsNonHttpRequests(): void
    {
        $controller = $this->controller();
        $event      = new MvcEvent();
        $event->setRouteMatch(new RouteMatch(['action' => 'submit']));
        $controller->setEvent($event);

        $result = $controller->dispatch(new StdlibRequest(), new StdlibResponse());

        static::assertSame(['error' => 'Method not allowed'], $this->json($result));
    }

    #[Test]
    public function rejectsRequestsThatAreNotPosts(): void
    {
        [$result, $response] = $this->dispatch($this->controller(), $this->request(method: 'GET'));

        static::assertSame([405, ['error' => 'Method not allowed']], [
            $response->getStatusCode(),
            $this->json($result),
        ]);
    }

    #[Test]
    public function spamIsAnsweredLikeASuccess(): void
    {
        $service = $this->serviceReturning($this->submission(
            valid: false,
            spam: true,
        ));

        [$result] = $this->dispatch(
            $this->controller(service: $service),
            $this->request(server: ['HTTP_REFERER' => '/c']),
        );

        static::assertSame('/c?submit=contact', $this->location($result));
    }

    #[Test]
    #[DataProvider('referrerProvider')]
    public function successfulSubmissionRedirectsBackWithTheSubmitFlag(
        string $referer,
        string $anchor,
        string $expected,
    ): void {
        $request = $this->request(
            post: ['_anchor' => $anchor],
            server: '' === $referer ? [] : ['HTTP_REFERER' => $referer],
        );

        [$result] = $this->dispatch(
            $this->controller(service: $this->serviceReturning($this->submission(valid: true))),
            $request,
        );

        static::assertSame($expected, $this->location($result));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->stash = new FormStateStash(new Container('SubmitControllerTest'));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }

    private function controller(
        ?LaminasDbFormLoader $loader = null,
        ?FormSubmissionService $service = null,
    ): SubmitController {
        return new SubmitController(
            $loader ?? $this->loaderReturning($this->form()),
            $service ?? $this->serviceReturning($this->submission(valid: true)),
            $this->stash,
            new TokenReplacer(),
        );
    }

    /**
     * @return array{mixed, Response}
     */
    private function dispatch(
        SubmitController $controller,
        HttpRequest $request,
        ?string $slug = 'contact',
    ): array {
        $event = new MvcEvent();
        $event->setRouteMatch(
            new RouteMatch(null === $slug ? ['action' => 'submit'] : ['action' => 'submit', 'slug' => $slug]),
        );
        $controller->setEvent($event);
        $response = new Response();

        return [$controller->dispatch($request, $response), $response];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function form(array $settings = []): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            settings: $settings,
        );
    }

    private function json(mixed $result): array
    {
        static::assertInstanceOf(JsonModel::class, $result);

        return $result->getVariables();
    }

    private function loaderReturning(FormDefinition $form): LaminasDbFormLoader
    {
        $loader = $this->createStub(LaminasDbFormLoader::class);
        $loader->method('loadBySlug')->willReturn($form);

        return $loader;
    }

    private function location(mixed $result): string
    {
        static::assertInstanceOf(Response::class, $result);
        static::assertSame(302, $result->getStatusCode());

        return $result->getHeaders()->get('Location')->getFieldValue();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    private function request(string $method = 'POST', array $post = [], array $server = []): Request
    {
        $request = new Request();
        $request->setMethod($method);
        $request->setPost(new Parameters($post));
        $request->setServer(new Parameters($server));

        return $request;
    }

    private function serviceReturning(SubmissionResult $result): FormSubmissionService
    {
        $service = $this->createStub(FormSubmissionService::class);
        $service->method('submit')->willReturn($result);

        return $service;
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function submission(
        bool $valid,
        array $errors = [],
        bool $spam = false,
        ?int $entryId = null,
    ): SubmissionResult {
        return new SubmissionResult(
            valid: $valid,
            form: new Form(),
            values: ['name' => 'Ann Lee'],
            errors: $errors,
            isSpam: $spam,
            entryId: $entryId,
        );
    }
}
