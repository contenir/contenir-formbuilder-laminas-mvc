<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Repository;

use Override;
use PhpDb\Adapter\AdapterInterface;
use Psr\Clock\ClockInterface;
use Throwable;

use function is_array;
use function is_scalar;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Writes submitted entries to the `form_entry` and `form_entry_value` tables
 * through a php-db adapter.
 *
 * Only `record()` is provided: it is all the public submit path needs.
 * Reading, moderating and redacting entries is the admin's job.
 *
 * @api
 */
final readonly class PhpDbEntryRepository implements EntryRepositoryInterface
{
    private const string INSERT_ENTRY = 'INSERT INTO form_entry (form_id, submitted_at, ip, user_id, status, meta_json) VALUES (?, ?, ?, ?, ?, ?)';

    private const string INSERT_VALUE =
        'INSERT INTO form_entry_value (form_entry_id, form_field_id, field_name, value_text, value_json) '
            . 'VALUES (?, ?, ?, ?, ?)';

    public function __construct(
        private AdapterInterface $adapter,
        private ClockInterface $clock,
    ) {}

    private static function json(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? null : $encoded;
    }

    /**
     * Inserts the entry and one value row per field in a transaction, and
     * returns the new entry id. Scalars go to `value_text`, arrays to
     * `value_json`; other values are stored as NULL. The submission time is
     * the clock's, formatted `Y-m-d H:i:s`.
     *
     * @param array<array-key, mixed> $values  field name => value
     * @param array<array-key, mixed> $meta
     *
     * @throws Throwable Any database error, after the transaction is rolled back.
     *
     * @mago-expect lint:excessive-parameter-list Implements EntryRepositoryInterface::record().
     * @mago-expect analysis:mixed-assignment Submitted values are untyped; each is stored by its type.
     */
    #[Override]
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
            $this->execute(self::INSERT_ENTRY, [
                $formId,
                $this->clock->now()->format('Y-m-d H:i:s'),
                $ip,
                $userId,
                $status,
                [] === $meta ? null : self::json($meta),
            ]);
            $entryId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

            foreach ($values as $name => $value) {
                $this->execute(self::INSERT_VALUE, [
                    $entryId,
                    null,
                    $name,
                    is_scalar($value) ? (string) $value : null,
                    is_array($value) ? self::json($value) : null,
                ]);
            }

            $connection->commit();

            return $entryId;
        } catch (Throwable $e) {
            $connection->rollback();

            throw $e;
        }
    }

    /**
     * @param list<mixed> $parameters
     *
     * @throws Throwable
     */
    private function execute(string $sql, array $parameters): void
    {
        $this->adapter->executeQuery($this->adapter->prepareQuery($sql, $parameters));
    }
}
