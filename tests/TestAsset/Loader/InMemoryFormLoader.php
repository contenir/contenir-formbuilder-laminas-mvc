<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\FormLoaderInterface;
use Override;

use function array_values;

/**
 * Serves fixed definitions, keyed by slug.
 */
final class InMemoryFormLoader implements FormLoaderInterface
{
    /** @var array<string, FormDefinition> */
    private array $forms = [];

    public function __construct(FormDefinition ...$forms)
    {
        foreach ($forms as $form) {
            $this->forms[$form->slug] = $form;
        }
    }

    #[Override]
    public function listSummaries(): array
    {
        return [];
    }

    #[Override]
    public function loadAll(): array
    {
        return array_values($this->forms);
    }

    #[Override]
    public function loadById(int $formId): ?FormDefinition
    {
        foreach ($this->forms as $form) {
            if ($form->id === $formId) {
                return $form;
            }
        }

        return null;
    }

    #[Override]
    public function loadBySlug(string $slug): ?FormDefinition
    {
        return $this->forms[$slug] ?? null;
    }
}
