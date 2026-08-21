@extends('layouts.auth')

@section('title', 'Reset Password - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account recovery</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Create a new password</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol.</p>
</header>

<form method="POST" action="{{ route('password.store') }}" class="tw-grid tw-gap-4" novalidate>
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <x-ui.input name="email" id="email" type="email" label="Email address" :value="$request->email" placeholder="name@company.com" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus />
    <x-ui.input name="password" id="password" type="password" label="New password" placeholder="Create a strong password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm new password" placeholder="Repeat your new password" autocomplete="new-password" minlength="12" maxlength="255" required />

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        <x-ui.icon name="shield-check" /> Reset Password
    </button>
</form>

<div class="tw-mt-4 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">
        <x-ui.icon name="arrow-left" /> Back to Sign In
    </a>
</div>
@endsection
