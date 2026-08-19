@extends('layouts.auth')

@section('title', 'Confirm Password - ADASI Supplier Portal')

@section('content')
<header>
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Protected action</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Confirm your password</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">For your security, enter your current password before continuing.</p>
</header>

<form method="POST" action="{{ route('password.confirm') }}" class="tw-mt-6 tw-grid tw-gap-4" novalidate>
    @csrf
    <x-ui.input name="password" id="password" type="password" label="Current Password" placeholder="Enter your password" autocomplete="current-password" maxlength="255" required autofocus />
    <x-ui.button type="submit" class="tw-w-full"><x-slot:leading><i class="bi bi-shield-lock"></i></x-slot:leading>Confirm and Continue</x-ui.button>
</form>
@endsection
