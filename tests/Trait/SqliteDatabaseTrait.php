<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Trait;

use PDO;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\Pdo\Statement;
use PhpDb\Sqlite\AdapterPlatform;
use PhpDb\Sqlite\Pdo\Connection;
use PhpDb\Sqlite\Pdo\Driver;
use PhpDb\Sqlite\Pdo\Feature\SqliteRowCounter;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function trim;

/**
 * A fresh in-memory SQLite database with the forms schema for each test.
 * Nothing persists between tests: the database disappears with the adapter.
 */
trait SqliteDatabaseTrait
{
    private AdapterInterface $adapter;

    private PDO $pdo;

    protected function setUpDatabase(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = (string) file_get_contents(__DIR__ . '/../install-forms.sqlite.sql');
        foreach (array_filter(array_map(trim(...), explode(';', $sql))) as $statement) {
            $this->pdo->exec($statement);
        }

        $driver = new Driver(
            connection: new Connection($this->pdo),
            statementPrototype: new Statement(),
            features: [new SqliteRowCounter()],
        );

        $this->adapter = new Adapter($driver, new AdapterPlatform($driver));
    }

    /**
     * @param array<string, scalar|null> $columns
     */
    private function insert(string $table, array $columns): int
    {
        $names        = implode(', ', array_map(static fn(string $name): string => "`{$name}`", array_keys($columns)));
        $placeholders = implode(', ', array_fill(0, count($columns), value: '?'));
        $statement    = $this->pdo->prepare("INSERT INTO {$table} ({$names}) VALUES ({$placeholders})");
        $statement->execute(array_values($columns));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        /** @var list<array<string, mixed>> */
        return false === $statement ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
