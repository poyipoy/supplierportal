<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        // Normalize the same way LoginRequest does, so lookups aren't
        // sensitive to casing/whitespace and rate-limit keys stay consistent
        // with the login flow's.
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
        ]);

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $exception) {
            // Do not reveal a delivery failure to the requester and do not log the
            // reset token, password, or email address.
            Log::warning('Password reset email delivery failed.', [
                'exception_class' => $exception::class,
            ]);
        }

        return back()->with('status', 'If an account is available, password reset instructions have been sent.');
    }
}
