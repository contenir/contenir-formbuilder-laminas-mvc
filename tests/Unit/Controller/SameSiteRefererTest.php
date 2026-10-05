<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Controller;

use Contenir\FormBuilder\Laminas\Mvc\Controller\SameSiteReferer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The controller tests cover the redirect end to end; these pin down how
 * scheme and host case are compared, which a laminas request URI (always
 * lower-cased) cannot reach.
 */
#[Group('unit')]
final class SameSiteRefererTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function caseProvider(): array
    {
        return [
            'upper-case scheme'       => ['HTTPS://site.example/p', 'site.example', 'HTTPS://site.example/p'],
            'upper-case request host' => ['https://site.example/p', 'SITE.example', 'https://site.example/p'],
            'other upper-case host'   => ['https://evil.example/p', 'SITE.example', '/'],
        ];
    }

    #[Test]
    #[DataProvider('caseProvider')]
    public function comparesSchemeAndHostCaseInsensitively(string $referer, string $host, string $expected): void
    {
        static::assertSame($expected, SameSiteReferer::resolve($referer, $host, null));
    }
}
