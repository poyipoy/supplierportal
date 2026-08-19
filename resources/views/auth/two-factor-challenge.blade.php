@extends('layouts.auth')

@section('title', 'Two-Factor Challenge - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width: 72px; height: auto;">
        <h4 class="fw-bold text-dark mb-1">Verify your sign-in</h4>
        <p class="text-muted small mb-0">Enter the 6-digit code from your authenticator app or one recovery code.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.challenge') }}">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label fw-medium">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="text" autocomplete="one-time-code"
                class="form-control text-center font-monospace @error('code') is-invalid @enderror"
                maxlength="32" autofocus required>
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-login w-100">
            <i class="bi bi-shield-check me-2"></i>Verify
        </button>
    </form>

    <div class="auth-footer">This challenge expires after 10 minutes.</div>
@endsection
