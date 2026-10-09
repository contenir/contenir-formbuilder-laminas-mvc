<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Contenir\Mail\Transport\TransportInterface;
use Contenir\Storage\StorageManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SplObserver;
use UnexpectedValueException;

use function is_array;
use function is_string;

/**
 * Wires the submit pipeline. Observers are attached in this order: the
 * entry store, webhooks, email notifications (only when a mail transport is
 * registered), then each `formbuilder.observers` service id that resolves to
 * an SplObserver. A registered `Contenir\Storage\StorageManager` enables file
 * uploads.
 *
 * @api
 */
final class SubmitControllerFactory
{
    /**
     * @return list<SplObserver>
     *
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each id is checked with is_string().
     */
    private function observers(ContainerInterface $container): array
    {
        $observers = [
            Services::get($container, StoreSubmissionRegistrar::class, StoreSubmissionRegistrar::class),
            Services::get($container, WebhookRegistrar::class, WebhookRegistrar::class),
        ];

        if ($container->has(TransportInterface::class)) {
            $observers[] = Services::get(
                $container,
                EmailNotificationRegistrar::class,
                EmailNotificationRegistrar::class,
            );
        }

        $extra = Services::config($container)['observers'] ?? [];
        foreach (is_array($extra) ? $extra : [] as $id) {
            $observer = is_string($id) ? Services::optional($container, $id, SplObserver::class) : null;
            if (null !== $observer) {
                $observers[] = $observer;
            }
        }

        return $observers;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): SubmitController
    {
        $service = new FormSubmissionService(
            new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()),
            Services::optional($container, StorageManager::class, StorageManager::class),
        );

        return new SubmitController(
            Services::get($container, FormLoaderInterface::class, FormLoaderInterface::class),
            $service,
            Services::get($container, FormStateStash::class, FormStateStash::class),
            Services::get($container, TokenReplacer::class, TokenReplacer::class),
            $this->observers($container),
        );
    }
}
