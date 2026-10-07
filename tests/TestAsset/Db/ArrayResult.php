<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Db;

use ArrayIterator;
use Override;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\ResultSet\ResultSet;
use PhpDb\ResultSet\ResultSetInterface;

/**
 * A query result over fixed rows, for loader tests that feed the hydrator
 * rows a real schema cannot produce (missing columns, non-array rows).
 *
 * @extends ArrayIterator<int, mixed>
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

    #[Override]
    public function buffer(): void {}

    #[Override]
    public function getAffectedRows(): int
    {
        return 0;
    }

    #[Override]
    public function getFieldCount(): int
    {
        return 0;
    }

    #[Override]
    public function getGeneratedValue(): string|int|false|null
    {
        return null;
    }

    #[Override]
    public function getQueryResult(?ResultSetInterface $resultPrototype = null): ResultSetInterface
    {
        return new ResultSet();
    }

    #[Override]
    public function getResource(): mixed
    {
        return null;
    }

    #[Override]
    public function isBuffered(): ?bool
    {
        return true;
    }

    #[Override]
    public function isQueryResult(): bool
    {
        return true;
    }
}
