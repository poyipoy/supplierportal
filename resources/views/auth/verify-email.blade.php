@extends('layouts.auth')

@section('title', 'Verify Email - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width:72px; height:auto; margin-bottom:1rem;">
        <h4 class="fw-bold text-dark mb-1">ADASI Supplier Portal</h4>
        <p>Account security</p>
    </div>

    <div class="mb-4">
        <h1 class="h4 fw-bold mb-2">Verify your email address</h1>
        <p class="text-muted small mb-0">Before getting started, open the verification link sent to your email address. If you did not receive it, we can send another one.</p>
    </div>

    @if (session('status') === 'verification-link-sent')
        <div class="alert alert-success small d-flex gap-2 align-items-start" role="status">
            <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
            <span>A new verification link has been sent to your email address.</span>
        </div>
    @endif

    <div class="d-grid gap-2">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-login w-100">Resend Verification Email</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary w-100">Log Out</button>
        </form>
    </div>
    <p class="auth-footer mb-0">&copy; {{ now()->year }} ADASI Supplier Portal</p>
@endsection
