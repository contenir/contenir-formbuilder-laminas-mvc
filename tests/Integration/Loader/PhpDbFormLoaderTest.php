<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Integration\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\SqliteDatabaseTrait;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function random_bytes;

#[Group('integration')]
#[Group('repository')]
final class PhpDbFormLoaderTest extends TestCase
{
    use SqliteDatabaseTrait;

    private PhpDbFormLoader $loader;

    #[Test]
    public function appliesDefaultsForEmptyOptionalColumns(): void
    {
        $formId = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact', 'settings_json' => 'not json']);

        $form = $this->loader->loadBySlug('contact');

        static::assertEquals(
            new FormDefinition(
                id: $formId,
                slug: 'contact',
                title: 'Contact',
            ),
            $form,
        );
    }

    #[Test]
    public function clampsTheColumnSpanAndDefaultsOptionalFieldColumns(): void
    {
        $row = $this->rowForNewForm();
        $this->insert('form_field', [
            'form_row_id'      => $row,
            'type'             => 'text',
            'name'             => 'wide',
            'col_span'         => 9,
            'conditional_json' => '"x"',
        ]);
        $this->insert('form_field', [
            'form_row_id' => $row,
            'type'        => 'text',
            'name'        => 'narrow',
            'col_span'    => 0,
            'sort'        => 1,
        ]);

        $fields = $this->loader->loadBySlug('contact')?->getAllFields() ?? [];

        static::assertSame([4, 1], [$fields[0]->colSpan, $fields[1]->colSpan]);
        static::assertSame([null, true, false, [], [], [], null], [
            $fields[0]->label,
            $fields[0]->showLabel,
            $fields[0]->required,
            $fields[0]->options,
            $fields[0]->validators,
            $fields[0]->filters,
            $fields[0]->conditional,
        ]);
    }

    #[Test]
    public function hydratesEveryFieldColumn(): void
    {
        $row = $this->rowForNewForm();
        $id  = $this->insert('form_field', [
            'form_row_id' => $row,
            'type' => 'select',
            'name' => 'size',
            'label' => 'Size',
            'show_label' => 0,
            'description' => 'Pick one',
            'placeholder' => 'Choose',
            'default_value' => 'm',
            'required' => 1,
            'col_span' => 2,
            'sort' => 5,
            'options_json' => '{"choices":[{"value":"m"}],"multiple":false}',
            'validators_json' => '[{"type":"regex","options":{"pattern":"/m/"},"message":"No"},{"type":5},{"options":[]},"bad",{"type":"email","options":"x","message":["y"]}]',
            'filters_json' => '["",3,"StringTrim","StripTags"]',
            'conditional_json' => '{"show_when":{"all":[]}}',
        ]);

        $field = $this->loader->loadBySlug('contact')?->getAllFields()[0];

        static::assertNotNull($field);
        static::assertSame(
            [$id, 'select', 'size', 'Size', false, 'Pick one', 'Choose', 'm', true, 2, 5],
            [
                $field->id,
                $field->type,
                $field->name,
                $field->label,
                $field->showLabel,
                $field->description,
                $field->placeholder,
                $field->defaultValue,
                $field->required,
                $field->colSpan,
                $field->sort,
            ],
        );
        static::assertSame(['choices' => [['value' => 'm']], 'multiple' => false], $field->options);
        static::assertEquals(
            [
                new ValidatorDefinition('regex', ['pattern' => '/m/'], 'No'),
                new ValidatorDefinition('5'),
                new ValidatorDefinition('email'),
            ],
            $field->validators,
        );
        static::assertSame(['StringTrim', 'StripTags'], $field->filters);
        static::assertSame(['show_when' => ['all' => []]], $field->conditional);
    }

