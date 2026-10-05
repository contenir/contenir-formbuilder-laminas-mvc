<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Loader;

use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Loader\RowReader;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class RowReaderTest extends TestCase
{
    #[Test]
    public function loaderSkipsRowsThatAreNotArrays(): void
    {
        $result = $this->createStub(ResultInterface::class);
        $result->method('valid')->willReturnOnConsecutiveCalls(true, true, false);
        $result->method('current')->willReturnOnConsecutiveCalls('not a row', ['form_id' => 3, 'slug' => 'c']);
        $statement = $this->createStub(StatementInterface::class);
        $statement->method('execute')->willReturn($result);
        $adapter = $this->createStub(Adapter::class);
        $adapter->method('createStatement')->willReturn($statement);

        static::assertSame(
            [['id' => 3, 'slug' => 'c', 'title' => '', 'status' => '']],
            (new LaminasDbFormLoader($adapter))->listSummaries(),
        );
    }

    #[Test]
    public function readsTypedValuesWithDefaults(): void
    {
        $read = new RowReader([
            'int'    => '7',
            'bad'    => 'x',
            'flag'   => '0',
            'text'   => 12,
            'array'  => ['x'],
            'json'   => '{"a":1}',
            'scalar' => '"s"',
            'empty'  => '',
        ]);

        static::assertSame(
            [7, 0, 3, null, false, true, '12', 'd', null, ['a' => 1], [], null, null, null],
            [
                $read->int('int'),
                $read->int('missing'),
                $read->int('bad', default: 3),
                $read->nullableInt('missing'),
                $read->bool('flag', default: true),
                $read->bool('array', default: true),
                $read->string('text'),
                $read->string('array', default: 'd'),
                $read->nullableString('missing'),
                $read->json('json'),
                $read->json('scalar'),
                $read->nullableJson('empty'),
                $read->nullableJson('array'),
                $read->nullableJson('missing'),
            ],
        );
    }
}
