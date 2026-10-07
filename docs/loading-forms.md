# Loading forms

`PhpDbFormLoader` implements `FormLoaderInterface` (the four methods
below); register another implementation as `FormLoaderInterface::class` to load
definitions from elsewhere. It hydrates `Contenir\FormBuilder\Definition\FormDefinition`
aggregates from the forms schema with one query per level (form, sections,
groups, rows, fields, notifications, webhooks), so the cost does not grow with
the form's size.

```php
$loader = new PhpDbFormLoader($adapter);

$loader->loadById(5);        // ?FormDefinition
$loader->loadBySlug('contact'); // ?FormDefinition
$loader->loadAll();          // list<FormDefinition>, by title
$loader->listSummaries();    // list<array{id, slug, title, status}>, by title, one query
```

## Schema

Tables: `form`, `form_section`, `form_group`, `form_row`, `form_field`,
`form_notification`, `form_webhook`, plus `form_entry` and `form_entry_value`
for submissions. `tests/install-forms.sqlite.sql` is the SQLite DDL.

Children are ordered by `sort`, then id. JSON columns (`settings_json`,
`options_json`, `validators_json`, `filters_json`, `conditional_json`,
`conditions_json`, `headers_json`) are decoded leniently:

- Missing, NULL and invalid JSON become `[]` (or `null` for `conditional_json`
  and `conditions_json`).
- Validators without a scalar `type` are skipped; a non-scalar `message`
  becomes null and non-array `options` become `[]`.
- Filters keep non-empty strings; webhook headers keep string values.
- `col_span` is clamped to 1–4; a webhook `method` is upper-cased and an empty
  `secret` becomes null.
