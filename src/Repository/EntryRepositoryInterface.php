<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Repository;

/**
 * Stores submitted entries. {@see PhpDbEntryRepository} writes the
 * `form_entry` tables; implement this to store entries elsewhere and register
 * the implementation under this interface's name.
 *
 * @api
 */
interface EntryRepositoryInterface
{
    public const string STATUS_PENDING  = 'pending';
    public const string STATUS_COMPLETE = 'complete';
    public const string STATUS_SPAM     = 'spam';
    public const string STATUS_ARCHIVE  = 'archive';
    public const string STATUS_REDACTED = 'redacted';

    /**
     * Stores one entry and returns its id.
     *
     * @param array<array-key, mixed> $values Field name => submitted value.
     * @param array<array-key, mixed> $meta
     *
     * @mago-expect lint:excessive-parameter-list The record() signature of the laminas-mvc adapter, kept so implementations port unchanged.
     */
    public function record(
        int $formId,
        array $values,
        string $status,
        ?string $ip,
        ?int $userId,
        array $meta = [],
    ): int;
}
