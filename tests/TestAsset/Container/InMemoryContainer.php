<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Container;

use Override;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function array_key_exists;

/**
 * A PSR-11 container over a fixed map of services, for factory tests.
 */
final class InMemoryContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private array $services = [],
    ) {}

    #[Override]
    public function get(string $id): mixed
    {
        if (! $this->has($id)) {
            throw new class("Service \"{$id}\" not found.") extends RuntimeException implements
                NotFoundExceptionInterface {};
        }

        return $this->services[$id];
    }

    #[Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
