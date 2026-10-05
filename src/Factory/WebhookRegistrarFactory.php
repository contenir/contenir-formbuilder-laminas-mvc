<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The PSR-3 logger is used when one is registered.
 *
 * @api
 */
final class WebhookRegistrarFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): WebhookRegistrar
    {
        return new WebhookRegistrar(Services::optional($container, LoggerInterface::class, LoggerInterface::class));
    }
}
