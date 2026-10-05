<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset;

use Laminas\Form\ConfigProvider as FormConfigProvider;
use Laminas\Form\View\Helper\FormElement;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use LogicException;

/**
 * Builds a {@see PhpRenderer} pre-wired with the laminas-form view-helper
 * aliases and factories, so tests can exercise the renderer with a real
 * `formElement` helper rather than a hand-rolled stub.
 *
 * Each call returns a fresh renderer/helper pair so tests stay isolated.
 */
final class ViewRendererBuilder
{
    /** @return array{0: PhpRenderer, 1: FormElement} */
    public static function build(): array
    {
        $formConfig    = (new FormConfigProvider())();
        $helperManager = new HelperPluginManager(
            new ServiceManager(),
            $formConfig['view_helpers'] ?? [],
        );

        $renderer = new PhpRenderer();
        $renderer->setHelperPluginManager($helperManager);

        $formElement = $helperManager->get('formElement');
        if (! $formElement instanceof FormElement) {
            throw new LogicException('laminas-form did not register the formElement helper.');
        }

        return [$renderer, $formElement];
    }
}
