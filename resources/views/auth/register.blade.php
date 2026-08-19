@extends('layouts.auth')

@section('title', 'Register - ADASI Supplier Portal')

@section('content')
<header>
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">New account</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Create your account</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Enter your identity and choose a strong password.</p>
</header>

<form method="POST" action="{{ route('register') }}" class="tw-mt-6 tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="name" id="name" label="Name" autocomplete="name" required autofocus />
    <x-ui.input name="email" id="email" type="email" label="Email" autocomplete="username" required />
    <x-ui.input name="password" id="password" type="password" label="Password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm Password" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.button type="submit" class="tw-w-full">Register</x-ui.button>
</form>

<div class="tw-mt-5 tw-text-center"><a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Already registered?</a></div>
@endsection
