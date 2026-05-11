@extends('layouts.auth')

@section('title', __('Login') . ' - ADASI Supplier Portal')

@section('content')
    {{-- Logo --}}
    <div class="auth-logo">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width: 100px; height: auto; margin-bottom: 1rem;">
        <h4>{{ __('ADASI Supplier Portal') }}</h4>
        <p>PT. Astra Daido Steel Indonesia</p>
    </div>

    {{-- Login Form --}}
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label fw-medium" style="font-size:0.875rem;">{{ __('Email') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" placeholder="{{ __('nama@email.com') }}" required autofocus>
            </div>
            @error('email')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-medium" style="font-size:0.875rem;">{{ __('Password') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="********" required>
            </div>
            @error('password')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3 form-check">
            <input type="checkbox" name="remember" class="form-check-input" id="remember"
                   {{ old('remember') ? 'checked' : '' }}>
            <label class="form-check-label small" for="remember">{{ __('Ingat saya') }}</label>
        </div>

        <button type="submit" class="btn btn-login w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>{{ __('Masuk') }}
        </button>
    </form>

    <div class="auth-footer">
        &copy; {{ date('Y') }} PT. Astra Daido Steel Indonesia
    </div>
@endsection
