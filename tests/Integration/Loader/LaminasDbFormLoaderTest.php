<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Integration\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function count;
use function explode;
use function file_get_contents;

#[Group('integration')]
final class LaminasDbFormLoaderTest extends TestCase
{
    private Adapter $adapter;
    private LaminasDbFormLoader $loader;

    #[Test]
    public function listSummariesReturnsLightweightProjection(): void
    {
        $this->seedSimpleForm();
        $rows = $this->loader->listSummaries();

        static::assertCount(1, $rows);
        static::assertSame('contact', $rows[0]['slug']);
        static::assertSame('Contact', $rows[0]['title']);
        static::assertSame('active', $rows[0]['status']);
    }

    #[Test]
    public function loadAllReturnsAllFormsHydratedSortedByTitle(): void
    {
        $this->seedSimpleForm();
        $this->seedNamedForm('signup', 'Sign-Up');

        $forms = $this->loader->loadAll();

        static::assertCount(2, $forms);
        // ORDER BY title ASC: "Contact" < "Sign-Up"
        static::assertSame('Contact', $forms[0]->title);
        static::assertSame('Sign-Up', $forms[1]->title);
    }

    #[Test]
    public function loadByIdHydratesFullAggregate(): void
    {
        $formId = $this->seedSimpleForm();

        $form = $this->loader->loadById($formId);
        static::assertInstanceOf(FormDefinition::class, $form);
        static::assertSame('contact', $form->slug);
        static::assertSame('Contact', $form->title);
        static::assertCount(1, $form->sections);
        static::assertCount(1, $form->sections[0]->groups);
        static::assertCount(1, $form->sections[0]->groups[0]->rows);
        static::assertCount(2, $form->sections[0]->groups[0]->rows[0]->fields);

        $name  = $form->sections[0]->groups[0]->rows[0]->fields[0];
        $email = $form->sections[0]->groups[0]->rows[0]->fields[1];
        static::assertSame('name', $name->name);
        static::assertSame('text', $name->type);
        static::assertTrue($name->required);
        static::assertSame('email', $email->name);
        static::assertSame('email', $email->type);
    }

    #[Test]
    public function loadByIdReturnsNullWhenFormMissing(): void
    {
        static::assertNull($this->loader->loadById(9999));
    }

    #[Test]
    public function loadBySlugHydratesSameAggregateAsLoadById(): void
    {
        $formId = $this->seedSimpleForm();
        $byId   = $this->loader->loadById($formId);
        $bySlug = $this->loader->loadBySlug('contact');

        static::assertNotNull($byId);
        static::assertNotNull($bySlug);
        static::assertSame($byId->id, $bySlug->id);
        static::assertSame($byId->slug, $bySlug->slug);
        static::assertCount(
            count($byId->sections[0]->groups[0]->rows[0]->fields),
            $bySlug->sections[0]->groups[0]->rows[0]->fields,
        );
    }

    #[Test]
    public function loadBySlugReturnsNullWhenFormMissing(): void
    {
        static::assertNull($this->loader->loadBySlug('nope'));
    }

    protected function setUp(): void
    {
        $this->adapter = new Adapter([
            'driver' => 'Pdo_Sqlite',
            'dsn'    => 'sqlite::memory:',
        ]);
        $this->loadSchema();
        $this->loader = new LaminasDbFormLoader($this->adapter);
    }

    private function loadSchema(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../install-forms.sqlite.sql');
        // Sqlite executes one statement at a time via Pdo; split on
        // semicolons that terminate a statement (the fixture is plain DDL,
        // no string literals containing semicolons).
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->adapter->getDriver()->getConnection()->getResource()->exec($stmt);
        }
    }

    private function seedNamedForm(string $slug, string $title): int
    {
        $now = '2026-05-10 00:00:00';
        $this->adapter->query(
            'INSERT INTO form (slug, title, layout_mode, submit_label, submit_alignment, '
                . 'settings_json, status, created, updated) '
                . "VALUES (?, ?, 'single', 'Submit', 'left', '[]', 'active', ?, ?)",
            [$slug, $title, $now, $now],
        );
        $formId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            "INSERT INTO form_section (form_id, key, legend, sort) VALUES (?, 'main', NULL, 0)",
            [$formId],
        );
        $sectionId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            'INSERT INTO form_group (form_section_id, legend, sort) VALUES (?, NULL, 0)',
            [$sectionId],
        );
        $groupId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            'INSERT INTO form_row (form_group_id, sort) VALUES (?, 0)',
            [$groupId],
        );
        $rowId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $insertField =
            'INSERT INTO form_field '
            . '(form_row_id, type, name, label, show_label, required, col_span, sort, options_json) '
            . "VALUES (?, ?, ?, ?, 1, ?, 4, ?, '{}')";
        $this->adapter->query($insertField, [$rowId, 'text', 'name', 'Name', 1, 0]);
        $this->adapter->query($insertField, [$rowId, 'email', 'email', 'Email', 0, 1]);

        return $formId;
    }

    private function seedSimpleForm(): int
    {
        return $this->seedNamedForm('contact', 'Contact');
    }
}
