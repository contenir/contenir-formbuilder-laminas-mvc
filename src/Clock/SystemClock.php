<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Clock;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * The current time. Used for entry timestamps when no
 * `Psr\Clock\ClockInterface` service is registered.
 *
 * @internal
 */
final class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
