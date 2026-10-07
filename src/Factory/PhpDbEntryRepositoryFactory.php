<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Clock\SystemClock;
use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Repository\PhpDbEntryRepository;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * Uses the php-db adapter named by `formbuilder.db_adapter`
 * (default `PhpDb\Adapter\AdapterInterface`), and the `Psr\Clock\ClockInterface`
 * service for entry timestamps when one is registered.
 *
 * @api
 */
final class PhpDbEntryRepositoryFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException When the adapter service is not a php-db adapter.
     */
    public function __invoke(ContainerInterface $container): PhpDbEntryRepository
    {
        return new PhpDbEntryRepository(
            Services::adapter($container),
            Services::optional($container, ClockInterface::class, ClockInterface::class) ?? new SystemClock(),
        );
    }
}
