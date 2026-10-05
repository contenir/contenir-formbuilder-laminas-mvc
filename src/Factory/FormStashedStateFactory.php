<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\View\Helper\FormStashedState;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * @api
 */
final class FormStashedStateFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): FormStashedState
    {
        return new FormStashedState(Services::get($container, FormStateStash::class, FormStateStash::class));
    }
}
