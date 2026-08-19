<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutOtherDevicesController extends Controller
{
    public function __invoke(Request $request, SessionRevocationService $revocation): RedirectResponse
    {
        $validated = $request->validateWithBag('logoutOtherDevices', [
            'password' => ['required', 'string', 'max:255', 'current_password'],
        ]);

        Auth::logoutOtherDevices($validated['password']);
        $revocation->revokeOtherSessions($request->user(), $request, false);

        return back()->with('status', 'other-devices-logged-out');
    }
}
