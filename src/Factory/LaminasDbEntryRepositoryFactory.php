<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function is_string;

/**
 * Uses the database adapter named by `formbuilder.db_adapter`
 * (default `Laminas\Db\Adapter\Adapter`).
 *
 * @api
 */
final class LaminasDbEntryRepositoryFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the adapter id is checked with is_string().
     */
    public function __invoke(ContainerInterface $container): LaminasDbEntryRepository
    {
        $adapterId = Services::config($container)['db_adapter'] ?? null;

        return new LaminasDbEntryRepository(Services::get(
            $container,
            is_string($adapterId) ? $adapterId : Adapter::class,
            Adapter::class,
        ));
    }
}
