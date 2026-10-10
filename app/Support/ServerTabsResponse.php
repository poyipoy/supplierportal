<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server side of the AdasiServerTabs contract (resources/js/server-tabs.js).
 *
 * The browser asks for a tab/filter/page as a JSON fragment by sending the X-Adasi-Server-Tabs header. A bare
 * `X-Requested-With` is not enough: DataTables and other XHR share the same URLs, so controllers must test
 * wants() before any legacy `$request->ajax()` branch.
 */
final class ServerTabsResponse
{
    public const HEADER = 'X-Adasi-Server-Tabs';

    public static function wants(Request $request): bool
    {
        return $request->header(self::HEADER) === '1';
    }

    /**
     * @param  array<string, mixed>  $extra  Optional payload keys, e.g. `nav` (server-rendered tab nav that replaces
     *                                       the `[data-server-tabs-nav]` markup) or page specific `metrics`.
     */
    public static function make(string $html, string $tab, Request $request, array $extra = []): JsonResponse
    {
        return response()->json([
            'html' => $html,
            'tab' => $tab,
            'url' => $request->fullUrl(),
            ...$extra,
        ]);
    }
}
