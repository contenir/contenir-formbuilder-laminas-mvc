<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Observer;

use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use SplObserver;
use SplSubject;

/**
 * Records a copy of the submission registry each time it is notified, and
 * optionally writes values back into the registry as a storing registrar would.
 */
final class RecordingObserver implements SplObserver
{
    /** @var list<array<string, mixed>> */
    public array $registries = [];

    /** @var list<SplSubject> */
    public array $subjects = [];

    /**
     * @param array<string, mixed> $writes
     */
    public function __construct(
        private readonly array $writes = [],
    ) {}

    #[Override]
    public function update(SplSubject $subject): void
    {
        $this->subjects[] = $subject;
        if (! $subject instanceof BuilderForm || null === $subject->registry) {
            return;
        }

        $this->registries[] = $subject->registry->getArrayCopy();
        foreach ($this->writes as $key => $value) {
            $subject->registry[$key] = $value;
        }
    }
}
