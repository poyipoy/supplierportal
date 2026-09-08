@extends('layouts.app')

@section('title', 'Quotation Price Form - ADASI Portal')
@section('page-title', 'Create Quotation')

@push('styles')
<style>
    .quotation-page,
    .quotation-form,
    .quotation-entry-card,
    .quotation-table-shell {
        max-width: 100%;
        min-width: 0;
    }

    .quotation-table-scroll {
        display: block;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        overscroll-behavior-inline: contain;
        position: relative;
        scrollbar-width: thin;
        width: 100%;
        -webkit-overflow-scrolling: touch;
    }

    .quotation-table-scroll:focus-visible {
        outline: 2px solid var(--md-primary);
        outline-offset: -2px;
    }

    .quotation-items-table {
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: var(--ui-font-size-xs);
        max-width: none !important;
        min-width: 2180px !important;
        table-layout: fixed !important;
        width: 2180px !important;
    }

    .quotation-items-table th,
    .quotation-items-table td {
        background-color: var(--md-surface);
        border-color: var(--md-outline-variant) !important;
        padding: .38rem .4rem;
        vertical-align: middle !important;
    }

    .quotation-col-number { width: 48px; }
    .quotation-col-material { width: 230px; }
    .quotation-col-row-type { width: 88px; }
    .quotation-col-qty { width: 120px; }
    .quotation-col-dimension { width: 110px; }
    .quotation-col-length { width: 135px; }
    .quotation-col-weight { width: 145px; }
    .quotation-col-total-weight { width: 130px; }
    .quotation-col-commercial { width: 145px; }
    .quotation-col-amount { width: 160px; }
    .quotation-col-notes { width: 210px; }
    .quotation-col-mtc { width: 210px; }
    .quotation-col-availability { width: 160px; }

    .quotation-items-table .quotation-field-header th {
        background: var(--md-surface-container-low) !important;
        color: var(--md-on-surface-variant) !important;
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        height: 42px;
        position: sticky;
        text-align: center;
        top: 0;
        vertical-align: middle;
        z-index: 9;
    }

    .quotation-items-table .quotation-sticky-number,
    .quotation-items-table .quotation-sticky-material,
    .quotation-items-table .quotation-sticky-row-type {
        position: sticky;
    }

    .quotation-items-table .quotation-sticky-number {
        left: 0;
        min-width: 48px;
        width: 48px;
        z-index: 7;
    }

    .quotation-items-table .quotation-sticky-material {
        border-right: 1px solid var(--md-outline) !important;
        left: 48px;
        min-width: 230px;
        width: 230px;
        z-index: 7;
    }

    .quotation-items-table .quotation-sticky-row-type {
        border-right: 1px solid var(--md-outline) !important;
        left: 278px;
        min-width: 88px;
        width: 88px;
        z-index: 6;
    }

    .quotation-items-table thead .quotation-sticky-number,
    .quotation-items-table thead .quotation-sticky-material,
    .quotation-items-table thead .quotation-sticky-row-type {
        background: var(--md-surface-container-low) !important;
        z-index: 12;
    }

    .quotation-items-table .quotation-requested-row > * {
        border-top: 2px solid var(--md-outline) !important;
    }

    .quotation-items-table .quotation-requested-row > td,
    .quotation-items-table .quotation-requested-row > .quotation-sticky-row-type {
        background-color: var(--md-surface-container-low) !important;
    }

    .quotation-items-table .quotation-offer-row > td,
    .quotation-items-table .quotation-offer-row > .quotation-sticky-row-type {
        background-color: var(--md-surface) !important;
    }

    .quotation-items-table .quotation-offer-row.is-unavailable > td,
    .quotation-items-table .quotation-offer-row.is-unavailable > .quotation-sticky-row-type {
        background-color: var(--md-surface-container-low) !important;
    }

    /* Keep the paired rows visually balanced; validation feedback can still grow a row. */
    .quotation-items-table .quotation-requested-row > td:not([rowspan]),
    .quotation-items-table .quotation-offer-row > td {
        height: 3.75rem;
    }

    .quotation-items-table .quotation-requested-row > td {
        vertical-align: middle !important;
    }

    .quotation-items-table .quotation-offer-row > td {
        vertical-align: top !important;
        padding-top: .5rem !important;
    }

    .quotation-items-table .quotation-offer-row .quotation-sticky-row-type {
        vertical-align: top !important;
        padding-top: .5rem !important;
    }

    .quotation-items-table .quotation-offer-row .quotation-row-label--offer {
        display: inline-flex;
        align-items: center;
        height: 34px;
        min-height: 34px;
    }

    .quotation-items-table .quotation-offer-row .offer-total-weight-display {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        height: 34px;
        min-height: 34px;
        width: 100%;
    }

    .quotation-items-table .quotation-offer-row .offer-amount-display {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        height: 34px;
        min-height: 34px;
        width: 100%;
    }

    .quotation-items-table .quotation-editable {
        background: var(--md-surface);
        border-color: var(--md-outline);
        height: 34px;
        min-height: 34px;
        box-sizing: border-box;
    }

    /* Hide native number input spinners in table to prevent crowding & clipping */
    .quotation-items-table input[type="number"]::-webkit-outer-spin-button,
    .quotation-items-table input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        margin: 0 !important;
    }
    .quotation-items-table input[type="number"] {
        -moz-appearance: textfield !important;
    }

    /* Reset intrusive Bootstrap validation icon and padding-right in compact table cells */
    .quotation-items-table .form-control.is-invalid {
        background-image: none !important;
        padding-right: .5rem !important;
        padding-left: .5rem !important;
        border-color: var(--md-error, #dc3545) !important;
    }

    .quotation-items-table .invalid-feedback {
        font-size: .6875rem;
        line-height: 1.2;
        margin-top: .25rem;
        text-align: left;
    }

    .quotation-items-table .quotation-editable:focus {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 var(--ui-focus-ring-width) rgba(var(--md-primary-rgb), .15);
    }

    .quotation-items-table .quotation-editable:disabled {
        background: var(--md-surface-container-low);
        color: var(--md-on-surface-variant);
        cursor: not-allowed;
        opacity: .75;
    }

    .quotation-items-table .quotation-calculated {
        color: var(--md-on-surface);
        font-weight: 600;
    }

    .quotation-item-notes {
        display: none !important;
    }

    .quotation-notes-cell {
        position: relative;
    }

    .quotation-notes-trigger {
        align-items: center;
        background: var(--md-surface);
        border: 1px solid var(--md-outline);
        border-radius: var(--md-shape-sm, 8px);
        color: var(--md-on-surface);
        cursor: pointer;
        display: flex;
        font-size: var(--ui-font-size-xs, 12px);
        gap: .375rem;
        height: 34px;
        min-height: 34px;
        box-sizing: border-box;
        padding: .25rem .5rem;
        position: relative;
        text-align: left;
        transition: border-color var(--ui-motion-standard), background var(--ui-motion-standard), box-shadow var(--ui-motion-standard);
        width: 100%;
    }

    .quotation-notes-trigger:hover {
        background: var(--md-surface-container);
        border-color: var(--md-primary);
    }

    .quotation-notes-trigger.has-notes {
        background: var(--md-surface);
        border-color: var(--md-primary);
        font-weight: 500;
    }

    .quotation-notes-trigger.has-notes .quotation-notes-trigger__icon {
        color: var(--md-primary);
    }

    .quotation-notes-trigger__icon {
        color: var(--md-on-surface-variant);
        flex-shrink: 0;
    }

    .quotation-notes-trigger__text {
        flex: 1 1 auto;
        min-width: 0;
    }

    .quotation-notes-trigger:not(.has-notes) .quotation-notes-trigger__text {
        color: var(--md-on-surface-variant);
    }

    .quotation-notes-trigger__badge {
        background: var(--md-primary);
        border-radius: 50%;
        flex-shrink: 0;
        height: 6px;
        width: 6px;
    }

    .quotation-notes-popover {
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: 12px;
        box-shadow: 0 4px 24px -2px rgba(20, 24, 43, 0.16), 0 12px 32px -4px rgba(20, 24, 43, 0.12);
        min-width: 280px;
        max-width: 320px;
        padding: .75rem;
        position: fixed;
        width: 320px;
        z-index: 1080;
    }

    .quotation-notes-popover[hidden] {
        display: none !important;
    }

    .quotation-notes-popover__header {
        align-items: flex-start;
        display: flex;
        justify-content: space-between;
        margin-bottom: .5rem;
    }

    .quotation-notes-popover__title {
        color: var(--md-on-surface);
        display: block;
        font-size: var(--ui-font-size-xs, 12px);
        font-weight: 700;
    }

    .quotation-notes-popover__subtitle {
        color: var(--md-on-surface-variant);
        display: block;
        font-size: .6875rem;
        max-width: 220px;
    }

    .quotation-notes-popover__close {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 6px;
        color: var(--md-on-surface-variant);
        cursor: pointer;
        display: inline-flex;
        height: 22px;
        justify-content: center;
        padding: 0;
        width: 22px;
        transition: background var(--ui-motion-standard), color var(--ui-motion-standard);
    }

    .quotation-notes-popover__close:hover {
        background: var(--md-surface-container);
        color: var(--md-on-surface);
    }

    .quotation-notes-draft {
        font-size: var(--ui-font-size-xs, 12px);
        line-height: 1.35;
        resize: vertical;
        min-height: 70px;
        max-height: 140px;
    }

    .quotation-notes-popover__hint {
        color: var(--md-on-surface-variant);
        font-size: .6875rem;
        margin-top: .25rem;
    }

    .quotation-notes-popover__footer {
        align-items: center;
        display: flex;
        gap: .375rem;
        justify-content: flex-end;
        margin-top: .5rem;
    }

    .quotation-notes-btn-cancel,
    .quotation-notes-btn-save {
        font-size: .75rem;
        padding: .2rem .5rem;
    }

    .quotation-offer-row.is-unavailable .quotation-notes-trigger {
        background: var(--md-surface-container-low);
        cursor: not-allowed;
        opacity: .6;
    }

    .quotation-row-label {
        color: var(--md-on-surface-variant);
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        letter-spacing: .025em;
        text-transform: uppercase;
    }

    .quotation-row-label--offer {
        color: var(--md-primary);
    }

    .quotation-value-secondary {
        color: var(--md-on-surface-variant);
        font-size: var(--ui-font-size-xs);
        line-height: 1.2;
        margin-top: .2rem;
    }

    .offer-length-input + .quotation-value-secondary {
        font-size: .625rem;
        line-height: 1;
        margin-top: .1rem;
        white-space: nowrap;
    }

    .offer-weight-indicator {
        align-self: flex-end;
        font-size: .625rem;
        line-height: 1;
        padding: .15rem .45rem;
        margin: 0;
        white-space: nowrap;
    }

    .offer-weight-control {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        gap: .15rem;
    }

    .offer-weight-control .offered-weight-input {
        width: 100%;
        height: 34px;
        min-height: 34px;
        box-sizing: border-box;
    }

    .mtc-upload-container {
        position: relative;
        width: 100%;
    }

    /* MTC State 1: Empty state (Upload MTC Button) */
    .mtc-empty-state {
        display: flex;
        flex-direction: column;
        gap: 2px;
        width: 100%;
    }

    .mtc-empty-state .mtc-file-label {
        align-items: center;
        background: var(--md-surface);
        border: 1px dashed var(--md-outline);
        border-radius: var(--md-shape-sm, 8px);
        color: var(--md-primary);
        cursor: pointer;
        display: flex;
        font-size: .75rem;
        font-weight: 600;
        justify-content: center;
        height: 34px;
        min-height: 34px;
        box-sizing: border-box;
        padding: .25rem .5rem;
        text-align: center;
        transition: background var(--ui-motion-standard), border-color var(--ui-motion-standard), color var(--ui-motion-standard);
        width: 100%;
    }

    .mtc-empty-state .mtc-file-label:hover {
        background: var(--md-primary-container);
        border-color: var(--md-primary);
        color: var(--md-on-primary-container);
    }

    .mtc-file-hint {
        color: var(--md-on-surface-variant);
        font-size: .625rem;
        letter-spacing: .01em;
        text-align: center;
    }

    /* MTC State 2: File Card / Pill */
    .mtc-file-card {
        align-items: center;
        background: var(--md-surface-container-low);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm, 8px);
        display: flex;
        gap: .375rem;
        justify-content: space-between;
        height: 34px;
        min-height: 34px;
        box-sizing: border-box;
        padding: .25rem .5rem;
        width: 100%;
    }

    .mtc-file-card__main {
        align-items: center;
        display: flex;
        flex: 1 1 auto;
        gap: .375rem;
        min-width: 0;
    }

    .mtc-file-card__icon {
        color: var(--md-primary);
        flex-shrink: 0;
    }

    .mtc-file-card .mtc-file-name {
        color: var(--md-on-surface);
        flex: 1 1 auto;
        font-size: .75rem;
        font-weight: 500;
        margin: 0;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .mtc-file-card__actions {
        align-items: center;
        display: flex;
        flex-shrink: 0;
        gap: 2px;
    }

    .mtc-file-action-link,
    .mtc-file-action-btn {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--md-on-surface-variant);
        cursor: pointer;
        display: inline-flex;
        font-size: .6875rem;
        font-weight: 500;
        height: 24px;
        justify-content: center;
        margin: 0;
        padding: 0 4px;
        text-decoration: none;
        transition: background var(--ui-motion-standard), color var(--ui-motion-standard);
    }

    .mtc-file-action-link {
        color: var(--md-primary);
        gap: 2px;
    }

    .mtc-file-action-link:hover,
    .mtc-file-action-btn:hover {
        background: var(--md-surface-container);
        color: var(--md-on-surface);
    }

    .mtc-file-action-btn.text-danger:hover {
        background: var(--md-error-container);
        color: var(--md-error) !important;
    }

    .quotation-offer-row.is-unavailable .mtc-empty-state .mtc-file-label,
    .quotation-offer-row.is-unavailable .mtc-file-card {
        cursor: not-allowed;
        opacity: .55;
        pointer-events: none;
    }

    .availability-control {
        align-items: center;
        display: flex;
        justify-content: center;
        width: 100%;
        min-height: 34px;
    }

    .availability-toggle {
        align-items: center;
        border: 1px solid transparent;
        border-radius: var(--md-shape-full, 9999px);
        cursor: pointer;
        display: inline-flex;
        font-size: var(--ui-font-size-xs, 12px);
        font-weight: 600;
        gap: .375rem;
        justify-content: center;
        line-height: 1.2;
        min-height: 34px;
        height: 34px;
        box-sizing: border-box;
        padding: .25rem .75rem;
        text-align: center;
        transition: background-color var(--ui-motion-standard), border-color var(--ui-motion-standard), color var(--ui-motion-standard), transform var(--ui-motion-standard);
        user-select: none;
        white-space: nowrap;
    }

    .availability-toggle:hover {
        transform: translateY(-1px);
    }

    .availability-toggle.is-available {
        background: var(--md-success-container);
        border-color: var(--md-success);
        color: var(--md-on-success-container);
    }

    .availability-toggle.is-available:hover {
        background: var(--md-surface-container);
        border-color: var(--md-success);
    }

    .availability-toggle.is-unavailable {
        background: var(--md-error-container);
        border-color: var(--md-error);
        color: var(--md-on-error-container);
    }

    .availability-toggle.is-unavailable:hover {
        background: var(--md-surface-container);
        border-color: var(--md-error);
    }

    .availability-toggle__icon {
        align-items: center;
        display: inline-flex;
        justify-content: center;
    }

    .availability-toggle__icon .ui-icon {
        height: 14px;
        width: 14px;
    }

    .availability-toggle .item-availability-label {
        white-space: nowrap;
    }

    .availability-copied .quotation-offer-row > td {
        background-color: var(--md-success-container) !important;
        transition: background-color 0.2s ease;
    }
