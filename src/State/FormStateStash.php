<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\State;

use Laminas\Session\Container;

use function is_array;
use function preg_replace;
use function strtolower;

/**
 * One-shot session stash for invalid submissions.
 *
 * On a non-AJAX validation failure the SubmitController stores the raw
 * post values plus the form's validator messages here, then redirects
 * back to the page that hosts the form. The next render of the section
 * block calls {@see consume()} to read-and-clear the entry, hydrating
 * the rebuilt Laminas form so the user sees their values and the
 * inline error messages instead of an empty form.
 *
 * Entries are keyed by form slug so multiple form blocks on the same
 * page do not collide. Read once and discarded — a second render with
 * no new submission shows a blank form, which is what the user expects
 * after they navigated away and came back.
 *
 * @api
 */
class FormStateStash
{
    private const string SESSION_NAMESPACE = 'ContenirFormBuilderFlash';

    private Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container(self::SESSION_NAMESPACE);
    }

    /**
     * Read and remove the stash for $slug. Malformed entries are discarded.
     *
     * @return array{values: array<array-key, mixed>, errors: array<array-key, mixed>}|null
     *
     * @mago-expect analysis:mixed-assignment Session data is untyped; the shape is checked before it is returned.
     */
    public function consume(string $slug): ?array
    {
        $key = $this->key($slug);
        if (! $this->container->offsetExists($key)) {
            return null;
        }

        $data = $this->container->offsetGet($key);
        $this->container->offsetUnset($key);

        $values = is_array($data) ? $data['values'] ?? null : null;
        $errors = is_array($data) ? $data['errors'] ?? null : null;
        if (! is_array($values) || ! is_array($errors)) {
            return null;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<array-key, mixed> $values Submitted values, keyed by field name.
     * @param array<array-key, mixed> $errors Laminas validation messages, keyed by field name.
     */
    public function store(string $slug, array $values, array $errors): void
    {
        $this->container->offsetSet($this->key($slug), ['values' => $values, 'errors' => $errors]);
    }

    private function key(string $slug): string
    {
        $clean = (string) preg_replace('/[^a-z0-9_\-]/', replacement: '_', subject: strtolower($slug));

        return "form_{$clean}";
    }
}
