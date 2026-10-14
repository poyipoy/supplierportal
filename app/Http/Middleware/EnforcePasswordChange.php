<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->must_change_password || $request->routeIs('profile.security', 'password.update', 'logout', 'password.confirm', 'password.confirm.store', 'locale.*')) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => __('finance_controls.password_required'), 'redirect' => route('profile.security')], 403);
        }

        return redirect()->route('profile.security')->with('warning', __('finance_controls.password_required'));
    }
}
