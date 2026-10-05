<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Loader;

use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;

/**
 * Typed reads from an untyped database row. Missing and NULL columns, and
 * values of the wrong type, fall back to the given default.
 *
 * @internal
 */
final readonly class RowReader
{
    /**
     * @param array<array-key, mixed> $row
     */
    public function __construct(
        private array $row,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Row values are untyped; the value is checked before use.
     */
    public function bool(string $column, bool $default): bool
    {
        $value = $this->row[$column] ?? null;

        return is_scalar($value) ? (bool) $value : $default;
    }

    public function int(string $column, int $default = 0): int
    {
        return $this->nullableInt($column) ?? $default;
    }

    /**
     * Decodes a JSON column into an array; anything else becomes `[]`.
     *
     * @return array<array-key, mixed>
     */
    public function json(string $column): array
    {
        return $this->nullableJson($column) ?? [];
    }

    /**
     * @mago-expect analysis:mixed-assignment Row values are untyped; the value is checked before use.
     */
    public function nullableInt(string $column): ?int
    {
        $value = $this->row[$column] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Decodes a JSON column into an array; anything else becomes null.
     *
     * @return array<array-key, mixed>|null
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; only arrays are returned.
     */
    public function nullableJson(string $column): ?array
    {
        $raw     = $this->row[$column] ?? null;
        $decoded = is_string($raw) && '' !== $raw ? json_decode($raw, associative: true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @mago-expect analysis:mixed-assignment Row values are untyped; the value is checked before use.
     */
    public function nullableString(string $column): ?string
    {
        $value = $this->row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function string(string $column, string $default = ''): string
    {
        return $this->nullableString($column) ?? $default;
    }
}
