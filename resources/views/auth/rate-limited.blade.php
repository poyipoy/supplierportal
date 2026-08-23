@extends('layouts.auth')

@section('title', 'Please Wait - ADASI Supplier Portal')
@section('meta')<meta name="robots" content="noindex, nofollow">@endsection

@section('content')
@php
    $retryAfter = max(0, (int) ($retryAfter ?? 0));
    $waitLabel = $retryAfter > 60
        ? (int) ceil($retryAfter / 60).' '.((int) ceil($retryAfter / 60) === 1 ? 'minute' : 'minutes')
        : ($retryAfter > 0 ? $retryAfter.' '.($retryAfter === 1 ? 'second' : 'seconds') : 'a moment');
@endphp

<div aria-labelledby="rate-limit-title">
    <header class="tw-mb-5">
        <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-error">429 / Request limited</p>
        <h1 id="rate-limit-title" class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Please wait a moment</h1>
        <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">To protect your account, this action is temporarily limited due to too many requests.</p>
    </header>

    <x-ui.alert tone="warning" class="tw-mb-4">
        Please wait <strong id="retry-countdown" data-seconds="{{ $retryAfter }}">{{ $waitLabel }}</strong> before trying again.
    </x-ui.alert>

    <div class="tw-grid tw-grid-cols-1 tw-gap-3 md:tw-grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]">
        <button type="button" id="go-back" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">
            <x-ui.icon name="arrow-left" size="sm" /> <span class="tw-whitespace-nowrap">Go Back</span>
        </button>
        <a href="{{ $returnUrl }}" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-3 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95">
            <span class="tw-whitespace-nowrap">{{ $returnLabel }}</span>
        </a>
    </div>
    <p class="tw-m-0 tw-mt-4 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">If the issue continues, please contact your system administrator.</p>
</div>
@endsection

@section('scripts')
<script>
const countdown = document.getElementById('retry-countdown');
let seconds = Number(countdown.dataset.seconds || 0);
const formatWait = (value) => value > 60 ? `${Math.ceil(value / 60)} ${Math.ceil(value / 60) === 1 ? 'minute' : 'minutes'}` : value > 0 ? `${value} ${value === 1 ? 'second' : 'seconds'}` : 'a moment';
if (seconds > 0) {
    window.setInterval(() => {
        seconds = Math.max(0, seconds - 1);
        countdown.textContent = formatWait(seconds);
    }, 1000);
}
document.getElementById('go-back').addEventListener('click', () => {
    if (window.history.length > 1) { window.history.back(); return; }
    window.location.assign(@json($returnUrl));
});
</script>
@endsection
