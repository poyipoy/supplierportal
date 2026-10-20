<?php

namespace App\Support;

final class PageRegistry
{
    public static function url(string $routeName, ?string $fallback = null): string
    {
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
