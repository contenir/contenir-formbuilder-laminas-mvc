<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use SplObserver;
use SplSubject;
use Throwable;

use function is_array;
use function is_numeric;
use function is_scalar;

/**
 * Persists a submitted form into the entries tables (Laminas\Db edition).
 *
 * Reads the {@see BuilderForm} subject's registry for the form definition,
 * the canonical submitted values, and submission metadata, then delegates
 * to {@see LaminasDbEntryRepository::record()} so the persistence concern
 * stays in one place.
 *
 * Mirrors admin4's `PeptoCms\Form\Builder\Registrar\StoreSubmissionRegistrar`
 * but bound to a Laminas\Db repository — admin4's version persists while
 * this version covers the public submit path on Laminas-MVC sites.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity The count comes from type guards on the untyped registry, not from branching logic.
 */
final class StoreSubmissionRegistrar implements SplObserver
{
    public function __construct(
        private EntryRepositoryInterface $repository,
    ) {}

    /**
     * Records valid and spam submissions alike (spam with the `spam` status),
     * then writes `entry_id` and `entry_status` into the registry for the
     * observers that follow.
     *
     * @throws Throwable When the entry cannot be stored; the submission must not report success.
     *
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; each entry is checked before use.
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        $registry = $subject instanceof BuilderForm ? $subject->registry : null;
        $form     = $registry['form'] ?? null;
        $values   = $registry['values'] ?? null;
        if (null === $registry || ! $form instanceof FormDefinition || null === $form->id || ! is_array($values)) {
            return;
        }

        $context = $registry['context'] ?? [];
        $context = is_array($context) ? $context : [];
        $ip      = $context['ip'] ?? null;
        $userId  = $context['user_id'] ?? null;
        $meta    = $context['meta'] ?? [];
        $status  = true === ($registry['spam'] ?? false)
            ? LaminasDbEntryRepository::STATUS_SPAM
            : LaminasDbEntryRepository::STATUS_COMPLETE;

        $registry['entry_id'] = $this->repository->record(
            $form->id,
            $values,
            $status,
            is_scalar($ip) ? (string) $ip : null,
            is_numeric($userId) ? (int) $userId : null,
            is_array($meta) ? $meta : [],
        );
        $registry['entry_status'] = $status;
    }
}
