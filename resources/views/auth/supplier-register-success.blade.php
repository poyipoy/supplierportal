@extends('layouts.auth')

@section('title', 'Registration Submitted - ADASI Supplier Portal')

@section('content')
<style>
    .auth-form-surface { max-width: 32rem !important; }
</style>

<div class="tw-text-center tw-mb-4" x-data="{ copiedRef: false, copiedKey: false }">
    <div class="tw-inline-flex tw-items-center tw-justify-center tw-w-16 tw-h-16 tw-rounded-full tw-bg-success/15 tw-text-success tw-mb-3">
        <x-ui.icon name="check-circle" size="lg" />
    </div>

    <h2 class="tw-m-0 tw-text-ui-xl tw-font-bold tw-text-on-surface">Registration Submitted!</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">
        Thank you, <strong class="tw-text-on-surface">{{ $companyName }}</strong>. Your registration application has been received and queued for review.
    </p>

    {{-- STATUS CHIP --}}
    <div class="tw-mt-3">
        <span class="ui-status-chip ui-status-chip--warning">
            <x-ui.icon name="clock" size="xs" />
            Status: PENDING REVIEW
        </span>
    </div>

    {{-- CREDENTIALS CARD --}}
    <div class="tw-mt-5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4 tw-text-start">
        <div class="tw-flex tw-items-start tw-gap-2.5 tw-text-warning-dim tw-bg-warning/10 tw-p-3 tw-rounded-ui-xs tw-mb-4">
            <x-ui.icon name="alert-triangle" size="sm" class="tw-shrink-0 tw-mt-0.5" />
            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface">
                <strong>IMPORTANT:</strong> Save your Access Key now! For security reasons, the Access Key is <strong>only shown once</strong> and cannot be retrieved later. You need both the Reference and Access Key to track review status and perform revisions.
            </p>
        </div>

        {{-- REGISTRATION REFERENCE --}}
        <div class="tw-mb-4">
            <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-1">
                Registration Reference
            </label>
            <div class="tw-flex tw-items-center tw-justify-between tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-xs tw-p-2.5">
                <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-primary">{{ $reference }}</span>
                <button
                    type="button"
                    class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-border-0 tw-bg-transparent"
                    @click="navigator.clipboard.writeText('{{ $reference }}'); copiedRef = true; setTimeout(() => copiedRef = false, 2000)"
                >
                    <x-ui.icon name="copy" size="xs" x-show="!copiedRef" />
                    <x-ui.icon name="check" size="xs" x-show="copiedRef" />
                    <span x-text="copiedRef ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>
        </div>

        {{-- REGISTRATION ACCESS KEY --}}
        <div>
            <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-1">
                Registration Access Key (Private Secret)
            </label>
            <div class="tw-flex tw-items-center tw-justify-between tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-xs tw-p-2.5">
                <span class="tw-font-mono tw-text-ui-xs tw-text-on-surface tw-break-all">{{ $accessKey }}</span>
                <button
                    type="button"
                    class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-border-0 tw-bg-transparent tw-ml-2 tw-shrink-0"
                    @click="navigator.clipboard.writeText('{{ $accessKey }}'); copiedKey = true; setTimeout(() => copiedKey = false, 2000)"
                >
                    <x-ui.icon name="copy" size="xs" x-show="!copiedKey" />
                    <x-ui.icon name="check" size="xs" x-show="copiedKey" />
                    <span x-text="copiedKey ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ACTIONS --}}
    <div class="tw-mt-5 tw-grid tw-gap-2.5">
        <a
            href="{{ route('supplier.registration.access-form') }}"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95"
        >
            <x-ui.icon name="search" size="sm" />
            <span>Check Registration Status</span>
        </a>

        <a
            href="{{ route('login') }}"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-no-underline hover:tw-bg-surface-container"
        >
            <span>Return to Portal Login</span>
        </a>
    </div>
</div>
@endsection
