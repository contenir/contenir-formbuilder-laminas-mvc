<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Loader;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Exception\ExceptionInterface as PhpDbException;

use function array_map;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function strtoupper;

/**
 * Hydrates {@see FormDefinition} aggregates from the forms schema using
 * a php-db adapter.
 *
 * Fetches in bulk, one query per level (form → sections → groups → rows →
 * fields, plus notifications and webhooks), so the cost is fixed regardless of
 * the form's size: no N+1 traversal.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one builder per table); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (one builder per table); splitting it is a proposed follow-up.
 * @mago-expect lint:too-many-methods Kept whole for 2.0 (one builder per table); splitting it is a proposed follow-up.
 */
final class PhpDbFormLoader implements FormLoaderInterface
{
    public function __construct(
        private AdapterInterface $adapter,
    ) {}

    /**
     * A field spans 1 to 4 columns, 4 when the column is empty.
     */
    private static function colSpan(?int $span): int
    {
        return null === $span ? 4 : max(1, min(4, $span));
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; only non-empty strings are kept.
     */
    private static function filters(array $raw): array
    {
        $filters = [];
        foreach ($raw as $value) {
            if (! (is_string($value) && '' !== $value)) {
                continue;
            }

            $filters[] = $value;
        }

        return $filters;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; only string values are kept.
     */
    private static function headers(array $raw): array
    {
        $headers = [];
        foreach ($raw as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            $headers[(string) $name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment JSON objects are untyped; values are passed through.
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    private static function stringKeysOrNull(?array $values): ?array
    {
        return null === $values ? null : self::stringKeys($values);
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return list<ValidatorDefinition>
     *
     * Entries without a scalar `type` are skipped; {@see ValidatorDefinition::fromArray()}
     * coerces the rest.
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; each entry is checked before use.
     */
    private static function validators(array $raw): array
    {
        $validators = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! is_scalar($entry['type'] ?? null)) {
                continue;
            }

            $validators[] = ValidatorDefinition::fromArray($entry);
        }

        return $validators;
    }

    /**
     * Lightweight projection — single query, no nested hydration.
     *
     * @return list<array{id: int, slug: string, title: string, status: string}>
     *
     * @throws PhpDbException When a query fails.
     */
    #[Override]
    public function listSummaries(): array
    {
        return array_map(
            static function (array $row): array {
                $read = new RowReader($row);

                return [
                    'id'     => $read->int('form_id'),
                    'slug'   => $read->string('slug'),
                    'title'  => $read->string('title'),
                    'status' => $read->string('status'),
                ];
            },
            $this->fetchAll('SELECT form_id, slug, title, status FROM form ORDER BY title ASC', []),
        );
    }

    /**
     * @return list<FormDefinition>
     *
     * @throws PhpDbException When a query fails.
     */
    #[Override]
    public function loadAll(): array
    {
        return array_map($this->hydrate(...), $this->fetchAll('SELECT * FROM form ORDER BY title ASC', []));
    }

    /**
     * @throws PhpDbException When a query fails.
     */
    #[Override]
    public function loadById(int $formId): ?FormDefinition
    {
        return $this->loadOne('SELECT * FROM form WHERE form_id = ?', [$formId]);
    }

    /**
     * @throws PhpDbException When a query fails.
     */
    #[Override]
    public function loadBySlug(string $slug): ?FormDefinition
    {
        return $this->loadOne('SELECT * FROM form WHERE slug = ?', [$slug]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function buildField(array $row): FieldDefinition
    {
        $read = new RowReader($row);

        return new FieldDefinition(
            id: $read->int('form_field_id'),
            type: $read->string('type'),
            name: $read->string('name'),
            label: $read->nullableString('label'),
            showLabel: $read->bool('show_label', default: true),
            description: $read->nullableString('description'),
            placeholder: $read->nullableString('placeholder'),
            defaultValue: $read->nullableString('default_value'),
            required: $read->bool('required', default: false),
            colSpan: self::colSpan($read->nullableInt('col_span')),
            sort: $read->int('sort'),
            options: self::stringKeys($read->json('options_json')),
            validators: self::validators($read->json('validators_json')),
            filters: self::filters($read->json('filters_json')),
            conditional: self::stringKeysOrNull($read->nullableJson('conditional_json')),
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     *
     * @return list<NotificationDefinition>
     */
    private function buildNotifications(array $rows): array
    {
        return array_map(static function (array $row): NotificationDefinition {
            $read = new RowReader($row);

            return new NotificationDefinition(
                id: $read->int('form_notification_id'),
                name: $read->string('name'),
                trigger: $read->string('trigger', default: 'submit'),
                toAddress: $read->string('to_address'),
                fromAddress: $read->nullableString('from_address'),
                replyTo: $read->nullableString('reply_to'),
                subject: $read->string('subject'),
                bodyTemplate: $read->nullableString('body_template'),
                conditions: self::stringKeysOrNull($read->nullableJson('conditions_json')),
                enabled: $read->bool('enabled', default: true),
                sort: $read->int('sort'),
            );
        }, $rows);
    }

    /**
     * @param list<array<array-key, mixed>> $sectionRows
     * @param array<int, list<GroupDefinition>> $groupsBySection
     *
     * @return list<SectionDefinition>
     */
    private function buildSections(array $sectionRows, array $groupsBySection): array
    {
        return array_map(static function (array $row) use ($groupsBySection): SectionDefinition {
            $read = new RowReader($row);
            $id   = $read->int('form_section_id');

            return new SectionDefinition(
                id: $id,
                key: $read->string('key'),
                legend: $read->nullableString('legend'),
                description: $read->nullableString('description'),
                sort: $read->int('sort'),
                groups: $groupsBySection[$id] ?? [],
            );
        }, $sectionRows);
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     *
     * @return list<WebhookDefinition>
     */
    private function buildWebhooks(array $rows): array
    {
        return array_map(static function (array $row): WebhookDefinition {
            $read = new RowReader($row);

            return new WebhookDefinition(
                id: $read->int('form_webhook_id'),
                name: $read->string('name'),
                url: $read->string('url'),
                method: strtoupper($read->string('method', default: 'POST')),
                secret: '' === $read->string('secret') ? null : $read->string('secret'),
                headers: self::headers($read->json('headers_json')),
                enabled: $read->bool('enabled', default: true),
                sort: $read->int('sort'),
            );
        }, $rows);
    }

    /**
     * @param array<int, mixed> $params
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws PhpDbException When the query fails.
     */
    private function fetchAll(string $sql, array $params): array
    {
        $rows = [];
        foreach ($this->adapter->executeQuery($this->adapter->prepareQuery($sql, $params)) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Rows of $table whose $parentColumn is one of the parents' ids, in one
     * query per level so the cost does not grow with the form's size.
     *
     * @param list<array<array-key, mixed>> $parents
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws PhpDbException When a query fails.
     */
    private function fetchChildren(string $table, string $parentColumn, array $parents, string $idColumn): array
    {
        $ids = array_map(
            /** @param array<array-key, mixed> $row */
            static fn(array $row): int => (new RowReader($row))->int($parentColumn),
            $parents,
        );
        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_map(static fn(): string => '?', $ids));

        return $this->fetchAll(
            "SELECT * FROM {$table} WHERE {$parentColumn} IN ({$placeholders}) ORDER BY sort ASC, {$idColumn} ASC",
            $ids,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $groupRows
     * @param array<int, list<RowDefinition>> $rowsByGroup
     *
     * @return array<int, list<GroupDefinition>>
     */
    private function groupGroups(array $groupRows, array $rowsByGroup): array
    {
        $bySection = [];
        foreach ($groupRows as $row) {
            $read = new RowReader($row);
            $id   = $read->int('form_group_id');

            $bySection[$read->int('form_section_id')][] = new GroupDefinition(
                id: $id,
                legend: $read->nullableString('legend'),
                description: $read->nullableString('description'),
                sort: $read->int('sort'),
                rows: $rowsByGroup[$id] ?? [],
            );
        }

        return $bySection;
    }

    /**
     * @param list<array<array-key, mixed>> $rowRows
     * @param array<int, list<FieldDefinition>> $fieldsByRow
     *
     * @return array<int, list<RowDefinition>>
     */
    private function groupRows(array $rowRows, array $fieldsByRow): array
    {
        $byGroup = [];
        foreach ($rowRows as $row) {
            $read = new RowReader($row);
            $id   = $read->int('form_row_id');

            $byGroup[$read->int('form_group_id')][] = new RowDefinition(
                id: $id,
                sort: $read->int('sort'),
                fields: $fieldsByRow[$id] ?? [],
            );
        }

        return $byGroup;
    }

    /**
     * @param array<array-key, mixed> $formRow
     *
     * @throws PhpDbException When a query fails.
     */
    private function hydrate(array $formRow): FormDefinition
    {
        $read   = new RowReader($formRow);
        $formId = $read->int('form_id');

        $sectionRows = $this->fetchAll(
            'SELECT * FROM form_section WHERE form_id = ? ORDER BY sort ASC, form_section_id ASC',
            [$formId],
        );
        $groupRows = $this->fetchChildren('form_group', 'form_section_id', $sectionRows, 'form_group_id');
        $rowRows   = $this->fetchChildren('form_row', 'form_group_id', $groupRows, 'form_row_id');
        $fieldRows = $this->fetchChildren('form_field', 'form_row_id', $rowRows, 'form_field_id');

        $fieldsByRow = [];
        foreach ($fieldRows as $row) {
            $fieldsByRow[(new RowReader($row))->int('form_row_id')][] = $this->buildField($row);
        }

        $groupsBySection = $this->groupGroups($groupRows, $this->groupRows($rowRows, $fieldsByRow));

        return new FormDefinition(
            id: $formId,
            slug: $read->string('slug'),
            title: $read->string('title'),
            description: $read->nullableString('description'),
            layoutMode: $read->string('layout_mode', default: FormDefinition::LAYOUT_SINGLE),
            submitLabel: $read->string('submit_label', default: 'Submit'),
            submitAlignment: $read->string('submit_alignment', default: 'left'),
            settings: self::stringKeys($read->json('settings_json')),
            retentionDays: $read->nullableInt('retention_days'),
            status: $read->string('status', default: FormDefinition::STATUS_ACTIVE),
            sections: $this->buildSections($sectionRows, $groupsBySection),
            notifications: $this->buildNotifications($this->fetchAll(
                'SELECT * FROM form_notification WHERE form_id = ? ORDER BY sort ASC, form_notification_id ASC',
                [$formId],
            )),
            webhooks: $this->buildWebhooks($this->fetchAll(
                'SELECT * FROM form_webhook WHERE form_id = ? ORDER BY sort ASC, form_webhook_id ASC',
                [$formId],
            )),
        );
    }

    /**
     * @param array<int, mixed> $params
     *
     * @throws PhpDbException When a query fails.
     */
    private function loadOne(string $sql, array $params): ?FormDefinition
    {
        $rows = $this->fetchAll($sql, $params);

        return [] === $rows ? null : $this->hydrate($rows[0]);
    }
}
