<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Trait;

use Laminas\Session\Container;
use Laminas\Session\SessionManager;
use Laminas\Session\Storage\ArrayStorage;

/**
 * Backs the CSRF element's session container with array storage, so building
 * and validating forms never touches PHP's native session. The default
 * manager is a process-wide static, so it is reset after every test.
 */
trait InMemorySessionTrait
{
    protected function setUpInMemorySession(): void
    {
        Container::setDefaultManager(new SessionManager(storage: new ArrayStorage()));
    }

    protected function tearDownInMemorySession(): void
    {
        Container::setDefaultManager(null);
    }
}
