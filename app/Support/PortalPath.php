<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

final class PortalPath
{
    // Auth, signed receipts, health and protocol endpoints stay at root. 'forgot-password' and 'supplier'
    // are kept reserved so a prefix can never shadow the legacy paths the 301 fallback still resolves.
    private const ROOT_SEGMENTS = [
        'login', 'logout', 'two-factor-challenge', 'forgot-password', 'confirm-password',
        'password', 'oauth', 'sso', 'verify-receipt', 'supplier', 'locale', 'up', 'broadcasting',
        'storage', 'build', 'assets', 'vendor', 'favicon.ico', 'robots.txt',
    ];

    private static ?string $validatedPrefixKey = null;

    public static function resetCache(): void
    {
        self::$validatedPrefixKey = null;
    }

    public static function enabled(): bool
    {
        return (bool) config('portal_urls.prefix.enabled', false);
    }

    public static function prefix(): string
    {
        if (! self::enabled()) {
            return '';
        }

        $prefix = config('portal_urls.prefix.value');
        $cacheKey = is_string($prefix) ? $prefix."\0".public_path() : null;

        if ($cacheKey === null || self::$validatedPrefixKey !== $cacheKey) {
            self::assertValid();
            self::$validatedPrefixKey = $cacheKey;
        }

        return $prefix;
    }

    public static function normalizeBasePath(string $raw): string
    {
        $trimmed = rtrim(trim($raw), '/');
        if ($trimmed === '' || $trimmed === '/') {
            return '';
        }

        if (
            ! str_starts_with($trimmed, '/')
            || str_starts_with($trimmed, '//')
            || str_contains($trimmed, '\\')
            || str_contains($trimmed, '?')
            || str_contains($trimmed, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1
            || preg_match('/(?:^|\/)\.\.(?:\/|$)/', $trimmed) === 1
        ) {
            return '';
        }

        return $trimmed;
    }

    public static function baseUrl(?Request $request = null): string
    {
        if (! $request instanceof Request) {
            if (! app()->bound('request')) {
                return '';
            }
            $bound = request();
            if (! $bound instanceof Request || ! self::isHttpBoundRequest($bound)) {
                return '';
            }
            $request = $bound;
        }

        return self::normalizeBasePath((string) $request->getBaseUrl());
    }

    public static function httpPrefix(?Request $request = null): string
    {
        $base = self::baseUrl($request);
        $prefix = trim(self::prefix(), '/');

        return $base.($prefix !== '' ? '/'.$prefix : '');
    }

    public static function httpPath(string $path, ?Request $request = null): string
    {
        if ($path === '' || str_starts_with($path, '//') || preg_match('/\A[a-zA-Z][a-zA-Z\d+\-.]*:/', $path) === 1) {
            return $path;
        }

        $base = self::baseUrl($request);
        if ($base === '') {
            return $path;
        }

        $splitPos = strcspn($path, '?#');
        $pathPart = '/'.ltrim(substr($path, 0, $splitPos), '/');
        $suffix = substr($path, $splitPos);

        if ($pathPart === $base || str_starts_with($pathPart, $base.'/')) {
            return $pathPart.$suffix;
        }

        return $base.$pathPart.$suffix;
    }

    public static function stripBaseUrl(string $path, ?Request $request = null): string
    {
        $base = self::baseUrl($request);
        if ($base === '' || $path === '') {
            return $path;
        }

        $splitPos = strcspn($path, '?#');
        $pathPart = substr($path, 0, $splitPos);
        $suffix = substr($path, $splitPos);

        if ($pathPart === $base) {
            return '/'.$suffix;
        }

        if (str_starts_with($pathPart, $base.'/')) {
            return substr($pathPart, strlen($base)).$suffix;
        }

        return $path;
    }

    public static function httpUrl(string $pathOrUrl, ?Request $request = null): string
    {
        if ($pathOrUrl === '' || preg_match('/\Ahttps?:\/\//i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        $appRelative = self::stripBaseUrl($pathOrUrl, $request);
        $resolvedRequest = $request ?? (app()->bound('request') && request() instanceof Request ? request() : null);
        if ($resolvedRequest instanceof Request && ($request instanceof Request || self::isHttpBoundRequest($resolvedRequest))) {
            return $resolvedRequest->getSchemeAndHttpHost().self::httpPath($appRelative, $resolvedRequest);
        }

        return url($appRelative);
    }

    public static function routePath(string $name, mixed $parameters = [], ?Request $request = null): string
    {
        return self::httpPath(route($name, $parameters, absolute: false), $request);
    }

    public static function path(string $path): string
    {
        $prefix = self::prefix();

        return ($prefix === '' ? '' : '/'.$prefix).'/'.ltrim($path, '/');
    }

    public static function strip(string $path): string
    {
        $path = self::stripBaseUrl($path);

        $prefixes = array_unique([
            (string) config('portal_urls.prefix.value', 'v2/ADASI_PRD'),
            ...config('portal_urls.prefix.legacy_values', ['v2/ADASI_PRD']),
        ]);
        // Prefer the longest match if a previous prefix is a parent of the current one.
        usort($prefixes, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($prefixes as $prefix) {
            if (! self::hasValidShape($prefix)) {
                continue;
            }
            if ($path === '/'.$prefix) {
                return '/';
            }
            if (str_starts_with($path, '/'.$prefix.'/')) {
                return substr($path, strlen($prefix) + 1);
            }
        }

        return $path;
    }

    public static function assertValid(): void
    {
        if (! self::enabled()) {
            return;
        }

        $prefix = config('portal_urls.prefix.value');
        if (! is_string($prefix) || ! self::hasValidShape($prefix)) {
            throw new RuntimeException('PORTAL_PATH_PREFIX must contain 1 to 4 slash-separated alphanumeric, underscore or hyphen segments, at most 64 characters, without leading or trailing slashes.');
        }

        $firstSegment = strtolower(explode('/', $prefix, 2)[0]);
        $publicDirectories = array_map(
            fn (string $directory): string => strtolower(basename($directory)),
            glob(public_path('*'), GLOB_ONLYDIR) ?: [],
        );
        if (in_array($firstSegment, [...self::ROOT_SEGMENTS, ...$publicDirectories], true)) {
            throw new RuntimeException('PORTAL_PATH_PREFIX conflicts with a root route or public directory. Choose a different first segment.');
        }
    }

    private static function hasValidShape(string $prefix): bool
    {
        return strlen($prefix) <= 64 && preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+){0,3}\z~', $prefix) === 1;
    }

    private static function isHttpBoundRequest(Request $request): bool
    {
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            return true;
        }

        $scriptName = str_replace('\\', '/', (string) $request->server->get('SCRIPT_NAME', ''));

        return str_ends_with($scriptName, '/index.php');
    }
}
