@extends('layouts.auth')

@section('title', 'Two-Factor Verification - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Sign-in verification</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Verify your identity</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter the 6-digit code from your authenticator app, or use a recovery code.</p>
</header>

<form method="POST" action="{{ route('two-factor.challenge') }}" class="tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="code" id="code" type="text" label="Authentication code" inputmode="text" autocomplete="one-time-code" maxlength="32" class="[&_input]:tw-text-center [&_input]:tw-font-mono [&_input]:tw-text-ui-lg [&_input]:tw-tracking-[0.25em]" required autofocus />

    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Verify
    </button>
</form>

<p class="tw-m-0 tw-mt-4 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">This challenge expires after 10 minutes.</p>
@endsection
