<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordConfirmationContinuationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request, PasswordConfirmationContinuationService $continuation): View|RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => 'The entered password is incorrect.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        $pendingAction = $continuation->pull($request);
        if ($pendingAction !== null) {
            return view('auth.password-confirmation-continuation', [
                'action' => $pendingAction,
            ]);
        }

        return redirect()->route('profile.edit');
    }
}
