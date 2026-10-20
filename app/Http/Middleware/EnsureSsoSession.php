<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\SsoEntryController;
use App\Services\Auth\Sso\SsoTransactionStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSsoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sso.enabled') || ! $request->user()) {
            return $next($request);
        }

        $exempt = $request->routeIs(
            'login', 'login.store', 'two-factor.*', 'passport.*', 'sso.*', 'logout',
            'profile.security', 'password.update', 'password.confirm', 'password.confirm.store',
            'password.request', 'locale.*', 'supplier.register', 'supplier.register.store', 'supplier.registration.*',
            'receipts.verify-supplier', 'receipts.verify-ga',
        );

        // Preserve forced password change before authorizing the portal session.
        // The transaction's final destination survives the detour via Security.
        if ($request->user()->must_change_password) {
            if (! $exempt && ! $request->session()->has('sso.intended') && $request->isMethod('GET')) {
                $request->session()->put('sso.intended', app(SsoTransactionStore::class)->safeIntendedUrl($request, $request->fullUrl()));
            }

            return $next($request);
        }

        $verified = $request->session()->get('sso.verified_at');
        if ($exempt || (is_int($verified) && $verified > 0 && $verified <= now()->timestamp // biz-time:ignore session proof is an instant
            && $request->session()->get('sso.client_id') === (string) config('sso.client_id'))) {
            return $next($request);
        }

        $request->session()->forget(['sso.verified_at', 'sso.client_id']);
        if ($request->expectsJson()
            || $request->is('notifications/unread-count', 'conversations/unread-count', 'conversations/*/messages/latest', 'exports/*/status')) {
            return response()->json(['message' => __('auth.feedback.unauthenticated')], 401)
                ->header('Cache-Control', 'no-store, private');
        }

        $request->session()->put('url.intended', $request->session()->get('sso.intended',
            $request->isMethod('GET') ? $request->fullUrl() : route('dashboard', absolute: false)));

        // /login remains guest-only: begin directly to avoid a guest/dashboard loop.
        return app()->call([app(SsoEntryController::class), 'start']);
    }
}
