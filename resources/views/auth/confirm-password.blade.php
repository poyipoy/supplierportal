@extends('layouts.auth')

@section('title', 'Confirm Password - ADASI Supplier Portal')

@section('content')
<header class="tw-text-center tw-mb-6">
    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI" class="tw-h-12 tw-w-auto tw-mx-auto tw-mb-3">
    <p class="tw-m-0 tw-text-ui-xs tw-font-bold tw-uppercase tw-tracking-[0.12em] tw-text-primary">Protected action</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-2xl tw-font-bold tw-tracking-tight tw-text-on-surface">Confirm your password</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">For your security, enter your current password before continuing.</p>
</header>

<form method="POST" action="{{ route('password.confirm') }}" class="tw-grid tw-gap-5" novalidate>
    @csrf

    <div x-data="{ visible: false }" class="tw-grid tw-gap-1.5">
        <label for="password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Current Password <span class="tw-text-error" aria-hidden="true">*</span></label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3.5 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="lock" />
            </div>
            <input
                id="password"
                :type="visible ? 'text' : 'password'"
                name="password"
                class="ui-motion tw-h-12 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-12 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                placeholder="Enter your password"
                autocomplete="current-password"
                maxlength="255"
                required
                autofocus
            >
            <button type="button" class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-10 tw-w-10 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container" @click="visible = !visible" :aria-label="visible ? 'Hide password' : 'Show password'">
                <x-ui.icon name="eye-off" x-show="visible" />
                <x-ui.icon name="eye" x-show="!visible" />
            </button>
        </div>
        @error('password')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-12 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-white hover:tw-bg-primary-600 active:tw-bg-primary-700">
        <x-ui.icon name="shield-lock" />
        Confirm and Continue
    </button>
</form>
@endsection
