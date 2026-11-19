@extends('layouts.auth')

@section('plain-layout', true)
@section('title', '503 - '.__('common.review.service_unavailable_title').' | ADASI Supplier Portal')
@section('meta')<meta name="robots" content="noindex, nofollow">@endsection

@section('content')
<div aria-labelledby="error-title" class="tw:text-left">
    <header class="tw:mb-5">
        <div class="tw:flex tw:items-center tw:gap-2">
            <span class="ui-status-chip ui-status-chip--warning">{{ __('common.review.error_code', ['code' => 503]) }}</span>
            <span class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('common.review.service_unavailable') }}</span>
        </div>
        <h1 id="error-title" class="tw:m-0 tw:mt-2 tw:text-ui-xl tw:font-bold tw:tracking-tight tw:text-on-surface">
            {{ __('common.review.service_unavailable_title') }}
        </h1>
        <p class="tw:m-0 tw:mt-1.5 tw:text-ui-sm tw:text-on-surface-variant">
            {{ (isset($exception) && $exception->getMessage() !== '') ? $exception->getMessage() : __('common.review.service_unavailable_description') }}
        </p>
    </header>

    <div class="tw:mb-5 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3.5 tw:text-ui-xs tw:text-on-surface-variant">
        <div class="tw:flex tw:items-center tw:gap-2.5">
            <x-ui.icon name="wrench" size="sm" class="tw:shrink-0 tw:text-warning" />
            <span>{{ __('common.review.service_unavailable_help') }}</span>
        </div>
    </div>

    <div class="tw:grid tw:grid-cols-1 tw:gap-3 tw:sm:grid-cols-2">
        <button type="button" onclick="window.location.reload()"
            class="ui-focus-ring ui-motion tw:flex tw:h-11 tw:w-full tw:items-center tw:justify-center tw:gap-2 tw:rounded-ui-sm tw:border-0 tw:bg-primary tw:px-4 tw:text-ui-sm tw:font-semibold tw:text-primary-foreground tw:hover:brightness-95">
            <x-ui.icon name="refresh-cw" size="sm" />
            <span>{{ __('common.review.reload') }}</span>
        </button>

        <a href="{{ url('/') }}"
            class="ui-focus-ring ui-motion tw:flex tw:h-11 tw:w-full tw:items-center tw:justify-center tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-transparent tw:px-4 tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:no-underline tw:hover:bg-surface-container">
            <x-ui.icon name="home" size="sm" />
            <span>{{ __('navigation.dashboard') }}</span>
        </a>
    </div>

    <p class="tw:m-0 tw:mt-4 tw:text-center tw:text-ui-xs tw:text-on-surface-variant">
        {{ __('common.review.service_unavailable_support') }}
    </p>
</div>
@endsection
