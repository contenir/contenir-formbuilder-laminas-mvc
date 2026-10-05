<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\View\Helper;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Laminas\View\Helper\AbstractHelper;

/**
 * View-side accessor for {@see FormStateStash}.
 *
 * Section partials call `$this->formStashedState($slug)` to read-and-
 * clear any stashed state for that form. Returns null when there is
 * nothing pending — the partial then renders an empty form.
 *
 * Kept deliberately thin: the partial is responsible for hydrating the
 * Laminas form (`setData()` for values, `setMessages()` for errors).
 * That keeps this helper free of any form-building dependency.
 *
 * @api
 *
 * @mago-expect analysis:deprecated-class Kept on AbstractHelper for 2.0 so existing helper configuration keeps working; see the proposed follow-up.
 */
class FormStashedState extends AbstractHelper
{
    public function __construct(
        private FormStateStash $stash,
    ) {}

    /**
     * @return array{values: array<array-key, mixed>, errors: array<array-key, mixed>}|null
     */
    public function __invoke(string $slug): ?array
    {
        return $this->stash->consume($slug);
    }
}
