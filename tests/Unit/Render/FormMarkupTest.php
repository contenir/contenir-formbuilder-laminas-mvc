<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Render\FormMarkup;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\RecordingFormElement;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\ViewRendererBuilder;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Email;
use Laminas\Form\Element\File;
use Laminas\Form\Element\Hidden;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Form;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormMarkupTest extends TestCase
{
    private FormMarkup $renderer;
    private RecordingFormElement $formElement;

    /** @return array<string, array{0: string, 1: string, 2: string, 3: class-string}> */
    public static function elementsRoutedThroughFormElementProvider(): array
    {
        return [
            'text'  => ['makeText', 'text', 'first_name', Text::class],
            'email' => ['makeText', 'email', 'email', Text::class], // helper just creates a Text
            'hidden'   => ['makeHidden', 'hidden', 'token', Hidden::class],
            'textarea' => ['makeTextarea', 'textarea', 'notes', Textarea::class],
            'submit'   => ['makeSubmit', 'submit', '_submit', Submit::class],
        ];
    }

    private static function makeHidden(string $name): Hidden
    {
        return new Hidden($name);
    }

    private static function makeSubmit(string $name): Submit
    {
        $element = new Submit($name);
        $element->setValue('Submit');
        return $element;
    }

    private static function makeText(string $name): Text
    {
        return new Text($name);
    }

    private static function makeTextarea(string $name): Textarea
    {
        return new Textarea($name);
    }

    #[Test]
    public function choiceListsAcceptValueAndLabelSpecs(): void
    {
        $radio = new Radio('size');
        $radio->setValueOptions([
            's'   => 'Small',
            'x'   => ['value' => 'l', 'label' => 'Large'],
            'bad' => ['label' => ['x']],
        ]);
        $form = $this->buildForm();
        $form->add($radio);

        $html = $this->renderer->render(FormDefinitionFactory::withFields([FormDefinitionFactory::field(
            'radio',
            'size',
        )]), $form);

        static::assertStringContainsString('for="size-l">Large</label>', $html);
        static::assertStringNotContainsString('size-bad', $html);
    }

    #[Test]
    public function conditionalFieldGetsHiddenAttributeWhenRuleFails(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('details'));

        $rule = [
            'show_when' => [
                'all' => [
                    ['field' => 'opt_in', 'op' => 'equals', 'value' => 'yes'],
                ],
            ],
        ];

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'details', ['conditional' => $rule]),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('data-form-conditional=', $html);
        static::assertStringContainsString(' hidden="hidden"', $html);
    }

    #[Test]
    public function conditionalFieldVisibleWhenRulePasses(): void
    {
        $form = $this->buildForm();
        $form->add((new Text('opt_in'))->setValue('yes'));
        $form->add(new Text('details'));

        $rule = [
            'show_when' => [
                'all' => [
                    ['field' => 'opt_in', 'op' => 'equals', 'value' => 'yes'],
                ],
            ],
        ];

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'opt_in'),
            FormDefinitionFactory::field('text', 'details', ['conditional' => $rule]),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('data-form-conditional=', $html);
        static::assertStringNotContainsString(' hidden="hidden"', $html);
    }

    #[Test]
    public function contentFieldEmitsSanitizedHtmlBody(): void
    {
        $form = $this->buildForm();

        $contentField = FormDefinitionFactory::field('content', 'intro', [
            'options' => ['html' => '<p>Welcome <strong>back</strong></p>'],
        ]);
        $def = FormDefinitionFactory::withFields([$contentField]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('<div class="formbuilder__content">', $html);
        static::assertStringContainsString('<p>Welcome <strong>back</strong></p>', $html);
    }

    #[Test]
    public function contentFieldWithEmptyHtmlIsSkipped(): void
    {
        $form = $this->buildForm();

        $blank = FormDefinitionFactory::field('content', 'blank', ['options' => ['html' => '   ']]);
        $def   = FormDefinitionFactory::withFields([$blank]);

        $html = $this->renderer->render($def, $form);

        static::assertStringNotContainsString('formbuilder__content', $html);
    }

    #[Test]
    public function csrfElementIsRoutedThroughFormElementHelper(): void
    {
        $form = $this->buildForm();
        $form->add(new Csrf(FormBuilderService::CSRF_NAME));
        $def = FormDefinitionFactory::withFields([]);

        $this->renderer->render($def, $form);

        static::assertContains(Csrf::class, $this->formElement->renderedClasses());
    }

    #[Test]
    public function customEscaperIsUsedForLabelText(): void
    {
        $this->renderer->setEscaper(static fn(string $value): string => "[ESC:{$value}]");

        $form = $this->buildForm();
        $form->add(new Text('first_name'));
        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name', ['label' => 'Name']),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('[ESC:Name]', $html);
    }

    #[Test]
    public function emptyGroupEmitsPlaceholderCopy(): void
    {
        $form     = $this->buildForm();
        $emptyRow = new RowDefinition(null, 0, []);
        $group    = new GroupDefinition(null, 'Empty', null, 0, [$emptyRow]);
        $section  = new SectionDefinition(null, 'main', null, null, 0, [$group]);
        $def      = new FormDefinition(
            id: 1,
            slug: 'e',
            title: 'E',
            sections: [$section],
        );

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString(
            '<p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p>',
            $html,
        );
    }

    #[Test]
    public function errorMessagesRenderedAsList(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('email'));
        $form->setMessages(['email' => ['Invalid email address']]);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'email'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString(
            '<ul class="formbuilder__errors"><li>Invalid email address</li></ul>',
            $html,
        );
    }

    #[Test]
    public function errorMessagesSkipNonScalarEntries(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('name'));
        $form->get('name')->setMessages(['isEmpty' => 'Required', 'nested' => ['a', ['b']]]);

        $html = $this->renderer->render(FormDefinitionFactory::withFields([FormDefinitionFactory::field(
            'text',
            'name',
        )]), $form);

        static::assertStringContainsString('<ul class="formbuilder__errors"><li>Required</li><li>a</li></ul>', $html);
    }

    #[Test]
    public function fieldDescriptionRenderedAfterInput(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('promo_code'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'promo_code', ['description' => 'Optional']),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('<p class="formbuilder__description">Optional</p>', $html);
    }

    #[Test]
    public function fieldLabelOmittedWhenShowLabelIsFalse(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name', ['showLabel' => false]),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringNotContainsString('<label class="formbuilder__label"', $html);
    }

    #[Test]
    public function fileFieldSwitchesFormEnctypeToMultipart(): void
    {
        $form    = $this->buildForm();
        $element = new File('upload');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('file', 'upload'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('enctype="multipart/form-data"', $html);
        static::assertContains(File::class, $this->formElement->renderedClasses());
    }

    #[Test]
    public function groupLegendAndDescriptionRendered(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row = new RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group = new GroupDefinition(
            null,
            'Personal',
            'Personal details',
            0,
            [$row],
        );
        $section = new SectionDefinition(null, 'main', null, null, 0, [$group]);
        $def     = new FormDefinition(
            id: 1,
            slug: 'g',
            title: 'G',
            sections: [$section],
        );

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('<legend class="formbuilder__legend">Personal</legend>', $html);
        static::assertStringContainsString(
            '<p class="formbuilder__panel-description">Personal details</p>',
            $html,
        );
    }

    #[Test]
    public function honeypotElementWrappedInHoneypotDiv(): void
    {
        $form = $this->buildForm();
        $form->add(new Text(FormBuilderService::HONEYPOT_NAME));
        $def = FormDefinitionFactory::withFields([]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('<div class="formbuilder__honeypot" aria-hidden="true">', $html);
    }

    #[Test]
    public function multiCheckboxArrayDefaultMarksMatchingOptionsChecked(): void
    {
        $form    = $this->buildForm();
        $element = new MultiCheckbox('interests');
        $element->setValueOptions(['news' => 'News', 'events' => 'Events', 'jobs' => 'Jobs']);
        $element->setValue(['news', 'jobs']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multicheckbox', 'interests'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertMatchesRegularExpression('/id="interests-news"[^>]*checked="checked"/', $html);
        static::assertMatchesRegularExpression('/id="interests-jobs"[^>]*checked="checked"/', $html);
        static::assertDoesNotMatchRegularExpression('/id="interests-events"[^>]*checked="checked"/', $html);
    }

    #[Test]
    public function multiCheckboxListEmitsSiblingLabelMarkupAndBypassesFormElementHelper(): void
    {
        $form    = $this->buildForm();
        $element = new MultiCheckbox('interests');
        $element->setValueOptions(['news' => 'News', 'events' => 'Events']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multicheckbox', 'interests'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertNotContains(MultiCheckbox::class, $this->formElement->renderedClasses());
        static::assertStringContainsString('<input type="checkbox" name="interests[]"', $html);
        static::assertStringContainsString(
            '<label class="formbuilder__label" for="interests-news">News</label>',
            $html,
        );
    }

    #[Test]
    public function multiSelectKeepsBracketNameAndSkipsSelectModifierClass(): void
    {
        $form    = $this->buildForm();
        $element = new Select('roles');
        $element->setAttribute('multiple', true);
        $element->setValueOptions(['admin' => 'Admin', 'editor' => 'Editor']);
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multiselect', 'roles'),
        ]);

        $html = $this->renderer->render($def, $form);

        // Laminas's escape helper renders the brackets as HTML entities;
        // the browser decodes them back to `[]` either way.
        static::assertMatchesRegularExpression('/name="roles(\[\]|&#x5B;&#x5D;)"/', $html);
        static::assertStringNotContainsString('formbuilder__control--select', $html);
    }

    #[Test]
    public function previewModeSwapsToPreviewClassPrefix(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row = new RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group   = new GroupDefinition(null, 'Personal', null, 0, [$row]);
        $section = new SectionDefinition(null, 'main', 'Main', null, 0, [$group]);
        $def     = new FormDefinition(
            id: 1,
            slug: 'preview',
            title: 'Preview',
            sections: [$section],
        );

        $html = $this->renderer->render($def, $form, preview: true);

        static::assertStringContainsString('class="form-preview__section"', $html);
        static::assertStringContainsString('class="form-preview__field"', $html);
        static::assertStringContainsString('class="form-preview__row"', $html);
    }

    #[Test]
    public function radioListEmitsSiblingLabelMarkupAndBypassesFormElementHelper(): void
    {
        $form    = $this->buildForm();
        $element = new Radio('contact_method');
        $element->setValueOptions(['email' => 'Email', 'phone' => 'Phone']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('radio', 'contact_method'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertNotContains(Radio::class, $this->formElement->renderedClasses());
        static::assertStringContainsString('formbuilder__control--checkbox-list', $html);
        static::assertStringContainsString('<span class="formbuilder__control--checkbox-list-item">', $html);
        static::assertStringContainsString('<input type="radio" name="contact_method"', $html);
        static::assertStringContainsString(
            '<label class="formbuilder__label" for="contact_method-email">Email</label>',
            $html,
        );
    }

    #[Test]
    public function radioOptionMatchingSelectedValueGetsCheckedAttribute(): void
    {
        $form    = $this->buildForm();
        $element = new Radio('contact_method');
        $element->setValueOptions(['email' => 'Email', 'phone' => 'Phone']);
        $element->setValue('phone');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('radio', 'contact_method'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertMatchesRegularExpression(
            '/id="contact_method-phone"[^>]*checked="checked"/',
            $html,
        );
    }

    #[Test]
    public function rendersOpeningAndClosingFormTags(): void
    {
        $form = $this->buildForm();
        $def  = FormDefinitionFactory::withFields([]);

        $html = $this->renderer->render($def, $form);

        static::assertStringStartsWith('<form', $html);
        static::assertStringContainsString('class="formbuilder__form formbuilder__form--stacked"', $html);
        static::assertMatchesRegularExpression('/method="(post|POST)"/', $html);
        static::assertStringContainsString('autocomplete="on"', $html);
        static::assertStringEndsWith('</form>', $html);
    }

    #[Test]
    public function renderWithoutFormElementHelperThrowsLogicException(): void
    {
        $renderer = new FormMarkup();
        $form     = $this->buildForm();
        $form->add(new Text('first_name'));
        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FormMarkup requires a Laminas\\Form\\View\\Helper\\FormElement helper');

        $renderer->render($def, $form);
    }

    #[Test]
    public function requiredFieldLabelCarriesRequiredModifierClass(): void
    {
        $form = $this->buildForm();
        $form->add(new Email('email'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('email', 'email', ['required' => true]),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString(
            '<label class="formbuilder__label formbuilder__label--required" for="email">Email</label>',
            $html,
        );
    }

    /**
     * @param class-string<\Laminas\Form\ElementInterface> $expected
     */
    #[Test]
    #[DataProvider('elementsRoutedThroughFormElementProvider')]
    public function routesScalarElementsThroughFormElementHelper(
        string $factoryMethod,
        string $type,
        string $name,
        string $expected,
    ): void {
        $form    = $this->buildForm();
        $element = self::$factoryMethod($name);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field($type, $name),
        ]);

        $this->renderer->render($def, $form);

        static::assertContains($expected, $this->formElement->renderedClasses());
    }

    #[Test]
    public function sectionLegendRenderedWhenSet(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row = new RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group   = new GroupDefinition(null, null, null, 0, [$row]);
        $section = new SectionDefinition(
            null,
            'main',
            'About You',
            'Tell us a bit about yourself',
            0,
            [$group],
        );
        $def = new FormDefinition(
            id: 1,
            slug: 'sec',
            title: 'Sec',
            sections: [$section],
        );

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString(
            '<h2 class="formbuilder__section-title">About You</h2>',
            $html,
        );
        static::assertStringContainsString(
            '<p class="formbuilder__section-description">Tell us a bit about yourself</p>',
            $html,
        );
    }

    #[Test]
    public function sectionWithoutGroupsRendersNothing(): void
    {
        $definition = new FormDefinition(
            id: 1,
            slug: 's',
            title: 'S',
            sections: [
                new SectionDefinition(
                    id: null,
                    key: 'empty',
                    legend: 'Invisible',
                ),
            ],
        );

        static::assertStringNotContainsString('Invisible', $this->renderer->render($definition, $this->buildForm()));
    }

    #[Test]
    public function singleCheckboxRendersWithoutSiblingLabelWhenElementLabelEmpty(): void
    {
        $form    = $this->buildForm();
        $element = new Checkbox('flag');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('checkbox', 'flag', ['label' => null]),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertContains(Checkbox::class, $this->formElement->renderedClasses());
        // No sibling label emitted (the input pair still renders)
        static::assertStringNotContainsString('<label class="formbuilder__label" for="flag">', $html);
    }

    #[Test]
    public function singleCheckboxRoutesInputPairThroughFormElementWithTrailingLabel(): void
    {
        $form    = $this->buildForm();
        $element = new Checkbox('subscribe');
        $element->setLabel('Subscribe to newsletter');
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('checkbox', 'subscribe'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertContains(Checkbox::class, $this->formElement->renderedClasses());
        static::assertStringContainsString('formbuilder__control--checkbox', $html);
        static::assertStringContainsString('type="hidden"', $html);
        static::assertStringContainsString('type="checkbox"', $html);
        // Sibling label, not wrapping label
        static::assertStringContainsString(
            '<label class="formbuilder__label" for="subscribe">Subscribe to newsletter</label>',
            $html,
        );
    }

    #[Test]
    public function singleSelectGetsControlSelectClassAndRoutesThroughFormElement(): void
    {
        $form    = $this->buildForm();
        $element = new Select('country');
        $element->setValueOptions(['au' => 'Australia', 'nz' => 'New Zealand']);
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('select', 'country'),
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertContains(Select::class, $this->formElement->renderedClasses());
        static::assertStringContainsString('formbuilder__control--select', $html);
        static::assertStringContainsString('<select', $html);
        static::assertStringContainsString('<option value="au">Australia</option>', $html);
    }

    #[Test]
    public function stepLabelFallsBackToTheKeyAndStepsShowDescriptions(): void
    {
        $definition = new FormDefinition(
            id: 1,
            slug: 's',
            title: 'S',
            layoutMode: FormDefinition::LAYOUT_STEPPED,
            sections: [
                new SectionDefinition(
                    id: null,
                    key: 'your-details_now',
                    description: 'Tell us',
                    groups: [new GroupDefinition(id: null)],
                ),
            ],
        );

        $html = $this->renderer->render($definition, $this->buildForm());

        static::assertStringContainsString('</span> Your details now</button>', $html);
        static::assertStringContainsString('<p class="formbuilder__step-description">Tell us</p>', $html);
    }

    #[Test]
    public function stepLayoutEmitsStepNavAndPerStepSections(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));
        $form->add(new Text('email'));

        $def = FormDefinitionFactory::withSections([
            ['key' => 'about', 'legend' => 'About', 'fields' => [FormDefinitionFactory::field('text', 'first_name')]],
            ['key' => 'contact', 'legend' => 'Contact', 'fields' => [FormDefinitionFactory::field('text', 'email')]],
        ]);

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('<ol class="formbuilder__steps"', $html);
        static::assertStringContainsString('data-form-step-target="about"', $html);
        static::assertStringContainsString('data-form-step-target="contact"', $html);
        static::assertStringContainsString('data-form-step="about"', $html);
        static::assertStringContainsString('data-form-step="contact"', $html);
        static::assertStringContainsString('data-form-stepper="true"', $html);
    }

    #[Test]
    public function steppedFormDropsANonScalarClass(): void
    {
        $form = $this->buildForm();
        $form->setAttribute('class', ['x']);
        $definition = new FormDefinition(
            id: 1,
            slug: 's',
            title: 'S',
            layoutMode: FormDefinition::LAYOUT_STEPPED,
            sections: [
                new SectionDefinition(
                    id: null,
                    key: 'only',
                    groups: [new GroupDefinition(id: null)],
                ),
            ],
        );

        static::assertStringContainsString('class="formbuilder__form--stepped"', $this->renderer->render(
            $definition,
            $form,
        ));
    }

    #[Test]
    public function submitAlignmentClassFollowsDefinition(): void
    {
        $form = $this->buildForm();
        $form->add(new Submit('_submit'));

        $def = new FormDefinition(
            id: 1,
            slug: 'aligned',
            title: 'Aligned',
            submitAlignment: 'right',
            sections: [],
        );

        $html = $this->renderer->render($def, $form);

        static::assertStringContainsString('formbuilder__actions--right', $html);
    }

    #[Test]
    public function unencodableConditionalRuleIsLeftOff(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('name'));
        $definition = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'name', ['conditional' => ['show_when' => "\xB1"]]),
        ]);

        static::assertStringNotContainsString('data-form-conditional', $this->renderer->render($definition, $form));
    }

    protected function setUp(): void
    {
        [$view] = ViewRendererBuilder::build();
        $this->formElement = new RecordingFormElement();
        $this->formElement->setView($view);

        $this->renderer = new FormMarkup();
        $this->renderer->setFormElementHelper($this->formElement);
    }

    private function buildForm(): Form
    {
        return new Form('test');
    }
}
