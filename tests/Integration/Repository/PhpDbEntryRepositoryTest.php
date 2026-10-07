<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Integration\Repository;

use Contenir\FormBuilder\Laminas\Mvc\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Laminas\Mvc\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Clock\FrozenClock;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\SqliteDatabaseTrait;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

use function array_map;

#[Group('integration')]
#[Group('repository')]
final class PhpDbEntryRepositoryTest extends TestCase
{
    use SqliteDatabaseTrait;

    private int $formId;

    #[Test]
    public function commitsTheTransaction(): void
    {
        (new PhpDbEntryRepository($this->adapter, new FrozenClock()))->record(
            $this->formId,
            ['name' => 'Ann'],
            'complete',
            null,
            null,
        );

        static::assertFalse($this->pdo->inTransaction());
    }

    #[Test]
    public function recordsTheEntryAndOneRowPerValue(): void
    {
        $repository = new PhpDbEntryRepository($this->adapter, new FrozenClock());

        $id = $repository->record(
            $this->formId,
            ['name' => 'Ann', 'tags' => ['a', 'b'], 'age' => 42, 'blob' => new stdClass()],
            EntryRepositoryInterface::STATUS_COMPLETE,
            '10.0.0.1',
            7,
            ['user_agent' => 'UA/1'],
        );

        $entry = $this->rows('SELECT * FROM form_entry')[0];
        static::assertSame(
            [$id, $this->formId, '10.0.0.1', 7, 'complete', '{"user_agent":"UA/1"}'],
            [
                (int) $entry['form_entry_id'],
                (int) $entry['form_id'],
                $entry['ip'],
                (int) $entry['user_id'],
                $entry['status'],
                $entry['meta_json'],
            ],
        );
        static::assertSame('2026-10-06 09:30:15', $entry['submitted_at']);
        static::assertSame(
            [
                ['name', 'Ann', null],
                ['tags', null,  '["a","b"]'],
                ['age',  '42',  null],
                ['blob', null,  null],
            ],
            array_map(
                static fn(array $row): array => [$row['field_name'], $row['value_text'], $row['value_json']],
                $this->rows('SELECT * FROM form_entry_value ORDER BY form_entry_value_id'),
            ),
        );
    }

    #[Test]
    public function rollsBackTheEntryWhenAValueCannotBeStored(): void
    {
        $this->pdo->exec('DROP TABLE form_entry_value');
        $repository = new PhpDbEntryRepository($this->adapter, new FrozenClock());

        try {
            $repository->record($this->formId, ['name' => 'Ann'], 'complete', null, null);
            static::fail('The failed insert should have been rethrown.');
        } catch (Throwable $exception) {
            static::assertStringContainsString(
                'form_entry_value',
                $exception->getMessage() . $exception->getPrevious()?->getMessage(),
            );
        }

        static::assertSame([], $this->rows('SELECT * FROM form_entry'));
    }

    #[Test]
    public function storesArraysWithUnescapedSlashesAndUnicode(): void
    {
        (new PhpDbEntryRepository($this->adapter, new FrozenClock()))->record(
            $this->formId,
            ['links' => ['https://example.com/café']],
            'complete',
            null,
            null,
        );

        static::assertSame(
            '["https://example.com/café"]',
            $this->rows('SELECT value_json FROM form_entry_value')[0]['value_json'],
        );
    }

    #[Test]
    public function storesFalseAsAnEmptyString(): void
    {
        (new PhpDbEntryRepository($this->adapter, new FrozenClock()))->record(
            $this->formId,
            ['agree' => false],
            'complete',
            null,
            null,
        );

        static::assertSame('', $this->rows('SELECT value_text FROM form_entry_value')[0]['value_text']);
    }

    #[Test]
    public function storesNoMetaAndAnonymousEntriesAsNull(): void
    {
        (new PhpDbEntryRepository($this->adapter, new FrozenClock()))->record(
            $this->formId,
            [],
            EntryRepositoryInterface::STATUS_SPAM,
            null,
            null,
        );

        $entry = $this->rows('SELECT * FROM form_entry')[0];
        static::assertSame([null, null, null, 'spam'], [
            $entry['ip'],
            $entry['user_id'],
            $entry['meta_json'],
            $entry['status'],
        ]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->formId = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
    }
}
