<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Auth\SessionInventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly SessionInventoryService $sessions) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $supplierScopes = $user->isSupplier()
            ? array_values(array_intersect(['import', 'local'], $user->supplierScopes()->pluck('scope')->all()))
            : [];

        return view('profile.edit', [
            'user' => $user,
            'supplierScopes' => $supplierScopes,
        ]);
    }

    /**
     * Display the current user's account security controls.
     */
    public function security(Request $request): View
    {
        return view('profile.security', [
            'user' => $request->user(),
            'activeSessions' => $this->sessions->activeSessionsFor(
                $request->user(),
                $request->session()->getId(),
            ),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only('name'));

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
