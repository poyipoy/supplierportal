@extends('layouts.auth')

@section('title', 'Registration Status Access - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Supplier Onboarding</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Registration Status Access</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Enter your Registration Reference and Access Key to track review progress or submit revisions.</p>
</header>

@if (session('error'))
    <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3 tw-text-on-error-container tw-mb-4 tw-flex tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium" role="alert">
        <x-ui.icon name="alert-circle" size="sm" class="tw-shrink-0" />
        <span>{{ session('error') }}</span>
    </div>
@endif

@if (session('info'))
    <div class="tw-rounded-ui-sm tw-bg-info-container tw-p-3 tw-text-on-info-container tw-mb-4 tw-flex tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium" role="alert">
        <x-ui.icon name="info" size="sm" class="tw-shrink-0" />
        <span>{{ session('info') }}</span>
    </div>
@endif

<form method="POST" action="{{ route('supplier.registration.access') }}" class="tw-grid tw-gap-4">
    @csrf

    <div class="tw-grid tw-gap-1.5">
        <label for="reference" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Registration Reference</label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="hash" size="sm" />
            </div>
            <input
                id="reference"
                type="text"
                name="reference"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-font-mono tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('reference') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="e.g. REG-2026-AB12CD34"
                value="{{ old('reference') }}"
                required
                autofocus
            >
        </div>
        @error('reference')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
    </div>

    <div class="tw-grid tw-gap-1.5" x-data="{ showKey: false }">
        <label for="access_key" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Registration Access Key</label>
        <div class="tw-relative">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="key" size="sm" />
            </div>
            <input
                id="access_key"
                :type="showKey ? 'text' : 'password'"
                name="access_key"
                class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-font-mono tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('access_key') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                placeholder="Enter 32-character key"
                required
            >
            <button
                type="button"
                class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                @click="showKey = !showKey"
                tabindex="-1"
                :aria-label="showKey ? 'Hide access key' : 'Show access key'"
            >
                <x-ui.icon name="eye" size="sm" x-show="!showKey" />
                <x-ui.icon name="eye-off" size="sm" x-show="showKey" />
            </button>
        </div>
        @error('access_key')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        <x-ui.icon name="arrow-right" size="sm" />
        <span>View Registration Status</span>
    </button>
</form>

<div class="tw-mt-4 tw-pt-4 tw-border-t tw-border-outline-variant tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
    <p class="tw-m-0">Need to register a new supplier? <a href="{{ route('supplier.register') }}" class="tw-font-semibold tw-text-primary hover:tw-underline">Start registration</a></p>
    <p class="tw-m-0 tw-mt-1.5"><a href="{{ route('login') }}" class="tw-text-on-surface-variant hover:tw-text-primary hover:tw-underline">Sign in with active portal account</a></p>
</div>
@endsection
