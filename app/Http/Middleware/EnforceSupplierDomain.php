<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnforceSupplierDomain
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->isLocalOperator() && $request->routeIs('attachments.*', 'conversations.*', 'shared.pdf.*')) {
            abort(403);
        }
        if ($request->user()?->isSupplier() && ! $request->user()->hasSupplierScope('import') && $request->routeIs('exports.*')) {
            abort(403);
        }
        // Shared Import endpoints are outside the supplier route group.
        if ($request->user()?->isSupplier() && $request->routeIs('supplier.*', 'attachments.*', 'conversations.*', 'shared.pdf.*')) {
            abort_unless($request->user()->hasSupplierScope('import'), 403);
        }

        return $next($request);
    }
}
