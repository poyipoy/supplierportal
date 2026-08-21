@extends('layouts.auth')

@section('title', 'Register - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">New account</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Create your account</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter your identity and choose a strong password.</p>
</header>

<form method="POST" action="{{ route('register') }}" class="tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="name" id="name" label="Full name" autocomplete="name" required autofocus />
    <x-ui.input name="email" id="email" type="email" label="Email address" autocomplete="username" required />
    <x-ui.input name="password" id="password" type="password" label="Password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" minlength="12" maxlength="255" required />

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Register
    </button>
</form>

<div class="tw-mt-4 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Already registered?</a>
</div>
@endsection
