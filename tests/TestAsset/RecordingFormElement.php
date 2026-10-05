<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset;

use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormElement;

use function array_map;

/**
 * `FormElement` helper subclass that records every element handed to it.
 *
 * Tests use it to assert that specific element classes (most importantly
 * the Csrf element — which is the whole reason this renderer routes
 * through `formElement`) reach the helper.
 *
 * Delegates rendering to the parent so the produced HTML still matches the
 * real Laminas helper output.
 */
final class RecordingFormElement extends FormElement
{
    /** @var list<ElementInterface> */
    public array $rendered = [];

    public function render(ElementInterface $element): string
    {
        $this->rendered[] = $element;
        return parent::render($element);
    }

    /** @return list<class-string<ElementInterface>> */
    public function renderedClasses(): array
    {
        return array_map(static fn($element) => $element::class, $this->rendered);
    }
}
