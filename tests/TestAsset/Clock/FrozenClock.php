<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Clock;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Always the same instant, 2026-10-06 09:30:15 UTC unless given another.
 */
final readonly class FrozenClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now = new DateTimeImmutable('2026-10-06 09:30:15'),
    ) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
