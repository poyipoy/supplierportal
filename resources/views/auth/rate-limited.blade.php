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

<div class="tw-text-center" aria-labelledby="rate-limit-title">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-error">429 / Request Limited</p>
    <span class="tw-mt-4 tw-inline-flex tw-h-16 tw-w-16 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-primary-container tw-text-primary-container-foreground"><i class="bi bi-shield-exclamation tw-text-2xl" aria-hidden="true"></i></span>
    <h1 id="rate-limit-title" class="tw-m-0 tw-mt-4 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Please wait a moment</h1>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-leading-6 tw-text-on-surface-variant">To help protect your account, this action is temporarily limited because too many requests were made.</p>

    <x-ui.alert tone="warning" class="tw-mt-5 tw-text-start">
        Please wait <strong id="retry-countdown" data-seconds="{{ $retryAfter }}">{{ $waitLabel }}</strong> before trying again.
    </x-ui.alert>

    <div class="tw-mt-6 tw-grid tw-gap-3 sm:tw-grid-cols-2">
        <x-ui.button type="button" variant="ghost" id="go-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Go Back</x-ui.button>
        <x-ui.button :href="$returnUrl"><i class="bi bi-person-circle" aria-hidden="true"></i> {{ $returnLabel }}</x-ui.button>
    </div>
    <p class="tw-m-0 tw-mt-5 tw-text-ui-xs tw-text-on-surface-variant">If the issue continues, please contact your system administrator.</p>
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