</style>
@endpush

@section('content')
<div class="quotation-page tw-grid tw-min-w-0 tw-max-w-full tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Quotation Periods' => route('supplier.quotations.index'),
        ($pr->period->display_label ?? 'Requisitions') => route('supplier.quotations.period', $pr->period_id),
        ($quotation?->status === 'revision_requested' ? 'Revise Quotation' : 'Submit Quotation') => null,
    ]" />

    <x-ui.page-header
        :title="$quotation?->status === 'revision_requested' ? 'Revise Quotation' : 'Create Quotation'"
        eyebrow="Supplier Proposal Entry"
        :description="'Provide pricing, availability parameters, and technical MTC files for ' . ($pr->pr_number ?? 'this requisition') . '.'"
    >
        <x-slot:meta>
            @if($quotation?->status === 'revision_requested')
                <span class="ui-status-chip ui-status-chip--warning">
                    <x-ui.icon name="rotate-ccw" size="sm" class="me-1" />Revision Requested
                </span>
            @else
                <span class="ui-status-chip ui-status-chip--neutral">
                    <x-ui.icon name="square-pen" size="sm" class="me-1" />New Offer
                </span>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('supplier.quotations.period', $pr->period_id)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Back to Requisitions</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Main Sectioned Form --}}
    <form id="quotationForm" class="quotation-form" action="{{ route('supplier.quotations.store', $pr) }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="action" id="formAction" value="draft">

        <div class="tw-grid tw-gap-5">
            {{-- Section 1: Procurement Context --}}
            <x-ui.form-section
                title="Procurement Context"
                description="General requisition parameters and notes established by ADASI Purchasing."
            >
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Procurement Period</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $pr->period->display_label }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Requisition Reference</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $pr->pr_number ?? 'PR -' }}</div>
                    </div>
                    <div class="sm:tw-col-span-2 lg:tw-col-span-1 tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Purchasing Instructions</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $pr->notes ?: 'No additional notes provided.' }}</div>
                    </div>
                </div>

                @if($quotation?->status === 'revision_requested')
                    <x-ui.alert tone="warning" title="Quotation Revision Requested" class="tw-mt-3">
                        Purchasing asked this quotation to be resubmitted. Update unit prices, delivery schedule, validity date, or specifications before submitting again.
                    </x-ui.alert>
                @endif
            </x-ui.form-section>

            {{-- Section 2: Material Pricing & Availability Matrix --}}
            <x-ui.form-section
                class="quotation-material-section"
                title="Material Commercial Offer"
                description="Specify your availability, dimensions, price per kg, and attach MTC certificates."
            >
                <x-slot:actions>
                    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                        {{-- Autosave Indicator --}}
                        <span id="autoSaveBadge" class="ui-status-chip ui-status-chip--success d-none">
                            <x-ui.icon name="check" size="sm" class="me-1" />Draft Saved
                        </span>

                        {{-- Import Controls --}}
                        @include('supplier.quotations._import_controls')

                        {{-- Copy All Requested --}}
                        <x-ui.button type="button" id="copyAllRequested" variant="outline" size="sm">
                            <x-ui.icon name="clipboard-check" size="sm" />
                            <span>Copy All Requested Values</span>
                        </x-ui.button>

                        {{-- Currency Select --}}
                        <div class="d-flex align-items-center tw-gap-1.5 ps-2 border-start">
                            <label for="quotationCurrency" class="small fw-semibold tw-text-on-surface mb-0">Currency:</label>
                            <select name="currency" id="quotationCurrency" class="form-select form-select-sm fw-bold @error('currency') is-invalid @enderror" style="width: 100px;" required>
                                <option value="" disabled @selected($supplierCurrency === '')>Select</option>
                                @foreach($currencyOptions as $currency)
                                    <option value="{{ $currency }}" @selected(old('currency', $supplierCurrency) === $currency)>{{ $currency }}</option>
                                @endforeach
                            </select>
                            @error('currency')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </x-slot:actions>

                <x-ui.alert id="currencyRateWarning" tone="warning" title="Exchange Rate Required" class="tw-mb-2 {{ $supplierCurrency && ! $supplierRate ? '' : 'd-none' }}">
                    Exchange rate for <strong id="currencyWarningLabel">{{ $supplierCurrency ?: '-' }}</strong> is not recorded in master exchange rates. Contact Admin before final submission.
                </x-ui.alert>

                @php
                    $quotationDimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
                    $quotationScalarDimensionOrder = array_values(array_filter(
                        $quotationDimensionOrder,
                        static fn (string $field): bool => $field !== 'length',
                    ));
                @endphp

                {{-- Material Entry Table --}}
                <div class="border rounded overflow-hidden">
                    <div id="quotationTableScrollHint" class="tw-bg-surface-low border-bottom px-3 tw-py-1.5 tw-text-on-surface-variant tw-text-ui-xs d-flex align-items-center tw-gap-1.5">
                        <x-ui.icon name="move-horizontal" size="sm" />
                        <span>Scroll horizontally to compare Requested and Offer values for each material.</span>
                    </div>

                    <div class="table-responsive quotation-table-scroll" role="region" tabindex="0" aria-label="Material quotation price entry" aria-describedby="quotationTableScrollHint">
                        <table class="table table-bordered align-middle mb-0 quotation-items-table tw-text-ui-xs">
                            <caption class="visually-hidden">Supplier quotation entry table with paired Requested and Offer rows for each material</caption>
                            <colgroup>
                                <col class="quotation-col-number">
                                <col class="quotation-col-material">
                                <col class="quotation-col-row-type">
                                <col class="quotation-col-qty">
                                <col class="quotation-col-dimension">
                                <col class="quotation-col-dimension">
                                <col class="quotation-col-dimension">
                                <col class="quotation-col-dimension">
                                <col class="quotation-col-length">
                                <col class="quotation-col-weight">
                                <col class="quotation-col-total-weight">
                                <col class="quotation-col-commercial">
                                <col class="quotation-col-amount">
                                <col class="quotation-col-notes">
                                <col class="quotation-col-mtc">
                                <col class="quotation-col-availability">
                            </colgroup>
                            <thead class="table-light text-center">
                                <tr class="quotation-field-header">
                                    <th scope="col" class="quotation-sticky-number">No</th>
                                    <th scope="col" class="quotation-sticky-material">Material</th>
                                    <th scope="col" class="quotation-sticky-row-type">Row Type</th>
                                    <th scope="col">Qty</th>
                                    @foreach($quotationDimensionOrder as $dimensionField)
                                        <th scope="col">{{ \App\Models\PrItem::DIMENSION_LABELS[$dimensionField] }}</th>
                                    @endforeach
                                    <th scope="col">KG / Unit</th>
                                    <th scope="col">Total KG</th>
                                    <th scope="col">Price / KG (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                                    <th scope="col">Amount (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                                    <th scope="col">Notes</th>
                                    <th scope="col">MTC</th>
                                    <th scope="col">Availability</th>
                                </tr>
                            </thead>
                            @foreach($pr->items as $index => $item)
                                @php
                                    $qItem = $quotation?->items?->firstWhere('pr_item_id', $item->id);
                                    $mtcAttachment = $qItem?->attachments?->first();
                                    $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($item->shape);
                                    $storedLength = '';
                                    if ($qItem?->available_length_min !== null && $qItem?->available_length_max !== null) {
                                        $storedLength = \App\Models\PrItem::formatDimensionValue($qItem->available_length_min)
                                            .'-'.\App\Models\PrItem::formatDimensionValue($qItem->available_length_max);
                                    } elseif ($qItem?->available_length !== null) {
                                        $storedLength = \App\Models\PrItem::formatDimensionValue($qItem->available_length);
                                    }
                                    $availabilityInput = old("items.{$index}.is_available", $qItem?->is_available ?? true);
                                    $itemIsAvailable = \App\Models\QuotationItem::normalizeAvailabilityState($availabilityInput);
                                    $manualWeightOverride = old(
                                        "items.{$index}.offered_weight_manual_override",
                                        $qItem?->is_estimated_weight ? '1' : '0',
                                    );
                                @endphp
                                <tbody
                                    class="quotation-item-group"
                                    data-pr-item-id="{{ $item->id }}"
                                    data-material-name="{{ $item->material_name }}"
                                    data-shape="{{ $item->shape }}"
                                    data-density-profile="{{ $item->materialMaster?->density_profile ?? 'steel' }}"
                                    data-requested-qty="{{ $item->quantity_value }}"
                                    data-requested-weight="{{ $item->weight_needed }}"
                                    data-requested-total-weight="{{ $item->total_weight }}"
                                >
                                    <tr class="quotation-requested-row">
                                        <td rowspan="2" class="text-center quotation-sticky-number tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                        <td rowspan="2" class="quotation-sticky-material align-top">
                                            <div class="fw-bold tw-text-on-surface">{{ $item->material_name }}</div>
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                                {{ $item->hs_code ? 'HS '.$item->hs_code.' · ' : '' }}{{ $item->shape ?: '-' }}
                                            </div>
                                            @if($item->remark)
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-1">{{ $item->remark }}</div>
                                            @endif
                                            <x-ui.button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="copy-from-pr-btn tw-mt-2"
                                                data-requested-qty="{{ $item->quantity_value }}"
                                                data-requested-thickness="{{ $item->thickness !== null ? (float) $item->thickness : '' }}"
                                                data-requested-d-outer="{{ $item->d_outer !== null ? (float) $item->d_outer : '' }}"
                                                data-requested-d-inner="{{ $item->d_inner !== null ? (float) $item->d_inner : '' }}"
                                                data-requested-width="{{ $item->width !== null ? (float) $item->width : '' }}"
                                                data-requested-length="{{ $item->length !== null ? (float) $item->length : '' }}"
                                                data-requested-weight="{{ $item->weight_needed }}"
                                                title="Copy requested quantity, dimensions, and KG/unit into the Offer row"
                                            >
                                                <x-ui.icon name="clipboard-check" size="sm" /> Copy Requested
                                            </x-ui.button>
                                            <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                            <input type="hidden" name="items[{{ $index }}][is_available]" class="item-availability-input" value="{{ $itemIsAvailable ? '1' : '0' }}">
                                            @error("items.{$index}.pr_item_id")
                                                <div class="text-danger small tw-mt-1">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td class="quotation-sticky-row-type"><span class="quotation-row-label">Requested</span></td>
                                        <td class="text-center fw-semibold ui-tabular-nums">{{ $item->quantity_value }}</td>
                                        @foreach($quotationScalarDimensionOrder as $dimension)
                                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                                {{ in_array($dimension, $relevantDimensions, true) ? \App\Support\NumberFormat::maxDecimals($item->{$dimension}) : '—' }}
                                            </td>
                                        @endforeach
                                        <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                            {{ in_array('length', $relevantDimensions, true) ? \App\Support\NumberFormat::maxDecimals($item->length) : '—' }}
                                        </td>
                                        <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ \App\Support\NumberFormat::maxDecimals($item->weight_needed) }}</td>
                                        <td class="text-end fw-semibold ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->total_weight) }}</td>
                                        <td class="text-end ui-tabular-nums requested-price-display">—</td>
                                        <td class="text-end ui-tabular-nums">
                                            <span class="requested-amount-display quotation-calculated" aria-live="polite">—</span>
                                        </td>
                                        <td class="text-start tw-text-on-surface-variant">{{ $item->remark ?: '—' }}</td>
                                        <td class="text-center tw-text-outline">—</td>
                                        <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">Requested</span></td>
                                    </tr>
                                    <tr class="quotation-offer-row {{ $itemIsAvailable ? '' : 'is-unavailable' }}">
                                        <td class="quotation-sticky-row-type"><span class="quotation-row-label quotation-row-label--offer">Offer</span></td>
                                        <td>
                                            <input
                                                id="availableQty{{ $index }}"
                                                type="number"
                                                min="1"
                                                max="{{ $item->quantity_value }}"
                                                step="1"
                                                name="items[{{ $index }}][available_qty]"
                                                class="form-control form-control-sm availability-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.available_qty") is-invalid @enderror"
                                                data-availability-field="qty"
                                                value="{{ old("items.{$index}.available_qty", $qItem?->available_qty) }}"
                                                aria-label="Offer quantity for {{ $item->material_name }}; maximum {{ $item->quantity_value }}"
                                                aria-describedby="availableQtyFeedback{{ $index }}"
                                            >
                                            <div id="availableQtyFeedback{{ $index }}" class="invalid-feedback offer-qty-feedback">
                                                @error("items.{$index}.available_qty"){{ $message }}@enderror
                                            </div>
                                        </td>
                                        @foreach($quotationScalarDimensionOrder as $dimension)
                                            <td>
                                                @if(in_array($dimension, $relevantDimensions, true))
                                                    <input
                                                        type="number"
                                                        min="0.0001"
                                                        step="0.0001"
                                                        name="items[{{ $index }}][available_{{ $dimension }}]"
                                                        class="form-control form-control-sm availability-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.available_{$dimension}") is-invalid @enderror"
                                                        data-availability-field="{{ $dimension }}"
                                                        value="{{ old("items.{$index}.available_{$dimension}", $qItem?->{'available_'.$dimension}) }}"
                                                        aria-label="Offer {{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }} for {{ $item->material_name }} in millimeters"
                                                    >
                                                    @error("items.{$index}.available_{$dimension}")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                @else
                                                    <div class="text-center tw-text-outline" aria-label="Not applicable">—</div>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td>
                                            @if(in_array('length', $relevantDimensions, true))
                                                <input
                                                    id="availableLength{{ $index }}"
                                                    type="text"
                                                    inputmode="decimal"
                                                    name="items[{{ $index }}][available_length_input]"
                                                    class="form-control form-control-sm availability-input offer-length-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.available_length_input") is-invalid @enderror"
                                                    data-availability-field="length"
                                                    value="{{ old("items.{$index}.available_length_input", $storedLength) }}"
                                                    placeholder="e.g. 2300 or 2300-2500 mm"
                                                    aria-label="Offer exact or ranged length for {{ $item->material_name }} in millimeters"
                                                    aria-describedby="availableLengthHelp{{ $index }} availableLengthFeedback{{ $index }}"
                                                >
                                                <div id="availableLengthHelp{{ $index }}" class="quotation-value-secondary">Exact or min-max</div>
                                                <div id="availableLengthFeedback{{ $index }}" class="invalid-feedback offer-length-feedback">
                                                    @error("items.{$index}.available_length_input"){{ $message }}@enderror
                                                </div>
                                            @else
                                                <div class="text-center tw-text-outline" aria-label="Not applicable">—</div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="offer-weight-control">
                                                <input
                                                    type="number"
                                                    min="0.0001"
                                                    step="0.0001"
                                                    name="items[{{ $index }}][offered_weight_per_unit]"
                                                    class="form-control form-control-sm offered-weight-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.offered_weight_per_unit") is-invalid @enderror"
                                                    value="{{ old("items.{$index}.offered_weight_per_unit", $qItem?->offered_weight_per_unit) }}"
                                                    aria-label="Offer KG per unit for {{ $item->material_name }}"
                                                >
                                                <span class="ui-status-chip ui-status-chip--warning offer-weight-indicator {{ $manualWeightOverride ? '' : 'd-none' }}">Est Weight</span>
                                            </div>
                                            <input type="hidden" name="items[{{ $index }}][offered_weight_manual_override]" class="offered-weight-manual-override" value="{{ $manualWeightOverride }}">
                                            @error("items.{$index}.offered_weight_per_unit")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        </td>
                                        <td class="text-end ui-tabular-nums">
                                            <span class="offer-total-weight-display quotation-calculated" aria-live="polite">—</span>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.0001"
                                                min="0.0001"
                                                name="items[{{ $index }}][price_per_kg]"
                                                class="form-control form-control-sm price-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.price_per_kg") is-invalid @enderror"
                                                value="{{ old("items.{$index}.price_per_kg", $qItem?->price_per_kg) }}"
                                                aria-label="Price per kilogram for {{ $item->material_name }}"
                                                placeholder="e.g. 4.2500"
                                            >
                                            @error("items.{$index}.price_per_kg")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td class="text-end ui-tabular-nums">
                                            <span class="offer-amount-display quotation-calculated" aria-live="polite">—</span>
                                        </td>
                                        <td class="quotation-notes-cell">
                                            @php $currentNote = old("items.{$index}.notes", $qItem?->notes); @endphp
                                            <textarea
                                                name="items[{{ $index }}][notes]"
                                                class="quotation-item-notes quotation-editable @error("items.{$index}.notes") is-invalid @enderror"
                                                aria-label="Offer notes for {{ $item->material_name }}"
                                                tabindex="-1"
                                            >{{ $currentNote }}</textarea>

                                            <button
                                                type="button"
                                                class="quotation-notes-trigger ui-motion ui-focus-ring @error("items.{$index}.notes") is-invalid @enderror {{ !empty($currentNote) ? 'has-notes' : '' }}"
                                                data-notes-trigger
                                                aria-haspopup="dialog"
                                                aria-expanded="false"
                                                aria-label="Edit notes for {{ $item->material_name }}"
                                                title="{{ $currentNote ?: 'Click to add notes' }}"
                                            >
                                                <x-ui.icon name="file-text" size="sm" class="quotation-notes-trigger__icon" aria-hidden="true" />
                                                <span class="quotation-notes-trigger__text text-truncate">{{ $currentNote ?: 'Add note...' }}</span>
                                                @if(!empty($currentNote))
                                                    <span class="quotation-notes-trigger__badge" title="Note entered" aria-hidden="true"></span>
                                                @endif
                                            </button>
                                            @error("items.{$index}.notes")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                                            <div class="quotation-notes-popover" data-notes-popover hidden role="dialog" aria-modal="false" aria-label="Offer notes for {{ $item->material_name }}">
                                                <div class="quotation-notes-popover__header">
                                                    <div>
                                                        <span class="quotation-notes-popover__title">Offer Notes</span>
                                                        <span class="quotation-notes-popover__subtitle text-truncate">{{ $item->material_name }}</span>
                                                    </div>
                                                    <button type="button" class="quotation-notes-popover__close" data-notes-cancel aria-label="Close notes popover">
                                                        <x-ui.icon name="x" size="sm" />
                                                    </button>
                                                </div>
                                                <div class="quotation-notes-popover__body">
                                                    <textarea
                                                        class="form-control form-control-sm quotation-notes-draft"
                                                        rows="4"
                                                        placeholder="e.g. Mill tolerance ±0.5mm, prime grade, MTC included..."
                                                        aria-label="Notes draft for {{ $item->material_name }}"
                                                    >{{ $currentNote }}</textarea>
                                                    <div class="quotation-notes-popover__hint">Provide tolerances, specs, or availability details.</div>
                                                </div>
                                                <div class="quotation-notes-popover__footer">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary quotation-notes-btn-cancel" data-notes-cancel>Cancel</button>
                                                    <button type="button" class="btn btn-sm btn-primary quotation-notes-btn-save" data-notes-save>
                                                        <x-ui.icon name="check" size="sm" class="me-1" /> Save
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @php $hasMtc = !empty($mtcAttachment); @endphp
                                            <div class="mtc-upload-container @error("items.{$index}.mtc_file") border-danger @enderror" data-mtc-container data-existing-attachment-id="{{ $mtcAttachment?->id ?? '' }}">
                                                <input
                                                    id="mtcFile{{ $index }}"
                                                    type="file"
                                                    name="items[{{ $index }}][mtc_file]"
                                                    class="visually-hidden mtc-file-input offer-disable-when-unavailable @error("items.{$index}.mtc_file") is-invalid @enderror"
                                                    accept=".pdf,.jpg,.jpeg,.png"
                                                    aria-label="MTC file for {{ $item->material_name }}"
                                                    aria-describedby="mtcFileHelp{{ $index }}"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="items[{{ $index }}][copy_from_attachment_id]"
                                                    class="mtc-copy-attachment-id"
                                                    value=""
                                                >
                                                <input
                                                    type="hidden"
                                                    name="items[{{ $index }}][keep_existing_attachment]"
                                                    class="mtc-keep-existing-attachment"
                                                    value="{{ $hasMtc ? '1' : '0' }}"
                                                >

                                                {{-- State 1: Empty State (Upload MTC Button) --}}
                                                <div class="mtc-empty-state {{ $hasMtc ? 'd-none' : '' }}" data-mtc-empty>
                                                    <label for="mtcFile{{ $index }}" class="mtc-file-label ui-motion ui-focus-ring" title="Accepted files: PDF, JPG, or PNG; maximum 5MB.">
                                                        <x-ui.icon name="paperclip" size="sm" class="me-1.5" />
                                                        <span>Upload MTC</span>
                                                    </label>
                                                    <div id="mtcFileHelp{{ $index }}" class="mtc-file-hint">PDF/JPG/PNG, max 5MB</div>
                                                </div>

                                                {{-- State 2: File Pill/Card State (Attached / Selected) --}}
                                                <div class="mtc-file-card {{ $hasMtc ? '' : 'd-none' }}" data-mtc-card>
                                                    <div class="mtc-file-card__main">
                                                        <x-ui.icon name="file-text" size="sm" class="mtc-file-card__icon" />
                                                        <span class="mtc-file-name text-truncate" data-default-name="{{ $mtcAttachment?->file_name ?? '' }}" title="{{ $mtcAttachment?->file_name ?? '' }}">
                                                            {{ $mtcAttachment?->file_name ?? '' }}
                                                        </span>
                                                    </div>
                                                    <div class="mtc-file-card__actions">
                                                        @if($mtcAttachment)
                                                            <a href="{{ route('attachments.show', $mtcAttachment->id) }}" class="mtc-file-action-link" target="_blank" rel="noopener" title="View attached file" data-mtc-server-link>
                                                                <x-ui.icon name="external-link" size="sm" />
                                                                <span>View</span>
                                                            </a>
                                                        @endif
                                                        <div class="dropdown d-inline-block mtc-copy-dropdown">
                                                            <button
                                                                type="button"
                                                                class="mtc-file-action-btn"
                                                                data-bs-toggle="dropdown"
                                                                data-bs-display="static"
                                                                aria-expanded="false"
                                                                title="Copy MTC to other items"
                                                                data-mtc-copy-trigger
                                                            >
                                                                <x-ui.icon name="copy" size="sm" />
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.75rem; min-width: 220px; z-index: 1060;">
                                                                <li>
                                                                    <button type="button" class="dropdown-item py-1.5 px-3 d-flex align-items-center gap-1.5" data-mtc-apply="same_material">
                                                                        <x-ui.icon name="layers" size="sm" class="tw-text-on-surface-variant flex-shrink-0" />
                                                                        <span>Apply to same material (<strong class="text-truncate d-inline-block align-bottom" style="max-width: 105px;">{{ $item->material_name }}</strong>)</span>
                                                                    </button>
                                                                </li>
                                                                <li>
                                                                    <button type="button" class="dropdown-item py-1.5 px-3 d-flex align-items-center gap-1.5" data-mtc-apply="all_available">
                                                                        <x-ui.icon name="check-check" size="sm" class="tw-text-on-surface-variant flex-shrink-0" />
                                                                        <span>Apply to all available items</span>
                                                                    </button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                        <label for="mtcFile{{ $index }}" class="mtc-file-action-btn" title="Change file" role="button">
                                                            <x-ui.icon name="refresh-cw" size="sm" />
                                                        </label>
                                                        <button type="button" class="mtc-file-action-btn text-danger" title="Remove file" data-mtc-remove aria-label="Remove selected MTC file">
                                                            <x-ui.icon name="x" size="sm" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            @error("items.{$index}.mtc_file")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        </td>
                                        <td>
                                            <div class="availability-control">
                                                <button
                                                    type="button"
                                                    class="availability-toggle ui-motion ui-focus-ring {{ $itemIsAvailable ? 'is-available' : 'is-unavailable' }}"
                                                    data-availability-toggle
                                                    role="switch"
                                                    aria-checked="{{ $itemIsAvailable ? 'true' : 'false' }}"
                                                    aria-label="Toggle availability for {{ $item->material_name }}"
                                                >
                                                    <input
                                                        id="notAvailable{{ $index }}"
                                                        type="checkbox"
                                                        class="item-not-available-toggle visually-hidden"
                                                        {{ $itemIsAvailable ? '' : 'checked' }}
                                                        tabindex="-1"
                                                        aria-hidden="true"
                                                    >
                                                    <span class="availability-toggle__icon" aria-hidden="true">
                                                        <x-ui.icon :name="$itemIsAvailable ? 'check' : 'x'" size="sm" />
                                                    </span>
                                                    <span class="availability-toggle__label item-availability-label">
                                                        {{ $itemIsAvailable ? 'Available' : 'Not Available' }}
                                                    </span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach
                            <tfoot class="table-light fw-bold border-top">
                                <tr>
                                    <td colspan="12" class="text-end tw-text-on-surface">TOTAL OFFER AMOUNT</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums">
                                        <span id="totalAmount">0</span>
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Section 3: Commercial Terms & Logistics --}}
            <x-ui.form-section
                title="Commercial Terms and Logistics"
                description="Specify estimated delivery timeline, proposal validity duration, and payment arrangements."
            >
                <x-ui.alert id="allUnavailableTermsNotice" tone="info" class="tw-mb-4 d-none">
                    All requested items are marked <strong>Not Available</strong>. Delivery timeline, validity period, and payment terms are disabled. You may still provide General Supplier Notes before submitting.
                </x-ui.alert>

                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <x-ui.date-picker
                        name="estimated_delivery"
                        label="Supplier Estimated Ready / Dispatch Date"
                        :value="optional($quotation?->estimated_delivery)->format('Y-m-d')"
                        helper="Estimated date material will be ready for dispatch from your facility."
                        required
                    />
                    <x-ui.date-picker
                        name="validity_period"
                        id="validityPeriod"
                        label="Quotation Valid Until"
                        :value="optional($quotation?->validity_period)->format('Y-m-d')"
                        :min="now()->toDateString()"
                        helper="Required for final submission. Prices remain firm until this date."
                    />
                    <x-ui.textarea
                        name="payment_terms"
                        label="Payment Terms"
                        :rows="2"
                        maxlength="100"
                        required
                        placeholder="e.g. T/T 30 Days after B/L date, L/C at sight, CAD"
                        :value="$quotation->payment_terms ?? 'TT 30 Days'"
                    />
                    <x-ui.textarea
                        name="general_notes"
                        label="General Supplier Notes"
                        :rows="2"
                        placeholder="e.g. CIF Tanjung Priok, sea freight in wooden cases, valid 14 days"
                        :value="$quotation->general_notes ?? ''"
                    />
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw-mt-6">
            <x-slot:left>
                <x-ui.button :href="route('supplier.quotations.period', $pr->period_id)" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>Cancel</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" variant="secondary" size="sm" id="btnSaveDraft" onclick="submitForm('draft')">
                    <span>{{ $quotation?->status === 'revision_requested' ? 'Save Revision Draft' : 'Save Draft' }}</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnSubmitQuotation" onclick="confirmSubmit()">
                    <x-ui.icon name="send" size="sm" />
                    <span>{{ $quotation?->status === 'revision_requested' ? 'Resubmit Quotation' : 'Submit Final Quotation' }}</span>
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>

