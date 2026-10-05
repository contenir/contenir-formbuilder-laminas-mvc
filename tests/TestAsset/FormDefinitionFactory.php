<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;

use function ucfirst;

/**
 * Minimal {@see FormDefinition} builders for renderer tests.
 *
 * Tests pass field definitions inline; the helpers below wrap them in the
 * section/group/row scaffolding the renderer walks so the tests don't have
 * to repeat the structural noise for every case.
 */
final class FormDefinitionFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function field(string $type, string $name, array $overrides = []): FieldDefinition
    {
        return new FieldDefinition(
            id: null,
            type: $type,
            name: $name,
            label: $overrides['label'] ?? ucfirst($name),
            showLabel: $overrides['showLabel'] ?? true,
            description: $overrides['description'] ?? null,
            placeholder: $overrides['placeholder'] ?? null,
            defaultValue: $overrides['defaultValue'] ?? null,
            required: $overrides['required'] ?? false,
            colSpan: $overrides['colSpan'] ?? 4,
            sort: $overrides['sort'] ?? 0,
            options: $overrides['options'] ?? [],
            validators: $overrides['validators'] ?? [],
            filters: $overrides['filters'] ?? [],
            conditional: $overrides['conditional'] ?? null,
        );
    }

    /**
     * Build a single-section, single-group, single-row form around a list
     * of fields. Suitable for most renderer tests; bypasses sections that
     * have neither a legend nor a description.
     *
     * @param list<FieldDefinition> $fields
     */
    public static function withFields(
        array $fields,
        string $layoutMode = FormDefinition::LAYOUT_SINGLE,
    ): FormDefinition {
        $row     = new RowDefinition(null, 0, $fields);
        $group   = new GroupDefinition(null, null, null, 0, [$row]);
        $section = new SectionDefinition(null, 'main', null, null, 0, [$group]);

        return new FormDefinition(
            id: 1,
            slug: 'test',
            title: 'Test',
            layoutMode: $layoutMode,
            sections: [$section],
        );
    }

    /**
     * Build a multi-section form (one group/row of fields per section) for
     * exercising stepped-layout rendering.
     *
     * @param list<array{key: string, legend?: string, fields: list<FieldDefinition>}> $sections
     */
    public static function withSections(
        array $sections,
        string $layoutMode = FormDefinition::LAYOUT_STEPPED,
    ): FormDefinition {
        $built = [];
        foreach ($sections as $index => $spec) {
            $row     = new RowDefinition(null, 0, $spec['fields']);
            $group   = new GroupDefinition(null, null, null, 0, [$row]);
            $built[] = new SectionDefinition(
                null,
                $spec['key'],
                $spec['legend'] ?? null,
                null,
                $index,
                [$group],
            );
        }

        return new FormDefinition(
            id: 1,
            slug: 'stepped',
            title: 'Stepped',
            layoutMode: $layoutMode,
            sections: $built,
        );
    }
}
