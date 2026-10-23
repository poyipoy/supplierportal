@extends('layouts.auth')

@section('title', __('registration.status_title'))

@section('content')
<div class="tw:grid tw:gap-4">
    <div>
        <p class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-primary">{{ __('registration.status') }}</p>
        <h2 class="tw:m-0 tw:mt-1 tw:text-ui-xl tw:font-bold tw:text-on-surface">{{ $approved ? __('registration.approved') : __('registration.rejected') }}</h2>
        <p class="tw:m-0 tw:mt-2 tw:font-mono tw:text-ui-sm tw:text-on-surface-variant">{{ __('common.review.reference') }} {{ $reference }}</p>
        <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('registration.decided_on', ['date' => \App\Support\BusinessTime::format($decidedAt)]) }}</p>
    </div>

    @unless($approved)
        <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3">
            <h3 class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('registration.rejection_reason') }}</h3>
            <p class="tw:m-0 tw:mt-2 tw:whitespace-pre-line tw:text-ui-sm tw:text-on-surface-variant">{{ $rejectionReason ?: __('registration.rejected_fallback') }}</p>
        </div>
    @endunless

    <a href="{{ $approved ? route('login') : route('supplier.register') }}" class="ui-focus-ring tw:inline-flex tw:items-center tw:justify-center tw:gap-2 tw:rounded-ui-sm tw:bg-primary tw:px-4 tw:py-2.5 tw:text-ui-sm tw:font-semibold tw:text-primary-foreground tw:no-underline">
        <x-ui.icon :name="$approved ? 'log-in' : 'refresh-cw'" size="sm" />
        {{ $approved ? __('registration.proceed_login') : __('registration.register_again') }}
    </a>

    <form method="POST" action="{{ route('supplier.registration.logout') }}">
        @csrf
        <x-ui.button type="submit" variant="secondary" class="tw:w-full">{{ __('common.actions.exit') }}</x-ui.button>
    </form>
</div>
@endsection
