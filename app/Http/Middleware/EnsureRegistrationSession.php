<?php

namespace App\Http\Middleware;

use App\Models\SupplierRegistrationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationSession
{
    /**
     * Validate tracking proof and restrict final decisions to their result page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accessId = $request->session()->get('registration_access_id');
        $userId = $request->session()->get('registration_user_id');

        if (! $accessId || ! $userId) {
            return redirect()->route('supplier.registration.access-form')
                ->with('error', __('auth.registration_session.required'));
        }

        /** @var SupplierRegistrationAccess|null $access */
        $access = SupplierRegistrationAccess::query()
            ->with(['user', 'latestAttempt'])
            ->whereKey($accessId)
            ->where('user_id', $userId)
            ->first();

        if (! $access || ! $access->canViewStatus()
            || ! hash_equals($access->registration_reference, (string) $request->session()->get('registration_cycle_reference', ''))) {
            $request->session()->forget(['registration_access_id', 'registration_user_id', 'registration_cycle_reference']);

            return redirect()->route('supplier.registration.access-form')
                ->with('error', __('auth.registration_session.expired'));
        }

        abort_if($access->hasFinalResult() && ! $request->routeIs('supplier.registration.status'), 403);

        $request->attributes->set('registrationAccess', $access);

        return $next($request);
    }
}
