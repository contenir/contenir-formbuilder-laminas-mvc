<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\State;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Laminas\Session\Container;
use Laminas\Session\SessionManager;
use Laminas\Session\Storage\ArrayStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormStateStashTest extends TestCase
{
    private FormStateStash $stash;

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
    public function entriesAreScopedPerSlug(): void
    {
        $this->stash->store('contact', ['name' => 'Alice'], []);
        $this->stash->store('enquiry', ['name' => 'Bob'], []);

        static::assertSame(['name' => 'Alice'], $this->stash->consume('contact')['values'] ?? null);
        static::assertSame(['name' => 'Bob'], $this->stash->consume('enquiry')['values'] ?? null);
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

    protected function setUp(): void
    {
        Container::setDefaultManager(null);
        $manager = (new SessionManager())->setStorage(new ArrayStorage());
        Container::setDefaultManager($manager);

        $this->stash = new FormStateStash(new Container('FormStateStashTest'));
    }

    protected function tearDown(): void
    {
        Container::setDefaultManager(null);
    }
}
