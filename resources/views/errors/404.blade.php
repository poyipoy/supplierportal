@extends('layouts.auth')

@section('title', '404 - Halaman Tidak Ditemukan | ADASI Supplier Portal')
@section('meta')<meta name="robots" content="noindex, nofollow">@endsection

@section('content')
<div aria-labelledby="error-title" class="tw-text-left">
    <header class="tw-mb-5">
        <div class="tw-flex tw-items-center tw-gap-2">
            <span class="ui-status-chip ui-status-chip--error">Error 404</span>
            <span class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Not Found</span>
        </div>
        <h1 id="error-title" class="tw-m-0 tw-mt-2 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">
            Halaman Tidak Ditemukan
        </h1>
        <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">
            Alamat yang Anda tuju tidak ditemukan, telah dipindahkan, atau tautan yang Anda gunakan tidak valid.
        </p>
    </header>

    <div class="tw-mb-5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-text-ui-xs tw-text-on-surface-variant">
        <div class="tw-flex tw-items-center tw-gap-2.5">
            <x-ui.icon name="help-circle" size="sm" class="tw-flex-shrink-0 tw-text-primary" />
            <span>Pastikan URL yang dimasukkan sudah benar atau kembali ke halaman utama portal.</span>
        </div>
    </div>

    <div class="tw-grid tw-grid-cols-1 tw-gap-3 sm:tw-grid-cols-2">
        <button type="button" onclick="window.history.length > 1 ? window.history.back() : window.location.assign('{{ url('/') }}')"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">
            <x-ui.icon name="arrow-left" size="sm" />
            <span>Kembali</span>
        </button>

        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
            class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95">
            <x-ui.icon name="{{ auth()->check() ? 'layout-dashboard' : 'log-in' }}" size="sm" />
            <span>{{ auth()->check() ? 'Dashboard' : 'Halaman Masuk' }}</span>
        </a>
    </div>

    <p class="tw-m-0 tw-mt-4 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
        Jika membutuhkan bantuan, hubungi tim dukungan pengadaan PT Astra Daido Steel Indonesia.
    </p>
</div>
@endsection
