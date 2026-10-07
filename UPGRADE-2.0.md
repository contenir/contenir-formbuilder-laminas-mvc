# Upgrading from 0.x to 2.0

2.0 keeps the 0.1 API: the same services, routes, helpers and configuration
keys. Most sites only update the constraint.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| contenir/formbuilder | ^0.1.1 | `contenir/contenir-formbuilder` ^2.0 |
| laminas-stdlib | any (indirect) | 3.21+ |
| laminas-mvc | ^3.4 | ^3.8 |

```bash
composer require contenir/contenir-formbuilder-laminas-mvc:^2.0@RC
```

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.1`, maintained on
the `0.x` branch.


## Database layer: laminas-db to php-db

The loader and the entry repository now use `php-db/phpdb` instead of
`laminas/laminas-db`, as in the Mezzio adapter:

| 0.x | 2.0 |
| --- | --- |
| `Loader\LaminasDbFormLoader` | `Loader\PhpDbFormLoader` |
| `Repository\LaminasDbEntryRepository` | `Repository\PhpDbEntryRepository` |
| `Factory\LaminasDb*Factory` | `Factory\PhpDb*Factory` |
| `db_adapter` default `Laminas\Db\Adapter\Adapter` | `PhpDb\Adapter\AdapterInterface` |

Register a php-db adapter (`php-db/phpdb` 0.6 plus the driver package for your
database, such as `php-db/phpdb-mysql`) under `PhpDb\Adapter\AdapterInterface`,
or point `formbuilder.db_adapter` at its service id. The schema and the
queries are unchanged. `PhpDbEntryRepository` also takes a
`Psr\Clock\ClockInterface` for the submission time; the factory uses the
container's clock service when there is one, otherwise the system clock.
Code that resolves the services by interface needs no change.

## Final classes and extension points

Every concrete class is now `final`: `Module`, the factories, the controller,
loader, repository, stash, registrars, renderer and view helpers. Customise
through interfaces instead:

| To customise | 0.x | 2.0 |
| --- | --- | --- |
| Where definitions come from | extend `LaminasDbFormLoader` | implement `Loader\FormLoaderInterface` and register it as `FormLoaderInterface::class` |
| Where entries are stored | extend `LaminasDbEntryRepository` | implement `Repository\EntryRepositoryInterface` and register it as `EntryRepositoryInterface::class` |
| A registrar | extend `StoreSubmissionRegistrar` / `EmailNotificationRegistrar` | implement `SplObserver` and add it to `formbuilder.observers` |
| A factory | extend it | register your own factory for the service |

`ConfigProvider` aliases the interfaces to the php-db implementations, and
the submit pipeline asks for the interfaces, so a replacement registered under
the interface name is picked up everywhere:

```php
// 0.x
final class ApiFormLoader extends LaminasDbFormLoader { /* … */ }

// 2.0
final class ApiFormLoader implements FormLoaderInterface { /* … */ }

return ['service_manager' => [
    'aliases'   => [FormLoaderInterface::class => ApiFormLoader::class],
    'factories' => [ApiFormLoader::class => ApiFormLoaderFactory::class],
]];
```

`SubmitController`'s first constructor parameter is typed `FormLoaderInterface`
and `StoreSubmissionRegistrar`'s is `EntryRepositoryInterface` (both were the
concrete classes); existing callers are unaffected.

For factories:

```php
// 0.x
final class MyLoaderFactory extends LaminasDbFormLoaderFactory { /* … */ }

// 2.0: register your own factory instead
return ['service_manager' => ['factories' => [
    PhpDbFormLoader::class => MyLoaderFactory::class,
]]];
```

## Typed constants

```php
// 0.x
public const STATUS_SPAM = 'spam';
// 2.0
public const string STATUS_SPAM = 'spam';
```

The status constants are now on `Repository\EntryRepositoryInterface`
(`EntryRepositoryInterface::STATUS_SPAM`, …); `LaminasDbEntryRepository::STATUS_*`
no longer exists.

## Request data

`SubmitController` and `TokenReplacerFactory` read from the MVC request
instead of the superglobals. In a normal laminas-mvc application nothing
changes. Code that dispatches the controller with a hand-built request must put
the data on the request:

```php
// 0.x: read from $_SERVER and $_FILES
$_SERVER['HTTP_ACCEPT'] = 'application/json';

// 2.0
$request = new Laminas\Http\PhpEnvironment\Request();
$request->setServer(new Parameters(['HTTP_ACCEPT' => 'application/json']));
$request->setFiles(new Parameters($files));
```

Server variables are only read from a `PhpEnvironment\Request`; a plain
`Laminas\Http\Request` gives empty values. Without a `Request` service,
`TokenReplacerFactory` no longer derives `site:base_url`; configure
`formbuilder.site_context.base_url` for such contexts.

## Factories and services

- A required service of the wrong type throws `UnexpectedValueException`
  instead of failing later with a `TypeError`.
- An optional `LoggerInterface`, `StorageManager` or extra observer of the
  wrong type is ignored, as before.
- `SubmitControllerFactory` uses the container it is given; it no longer calls
  the removed `ControllerManager::getServiceLocator()`.

## Behaviour

- Notification subjects have line breaks replaced by spaces.
- `StoreSubmissionRegistrar` stores a non-numeric `context.user_id` as NULL
  (0.x stored 0) and only treats `spam === true` as spam.
- `EmailNotificationRegistrar` only skips when `spam === true`.
- `Render\FormMarkup` omits attributes set to `false` (0.x rendered
  `disabled=""`, which disables the input) and non-scalar attributes.
- `FormStateStash::consume()` documents `errors` as Laminas' nested messages
  (`array<array-key, mixed>`), which is what it always returned.

## Package renamed

From 2.0, the package is published as
`contenir/contenir-formbuilder-laminas-mvc`. It declares `replace` for
`contenir/formbuilder-laminas-mvc`, so the two can never be installed
together. Switch the requirement:

```bash
composer remove contenir/formbuilder-laminas-mvc && composer require contenir/contenir-formbuilder-laminas-mvc:^2.0@RC
```

2.0 also requires `contenir/contenir-formbuilder` `^2.0` (the renamed
`contenir/formbuilder`) instead of `contenir/formbuilder`. If you require
`contenir/formbuilder` directly, switch that requirement as well. Sites that
use file fields should likewise move from `contenir/storage` to
`contenir/contenir-storage` `^2.2`.

No code changes are needed: namespaces, classes, the module name, routes and
templates are unchanged.
