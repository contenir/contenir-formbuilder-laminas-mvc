# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.2.0] - 2026-10-05

### Changed

- Renamed from `contenir/formbuilder-laminas-mvc` to
  `contenir/contenir-formbuilder-laminas-mvc`. The package declares `replace`
  for the old name; require `contenir/contenir-formbuilder-laminas-mvc`
  instead. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Requires `contenir/contenir-formbuilder` `^2.2`, the renamed
  `contenir/formbuilder`, in place of `contenir/formbuilder` `^2.1`.
- The optional storage integration names `contenir/contenir-storage` `^2.2`,
  the renamed `contenir/storage`, in `suggest` and `require-dev`.

### Added

- Infection mutation testing in CI, MSI 100%.

## [2.1.0] - 2026-10-05

### Security

- **HTML injection in notification emails.** `{field:*}` values were inserted
  into HTML bodies unescaped, so a visitor could add markup to the email sent
  to site admins. HTML bodies now go through `TokenReplacer::replaceForHtml()`,
  which escapes every value.
- **Plain-text bodies turned into HTML.** Whether a body was HTML was decided
  after merge tags were expanded, so submitting markup into a plain-text
  template made the whole message HTML, unescaped. The format is now decided
  by the template alone: markup or `{entry:fields}` makes it HTML.
- **Open redirect via Referer.** After a submission the controller redirected
  to the client-supplied Referer as-is. It now only follows a local path or a
  same-host `http(s)` URL, and redirects to `/` otherwise.

### Changed

- Requires `contenir/formbuilder` ^2.1 for `TokenReplacer::replaceForHtml()`.
  Stay on 2.0.x of this package if you need formbuilder 0.1.
- Conflicts with `laminas/laminas-uri` < 2.14, whose `Http::getPort()` raises a
  PHP 8.5 deprecation when the controller reads the request host and port.

## [2.0.0] - 2026-10-05

The public API keeps its shape. The major version marks the move to PHP 8.3+
and the php-db QA toolchain, `final` wiring classes, and several behaviour
fixes. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

- Requires PHP 8.3, 8.4 or 8.5. PHP 8.1 and 8.2 are no longer supported.
- Works with `contenir/formbuilder` `^0.1.1 || ^2.0`.
- Requires laminas-mvc 3.8+, the first release without PHP 8.4 deprecations.
- Every concrete class is `final`. `SubmitController` depends on the new
  `Loader\FormLoaderInterface` and `StoreSubmissionRegistrar` on the new
  `Repository\EntryRepositoryInterface`; `ConfigProvider` aliases both to the
  Laminas\Db implementations.
- `laminas/laminas-http`, `laminas/laminas-mime`, `laminas/laminas-stdlib`
  (3.21+) and `psr/container`, used directly, are now declared dependencies.
- `SubmitController` reads server variables and uploaded files from the
  request object instead of `$_SERVER` and `$_FILES`.
- `TokenReplacerFactory` takes `HTTP_HOST` and `HTTPS` from the `Request`
  service instead of `$_SERVER`.
- `SubmitControllerFactory` no longer unwraps a `ControllerManager`
  (laminas-mvc 3 passes the application container).
- Factories check service types and fail with `UnexpectedValueException`
  naming the misconfigured service; optional services of the wrong type are
  ignored.
- `LaminasDbFormLoader` reads columns type-safely; validators without a scalar
  type are skipped.
- `Render\FormMarkup` omits `false` and non-scalar attributes and accepts
  `['value' => …, 'label' => …]` option specs, like the core renderer.
- `StoreSubmissionRegistrar` stores a non-numeric `user_id` and a non-scalar
  `ip` as NULL.
- Class constants are typed (`LaminasDbEntryRepository::STATUS_*`).
- The MIT licence's copyright holder is now Contenir, and the text restores
  the missing "USE OR OTHER" wording.

### Added

- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Unit and integration (in-memory SQLite) test suites with 100% line and
  branch coverage, and a `docs/` folder.

### Fixed

- A line break in a submitted value used in a notification subject made
  laminas-mail reject the header, so the notification was silently dropped.
  Line breaks in the subject now become spaces.
- An invalid From or Reply-To address was swallowed by an empty `catch`; it is
  now logged as a notice.
- Non-string validator messages in `validators_json` caused "Array to string
  conversion" warnings.

### Removed

- `squizlabs/php_codesniffer`, `phpcs.xml`, `phpunit.xml` and the composer
  path/VCS repositories for `contenir/formbuilder` (it is on Packagist).

## [0.1.5] - 2026-05-12

- Inputs are rendered through the Laminas `formElement` view helper.

## [0.1.4] - 2026-05-10

- `TokenReplacerFactory` fills `site_context.base_url` from the request.

## [0.1.3] - 2026-05-10

- Notification email: token-resolved addresses, multipart body, custom
  token namespaces.

## [0.1.2] - 2026-05-10

- Anchor and state-stash support for mid-page form blocks.

## [0.1.1] - 2026-05-10

- `EmailNotificationRegistrar` and webhook registrar, attached automatically.

## [0.1.0] - 2026-05-10

- Initial Laminas MVC adapter.
