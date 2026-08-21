@extends('layouts.auth')

@section('title', 'Confirm Password - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Protected action</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Confirm your password</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">For your security, enter your current password before continuing.</p>
</header>

<form method="POST" action="{{ route('password.confirm') }}" class="tw-grid tw-gap-4" novalidate>
    @csrf

    <div x-data="{ visible: false }" class="tw-grid tw-gap-1.5">
        <label for="password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Current password</label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="lock" />
            </div>
            <input
                id="password"
                :type="visible ? 'text' : 'password'"
                name="password"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('password') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="Enter your password"
                autocomplete="current-password"
                maxlength="255"
                required
                autofocus
            >
            <button type="button" class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-0.5 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container" @click="visible = !visible" :aria-label="visible ? 'Hide password' : 'Show password'">
                <x-ui.icon name="eye-off" x-show="visible" />
                <x-ui.icon name="eye" x-show="!visible" />
            </button>
        </div>
        @error('password')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        Confirm and Continue
    </button>
</form>
@endsection
