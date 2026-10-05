# Upgrading from 0.x to 2.0

2.0 keeps the 0.1 API: the same services, routes, helpers and configuration
keys. Most sites only update the constraint.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| contenir/formbuilder | ^0.1.1 | ^0.1.1 or ^2.0 |
| laminas-stdlib | any (indirect) | 3.21+ |

```bash
composer require contenir/formbuilder-laminas-mvc:^2.0
```

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.1`, maintained on
the `0.x` branch.

## Final wiring classes

`Module` and all factories in `Factory\` are now `final`.

```php
// 0.x
final class MyLoaderFactory extends LaminasDbFormLoaderFactory { /* … */ }

// 2.0: register your own factory instead
return ['service_manager' => ['factories' => [
    LaminasDbFormLoader::class => MyLoaderFactory::class,
]]];
```

## Typed constants

```php
// 0.x
public const STATUS_SPAM = 'spam';
// 2.0
public const string STATUS_SPAM = 'spam';
```

A subclass of `LaminasDbEntryRepository` that redeclares a status constant must
declare it as `string`.

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
