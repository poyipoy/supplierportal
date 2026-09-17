@extends('layouts.auth')

@section('title', 'Sign In with Company SSO - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Supplier Portal</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Sign in with company SSO</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter your work email. We'll redirect you to your company's sign-in page if SSO is set up for your organization.</p>
</header>

<form method="POST" action="{{ route('sso.redirect') }}" class="tw-grid tw-gap-4">
    @csrf

    <div class="tw-grid tw-gap-1.5">
        <label for="email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Work email address</label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="mail" />
            </div>
            <input
                id="email"
                type="email"
                name="email"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ (isset($errors) && $errors->has('email')) ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="you@yourcompany.com"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
            >
        </div>
        @if(isset($errors) && $errors->has('email'))<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $errors->first('email') }}</p>@endif
    </div>

    <label class="tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-cursor-pointer" for="remember">
        <input type="checkbox" name="remember" value="1" class="form-check-input tw-mt-0" id="remember" {{ old('remember') ? 'checked' : '' }}>
        <span class="tw-font-medium tw-text-on-surface">Remember this device</span>
    </label>

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Continue
    </button>
</form>

<p class="tw-m-0 tw-mt-5 tw-text-center tw-text-ui-sm tw-text-on-surface-variant">
    Not using company SSO?
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Sign in with your portal password</a>
</p>
@endsection
