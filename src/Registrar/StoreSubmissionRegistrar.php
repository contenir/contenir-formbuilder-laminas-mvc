<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use SplObserver;
use SplSubject;
use Throwable;

use function is_array;
use function is_numeric;
use function is_scalar;

/**
 * Stores a submitted form through the {@see EntryRepositoryInterface}.
 *
 * Reads the {@see BuilderForm} subject's registry for the form definition,
 * the canonical submitted values and the submission context, then delegates
 * to {@see EntryRepositoryInterface::record()} so the persistence concern
 * stays in one place.
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
            ? EntryRepositoryInterface::STATUS_SPAM
            : EntryRepositoryInterface::STATUS_COMPLETE;

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