    #[Test]
    public function hydratesNotificationsAndWebhooksInSortOrder(): void
    {
        $signingKey = bin2hex(random_bytes(4));
        $formId     = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $this->insert('form_notification', [
            'form_id'    => $formId,
            'name'       => 'Second',
            'to_address' => 'b@example.com',
            'subject'    => 'S2',
            'enabled'    => 0,
            'sort'       => 2,
        ]);
        $first = $this->insert('form_notification', [
            'form_id'         => $formId,
            'name'            => 'Admin',
            'trigger'         => 'submit',
            'to_address'      => 'a@example.com',
            'from_address'    => 'f@example.com',
            'reply_to'        => '{field:email}',
            'subject'         => 'New entry',
            'body_template'   => '{entry:fields}',
            'conditions_json' => '{"x":1}',
            'sort'            => 1,
        ]);
        $hook = $this->insert('form_webhook', [
            'form_id'      => $formId,
            'name'         => 'CRM',
            'url'          => 'https://crm.example/hook',
            'method'       => 'put',
            'secret'       => $signingKey,
            'headers_json' => '{"X-Bad":1,"X-Key":"abc","X-Trace":"on"}',
            'enabled'      => 0,
            'sort'         => 3,
        ]);
        $this->insert('form_webhook', [
            'form_id' => $formId,
            'name'    => 'Plain',
            'url'     => 'https://x.example',
            'secret'  => '',
        ]);

        $form = $this->loader->loadById($formId);

        static::assertNotNull($form);
        $notification = $form->notifications[0];
        static::assertSame(
            [
                $first,
                'Admin',
                'submit',
                'a@example.com',
                'f@example.com',
                '{field:email}',
                'New entry',
                '{entry:fields}',
                ['x' => 1],
                true,
                1,
            ],
            [
                $notification->id,
                $notification->name,
                $notification->trigger,
                $notification->toAddress,
                $notification->fromAddress,
                $notification->replyTo,
                $notification->subject,
                $notification->bodyTemplate,
                $notification->conditions,
                $notification->enabled,
                $notification->sort,
            ],
        );
        static::assertSame([false, null, null], [
            $form->notifications[1]->enabled,
            $form->notifications[1]->fromAddress,
            $form->notifications[1]->conditions,
        ]);
        static::assertSame(['Plain', 'CRM'], array_map(static fn($w) => $w->name, $form->webhooks));
        $webhook = $form->webhooks[1];
        static::assertSame(
            [$hook, 'https://crm.example/hook', 'PUT', $signingKey, ['X-Key' => 'abc', 'X-Trace' => 'on'], false, 3],
            [
                $webhook->id,
                $webhook->url,
                $webhook->method,
                $webhook->secret,
                $webhook->headers,
                $webhook->enabled,
                $webhook->sort,
            ],
        );
        static::assertSame(['POST', null], [$form->webhooks[0]->method, $form->webhooks[0]->secret]);
    }

    #[Test]
    public function hydratesTheFormAttributes(): void
    {
        $formId = $this->insert('form', [
            'slug'             => 'contact',
            'title'            => 'Contact',
            'description'      => 'Say hi',
            'layout_mode'      => 'stepped',
            'submit_label'     => 'Send',
            'submit_alignment' => 'center',
            'settings_json'    => '{"success":{"mode":"inline_message"}}',
            'retention_days'   => 30,
            'status'           => 'inactive',
        ]);

        $form = $this->loader->loadById($formId);

        static::assertEquals(
            new FormDefinition(
                id: $formId,
                slug: 'contact',
                title: 'Contact',
                description: 'Say hi',
                layoutMode: 'stepped',
                submitLabel: 'Send',
                submitAlignment: 'center',
                settings: ['success' => ['mode' => 'inline_message']],
                retentionDays: 30,
                status: 'inactive',
            ),
            $form,
        );
    }

