<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Repository;

/**
 * Stores submitted entries. {@see LaminasDbEntryRepository} writes the
 * `form_entry` tables; implement this to store entries elsewhere and register
 * the implementation under this interface's name.
 *
 * @api
 */
interface EntryRepositoryInterface
{
    /**
     * Stores one entry and returns its id.
     *
     * @param array<array-key, mixed> $values Field name => submitted value.
     * @param array<array-key, mixed> $meta
     *
     * @mago-expect lint:excessive-parameter-list The 0.x LaminasDbEntryRepository::record() signature, kept for 2.0.
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
