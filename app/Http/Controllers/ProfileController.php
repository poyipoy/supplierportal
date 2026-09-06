<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Auth\SessionInventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        return view('profile.edit', [
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
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account safely.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'string', 'max:255', 'current_password'],
        ]);

        $user = $request->user();

        // 1. Dependency pre-check for clean UX before attempting deletion
        if ($user->hasBlockingProcurementHistory()) {
            return back()->withErrors([
                'password' => 'This account cannot be deleted because it is associated with active or historical procurement records (quotations, purchase orders, requisitions, inspections, or claims). Please contact an administrator.',
            ], 'userDeletion');
        }

        // 2. Clear remember token in memory so SessionGuard::logout() does not re-insert the user via cycleRememberToken
        $user->setRememberToken(null);

        // 3. Attempt deletion safely within a database transaction
        try {
            DB::transaction(function () use ($user) {
                $user->delete();
            });
        } catch (QueryException $exception) {
            report($exception);

            return back()->withErrors([
                'password' => 'This account cannot be deleted because it has linked system records. Please contact an administrator.',
            ], 'userDeletion');
        }

        // 4. Only logout and invalidate session AFTER deletion succeeds in DB
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
