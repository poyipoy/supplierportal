@extends('layouts.auth')

@section('title', 'Two-Factor Challenge - ADASI Supplier Portal')

@section('content')
<header class="tw-text-center">
    <span class="tw-inline-flex tw-h-14 tw-w-14 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-primary-container tw-text-primary-container-foreground"><i class="bi bi-shield-lock tw-text-2xl" aria-hidden="true"></i></span>
    <h2 class="tw-m-0 tw-mt-4 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Verify your sign-in</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Enter the 6-digit code from your authenticator app or one recovery code.</p>
</header>

<form method="POST" action="{{ route('two-factor.challenge') }}" class="tw-mt-6 tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="code" id="code" type="text" label="Authentication code" inputmode="text" autocomplete="one-time-code" maxlength="32" class="[&_input]:tw-text-center [&_input]:tw-font-mono [&_input]:tw-text-ui-lg [&_input]:tw-tracking-wider" required autofocus />
    <x-ui.button type="submit" class="tw-w-full"><x-slot:leading><i class="bi bi-shield-check"></i></x-slot:leading>Verify</x-ui.button>
</form>

<p class="tw-m-0 tw-mt-5 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">This challenge expires after 10 minutes.</p>
@endsection
