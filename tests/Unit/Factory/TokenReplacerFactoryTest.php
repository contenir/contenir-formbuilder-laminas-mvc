<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Factory\TokenReplacerFactory;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TokenReplacerFactoryTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function baseUrlProvider(): array
    {
        return [
            'https request' => [['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'], 'https://example.com'],
            'https off'     => [['HTTP_HOST' => 'example.com', 'HTTPS' => 'off'], 'http://example.com'],
            'plain http'    => [['HTTP_HOST' => 'example.com:8080'], 'http://example.com:8080'],
            'no host'       => [[], '{site:base_url}'],
            'empty host'    => [['HTTP_HOST' => ''], '{site:base_url}'],
        ];
    }

    #[Test]
    public function configuredBaseUrlWinsOverTheRequest(): void
    {
        $request = new Request();
        $request->setServer(new Parameters(['HTTP_HOST' => 'example.com']));
        $container = new InMemoryContainer([
            'Request' => $request,
            'config'  => ['formbuilder' => ['site_context' => ['base_url' => 'https://cdn.example', 'name' => 'Site']]],
        ]);

        $tokens = (new TokenReplacerFactory())($container);

        static::assertSame('https://cdn.example Site', $tokens->replace(
            '{site:base_url} {site:name}',
            $this->form(),
            [],
        ));
    }

    /**
     * @param array<string, mixed> $server
     */
    #[Test]
    #[DataProvider('baseUrlProvider')]
    public function derivesTheBaseUrlFromTheRequest(array $server, string $expected): void
    {
        $request = new Request();
        $request->setServer(new Parameters($server));

        $tokens = (new TokenReplacerFactory())(new InMemoryContainer(['Request' => $request]));

        static::assertSame($expected, $tokens->replace('{site:base_url}', $this->form(), []));
    }

    #[Test]
    public function nonArrayResolverConfigIsIgnored(): void
    {
        $tokens = (new TokenReplacerFactory())(new InMemoryContainer([
            'config' => ['formbuilder' => ['token_resolvers' => 'x']],
        ]));

        static::assertSame('{a:b}', $tokens->replace('{a:b}', $this->form(), []));
    }

    #[Test]
    public function registersConfiguredResolverServices(): void
    {
        $container = new InMemoryContainer([
            'config'            => [
                'formbuilder' => ['token_resolvers' => [
                    'settings' => 'resolver.settings',
                    'missing'  => 'resolver.missing',
                    'broken'   => 'resolver.broken',
                    'numbered' => 5,
                    0          => 'resolver.settings',
                ]],
            ],
            'resolver.settings' => static fn(string $key): string => "S:{$key}",
            'resolver.broken'   => 'not callable',
        ]);

        $tokens = (new TokenReplacerFactory())($container);

        static::assertSame(
            'S:phone {missing:x} {broken:x} {numbered:x}',
            $tokens->replace('{settings:phone} {missing:x} {broken:x} {numbered:x}', $this->form(), []),
        );
    }

    #[Test]
    public function withoutARequestServiceTheBaseUrlTokenStays(): void
    {
        $tokens = (new TokenReplacerFactory())(new InMemoryContainer([
            'config' => ['formbuilder' => ['site_context' => 'x']],
        ]));

        static::assertSame('{site:base_url}', $tokens->replace('{site:base_url}', $this->form(), []));
    }

    private function form(): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
        );
    }
}
