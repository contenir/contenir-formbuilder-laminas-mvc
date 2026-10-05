<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * @api
 */
final class StoreSubmissionRegistrarFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): StoreSubmissionRegistrar
    {
        return new StoreSubmissionRegistrar(
            Services::get($container, LaminasDbEntryRepository::class, LaminasDbEntryRepository::class),
        );
    }
}