    #[Test]
    public function hydratesTheLayoutInSortOrder(): void
    {
        $formId = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $second = $this->insert('form_section', ['form_id' => $formId, 'key' => 'second', 'sort' => 2]);
        $first  = $this->insert('form_section', [
            'form_id'     => $formId,
            'key'         => 'first',
            'legend'      => 'About',
            'description' => 'You',
            'sort'        => 1,
        ]);
        $this->insert('form_section', ['form_id' => $formId, 'key' => 'empty', 'sort' => 3]);
        $group = $this->insert('form_group', [
            'form_section_id' => $first,
            'legend'          => 'Details',
            'description'     => 'D',
            'sort'            => 0,
        ]);
        $this->insert('form_group', ['form_section_id' => $second, 'sort' => 0]);
        $row      = $this->insert('form_row', ['form_group_id' => $group, 'sort' => 1]);
        $emptyRow = $this->insert('form_row', ['form_group_id' => $group, 'sort' => 0]);
        $this->insert('form_field', ['form_row_id' => $row, 'type' => 'email', 'name' => 'email', 'sort' => 2]);
        $this->insert('form_field', ['form_row_id' => $row, 'type' => 'text', 'name' => 'name', 'sort' => 1]);

        $form = $this->loader->loadById($formId);

        static::assertNotNull($form);
        static::assertSame(['first', 'second', 'empty'], array_map(static fn($s) => $s->key, $form->sections));
        static::assertSame(['About', 'You'], [$form->sections[0]->legend, $form->sections[0]->description]);
        static::assertSame([], $form->sections[2]->groups);
        $groupDefinition = $form->sections[0]->groups[0];
        static::assertSame([$group, 'Details', 'D'], [
            $groupDefinition->id,
            $groupDefinition->legend,
            $groupDefinition->description,
        ]);
        static::assertSame([$emptyRow, $row], array_map(static fn($r) => $r->id, $groupDefinition->rows));
        static::assertSame([], $groupDefinition->rows[0]->fields);
        static::assertSame(['name', 'email'], array_map(static fn($f) => $f->name, $groupDefinition->rows[1]->fields));
        static::assertSame([], $form->sections[1]->groups[0]->rows);
    }

    #[Test]
    public function hydratesTheRowsOfEveryGroup(): void
    {
        $formId    = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $section   = $this->insert('form_section', ['form_id' => $formId, 'key' => 'main']);
        $first     = $this->insert('form_group', ['form_section_id' => $section, 'sort' => 0]);
        $second    = $this->insert('form_group', ['form_section_id' => $section, 'sort' => 1]);
        $firstRow  = $this->insert('form_row', ['form_group_id' => $first]);
        $secondRow = $this->insert('form_row', ['form_group_id' => $second]);

        $groups = $this->loader->loadById($formId)?->sections[0]->groups ?? [];

        static::assertSame(
            [[$firstRow], [$secondRow]],
            array_map(static fn($group) => array_map(static fn($row) => $row->id, $group->rows), $groups),
        );
    }

    #[Test]
    public function loadsAllFormsAndSummariesByTitle(): void
    {
        $zed   = $this->insert('form', ['slug' => 'zed', 'title' => 'Zed']);
        $alpha = $this->insert('form', ['slug' => 'alpha', 'title' => 'Alpha', 'status' => 'inactive']);

        static::assertSame(['alpha', 'zed'], array_map(static fn($f) => $f->slug, $this->loader->loadAll()));
        static::assertSame(
            [
                ['id' => $alpha, 'slug' => 'alpha', 'title' => 'Alpha', 'status' => 'inactive'],
                ['id' => $zed, 'slug' => 'zed', 'title' => 'Zed', 'status' => 'active'],
            ],
            $this->loader->listSummaries(),
        );
    }

    #[Test]
    public function returnsNullForAMissingForm(): void
    {
        static::assertSame([null, null], [$this->loader->loadById(9999), $this->loader->loadBySlug('nope')]);
    }

    #[Test]
    public function rowsWithoutFieldsHydrateEmpty(): void
    {
        $this->rowForNewForm();

        static::assertSame([], $this->loader->loadBySlug('contact')?->sections[0]->groups[0]->rows[0]->fields);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->loader = new PhpDbFormLoader($this->adapter);
    }

    private function rowForNewForm(): int
    {
        $formId  = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $section = $this->insert('form_section', ['form_id' => $formId, 'key' => 'main']);
        $group   = $this->insert('form_group', ['form_section_id' => $section]);

        return $this->insert('form_row', ['form_group_id' => $group]);
    }
}
