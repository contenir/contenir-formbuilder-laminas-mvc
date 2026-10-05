<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Container;

use ArrayObject;
use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[Group('unit')]
final class ServicesTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array<array-key, mixed>}>
     */
    public static function configProvider(): array
    {
        return [
            'no config service'    => [[], []],
            'config not an array'  => [['config' => 'x'], []],
            'no formbuilder key'   => [['config' => ['other' => 1]], []],
            'section not an array' => [['config' => ['formbuilder' => 'x']], []],
            'section'              => [['config' => ['formbuilder' => ['db_adapter' => 'db']]], ['db_adapter' => 'db']],
        ];
    }

    /**
     * @param array<string, mixed> $services
     * @param array<array-key, mixed> $expected
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function configReturnsTheFormbuilderSection(array $services, array $expected): void
    {
        static::assertSame($expected, Services::config(new InMemoryContainer($services)));
    }

    #[Test]
    public function getRejectsAServiceOfTheWrongType(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Service "svc" must be an instance of ArrayObject, stdClass given.');

        Services::get(new InMemoryContainer(['svc' => new stdClass()]), 'svc', ArrayObject::class);
    }

    #[Test]
    public function getReturnsAServiceOfTheExpectedType(): void
    {
        $service = new ArrayObject();

        static::assertSame($service, Services::get(
            new InMemoryContainer(['svc' => $service]),
            'svc',
            ArrayObject::class,
        ));
    }

    #[Test]
    public function optionalReturnsNullWhenMissingOrOfTheWrongType(): void
    {
        $container = new InMemoryContainer(['wrong' => new stdClass(), 'right' => new ArrayObject()]);

        static::assertSame(
            [null, null, true],
            [
                Services::optional($container, 'missing', ArrayObject::class),
                Services::optional($container, 'wrong', ArrayObject::class),
                Services::optional($container, 'right', ArrayObject::class) instanceof ArrayObject,
            ],
        );
    }
}
