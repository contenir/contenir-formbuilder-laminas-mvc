<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Factory;

use ArrayObject;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Factory\EmailNotificationRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\FormStashedStateFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\FormStateStashFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\LaminasDbEntryRepositoryFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\LaminasDbFormLoaderFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\StoreSubmissionRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\SubmitControllerFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\WebhookRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Laminas\Mvc\View\Helper\FormStashedState;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\Storage\StorageManager;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mail\Transport\InMemory;
use Laminas\Mail\Transport\TransportInterface;
use Laminas\Session\Container;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

use function array_map;

/**
 * Factories are exercised against an in-memory PSR-11 container.
 */
#[Group('unit')]
final class FactoriesTest extends TestCase
{
    use InMemorySessionTrait;

    #[Test]
    public function dbFactoriesUseTheConfiguredAdapterService(): void
    {
        $adapter   = $this->createStub(Adapter::class);
        $container = new InMemoryContainer([
            'config'   => ['formbuilder' => ['db_adapter' => 'db.forms']],
            'db.forms' => $adapter,
        ]);

        static::assertSame(
            [$adapter, $adapter],
            [
                $this->property((new LaminasDbFormLoaderFactory())($container), 'adapter'),
                $this->property((new LaminasDbEntryRepositoryFactory())($container), 'adapter'),
            ],
        );
    }

    #[Test]
    public function dbFactoriesUseTheDefaultAdapterService(): void
    {
        $adapter   = $this->createStub(Adapter::class);
        $container = new InMemoryContainer([Adapter::class => $adapter]);

        static::assertSame(
            [$adapter, $adapter],
            [
                $this->property((new LaminasDbFormLoaderFactory())($container), 'adapter'),
                $this->property((new LaminasDbEntryRepositoryFactory())($container), 'adapter'),
            ],
        );
    }

    #[Test]
    public function loggersAreOptional(): void
    {
        $container = new InMemoryContainer([
            TokenReplacer::class      => new TokenReplacer(),
            TransportInterface::class => new InMemory(),
            LoggerInterface::class    => 'not a logger',
        ]);

        static::assertSame(
            [null, null],
            [
                $this->property((new EmailNotificationRegistrarFactory())($container), 'log'),
                $this->property((new WebhookRegistrarFactory())(new InMemoryContainer()), 'log'),
            ],
        );
    }

    #[Test]
    public function registrarFactoriesWireTheirCollaborators(): void
    {
        $tokens     = new TokenReplacer();
        $transport  = new InMemory();
        $logger     = $this->createStub(LoggerInterface::class);
        $repository = $this->createStub(LaminasDbEntryRepository::class);
        $container  = new InMemoryContainer([
            TokenReplacer::class            => $tokens,
            TransportInterface::class       => $transport,
            LoggerInterface::class          => $logger,
            LaminasDbEntryRepository::class => $repository,
        ]);

        $email   = (new EmailNotificationRegistrarFactory())($container);
        $store   = (new StoreSubmissionRegistrarFactory())($container);
        $webhook = (new WebhookRegistrarFactory())($container);

        static::assertSame([$tokens, $transport, $logger], [
            $this->property($email, 'tokens'),
            $this->property($email, 'transport'),
            $this->property($email, 'log'),
        ]);
        static::assertSame($repository, $this->property($store, 'repository'));
        static::assertSame($logger, $this->property($webhook, 'log'));
    }

    #[Test]
    public function stashFactoriesShareTheStash(): void
    {
        $stash = (new FormStateStashFactory())();
        $stash->store('contact', ['a' => 1], []);

        $helper = (new FormStashedStateFactory())(new InMemoryContainer([FormStateStash::class => $stash]));

        static::assertInstanceOf(FormStashedState::class, $helper);
        static::assertSame(['values' => ['a' => 1], 'errors' => []], $helper('contact'));
    }

    #[Test]
    public function submitControllerAttachesObserversInOrder(): void
    {
        $email  = $this->emailRegistrar();
        $extra  = new RecordingObserver();
        $config = ['formbuilder' => ['observers' => ['extra', 'missing', 42, 'not-an-observer']]];

        $controller = (new SubmitControllerFactory())($this->controllerContainer([
            'config'                          => $config,
            TransportInterface::class         => new InMemory(),
            EmailNotificationRegistrar::class => $email,
            'extra'                           => $extra,
            'not-an-observer'                 => new ArrayObject(),
            StorageManager::class             => new StorageManager(),
        ]));

        static::assertSame(
            [
                StoreSubmissionRegistrar::class,
                WebhookRegistrar::class,
                EmailNotificationRegistrar::class,
                RecordingObserver::class,
            ],
            array_map(static fn(object $observer): string => $observer::class, $this->observers($controller)),
        );
    }

    #[Test]
    public function submitControllerSkipsEmailWithoutATransport(): void
    {
        $controller = (new SubmitControllerFactory())($this->controllerContainer([
            'config' => ['formbuilder' => ['observers' => 'x']],
        ]));

        static::assertSame(
            [StoreSubmissionRegistrar::class, WebhookRegistrar::class],
            array_map(static fn(object $observer): string => $observer::class, $this->observers($controller)),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function controllerContainer(array $extra): InMemoryContainer
    {
        return new InMemoryContainer([
            LaminasDbFormLoader::class      => $this->createStub(LaminasDbFormLoader::class),
            FormStateStash::class           => new FormStateStash(new Container('test')),
            TokenReplacer::class            => new TokenReplacer(),
            StoreSubmissionRegistrar::class => new StoreSubmissionRegistrar($this->createStub(
                LaminasDbEntryRepository::class,
            )),
            WebhookRegistrar::class         => new WebhookRegistrar(),
            ...$extra,
        ]);
    }

    private function emailRegistrar(): EmailNotificationRegistrar
    {
        return new EmailNotificationRegistrar(new TokenReplacer(), new InMemory());
    }

    /**
     * The observers a controller attached, read back by notifying a form
     * through the submission service's observer list.
     *
     * @return list<object>
     */
    private function observers(SubmitController $controller): array
    {
        $service = $this->property($controller, 'service');

        return $this->property($service, 'observers');
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
