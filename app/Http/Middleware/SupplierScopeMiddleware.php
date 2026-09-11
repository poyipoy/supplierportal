<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SupplierScopeMiddleware
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        abort_unless($request->user()?->hasSupplierScope($scope), 403);
        $request->session()->put('supplier_context', $scope);

        return $next($request);
    }
}
