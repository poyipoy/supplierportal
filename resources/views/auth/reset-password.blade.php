@extends('layouts.auth')

@section('title', 'Reset Password - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width:72px; height:auto; margin-bottom:1rem;">
        <h4 class="fw-bold text-dark mb-1">ADASI Supplier Portal</h4>
        <p>Account security</p>
    </div>

    <div class="mb-4">
        <h1 class="h4 fw-bold mb-2">Create a new password</h1>
        <p class="text-muted small mb-0">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label fw-medium" style="font-size:0.875rem;">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                <input id="email" type="email" name="email"
                    class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email', $request->email) }}" placeholder="name@email.com" autocomplete="username"
                    autocapitalize="none" spellcheck="false" required autofocus>
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-medium" style="font-size:0.875rem;">New Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                <input id="password" type="password" name="password"
                    class="form-control @error('password') is-invalid @enderror"
                    placeholder="Create a strong password" autocomplete="new-password" minlength="12" maxlength="255" required>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-medium" style="font-size:0.875rem;">Confirm New Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <input id="password_confirmation" type="password" name="password_confirmation"
                    class="form-control @error('password_confirmation') is-invalid @enderror"
                    placeholder="Repeat your new password" autocomplete="new-password" minlength="12" maxlength="255" required>
            </div>
            @error('password_confirmation')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-login w-100">Reset Password</button>
    </form>

    <p class="text-center small mt-4 mb-0">
        <a href="{{ route('login') }}" class="text-decoration-none fw-semibold"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Login</a>
    </p>
    <p class="auth-footer mb-0">&copy; {{ now()->year }} ADASI Supplier Portal</p>
@endsection
