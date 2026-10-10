<?php

namespace App\Http\Middleware;

use App\Support\PurchasingNavigation;
use App\Support\ServerTabsResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberPurchasingListUrl
{
    /**
     * Store the latest purchasing list URL, including active filters.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Server-tabs fragments are the page the user is looking at (pushState mirrors this URL), so they count;
        // other JSON (DataTables feeds, chart payloads) must not replace the remembered list.
        if (
            $request->isMethod('GET')
            && (! $request->expectsJson() || ServerTabsResponse::wants($request))
            && $request->input('view') !== 'json'
            && $request->user()?->role === 'purchasing'
        ) {
            $routeName = $request->route()?->getName();

            if (PurchasingNavigation::isListRoute($routeName)) {
                PurchasingNavigation::rememberListUrl(
                    $routeName,
                    $request->fullUrlWithoutQuery([PurchasingNavigation::RETURN_URL_KEY])
                );
            }
        }

        return $next($request);
    }
}
