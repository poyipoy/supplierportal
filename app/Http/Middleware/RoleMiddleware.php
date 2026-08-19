<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! in_array($request->user()->role, $roles)) {
            Log::error('RoleMiddleware 403:', [
                'user_role' => $request->user()->role,
                'expected_roles' => $roles,
                'url' => $request->fullUrl(),
            ]);
            abort(403, 'You do not have access to this page.');
        }

        return $next($request);
    }
}
