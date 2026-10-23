{{-- Daftar "Import belum selesai" milik user (PO + GR berbagi slot). Diisi resources/js/local-procurement-import.js;
     template di bawah menjaga chip/tombol tetap memakai komponen design system. --}}
<section data-import-recovery hidden
    aria-labelledby="local{{ $importName }}ImportRecoveryTitle"
    data-slot-limit="{{ (int) config('local_procurement_imports.active_per_user') }}"
    class="tw:rounded-ui-md tw:bg-surface-container tw:px-3.5 tw:pt-3 tw:pb-1">
    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1">
        <h6 id="local{{ $importName }}ImportRecoveryTitle" class="tw:m-0 tw:text-balance tw:text-ui-sm tw:font-semibold tw:text-on-surface">
            {{ __('local_procurement.large_import.recovery_title') }}
        </h6>
        <span data-recovery-slots class="tw:text-ui-xs tw:text-on-surface-variant tw:tabular-nums"></span>
        <x-ui.button type="button" variant="ghost" size="sm" class="tw:ms-auto" data-recovery-refresh>
            <x-ui.icon name="refresh-cw" size="sm" />
            <span>{{ __('local_procurement.large_import.refresh') }}</span>
        </x-ui.button>
    </div>
    <p class="tw:mb-0 tw:mt-0.5 tw:text-pretty tw:text-ui-xs tw:text-on-surface-variant">
        {{ __('local_procurement.large_import.recovery_help') }}
    </p>
    <div data-recovery-error role="alert" hidden class="tw:mt-2 tw:text-ui-xs tw:font-medium tw:text-error"></div>
    {{-- Preflight Tailwind dimatikan, jadi border-style harus eksplisit agar garis pemisah tergambar. --}}
    <div data-recovery-list class="tw:mt-2">
        <section data-recovery-processing-group hidden aria-labelledby="local{{ $importName }}ImportProcessingGroup">
            <h3 id="local{{ $importName }}ImportProcessingGroup" class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:m-0 tw:pt-2">{{ __('local_procurement.large_import.processing_group') }}</h3>
            <ul data-recovery-processing-list role="list" class="tw:mb-0 tw:mt-1 tw:list-none tw:border-0 tw:border-t tw:border-solid tw:border-outline-variant tw:p-0"></ul>
        </section>
        <section data-recovery-follow-up-group hidden aria-labelledby="local{{ $importName }}ImportFollowUpGroup">
            <h3 id="local{{ $importName }}ImportFollowUpGroup" class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:m-0 tw:pt-2">{{ __('local_procurement.large_import.follow_up_group') }}</h3>
            <ul data-recovery-follow-up-list role="list" class="tw:mb-0 tw:mt-1 tw:list-none tw:border-0 tw:border-t tw:border-solid tw:border-outline-variant tw:p-0"></ul>
        </section>
    </div>
</section>
{{-- Live region harus sudah ada sebelum diisi, dan tetap ada walau panel disembunyikan. --}}
<div data-recovery-announcer class="visually-hidden" role="status" aria-live="polite"></div>

<template data-recovery-chip="ready"><x-ui.status-chip tone="success" icon="circle-check"><span data-chip-label></span></x-ui.status-chip></template>
<template data-recovery-chip="invalid"><x-ui.status-chip tone="warning" icon="triangle-alert"><span data-chip-label></span></x-ui.status-chip></template>
<template data-recovery-chip="queued"><x-ui.status-chip tone="neutral" icon="clock"><span data-chip-label></span></x-ui.status-chip></template>
<template data-recovery-chip="processing"><x-ui.status-chip tone="info" icon="loader-circle"><span data-chip-label></span></x-ui.status-chip></template>
<template data-recovery-chip="saving"><x-ui.status-chip tone="info" icon="database"><span data-chip-label></span></x-ui.status-chip></template>
<template data-recovery-action><x-ui.button type="button" variant="outline" size="sm"><span data-button-label></span></x-ui.button></template>
<template data-recovery-cancel><x-ui.button type="button" variant="ghost" size="sm" class="tw:text-error!"><x-ui.icon name="x" size="sm" /><span data-button-label></span></x-ui.button></template>
