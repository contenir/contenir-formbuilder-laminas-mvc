<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Service\BuilderForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplObserver;
use SplSubject;

#[Group('unit')]
final class StoreSubmissionRegistrarTest extends TestCase
{
    /**
     * @return array<string, array{SplSubject}>
     */
    public static function unstorableSubjectProvider(): array
    {
        $form = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
        );

        return [
            'not a builder form'  => [new class implements SplSubject {
                public function attach(SplObserver $observer): void {}

                public function detach(SplObserver $observer): void {}

                public function notify(): void {}
            }],
            'no registry'         => [new BuilderForm()],
            'no form definition'  => [self::subject(['values' => []])],
            'unsaved form'        => [self::subject([
                'form'   => new FormDefinition(
                    id: null,
                    slug: 'x',
                    title: 'X',
                ),
                'values' => [],
            ])],
            'values not an array' => [self::subject(['form' => $form, 'values' => 'x'])],
        ];
    }

    /**
     * @param array<string, mixed> $registry
     */
    private static function subject(array $registry): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject($registry);

        return $form;
    }

    #[Test]
    public function nonArrayContextIsIgnored(): void
    {
        $repository = $this->createMock(EntryRepositoryInterface::class);
        $repository->expects($this->once())->method('record')->with(5, [], 'complete', null, null, [])->willReturn(1);

        (new StoreSubmissionRegistrar($repository))->update(self::subject([
            'form'    => new FormDefinition(
                id: 5,
                slug: 'contact',
                title: 'Contact',
            ),
            'values'  => [],
            'context' => 'x',
        ]));
    }

    #[Test]
    public function recordsSpamWithTheSpamStatusAndIgnoresUnusableContext(): void
    {
        $repository = $this->createMock(EntryRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('record')
            ->with(5, [], 'spam', null, null, [])
            ->willReturn(1);
        $subject = self::subject([
            'form'    => new FormDefinition(
                id: 5,
                slug: 'contact',
                title: 'Contact',
            ),
            'values'  => [],
            'spam'    => true,
            'context' => ['ip' => ['x'], 'user_id' => 'guest', 'meta' => 'x'],
        ]);

        (new StoreSubmissionRegistrar($repository))->update($subject);

        static::assertSame('spam', $subject->registry['entry_status']);
    }

    #[Test]
    public function recordsTheSubmissionAndPublishesTheEntryId(): void
    {
        $repository = $this->createMock(EntryRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('record')
            ->with(5, ['name' => 'Ann'], 'complete', '10.0.0.1', 7, ['user_agent' => 'UA'])
            ->willReturn(42);
        $subject = self::subject([
            'form'    => new FormDefinition(
                id: 5,
                slug: 'contact',
                title: 'Contact',
            ),
            'values'  => ['name' => 'Ann'],
            'spam'    => false,
            'context' => ['ip' => '10.0.0.1', 'user_id' => '7', 'meta' => ['user_agent' => 'UA']],
        ]);

        (new StoreSubmissionRegistrar($repository))->update($subject);

        static::assertSame([42, 'complete'], [$subject->registry['entry_id'], $subject->registry['entry_status']]);
    }

    #[Test]
    #[DataProvider('unstorableSubjectProvider')]
    public function storesNothingWithoutASavedFormAndValues(SplSubject $subject): void
    {
        $repository = $this->createMock(EntryRepositoryInterface::class);
        $repository->expects($this->never())->method('record');

        (new StoreSubmissionRegistrar($repository))->update($subject);
    }
}
