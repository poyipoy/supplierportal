<?php

namespace App\Http\Middleware;

use App\Support\PurchasingNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberPurchasingListUrl
{
    /**
     * Store the latest purchasing list URL, including active filters.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('GET')
            && ! $request->expectsJson()
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
