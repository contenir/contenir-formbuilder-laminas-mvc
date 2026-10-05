<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Container\Services;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Http\PhpEnvironment\Request;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_callable;
use function is_string;

/**
 * Builds a shared {@see TokenReplacer} for notification + redirect URL
 * substitution. The `site:` namespace is wired from
 * `formbuilder.site_context`; consumers can register additional
 * namespaces via `formbuilder.token_resolvers`, an array keyed by
 * namespace name with values that are service-manager ids resolving
 * to callables of shape `function (string $key): ?string`.
 *
 * Example consumer config:
 *
 *     'formbuilder' => [
 *         'token_resolvers' => [
 *             'settings' => Application\Mail\TokenResolver\SettingsResolver::class,
 *         ],
 *     ],
 *
 * The same instance is consumed by EmailNotificationRegistrar and
 * SubmitController so a `{settings:foo}` token resolves identically in
 * notification subject/body templates and in success-redirect URLs.
 *
 * @api
 */
final class TokenReplacerFactory
{
    /**
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Container services are untyped; the resolver is checked with is_callable().
     * @mago-expect analysis:less-specific-nested-argument-type Resolvers are documented to return ?string; TokenReplacer enforces it.
     */
    private function registerResolver(
        ContainerInterface $container,
        TokenReplacer $tokens,
        int|string $namespace,
        mixed $serviceId,
    ): void {
        if (! is_string($namespace) || ! is_string($serviceId) || ! $container->has($serviceId)) {
            return;
        }

        $resolver = $container->get($serviceId);
        if (is_callable($resolver)) {
            $tokens->register($namespace, $resolver);
        }
    }

    /**
     * Fill in `base_url` from the HTTP request when the consumer hasn't
     * pinned it via config. Without a request host (CLI contexts such as
     * cron or queue workers) the static `site_context.base_url`, if any,
     * is used, or the token is left in place.
     *
     * @param array<array-key, mixed> $siteContext
     *
     * @return array<string, mixed>
     *
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Server variables are untyped; each is checked with is_string().
     * @mago-expect analysis:less-specific-return-statement Configuration keys are strings by convention.
     */
    private function siteContext(ContainerInterface $container, array $siteContext): array
    {
        $request = Services::optional($container, 'Request', Request::class);
        if (array_key_exists('base_url', $siteContext) || null === $request) {
            return $siteContext;
        }

        $host  = $request->getServer('HTTP_HOST');
        $https = $request->getServer('HTTPS', '');
        if (! is_string($host) || '' === $host) {
            return $siteContext;
        }

        $secure                  = ! in_array($https, [null, '', 'off'], strict: true);
        $siteContext['base_url'] = ($secure ? 'https' : 'http') . "://{$host}";

        return $siteContext;
    }

    /**
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each value is checked before use.
     */
    public function __invoke(ContainerInterface $container): TokenReplacer
    {
        $config      = Services::config($container);
        $siteContext = $config['site_context'] ?? [];
        $resolvers   = $config['token_resolvers'] ?? [];

        $tokens = new TokenReplacer($this->siteContext(
            $container,
            is_array($siteContext) ? $siteContext : [],
        ));

        foreach (is_array($resolvers) ? $resolvers : [] as $namespace => $serviceId) {
            $this->registerResolver($container, $tokens, $namespace, $serviceId);
        }

        return $tokens;
    }
}
