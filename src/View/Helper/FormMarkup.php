<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\View\Helper;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Render\FormMarkup as RenderFormMarkup;
use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\FormElement;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use LogicException;

/**
 * Laminas-View adapter that renders a {@see FormDefinition} via
 * {@see RenderFormMarkup}.
 *
 * Wires two view-helper plugins into the renderer at render time:
 *  - `escapeHtml` so HTML output is escaped per the application's
 *    view-helper config (charset, recursion limit, etc.) rather than the
 *    renderer's generic htmlspecialchars default.
 *  - `formElement` so every input dispatched through the renderer travels
 *    through the same helper as a hand-rolled `<?= $this->formElement($e) ?>`
 *    call. Delegators registered against `Laminas\Form\View\Helper\FormElement`
 *    (e.g. the page-cache CSRF-disable delegator shipped by
 *    `contenir/cache-laminas-mvc`) therefore observe formbuilder renders too.
 *
 * @api
 *
 * @mago-expect analysis:deprecated-class laminas-view 2.x helpers still need getView() here; constructor injection is a proposed follow-up.
 */
final class FormMarkup extends AbstractHelper
{
    public function __construct(
        private RenderFormMarkup $renderer = new RenderFormMarkup(),
    ) {}

    /**
     * @throws LogicException When no view with a `formElement` helper is attached.
     *
     * @mago-expect analysis:mixed-assignment View plugins are untyped; formElement is checked with instanceof.
     * @mago-expect analysis:invalid-callable The escapeHtml plugin is an invokable helper.
     * @mago-expect analysis:deprecated-method PhpRenderer::plugin() is the laminas-view 2.x way to reach other helpers.
     */
    public function __invoke(FormDefinition $definition, FormInterface $form, bool $preview = false): string
    {
        $view = $this->getView();
        if ($view instanceof PhpRenderer) {
            $escapeHtml = $view->plugin('escapeHtml');
            $this->renderer->setEscaper(static fn(string $value): string => (string) $escapeHtml($value));

            $formElement = $view->plugin('formElement');
            if ($formElement instanceof FormElement) {
                $this->renderer->setFormElementHelper($formElement);
            }
        }

        return $this->renderer->render($definition, $form, $preview);
    }
}
