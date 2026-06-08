<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! $request->user()->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        if (! in_array($request->user()->role, $roles)) {
            \Illuminate\Support\Facades\Log::error('RoleMiddleware 403:', [
                'user_role' => $request->user()->role,
                'expected_roles' => $roles,
                'url' => $request->fullUrl(),
            ]);
            abort(403, 'You do not have access to this page.');
        }

        return $next($request);
    }
}
