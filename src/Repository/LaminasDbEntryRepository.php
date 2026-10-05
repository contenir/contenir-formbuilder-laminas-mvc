<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Repository;

use DateTimeImmutable;
use Laminas\Db\Adapter\Adapter;
use Throwable;

use function is_array;
use function is_scalar;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Submission-write entry repository for Laminas\Db sites.
 *
 * Slice 2 ports only `record()` — that's everything the public submit
 * path needs. Read-side methods (find/list/setStatus/redact) live on
 * admin4's `EntryRepository` and aren't reproduced here because Sites
 * don't read entries; that's the admin module's job. Add them if a
 * future Site grows an entry-management surface.
 *
 * @api
 */
class LaminasDbEntryRepository
{
    public const string STATUS_PENDING  = 'pending';
    public const string STATUS_COMPLETE = 'complete';
    public const string STATUS_SPAM     = 'spam';
    public const string STATUS_ARCHIVE  = 'archive';
    public const string STATUS_REDACTED = 'redacted';

    public function __construct(
        private Adapter $adapter,
    ) {}

    /**
     * Inserts the entry and one value row per field in a transaction, and
     * returns the new entry id. Scalars go to `value_text`, arrays to
     * `value_json`; other values are stored as NULL.
     *
     * @param array<array-key, mixed> $values  field name => value
     * @param array<array-key, mixed> $meta
     *
     * @throws Throwable Any database error, after the transaction is rolled back.
     *
     * @mago-expect lint:excessive-parameter-list Public 0.x signature, kept for 2.0.
     * @mago-expect analysis:mixed-assignment Submitted values are untyped; each is stored by its type.
     */
    public function record(
        int $formId,
        array $values,
        string $status,
        ?string $ip,
        ?int $userId,
        array $meta = [],
    ): int {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $this->adapter->query(
                'INSERT INTO form_entry (form_id, submitted_at, ip, user_id, status, meta_json) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $formId,
                    (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $ip,
                    $userId,
                    $status,
                    [] === $meta
                        ? null
                        : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            );
            $entryId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

            $insertValueSql =
                'INSERT INTO form_entry_value '
                . '(form_entry_id, form_field_id, field_name, value_text, value_json) '
                . 'VALUES (?, ?, ?, ?, ?)';
            foreach ($values as $name => $value) {
                $this->adapter->query($insertValueSql, [
                    $entryId,
                    null,
                    $name,
                    is_scalar($value) ? (string) $value : null,
                    is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                ]);
            }

            $connection->commit();
            return $entryId;
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
