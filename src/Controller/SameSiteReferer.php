<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Controller;

use function in_array;
use function is_array;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Reduces a client-supplied Referer to a redirect target on this site.
 *
 * The Referer header is controlled by the client, so redirecting to it as-is
 * is an open redirect. A local path is kept (but not `//host` or `/\host`,
 * which browsers treat as another site), as is an absolute http(s) URL whose
 * host, and port if given, match the current request's. Anything else,
 * including any absolute URL when the request host is unknown, becomes `/`.
 *
 * @internal
 */
final class SameSiteReferer
{
    public static function resolve(string $referer, ?string $requestHost, ?int $requestPort): string
    {
        if ($referer === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $referer) === 1) {
            return '/';
        }
        if (str_starts_with($referer, '/')) {
            return str_starts_with($referer, '//') ? '/' : $referer;
        }

        return self::isSameSite(parse_url($referer), $requestHost, $requestPort) ? $referer : '/';
    }

    /**
     * @param array<string, int|string>|false $parts
     */
    private static function isSameSite(array|false $parts, ?string $requestHost, ?int $requestPort): bool
    {
        if (! is_array($parts) || $requestHost === null || $requestHost === '') {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $port   = $parts['port'] ?? $requestPort;

        return in_array($scheme, ['http', 'https'], true)
            && strtolower($requestHost) === $host
            && $port === $requestPort;
    }
}
