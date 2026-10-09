# Configuration

`ConfigProvider` (merged by `Module::getConfig()`) registers:

| Key | Contents |
| --- | --- |
| `service_manager.factories` | `PhpDbFormLoader`, `PhpDbEntryRepository`, `StoreSubmissionRegistrar`, `EmailNotificationRegistrar`, `WebhookRegistrar` (core), `FormStateStash`, `TokenReplacer` (core) |
| `controllers.factories` | `SubmitController` |
| `view_helpers` | `formMarkup` (invokable), `formStashedState` (factory) |
| `router.routes.forms-submit` | `/forms/submit/:slug`, slug `[a-z0-9][a-z0-9\-]*`, action `submit` |
| `formbuilder` | The defaults below |

## `formbuilder` keys

```php
return [
    'formbuilder' => [
        // Service id of the php-db adapter for the loader and repository.
        'db_adapter'      => PhpDb\Adapter\AdapterInterface::class,
        // Static values for {site:*} merge tags.
        'site_context'    => ['admin_url' => 'https://admin.example.com'],
        // Extra merge-tag namespaces: namespace => service id of a callable
        // fn (string $key): ?string. Return null to leave the tag in place.
        'token_resolvers' => ['settings' => App\Mail\SettingsResolver::class],
        // Extra observers, attached after the built-in ones. Ids that are not
        // registered, or do not resolve to an SplObserver, are skipped.
        'observers'       => [App\Form\CrmRegistrar::class],
    ],
];
```

`{site:base_url}` is filled from the `Request` service (`HTTP_HOST`, with
`https` when `HTTPS` is set and not `off`) unless `site_context.base_url` is
configured. Without a request host (CLI) the tag is left as is.

## Optional services

| Service | Effect |
| --- | --- |
| `Contenir\Mail\Transport\TransportInterface` | Enables `EmailNotificationRegistrar` in the submit pipeline |
| `Psr\Log\LoggerInterface` | Email and webhook failures are logged |
| `Contenir\Storage\StorageManager` | Enables `file` field uploads |

A service registered under one of these names but of another type is ignored.
A required service of the wrong type (for example a `db_adapter` that is not
a `PhpDb\Adapter\AdapterInterface`) fails with an `UnexpectedValueException` naming the service.

Every class is `final`. `ConfigProvider` also aliases
`Loader\FormLoaderInterface` to `PhpDbFormLoader` and
`Repository\EntryRepositoryInterface` to `PhpDbEntryRepository`; the
submit pipeline resolves the interfaces, so register your own implementation
under the interface name to replace either. Replace a factory in your own
configuration instead of extending it.
