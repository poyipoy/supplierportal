<?php

namespace App\Http\Middleware;

use App\Models\SupplierRegistrationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationSession
{
    /**
     * Ensure the applicant has an active, unexpired, and unrevoked registration session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accessId = $request->session()->get('registration_access_id');
        $userId = $request->session()->get('registration_user_id');

        if (! $accessId || ! $userId) {
            return redirect()->route('supplier.registration.access-form')
                ->with('error', __('Please enter your registration reference and access key to view or update your registration.'));
        }

        /** @var SupplierRegistrationAccess|null $access */
        $access = SupplierRegistrationAccess::query()
            ->with(['user'])
            ->whereKey($accessId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $access) {
            $request->session()->forget(['registration_access_id', 'registration_user_id']);

            return redirect()->route('supplier.registration.access-form')
                ->with('error', __('Your registration session has expired or been revoked. Please verify your reference and access key.'));
        }

        $request->attributes->set('registrationAccess', $access);

        return $next($request);
    }
}
