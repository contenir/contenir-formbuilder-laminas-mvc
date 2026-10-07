<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Loader\PhpDbFormLoader;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * Uses the php-db adapter named by `formbuilder.db_adapter`
 * (default `PhpDb\Adapter\AdapterInterface`).
 *
 * @api
 */
final class PhpDbFormLoaderFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException When the adapter service is not a php-db adapter.
     */
    public function __invoke(ContainerInterface $container): PhpDbFormLoader
    {
        return new PhpDbFormLoader(Services::adapter($container));
    }
}
