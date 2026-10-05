# Rendering

## `formMarkup` view helper

```php
<?= $this->formMarkup($definition, $form) ?>
<?= $this->formMarkup($definition, $form, true) ?> <!-- admin preview classes -->
```

Renders with `Contenir\FormBuilder\Laminas\Mvc\Render\FormMarkup`, using the
view's `escapeHtml` helper for escaping and its `formElement` helper for
inputs. Routing inputs through `formElement` means delegators registered
against `Laminas\Form\View\Helper\FormElement` (for example the page-cache
CSRF delegator in `contenir/cache-laminas-mvc`) see formbuilder forms too.

## `Render\FormMarkup`

The same markup as the core `Contenir\FormBuilder\Render\FormMarkup` (layout,
classes, stepped and preview modes, conditional attributes, content blocks),
with two differences:

- `setFormElementHelper(FormElement $helper)` must be called before rendering a
  form that has inputs; otherwise `render()` throws a `LogicException`.
- Inputs are rendered by that helper. Radio and checkbox lists keep the
  sibling-label markup (`formbuilder__control--checkbox-list-item`); single
  checkboxes get `formbuilder__control--checkbox` and a trailing label;
  single-value selects get `formbuilder__control--select`.

```php
$renderer = new FormMarkup();
$renderer->setEscaper($escapeHtml);
$renderer->setFormElementHelper($formElement);
echo $renderer->render($definition, $form);
```

## `formStashedState` view helper

```php
$stashed = $this->formStashedState('contact');
// null, or ['values' => …, 'errors' => …] from the last invalid submission
```

Reads and clears the [`FormStateStash`](submitting.md#formstatestash) entry.
Hydrate the form with `setData()` and `setMessages()`.

Both helpers still extend laminas-view's deprecated `AbstractHelper` in 2.0.
