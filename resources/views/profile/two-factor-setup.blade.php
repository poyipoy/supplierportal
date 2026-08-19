@extends('layouts.auth')

@section('title', 'Set Up Two-Factor Authentication - ADASI Supplier Portal')

@section('content')
    <div class="text-center mb-3">
        <h4 class="fw-bold text-dark">Set up two-factor authentication</h4>
        <p class="small text-muted">Scan this QR code with an authenticator app, then enter the generated 6-digit code.</p>
        <img src="{{ $qrCode }}" alt="Two-factor authentication QR code" class="img-fluid mx-auto my-3" style="max-width: 220px;">
    </div>

    <div class="alert alert-light border small">
        <span class="text-muted d-block mb-1">Manual setup key</span>
        <code class="text-break user-select-all">{{ $secret }}</code>
    </div>

    <form method="POST" action="{{ route('profile.two-factor.confirm') }}">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label fw-medium">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                autocomplete="one-time-code" maxlength="6"
                class="form-control text-center font-monospace @error('code') is-invalid @enderror" required autofocus>
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-login w-100">Enable two-factor authentication</button>
        <a href="{{ route('profile.edit') }}" class="btn btn-link text-muted w-100 mt-2">Cancel</a>
    </form>
@endsection
