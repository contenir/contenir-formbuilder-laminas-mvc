<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Render;

use Closure;
use Contenir\FormBuilder\Conditional\RuleEvaluator;
use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Html\FormContentSanitizer;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\File;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Select;
use Laminas\Form\ElementInterface;
use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\FormElement;
use LogicException;

use function count;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;
use function ucfirst;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

/**
 * Walks a {@see FormDefinition} and emits the form markup, dispatching each
 * input through the Laminas `formElement` view helper so that delegators
 * registered against `Laminas\Form\View\Helper\FormElement` (e.g. the
 * page-cache CSRF-disable delegator shipped by `contenir/cache-laminas-mvc`)
 * fire for formbuilder forms.
 *
 * Radio and multi-checkbox lists keep bespoke per-option markup because the
 * stock Laminas helpers wrap each `<input>` in a `<label>`, which is
 * incompatible with the BEM `formbuilder__control--checkbox-list-item`
 * pattern this project's CSS targets.
 *
 * The outer BEM structure (section / group / row / field / actions) and the
 * preview-mode class prefix (`form-preview__*`) match the framework-agnostic
 * {@see \Contenir\FormBuilder\Render\FormMarkup} renderer so consumers can
 * swap implementations without touching templates or stylesheets.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one renderer per element type); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (one renderer per element type); splitting it is a proposed follow-up.
 * @mago-expect lint:too-many-methods Kept whole for 2.0 (one renderer per element type); splitting it is a proposed follow-up.
 */
class FormMarkup
{
    private const array CLASSES = [
        'section'             => 'formbuilder__section',
        'section-title'       => 'formbuilder__section-title',
        'section-description' => 'formbuilder__section-description',
        'group'               => 'formbuilder__panel',
        'group-description'   => 'formbuilder__panel-description',
        'group-empty'         => 'formbuilder__panel-empty',
        'group-body'          => 'formbuilder__panel-body',
        'row'                 => 'formbuilder__row',
        'field'               => 'formbuilder__field',
        'content'             => 'formbuilder__content',
    ];

    private const array PREVIEW_CLASSES = [
        'section'             => 'form-preview__section',
        'section-title'       => 'form-preview__section-title',
        'section-description' => 'form-preview__section-description',
        'group'               => 'form-preview__group',
        'group-description'   => 'form-preview__group-description',
        'group-empty'         => 'form-preview__group-empty',
        'group-body'          => 'form-preview__group-body',
        'row'                 => 'form-preview__row',
        'field'               => 'form-preview__field',
        'content'             => 'form-preview__content',
    ];

    /** Values of the `multiple` attribute that leave a select single-valued. */
    private const array SINGLE_SELECT = [null, false, '', '0', 0];

    private RuleEvaluator $conditionalEvaluator;

    private ?FormElement $formElement = null;

    /** @var Closure(string): string */
    private Closure $escaper;

    private bool $preview = false;

    /** @var array<string, mixed> */
    private array $valueContext = [];

