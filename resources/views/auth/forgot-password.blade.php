@extends('layouts.auth')

@section('title', 'Forgot Password - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width:72px; height:auto; margin-bottom:1rem;">
        <h4 class="fw-bold text-dark mb-1">ADASI Supplier Portal</h4>
        <p>Account security</p>
    </div>

    <div class="mb-4">
        <h1 class="h4 fw-bold mb-2">Forgot your password?</h1>
        <p class="text-muted small mb-0">Enter your email address and we will send a secure password reset link if an account is available.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label fw-medium" style="font-size:0.875rem;">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                <input id="email" type="email" name="email"
                    class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email') }}" placeholder="name@email.com" autocomplete="email"
                    autocapitalize="none" spellcheck="false" required autofocus>
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-login w-100">Send Reset Link</button>
    </form>

    <p class="text-center small mt-4 mb-0">
        <a href="{{ route('login') }}" class="text-decoration-none fw-semibold"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Login</a>
    </p>
    <p class="auth-footer mb-0">&copy; {{ now()->year }} ADASI Supplier Portal</p>
@endsection
