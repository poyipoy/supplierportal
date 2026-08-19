@extends('layouts.auth')

@section('title', 'Login - ADASI Supplier Portal')

@section('content')
<header>
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Welcome back</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Sign in to your account</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Use your assigned ADASI Supplier Portal credentials.</p>
</header>

<form method="POST" action="{{ route('login') }}" class="tw-mt-6 tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="email" id="email" type="email" label="Email" placeholder="name@email.com" autocomplete="email" required autofocus />

    <div x-data="{ visible: false }" class="tw-grid tw-gap-1.5">
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
            <label for="password" class="tw-text-ui-sm tw-font-medium">Password <span class="tw-text-error" aria-hidden="true">*</span></label>
            <a href="{{ route('password.request') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Forgot password?</a>
        </div>
        <div class="tw-relative">
            <input
                id="password"
                :type="visible ? 'text' : 'password'"
                name="password"
                class="ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-pe-12 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                placeholder="Enter your password"
                autocomplete="current-password"
                maxlength="255"
                @if($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif
                required
            >
            <button type="button" class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-10 tw-w-10 tw-items-center tw-justify-center tw-rounded-ui-full tw-text-on-surface-variant hover:tw-bg-surface-container" @click="visible = !visible" :aria-label="visible ? 'Hide password' : 'Show password'">
                <i class="bi" :class="visible ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i>
            </button>
        </div>
        @error('password')<p id="password-error" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
    </div>

    <label class="tw-flex tw-items-start tw-gap-2 tw-text-ui-sm" for="remember">
        <input type="checkbox" name="remember" value="1" class="form-check-input tw-mt-0.5" id="remember" {{ old('remember') ? 'checked' : '' }}>
        <span><span class="tw-block tw-font-medium">Remember me</span><span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">Keep me signed in on this device.</span></span>
    </label>

    @if ($turnstileRequired && $turnstileSiteKey)
        <div>
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
            @error('cf-turnstile-response')<p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endif

    <x-ui.button type="submit" class="tw-w-full">
        <x-slot:leading><i class="bi bi-box-arrow-in-right"></i></x-slot:leading>
        Sign In
    </x-ui.button>
</form>
@endsection

@section('scripts')
@if ($turnstileRequired && $turnstileSiteKey)
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
@endsection
