<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Integration;

use Contenir\FormBuilder\Laminas\Mvc\ConfigProvider;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Laminas\Http\Request;
use Laminas\Router\Http\TreeRouteStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Matches request paths against the provided route through a real
 * laminas-router stack.
 */
#[Group('integration')]
final class ConfigProviderTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unroutablePathProvider(): array
    {
        return [
            'upper-case slug'      => ['/forms/submit/Contact'],
            'slug led by a hyphen' => ['/forms/submit/-contact'],
            'missing slug'         => ['/forms/submit/'],
        ];
    }

    #[Test]
    #[DataProvider('unroutablePathProvider')]
    public function rejectsASlugOutsideTheConstraint(string $path): void
    {
        static::assertNull($this->match($path));
    }

    #[Test]
    public function routesASubmissionToTheSubmitController(): void
    {
        static::assertSame(
            ['controller' => SubmitController::class, 'action' => 'submit', 'slug' => 'contact-us-2'],
            $this->match('/forms/submit/contact-us-2'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function match(string $path): ?array
    {
        $router = TreeRouteStack::factory((new ConfigProvider())->getRouter());

        return $router->match((new Request())->setUri("https://example.test{$path}"))?->getParams();
    }
}
