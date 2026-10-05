<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Container;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function get_debug_type;
use function is_array;
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