{{-- Excel Import Modal --}}
<div class="modal fade" id="quotationImportModal" tabindex="-1" aria-labelledby="quotationImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-bold" id="quotationImportModalLabel">Import Quotation Items from Spreadsheet</h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs">Imported values are mapped by PR Item ID and are not committed to database until saved.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="quotationImportFile" class="form-label small fw-semibold tw-text-on-surface">Spreadsheet File (.xlsx, .xls, .csv)</label>
                        <input type="file" id="quotationImportFile" class="form-control form-control-sm" accept=".xlsx,.xls,.csv">
                        <div class="form-text tw-text-ui-xs">Use the template for this PR. Max 10 MB and 1,000 data rows.</div>
                    </div>
                    <div class="col-md-5">
                        <label for="quotationImportMode" class="form-label small fw-semibold tw-text-on-surface">Import Mode</label>
                        <select id="quotationImportMode" class="form-select form-select-sm" aria-describedby="quotationImportModeHelp">
                            <option value="fill_empty" selected>Fill Empty Fields Only</option>
                            <option value="replace">Replace Imported Fields</option>
                        </select>
                        <div id="quotationImportModeHelp" class="form-text tw-text-ui-xs" aria-live="polite"></div>
                    </div>
                </div>

                <div id="quotationImportResult" class="d-none mt-3">
                    <div id="quotationImportSummary" class="tw-mb-2.5 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-px-3 tw-py-2 tw-text-ui-xs tw-font-semibold tw-text-on-surface" role="status"></div>

                    <div id="quotationImportWarningsPanel" class="d-none tw-mb-2.5 tw-rounded-ui-sm tw-border-s-4 tw-border-warning tw-bg-warning-container tw-px-3 tw-py-2 tw-text-ui-xs tw-text-warning-container-foreground" role="status">
                        <div class="fw-bold mb-1"><x-ui.icon name="triangle-alert" size="sm" class="me-1" />Warnings</div>
                        <ul id="quotationImportWarnings" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="quotationImportErrorsPanel" class="d-none tw-mb-2.5 tw-rounded-ui-sm tw-border-s-4 tw-border-error tw-bg-error-container tw-px-3 tw-py-2 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1"><x-ui.icon name="circle-x" size="sm" class="me-1" />Import Errors</div>
                        <ul id="quotationImportErrors" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="quotationImportPreviewPanel" class="d-none">
                        <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mb-1.5">Parsed Item Preview</div>
                        <div class="table-responsive border rounded tw-max-h-64">
                            <table class="table table-sm table-striped align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col">PR Item ID</th>
                                        <th scope="col">Availability</th>
                                        <th scope="col">Price/Kg</th>
                                        <th scope="col">Offer Qty</th>
                                        @foreach($quotationDimensionOrder as $dimensionField)
                                            <th scope="col">{{ \App\Models\PrItem::DIMENSION_LABELS[$dimensionField] }}</th>
                                        @endforeach
                                        <th scope="col">Offer KG/Unit</th>
                                        <th scope="col">Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="quotationImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer tw-bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseQuotationImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="quotationImportSpinner"></span>
                    Parse &amp; Validate
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnApplyQuotationImport" disabled>
                    <x-ui.icon name="circle-check" size="sm" class="me-1" /> Apply to Form
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

