<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Db;

use ArrayIterator;
use Laminas\Db\Adapter\Driver\ResultInterface;

use function count;

/**
 * A query result over fixed rows, for loader tests that feed the hydrator
 * rows a real schema cannot produce (missing columns, non-array rows).
 */
final class ArrayResult extends ArrayIterator implements ResultInterface
{
    /**
     * @param list<mixed> $rows
     */
    public function __construct(array $rows)
    {
        parent::__construct($rows);
    }

    public function buffer(): void {}

    public function count(): int
    {
        return count($this->getArrayCopy());
    }

    public function getAffectedRows(): int
    {
        return 0;
    }

    public function getFieldCount(): int
    {
        return 0;
    }

    public function getGeneratedValue(): mixed
    {
        return null;
    }

    public function getResource(): mixed
    {
        return null;
    }

    public function isBuffered(): bool
    {
        return true;
    }

    public function isQueryResult(): bool
    {
        return true;
    }
}
