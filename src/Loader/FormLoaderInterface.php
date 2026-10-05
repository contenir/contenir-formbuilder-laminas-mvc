<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;

/**
 * Source of form definitions. {@see LaminasDbFormLoader} reads the forms
 * schema; implement this to load definitions from elsewhere and register the
 * implementation under this interface's name.
 *
 * @api
 */
interface FormLoaderInterface
{
    /**
     * @return list<array{id: int, slug: string, title: string, status: string}>
     */
    public function listSummaries(): array;

    /**
     * @return list<FormDefinition>
     */
    public function loadAll(): array;

    public function loadById(int $formId): ?FormDefinition;

    public function loadBySlug(string $slug): ?FormDefinition;
}
