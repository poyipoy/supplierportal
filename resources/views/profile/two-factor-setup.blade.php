@extends('layouts.auth')

@section('title', 'Set Up Two-Factor Authentication - ADASI Supplier Portal')

@section('content')
<header class="tw-text-center">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account security</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Set up two-factor authentication</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Scan this QR code with an authenticator app, then enter the generated 6-digit code.</p>
    <img src="{{ $qrCode }}" alt="Two-factor authentication QR code" class="tw-mx-auto tw-mt-5 tw-w-full tw-max-w-[220px] tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-white tw-p-2">
</header>

<div class="tw-mt-5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4">
    <span class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Manual setup key</span>
    <code class="tw-mt-1 tw-block tw-break-all tw-select-all tw-text-primary">{{ $secret }}</code>
</div>

<form method="POST" action="{{ route('profile.two-factor.confirm') }}" class="tw-mt-5 tw-grid tw-gap-3">
    @csrf
    <x-ui.input name="code" id="code" type="text" label="Authentication code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" class="[&_input]:tw-text-center [&_input]:tw-font-mono [&_input]:tw-tracking-wider" required autofocus />
    <x-ui.button type="submit" class="tw-w-full">Enable Two-Factor Authentication</x-ui.button>
    <x-ui.button :href="route('profile.edit')" variant="ghost" class="tw-w-full">Cancel</x-ui.button>
</form>
@endsection
