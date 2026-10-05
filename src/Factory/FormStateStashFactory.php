<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;

/**
 * The stash uses its own session container namespace.
 *
 * @api
 */
final class FormStateStashFactory
{
    public function __invoke(): FormStateStash
    {
        return new FormStateStash();
    }
}
