<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            return back()->withErrors(['email' => 'Your account has been deactivated. Contact Admin.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Determine redirect path based on user role.
     */
    protected function redirectByRole(string $role): string
    {
        return match ($role) {
            'admin'      => route('admin.dashboard', absolute: false),
            'purchasing' => route('purchasing.dashboard', absolute: false),
            'supplier'   => route('supplier.dashboard', absolute: false),
            'qc'         => route('qc.dashboard', absolute: false),
            default      => '/',
        };
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
