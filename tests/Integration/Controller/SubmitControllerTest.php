<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Integration\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Loader\InMemoryFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\FormBuilder\Validator\ValidatorFactory;
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

use function array_keys;

/**
 * Dispatches the controller over the real formbuilder submission pipeline,
 * with an in-memory session (CSRF, stash) and loader.
 */
#[Group('integration')]
#[Group('handler')]
final class SubmitControllerTest extends TestCase
{
    use InMemorySessionTrait;

    private const array ACCEPT_JSON = ['HTTP_ACCEPT' => 'application/json'];

    private FormBuilderService $builder;

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
     * @return array<string, array{string, mixed, string}>
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
            'array anchor dropped'     => ['/p', ['x'], '/p?submit=contact'],
            'no referrer'              => ['', '', '/?submit=contact'],
        ];
    }

    #[Test]
    public function invalidJsonSubmissionAnswers422WithErrors(): void
    {
        [$result, $response] = $this->dispatch($this->controller(), $this->request(
            post: ['name' => ''],
            server: self::ACCEPT_JSON,
        ));

        static::assertSame(422, $response->getStatusCode());
        static::assertSame([false, ['name']], [$this->json($result)['ok'], array_keys($this->json($result)['errors'])]);
    }

    #[Test]
    public function invalidSubmissionStashesValuesAndRedirectsBack(): void
    {
        $request = $this->request(
            post: ['name' => '', '_anchor' => 'contact-form'],
            server: ['HTTP_REFERER' => '/contact?submit=old#x'],
        );

        [$result] = $this->dispatch($this->controller(), $request);

        static::assertSame('/contact#contact-form', $this->location($result));
        $stashed = $this->stash->consume('contact');
        static::assertNotNull($stashed);
        static::assertSame('', $stashed['values']['name']);
        static::assertArrayNotHasKey('_anchor', $stashed['values']);
        static::assertArrayHasKey('isEmpty', $stashed['errors']['name']);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('jsonSuccessProvider')]
    public function jsonSuccessDescribesTheConfiguredMode(array $settings, array $expected): void
    {
        $controller = $this->controller($this->form($settings), [new RecordingObserver(['entry_id' => 42])]);

        [$result, $response] = $this->dispatch($controller, $this->request(server: self::ACCEPT_JSON));

        static::assertSame([200, $expected], [$response->getStatusCode(), $this->json($result)]);
    }

    #[Test]
    public function passesTheRequestContextToObservers(): void
    {
        $observer = new RecordingObserver();
        $request  = $this->request(server: [
            'REMOTE_ADDR'     => '10.0.0.1',
            'HTTP_USER_AGENT' => 'UA/1',
            'HTTP_REFERER'    => '/contact',
        ]);

        $this->dispatch($this->controller(observers: [$observer]), $request);

        static::assertSame(
            ['ip' => '10.0.0.1', 'user_id' => null, 'meta' => ['user_agent' => 'UA/1', 'referer' => '/contact']],
            $observer->registries[0]['context'],
        );
    }

    #[Test]
    public function plainHttpRequestsAndNonScalarServerValuesUseDefaults(): void
    {
        $plain = new HttpRequest();
        $plain->setMethod('POST');
        $plain->setPost(new Parameters(['name' => 'Ann Lee', '_csrf' => $this->csrf()]));

        [$first] = $this->dispatch($this->controller(), $plain);
        [$second] = $this->dispatch($this->controller(), $this->request(server: ['HTTP_REFERER' => ['x']]));

        static::assertSame(['/?submit=contact', '/?submit=contact'], [
            $this->location($first),
            $this->location($second),
        ]);
    }

    #[Test]
    public function redirectUrlModeRedirectsToTheExpandedUrl(): void
    {
        $form = $this->form(['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks?n={field:name}']]);

        [$result] = $this->dispatch($this->controller($form), $this->request());

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
        [$result, $response] = $this->dispatch($this->controller(), $this->request(), slug: 'missing');

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
        $request = $this->request();
        $request->setMethod('GET');

        [$result, $response] = $this->dispatch($this->controller(), $request);

        static::assertSame([405, ['error' => 'Method not allowed']], [
            $response->getStatusCode(),
            $this->json($result),
        ]);
    }

    #[Test]
    public function spamIsAnsweredLikeASuccess(): void
    {
        $request = $this->request(
            post: ['name' => '', 'hid' => 'bot'],
            server: ['HTTP_REFERER' => '/c'],
        );

        [$result] = $this->dispatch($this->controller(), $request);

        static::assertSame('/c?submit=contact', $this->location($result));
    }

    #[Test]
    #[DataProvider('referrerProvider')]
    public function successfulSubmissionRedirectsBackWithTheSubmitFlag(
        string $referer,
        mixed $anchor,
        string $expected,
    ): void {
        $request = $this->request(
            post: ['_anchor' => $anchor],
            server: '' === $referer ? [] : ['HTTP_REFERER' => $referer],
        );

        [$result] = $this->dispatch($this->controller(), $request);

        static::assertSame($expected, $this->location($result));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
        $this->stash   = new FormStateStash(new Container('SubmitControllerTest'));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }

    /**
     * @param list<RecordingObserver> $observers
     */
    private function controller(?FormDefinition $form = null, array $observers = []): SubmitController
    {
        return new SubmitController(
            new InMemoryFormLoader($form ?? $this->form()),
            new FormSubmissionService($this->builder),
            $this->stash,
            new TokenReplacer(),
            $observers,
        );
    }

    private function csrf(): string
    {
        return (string) $this->builder->build($this->form())->get('_csrf')->getValue();
    }

    /**
     * @return array{mixed, Response}
     */
    private function dispatch(SubmitController $controller, HttpRequest $request, ?string $slug = 'contact'): array
    {
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
        $definition = FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name', [
            'required' => true,
        ])]);

        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            settings: $settings,
            sections: $definition->sections,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $result): array
    {
        static::assertInstanceOf(JsonModel::class, $result);

        return $result->getVariables();
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
    private function request(array $post = ['name' => 'Ann Lee'], array $server = []): Request
    {
        $request = new Request();
        $request->setMethod('POST');
        $request->setPost(new Parameters([...['name' => 'Ann Lee'], ...$post, '_csrf' => $this->csrf()]));
        $request->setServer(new Parameters($server));

        return $request;
    }
}
