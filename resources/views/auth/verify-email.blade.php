@extends('layouts.auth')

@section('title', 'Verify Email - ADASI Supplier Portal')

@section('content')
<header>
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account security</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Verify your email address</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Open the verification link sent to your email address. If it did not arrive, request another one below.</p>
</header>

@if (session('status') === 'verification-link-sent')
    <x-ui.alert tone="success" title="Verification link sent" class="tw-mt-5">A new verification link has been sent to your email address.</x-ui.alert>
@endif

<div class="tw-mt-6 tw-grid tw-gap-3">
    <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ui.button type="submit" class="tw-w-full">Resend Verification Email</x-ui.button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button type="submit" variant="ghost" class="tw-w-full">Log Out</x-ui.button></form>
</div>
@endsection
