@extends('layouts.auth')

@section('title', 'Forgot Password - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account recovery</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Forgot your password?</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter your email address. If an account exists, a secure password-reset link will be sent.</p>
</header>

<form method="POST" action="{{ route('password.email') }}" class="tw-grid tw-gap-4" novalidate>
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
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('email') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="name@company.com"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
            >
        </div>
        @error('email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        <x-ui.icon name="send" />
        Send Reset Link
    </button>
</form>

<div class="tw-mt-4 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">
        <x-ui.icon name="arrow-left" /> Back to Sign In
    </a>
</div>
@endsection
