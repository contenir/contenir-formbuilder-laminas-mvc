<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset;

use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormElement;

use function sprintf;

/**
 * `FormElement` helper that renders each element as a short placeholder tag,
 * so renderer tests can assert the surrounding markup exactly without
 * depending on the Laminas helpers' own attribute output.
 */
final class PlaceholderFormElement extends FormElement
{
    public function render(ElementInterface $element): string
    {
        return sprintf('<input name="%s">', (string) $element->getName());
    }
}
