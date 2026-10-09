<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\Mail\Transport\TransportInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Needs a `Contenir\Mail\Transport\TransportInterface` service; the PSR-3
 * logger is used when one is registered.
 *
 * @api
 */
final class EmailNotificationRegistrarFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): EmailNotificationRegistrar
    {
        return new EmailNotificationRegistrar(
            Services::get($container, TokenReplacer::class, TokenReplacer::class),
            Services::get($container, TransportInterface::class, TransportInterface::class),
            Services::optional($container, LoggerInterface::class, LoggerInterface::class),
        );
    }
}
