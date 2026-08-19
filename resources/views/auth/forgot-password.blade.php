@extends('layouts.auth')

@section('title', 'Forgot Password - ADASI Supplier Portal')

@section('content')
<header>
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Account security</p>
    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Forgot your password?</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Enter your email address. If an account is available, we will send a secure password-reset link.</p>
</header>

<form method="POST" action="{{ route('password.email') }}" class="tw-mt-6 tw-grid tw-gap-4" novalidate>
    @csrf
    <x-ui.input name="email" id="email" type="email" label="Email" placeholder="name@email.com" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus />
    <x-ui.button type="submit" class="tw-w-full"><x-slot:leading><i class="bi bi-envelope-arrow-up"></i></x-slot:leading>Send Reset Link</x-ui.button>
</form>

<div class="tw-mt-5 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Login</a>
</div>
@endsection
