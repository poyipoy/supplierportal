@extends('layouts.auth')

@section('title', 'Sign In - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Supplier Portal</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Sign in to your account</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter your assigned portal credentials.</p>
</header>

<form method="POST" action="{{ route('login') }}" class="tw-grid tw-gap-4">
    @csrf

    <div class="tw-grid tw-gap-1.5">
        <label for="email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Email address</label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="mail" />
            </div>
            <input
                id="email"
                type="email"
                name="email"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ (isset($errors) && $errors->has('email')) ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="name@company.com"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
            >
        </div>
        @if(isset($errors) && $errors->has('email'))<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $errors->first('email') }}</p>@endif
    </div>

    <div x-data="{ visible: false }" class="tw-grid tw-gap-1.5">
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
            <label for="password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Password</label>
            <a href="{{ route('password.request') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Forgot password?</a>
        </div>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="lock" />
            </div>
            <input
                id="password"
                :type="visible ? 'text' : 'password'"
                name="password"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ (isset($errors) && $errors->has('password')) ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="••••••••"
                autocomplete="current-password"
                maxlength="255"
                @if(isset($errors) && $errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif
                required
            >
            <button type="button" class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-0.5 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container" @click="visible = !visible" :aria-label="visible ? 'Hide password' : 'Show password'">
                <x-ui.icon name="eye-off" x-show="visible" />
                <x-ui.icon name="eye" x-show="!visible" />
            </button>
        </div>
        @if(isset($errors) && $errors->has('password'))<p id="password-error" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $errors->first('password') }}</p>@endif
    </div>

    <label class="tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-cursor-pointer" for="remember">
        <input type="checkbox" name="remember" value="1" class="form-check-input tw-mt-0" id="remember" {{ old('remember') ? 'checked' : '' }}>
        <span class="tw-font-medium tw-text-on-surface">Remember this device</span>
    </label>

    @if (isset($turnstileRequired) && $turnstileRequired && isset($turnstileSiteKey) && $turnstileSiteKey)
        <div>
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
            @error('cf-turnstile-response')<p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endif

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Sign In
    </button>
</form>
@endsection

@section('scripts')
@if (isset($turnstileRequired) && $turnstileRequired && isset($turnstileSiteKey) && $turnstileSiteKey)
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
@endsection
