# contenir/contenir-formbuilder-laminas-mvc

Formerly `contenir/formbuilder-laminas-mvc`; the old package is abandoned in favour of this one.

[![Continuous Integration](https://github.com/contenir/contenir-formbuilder-laminas-mvc/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-formbuilder-laminas-mvc/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-formbuilder-laminas-mvc/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-formbuilder-laminas-mvc)

Laminas MVC adapter for [`contenir/contenir-formbuilder`](https://github.com/contenir/contenir-formbuilder).

It wires the framework-agnostic form-builder engine into a laminas-mvc site:

- **`LaminasDbFormLoader`** reads form definitions from the forms schema
  through laminas-db, one query per level.
- **`SubmitController`** handles `POST /forms/submit/{slug}`: validation,
  spam trap, redirect or JSON response, and a one-shot stash so the form can
  be re-shown with errors after a redirect.
- **Registrars** store the entry (`StoreSubmissionRegistrar`), send email
  notifications (`EmailNotificationRegistrar`) and fire webhooks.
- **View helpers** render a form (`formMarkup`) and read the stash
  (`formStashedState`).
- **`Module`** and **`ConfigProvider`** with factories for all of it.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `contenir/contenir-formbuilder` 2.2+ (use 2.0.x of this package for `contenir/formbuilder` 0.1, and 2.1.x for `contenir/formbuilder` 2.1)
- laminas-mvc 3.8+, laminas-db 2.17+, laminas-form, laminas-view, laminas-session, laminas-mail
- A database with the forms schema (`tests/install-forms.sqlite.sql` is the SQLite version)
- Optional: `contenir/contenir-storage` 2.2+, registered as `Contenir\Storage\StorageManager`, for file uploads

## Installation

```bash
composer require contenir/contenir-formbuilder-laminas-mvc
```

The module registers itself through `extra.laminas.module` and
laminas-component-installer. Without the installer, add it to
`config/modules.config.php`:

```php
'Contenir\FormBuilder\Laminas\Mvc',
```

The 0.x releases, which support PHP 8.1, remain available from the `0.x`
branch and `v0.*` tags; see [UPGRADE-2.0.md](UPGRADE-2.0.md).

## Usage

The controller loads the definition and builds the form; the template only
renders.

```php
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Service\FormBuilderService;

final class ContactController extends AbstractActionController
{
    public function __construct(
        private LaminasDbFormLoader $loader,
        private FormBuilderService $builder,
    ) {}

    public function indexAction(): ViewModel
    {
        $definition = $this->loader->loadBySlug('contact');
        $form       = $this->builder->build($definition);

        return new ViewModel([
            'definition' => $definition,
            'form'       => $form,
            'isSuccess'  => $this->params()->fromQuery('submit') === 'contact',
        ]);
    }
}
```

```phtml
<?php $stashed = $this->formStashedState($definition->slug); ?>
<?php if ($stashed !== null): ?>
    <?php $form->setData($stashed['values']); $form->setMessages($stashed['errors']); ?>
<?php endif ?>

<?php if ($isSuccess): ?>
    <div class="form-success">Thank you.</div>
<?php else: ?>
    <?= $this->formMarkup($definition, $form) ?>
<?php endif ?>
```

The rendered form posts to `/forms/submit/{slug}`. See [docs/](docs/):

| Page | Covers |
| --- | --- |
| [Configuration](docs/configuration.md) | The `formbuilder` config keys and registered services |
| [Loading forms](docs/loading-forms.md) | `LaminasDbFormLoader`, the schema |
| [Submitting](docs/submitting.md) | `SubmitController`, success modes, JSON responses, `FormStateStash` |
| [Registrars](docs/registrars.md) | `StoreSubmissionRegistrar`, `LaminasDbEntryRepository`, `EmailNotificationRegistrar`, webhooks |
| [Rendering](docs/rendering.md) | `formMarkup` and `formStashedState` helpers, `Render\FormMarkup` |

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: stubs and in-memory session, no database
composer test-integration  # integration suite: in-memory SQLite with the forms schema
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection over both suites (needs Xdebug or PCOV)
```

## License

MIT. See [LICENSE](LICENSE).
