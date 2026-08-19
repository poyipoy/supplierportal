@extends('layouts.auth')

@section('title', 'Confirm Password - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width:72px; height:auto; margin-bottom:1rem;">
        <h4 class="fw-bold text-dark mb-1">ADASI Supplier Portal</h4>
        <p>Protected action</p>
    </div>

    <div class="mb-4">
        <h1 class="h4 fw-bold mb-2">Confirm your password</h1>
        <p class="text-muted small mb-0">For your security, enter your current password before continuing.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" novalidate>
        @csrf

        <div class="mb-4">
            <label for="password" class="form-label fw-medium" style="font-size:0.875rem;">Current Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                <input id="password" type="password" name="password"
                    class="form-control @error('password') is-invalid @enderror"
                    placeholder="Enter your password" autocomplete="current-password" maxlength="255" required autofocus>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-login w-100">Confirm and Continue</button>
    </form>
    <p class="auth-footer mb-0">&copy; {{ now()->year }} ADASI Supplier Portal</p>
@endsection
