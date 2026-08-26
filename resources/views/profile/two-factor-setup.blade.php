@extends('layouts.auth')

@section('title', 'Set Up Two-Factor Authentication - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account security</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Set up two-factor authentication</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Scan this QR code with an authenticator app, then enter the generated 6-digit code.</p>
</header>

<div class="tw-flex tw-justify-center tw-mb-4">
    <img src="{{ $qrCode }}" alt="Two-factor authentication QR code" class="tw-w-full tw-max-w-[200px] tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-2">
</div>

<div class="tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-p-3 tw-mb-5">
    <span class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Manual setup key</span>
    <code class="tw-mt-1 tw-block tw-break-all tw-select-all tw-text-ui-sm tw-text-primary">{{ $secret }}</code>
</div>

<form method="POST" action="{{ route('profile.two-factor.confirm') }}" class="tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="code" id="code" type="text" label="Authentication code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" class="[&_input]:tw-text-center [&_input]:tw-font-mono [&_input]:tw-tracking-[0.25em]" required autofocus />

    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Enable Two-Factor Authentication
    </button>
    <a href="{{ route('profile.edit') }}" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-no-underline hover:tw-bg-surface-container">
        Cancel
    </a>
</form>
@endsection
