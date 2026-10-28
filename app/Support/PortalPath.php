<?php

namespace App\Support;

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

    public static function enabled(): bool
    {
        return (bool) config('portal_urls.prefix.enabled', false);
    }

    public static function prefix(): string
    {
        if (! self::enabled()) {
            return '';
        }

        self::assertValid();

        return config('portal_urls.prefix.value');
    }

    public static function path(string $path): string
    {
        $prefix = self::prefix();

        return ($prefix === '' ? '' : '/'.$prefix).'/'.ltrim($path, '/');
    }

    public static function strip(string $path): string
    {
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
}
