# Registrars

Registrars are `SplObserver`s notified by `FormSubmissionService` with the
`BuilderForm` and its `registry` (see the core package's documentation).

## StoreSubmissionRegistrar

Records every valid and spam submission through
`PhpDbEntryRepository::record()` (status `complete` or `spam`), then writes
`entry_id` and `entry_status` to the registry so later observers and the
controller see them. It does nothing without a `FormDefinition` that has an id
and an array of values. A database error is rethrown: the visitor must not be
told the submission succeeded.

## PhpDbEntryRepository

Implements `EntryRepositoryInterface::record()`, which `StoreSubmissionRegistrar`
depends on; register another implementation as `EntryRepositoryInterface::class`
to store entries elsewhere. It writes through the php-db adapter named by
`formbuilder.db_adapter`, and takes the submission time from the container's
`Psr\Clock\ClockInterface` service when one is registered, otherwise from the
system clock.

```php
$id = $repository->record(
    formId: 5,
    values: ['name' => 'Ann', 'tags' => ['a', 'b']],
    status: EntryRepositoryInterface::STATUS_COMPLETE,
    ip: '10.0.0.1',
    userId: null,
    meta: ['user_agent' => '…'],
);
```

Inserts one `form_entry` row (with the clock's current time and `meta_json`,
NULL when empty) and one `form_entry_value` row per value, in a transaction: scalars go
to `value_text`, arrays to `value_json`, anything else is stored as NULL. On
failure the transaction is rolled back and the exception rethrown.

Status constants: `STATUS_PENDING`, `STATUS_COMPLETE`, `STATUS_SPAM`,
`STATUS_ARCHIVE`, `STATUS_REDACTED`.

## EmailNotificationRegistrar

Sends each enabled `NotificationDefinition` of the form through a
`Contenir\Mail\Transport\TransportInterface` (contenir-mail). Spam is skipped.

- **Merge tags** are expanded in the subject, body and addresses with the shared
  `TokenReplacer`. Line breaks in the expanded subject become spaces.
- **Recipients:** `toAddress` is split on commas, semicolons and whitespace;
  each part is expanded and kept when it is a valid email address.
- **From and Reply-To:** expanded; an empty result is skipped, and one
  contenir-mail rejects as an address is logged as a notice and skipped.
- **Body:** the *template* decides the format. A template containing HTML
  tags or `{entry:fields}` is an HTML body: every merge-tag value is
  HTML-escaped (`TokenReplacer::replaceForHtml()`) and the message is sent as
  `multipart/alternative` with a generated plain-text part. Any other template
  is sent as UTF-8 plain text, even if a submitted value contains markup.
- **Failures** are logged as warnings and never thrown, and do not stop the
  other notifications.

Submitted values can't add markup to a notification: HTML bodies escape them,
and plain-text bodies are never sent as HTML.

## WebhookRegistrar

The core `Contenir\FormBuilder\Registrar\WebhookRegistrar`, built with the
container's PSR-3 logger when there is one. See the core documentation.
