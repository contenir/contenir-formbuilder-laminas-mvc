<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc;

/**
 * Laminas MVC entry point. Routes + view helpers + controller +
 * service factories are merged in via ConfigProvider.
 *
 * @api
 */
final class Module
{
    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return (new ConfigProvider())();
    }
}