<div id="exchangeRates" class="d-none"></div>
@endsection

@push('scripts')
<script>
    const currencyRates = @json($currencyRates);
    const quotationImportPreviewUrl = @json(route('supplier.quotations.import-preview', $pr));
    let quotationImportRows = [];
    let quotationImportRequestInFlight = false;

    const quotationImportFieldSelectors = {
        price_per_kg: '.price-input',
        available_qty: '[data-availability-field="qty"]',
        available_thickness: '[data-availability-field="thickness"]',
        available_width: '[data-availability-field="width"]',
        available_d_outer: '[data-availability-field="d_outer"]',
        available_d_inner: '[data-availability-field="d_inner"]',
        available_length_input: '[data-availability-field="length"]',
        offered_weight_per_unit: '.offered-weight-input',
        notes: '.quotation-item-notes'
    };

    function quotationRowForPrItem(prItemId) {
        return $(`.quotation-item-group[data-pr-item-id="${String(prItemId)}"]`).first();
    }

    function importedLengthValue(row) {
        if (row.available_length !== null && row.available_length !== undefined && row.available_length !== '') {
            return row.available_length;
        }

        if (row.available_length_min !== null && row.available_length_min !== undefined
            && row.available_length_max !== null && row.available_length_max !== undefined) {
            return `${row.available_length_min}-${row.available_length_max}`;
        }

        return '';
    }

    function formatQuotationImportMessage(entry) {
        const location = entry.row
            ? `Row ${entry.row}${entry.column ? `, ${entry.column}` : ''}`
            : (entry.column || 'File');

        return `${location}: ${entry.message}`;
    }

    function renderQuotationImportResult(payload) {
        const summary = payload.summary || { total: 0, valid: 0, invalid: 0 };
        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        const errors = Array.isArray(payload.errors) ? payload.errors : [];
        const rows = Array.isArray(payload.rows) ? payload.rows : [];

        $('#quotationImportResult').removeClass('d-none');
        $('#quotationImportSummary').text(
            `Total Rows: ${summary.total || 0} | Valid: ${summary.valid || 0} | Invalid: ${summary.invalid || 0}`
        );

        const $warnings = $('#quotationImportWarnings').empty();
        warnings.forEach((warning) => $('<li>').text(formatQuotationImportMessage(warning)).appendTo($warnings));
        $('#quotationImportWarningsPanel').toggleClass('d-none', warnings.length === 0);

        const $errors = $('#quotationImportErrors').empty();
        errors.forEach((error) => $('<li>').text(formatQuotationImportMessage(error)).appendTo($errors));
        $('#quotationImportErrorsPanel').toggleClass('d-none', errors.length === 0);

        const $preview = $('#quotationImportPreviewBody').empty();
        rows.forEach((row) => {
            const $tableRow = $('<tr>');
            [
                row.pr_item_id,
                row.availability ?? (row.is_available === false ? 'Not Available' : 'Available'),
                row.price_per_kg,
                row.available_qty ?? '-',
                row.available_thickness ?? '-',
                row.available_width ?? '-',
                row.available_d_outer ?? '-',
                row.available_d_inner ?? '-',
                importedLengthValue(row) || '-',
                row.offered_weight_per_unit ?? '-',
                row.notes ?? '-'
            ].forEach((value) => $('<td>').text(value).appendTo($tableRow));
            $tableRow.appendTo($preview);
        });
        $('#quotationImportPreviewPanel').toggleClass('d-none', rows.length === 0);

        quotationImportRows = payload.success === true ? rows : [];
        $('#btnApplyQuotationImport').prop('disabled', payload.success !== true || rows.length === 0);
    }

    function setQuotationImportBusy(isBusy) {
        quotationImportRequestInFlight = isBusy;
        $('#btnParseQuotationImport').prop('disabled', isBusy);
        $('#quotationImportFile, #quotationImportMode').prop('disabled', isBusy);
        $('#quotationImportSpinner').toggleClass('d-none', !isBusy);
    }

    function parseQuotationImport() {
        if (quotationImportRequestInFlight) {
            return;
        }

        const fileInput = document.getElementById('quotationImportFile');
        const file = fileInput.files[0];
        if (!file) {
            fileInput.setCustomValidity('Select an XLSX, XLS, or CSV file before continuing.');
            fileInput.reportValidity();
            return;
        }
        fileInput.setCustomValidity('');

        const formData = new FormData();
        formData.append('_token', @json(csrf_token()));
        formData.append('import_file', file);
        quotationImportRows = [];
        $('#btnApplyQuotationImport').prop('disabled', true);
        setQuotationImportBusy(true);

        $.ajax({
            url: quotationImportPreviewUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done((payload) => {
            renderQuotationImportResult(payload);
        }).fail((xhr) => {
            renderQuotationImportResult(xhr.responseJSON || {
                success: false,
                rows: [],
                warnings: [],
                summary: { total: 0, valid: 0, invalid: 0 },
                errors: [{ row: null, column: 'import_file', message: 'The spreadsheet could not be processed.' }]
            });
        }).always(() => {
            setQuotationImportBusy(false);
        });
    }

    function quotationImportWouldOverwrite() {
        return quotationImportRows.some((row) => {
            const $formRow = quotationRowForPrItem(row.pr_item_id);
            if ($formRow.length === 0) {
                return false;
            }

            const fieldWouldChange = Object.entries(quotationImportFieldSelectors).some(([field, selector]) => {
                if (field === 'offered_weight_per_unit') {
                    const incomingWeight = row.offered_weight_per_unit;
                    if (incomingWeight === null || incomingWeight === undefined || String(incomingWeight).trim() === '') {
                        return false;
                    }
                }

                const $input = $formRow.find(selector);
                if ($input.length === 0) {
                    return false;
                }

                const current = String($input.val() ?? '').trim();
                const incomingValue = field === 'available_length_input' ? importedLengthValue(row) : row[field];
                const incoming = String(incomingValue ?? '').trim();
                return current !== '' && current !== incoming;
            });

            const importedAvailable = row.is_available !== false;
            const currentAvailable = $formRow.find('.item-availability-input').val() !== '0';

            return fieldWouldChange || importedAvailable !== currentAvailable;
        });
    }

    function quotationGroupHasOfferValues($group) {
        return Object.values(quotationImportFieldSelectors).some((selector) => {
            const value = $group.find(selector).first().val();
            return value !== undefined && String(value).trim() !== '';
        });
    }

    function performQuotationImportApply(mode) {
        let changedFields = 0;
        const importedItems = quotationImportRows.length;

        quotationImportRows.forEach((row) => {
            const $formRow = quotationRowForPrItem(row.pr_item_id);
            if ($formRow.length === 0) {
                return;
            }

            const importedAvailable = row.is_available !== false;
            const canApplyAvailability = mode === 'replace' || !quotationGroupHasOfferValues($formRow);
            if (canApplyAvailability) {
                setItemUnavailable($formRow, !importedAvailable);
                changedFields++;
            }

            // Apply all fields except offered_weight_per_unit which is handled explicitly below
            Object.entries(quotationImportFieldSelectors).forEach(([field, selector]) => {
                if (field === 'offered_weight_per_unit') {
                    return;
                }

                const $input = $formRow.find(selector);
                if ($input.length === 0) {
                    return;
                }

                const incoming = field === 'available_length_input'
                    ? importedLengthValue(row)
                    : (row[field] ?? '');
                const current = String($input.val() ?? '').trim();
                const incomingText = String(incoming).trim();
                const shouldApply = mode === 'replace'
                    || (current === '' && incomingText !== '');

                if (!shouldApply || current === incomingText) {
                    return;
                }

                $input.val(incoming).trigger('input').trigger('change');
                changedFields++;
            });

            // Explicitly handle offered_weight_per_unit without wiping out auto-calculated weight
            const rawImportedWeight = row.offered_weight_per_unit;
            const hasExplicitWeight = rawImportedWeight !== null && rawImportedWeight !== undefined && String(rawImportedWeight).trim() !== '';
            const $weightInput = $formRow.find('.offered-weight-input');

            if (hasExplicitWeight) {
                const incomingWeight = String(rawImportedWeight).trim();
                const currentWeight = String($weightInput.val() ?? '').trim();
                if (mode === 'replace' || currentWeight === '') {
                    $weightInput.val(incomingWeight);
                    setFieldValidity($weightInput, true);
                    $formRow.find('.offered-weight-manual-override').val('1');
                    changedFields++;
                }
            } else if (importedAvailable) {
                const currentWeight = String($weightInput.val() ?? '').trim();
                if (mode === 'replace' || currentWeight === '') {
                    autoCalculateOfferWeight($formRow);
                }
            }

            updateOfferRowState($formRow);
            updateOfferWeightState($formRow);
            recalculateQuotationRow($formRow);
        });

        recalculateQuotationTotals();
        updateCommercialTermsState();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('quotationImportModal')).hide();
        AdasiToast.show({
            type: 'success',
            title: 'Import Applied',
            message: `${changedFields} field(s) across ${importedItems} item(s) applied.`,
            autoClose: 2400
        });
    }

    function applyQuotationImport() {
        if (quotationImportRows.length === 0) {
            return;
        }

        const mode = $('#quotationImportMode').val();
        if (mode === 'replace' && quotationImportWouldOverwrite()) {
            AdasiAlert.confirmDanger({
                title: 'Replace existing item values?',
                text: 'Imported values will replace values currently entered for matching items.',
                confirmText: 'Yes, Replace Fields',
                cancelText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    performQuotationImportApply(mode);
                }
            });
            return;
        }

        performQuotationImportApply(mode);
    }

    function selectedCurrency() {
        return $('#quotationCurrency').val() || '';
    }

    function selectedRate() {
        return parseFloat(currencyRates[selectedCurrency()]) || 0;
    }

    function formatMaxTwo(value) {
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
            return '—';
        }

        return Number(value).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });
    }

    function normalizeEditableNumber(value) {
        const numeric = Number(value);
        return Number.isFinite(numeric) ? String(numeric) : String(value ?? '').trim();
    }

    function parseOfferLength(value) {
        const raw = String(value ?? '').trim().replace(/\u2013/g, '-');
        if (raw === '') {
            return { valid: true, empty: true, isRange: false };
        }

        const match = raw.match(/^([0-9]+(?:\.[0-9]+)?)\s*(?:-\s*([0-9]+(?:\.[0-9]+)?))?$/);
        if (!match) {
            return { valid: false, empty: false, reason: 'format' };
        }

        const first = Number(match[1]);
        const last = match[2] === undefined ? null : Number(match[2]);
        if (!Number.isFinite(first) || first <= 0 || (last !== null && (!Number.isFinite(last) || last <= 0))) {
            return { valid: false, empty: false, reason: 'positive' };
        }

        if (last !== null && first > last) {
            return { valid: false, empty: false, reason: 'reversed' };
        }

        return last === null
            ? { valid: true, empty: false, isRange: false, exact: first }
            : { valid: true, empty: false, isRange: true, min: first, max: last };
    }

    function formatOfferLength(parsed) {
        if (!parsed?.valid || parsed.empty) return '';
        return parsed.isRange
            ? `${normalizeEditableNumber(parsed.min)}-${normalizeEditableNumber(parsed.max)}`
            : normalizeEditableNumber(parsed.exact);
    }

    function itemIsUnavailable($group) {
        return $group.find('.item-availability-input').val() === '0';
    }

    function setFieldValidity($input, valid, message = '') {
        const input = $input?.[0];
        if (!input) return;
        input.setCustomValidity(valid ? '' : message);
        $input.toggleClass('is-invalid', !valid);
    }

    function validateOfferQuantity($group, requireValue = false) {
        const $input = $group.find('[data-availability-field="qty"]');
        if (!$input.length || itemIsUnavailable($group)) {
            setFieldValidity($input, true);
            return true;
        }

        const raw = String($input.val() ?? '').trim();
        const requested = Number($group.data('requested-qty'));
        const value = Number(raw);
        let message = '';

        if (raw === '' && requireValue) {
            message = 'Offer Qty is required for an available item.';
        } else if (raw !== '' && (!Number.isInteger(value) || value < 1)) {
            message = 'Offer Qty must be a whole number of at least 1.';
        } else if (raw !== '' && value > requested) {
            message = `Offer Qty cannot exceed the requested Qty (${requested}).`;
        }

        setFieldValidity($input, message === '', message);
        $group.find('.offer-qty-feedback').text(message);
        return message === '';
    }

    function autoCalculateOfferWeight($group) {
        if (itemIsUnavailable($group)) return;

        const shape = $group.data('shape');
        const densityProfile = $group.data('density-profile') || 'steel';
        const isAluminium = densityProfile === 'aluminium';
        const flatFactor = isAluminium ? 0.00273 : 0.00785;
        const roundFactor = 0.006167;

        const thickness = Number($group.find('[data-availability-field="thickness"]').val()) || 0;
        const width = Number($group.find('[data-availability-field="width"]').val()) || 0;
        const dOuter = Number($group.find('[data-availability-field="d_outer"]').val()) || 0;
        const dInner = Number($group.find('[data-availability-field="d_inner"]').val()) || 0;

        const parsedLength = parseOfferLength($group.find('.offer-length-input').val());
        let length = 0;
        let isRange = false;

        if (parsedLength.valid && !parsedLength.empty) {
            if (parsedLength.isRange) {
                length = (parsedLength.min + parsedLength.max) / 2;
                isRange = true;
            } else {
                length = parsedLength.exact;
            }
        }

        if (length <= 0) return;

        let calculatedWeight = null;

        if (shape === 'Flat') {
            if (thickness > 0 && width > 0) {
                calculatedWeight = (thickness * width * length * flatFactor) / 1000;
            }
        } else if (shape === 'Round') {
            if (dOuter > 0) {
                calculatedWeight = (dOuter * dOuter * length * roundFactor) / 1000;
            }
        } else if (shape === 'Hollow') {
            if (dOuter > 0 && dInner > 0 && dOuter > dInner) {
                calculatedWeight = (((dOuter * dOuter) - (dInner * dInner)) * length * roundFactor) / 1000;
            }
        }

        if (calculatedWeight !== null && Number.isFinite(calculatedWeight) && calculatedWeight > 0) {
            const rounded = Number(calculatedWeight.toFixed(4));
            const $weightInput = $group.find('.offered-weight-input');
            $weightInput.val(rounded);
            setFieldValidity($weightInput, true);
            $group.find('.offered-weight-manual-override').val(isRange ? '1' : '0');
            updateOfferWeightState($group);
            recalculateQuotationRow($group);
        }
    }

    function updateOfferWeightState($group) {
        const parsedLength = parseOfferLength($group.find('.offer-length-input').val());
        const isEstimated = parsedLength.isRange
            || $group.find('.offered-weight-manual-override').val() === '1';

        $group.find('.offer-weight-indicator').toggleClass('d-none', itemIsUnavailable($group) || !isEstimated);
        return isEstimated;
    }

    function validateOfferLength($group) {
        const $input = $group.find('.offer-length-input');
        if (!$input.length || itemIsUnavailable($group)) {
            setFieldValidity($input, true);
            updateOfferWeightState($group);
            return true;
        }

        const parsed = parseOfferLength($input.val());
        let message = '';
        if (!parsed.valid) {
            message = parsed.reason === 'reversed'
                ? 'The minimum Offer Length cannot exceed the maximum length.'
                : 'Enter one length (e.g. 2300) or a range from minimum to maximum (e.g. 2300-2500).';
        }

        setFieldValidity($input, parsed.valid, message);
        $group.find('.offer-length-feedback').text(message);
        if (parsed.valid && parsed.isRange) {
            $group.find('.offered-weight-manual-override').val('1');
        }
        updateOfferWeightState($group);
        return parsed.valid;
    }

    function isAllItemsUnavailable() {
        const $groups = $('.quotation-item-group');
        return $groups.length > 0 && $groups.toArray().every((group) => itemIsUnavailable($(group)));
    }

    function refreshCurrencyState() {
        const currency = selectedCurrency();
        $('.currency-label').text(currency || '-');
        $('#currencyWarningLabel').text(currency || '-');
        const allUnavailable = isAllItemsUnavailable();
        $('#currencyRateWarning').toggleClass('d-none', allUnavailable || !currency || selectedRate() > 0);
        calculateTotal();
    }

    function updateCommercialTermsState() {
        const allUnavailable = isAllItemsUnavailable();
        const $currency = $('#quotationCurrency');
        const $deliveryInput = $('[name="estimated_delivery"]');
        const $deliveryPicker = $deliveryInput.closest('[data-adasi-date-picker]');
        const $deliveryTrigger = $deliveryPicker.find('[data-calendar-trigger]');
        const $paymentTerms = $('[name="payment_terms"]');
        const $paymentTermsGroup = $paymentTerms.closest('div');
        const $validityPeriod = $('#validityPeriod, [name="validity_period"]');
        const $validityPicker = $validityPeriod.closest('[data-adasi-date-picker]');
        const $validityTrigger = $validityPicker.find('[data-calendar-trigger]');
        const $rateWarning = $('#currencyRateWarning');
        const $termsNotice = $('#allUnavailableTermsNotice');

        if (allUnavailable) {
            $currency.removeAttr('required').removeClass('is-invalid');

            // Estimated Delivery: disable input and trigger, remove validation, hide asterisks
            $deliveryInput.removeAttr('required').removeClass('is-invalid').prop('disabled', true).attr('aria-disabled', 'true');
            $deliveryTrigger.prop('disabled', true).attr('aria-disabled', 'true').attr('tabindex', '-1');
            $deliveryPicker.attr('data-calendar-required', 'false').attr('data-calendar-disabled', 'true').addClass('is-disabled');
            $deliveryPicker.find('.tw-text-error').addClass('d-none');
            $deliveryPicker[0]?.dispatchEvent(new CustomEvent('adasi:calendar-close'));

            // Payment Terms: disable textarea, remove validation, hide asterisks
            $paymentTerms.removeAttr('required').removeClass('is-invalid').prop('disabled', true).attr('aria-disabled', 'true').addClass('tw-bg-surface-container tw-opacity-50 tw-cursor-not-allowed');
            $paymentTermsGroup.find('.tw-text-error').addClass('d-none');

            // Validity Period: disable input and trigger, remove validation, hide asterisks
            $validityPeriod.removeAttr('required').removeClass('is-invalid').prop('disabled', true).attr('aria-disabled', 'true');
            $validityTrigger.prop('disabled', true).attr('aria-disabled', 'true').attr('tabindex', '-1');
            $validityPicker.attr('data-calendar-required', 'false').attr('data-calendar-disabled', 'true').addClass('is-disabled');
            $validityPicker.find('.tw-text-error').addClass('d-none');
            $validityPicker.find('#validityPeriod-help').addClass('tw-opacity-40');
            $validityPicker[0]?.dispatchEvent(new CustomEvent('adasi:calendar-close'));

            $rateWarning.addClass('d-none');
            $termsNotice.removeClass('d-none');
        } else {
            $currency.attr('required', 'required');

            // Estimated Delivery: enable input and trigger, restore validation, show asterisks
            $deliveryInput.attr('required', 'required').prop('disabled', false).removeAttr('aria-disabled');
            $deliveryTrigger.prop('disabled', false).removeAttr('aria-disabled').removeAttr('tabindex');
            $deliveryPicker.attr('data-calendar-required', 'true').attr('data-calendar-disabled', 'false').removeClass('is-disabled');
            $deliveryPicker.find('.tw-text-error').removeClass('d-none');

            // Payment Terms: enable textarea, restore validation, show asterisks
            $paymentTerms.attr('required', 'required').prop('disabled', false).removeAttr('aria-disabled').removeClass('tw-bg-surface-container tw-opacity-50 tw-cursor-not-allowed');
            $paymentTermsGroup.find('.tw-text-error').removeClass('d-none');

            // Validity Period: enable input and trigger, restore validation, show asterisks
            $validityPeriod.prop('disabled', false).removeAttr('aria-disabled');
            $validityTrigger.prop('disabled', false).removeAttr('aria-disabled').removeAttr('tabindex');
            $validityPicker.attr('data-calendar-disabled', 'false').removeClass('is-disabled');
            $validityPicker.find('.tw-text-error').removeClass('d-none');
            $validityPicker.find('#validityPeriod-help').removeClass('tw-opacity-40');

            $termsNotice.addClass('d-none');
            refreshCurrencyState();
        }
    }

    window.isAllItemsUnavailable = isAllItemsUnavailable;
    window.refreshCurrencyState = refreshCurrencyState;
    window.updateCommercialTermsState = updateCommercialTermsState;

    function setItemUnavailable($group, unavailable) {
        $group.find('.item-availability-input').val(unavailable ? '0' : '1');
        $group.find('.item-not-available-toggle').prop('checked', unavailable);
        $group.find('.quotation-offer-row').toggleClass('is-unavailable', unavailable);
        $group.find('.offer-disable-when-unavailable').prop('disabled', unavailable).attr('aria-disabled', unavailable ? 'true' : 'false');
        $group.find('.mtc-file-label').attr('aria-disabled', unavailable ? 'true' : 'false');

        const $toggleBtn = $group.find('.availability-toggle');
        $toggleBtn
            .toggleClass('is-available', !unavailable)
            .toggleClass('is-unavailable', unavailable)
            .attr('aria-checked', unavailable ? 'false' : 'true');
        $toggleBtn.find('.item-availability-label').text(unavailable ? 'Not Available' : 'Available');

        const iconSvg = unavailable
            ? '<svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
            : '<svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        $toggleBtn.find('.availability-toggle__icon').html(iconSvg);

        $group.find('.quotation-item-notes').attr(
            'placeholder',
            unavailable
                ? 'e.g. Out of stock, earliest rolling schedule next quarter...'
                : 'e.g. Mill tolerance ±0.5mm, prime grade, MTC included...'
        );
        $group.find('.quotation-notes-trigger').prop('disabled', unavailable).attr('aria-disabled', unavailable ? 'true' : 'false');
        $group.find('.quotation-notes-draft').attr(
            'placeholder',
            unavailable
                ? 'e.g. Out of stock, earliest rolling schedule next quarter...'
                : 'e.g. Mill tolerance ±0.5mm, prime grade, MTC included...'
        );

        if (unavailable) {
            $group.find('.offer-disable-when-unavailable').each(function() {
                this.setCustomValidity?.('');
                $(this).removeClass('is-invalid');
            });
            $group.find('.offer-qty-feedback, .offer-length-feedback').text('');
        }

        updateOfferWeightState($group);
        recalculateQuotationRow($group);
        updateCommercialTermsState();
    }

    function updateOfferRowState($group) {
        setItemUnavailable($group, itemIsUnavailable($group));
        validateOfferQuantity($group);
        validateOfferLength($group);
    }

    function recalculateQuotationRow($group) {
        const unavailable = itemIsUnavailable($group);
        const price = Number($group.find('.price-input').val());
        const requestedTotalWeight = Number($group.data('requested-total-weight')) || 0;
        const offeredQty = Number($group.find('[data-availability-field="qty"]').val());
        const offeredWeight = Number($group.find('.offered-weight-input').val());
        const rate = selectedRate();

        if (unavailable) {
            $group.find('.requested-price-display, .requested-amount-display, .offer-total-weight-display, .offer-amount-display').text('—');
            $group.find('.requested-idr-display, .offer-idr-display').text('');
            return { amount: 0, idr: 0 };
        }

        const hasPrice = Number.isFinite(price) && price > 0;
        const offerTotalWeight = Number.isFinite(offeredQty) && offeredQty > 0
            && Number.isFinite(offeredWeight) && offeredWeight > 0
            ? offeredQty * offeredWeight
            : null;
        const requestedAmount = hasPrice ? requestedTotalWeight * price : null;
        const offerAmount = hasPrice && offerTotalWeight !== null ? offerTotalWeight * price : null;

        $group.find('.requested-price-display').text(hasPrice ? formatMaxTwo(price) : '—');
        $group.find('.requested-amount-display').text(requestedAmount === null ? '—' : formatMaxTwo(requestedAmount));
        $group.find('.offer-total-weight-display').text(offerTotalWeight === null ? '—' : formatMaxTwo(offerTotalWeight));
        $group.find('.offer-amount-display').text(offerAmount === null ? '—' : formatMaxTwo(offerAmount));

        return {
            amount: offerAmount ?? 0,
            idr: offerAmount !== null && rate > 0 ? offerAmount * rate : 0,
        };
    }

    function recalculateQuotationTotals() {
        let totalAmount = 0;
        let totalIdr = 0;
        $('.quotation-item-group').each(function() {
            const totals = recalculateQuotationRow($(this));
            totalAmount += totals.amount;
            totalIdr += totals.idr;
        });

        $('#totalAmount').text(formatMaxTwo(totalAmount));
    }

    function calculateTotal() {
        recalculateQuotationTotals();
    }

    const requestedValueAttributes = {
        qty: 'data-requested-qty',
        thickness: 'data-requested-thickness',
        d_outer: 'data-requested-d-outer',
        d_inner: 'data-requested-d-inner',
        width: 'data-requested-width',
        length: 'data-requested-length',
        weight: 'data-requested-weight',
    };

    function copyButtonElement(candidate) {
        const button = candidate?.currentTarget || candidate;

        if (button?.jquery) {
            return button[0] || null;
        }

        return button && typeof button.closest === 'function' ? button : null;
    }

    function normalizedRequestedValue(rawValue) {
        const value = String(rawValue ?? '').trim();

        if (value === '') {
            return '';
        }

        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return Number.isInteger(numericValue)
            ? String(numericValue)
            : String(Number(numericValue.toFixed(4)));
    }

    window.copyRequestedValues = function(candidate) {
        const button = copyButtonElement(candidate);
        const row = button?.closest('.quotation-item-group');

        if (!row) {
            return false;
        }

        const $group = $(row);
        setItemUnavailable($group, false);
        let copied = false;

        Object.entries(requestedValueAttributes).forEach(([field, attribute]) => {
            const value = normalizedRequestedValue(button.getAttribute(attribute));

            if (value === '') {
                return;
            }

            const input = field === 'weight'
                ? row.querySelector('.offered-weight-input')
                : row.querySelector(`[data-availability-field="${field}"]`);

            if (!input) {
                return;
            }

            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            copied = true;
        });

        if (copied) {
            $group.find('.offered-weight-manual-override').val('0');
            validateOfferQuantity($group);
            validateOfferLength($group);
            updateOfferWeightState($group);
            recalculateQuotationRow($group);
            row.classList.add('availability-copied');
            setTimeout(() => row.classList.remove('availability-copied'), 900);
        }

        return copied;
    };

    window.copyAllRequestedValues = function() {
        let copiedRows = 0;

        document.querySelectorAll('.copy-from-pr-btn').forEach((button) => {
            if (window.copyRequestedValues(button)) {
                copiedRows++;
            }
        });

        if (copiedRows > 0) {
            showCopyFeedback(`Copied requested values for ${copiedRows} item(s).`);
        }

        return copiedRows;
    };

    function showCopyFeedback(message) {
        if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
            window.AdasiToast.success(message, {
                title: 'Values Copied',
                autoClose: 2000
            });
        } else if (window.AdasiToast && typeof window.AdasiToast.show === 'function') {
            window.AdasiToast.show({
                type: 'success',
                title: 'Values Copied',
                message: message,
                autoClose: 2000
            });
        }
    }

    document.addEventListener('click', function(event) {
        const target = event.target;
        const button = target && typeof target.closest === 'function'
            ? target.closest('#copyAllRequested, .copy-from-pr-btn')
            : null;

        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (button.id === 'copyAllRequested') {
            window.copyAllRequestedValues();
            return;
        }

        if (window.copyRequestedValues(button)) {
            showCopyFeedback('Requested values copied to row.');
        }
    });

    $(document).ready(function() {
        const importModeDescriptions = {
            fill_empty: 'Only fills offer fields that are still empty. Existing values entered in the form are preserved.',
            replace: 'Replaces the matching offer fields for the same PR items using values from the spreadsheet. It does not create additional quotation items.',
        };
        const updateQuotationImportModeHelp = () => {
            $('#quotationImportModeHelp').text(importModeDescriptions[$('#quotationImportMode').val()] || '');
        };

        $('#btnParseQuotationImport').on('click', parseQuotationImport);
        $('#btnApplyQuotationImport').on('click', applyQuotationImport);
        $('#quotationImportMode').on('change', updateQuotationImportModeHelp);
        updateQuotationImportModeHelp();
        $(document).on('change', '.mtc-file-input', function() {
            const fileName = this.files?.[0]?.name;
            const $container = $(this).closest('[data-mtc-container]');
            const $card = $container.find('[data-mtc-card]');
            const $empty = $container.find('[data-mtc-empty]');
            const $fileName = $container.find('.mtc-file-name');
            const defaultName = $fileName.data('default-name');

            if (fileName) {
                $container.find('.mtc-copy-attachment-id').val('');
                $container.find('.mtc-keep-existing-attachment').val('0');
                $fileName.text(fileName).attr('title', fileName);
                $empty.addClass('d-none');
                $card.removeClass('d-none');
                $container.find('[data-mtc-server-link]').addClass('d-none');
            } else if ($container.find('.mtc-copy-attachment-id').val()) {
                // If a copy attachment ID was set, keep card visible
                $empty.addClass('d-none');
                $card.removeClass('d-none');
            } else if (defaultName && $container.find('.mtc-keep-existing-attachment').val() === '1') {
                $fileName.text(defaultName).attr('title', defaultName);
                $empty.addClass('d-none');
                $card.removeClass('d-none');
                $container.find('[data-mtc-server-link]').removeClass('d-none');
            } else {
                $fileName.text('').removeAttr('title');
                $card.addClass('d-none');
                $empty.removeClass('d-none');
            }
        });

        $(document).on('click', '[data-mtc-remove]', function(e) {
            e.preventDefault();
            const $container = $(this).closest('[data-mtc-container]');
            const $input = $container.find('.mtc-file-input');
            const $fileName = $container.find('.mtc-file-name');
            $input.val('');
            $container.find('.mtc-copy-attachment-id').val('');
            $container.find('.mtc-keep-existing-attachment').val('0');
            $fileName.data('default-name', '').removeAttr('data-default-name').text('').removeAttr('title');
            $container.find('[data-mtc-card]').addClass('d-none');
            $container.find('[data-mtc-empty]').removeClass('d-none');
        });

        // Quick Apply / Copy MTC to multiple items
        $(document).on('click', '[data-mtc-apply]', function(e) {
            e.preventDefault();
            const mode = $(this).data('mtc-apply'); // 'same_material' or 'all_available'
            const $sourceContainer = $(this).closest('[data-mtc-container]');
            const $sourceGroup = $sourceContainer.closest('.quotation-item-group');
            const sourceMaterialName = ($sourceGroup.data('material-name') || '').toString().trim();
            const sourceInput = $sourceContainer.find('.mtc-file-input')[0];
            const sourceFile = sourceInput?.files?.[0] || null;
            const sourceAttachmentId = $sourceContainer.find('.mtc-copy-attachment-id').val() || $sourceContainer.attr('data-existing-attachment-id') || null;
            const sourceFileName = $sourceContainer.find('.mtc-file-name').text().trim();
            const sourceServerLink = $sourceContainer.find('[data-mtc-server-link]').attr('href') || null;

            if (!sourceFile && !sourceAttachmentId) {
                if (window.AdasiToast) {
                    AdasiToast.error('Please upload or select an MTC file first before copying.');
                }
                return;
            }

            // Find all candidate target groups
            const $allGroups = $('.quotation-item-group');
            const targetList = [];

            $allGroups.each(function() {
                const $targetGroup = $(this);
                // Exclude source group
                if ($targetGroup[0] === $sourceGroup[0]) return;

                // Check availability: must be available
                const isAvailable = $targetGroup.find('.item-availability-input').val() === '1' && !$targetGroup.hasClass('is-unavailable');
                if (!isAvailable) return;

                // Check material match for 'same_material' mode
                const targetMaterialName = ($targetGroup.data('material-name') || '').toString().trim();
                if (mode === 'same_material') {
                    if (targetMaterialName.toLowerCase() !== sourceMaterialName.toLowerCase()) {
                        return;
                    }
                }

                const $targetContainer = $targetGroup.find('[data-mtc-container]');
                const targetInput = $targetContainer.find('.mtc-file-input')[0];
                const hasExistingFile = (targetInput?.files && targetInput.files.length > 0) ||
                    $targetContainer.find('.mtc-copy-attachment-id').val() !== '' ||
                    (!$targetContainer.find('[data-mtc-card]').hasClass('d-none') && $targetContainer.find('.mtc-file-name').text().trim() !== '');

                targetList.push({
                    $group: $targetGroup,
                    $container: $targetContainer,
                    input: targetInput,
                    hasExistingFile: hasExistingFile,
                });
            });

            if (targetList.length === 0) {
                if (window.AdasiToast) {
                    const msg = mode === 'same_material'
                        ? 'No other available items with the same material (' + sourceMaterialName + ').'
                        : 'No other available items found.';
                    AdasiToast.info(msg);
                }
                return;
            }

            const overwriteTargets = targetList.filter(t => t.hasExistingFile);

            const executeApply = () => {
                let appliedCount = 0;
                targetList.forEach(target => {
                    const $tCont = target.$container;
                    const tInput = target.input;

                    if (sourceFile) {
                        try {
                            const dt = new DataTransfer();
                            dt.items.add(sourceFile);
                            tInput.files = dt.files;
                            $tCont.find('.mtc-copy-attachment-id').val('');
                            $tCont.find('.mtc-keep-existing-attachment').val('0');
                            $(tInput).trigger('change');
                            appliedCount++;
                        } catch (err) {
                            console.error('DataTransfer copy failed:', err);
                        }
                    } else if (sourceAttachmentId) {
                        if (tInput) tInput.value = '';
                        $tCont.find('.mtc-copy-attachment-id').val(sourceAttachmentId);
                        $tCont.find('.mtc-keep-existing-attachment').val('0');

                        const $tFileName = $tCont.find('.mtc-file-name');
                        $tFileName.text(sourceFileName).attr('title', sourceFileName);
                        $tCont.find('[data-mtc-empty]').addClass('d-none');
                        $tCont.find('[data-mtc-card]').removeClass('d-none');

                        const $tServerLink = $tCont.find('[data-mtc-server-link]');
                        if (sourceServerLink) {
                            if ($tServerLink.length) {
                                $tServerLink.attr('href', sourceServerLink).removeClass('d-none');
                            } else {
                                $tCont.find('.mtc-file-card__actions').prepend(
                                    '<a href="' + sourceServerLink + '" class="mtc-file-action-link" target="_blank" rel="noopener" title="View attached file" data-mtc-server-link>' +
                                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-external-link"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>' +
                                    ' <span>View</span></a>'
                                );
                            }
                        }
                        appliedCount++;
                    }
                });

                if (window.AdasiToast) {
                    AdasiToast.success('MTC file applied to ' + appliedCount + ' item(s).');
                }
            };

            if (overwriteTargets.length > 0) {
                if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
                    AdasiAlert.confirm({
                        title: 'Overwrite Existing MTC Files?',
                        text: overwriteTargets.length + ' item(s) already have an MTC file attached. Do you want to overwrite them with "' + sourceFileName + '"?',
                        type: 'warning',
                        confirmText: 'Yes, Overwrite',
                        cancelText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            executeApply();
                        }
                    });
                } else {
                    if (confirm(overwriteTargets.length + ' item(s) already have an MTC file attached. Do you want to overwrite them?')) {
                        executeApply();
                    }
                }
            } else {
                executeApply();
            }
        });

        // Notes popover handlers & positioning
        function positionQuotationNotesPopover($popover, trigger) {
            if (!$popover || !$popover.length || $popover.prop('hidden')) return;

            const triggerEl = trigger || $popover.closest('td').find('[data-notes-trigger]')[0];
            if (!triggerEl) return;

            const rect = triggerEl.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) {
                $popover.prop('hidden', true);
                return;
            }

            const $scrollContainer = $popover.closest('.quotation-table-scroll');
            if ($scrollContainer.length) {
                const containerRect = $scrollContainer[0].getBoundingClientRect();
                if (rect.right < containerRect.left + 10 || rect.left > containerRect.right - 10) {
                    $popover.prop('hidden', true);
                    $(triggerEl).attr('aria-expanded', 'false');
                    return;
                }
            }

            if (rect.bottom < 0 || rect.top > window.innerHeight) {
                $popover.prop('hidden', true);
                $(triggerEl).attr('aria-expanded', 'false');
                return;
            }

            const viewportPadding = 8;
            const gap = 4;
            const popoverWidth = Math.min(320, Math.max(280, window.innerWidth - (viewportPadding * 2)));
            const popoverHeight = Math.min($popover.outerHeight() || $popover[0].scrollHeight || 230, 280);

            const fitsBelow = rect.bottom + gap + popoverHeight <= window.innerHeight - viewportPadding;
            const fitsAbove = rect.top - gap - popoverHeight >= viewportPadding;
            const opensAbove = !fitsBelow && fitsAbove;

            let top;
            if (opensAbove) {
                top = Math.max(viewportPadding, rect.top - gap - popoverHeight);
            } else {
                top = Math.min(window.innerHeight - popoverHeight - viewportPadding, rect.bottom + gap);
            }

            let left = rect.right - popoverWidth;
            if (left < viewportPadding) {
                left = Math.max(viewportPadding, rect.left);
            }
            if (left + popoverWidth > window.innerWidth - viewportPadding) {
                left = Math.max(viewportPadding, window.innerWidth - popoverWidth - viewportPadding);
            }

            $popover.css({
                position: 'fixed',
                top: `${Math.round(top)}px`,
                left: `${Math.round(left)}px`,
                bottom: 'auto',
                right: 'auto',
                width: `${popoverWidth}px`,
                zIndex: 1080,
            });
        }

        function repositionVisibleNotesPopovers() {
            $('[data-notes-popover]:not([hidden])').each(function() {
                const $popover = $(this);
                const $trigger = $popover.closest('td').find('[data-notes-trigger]');
                if ($trigger.length) {
                    positionQuotationNotesPopover($popover, $trigger[0]);
                }
            });
        }

        $(window).on('resize', repositionVisibleNotesPopovers);
        document.addEventListener('scroll', repositionVisibleNotesPopovers, true);

        $(document).on('click', '[data-notes-trigger]', function(e) {
            e.stopPropagation();
            const $cell = $(this).closest('td');
            const $popover = $cell.find('[data-notes-popover]');
            const isVisible = !$popover.prop('hidden');

            $('[data-notes-popover]').prop('hidden', true);
            $('[data-notes-trigger]').attr('aria-expanded', 'false');

            if (!isVisible) {
                const currentVal = $cell.find('.quotation-item-notes').val();
                $cell.find('.quotation-notes-draft').val(currentVal);

                $popover.prop('hidden', false);
                $(this).attr('aria-expanded', 'true');
                positionQuotationNotesPopover($popover, this);
                $cell.find('.quotation-notes-draft').focus();
            }
        });

        $(document).on('click', '[data-notes-save]', function(e) {
            e.stopPropagation();
            const $cell = $(this).closest('td');
            const $popover = $cell.find('[data-notes-popover]');
            const draftVal = $cell.find('.quotation-notes-draft').val().trim();
            const $realInput = $cell.find('.quotation-item-notes');
            const $trigger = $cell.find('[data-notes-trigger]');
            const $triggerText = $trigger.find('.quotation-notes-trigger__text');

            $realInput.val(draftVal).trigger('input').trigger('change');

            if (draftVal) {
                $triggerText.text(draftVal);
                $trigger.addClass('has-notes').attr('title', draftVal);
                if (!$trigger.find('.quotation-notes-trigger__badge').length) {
                    $trigger.append('<span class="quotation-notes-trigger__badge" title="Note entered" aria-hidden="true"></span>');
                }
            } else {
                $triggerText.text('Add note...');
                $trigger.removeClass('has-notes').attr('title', 'Click to add notes');
                $trigger.find('.quotation-notes-trigger__badge').remove();
            }

            $popover.prop('hidden', true);
            $trigger.attr('aria-expanded', 'false');
        });

        $(document).on('click', '[data-notes-cancel]', function(e) {
            e.stopPropagation();
            const $cell = $(this).closest('td');
            $cell.find('[data-notes-popover]').prop('hidden', true);
            $cell.find('[data-notes-trigger]').attr('aria-expanded', 'false').focus();
        });

        $(document).on('change input', '.quotation-item-notes', function() {
            const val = $(this).val().trim();
            const $cell = $(this).closest('td');
            const $trigger = $cell.find('[data-notes-trigger]');
            const $triggerText = $trigger.find('.quotation-notes-trigger__text');
            if (val) {
                $triggerText.text(val);
                $trigger.addClass('has-notes').attr('title', val);
                if (!$trigger.find('.quotation-notes-trigger__badge').length) {
                    $trigger.append('<span class="quotation-notes-trigger__badge" title="Note entered" aria-hidden="true"></span>');
                }
            } else {
                $triggerText.text('Add note...');
                $trigger.removeClass('has-notes').attr('title', 'Click to add notes');
                $trigger.find('.quotation-notes-trigger__badge').remove();
            }
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('[data-notes-popover], [data-notes-trigger]').length) {
                $('[data-notes-popover]').prop('hidden', true);
                $('[data-notes-trigger]').attr('aria-expanded', 'false');
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('[data-notes-popover]').prop('hidden', true);
                $('[data-notes-trigger]').attr('aria-expanded', 'false');
            }
        });

        $('#quotationImportModal').on('hidden.bs.modal', function() {
            if (quotationImportRequestInFlight) {
                return;
            }

            document.getElementById('quotationImportFile').value = '';
            $('#quotationImportMode').val('fill_empty');
            updateQuotationImportModeHelp();
            $('#quotationImportResult').addClass('d-none');
            $('#quotationImportWarnings, #quotationImportErrors, #quotationImportPreviewBody').empty();
            $('#btnApplyQuotationImport').prop('disabled', true);
            quotationImportRows = [];
        });

        $('#quotationCurrency').on('change', refreshCurrencyState);

        $(document).on('input change', '.price-input, [data-availability-field="qty"]', function() {
            const $group = $(this).closest('.quotation-item-group');
            validateOfferQuantity($group);
            recalculateQuotationTotals();
        });

        $(document).on('input change', '[data-availability-field="thickness"], [data-availability-field="width"], [data-availability-field="d_outer"], [data-availability-field="d_inner"]', function() {
            const $group = $(this).closest('.quotation-item-group');
            autoCalculateOfferWeight($group);
            recalculateQuotationTotals();
        });

        $(document).on('input', '.offered-weight-input', function() {
            const $group = $(this).closest('.quotation-item-group');
            $group.find('.offered-weight-manual-override').val('1');
            updateOfferWeightState($group);
            recalculateQuotationTotals();
        });

        $(document).on('input', '.offer-length-input', function() {
            const $group = $(this).closest('.quotation-item-group');
            validateOfferLength($group);
            autoCalculateOfferWeight($group);
            recalculateQuotationTotals();
        });

        $(document).on('blur', '.offer-length-input', function() {
            const $group = $(this).closest('.quotation-item-group');
            const parsed = parseOfferLength($(this).val());
            if (parsed.valid && !parsed.empty) {
                $(this).val(formatOfferLength(parsed));
            }
            validateOfferLength($group);
            autoCalculateOfferWeight($group);
            recalculateQuotationTotals();
        });

        $(document).on('click', '[data-availability-toggle]', function(e) {
            e.preventDefault();
            const $group = $(this).closest('.quotation-item-group');
            const $checkbox = $group.find('.item-not-available-toggle');
            const currentlyUnavailable = $checkbox.is(':checked');
            const newUnavailable = !currentlyUnavailable;
            $checkbox.prop('checked', newUnavailable);
            setItemUnavailable($group, newUnavailable);
            recalculateQuotationTotals();
            updateCommercialTermsState();
        });

        $(document).on('change', '.item-not-available-toggle', function() {
            setItemUnavailable($(this).closest('.quotation-item-group'), this.checked);
            recalculateQuotationTotals();
            updateCommercialTermsState();
        });

        refreshCurrencyState();
        $('.quotation-item-group').each(function() {
            const $group = $(this);
            setItemUnavailable($group, itemIsUnavailable($group));
            validateOfferQuantity($group);
            validateOfferLength($group);
        });
        recalculateQuotationTotals();
        updateCommercialTermsState();

        // Horizontal drag-to-scroll for table
        const qScroll = document.querySelector('.quotation-table-scroll');
        if (qScroll) {
            let isDown = false;
            let startX = 0;
            let startScrollLeft = 0;

            qScroll.addEventListener('mousedown', function(e) {
                if (e.button !== 0) return;
                if (e.target.closest('input, select, textarea, button, a, label, [role="button"], .dropdown-menu, .modal')) {
                    return;
                }
                isDown = true;
                startX = e.pageX;
                startScrollLeft = qScroll.scrollLeft;
                qScroll.style.cursor = 'grabbing';
                qScroll.style.userSelect = 'none';
            });

            window.addEventListener('mousemove', function(e) {
                if (!isDown) return;
                e.preventDefault();
                const walk = e.pageX - startX;
                qScroll.scrollLeft = startScrollLeft - walk;
            });

            window.addEventListener('mouseup', function() {
                if (isDown) {
                    isDown = false;
                    qScroll.style.cursor = '';
                    qScroll.style.removeProperty('user-select');
                }
            });
        }
    });

    function validateQuotationRows(requireCompleteOffer) {
        let valid = true;
        let firstInvalid = null;

        $('.quotation-item-group').each(function() {
            const $group = $(this);
            if (itemIsUnavailable($group)) return;

            if (!validateOfferQuantity($group, requireCompleteOffer)) valid = false;
            if (!validateOfferLength($group)) valid = false;

            const $price = $group.find('.price-input');
            const price = Number($price.val());
            const priceValid = String($price.val() ?? '').trim() !== '' && Number.isFinite(price) && price > 0;
            setFieldValidity($price, priceValid, 'Price / KG must be greater than zero for an available item.');
            if (!priceValid) valid = false;

            const $weight = $group.find('.offered-weight-input');
            const weightRaw = String($weight.val() ?? '').trim();
            const weight = Number(weightRaw);
            const weightValid = !requireCompleteOffer || (weightRaw !== '' && Number.isFinite(weight) && weight > 0);
            setFieldValidity($weight, weightValid, 'Offer KG / Unit is required and must be greater than zero for a final available offer.');
            if (!weightValid) valid = false;

            if (!firstInvalid) {
                firstInvalid = $group.find('.is-invalid').first()[0] || null;
            }
        });

        if (!valid) {
            firstInvalid?.focus();
            firstInvalid?.reportValidity?.();
        }

        return valid;
    }

    function submitForm(action) {
        if (action === 'submitted') {
            confirmSubmit();
            return;
        }

        if (!validateQuotationRows(false)) {
            return;
        }

        const form = document.getElementById('quotationForm');
        const activeBtn = document.getElementById('btnSaveDraft');
        if (form && activeBtn) {
            form._lastClickedButton = activeBtn;
        }
        $('#formAction').val(action);
        if (form && typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function confirmSubmit() {
        const allUnavailable = isAllItemsUnavailable();

        if (!allUnavailable) {
            let isValid = true;
            $('#quotationForm').find('input[required], select[required], [data-calendar-required="true"] [data-calendar-native-input], #validityPeriod').each(function() {
                if (!$(this).val()) {
                    isValid = false;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            if (!isValid || !validateQuotationRows(true)) {
                const firstInvalid = document.querySelector('#quotationForm .is-invalid');
                const calendarTrigger = firstInvalid?.closest('[data-adasi-date-picker], [data-adasi-date-range]')?.querySelector('[data-calendar-trigger], [data-calendar-boundary]');
                (calendarTrigger || firstInvalid)?.focus();
                firstInvalid?.reportValidity?.();
                return;
            }

            const form = document.getElementById('quotationForm');
            if (form && !form.checkValidity()) {
                form.reportValidity();
                return;
            }
        }

        const form = document.getElementById('quotationForm');

        AdasiAlert.confirm({
            title: allUnavailable
                ? @json('Submit quotation with all requested items marked Not Available?')
                : {!! json_encode($quotation?->status === 'revision_requested' ? 'Resubmit Quotation?' : 'Send Final Quotation?') !!},
            text: allUnavailable
                ? @json('Requested rows remain visible, but this quotation contains no available commercial offer lines.')
                : {!! json_encode($quotation?->status === 'revision_requested' ? 'The revised quotation will be sent back to Purchasing for evaluation.' : 'Submitted quotations cannot be modified after sending.') !!},
            type: 'warning',
            confirmText: @json($quotation?->status === 'revision_requested' ? 'Yes, Resubmit!' : 'Yes, Send!'),
            cancelText: @json('Cancel')
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem(draftKey);
                const submitBtn = document.getElementById('btnSubmitQuotation');
                if (form && submitBtn) {
                    form._lastClickedButton = submitBtn;
                }
                $('#formAction').val('submitted');
                if (form && typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }

    // Auto-save logic
    const prId = '{{ $pr->id }}';
    const draftKey = 'quotation_draft_' + prId;

    function saveDraft() {
        $('#autoSaveBadge').removeClass('d-none ui-status-chip--success').addClass('ui-status-chip--neutral').html('<span class="spinner-border spinner-border-sm me-1"></span>Saving...');
        
        try {
            const formData = $('#quotationForm').serializeArray();
            const data = {};
            $(formData).each(function(index, obj) {
                if(obj.name !== '_token' && obj.name !== 'action') {
                    data[obj.name] = obj.value;
                }
            });
            localStorage.setItem(draftKey, JSON.stringify(data));

            setTimeout(() => {
                $('#autoSaveBadge').removeClass('ui-status-chip--neutral').addClass('ui-status-chip--success').text('Draft Saved');
                setTimeout(() => {
                    $('#autoSaveBadge').addClass('d-none');
                }, 2000);
            }, 300);
        } catch (e) {
            $('#autoSaveBadge').removeClass('ui-status-chip--neutral ui-status-chip--success').addClass('ui-status-chip--error').text('Save failed');
        }
    }

    function loadDraft() {
        const saved = localStorage.getItem(draftKey);
        if(saved) {
            const data = JSON.parse(saved);
            for(const key in data) {
                const element = $(`[name="${key}"]`);
                if(element.length > 0 && !element.val()) {
                    element.val(data[key]);
                }
            }
            $('.quotation-item-group').each(function() {
                const $group = $(this);
                setItemUnavailable($group, itemIsUnavailable($group));
                validateOfferQuantity($group);
                validateOfferLength($group);
            });
            recalculateQuotationTotals();
            $('#autoSaveBadge').removeClass('d-none ui-status-chip--error ui-status-chip--neutral').addClass('ui-status-chip--success').text('Draft Restored');
            setTimeout(() => $('#autoSaveBadge').addClass('d-none'), 3000);
        }
    }

    $(document).ready(function() {
        loadDraft();

        let isDirty = false;
        let autoSaveTimer;
        $('#quotationForm input, #quotationForm select, #quotationForm textarea').on('input change', function() {
            isDirty = true;
            window.AdasiUnsaved?.markDirty?.();
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraft, 1200);
        });

        $('#quotationForm').on('submit', function() {
            isDirty = false;
            window.AdasiUnsaved?.markClean?.();
        });
    });
</script>
@endpush
