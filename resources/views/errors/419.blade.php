@extends('layouts.auth')

@section('title', '419 - Sesi Kedaluwarsa | ADASI Supplier Portal')
@section('meta')<meta name="robots" content="noindex, nofollow">@endsection

@section('content')
<div aria-labelledby="error-title" class="tw-text-left">
    <header class="tw-mb-5">
        <div class="tw-flex tw-items-center tw-gap-2">
            <span class="ui-status-chip ui-status-chip--warning">Error 419</span>
            <span class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Page Expired</span>
        </div>
        <h1 id="error-title" class="tw-m-0 tw-mt-2 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">
            Sesi Telah Kedaluwarsa
        </h1>
        <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">
            Sesi keamanan formulir Anda telah berakhir karena tidak ada aktivitas dalam waktu tertentu.
        </p>
    </header>

    <div class="tw-mb-5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-text-ui-xs tw-text-on-surface-variant">
        <div class="tw-flex tw-items-center tw-gap-2.5">
            <x-ui.icon name="clock" size="sm" class="tw-flex-shrink-0 tw-text-warning" />
            <span>Untuk keamanan data, silakan muat ulang halaman ini atau masuk kembali ke akun Anda.</span>
        </div>
    </div>

    <div class="tw-grid tw-grid-cols-1 tw-gap-3 sm:tw-grid-cols-2">
        <button type="button" onclick="window.location.reload()"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">
            <x-ui.icon name="refresh-cw" size="sm" />
            <span>Muat Ulang</span>
        </button>

        <a href="{{ route('login') }}"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95">
            <x-ui.icon name="log-in" size="sm" />
            <span>Masuk Kembali</span>
        </a>
    </div>

    <p class="tw-m-0 tw-mt-4 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
        PT Astra Daido Steel Indonesia &copy; {{ now()->year }}
    </p>
</div>
@endsection