    public function __construct()
    {
        $this->conditionalEvaluator = new RuleEvaluator();
        $this->escaper              = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            encoding: 'UTF-8',
        );
    }

    public function render(FormDefinition $definition, FormInterface $form, bool $preview = false): string
    {
        $this->preview      = $preview;
        $this->valueContext = $this->buildValueContext($form);

        if (FormDefinition::LAYOUT_STEPPED === $definition->layoutMode && [] !== $definition->sections) {
            return $this->renderStepped($definition, $form);
        }

        $html = "<form{$this->htmlAttribs($this->formAttributes($form))}>";
        foreach ($definition->sections as $section) {
            $html .= $this->renderSection($section, $form);
        }

        return "{$html}{$this->renderActions($definition, $form)}</form>";
    }

    /**
     * Plug in a host-provided HTML escaper. Useful when the host
     * framework configures escape semantics (e.g. Laminas-View's
     * {@see \Laminas\View\Helper\EscapeHtml}). The callable takes a
     * string and returns the escaped string.
     *
     * @param callable(string): string $escaper
     */
    public function setEscaper(callable $escaper): void
    {
        $this->escaper = $escaper(...);
    }

    /**
     * Plug in the Laminas form-element view helper. The renderer hands every
     * non-choice-list input to this helper rather than building markup
     * directly, so the helper's delegator chain (most notably the
     * CSRF cache-disable delegator) intercepts every render.
     */
    public function setFormElementHelper(FormElement $formElement): void
    {
        $this->formElement = $formElement;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValueContext(FormInterface $form): array
    {
        $context = [];
        foreach ($form->getElements() as $element) {
            $context[(string) $element->getName()] = $element->getValue();
        }

        return $context;
    }

    /**
     * Structural wrapper classes. The public path uses `.formbuilder__*` so
     * sites can style rendered forms independently of any project-level
     * `.form` styles; the preview path uses `.form-preview__*` so the admin
     * builder can have a self-contained stylesheet.
     *
     * @param key-of<self::CLASSES> $element
     *
     * @mago-expect analysis:possibly-undefined-string-array-index Both maps share the keys the parameter type allows.
     * @mago-expect analysis:nullable-return-statement Both maps share the keys the parameter type allows.
     * @mago-expect analysis:invalid-return-statement Both maps share the keys the parameter type allows.
     */
    private function classFor(string $element): string
    {
        return ($this->preview ? self::PREVIEW_CLASSES : self::CLASSES)[$element];
    }

    /**
     * Attributes for a field's column wrapper. A conditional field carries
     * its rule as JSON for the client-side evaluator, and starts hidden when
     * the rule fails against the form's current values.
     *
     * @return array<string, string>
     */
    private function columnAttributes(FieldDefinition $field): array
    {
        $attribs = ['class' => $this->classFor('field')];
        if (null === $field->conditional || [] === $field->conditional) {
            return $attribs;
        }

        $encoded = json_encode($field->conditional, JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            return $attribs;
        }

        $attribs['data-form-conditional'] = $encoded;
        if (! $this->conditionalEvaluator->shouldShow($field->conditional, $this->valueContext)) {
            $attribs['hidden'] = 'hidden';
        }

        return $attribs;
    }

    private function escape(string $value): string
    {
        return ($this->escaper)($value);
    }

    /**
     * The form's own attributes, with `method`, `class` and `autocomplete`
     * defaulted when missing, and `enctype` set when the form has a file input.
     *
     * @return array<string, mixed>
     */
    private function formAttributes(FormInterface $form): array
    {
        $attribs  = $form->getAttributes();
        $defaults = [
            'method'       => 'post',
            'class'        => 'formbuilder__form formbuilder__form--stacked',
            'autocomplete' => 'on',
        ];
        foreach ($defaults as $key => $value) {
            $current = $attribs[$key] ?? null;
            if (null === $current || '' === $current) {
                $attribs[$key] = $value;
            }
        }

        foreach ($form->getElements() as $element) {
            if (! $element instanceof File) {
                continue;
            }

            $attribs['enctype'] = 'multipart/form-data';
        }

        return $attribs;
    }

    /**
     * Renders `name="value"` pairs. Attributes that are null, false, empty or
     * not scalar are omitted, so a boolean attribute set to false is absent
     * rather than present-and-empty.
     *
     * @param array<array-key, mixed> $attribs
     *
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; only scalars are rendered.
     */
    private function htmlAttribs(array $attribs): string
    {
        $out = '';
        foreach ($attribs as $name => $value) {
            if (! is_scalar($value) || false === $value || '' === $value) {
                continue;
            }

            $out .= sprintf(' %s="%s"', $name, $this->escape((string) $value));
        }

        return $out;
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Element values are untyped; non-scalar entries are dropped.
     */
    private function normaliseSelected(mixed $value): array
    {
        $values   = is_array($value) ? $value : [$value];
        $selected = [];
        foreach ($values as $entry) {
            if (! (is_scalar($entry) && '' !== $entry)) {
                continue;
            }

            $selected[] = (string) $entry;
        }

        return $selected;
    }

    /**
     * Flattens Laminas value options, which map a value to either a label or
     * an `{value, label}` spec, into `value => label` strings. Option groups
     * are not supported and are skipped.
     *
     * @param array<array-key, mixed> $valueOptions
     *
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment Value options are untyped; only scalar values and labels are kept.
     */
    private function optionPairs(array $valueOptions): array
    {
        $pairs = [];
        foreach ($valueOptions as $key => $option) {
            $value = is_array($option) ? $option['value'] ?? null : $key;
            $label = is_array($option) ? $option['label'] ?? '' : $option;
            if (is_scalar($value) && is_scalar($label)) {
                $pairs[(string) $value] = (string) $label;
            }
        }

        return $pairs;
    }

    /**
     * Single-value selects get `formbuilder__control--select` for the chevron
     * and appearance reset; multi-selects keep the native list.
     */
    private function prepareSelect(Select $element): Select
    {
        $class = (string) ($element->getAttribute('class') ?? '');
        if (
            in_array($element->getAttribute('multiple'), self::SINGLE_SELECT, strict: true)
            && ! str_contains($class, 'formbuilder__control--select')
        ) {
            $element->setAttribute('class', trim("{$class} formbuilder__control--select"));
        }

        return $element;
    }

    private function renderActions(FormDefinition $definition, FormInterface $form): string
    {
        $html = "<div class=\"formbuilder__actions formbuilder__actions--{$this->escape($definition->submitAlignment)}\">";

        if ($form->has(FormBuilderService::CSRF_NAME)) {
            $html .= $this->renderInput($form->get(FormBuilderService::CSRF_NAME));
        }

        if ($form->has(FormBuilderService::HONEYPOT_NAME)) {
            $honeypot = $this->renderInput($form->get(FormBuilderService::HONEYPOT_NAME));
            $html     .= "<div class=\"formbuilder__honeypot\" aria-hidden=\"true\">{$honeypot}</div>";
        }

        if ($form->has('_submit')) {
            $html .= $this->renderInput($form->get('_submit'));
        }

        return "{$html}</div>";
    }

    /**
     * A single checkbox: the hidden and checkbox inputs come from the Laminas
     * `formCheckbox` helper (via `formElement`, so delegators still observe
     * the render), with `formbuilder__control--checkbox` styling and the
     * label as a trailing sibling.
     *
     * @throws LogicException When no formElement helper is set.
     */
    private function renderCheckbox(Checkbox $element): string
    {
        $class = trim((string) preg_replace(
            '/\bformbuilder__control\b/',
            replacement: 'formbuilder__control--checkbox',
            subject: (string) ($element->getAttribute('class') ?? ''),
        ));
        if (! str_contains($class, 'formbuilder__control--checkbox')) {
            $class = trim("formbuilder__control--checkbox {$class}");
        }

        $element->setAttribute('class', $class);

        $id = (string) ($element->getAttribute('id') ?? '');
        if ('' === $id) {
            $id = (string) $element->getName();
            $element->setAttribute('id', $id);
        }

        $label = (string) $element->getLabel();
        $input = $this->renderViaFormElement($element);

        return '' === $label
            ? $input
            : sprintf(
                '%s<label class="formbuilder__label" for="%s">%s</label>',
                $input,
                $this->escape($id),
                $this->escape($label),
            );
    }

    private function renderChoiceList(MultiCheckbox $element, string $type): string
    {
        $name     = (string) $element->getName();
        $selected = $this->normaliseSelected($element->getValue());

        $html = '<div class="formbuilder__control--checkbox-list">';
        foreach ($this->optionPairs($element->getValueOptions()) as $value => $label) {
            $inputId = sprintf(
                '%s-%s',
                $name,
                (string) preg_replace('/[^a-zA-Z0-9_-]/', replacement: '-', subject: $value),
            );
            $attribs = [
                'type'    => $type,
                'name'    => 'radio' === $type ? $name : "{$name}[]",
                'id'      => $inputId,
                'value'   => $value,
                'class'   => 'formbuilder__control--checkbox',
                'checked' => in_array($value, $selected, strict: true) ? 'checked' : null,
            ];

            $html .=
                '<span class="formbuilder__control--checkbox-list-item">'
                . "<input{$this->htmlAttribs($attribs)}>"
                . "<label class=\"formbuilder__label\" for=\"{$this->escape($inputId)}\">{$this->escape(
                    $label,
                )}</label>"
                . '</span>';
        }

        return "{$html}</div>";
    }

    /**
     * Render a static content block. The HTML body lives on
     * `field->options['html']` and runs through FormContentSanitizer
     * here so the output is always safe to dump verbatim into the
     * page (script / iframe / form / event-handler attributes are
     * stripped, disallowed wrappers are unwrapped).
     *
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; the body is checked with is_string().
     */
    private function renderContent(FieldDefinition $field): string
    {
        $raw = $field->options['html'] ?? '';
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        $body = FormContentSanitizer::sanitize($raw);

        return (
            "<div{$this->htmlAttribs($this->columnAttributes($field))}>"
                . "<div class=\"{$this->classFor('content')}\">{$body}</div>"
                . '</div>'
        );
    }

    /**
     * @mago-expect analysis:mixed-assignment Laminas messages are untyped; each is checked before it is rendered.
     * @mago-expect analysis:unhandled-thrown-type Only fieldsets throw from getMessages(); rendered fields are plain elements.
     */
    private function renderErrors(ElementInterface $element): string
    {
        $items = '';
        foreach ($element->getMessages() as $message) {
            foreach (is_array($message) ? $message : [$message] as $text) {
                if (! is_scalar($text)) {
                    continue;
                }

                $items .= "<li>{$this->escape((string) $text)}</li>";
            }
        }

        return '' === $items ? '' : "<ul class=\"formbuilder__errors\">{$items}</ul>";
    }

    /**
     * Checkboxes draw their own label after the input, so the column label is
     * skipped for them. The controls sit in an `__element` wrapper that owns
     * the positioning context for absolutely placed affordances (such as a
     * date-picker clear button), relative to the input row and not the label.
     */
    private function renderField(FieldDefinition $field, ElementInterface $element): string
    {
        $html = "<div{$this->htmlAttribs($this->columnAttributes($field))}>";

        if (! $element instanceof Checkbox && $field->showLabel && null !== $field->label && '' !== $field->label) {
            $labelClass = $field->required ? 'formbuilder__label formbuilder__label--required' : 'formbuilder__label';
            $html       .= "<label class=\"{$labelClass}\" for=\"{$this->escape($field->name)}\">{$this->escape($field->label)}</label>";
        }

        $html .= "<div class=\"formbuilder__element\">{$this->renderInput($element)}</div>";
        $html .= $this->renderErrors($element);

        if (null !== $field->description && '' !== $field->description) {
            $html .= "<p class=\"formbuilder__description\">{$this->escape($field->description)}</p>";
        }

        return "{$html}</div>";
    }

    private function renderGroup(GroupDefinition $group, FormInterface $form): string
    {
        $rows = '';
        foreach ($group->rows as $row) {
            $rows .= $this->renderRow($row, $form);
        }

        $html = "<fieldset class=\"{$this->classFor('group')}\">";
        if (null !== $group->legend && '' !== $group->legend) {
            $html .= "<legend class=\"formbuilder__legend\">{$this->escape($group->legend)}</legend>";
        }

        if (null !== $group->description && '' !== $group->description) {
            $html .= "<p class=\"{$this->classFor('group-description')}\">{$this->escape($group->description)}</p>";
        }

        $html .= '' === $rows
            ? "<p class=\"{$this->classFor('group-empty')}\"><em>No fields in this group yet.</em></p>"
            : "<div class=\"{$this->classFor('group-body')}\">{$rows}</div>";

        return "{$html}</fieldset>";
    }

    /**
     * Radio and multi-checkbox lists keep the bespoke sibling-label markup
     * (Laminas wraps each option in a `<label>`); everything else goes
     * through the `formElement` view helper.
     *
     * @throws LogicException When no formElement helper is set.
     */
    private function renderInput(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Radio => $this->renderChoiceList($element, 'radio'),
            $element instanceof MultiCheckbox => $this->renderChoiceList($element, 'checkbox'),
            $element instanceof Checkbox => $this->renderCheckbox($element),
            $element instanceof Select => $this->renderViaFormElement($this->prepareSelect($element)),
            default => $this->renderViaFormElement($element),
        };
    }

    private function renderRow(RowDefinition $row, FormInterface $form): string
    {
        $cols = '';
        foreach ($row->fields as $field) {
            if ('content' === $field->type) {
                $cols .= $this->renderContent($field);
                continue;
            }

            if ($form->has($field->name)) {
                $cols .= $this->renderField($field, $form->get($field->name));
            }
        }

        return '' === $cols ? '' : "<div class=\"{$this->classFor('row')}\">{$cols}</div>";
    }

    private function renderSection(SectionDefinition $section, FormInterface $form): string
    {
        $body = $this->renderSectionBody($section, $form);
        if ('' === $body) {
            return '';
        }

        $heading = '';
        if (null !== $section->legend && '' !== $section->legend) {
            $heading .= "<h2 class=\"{$this->classFor('section-title')}\">{$this->escape($section->legend)}</h2>";
        }

        if (null !== $section->description && '' !== $section->description) {
            $heading .= "<p class=\"{$this->classFor(
                'section-description',
            )}\">{$this->escape($section->description)}</p>";
        }

        return '' === $heading ? $body : "<section class=\"{$this->classFor('section')}\">{$heading}{$body}</section>";
    }

    private function renderSectionBody(SectionDefinition $section, FormInterface $form): string
    {
        $html = '';
        foreach ($section->groups as $group) {
            $html .= $this->renderGroup($group, $form);
        }

        return $html;
    }

    /**
     * @param list<SectionDefinition> $sections
     */
    private function renderStepNav(array $sections): string
    {
        $html = '<ol class="formbuilder__steps" role="tablist">';
        foreach ($sections as $index => $section) {
            $isFirst = 0 === $index;
            $label   = null === $section->legend || '' === $section->legend
                ? ucfirst(str_replace(['-', '_'], replace: ' ', subject: $section->key))
                : $section->legend;

            $html .= sprintf(
                '<li><button type="button" class="formbuilder__step-tab%s" data-form-step-target="%s"%s>'
                    . '<span class="formbuilder__step-tab-index">%d</span> %s</button></li>',
                $isFirst ? ' is-active' : '',
                $this->escape($section->key),
                $isFirst ? '' : ' disabled',
                $index + 1,
                $this->escape($label),
            );
        }

        return "{$html}</ol>";
    }

    private function renderStepNavButtons(
        FormDefinition $definition,
        FormInterface $form,
        int $index,
        int $lastIndex,
    ): string {
        $html = '<nav class="formbuilder__step-nav">';
        if (0 !== $index) {
            $html .= '<button type="button" class="btn" data-form-step-prev>Previous</button>';
        }

        $html .= $index === $lastIndex
            ? $this->renderActions($definition, $form)
            : '<button type="button" class="btn btn--primary" data-form-step-next>Next</button>';

        return "{$html}</nav>";
    }

    /**
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; the class is checked with is_scalar().
     */
    private function renderStepped(FormDefinition $definition, FormInterface $form): string
    {
        $sections  = $definition->sections;
        $lastIndex = count($sections) - 1;

        $attribs          = $this->formAttributes($form);
        $class            = $attribs['class'] ?? '';
        $attribs['class'] = trim(
            (is_scalar($class) ? (string) $class : '') . ' formbuilder__form--stepped',
        );
        $enctype = $attribs['enctype'] ?? null;
        unset($attribs['enctype']);
        $attribs['data-form-stepper'] = 'true';
        $attribs['enctype']           = $enctype;

        $html = "<form{$this->htmlAttribs($attribs)}>{$this->renderStepNav($sections)}";

        foreach ($sections as $index => $section) {
            $html .= sprintf(
                '<section class="formbuilder__step%s" data-form-step="%s"%s>',
                0 === $index ? ' is-active' : '',
                $this->escape($section->key),
                0 === $index ? '' : ' hidden',
            );

            if (null !== $section->legend && '' !== $section->legend) {
                $html .= "<h2 class=\"formbuilder__step-title\">{$this->escape($section->legend)}</h2>";
            }

            if (null !== $section->description && '' !== $section->description) {
                $html .= "<p class=\"formbuilder__step-description\">{$this->escape($section->description)}</p>";
            }

            $html .= $this->renderSectionBody($section, $form);
            $html .= $this->renderStepNavButtons($definition, $form, $index, $lastIndex);
            $html .= '</section>';
        }

        return "{$html}</form>";
    }

    /**
     * @throws LogicException When no formElement helper is set.
     */
    private function renderViaFormElement(ElementInterface $element): string
    {
        if (null === $this->formElement) {
            throw new LogicException(
                'FormMarkup requires a Laminas\\Form\\View\\Helper\\FormElement helper; call setFormElementHelper() before render().',
            );
        }

        return ($this->formElement)($element);
    }
}
