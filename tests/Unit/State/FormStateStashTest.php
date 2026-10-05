<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\State;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\Tests\Trait\InMemorySessionTrait;
use Laminas\Session\Container;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormStateStashTest extends TestCase
{
    use InMemorySessionTrait;

    private Container $container;

    private FormStateStash $stash;

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedEntryProvider(): array
    {
        return [
            'not an array'     => ['x'],
            'missing errors'   => [['values' => []]],
            'non-array values' => [['values' => 'x', 'errors' => []]],
            'non-array errors' => [['values' => [], 'errors' => 'x']],
        ];
    }

    #[Test]
    public function consumeIsOneShot(): void
    {
        $this->stash->store('contact', ['name' => 'Alice'], []);

        $this->stash->consume('contact');

        static::assertNull($this->stash->consume('contact'));
    }

    #[Test]
    public function consumeReturnsNullWhenNothingStashed(): void
    {
        static::assertNull($this->stash->consume('contact'));
    }

    #[Test]
    public function defaultContainerUsesTheFormbuilderNamespace(): void
    {
        (new FormStateStash())->store('contact', ['a' => 1], []);

        static::assertSame(
            ['values' => ['a' => 1], 'errors' => []],
            (new Container('ContenirFormBuilderFlash'))['form_contact'],
        );
    }

    #[Test]
    public function entriesAreScopedPerSlug(): void
    {
        $this->stash->store('contact', ['name' => 'Alice'], []);
        $this->stash->store('enquiry', ['name' => 'Bob'], []);

        static::assertSame(['name' => 'Alice'], $this->stash->consume('contact')['values'] ?? null);
        static::assertSame(['name' => 'Bob'], $this->stash->consume('enquiry')['values'] ?? null);
    }

    #[Test]
    #[DataProvider('malformedEntryProvider')]
    public function malformedEntriesAreDiscarded(mixed $entry): void
    {
        $this->container['form_contact'] = $entry;

        static::assertSame([null, false], [
            $this->stash->consume('contact'),
            $this->container->offsetExists('form_contact'),
        ]);
    }

    #[Test]
    public function slugsThatDifferOnlyByCaseShareAnEntry(): void
    {
        $this->stash->store('Contact', ['name' => 'Alice'], []);

        $consumed = $this->stash->consume('contact');

        static::assertNotNull($consumed);
        static::assertSame(['name' => 'Alice'], $consumed['values']);
    }

    #[Test]
    public function slugsWithDisallowedCharsAreNormalisedToTheSameKey(): void
    {
        $this->stash->store('contact form!', ['name' => 'Alice'], []);

        $consumed = $this->stash->consume('contact_form_');

        static::assertNotNull($consumed);
        static::assertSame(['name' => 'Alice'], $consumed['values']);
    }

    #[Test]
    public function storeThenConsumeReturnsTheStashedPayload(): void
    {
        $this->stash->store(
            'contact',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['email' => ['Invalid email']],
        );

        $consumed = $this->stash->consume('contact');

        static::assertSame(
            [
                'values' => ['name' => 'Alice', 'email' => 'alice@example.com'],
                'errors' => ['email' => ['Invalid email']],
            ],
            $consumed,
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->container = new Container('FormStateStashTest');
        $this->stash     = new FormStateStash($this->container);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }
}
