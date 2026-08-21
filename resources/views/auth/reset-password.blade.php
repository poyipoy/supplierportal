@extends('layouts.auth')

@section('title', 'Reset Password - ADASI Supplier Portal')

@section('content')
<header class="tw-text-center tw-mb-6">
    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI" class="tw-h-12 tw-w-auto tw-mx-auto tw-mb-3">
    <p class="tw-m-0 tw-text-ui-xs tw-font-bold tw-uppercase tw-tracking-[0.12em] tw-text-primary">Account security</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-2xl tw-font-bold tw-tracking-tight tw-text-on-surface">Create a new password</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol.</p>
</header>

<form method="POST" action="{{ route('password.store') }}" class="tw-grid tw-gap-4" novalidate>
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <x-ui.input name="email" id="email" type="email" label="Email" :value="$request->email" placeholder="name@email.com" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus />
    <x-ui.input name="password" id="password" type="password" label="New Password" placeholder="Create a strong password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm New Password" placeholder="Repeat your new password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.button type="submit" class="tw-w-full"><x-ui.icon name="shield-check" /> Reset Password</x-ui.button>
</form>

<div class="tw-mt-5 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">
        <x-ui.icon name="arrow-left" /> Back to Login
    </a>
</div>
@endsection
