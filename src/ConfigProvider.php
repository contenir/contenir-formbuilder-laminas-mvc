<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc;

use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Factory\EmailNotificationRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\FormStashedStateFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\FormStateStashFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\PhpDbEntryRepositoryFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\PhpDbFormLoaderFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\StoreSubmissionRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\SubmitControllerFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\TokenReplacerFactory;
use Contenir\FormBuilder\Laminas\Mvc\Factory\WebhookRegistrarFactory;
use Contenir\FormBuilder\Laminas\Mvc\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Laminas\Mvc\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Laminas\Mvc\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\View\Helper\FormMarkup;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\TokenReplacer;
use PhpDb\Adapter\AdapterInterface;

/**
 * Returns the merged Laminas-MVC config consumed by Module::getConfig().
 *
 * Kept separate so a future Mezzio adapter can require the same array
 * without reaching into the MVC Module class.
 *
 * @api
 */
final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function getControllers(): array
    {
        return [
            'factories' => [
                SubmitController::class => SubmitControllerFactory::class,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getDefaults(): array
    {
        return [
            // Service id of the php-db adapter (PhpDb\Adapter\AdapterInterface)
            // the loader and repository consume. Override in the consuming
            // site's `formbuilder.global.php` if the adapter is registered
            // under a different key.
            'db_adapter' => AdapterInterface::class,
            // Static values for {site:*} TokenReplacer expansion.
            'site_context' => [],
            // Map of additional TokenReplacer namespaces => service-manager
            // ids. Each service must resolve to a callable of shape
            // `function (string $key): string`. Wired by
            // {@see Factory\TokenReplacerFactory}; lets consumers add
            // namespaces like `{settings:foo.bar}` without touching the
            // package. The same TokenReplacer instance is shared between
            // EmailNotificationRegistrar and SubmitController.
            'token_resolvers' => [],
            // Factory list of additional submission observers. Each entry
            // can be a service-manager id (string) — resolved at submit
            // time and attached to FormSubmissionService alongside the
            // built-in StoreSubmissionRegistrar. Sites add email /
            // webhook / custom registrars here.
            'observers' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function getDependencies(): array
    {
        return [
            'aliases'   => [
                FormLoaderInterface::class      => PhpDbFormLoader::class,
                EntryRepositoryInterface::class => PhpDbEntryRepository::class,
            ],
            'factories' => [
                PhpDbFormLoader::class            => PhpDbFormLoaderFactory::class,
                PhpDbEntryRepository::class       => PhpDbEntryRepositoryFactory::class,
                StoreSubmissionRegistrar::class   => StoreSubmissionRegistrarFactory::class,
                EmailNotificationRegistrar::class => EmailNotificationRegistrarFactory::class,
                WebhookRegistrar::class           => WebhookRegistrarFactory::class,
                FormStateStash::class             => FormStateStashFactory::class,
                TokenReplacer::class              => TokenReplacerFactory::class,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getRouter(): array
    {
        return [
            'routes' => [
                'forms-submit' => [
                    'type'    => 'segment',
                    'options' => [
                        'route'       => '/forms/submit/:slug',
                        'defaults'    => [
                            'controller' => SubmitController::class,
                            'action'     => 'submit',
                        ],
                        'constraints' => [
                            'slug' => '[a-z0-9][a-z0-9\-]*',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * `formMarkup` is a pure renderer that takes a FormDefinition and
     * a built Laminas\Form. By design no view helper does its own
     * data fetching: the controller owns loading the definition (via
     * {@see PhpDbFormLoader}) and building the form (via
     * {@see \Contenir\FormBuilder\Service\FormBuilderService}), and
     * passes both to the template.
     *
     * `formStashedState` is the read-half of the {@see FormStateStash}
     * round trip. Section partials call it with the form slug to pull
     * the previous submission's values and validator messages so the
     * rebuilt form can be hydrated after a redirect-on-error reload.
     */
    public function getViewHelpers(): array
    {
        return [
            'invokables' => [
                'formMarkup' => FormMarkup::class,
            ],
            'factories'  => [
                'formStashedState' => FormStashedStateFactory::class,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'service_manager' => $this->getDependencies(),
            'controllers'     => $this->getControllers(),
            'view_helpers'    => $this->getViewHelpers(),
            'router'          => $this->getRouter(),
            'formbuilder'     => $this->getDefaults(),
        ];
    }
}
