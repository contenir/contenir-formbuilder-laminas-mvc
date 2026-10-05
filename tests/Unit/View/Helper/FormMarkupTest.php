<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\View\Helper;

use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\ViewRendererBuilder;
use Contenir\FormBuilder\Laminas\Mvc\View\Helper\FormMarkup;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;
use Laminas\View\Helper\EscapeHtml;
use Laminas\View\Renderer\PhpRenderer;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormMarkupTest extends TestCase
{
    #[Test]
    public function rendersPublicMarkupUnlessPreviewIsAsked(): void
    {
        [$renderer] = ViewRendererBuilder::build();
        $helper = new FormMarkup();
        $helper->setView($renderer);

        $html = $helper(FormDefinitionFactory::withFields([]), new Form());

        static::assertStringContainsString('<fieldset class="formbuilder__panel">', $html);
    }

    #[Test]
    public function rendersThroughTheViewsEscapeHtmlHelper(): void
    {
        [$renderer] = ViewRendererBuilder::build();
        $renderer->getHelperPluginManager()->setService('escapeHtml', static fn(string $value): string => "[{$value}]");
        $helper = new FormMarkup();
        $helper->setView($renderer);
        $form = new Form();
        $form->add(new Text('name'));

        $html = $helper(FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name', [
            'label' => 'Name',
        ])]), $form);

        static::assertStringContainsString('>[Name]</label>', $html);
    }

    #[Test]
    public function rendersThroughTheViewsEscaperAndFormElementHelper(): void
    {
        [$renderer] = ViewRendererBuilder::build();
        $helper = new FormMarkup();
        $helper->setView($renderer);
        $form = new Form();
        $form->add(new Text('name'));

        $html = $helper(FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name', [
            'label' => 'A & B',
        ])]), $form);

        static::assertStringContainsString('<label class="formbuilder__label" for="name">A &amp; B</label>', $html);
        static::assertStringContainsString('<input type="text" name="name" value="">', $html);
    }

    #[Test]
    public function viewWhoseFormElementHelperIsReplacedCannotRenderInputs(): void
    {
        $renderer = new PhpRenderer();
        $renderer->getHelperPluginManager()->setService('formElement', new EscapeHtml());
        $helper = new FormMarkup();
        $helper->setView($renderer);
        $form = new Form();
        $form->add(new Text('name'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('call setFormElementHelper() before render()');

        $helper(FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name')]), $form);
    }

    #[Test]
    public function withoutAViewOnlyInputlessFormsRender(): void
    {
        $html = (new FormMarkup())(FormDefinitionFactory::withFields([]), new Form());

        static::assertStringStartsWith('<form', $html);
    }
}
