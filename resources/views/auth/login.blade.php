@extends('layouts.auth')

@section('title', 'Login - ADASI Supplier Portal')

@section('content')
    {{-- Logo --}}
    <div class="auth-logo">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI"
            style="width: 100px; height: auto; margin-bottom: 1rem;">
        <h4>ADASI Supplier Portal</h4>
        <p>PT. Astra Daido Steel Indonesia</p>
    </div>

    {{-- Login Form --}}
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label fw-medium" style="font-size:0.875rem;">Email</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email') }}" placeholder="nama@email.com" autocomplete="email" required autofocus>
            </div>
            @error('email')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-medium" style="font-size:0.875rem;">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="password"
                    class="form-control @error('password') is-invalid @enderror" placeholder="********" autocomplete="current-password" required>
            </div>
            @error('password')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3 form-check d-flex align-items-start gap-2 ps-0">
            <input type="checkbox" name="remember" value="1" class="form-check-input m-0 mt-1" id="remember" {{ old('remember') ? 'checked' : '' }}>
            <label class="form-check-label small" for="remember">
                <span class="fw-medium">Remember me</span>
                <span class="d-block text-muted" style="font-size: 0.72rem;">Keep me signed in on this device.</span>
            </label>
        </div>

        <button type="submit" class="btn btn-login w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>Login
        </button>
    </form>

    <div class="auth-footer">
        &copy; {{ date('Y') }} PT. Astra Daido Steel Indonesia
    </div>
@endsection
