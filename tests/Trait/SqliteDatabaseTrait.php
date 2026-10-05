<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Trait;

use Laminas\Db\Adapter\Adapter;
use PDO;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;

/**
 * A fresh in-memory SQLite database with the forms schema for each test.
 * Nothing persists between tests: the database disappears with the adapter.
 */
trait SqliteDatabaseTrait
{
    private Adapter $adapter;

    protected function setUpDatabase(): void
    {
        $this->adapter = new Adapter(['driver' => 'Pdo_Sqlite', 'dsn' => 'sqlite::memory:']);
        $pdo           = $this->adapter->getDriver()->getConnection()->getResource();
        static::assertInstanceOf(PDO::class, $pdo);

        $sql = (string) file_get_contents(__DIR__ . '/../install-forms.sqlite.sql');
        foreach (array_filter(array_map(trim(...), explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @param array<string, scalar|null> $columns
     */
    private function insert(string $table, array $columns): int
    {
        $names        = implode(', ', array_map(static fn(string $name): string => "`{$name}`", array_keys($columns)));
        $placeholders = implode(', ', array_fill(0, count($columns), value: '?'));
        $this->adapter->query("INSERT INTO {$table} ({$names}) VALUES ({$placeholders})", array_values($columns));

        return (int) $this->adapter->getDriver()->getLastGeneratedValue();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = [];
        foreach ($this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }
}
