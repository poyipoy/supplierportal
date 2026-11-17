<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\KnownDeviceService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class RevokeKnownDeviceController extends Controller
{
    public function __invoke(Request $request, KnownDeviceService $knownDevices): RedirectResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $deviceHash = Crypt::decryptString($validated['device_token']);
        } catch (DecryptException) {
            return back()->with('status', 'device-not-found');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $deviceHash) !== 1) {
            return back()->with('status', 'device-not-found');
        }

        $currentDeviceHash = $knownDevices->currentDeviceHash($request);
        if ($currentDeviceHash !== null && hash_equals($currentDeviceHash, $deviceHash)) {
            return back()->with('warning', __('security.cannot_revoke_current_device'));
        }

        $revoked = $knownDevices->revoke($request->user(), $deviceHash);

        if ($revoked) {
            event(new AuthSecurityEvent('known_device_revoked', $request->user(), metadata: ['reason' => 'manual_device_removal']));
        }

        return back()->with('status', $revoked ? 'device-revoked' : 'device-not-found');
    }
}
