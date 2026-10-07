<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Container;

use PhpDb\Adapter\AdapterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Typed access to container services and the `formbuilder` configuration,
 * so misconfiguration fails with a clear message rather than a TypeError
 * deep inside construction.
 *
 * @internal
 */
final readonly class Services
{
    /**
     * The php-db adapter named by `formbuilder.db_adapter`, by default the
     * `PhpDb\Adapter\AdapterInterface` service.
     *
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException When the service is not a php-db adapter.
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the adapter id is checked with is_string().
     */
    public static function adapter(ContainerInterface $container): AdapterInterface
    {
        $adapterId = self::config($container)['db_adapter'] ?? null;

        return self::get(
            $container,
            is_string($adapterId) && '' !== $adapterId ? $adapterId : AdapterInterface::class,
            AdapterInterface::class,
        );
    }

    /**
     * The `formbuilder` section of the application configuration.
     *
     * @return array<array-key, mixed>
     *
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each level is checked with is_array().
     */
    public static function config(ContainerInterface $container): array
    {
        $config  = $container->has('config') ? $container->get('config') : [];
        $section = is_array($config) ? $config['formbuilder'] ?? [] : [];

        return is_array($section) ? $section : [];
    }

    /**
     * @template S of object
     *
     * @param class-string<S> $type
     *
     * @return S
     *
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException When the service is not an instance of $type.
     *
     * @mago-expect analysis:mixed-assignment Container services are untyped; the type is checked here.
     */
    public static function get(ContainerInterface $container, string $name, string $type): object
    {
        $service = $container->get($name);
        if (! $service instanceof $type) {
            throw new UnexpectedValueException(sprintf(
                'Service "%s" must be an instance of %s, %s given.',
                $name,
                $type,
                get_debug_type($service),
            ));
        }

        return $service;
    }

    /**
     * The service when it is registered and of the expected type, otherwise null.
     *
     * @template S of object
     *
     * @param class-string<S> $type
     *
     * @return S|null
     *
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Container services are untyped; the type is checked here.
     */
    public static function optional(ContainerInterface $container, string $name, string $type): ?object
    {
        if (! $container->has($name)) {
            return null;
        }

        $service = $container->get($name);

        return $service instanceof $type ? $service : null;
    }
}
