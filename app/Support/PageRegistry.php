<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class PageRegistry
{
    /**
     * Build a canonical Infor-style context URL when the feature flag is active.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $query
     */
    public static function contextUrl(
        string $routeName,
        array $params = [],
        array $query = [],
        ?string $fallback = null
    ): ?string {
        if (! config('portal_urls.context_urls.enabled', false)) {
            return null;
        }

        if ($fallback !== null && $params === []) {
            $internalUrl = $fallback;
        } elseif (Route::has($routeName)) {
            $internalUrl = route($routeName, $params);
        } else {
            return null;
        }

        if ($query !== []) {
            $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            if ($qs !== '') {
                $internalUrl .= (str_contains($internalUrl, '?') ? '&' : '?').$qs;
            }
        }

        return PortalContextCodec::project($internalUrl);
    }

    public static function url(string $routeName, ?string $fallback = null): string
    {
        if (config('portal_urls.context_urls.enabled', false)) {
            $contextUrl = self::contextUrl($routeName, fallback: $fallback);
            if ($contextUrl !== null) {
                return $contextUrl;
            }
        }

        if (config('portal_urls.page_registry.enabled', false)) {
            $uuid = array_search($routeName, config('page_registry', []), true);
            if ($uuid !== false) {
                return route('portal.page', ['pageUuid' => $uuid]);
            }
        }

        // Existing purchasing menu links retain their remembered filters while the flag is off.
        return $fallback ?? route($routeName);
    }
}
