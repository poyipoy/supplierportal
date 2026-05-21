<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PurchasingNavigation
{
    public const RETURN_URL_KEY = 'return_url';

    public const LIST_ROUTES = [
        'purchasing.dashboard',
        'purchasing.periods.index',
        'purchasing.requirements.index',
        'purchasing.quotations.index',
        'purchasing.comparison.inter-supplier',
        'purchasing.comparison.historical',
        'purchasing.comparison.vs-best',
        'purchasing.purchase-orders.index',
        'purchasing.conversations.index',
        'purchasing.claims.index',
        'purchasing.reports.index',
    ];

    public static function isListRoute(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::LIST_ROUTES, true);
    }

    public static function rememberListUrl(string $routeName, string $url): void
    {
        session()->put("purchasing.index_urls.{$routeName}", $url);
        session()->put('purchasing.last_list_url', $url);
    }

    public static function listUrl(string $routeName, array $parameters = []): string
    {
        $storedUrl = session("purchasing.index_urls.{$routeName}");

        if (self::isSafeUrl($storedUrl)) {
            return $storedUrl;
        }

        return Route::has($routeName)
            ? route($routeName, $parameters)
            : route('purchasing.dashboard');
    }

    public static function backUrl(?string $fallbackRouteName = null, array $parameters = []): string
    {
        $returnUrl = request()->query(self::RETURN_URL_KEY)
            ?? request()->input(self::RETURN_URL_KEY);

        if (self::isSafeUrl($returnUrl)) {
            return $returnUrl;
        }

        $listRoute = $fallbackRouteName ?: self::fallbackListRoute(request()->route()?->getName());

        if ($listRoute) {
            return self::listUrl($listRoute, $parameters);
        }

        $lastListUrl = session('purchasing.last_list_url');

        if (self::isSafeUrl($lastListUrl)) {
            return $lastListUrl;
        }

        return route('purchasing.dashboard');
    }

    public static function toRoute(string $routeName, mixed $parameters = [], bool $absolute = true): string
    {
        return route($routeName, self::withReturnUrl($parameters), $absolute);
    }

    public static function withReturnUrl(mixed $parameters = []): array
    {
        $returnUrl = self::currentUrlForReturn();

        if (is_array($parameters)) {
            return array_merge($parameters, [self::RETURN_URL_KEY => $returnUrl]);
        }

        if ($parameters === null || $parameters === '') {
            return [self::RETURN_URL_KEY => $returnUrl];
        }

        return [$parameters, self::RETURN_URL_KEY => $returnUrl];
    }

    public static function currentUrlForReturn(): string
    {
        return request()->fullUrlWithoutQuery([self::RETURN_URL_KEY]);
    }

    public static function isSafeUrl(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $url = trim($url);

        if (Str::startsWith($url, ['//', 'javascript:', 'data:'])) {
            return false;
        }

        if (Str::startsWith($url, '/')) {
            return true;
        }

        $parsed = parse_url($url);

        if (! is_array($parsed) || empty($parsed['host'])) {
            return false;
        }

        return ($parsed['host'] ?? null) === request()->getHost()
            && in_array($parsed['scheme'] ?? request()->getScheme(), ['http', 'https'], true);
    }

    public static function listRoutePaths(): array
    {
        return collect(self::LIST_ROUTES)
            ->filter(fn(string $routeName) => Route::has($routeName))
            ->map(fn(string $routeName) => parse_url(route($routeName), PHP_URL_PATH))
            ->filter()
            ->values()
            ->all();
    }

    private static function fallbackListRoute(?string $routeName): ?string
    {
        if (! $routeName || ! Str::startsWith($routeName, 'purchasing.')) {
            return null;
        }

        $segments = explode('.', $routeName);

        if (count($segments) >= 3) {
            return $segments[0] . '.' . $segments[1] . '.index';
        }

        return self::isListRoute($routeName) ? $routeName : null;
    }
}
