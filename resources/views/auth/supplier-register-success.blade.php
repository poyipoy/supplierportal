@extends('layouts.auth')

@section('title', __('registration.received_title'))

@section('content')
<div class="tw:space-y-4">
    <h2 class="tw:m-0 tw:text-ui-xl tw:font-bold tw:text-on-surface">
        {{ __('registration.received_heading') }}
    </h2>
    <p class="tw:m-0 tw:text-ui-sm tw:text-on-surface-variant">
        {{ __('registration.received_help') }}
    </p>
    <p class="tw:m-0 tw:text-ui-sm tw:text-on-surface-variant">
        {{ __('registration.received_tracking') }}
    </p>
    <section id="registration_receipt" data-registration-receipt class="tw:min-w-0 tw:space-y-3 tw:rounded-ui-md tw:border tw:border-outline-variant tw:p-4">
        <p class="tw:text-ui-sm tw:font-semibold">{{ __('registration.important_key') }}</p>
        <div>
            <label for="registration_reference" class="tw:block tw:text-ui-sm tw:font-semibold">{{ __('registration.reference') }}</label>
            <input id="registration_reference" data-receipt-reference readonly class="form-control tw:font-mono" value="{{ $receipt['reference'] }}">
        </div>
        <div>
            <label for="registration_access_key" class="tw:block tw:text-ui-sm tw:font-semibold">{{ __('registration.key') }}</label>
            <input id="registration_access_key" data-receipt-key readonly class="form-control tw:font-mono" value="{{ $receipt['access_key'] }}">
        </div>
        <div class="tw:flex tw:flex-wrap tw:gap-2">
            <button type="button" class="btn btn-outline-primary" data-receipt-copy>{{ __('registration.receipt.copy_both') }}</button>
            <button type="button" class="btn btn-outline-secondary" data-receipt-download>{{ __('registration.receipt.download') }}</button>
        </div>
        <p data-receipt-message role="status" aria-live="polite" class="tw:text-ui-xs tw:m-0"></p>
        <span data-receipt-label-reference hidden>{{ __('registration.reference') }}</span>
        <span data-receipt-label-key hidden>{{ __('registration.key') }}</span>
        <span data-receipt-copied hidden>{{ __('registration.copied') }}</span>
        <span data-receipt-copy-failed hidden>{{ __('registration.receipt.copy_failed') }}</span>
    </section>
    <div class="tw:flex tw:flex-wrap tw:gap-3">
        <a href="{{ route('supplier.registration.access-form') }}" class="btn btn-primary">
            {{ __('registration.check_status') }}
        </a>
        <a href="{{ route('login') }}" class="btn btn-outline-secondary">
            {{ __('registration.return_login') }}
        </a>
    </div>
</div>
@endsection

@section('scripts')
@vite('resources/js/registration-receipt.js')
@endsection
