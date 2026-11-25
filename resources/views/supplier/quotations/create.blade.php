@extends('layouts.app')

@section('title', __('supplier.copy.quotation_price_form_adasi_portal'))
@section('page-title', __('supplier.copy.create_quotation'))

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
        /* Decision 09: bounded vertical scroll (shared .ui-table-sticky) so
           the sticky header and Material column work while scrolling. */
        overflow: auto;
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
        /* Single source for column widths: the fixed layout, the table width and the
           sticky offsets all read these, so the sticky columns cannot drift apart. */
        --q-col-number: 48px;
        --q-col-material: 230px;
        --q-col-row-type: 88px;
        --q-col-qty: 120px;
        --q-col-dimension: 110px;
        --q-col-length: 160px;
        --q-col-weight: 145px;
        --q-col-total-weight: 130px;
        --q-col-commercial: 145px;
        --q-col-amount: 160px;
        --q-col-notes: 210px;
        --q-col-mtc: 0px;
        --q-col-availability: 160px;
        --q-table-width: calc(var(--q-col-number) + var(--q-col-material) + var(--q-col-row-type) + var(--q-col-qty)
            + 4 * var(--q-col-dimension) + var(--q-col-length) + var(--q-col-weight) + var(--q-col-total-weight)
            + var(--q-col-commercial) + var(--q-col-amount) + var(--q-col-notes) + var(--q-col-mtc) + var(--q-col-availability));
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: var(--ui-font-size-xs);
        max-width: none !important;
        min-width: var(--q-table-width) !important;
        table-layout: fixed !important;
        /* Exactly the column sum: a wider table would stretch every column and push them under the sticky ones. */
        width: var(--q-table-width) !important;
    }

    .quotation-items-table.has-mtc-column {
        --q-col-mtc: 210px;
    }

    .quotation-items-table th,
    .quotation-items-table td {
        background-color: var(--md-surface);
        border-color: var(--md-outline-variant) !important;
        padding: .38rem .4rem;
        vertical-align: middle !important;
    }

    .quotation-col-number { width: var(--q-col-number); }
    .quotation-col-material { width: var(--q-col-material); }
    .quotation-col-row-type { width: var(--q-col-row-type); }
    .quotation-col-qty { width: var(--q-col-qty); }
    .quotation-col-dimension { width: var(--q-col-dimension); }
    .quotation-col-length { width: var(--q-col-length); }
    .quotation-col-weight { width: var(--q-col-weight); }
    .quotation-col-total-weight { width: var(--q-col-total-weight); }
    .quotation-col-commercial { width: var(--q-col-commercial); }
    .quotation-col-amount { width: var(--q-col-amount); }
    .quotation-col-notes { width: var(--q-col-notes); }
    .quotation-col-mtc { width: var(--q-col-mtc); }
    .quotation-col-availability { width: var(--q-col-availability); }

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
        z-index: 7;
    }

    .quotation-items-table .quotation-sticky-material {
        border-right: 1px solid var(--md-outline) !important;
        left: var(--q-col-number);
        z-index: 7;
    }

    .quotation-items-table .quotation-sticky-row-type {
        border-right: 1px solid var(--md-outline) !important;
        left: calc(var(--q-col-number) + var(--q-col-material));
        z-index: 6;
    }

    /* The footer label spans the three sticky columns, so it stays pinned with them. */
    .quotation-items-table tfoot .quotation-sticky-total {
        background: var(--md-surface-container-low) !important;
        border-right: 1px solid var(--md-outline) !important;
        left: 0;
        position: sticky;
        z-index: 7;
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
<div class="quotation-page tw:grid tw:min-w-0 tw:max-w-full tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.quotation_periods') => route('supplier.quotations.index'),
        ($pr->period->display_label ?? __('purchasing.breadcrumbs.requisitions')) => route('supplier.quotations.period', $pr->period_id),
        ($quotation?->status === 'revision_requested' ? __('purchasing.breadcrumbs.revise_quotation') : __('purchasing.breadcrumbs.submit_quotation')) => null,
    ]" />

    <x-ui.page-header
        :title="$quotation?->status === 'revision_requested' ? __('supplier.audit_ui.revise_quotation') : __('supplier.copy.create_quotation')"
        :eyebrow="__('supplier.copy.supplier_proposal_entry')"
        :description="__($shipmentMtcEnabled ? 'supplier.page.quotation_help_shipment_mtc' : 'supplier.page.quotation_help', ['reference' => $pr->pr_number ?? __('terms.purchase_requisition')])"
    >
        <x-slot:meta>
            @if($quotation?->status === 'revision_requested')
                <span class="ui-status-chip ui-status-chip--warning">
                    <x-ui.icon name="rotate-ccw" size="sm" class="me-1" />{{ __('supplier.copy.revision_requested') }}
                </span>
            @else
                <span class="ui-status-chip ui-status-chip--neutral">
                    <x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('supplier.copy.new_offer') }}
                </span>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('supplier.quotations.period', $pr->period_id)" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier.copy.back_to_requisitions') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Mid-form Cancellation Alert (assertive live region in active non-inert DOM) --}}
    <div
        data-quotation-cancelled-banner
        role="alert"
        tabindex="-1"
        hidden
        class="tw:rounded-ui-md tw:border tw:border-error tw:bg-error-container tw:p-4 tw:text-on-error-container"
    >
        <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
            <div class="tw:flex tw:min-w-0 tw:items-start tw:gap-3">
                <x-ui.icon name="x-circle" size="md" class="tw:mt-0.5 tw:shrink-0" aria-hidden="true" />
                <div class="tw:min-w-0">
                    <p class="tw:m-0 tw:text-ui-sm tw:font-semibold">{{ __('supplier_requests.availability.banner_title') }}</p>
                    <p data-quotation-cancelled-message class="tw:m-0 tw:mt-1 tw:text-ui-xs">{{ __('supplier_requests.availability.pr_cancelled_banner') }}</p>
                </div>
            </div>
            <x-ui.button
                :href="route('supplier.requests.show', $pr)"
                variant="outline"
                size="sm"
                data-quotation-cancelled-link
            >
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_requests.availability.view_summary_action') }}</span>
            </x-ui.button>
        </div>
    </div>

    {{-- Main Sectioned Form --}}
    <form
        id="quotationForm"
        class="quotation-form"
        action="{{ route('supplier.quotations.store', $pr) }}"
        method="POST"
        enctype="multipart/form-data"
        data-async-submit
        data-quotation-availability-guard
        data-pr-hash="{{ $pr->hash }}"
        data-availability-url="{{ route('supplier.quotations.availability', $pr) }}"
        data-detail-url="{{ route('supplier.requests.show', $pr) }}"
        data-draft-key="quotation_draft_{{ (int) auth()->id() }}_{{ $pr->hash }}"
        data-pr-cancelled-message="{{ __('supplier_requests.availability.pr_cancelled_banner') }}"
        data-invitation-cancelled-message="{{ __('supplier_requests.availability.invitation_cancelled_banner') }}"
        data-fallback-cancelled-message="{{ __('supplier_requests.availability.pr_cancelled_banner') }}"
    >
        @csrf
        <input type="hidden" name="action" id="formAction" value="draft">

        <div class="tw:grid tw:gap-5">
            {{-- Section 1: Procurement Context --}}
            <x-ui.form-section
                :title="__('supplier.copy.procurement_context')"
                :description="__('supplier.copy.general_requisition_parameters_and_notes_established_by_adasi_purchasing')"
            >
                <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-3">
                    <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.procurement_period') }}</div>
                        <div class="fw-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $pr->period->display_label }}</div>
                    </div>
                    <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.requisition_reference') }}</div>
                        <div class="fw-bold text-primary tw:text-ui-sm tw:mt-0.5">{{ $pr->pr_number ?? 'PR -' }}</div>
                    </div>
                    <div class="tw:sm:col-span-2 tw:lg:col-span-1 tw:p-2.5 tw:bg-surface-low border rounded">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.purchasing_instructions') }}</div>
                        <div class="tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $pr->notes ?: __('supplier.copy.no_additional_notes_provided') }}</div>
                    </div>
                </div>

                <div class="tw:mt-3">
                    @include('partials.procurement-logistics', ['port' => $pr->port_of_discharge, 'incoterm' => $pr->incoterm])
                </div>
            </x-ui.form-section>

            {{-- Section 2: Commercial Terms & Logistics --}}
            <x-ui.form-section
                :title="__('supplier.copy.commercial_terms_and_logistics')"
                :description="__('supplier.copy.specify_estimated_delivery_timeline_proposal_validity_duration_and_payment_arrangements')"
            >
                <x-ui.alert id="allUnavailableTermsNotice" tone="info" class="tw:mb-4 d-none">
                    {{ __('common.final_review.all_unavailable') }}
                </x-ui.alert>

                <div class="tw:grid tw:gap-4 tw:sm:grid-cols-2">
                    <x-ui.date-picker
                        name="estimated_delivery"
                        :label="__('supplier.copy.supplier_estimate_delivery')"
                        :value="optional($quotation?->estimated_delivery)->format('Y-m-d')"
                        :helper="__('supplier.copy.estimated_date_material_will_be_ready_for_dispatch_from_your_facility')"
                        required
                    />
                    <x-ui.date-picker
                        name="validity_period"
                        id="validityPeriod"
                        :label="__('supplier.copy.quotation_valid_until')"
                        :value="optional($quotation?->validity_period)->format('Y-m-d')"
                        :min="now()->toDateString()"
                        :helper="__('supplier.copy.required_for_final_submission_prices_remain_firm_until_this_date')"
                    />
                    <x-ui.textarea
                        name="payment_terms"
                        :label="__('supplier.copy.payment_terms')"
                        :rows="2"
                        maxlength="100"
                        required
                        :placeholder="__('supplier.copy.e_g_t_t_30_days_after_b_l_date_l_c_at_sight_cad')"
                        :value="$quotation->payment_terms ?? 'TT 30 Days'"
                    />
                    <x-ui.textarea
                        name="general_notes"
                        :label="__('supplier.copy.general_supplier_notes')"
                        :rows="2"
                        :placeholder="__('supplier.copy.e_g_cif_tanjung_priok_sea_freight_in_wooden_cases_valid_14_days')"
                        :value="$quotation->general_notes ?? ''"
                    />
                </div>
            </x-ui.form-section>

            {{-- Section 3: Status & Follow-up --}}
            <x-ui.form-section
                :title="__('procurement.quotation.status_follow_up')"
                :description="__('procurement.quotation.status_follow_up_description')"
                data-quotation-section="status"
            >
                <div class="d-flex flex-wrap align-items-center tw:gap-2">
                    <span class="tw:text-ui-xs fw-semibold tw:uppercase tw:text-on-surface-variant">{{ __('procurement.quotation.current_status') }}</span>
                    @if($quotation)
                        <x-status-badge type="quotation" :status="$quotation->status" />
                    @else
                        <span class="ui-status-chip ui-status-chip--neutral">{{ __('procurement.quotation.not_yet_submitted') }}</span>
                    @endif
                </div>
                @if($quotation?->status === 'revision_requested')
                    <x-ui.alert tone="warning" :title="__('supplier.copy.quotation_revision_requested')" class="tw:mt-3">
                        {{ __('supplier.copy.purchasing_asked_this_quotation_to_be_resubmitted_update_unit_prices_delivery_schedule_validity_date') }}
                    </x-ui.alert>
                @endif
                @if($quotation?->reviewer_notes)
                    <div class="tw:mt-3 tw:p-2.5 bg-warning-subtle border border-warning-subtle rounded tw:text-ui-xs text-warning-emphasis">
                        <span class="fw-bold d-block tw:mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('supplier.copy.purchasing_reviewer_notes') }}</span>
                        {{ $quotation->reviewer_notes }}
                    </div>
                @elseif($quotation?->status !== 'revision_requested')
                    <p class="tw:m-0 tw:mt-2 tw:text-ui-xs tw:text-on-surface-variant">{{ __('procurement.quotation.no_follow_up') }}</p>
                @endif
            </x-ui.form-section>

            {{-- Section 4: Detail Quotation (material pricing & availability) --}}
            <x-ui.form-section
                class="quotation-material-section"
                :title="__('supplier.copy.material_commercial_offer')"
                :description="__($shipmentMtcEnabled ? 'supplier.copy.specify_your_availability_dimensions_and_price_per_kg_shipment_mtc' : 'supplier.copy.specify_your_availability_dimensions_price_per_kg_and_attach_mtc_certificates')"
            >
                <x-slot:actions>
                    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                        {{-- Autosave Indicator --}}
                        <span id="autoSaveBadge" class="ui-status-chip ui-status-chip--success d-none">
                            <x-ui.icon name="check" size="sm" class="me-1" />{{ __('supplier.copy.draft_saved') }}
                        </span>

                        {{-- Import Controls --}}
                        @include('supplier.quotations._import_controls')

                        {{-- Copy All Requested --}}
                        <x-ui.button type="button" id="copyAllRequested" variant="outline" size="sm">
                            <x-ui.icon name="clipboard-check" size="sm" />
                            <span>{{ __('supplier.copy.copy_all_requested_values') }}</span>
                        </x-ui.button>

                        {{-- Currency Select --}}
                        <div class="d-flex align-items-center tw:gap-1.5 ps-2 border-start">
                            <label for="quotationCurrency" class="small fw-semibold tw:text-on-surface mb-0">{{ __('supplier.copy.currency_b66c8f') }}</label>
                            <select name="currency" id="quotationCurrency" class="form-select form-select-sm fw-bold @error('currency') is-invalid @enderror" style="width: 100px;" required>
                                <option value="" disabled @selected($supplierCurrency === '')>{{ __('supplier.copy.select') }}</option>
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

                <x-ui.alert id="currencyRateWarning" tone="warning" :title="__('supplier.copy.exchange_rate_required')" class="tw:mb-2 {{ $supplierCurrency && ! $supplierRate ? '' : 'd-none' }}">
                    {{ __('supplier.copy.exchange_rate_for') }} <strong id="currencyWarningLabel">{{ $supplierCurrency ?: '-' }}</strong> {{ __('supplier.copy.is_not_recorded_in_master_exchange_rates_contact_admin_before_final_submission') }}
                </x-ui.alert>

                @if($surplusOffersAllowed)
                    <x-ui.alert tone="info" class="tw:mb-2">{{ __('procurement.quotation.surplus_hint') }}</x-ui.alert>
                @endif
                @php
                    $quotationDimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
                    $quotationScalarDimensionOrder = array_values(array_filter(
                        $quotationDimensionOrder,
                        static fn (string $field): bool => $field !== 'length',
                    ));
                @endphp

                {{-- Material Entry Table --}}
                <div class="border rounded overflow-hidden">
                    <div id="quotationTableScrollHint" class="tw:bg-surface-low border-bottom px-3 tw:py-1.5 tw:text-on-surface-variant tw:text-ui-xs d-flex align-items-center tw:gap-1.5">
                        <x-ui.icon name="move-horizontal" size="sm" />
                        <span>{{ __('supplier.copy.scroll_horizontally_to_compare_requested_and_offer_values_for_each_material') }}</span>
                    </div>

                    <div class="table-responsive quotation-table-scroll ui-table-sticky" role="region" tabindex="0" aria-label="{{ __('supplier.copy.material_quotation_price_entry') }}" aria-describedby="quotationTableScrollHint">
                        <table class="table table-bordered align-middle mb-0 quotation-items-table tw:text-ui-xs @unless($shipmentMtcEnabled) has-mtc-column @endunless">
                            <caption class="visually-hidden">{{ __('supplier.copy.supplier_quotation_entry_table_with_paired_requested_and_offer_rows_for_each_material') }}</caption>
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
                                @unless($shipmentMtcEnabled)
                                <col class="quotation-col-mtc">
                                @endunless
                                <col class="quotation-col-availability">
                            </colgroup>
                            <thead class="table-light text-center">
                                <tr class="quotation-field-header">
                                    <th scope="col" class="quotation-sticky-number">{{ __('supplier.copy.no') }}</th>
                                    <th scope="col" class="quotation-sticky-material ui-table-sticky-col">{{ __('supplier.copy.material') }}</th>
                                    <th scope="col" class="quotation-sticky-row-type">{{ __('supplier.copy.row_type') }}</th>
                                    <th scope="col">{{ __('supplier.copy.qty') }}</th>
                                    @foreach($quotationDimensionOrder as $dimensionField)
                                        <th scope="col">{{ \App\Models\PrItem::dimensionLabel($dimensionField) }}</th>
                                    @endforeach
                                    <th scope="col">{{ __('supplier.copy.kg_per_unit') }}</th>
                                    <th scope="col">{{ __('supplier.copy.total_kg') }}</th>
                                    <th scope="col">{{ __('supplier.copy.price_kg') }}<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                                    <th scope="col">{{ __('supplier.copy.amount') }}<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                                    <th scope="col">{{ __('supplier.copy.notes') }}</th>
                                    @unless($shipmentMtcEnabled)
                                    <th scope="col">MTC</th>
                                    @endunless
                                    <th scope="col">{{ __('supplier.copy.availability') }}</th>
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
                                        <td rowspan="2" class="text-center quotation-sticky-number tw:text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                        <td rowspan="2" class="quotation-sticky-material ui-table-sticky-col align-top">
                                            <div class="fw-bold tw:text-on-surface">{{ $item->material_name }}</div>
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">
                                                {{ $item->hs_code ? 'HS '.$item->hs_code.' · ' : '' }}{{ $item->shape ?: '-' }}
                                            </div>
                                            @if($item->remark)
                                                <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-1">{{ $item->remark }}</div>
                                            @endif
                                            <x-ui.button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="copy-from-pr-btn tw:mt-2"
                                                data-requested-qty="{{ $item->quantity_value }}"
                                                data-requested-thickness="{{ $item->thickness !== null ? (float) $item->thickness : '' }}"
                                                data-requested-d-outer="{{ $item->d_outer !== null ? (float) $item->d_outer : '' }}"
                                                data-requested-d-inner="{{ $item->d_inner !== null ? (float) $item->d_inner : '' }}"
                                                data-requested-width="{{ $item->width !== null ? (float) $item->width : '' }}"
                                                data-requested-length="{{ $item->length !== null ? (float) $item->length : '' }}"
                                                data-requested-weight="{{ $item->weight_needed }}"
                                                :title="__('supplier.copy.copy_requested_quantity_dimensions_and_kg_unit_into_the_offer_row')"
                                            >
                                                <x-ui.icon name="clipboard-check" size="sm" /> {{ __('supplier.copy.copy_requested') }}
                                            </x-ui.button>
                                            <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                            <input type="hidden" name="items[{{ $index }}][is_available]" class="item-availability-input" value="{{ $itemIsAvailable ? '1' : '0' }}">
                                            @error("items.{$index}.pr_item_id")
                                                <div class="text-danger small tw:mt-1">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td class="quotation-sticky-row-type"><span class="quotation-row-label">{{ __('supplier.copy.requested') }}</span></td>
                                        <td class="text-center fw-semibold ui-tabular-nums">{{ $item->quantity_value }}</td>
                                        @foreach($quotationScalarDimensionOrder as $dimension)
                                            <td class="text-end ui-tabular-nums tw:text-on-surface-variant">
                                                {{ in_array($dimension, $relevantDimensions, true) ? \App\Support\NumberFormat::maxDecimals($item->{$dimension}) : '—' }}
                                            </td>
                                        @endforeach
                                        <td class="text-end ui-tabular-nums tw:text-on-surface-variant">
                                            {{ in_array('length', $relevantDimensions, true) ? \App\Support\NumberFormat::maxDecimals($item->length) : '—' }}
                                        </td>
                                        <td class="text-end ui-tabular-nums tw:text-on-surface-variant">{{ \App\Support\NumberFormat::maxDecimals($item->weight_needed) }}</td>
                                        <td class="text-end fw-semibold ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->total_weight) }}</td>
                                        <td class="text-end ui-tabular-nums requested-price-display">—</td>
                                        <td class="text-end ui-tabular-nums">
                                            <span class="requested-amount-display quotation-calculated" aria-live="polite">—</span>
                                        </td>
                                        <td class="text-start tw:text-on-surface-variant">{{ $item->remark ?: '—' }}</td>
                                        @unless($shipmentMtcEnabled)
                                        <td class="text-center tw:text-on-surface-variant">—</td>
                                        @endunless
                                        <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ __('supplier.copy.requested') }}</span></td>
                                    </tr>
                                    <tr class="quotation-offer-row {{ $itemIsAvailable ? '' : 'is-unavailable' }}">
                                        <td class="quotation-sticky-row-type"><span class="quotation-row-label quotation-row-label--offer">{{ __('supplier.copy.offer') }}</span></td>
                                        <td>
                                            <input
                                                id="availableQty{{ $index }}"
                                                type="number"
                                                min="1"
                                                @unless($surplusOffersAllowed) max="{{ $item->quantity_value }}" @endunless
                                                step="1"
                                                name="items[{{ $index }}][available_qty]"
                                                class="form-control form-control-sm availability-input offer-disable-when-unavailable quotation-editable text-end ui-tabular-nums @error("items.{$index}.available_qty") is-invalid @enderror"
                                                data-availability-field="qty"
                                                value="{{ old("items.{$index}.available_qty", $qItem?->available_qty) }}"
                                                aria-label="{{ $surplusOffersAllowed
                                                    ? __('procurement.quotation.offer_quantity_a11y', ['material' => $item->material_name, 'requested' => $item->quantity_value])
                                                    : __('supplier.a11y.offer_quantity', ['material' => $item->material_name, 'maximum' => $item->quantity_value]) }}"
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
                                                        aria-label="{{ __('supplier.a11y.offer_dimension', ['dimension' => \App\Models\PrItem::dimensionLabel($dimension), 'material' => $item->material_name]) }}"
                                                    >
                                                    @error("items.{$index}.available_{$dimension}")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                @else
                                                    <div class="text-center tw:text-on-surface-variant" aria-label="{{ __('supplier.copy.not_applicable') }}">—</div>
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
                                                    placeholder="{{ __('supplier.copy.length_placeholder') }}"
                                                    aria-label="{{ __('supplier.a11y.offer_length', ['material' => $item->material_name]) }}"
                                                    aria-describedby="availableLengthHelp{{ $index }} availableLengthFeedback{{ $index }}"
                                                >
                                                <div id="availableLengthHelp{{ $index }}" class="quotation-value-secondary">{{ __('supplier.copy.exact_or_min_max') }}</div>
                                                <div id="availableLengthFeedback{{ $index }}" class="invalid-feedback offer-length-feedback">
                                                    @error("items.{$index}.available_length_input"){{ $message }}@enderror
                                                </div>
                                            @else
                                                <div class="text-center tw:text-on-surface-variant" aria-label="{{ __('supplier.copy.not_applicable') }}">—</div>
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
                                                    aria-label="{{ __('supplier.a11y.offer_weight', ['material' => $item->material_name]) }}"
                                                >
                                                <span class="ui-status-chip ui-status-chip--warning offer-weight-indicator {{ $manualWeightOverride ? '' : 'd-none' }}">{{ __('supplier.copy.est_weight') }}</span>
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
                                                aria-label="{{ __('supplier.a11y.price_weight', ['material' => $item->material_name]) }}"
                                                placeholder="{{ __('supplier.copy.e_g_4_2500') }}"
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
                                                aria-label="{{ __('supplier.a11y.offer_notes', ['material' => $item->material_name]) }}"
                                                tabindex="-1"
                                            >{{ $currentNote }}</textarea>

                                            <button
                                                type="button"
                                                class="quotation-notes-trigger ui-motion ui-focus-ring @error("items.{$index}.notes") is-invalid @enderror {{ !empty($currentNote) ? 'has-notes' : '' }}"
                                                data-notes-trigger
                                                aria-haspopup="dialog"
                                                aria-expanded="false"
                                                aria-label="{{ __('supplier.a11y.edit_notes', ['material' => $item->material_name]) }}"
                                                title="{{ $currentNote ?: __('supplier.copy.click_to_add_notes') }}"
                                            >
                                                <x-ui.icon name="file-text" size="sm" class="quotation-notes-trigger__icon" aria-hidden="true" />
                                                <span class="quotation-notes-trigger__text text-truncate">{{ $currentNote ?: __('supplier.copy.add_note') }}</span>
                                                @if(!empty($currentNote))
                                                    <span class="quotation-notes-trigger__badge" title="{{ __('supplier.copy.note_entered') }}" aria-hidden="true"></span>
                                                @endif
                                            </button>
                                            @error("items.{$index}.notes")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                                            <div class="quotation-notes-popover" data-notes-popover hidden role="dialog" aria-modal="false" aria-label="{{ __('supplier.a11y.offer_notes', ['material' => $item->material_name]) }}">
                                                <div class="quotation-notes-popover__header">
                                                    <div>
                                                        <span class="quotation-notes-popover__title">{{ __('supplier.copy.offer_notes') }}</span>
                                                        <span class="quotation-notes-popover__subtitle text-truncate">{{ $item->material_name }}</span>
                                                    </div>
                                                    <button type="button" class="quotation-notes-popover__close" data-notes-cancel aria-label="{{ __('supplier.copy.close_notes_popover') }}">
                                                        <x-ui.icon name="x" size="sm" />
                                                    </button>
                                                </div>
                                                <div class="quotation-notes-popover__body">
                                                    <textarea
                                                        class="form-control form-control-sm quotation-notes-draft"
                                                        rows="4"
                                                        placeholder="{{ __('supplier.copy.e_g_mill_tolerance_0_5mm_prime_grade_mtc_included') }}"
                                                        aria-label="{{ __('supplier.a11y.draft_notes', ['material' => $item->material_name]) }}"
                                                    >{{ $currentNote }}</textarea>
                                                    <div class="quotation-notes-popover__hint">{{ __('supplier.copy.provide_tolerances_specs_or_availability_details') }}</div>
                                                </div>
                                                <div class="quotation-notes-popover__footer">
                                                    <x-ui.button type="button" variant="ghost" size="sm" class="quotation-notes-btn-cancel" data-notes-cancel>{{ __('supplier.copy.cancel') }}</x-ui.button>
                                                    <x-ui.button type="button" variant="primary" size="sm" class="quotation-notes-btn-save" data-notes-save>
                                                        <x-ui.icon name="check" size="sm" class="me-1" /> {{ __('supplier.copy.save') }}
                                                    </x-ui.button>
                                                </div>
                                            </div>
                                        </td>
                                        @unless($shipmentMtcEnabled) {{-- Mission 05: MTC moved to the shipment --}}
                                        <td>
                                            @php $hasMtc = !empty($mtcAttachment); @endphp
                                            <div class="mtc-upload-container @error("items.{$index}.mtc_file") border-danger @enderror" data-mtc-container data-existing-attachment-id="{{ $mtcAttachment?->id ?? '' }}">
                                                <input
                                                    id="mtcFile{{ $index }}"
                                                    type="file"
                                                    name="items[{{ $index }}][mtc_file]"
                                                    class="visually-hidden mtc-file-input offer-disable-when-unavailable @error("items.{$index}.mtc_file") is-invalid @enderror"
                                                    accept=".pdf,.jpg,.jpeg,.png"
                                                    aria-label="{{ __('supplier.a11y.mtc_file', ['material' => $item->material_name]) }}"
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
                                                    <label for="mtcFile{{ $index }}" class="mtc-file-label ui-motion ui-focus-ring" title="{{ __('supplier.copy.accepted_files_pdf_jpg_or_png_maximum_5mb') }}">
                                                        <x-ui.icon name="paperclip" size="sm" class="me-1.5" />
                                                        <span>{{ __('supplier.copy.upload_mtc') }}</span>
                                                    </label>
                                                    <div id="mtcFileHelp{{ $index }}" class="mtc-file-hint">{{ __('supplier.copy.pdf_jpg_png_max_5mb') }}</div>
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
                                                            <a href="{{ route('attachments.show', $mtcAttachment) }}" class="mtc-file-action-link" target="_blank" rel="noopener" title="{{ __('supplier.copy.view_attached_file') }}" data-mtc-server-link>
                                                                <x-ui.icon name="external-link" size="sm" />
                                                                <span>{{ __('supplier.copy.view') }}</span>
                                                            </a>
                                                        @endif
                                                        <div class="dropdown d-inline-block mtc-copy-dropdown">
                                                            <button
                                                                type="button"
                                                                class="mtc-file-action-btn"
                                                                data-bs-toggle="dropdown"
                                                                data-bs-display="static"
                                                                aria-expanded="false"
                                                                title="{{ __('supplier.copy.copy_mtc_to_other_items') }}"
                                                                data-mtc-copy-trigger
                                                            >
                                                                <x-ui.icon name="copy" size="sm" />
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.75rem; min-width: 220px; z-index: 1060;">
                                                                <li>
                                                                    <button type="button" class="dropdown-item py-1.5 px-3 d-flex align-items-center gap-1.5" data-mtc-apply="same_material">
                                                                        <x-ui.icon name="layers" size="sm" class="tw:text-on-surface-variant flex-shrink-0" />
                                                                        <span>{{ __('supplier.copy.apply_to_same_material') }}<strong class="text-truncate d-inline-block align-bottom" style="max-width: 105px;">{{ $item->material_name }}</strong>)</span>
                                                                    </button>
                                                                </li>
                                                                <li>
                                                                    <button type="button" class="dropdown-item py-1.5 px-3 d-flex align-items-center gap-1.5" data-mtc-apply="all_available">
                                                                        <x-ui.icon name="check-check" size="sm" class="tw:text-on-surface-variant flex-shrink-0" />
                                                                        <span>{{ __('supplier.copy.apply_to_all_available_items') }}</span>
                                                                    </button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                        <label for="mtcFile{{ $index }}" class="mtc-file-action-btn" title="{{ __('supplier.copy.change_file') }}" role="button">
                                                            <x-ui.icon name="refresh-cw" size="sm" />
                                                        </label>
                                                        <button type="button" class="mtc-file-action-btn text-danger" title="{{ __('supplier.copy.remove_file') }}" data-mtc-remove aria-label="{{ __('supplier.copy.remove_selected_mtc_file') }}">
                                                            <x-ui.icon name="x" size="sm" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            @error("items.{$index}.mtc_file")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        </td>
                                        @endunless
                                        <td>
                                            <div class="availability-control">
                                                <button
                                                    type="button"
                                                    class="availability-toggle ui-motion ui-focus-ring {{ $itemIsAvailable ? 'is-available' : 'is-unavailable' }}"
                                                    data-availability-toggle
                                                    role="switch"
                                                    aria-checked="{{ $itemIsAvailable ? 'true' : 'false' }}"
                                                    aria-label="{{ __('supplier.a11y.toggle_availability', ['material' => $item->material_name]) }}"
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
                                                        {{ $itemIsAvailable ? __('status.availability.available') : __('supplier.copy.not_available') }}
                                                    </span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach
                            <tfoot class="table-light fw-bold border-top">
                                <tr>
                                    <th scope="row" colspan="3" class="text-end tw:text-on-surface quotation-sticky-total">{{ __('procurement.quotation.total_offered') }}</th>
                                    <td class="text-end tw:text-on-surface ui-tabular-nums">
                                        <span id="totalOfferedQty" aria-label="{{ __('procurement.quotation.total_offered_qty') }}">0</span>
                                    </td>
                                    <td colspan="6"></td>
                                    <td class="text-end tw:text-on-surface ui-tabular-nums">
                                        <span id="totalOfferedKg" aria-label="{{ __('procurement.quotation.total_offered_kg') }}">0</span>
                                    </td>
                                    <td class="text-end tw:text-on-surface">{{ __('supplier.copy.total_offer_amount') }}</td>
                                    <td class="text-end tw:text-on-surface ui-tabular-nums">
                                        <span id="totalAmount">0</span>
                                    </td>
                                    <td colspan="{{ $shipmentMtcEnabled ? 2 : 3 }}"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </x-ui.form-section>

        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw:mt-6">
            <x-slot:left>
                <x-ui.button :href="route('supplier.quotations.period', $pr->period_id)" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>{{ __('supplier.copy.cancel') }}</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" variant="outline" size="sm" id="btnSaveDraft" onclick="submitForm('draft')">
                    <span>{{ $quotation?->status === 'revision_requested' ? __('supplier.copy.save_revision_draft') : __('supplier.copy.save_draft') }}</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnSubmitQuotation" onclick="confirmSubmit()">
                    <x-ui.icon name="send" size="sm" />
                    <span>{{ $quotation?->status === 'revision_requested' ? __('supplier.copy.resubmit_quotation') : __('supplier.copy.submit_final_quotation') }}</span>
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
                    <h6 class="modal-title fw-bold" id="quotationImportModalLabel">{{ __('supplier.copy.import_quotation_items_from_spreadsheet') }}</h6>
                    <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ __('supplier.copy.imported_values_are_mapped_by_pr_item_id_and_are_not_committed_to_database_until_saved') }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('supplier.copy.close') }}"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="quotationImportFile" class="form-label small fw-semibold tw:text-on-surface">{{ __('supplier.copy.spreadsheet_file_xlsx_xls_csv') }}</label>
                        <input type="file" id="quotationImportFile" class="form-control form-control-sm" accept=".xlsx,.xls,.csv">
                        <div class="form-text tw:text-ui-xs">{{ __('supplier.copy.use_the_template_for_this_pr_max_10_mb_and_1_000_data_rows') }}</div>
                    </div>
                    <div class="col-md-5">
                        <label for="quotationImportMode" class="form-label small fw-semibold tw:text-on-surface">{{ __('supplier.copy.import_mode') }}</label>
                        <select id="quotationImportMode" class="form-select form-select-sm" aria-describedby="quotationImportModeHelp">
                            <option value="fill_empty" selected>{{ __('supplier.copy.fill_empty_fields_only') }}</option>
                            <option value="replace">{{ __('supplier.copy.replace_imported_fields') }}</option>
                        </select>
                        <div id="quotationImportModeHelp" class="form-text tw:text-ui-xs" aria-live="polite"></div>
                    </div>
                </div>

                <div id="quotationImportResult" class="d-none mt-3">
                    <div id="quotationImportSummary" class="tw:mb-2.5 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface-container tw:px-3 tw:py-2 tw:text-ui-xs tw:font-semibold tw:text-on-surface" role="status"></div>

                    <div id="quotationImportWarningsPanel" class="d-none tw:mb-2.5 tw:rounded-ui-sm tw:border-s-4 tw:border-warning tw:bg-warning-container tw:px-3 tw:py-2 tw:text-ui-xs tw:text-warning-container-foreground" role="status">
                        <div class="fw-bold mb-1"><x-ui.icon name="triangle-alert" size="sm" class="me-1" />{{ __('supplier.copy.warnings') }}</div>
                        <ul id="quotationImportWarnings" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="quotationImportErrorsPanel" class="d-none tw:mb-2.5 tw:rounded-ui-sm tw:border-s-4 tw:border-error tw:bg-error-container tw:px-3 tw:py-2 tw:text-ui-xs tw:text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1"><x-ui.icon name="circle-x" size="sm" class="me-1" />{{ __('supplier.copy.import_errors') }}</div>
                        <ul id="quotationImportErrors" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="quotationImportPreviewPanel" class="d-none">
                        <div class="fw-bold tw:text-on-surface tw:text-ui-xs tw:mb-1.5">{{ __('supplier.copy.parsed_item_preview') }}</div>
                        <div class="table-responsive border rounded tw:max-h-64">
                            <table class="table table-sm table-striped align-middle mb-0 tw:text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        {{-- Match canonical template order: ID, Material, Dimensions, Offer Qty, KG/Unit, Price, Notes, Availability --}}
                                        <th scope="col">{{ __('supplier.copy.pr_item_id') }}</th>
                                        <th scope="col">{{ __('supplier.copy.material') }}</th>
                                        @foreach($quotationDimensionOrder as $dimensionField)
                                            <th scope="col">{{ \App\Models\PrItem::dimensionLabel($dimensionField) }}</th>
                                        @endforeach
                                        <th scope="col">{{ __('supplier.copy.offer_qty') }}</th>
                                        <th scope="col">{{ __('supplier.copy.offer_kg_unit') }}</th>
                                        <th scope="col">{{ __('supplier.copy.price_kg') }}</th>
                                        <th scope="col">{{ __('supplier.copy.notes') }}</th>
                                        <th scope="col">{{ __('supplier.copy.availability') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="quotationImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer tw:bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('supplier.copy.cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseQuotationImport">
                    <span class="ui-spinner me-1 d-none" id="quotationImportSpinner" aria-hidden="true"></span>
                    {{ __('supplier.copy.parse_validate') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnApplyQuotationImport" disabled>
                    <x-ui.icon name="circle-check" size="sm" class="me-1" /> {{ __('supplier.copy.apply_to_form') }}
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
    const quotationCsrfToken = @json(csrf_token());
    // UI hint only; the server enforces the effective surplus flag.
    const quotationSurplusOffersAllowed = @json($surplusOffersAllowed);
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

    function resolveQuotationGroup(group) {
        if (group instanceof Element) {
            return group;
        }
        if (group && !(group instanceof Element) && group[0] instanceof Element) {
            return group[0];
        }
        return null;
    }

    function dispatchFieldInputAndChange(input) {
        if (!input) return;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function quotationRowForPrItem(prItemId) {
        return document.querySelector(`.quotation-item-group[data-pr-item-id="${CSS.escape(String(prItemId))}"]`);
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
            ? (entry.column
                ? window.AdasiI18n.t('js.import.row_column', { row: entry.row, column: entry.column })
                : window.AdasiI18n.t('js.import.row', { row: entry.row }))
            : (entry.column || window.AdasiI18n.t('js.import.file'));

        return `${location}: ${entry.message}`;
    }

    function renderQuotationImportResult(payload) {
        const summary = payload.summary || { total: 0, valid: 0, invalid: 0 };
        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        const errors = Array.isArray(payload.errors) ? payload.errors : [];
        const rows = Array.isArray(payload.rows) ? payload.rows : [];

        document.getElementById('quotationImportResult')?.classList.remove('d-none');
        const summaryEl = document.getElementById('quotationImportSummary');
        if (summaryEl) {
            summaryEl.textContent = window.AdasiI18n.t('supplier.js.import_summary', {
                total: summary.total || 0,
                valid: summary.valid || 0,
                invalid: summary.invalid || 0,
            });
        }

        const warningsEl = document.getElementById('quotationImportWarnings');
        if (warningsEl) {
            warningsEl.replaceChildren();
            warnings.forEach((warning) => {
                const li = document.createElement('li');
                li.textContent = formatQuotationImportMessage(warning);
                warningsEl.appendChild(li);
            });
        }
        document.getElementById('quotationImportWarningsPanel')?.classList.toggle('d-none', warnings.length === 0);

        const errorsEl = document.getElementById('quotationImportErrors');
        if (errorsEl) {
            errorsEl.replaceChildren();
            errors.forEach((error) => {
                const li = document.createElement('li');
                li.textContent = formatQuotationImportMessage(error);
                errorsEl.appendChild(li);
            });
        }
        document.getElementById('quotationImportErrorsPanel')?.classList.toggle('d-none', errors.length === 0);

        const previewEl = document.getElementById('quotationImportPreviewBody');
        // Material comes from this form's own PR rows, never from the uploaded file.
        const materialFor = (prItemId) => {
            const matchingInput = Array.from(document.querySelectorAll('input[name$="[pr_item_id]"]'))
                .find((input) => String(input.value) === String(prItemId));
            const label = matchingInput
                ?.closest('.quotation-sticky-material')
                ?.querySelector('.fw-bold')
                ?.textContent
                ?.trim();
            return label || '-';
        };

        if (previewEl) {
            previewEl.replaceChildren();
            rows.forEach((row) => {
                const tableRow = document.createElement('tr');
                [
                    row.pr_item_id,
                    materialFor(row.pr_item_id),
                    row.available_thickness ?? '-',
                    row.available_width ?? '-',
                    row.available_d_outer ?? '-',
                    row.available_d_inner ?? '-',
                    importedLengthValue(row) || '-',
                    row.available_qty ?? '-',
                    row.offered_weight_per_unit ?? '-',
                    row.price_per_kg ?? '-',
                    row.notes ?? '-',
                    row.is_available === false ? @js(__('supplier.copy.not_available')) : @js(__('supplier.copy.available'))
                ].forEach((value) => {
                    const td = document.createElement('td');
                    td.textContent = value;
                    tableRow.appendChild(td);
                });
                previewEl.appendChild(tableRow);
            });
        }
        document.getElementById('quotationImportPreviewPanel')?.classList.toggle('d-none', rows.length === 0);

        quotationImportRows = payload.success === true ? rows : [];
        const applyBtn = document.getElementById('btnApplyQuotationImport');
        if (applyBtn) applyBtn.disabled = payload.success !== true || rows.length === 0;
    }

    function setQuotationImportBusy(isBusy) {
        quotationImportRequestInFlight = isBusy;
        const parseBtn = document.getElementById('btnParseQuotationImport');
        const fileEl = document.getElementById('quotationImportFile');
        const modeEl = document.getElementById('quotationImportMode');
        if (parseBtn) parseBtn.disabled = isBusy;
        if (fileEl) fileEl.disabled = isBusy;
        if (modeEl) modeEl.disabled = isBusy;
        document.getElementById('quotationImportSpinner')?.classList.toggle('d-none', !isBusy);
    }

    async function parseQuotationImport() {
        if (quotationImportRequestInFlight) {
            return;
        }

        const fileInput = document.getElementById('quotationImportFile');
        const file = fileInput?.files[0];
        if (!file) {
            fileInput?.setCustomValidity(@json(__('supplier.copy.select_an_xlsx_xls_or_csv_file_before_continuing')));
            fileInput?.reportValidity();
            return;
        }
        fileInput.setCustomValidity('');

        const formData = new FormData();
        formData.append('_token', quotationCsrfToken);
        formData.append('import_file', file);
        quotationImportRows = [];
        const applyBtn = document.getElementById('btnApplyQuotationImport');
        if (applyBtn) applyBtn.disabled = true;
        setQuotationImportBusy(true);

        const fallbackErrorPayload = {
            success: false,
            rows: [],
            warnings: [],
            summary: { total: 0, valid: 0, invalid: 0 },
            errors: [{ row: null, column: 'import_file', message: @json(__('supplier.copy.the_spreadsheet_could_not_be_processed')) }]
        };

        try {
            const response = await fetch(quotationImportPreviewUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': quotationCsrfToken,
                },
            });
            const payload = await response.json().catch(() => null);

            if (response.status === 409) {
                const formEl = document.getElementById('quotationForm');
                formEl?.dispatchEvent(new CustomEvent('adasi:form-conflict', {
                    bubbles: true,
                    cancelable: true,
                    detail: { status: 409, data: payload || {}, form: formEl },
                }));
                return;
            }

            renderQuotationImportResult((response.ok && payload) ? payload : (payload || fallbackErrorPayload));
        } catch (error) {
            renderQuotationImportResult(fallbackErrorPayload);
        } finally {
            setQuotationImportBusy(false);
        }
    }

    function quotationImportWouldOverwrite() {
        return quotationImportRows.some((row) => {
            const formRow = quotationRowForPrItem(row.pr_item_id);
            if (!formRow) {
                return false;
            }

            const fieldWouldChange = Object.entries(quotationImportFieldSelectors).some(([field, selector]) => {
                if (field === 'offered_weight_per_unit') {
                    const incomingWeight = row.offered_weight_per_unit;
                    if (incomingWeight === null || incomingWeight === undefined || String(incomingWeight).trim() === '') {
                        return false;
                    }
                }

                const input = formRow.querySelector(selector);
                if (!input) {
                    return false;
                }

                const current = String(input.value ?? '').trim();
                const incomingValue = field === 'available_length_input' ? importedLengthValue(row) : row[field];
                const incoming = String(incomingValue ?? '').trim();
                return current !== '' && current !== incoming;
            });

            const importedAvailable = row.is_available !== false;
            const currentAvailable = formRow.querySelector('.item-availability-input')?.value !== '0';

            return fieldWouldChange || importedAvailable !== currentAvailable;
        });
    }

    function quotationGroupHasOfferValues(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return false;

        return Object.values(quotationImportFieldSelectors).some((selector) => {
            const value = groupEl.querySelector(selector)?.value;
            return value !== undefined && String(value).trim() !== '';
        });
    }

    function performQuotationImportApply(mode) {
        let changedFields = 0;
        const importedItems = quotationImportRows.length;

        quotationImportRows.forEach((row) => {
            const formRow = quotationRowForPrItem(row.pr_item_id);
            if (!formRow) {
                return;
            }

            const importedAvailable = row.is_available !== false;
            const canApplyAvailability = mode === 'replace' || !quotationGroupHasOfferValues(formRow);
            if (canApplyAvailability) {
                setItemUnavailable(formRow, !importedAvailable);
                changedFields++;
            }

            // Apply all fields except offered_weight_per_unit which is handled explicitly below
            Object.entries(quotationImportFieldSelectors).forEach(([field, selector]) => {
                if (field === 'offered_weight_per_unit') {
                    return;
                }

                const inputs = formRow.querySelectorAll(selector);
                if (inputs.length === 0) {
                    return;
                }

                const incoming = field === 'available_length_input'
                    ? importedLengthValue(row)
                    : (row[field] ?? '');
                const current = String(inputs[0].value ?? '').trim();
                const incomingText = String(incoming).trim();
                const shouldApply = mode === 'replace'
                    || (current === '' && incomingText !== '');

                if (!shouldApply || current === incomingText) {
                    return;
                }

                inputs.forEach((input) => {
                    input.value = incoming;
                    dispatchFieldInputAndChange(input);
                });
                changedFields++;
            });

            // Explicitly handle offered_weight_per_unit without wiping out auto-calculated weight
            const rawImportedWeight = row.offered_weight_per_unit;
            const hasExplicitWeight = rawImportedWeight !== null && rawImportedWeight !== undefined && String(rawImportedWeight).trim() !== '';
            const weightInput = formRow.querySelector('.offered-weight-input');

            if (hasExplicitWeight) {
                const incomingWeight = String(rawImportedWeight).trim();
                const currentWeight = String(weightInput?.value ?? '').trim();
                if (mode === 'replace' || currentWeight === '') {
                    if (weightInput) weightInput.value = incomingWeight;
                    setFieldValidity(weightInput, true);
                    const manualOverrideEl = formRow.querySelector('.offered-weight-manual-override');
                    if (manualOverrideEl) manualOverrideEl.value = '1';
                    changedFields++;
                }
            } else if (importedAvailable) {
                const currentWeight = String(weightInput?.value ?? '').trim();
                if (mode === 'replace' || currentWeight === '') {
                    autoCalculateOfferWeight(formRow);
                }
            }

            updateOfferRowState(formRow);
            updateOfferWeightState(formRow);
            recalculateQuotationRow(formRow);
        });

        recalculateQuotationTotals();
        updateCommercialTermsState();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('quotationImportModal')).hide();
        const changedFieldCount = window.AdasiI18n.choice('supplier.js.field_change_count', changedFields);
        AdasiToast.show({
            type: 'success',
            title: @json(__('supplier.copy.import_applied')),
            message: window.AdasiI18n.choice('supplier.js.import_result', importedItems, { field_count: changedFieldCount }),
            autoClose: 2400
        });
    }

    function applyQuotationImport() {
        if (quotationImportRows.length === 0) {
            return;
        }

        const mode = document.getElementById('quotationImportMode')?.value || 'fill_empty';
        if (mode === 'replace' && quotationImportWouldOverwrite()) {
            AdasiAlert.confirmDanger({
                title: @json(__('supplier.copy.replace_existing_item_values')),
                text: @json(__('supplier.copy.imported_values_will_replace_values_currently_entered_for_matching_items')),
                confirmText: @json(__('supplier.copy.yes_replace_fields')),
                cancelText: @json(__('supplier.copy.cancel'))
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
        return document.getElementById('quotationCurrency')?.value || '';
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

    function itemIsUnavailable(group) {
        const groupEl = resolveQuotationGroup(group);
        return groupEl?.querySelector('.item-availability-input')?.value === '0';
    }

    function setFieldValidity(input, valid, message = '') {
        const inputEl = input instanceof Element ? input : (input?.[0] instanceof Element ? input[0] : null);
        if (!inputEl) return;
        inputEl.setCustomValidity(valid ? '' : message);
        inputEl.classList.toggle('is-invalid', !valid);
    }

    function validateOfferQuantity(group, requireValue = false) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return true;

        const inputEl = groupEl.querySelector('[data-availability-field="qty"]');
        if (!inputEl || itemIsUnavailable(groupEl)) {
            setFieldValidity(inputEl, true);
            return true;
        }

        const raw = String(inputEl.value ?? '').trim();
        const requested = Number(groupEl.dataset.requestedQty);
        const value = Number(raw);
        let message = '';

        if (raw === '' && requireValue) {
            message = @json(__('supplier.copy.offer_qty_is_required_for_an_available_item'));
        } else if (raw !== '' && (!Number.isInteger(value) || value < 1)) {
            message = @json(__('supplier.copy.offer_qty_must_be_a_whole_number_of_at_least_1'));
        } else if (raw !== '' && !quotationSurplusOffersAllowed && value > requested) {
            message = window.AdasiI18n.t('supplier.js.offer_limit', { requested });
        }

        setFieldValidity(inputEl, message === '', message);
        const feedbackEl = groupEl.querySelector('.offer-qty-feedback');
        if (feedbackEl) feedbackEl.textContent = message;
        return message === '';
    }

    function autoCalculateOfferWeight(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl || itemIsUnavailable(groupEl)) return;

        const shape = groupEl.dataset.shape;
        const densityProfile = groupEl.dataset.densityProfile || 'steel';
        const isAluminium = densityProfile === 'aluminium';
        const flatFactor = isAluminium ? 0.00273 : 0.00785;
        const roundFactor = 0.006167;

        const thickness = Number(groupEl.querySelector('[data-availability-field="thickness"]')?.value) || 0;
        const width = Number(groupEl.querySelector('[data-availability-field="width"]')?.value) || 0;
        const dOuter = Number(groupEl.querySelector('[data-availability-field="d_outer"]')?.value) || 0;
        const dInner = Number(groupEl.querySelector('[data-availability-field="d_inner"]')?.value) || 0;

        const parsedLength = parseOfferLength(groupEl.querySelector('.offer-length-input')?.value);
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
            const weightInput = groupEl.querySelector('.offered-weight-input');
            if (weightInput) weightInput.value = String(rounded);
            setFieldValidity(weightInput, true);
            const manualOverrideEl = groupEl.querySelector('.offered-weight-manual-override');
            if (manualOverrideEl) manualOverrideEl.value = isRange ? '1' : '0';
            updateOfferWeightState(groupEl);
            recalculateQuotationRow(groupEl);
        }
    }

    function updateOfferWeightState(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return false;

        const parsedLength = parseOfferLength(groupEl.querySelector('.offer-length-input')?.value);
        const isEstimated = parsedLength.isRange
            || groupEl.querySelector('.offered-weight-manual-override')?.value === '1';

        groupEl.querySelector('.offer-weight-indicator')?.classList.toggle('d-none', itemIsUnavailable(groupEl) || !isEstimated);
        return isEstimated;
    }

    function validateOfferLength(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return true;

        const inputEl = groupEl.querySelector('.offer-length-input');
        if (!inputEl || itemIsUnavailable(groupEl)) {
            setFieldValidity(inputEl, true);
            updateOfferWeightState(groupEl);
            return true;
        }

        const parsed = parseOfferLength(inputEl.value);
        let message = '';
        if (!parsed.valid) {
            message = parsed.reason === 'reversed'
                ? @json(__('supplier.copy.the_minimum_offer_length_cannot_exceed_the_maximum_length'))
                : @json(__('supplier.copy.enter_one_length_e_g_2300_or_a_range_from_minimum_to_maximum_e_g_2300_2500'));
        }

        setFieldValidity(inputEl, parsed.valid, message);
        const feedbackEl = groupEl.querySelector('.offer-length-feedback');
        if (feedbackEl) feedbackEl.textContent = message;
        if (parsed.valid && parsed.isRange) {
            const manualOverrideEl = groupEl.querySelector('.offered-weight-manual-override');
            if (manualOverrideEl) manualOverrideEl.value = '1';
        }
        updateOfferWeightState(groupEl);
        return parsed.valid;
    }

    function isAllItemsUnavailable() {
        const groups = document.querySelectorAll('.quotation-item-group');
        return groups.length > 0 && Array.from(groups).every((groupEl) => itemIsUnavailable(groupEl));
    }

    function refreshCurrencyState() {
        const currency = selectedCurrency();
        document.querySelectorAll('.currency-label').forEach((el) => {
            el.textContent = currency || '-';
        });
        const warningLabelEl = document.getElementById('currencyWarningLabel');
        if (warningLabelEl) warningLabelEl.textContent = currency || '-';
        const allUnavailable = isAllItemsUnavailable();
        document.getElementById('currencyRateWarning')?.classList.toggle('d-none', allUnavailable || !currency || selectedRate() > 0);
        calculateTotal();
    }

    function setQuotationPickerState(pickerEl, inputEls, disabled, restoreRequired = false) {
        inputEls.forEach((inputEl) => {
            if (disabled) {
                inputEl.removeAttribute('required');
                inputEl.classList.remove('is-invalid');
                inputEl.disabled = true;
                inputEl.setAttribute('aria-disabled', 'true');
            } else {
                if (restoreRequired) {
                    inputEl.setAttribute('required', 'required');
                }
                inputEl.disabled = false;
                inputEl.removeAttribute('aria-disabled');
            }
        });

        pickerEl?.querySelectorAll('[data-calendar-trigger]').forEach((triggerEl) => {
            triggerEl.disabled = disabled;
            if (disabled) {
                triggerEl.setAttribute('aria-disabled', 'true');
                triggerEl.setAttribute('tabindex', '-1');
            } else {
                triggerEl.removeAttribute('aria-disabled');
                triggerEl.removeAttribute('tabindex');
            }
        });

        if (pickerEl) {
            if (disabled) {
                pickerEl.setAttribute('data-calendar-required', 'false');
                pickerEl.setAttribute('data-calendar-disabled', 'true');
                pickerEl.classList.add('is-disabled');
                pickerEl.dispatchEvent(new CustomEvent('adasi:calendar-close'));
            } else {
                if (restoreRequired) {
                    pickerEl.setAttribute('data-calendar-required', 'true');
                }
                pickerEl.setAttribute('data-calendar-disabled', 'false');
                pickerEl.classList.remove('is-disabled');
            }
            pickerEl.querySelectorAll('[class~="tw:text-error"]').forEach((errEl) => {
                errEl.classList.toggle('d-none', disabled);
            });
        }
    }

    function updateCommercialTermsState() {
        const allUnavailable = isAllItemsUnavailable();
        const currencyEl = document.getElementById('quotationCurrency');
        const deliveryInputs = Array.from(document.querySelectorAll('[name="estimated_delivery"]'));
        const deliveryPicker = deliveryInputs[0]?.closest('[data-adasi-date-picker]') || null;
        const paymentTermsEls = Array.from(document.querySelectorAll('[name="payment_terms"]'));
        const paymentTermsGroup = paymentTermsEls[0]?.closest('div') || null;
        const validityInputs = Array.from(document.querySelectorAll('#validityPeriod, [name="validity_period"]'));
        const validityPicker = validityInputs[0]?.closest('[data-adasi-date-picker]') || null;
        const rateWarningEl = document.getElementById('currencyRateWarning');
        const termsNoticeEl = document.getElementById('allUnavailableTermsNotice');

        if (allUnavailable) {
            if (currencyEl) {
                currencyEl.removeAttribute('required');
                currencyEl.classList.remove('is-invalid');
            }

            // Estimated Delivery & Validity Period pickers
            setQuotationPickerState(deliveryPicker, deliveryInputs, true);
            setQuotationPickerState(validityPicker, validityInputs, true);
            validityPicker?.querySelector('#validityPeriod-help')?.classList.add('tw:opacity-40');

            // Payment Terms: disable textarea, remove validation, hide asterisks
            paymentTermsEls.forEach((el) => {
                el.removeAttribute('required');
                el.classList.remove('is-invalid');
                el.disabled = true;
                el.setAttribute('aria-disabled', 'true');
                el.classList.add('tw:bg-surface-container', 'tw:opacity-50', 'tw:cursor-not-allowed');
            });
            paymentTermsGroup?.querySelectorAll('[class~="tw:text-error"]').forEach((errEl) => {
                errEl.classList.add('d-none');
            });

            rateWarningEl?.classList.add('d-none');
            termsNoticeEl?.classList.remove('d-none');
        } else {
            currencyEl?.setAttribute('required', 'required');

            // Estimated Delivery & Validity Period pickers
            setQuotationPickerState(deliveryPicker, deliveryInputs, false, true);
            setQuotationPickerState(validityPicker, validityInputs, false, false);
            validityPicker?.querySelector('#validityPeriod-help')?.classList.remove('tw:opacity-40');

            // Payment Terms: enable textarea, restore validation, show asterisks
            paymentTermsEls.forEach((el) => {
                el.setAttribute('required', 'required');
                el.disabled = false;
                el.removeAttribute('aria-disabled');
                el.classList.remove('tw:bg-surface-container', 'tw:opacity-50', 'tw:cursor-not-allowed');
            });
            paymentTermsGroup?.querySelectorAll('[class~="tw:text-error"]').forEach((errEl) => {
                errEl.classList.remove('d-none');
            });

            termsNoticeEl?.classList.add('d-none');
            refreshCurrencyState();
        }
    }

    window.isAllItemsUnavailable = isAllItemsUnavailable;
    window.refreshCurrencyState = refreshCurrencyState;
    window.updateCommercialTermsState = updateCommercialTermsState;

    function setItemUnavailable(group, unavailable) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return;

        groupEl.querySelectorAll('.item-availability-input').forEach((el) => {
            el.value = unavailable ? '0' : '1';
        });
        groupEl.querySelectorAll('.item-not-available-toggle').forEach((el) => {
            el.checked = unavailable;
        });
        groupEl.querySelectorAll('.quotation-offer-row').forEach((el) => {
            el.classList.toggle('is-unavailable', unavailable);
        });
        groupEl.querySelectorAll('.offer-disable-when-unavailable').forEach((el) => {
            el.disabled = unavailable;
            el.setAttribute('aria-disabled', unavailable ? 'true' : 'false');
        });
        groupEl.querySelectorAll('.mtc-file-label').forEach((el) => {
            el.setAttribute('aria-disabled', unavailable ? 'true' : 'false');
        });

        const iconSvg = unavailable
            ? '<svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
            : '<svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        groupEl.querySelectorAll('.availability-toggle').forEach((toggleBtn) => {
            toggleBtn.classList.toggle('is-available', !unavailable);
            toggleBtn.classList.toggle('is-unavailable', unavailable);
            toggleBtn.setAttribute('aria-checked', unavailable ? 'false' : 'true');
            const labelEl = toggleBtn.querySelector('.item-availability-label');
            if (labelEl) {
                labelEl.textContent = unavailable ? @js(__('supplier.copy.not_available')) : @js(__('supplier.copy.available'));
            }
            const iconWrap = toggleBtn.querySelector('.availability-toggle__icon');
            if (iconWrap) iconWrap.innerHTML = iconSvg;
        });

        const placeholderText = unavailable
            ? @json(__('supplier.copy.e_g_out_of_stock_earliest_rolling_schedule_next_quarter'))
            : @json(__('supplier.copy.e_g_mill_tolerance_0_5mm_prime_grade_mtc_included'));

        groupEl.querySelectorAll('.quotation-item-notes, .quotation-notes-draft').forEach((el) => {
            el.setAttribute('placeholder', placeholderText);
        });
        groupEl.querySelectorAll('.quotation-notes-trigger').forEach((el) => {
            el.disabled = unavailable;
            el.setAttribute('aria-disabled', unavailable ? 'true' : 'false');
        });

        if (unavailable) {
            groupEl.querySelectorAll('.offer-disable-when-unavailable').forEach((el) => {
                el.setCustomValidity?.('');
                el.classList.remove('is-invalid');
            });
            groupEl.querySelectorAll('.offer-qty-feedback, .offer-length-feedback').forEach((el) => {
                el.textContent = '';
            });
        }

        updateOfferWeightState(groupEl);
        recalculateQuotationRow(groupEl);
        updateCommercialTermsState();
    }

    function updateOfferRowState(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return;
        setItemUnavailable(groupEl, itemIsUnavailable(groupEl));
        validateOfferQuantity(groupEl);
        validateOfferLength(groupEl);
    }

    function recalculateQuotationRow(group) {
        const groupEl = resolveQuotationGroup(group);
        if (!groupEl) return { amount: 0, idr: 0 };

        const unavailable = itemIsUnavailable(groupEl);
        const price = Number(groupEl.querySelector('.price-input')?.value);
        const requestedTotalWeight = Number(groupEl.dataset.requestedTotalWeight) || 0;
        const offeredQty = Number(groupEl.querySelector('[data-availability-field="qty"]')?.value);
        const offeredWeight = Number(groupEl.querySelector('.offered-weight-input')?.value);
        const rate = selectedRate();

        const setGroupDisplay = (selector, text) => {
            groupEl.querySelectorAll(selector).forEach((el) => {
                el.textContent = text;
            });
        };

        if (unavailable) {
            setGroupDisplay('.requested-price-display, .requested-amount-display, .offer-total-weight-display, .offer-amount-display', '—');
            setGroupDisplay('.requested-idr-display, .offer-idr-display', '');
            return { amount: 0, idr: 0 };
        }

        const hasPrice = Number.isFinite(price) && price > 0;
        const offerTotalWeight = Number.isFinite(offeredQty) && offeredQty > 0
            && Number.isFinite(offeredWeight) && offeredWeight > 0
            ? offeredQty * offeredWeight
            : null;
        const requestedAmount = hasPrice ? requestedTotalWeight * price : null;
        const offerAmount = hasPrice && offerTotalWeight !== null ? offerTotalWeight * price : null;

        setGroupDisplay('.requested-price-display', hasPrice ? formatMaxTwo(price) : '—');
        setGroupDisplay('.requested-amount-display', requestedAmount === null ? '—' : formatMaxTwo(requestedAmount));
        setGroupDisplay('.offer-total-weight-display', offerTotalWeight === null ? '—' : formatMaxTwo(offerTotalWeight));
        setGroupDisplay('.offer-amount-display', offerAmount === null ? '—' : formatMaxTwo(offerAmount));

        return {
            qty: Number.isInteger(offeredQty) && offeredQty > 0 ? offeredQty : 0,
            kg: offerTotalWeight ?? 0,
            amount: offerAmount ?? 0,
            idr: offerAmount !== null && rate > 0 ? offerAmount * rate : 0,
        };
    }

    // Preview only: the server recomputes every amount on save (Decision 06).
    function recalculateQuotationTotals() {
        let totalQty = 0;
        let totalKg = 0;
        let totalAmount = 0;
        let totalIdr = 0;
        document.querySelectorAll('.quotation-item-group').forEach((groupEl) => {
            const totals = recalculateQuotationRow(groupEl);
            totalQty += totals.qty ?? 0;
            totalKg += totals.kg ?? 0;
            totalAmount += totals.amount;
            totalIdr += totals.idr;
        });

        const totalQtyEl = document.getElementById('totalOfferedQty');
        const totalKgEl = document.getElementById('totalOfferedKg');
        const totalAmountEl = document.getElementById('totalAmount');
        if (totalQtyEl) totalQtyEl.textContent = formatMaxTwo(totalQty);
        if (totalKgEl) totalKgEl.textContent = formatMaxTwo(totalKg);
        if (totalAmountEl) totalAmountEl.textContent = formatMaxTwo(totalAmount);
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
        const resolved = button instanceof Element ? button : (button?.[0] instanceof Element ? button[0] : null);

        return resolved && typeof resolved.closest === 'function' ? resolved : null;
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

        setItemUnavailable(row, false);
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
            dispatchFieldInputAndChange(input);
            copied = true;
        });

        if (copied) {
            const overrideInput = row.querySelector('.offered-weight-manual-override');
            if (overrideInput) overrideInput.value = '0';
            validateOfferQuantity(row);
            validateOfferLength(row);
            updateOfferWeightState(row);
            recalculateQuotationRow(row);
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
            showCopyFeedback(window.AdasiI18n.choice('supplier.js.requested_copied', copiedRows));
        }

        return copiedRows;
    };

    function showCopyFeedback(message) {
        if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
            window.AdasiToast.success(message, {
                title: @json(__('supplier.copy.values_copied')),
                autoClose: 2000
            });
        } else if (window.AdasiToast && typeof window.AdasiToast.show === 'function') {
            window.AdasiToast.show({
                type: 'success',
                title: @json(__('supplier.copy.values_copied')),
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
            showCopyFeedback(@json(__('supplier.copy.requested_values_copied_to_row')));
        }
    });

    function positionQuotationNotesPopover(popoverEl, triggerEl) {
        if (!popoverEl || popoverEl.hidden) return;

        const resolvedTrigger = triggerEl || popoverEl.closest('td')?.querySelector('[data-notes-trigger]');
        if (!resolvedTrigger) return;

        const rect = resolvedTrigger.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            popoverEl.hidden = true;
            return;
        }

        const scrollContainer = popoverEl.closest('.quotation-table-scroll');
        if (scrollContainer) {
            const containerRect = scrollContainer.getBoundingClientRect();
            if (rect.right < containerRect.left + 10 || rect.left > containerRect.right - 10) {
                popoverEl.hidden = true;
                resolvedTrigger.setAttribute('aria-expanded', 'false');
                return;
            }
        }

        if (rect.bottom < 0 || rect.top > window.innerHeight) {
            popoverEl.hidden = true;
            resolvedTrigger.setAttribute('aria-expanded', 'false');
            return;
        }

        const viewportPadding = 8;
        const gap = 4;
        const popoverWidth = Math.min(320, Math.max(280, window.innerWidth - (viewportPadding * 2)));
        const popoverHeight = Math.min(popoverEl.offsetHeight || popoverEl.scrollHeight || 230, 280);

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

        Object.assign(popoverEl.style, {
            position: 'fixed',
            top: `${Math.round(top)}px`,
            left: `${Math.round(left)}px`,
            bottom: 'auto',
            right: 'auto',
            width: `${popoverWidth}px`,
            zIndex: '1080',
        });
    }

    function repositionVisibleNotesPopovers() {
        document.querySelectorAll('[data-notes-popover]:not([hidden])').forEach((popoverEl) => {
            const triggerEl = popoverEl.closest('td')?.querySelector('[data-notes-trigger]');
            if (triggerEl) {
                positionQuotationNotesPopover(popoverEl, triggerEl);
            }
        });
    }

    function closeAllQuotationNotesPopovers() {
        document.querySelectorAll('[data-notes-popover]').forEach((el) => {
            el.hidden = true;
        });
        document.querySelectorAll('[data-notes-trigger]').forEach((el) => {
            el.setAttribute('aria-expanded', 'false');
        });
    }

    function syncQuotationNotesTriggerUi(cell, val) {
        if (!cell) return;
        const trigger = cell.querySelector('[data-notes-trigger]');
        const triggerText = trigger?.querySelector('.quotation-notes-trigger__text');
        if (!trigger) return;

        if (val) {
            if (triggerText) triggerText.textContent = val;
            trigger.classList.add('has-notes');
            trigger.setAttribute('title', val);
            if (!trigger.querySelector('.quotation-notes-trigger__badge')) {
                const badge = document.createElement('span');
                badge.className = 'quotation-notes-trigger__badge';
                badge.setAttribute('aria-hidden', 'true');
                badge.setAttribute('title', @js(__('common.final_review.note_entered')));
                trigger.appendChild(badge);
            }
        } else {
            if (triggerText) triggerText.textContent = @json(__('supplier.copy.add_note'));
            trigger.classList.remove('has-notes');
            trigger.setAttribute('title', @json(__('supplier.copy.click_to_add_notes')));
            trigger.querySelector('.quotation-notes-trigger__badge')?.remove();
        }
    }

    function validateQuotationRows(requireCompleteOffer) {
        let valid = true;
        let firstInvalid = null;

        document.querySelectorAll('.quotation-item-group').forEach((groupEl) => {
            if (itemIsUnavailable(groupEl)) return;

            if (!validateOfferQuantity(groupEl, requireCompleteOffer)) valid = false;
            if (!validateOfferLength(groupEl)) valid = false;

            const priceEl = groupEl.querySelector('.price-input');
            const price = Number(priceEl?.value);
            const priceValid = String(priceEl?.value ?? '').trim() !== '' && Number.isFinite(price) && price > 0;
            setFieldValidity(priceEl, priceValid, @json(__('supplier.copy.price_kg_must_be_greater_than_zero_for_an_available_item')));
            if (!priceValid) valid = false;

            const weightEl = groupEl.querySelector('.offered-weight-input');
            const weightRaw = String(weightEl?.value ?? '').trim();
            const weight = Number(weightRaw);
            const weightValid = !requireCompleteOffer || (weightRaw !== '' && Number.isFinite(weight) && weight > 0);
            setFieldValidity(weightEl, weightValid, @json(__('supplier.copy.offer_kg_unit_is_required_and_must_be_greater_than_zero_for_a_final_available_offer')));
            if (!weightValid) valid = false;

            if (!firstInvalid) {
                firstInvalid = groupEl.querySelector('.is-invalid') || null;
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
        const actionEl = document.getElementById('formAction');
        if (actionEl) actionEl.value = action;
        if (form && typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form?.submit();
        }
    }

    function confirmSubmit() {
        const allUnavailable = isAllItemsUnavailable();
        const formEl = document.getElementById('quotationForm');

        if (!allUnavailable && formEl) {
            let isValid = true;
            formEl.querySelectorAll('input[required], select[required], [data-calendar-required="true"] [data-calendar-native-input], #validityPeriod').forEach((el) => {
                if (!el.value) {
                    isValid = false;
                    el.classList.add('is-invalid');
                } else {
                    el.classList.remove('is-invalid');
                }
            });

            if (!isValid || !validateQuotationRows(true)) {
                const firstInvalid = formEl.querySelector('.is-invalid');
                const calendarTrigger = firstInvalid?.closest('[data-adasi-date-picker], [data-adasi-date-range]')?.querySelector('[data-calendar-trigger], [data-calendar-boundary]');
                (calendarTrigger || firstInvalid)?.focus();
                firstInvalid?.reportValidity?.();
                return;
            }

            if (!formEl.checkValidity()) {
                formEl.reportValidity();
                return;
            }
        }

        AdasiAlert.confirm({
            title: allUnavailable
                ? @json(__('supplier.copy.submit_quotation_with_all_requested_items_marked_not_available'))
                : @js($quotation?->status === 'revision_requested' ? __('supplier.copy.resubmit_quotation') : __('supplier.copy.send_final_quotation')),
            text: allUnavailable
                ? @json(__('supplier.copy.requested_rows_remain_visible_but_this_quotation_contains_no_available_commercial_offer_lines'))
                : @js($quotation?->status === 'revision_requested' ? __('supplier.copy.the_revised_quotation_will_be_sent_back_to_purchasing_for_evaluation') : __('supplier.copy.submitted_quotations_cannot_be_modified_after_sending')),
            type: 'warning',
            confirmText: @js($quotation?->status === 'revision_requested' ? __('supplier.copy.resubmit_quotation') : __('supplier.copy.submit_final_quotation')),
            cancelText: @json(__('supplier.copy.cancel'))
        }).then((result) => {
            if (result.isConfirmed) {
                const submitBtn = document.getElementById('btnSubmitQuotation');
                if (formEl && submitBtn) {
                    formEl._lastClickedButton = submitBtn;
                }
                const actionEl = document.getElementById('formAction');
                if (actionEl) actionEl.value = 'submitted';
                if (formEl && typeof formEl.requestSubmit === 'function') {
                    formEl.requestSubmit();
                } else {
                    formEl?.submit();
                }
            }
        });
    }

    // Auto-save logic (session-scoped to prevent persistent localStorage residue on shared devices)
    const prId = '{{ $pr->hash }}';
    const draftUserId = @json((int) auth()->id());
    const draftKey = 'quotation_draft_' + draftUserId + '_' + prId;
    let quotationAvailabilityLocked = false;

    function clearQuotationDraftStorage() {
        try { sessionStorage.removeItem(draftKey); } catch (error) { /* Storage may be blocked. */ }
        try { localStorage.removeItem(draftKey); } catch (error) { /* Storage may be blocked. */ }
    }

    function saveDraft() {
        if (quotationAvailabilityLocked) {
            return;
        }
        const badgeEl = document.getElementById('autoSaveBadge');
        if (badgeEl) {
            badgeEl.classList.remove('d-none', 'ui-status-chip--success', 'ui-status-chip--error');
            badgeEl.classList.add('ui-status-chip--neutral');
            const spinner = document.createElement('span');
            spinner.className = 'ui-spinner me-1';
            spinner.setAttribute('aria-hidden', 'true');
            badgeEl.replaceChildren(spinner, document.createTextNode(@json(__('supplier.audit_ui.saving'))));
        }

        try {
            const formEl = document.getElementById('quotationForm');
            const data = {};
            if (formEl) {
                for (const [name, value] of new FormData(formEl).entries()) {
                    if (typeof value === 'string' && name !== '_token' && name !== 'action') {
                        data[name] = value;
                    }
                }
            }
            sessionStorage.setItem(draftKey, JSON.stringify(data));
            try { localStorage.removeItem(draftKey); } catch (error) { /* Storage may be blocked. */ }

            setTimeout(() => {
                if (!badgeEl) return;
                badgeEl.classList.remove('ui-status-chip--neutral');
                badgeEl.classList.add('ui-status-chip--success');
                badgeEl.textContent = @json(__('supplier.copy.draft_saved'));
                setTimeout(() => {
                    badgeEl.classList.add('d-none');
                }, 2000);
            }, 300);
        } catch (e) {
            if (!badgeEl) return;
            badgeEl.classList.remove('ui-status-chip--neutral', 'ui-status-chip--success');
            badgeEl.classList.add('ui-status-chip--error');
            badgeEl.textContent = @json(__('supplier.copy.save_failed'));
        }
    }

    function loadDraft() {
        let saved;
        try { localStorage.removeItem(draftKey); } catch (error) { /* Purge legacy persistent draft. */ }
        try { saved = sessionStorage.getItem(draftKey); } catch (error) { return; }
        if (!saved) {
            return;
        }

        let data;
        try { data = JSON.parse(saved); } catch (error) { return; }
        for (const key in data) {
            const val = data[key];
            if (typeof val !== 'string') continue;
            document.querySelectorAll(`[name="${CSS.escape(key)}"]`).forEach((element) => {
                if (element.type !== 'file' && !element.value) {
                    element.value = val;
                }
            });
        }

        document.querySelectorAll('.quotation-item-group').forEach((groupEl) => {
            setItemUnavailable(groupEl, itemIsUnavailable(groupEl));
            validateOfferQuantity(groupEl);
            validateOfferLength(groupEl);
        });
        recalculateQuotationTotals();

        const badgeEl = document.getElementById('autoSaveBadge');
        if (badgeEl) {
            badgeEl.classList.remove('d-none', 'ui-status-chip--error', 'ui-status-chip--neutral');
            badgeEl.classList.add('ui-status-chip--success');
            badgeEl.textContent = @json(__('supplier.copy.draft_restored'));
            setTimeout(() => badgeEl.classList.add('d-none'), 3000);
        }
    }

    function bootSupplierQuotationCreate() {
        const importModeDescriptions = {
            fill_empty: @json(__('supplier.copy.only_fills_offer_fields_that_are_still_empty_existing_values_entered_in_the_form_are_preserved')),
            replace: @json(__('supplier.copy.replaces_the_matching_offer_fields_for_the_same_pr_items_using_values_from_the_spreadsheet_it_does_n')),
        };
        const importModeEl = document.getElementById('quotationImportMode');
        const importModeHelpEl = document.getElementById('quotationImportModeHelp');
        const updateQuotationImportModeHelp = () => {
            if (importModeHelpEl && importModeEl) {
                importModeHelpEl.textContent = importModeDescriptions[importModeEl.value] || '';
            }
        };

        document.getElementById('btnParseQuotationImport')?.addEventListener('click', parseQuotationImport);
        document.getElementById('btnApplyQuotationImport')?.addEventListener('click', applyQuotationImport);
        importModeEl?.addEventListener('change', updateQuotationImportModeHelp);
        updateQuotationImportModeHelp();

        window.addEventListener('resize', repositionVisibleNotesPopovers);
        document.addEventListener('scroll', repositionVisibleNotesPopovers, true);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeAllQuotationNotesPopovers();
            }
        });

        document.addEventListener('click', (e) => {
            const target = e.target;
            if (!(target instanceof Element)) return;

            const mtcRemoveBtn = target.closest('[data-mtc-remove]');
            if (mtcRemoveBtn) {
                e.preventDefault();
                const container = mtcRemoveBtn.closest('[data-mtc-container]');
                const input = container?.querySelector('.mtc-file-input');
                const fileNameEl = container?.querySelector('.mtc-file-name');
                const copyInput = container?.querySelector('.mtc-copy-attachment-id');
                const keepInput = container?.querySelector('.mtc-keep-existing-attachment');
                if (input) input.value = '';
                if (copyInput) copyInput.value = '';
                if (keepInput) keepInput.value = '0';
                if (fileNameEl) {
                    delete fileNameEl.dataset.defaultName;
                    fileNameEl.removeAttribute('data-default-name');
                    fileNameEl.textContent = '';
                    fileNameEl.removeAttribute('title');
                }
                container?.querySelector('[data-mtc-card]')?.classList.add('d-none');
                container?.querySelector('[data-mtc-empty]')?.classList.remove('d-none');
                return;
            }

            const mtcApplyBtn = target.closest('[data-mtc-apply]');
            if (mtcApplyBtn) {
                e.preventDefault();
                const mode = mtcApplyBtn.dataset.mtcApply; // 'same_material' or 'all_available'
                const sourceContainer = mtcApplyBtn.closest('[data-mtc-container]');
                const sourceGroup = sourceContainer?.closest('.quotation-item-group');
                const sourceMaterialName = String(sourceGroup?.dataset.materialName || '').trim();
                const sourceInput = sourceContainer?.querySelector('.mtc-file-input');
                const sourceFile = sourceInput?.files?.[0] || null;
                const sourceAttachmentId = sourceContainer?.querySelector('.mtc-copy-attachment-id')?.value
                    || sourceContainer?.getAttribute('data-existing-attachment-id')
                    || null;
                const sourceFileName = String(sourceContainer?.querySelector('.mtc-file-name')?.textContent || '').trim();
                const sourceServerLink = sourceContainer?.querySelector('[data-mtc-server-link]')?.getAttribute('href') || null;

                if (!sourceFile && !sourceAttachmentId) {
                    if (window.AdasiToast) {
                        AdasiToast.error(@json(__('supplier.copy.please_upload_or_select_an_mtc_file_first_before_copying')));
                    }
                    return;
                }

                const targetList = [];
                document.querySelectorAll('.quotation-item-group').forEach((targetGroup) => {
                    if (targetGroup === sourceGroup) return;

                    const isAvailable = targetGroup.querySelector('.item-availability-input')?.value === '1'
                        && !targetGroup.classList.contains('is-unavailable');
                    if (!isAvailable) return;

                    const targetMaterialName = String(targetGroup.dataset.materialName || '').trim();
                    if (mode === 'same_material' && targetMaterialName.toLowerCase() !== sourceMaterialName.toLowerCase()) {
                        return;
                    }

                    const targetContainer = targetGroup.querySelector('[data-mtc-container]');
                    const targetInput = targetContainer?.querySelector('.mtc-file-input') || null;
                    const hasExistingFile = (targetInput?.files && targetInput.files.length > 0)
                        || (targetContainer?.querySelector('.mtc-copy-attachment-id')?.value || '') !== ''
                        || (!targetContainer?.querySelector('[data-mtc-card]')?.classList.contains('d-none')
                            && String(targetContainer?.querySelector('.mtc-file-name')?.textContent || '').trim() !== '');

                    targetList.push({
                        group: targetGroup,
                        container: targetContainer,
                        input: targetInput,
                        hasExistingFile,
                    });
                });

                if (targetList.length === 0) {
                    if (window.AdasiToast) {
                        const msg = mode === 'same_material'
                            ? @js(__('common.final_review.no_same_material')).replace(':material', sourceMaterialName)
                            : @json(__('supplier.copy.no_other_available_items_found'));
                        AdasiToast.info(msg);
                    }
                    return;
                }

                const overwriteTargets = targetList.filter((t) => t.hasExistingFile);

                const executeApply = () => {
                    let appliedCount = 0;
                    targetList.forEach((itemTarget) => {
                        const tCont = itemTarget.container;
                        const tInput = itemTarget.input;

                        if (sourceFile && tInput) {
                            try {
                                const dt = new DataTransfer();
                                dt.items.add(sourceFile);
                                tInput.files = dt.files;
                                const copyInput = tCont?.querySelector('.mtc-copy-attachment-id');
                                const keepInput = tCont?.querySelector('.mtc-keep-existing-attachment');
                                if (copyInput) copyInput.value = '';
                                if (keepInput) keepInput.value = '0';
                                tInput.dispatchEvent(new Event('change', { bubbles: true }));
                                appliedCount++;
                            } catch (err) {
                                window.AdasiDebug?.error('DataTransfer copy failed:', err);
                            }
                        } else if (sourceAttachmentId) {
                            if (tInput) tInput.value = '';
                            const copyInput = tCont?.querySelector('.mtc-copy-attachment-id');
                            const keepInput = tCont?.querySelector('.mtc-keep-existing-attachment');
                            if (copyInput) copyInput.value = sourceAttachmentId;
                            if (keepInput) keepInput.value = '0';

                            const tFileName = tCont?.querySelector('.mtc-file-name');
                            if (tFileName) {
                                tFileName.textContent = sourceFileName;
                                tFileName.setAttribute('title', sourceFileName);
                            }
                            tCont?.querySelector('[data-mtc-empty]')?.classList.add('d-none');
                            tCont?.querySelector('[data-mtc-card]')?.classList.remove('d-none');

                            const tServerLink = tCont?.querySelector('[data-mtc-server-link]');
                            if (sourceServerLink) {
                                if (tServerLink) {
                                    tServerLink.setAttribute('href', sourceServerLink);
                                    tServerLink.classList.remove('d-none');
                                } else {
                                    const actionsEl = tCont?.querySelector('.mtc-file-card__actions');
                                    if (actionsEl) {
                                        const linkEl = document.createElement('a');
                                        linkEl.href = sourceServerLink;
                                        linkEl.className = 'mtc-file-action-link';
                                        linkEl.target = '_blank';
                                        linkEl.rel = 'noopener';
                                        linkEl.title = 'View attached file';
                                        linkEl.setAttribute('data-mtc-server-link', '');
                                        linkEl.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-external-link"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg> <span>View</span>';
                                        actionsEl.prepend(linkEl);
                                    }
                                }
                            }
                            appliedCount++;
                        }
                    });

                    if (window.AdasiToast) {
                        AdasiToast.success(window.AdasiI18n.choice('supplier.js.mtc_applied', appliedCount));
                    }
                };

                if (overwriteTargets.length > 0) {
                    if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
                        AdasiAlert.confirm({
                            title: @json(__('supplier.copy.overwrite_existing_mtc_files')),
                            text: window.AdasiI18n.t('supplier.js.mtc_overwrite_file', { count: overwriteTargets.length, file: sourceFileName }),
                            type: 'warning',
                            confirmText: window.AdasiI18n.t('supplier.js.overwrite_confirm'),
                            cancelText: @json(__('supplier.copy.cancel'))
                        }).then((result) => {
                            if (result.isConfirmed) {
                                executeApply();
                            }
                        });
                    } else if (confirm(window.AdasiI18n.t('supplier.js.mtc_overwrite', { count: overwriteTargets.length }))) {
                        executeApply();
                    }
                } else {
                    executeApply();
                }
                return;
            }

            const notesTrigger = target.closest('[data-notes-trigger]');
            if (notesTrigger) {
                const cell = notesTrigger.closest('td');
                const popover = cell?.querySelector('[data-notes-popover]');
                if (!cell || !popover) return;
                const isVisible = !popover.hidden;

                closeAllQuotationNotesPopovers();

                if (!isVisible) {
                    const currentVal = cell.querySelector('.quotation-item-notes')?.value || '';
                    const draftInput = cell.querySelector('.quotation-notes-draft');
                    if (draftInput) draftInput.value = currentVal;

                    popover.hidden = false;
                    notesTrigger.setAttribute('aria-expanded', 'true');
                    positionQuotationNotesPopover(popover, notesTrigger);
                    draftInput?.focus();
                }
                return;
            }

            const notesSave = target.closest('[data-notes-save]');
            if (notesSave) {
                const cell = notesSave.closest('td');
                const popover = cell?.querySelector('[data-notes-popover]');
                const draftVal = String(cell?.querySelector('.quotation-notes-draft')?.value || '').trim();
                const realInput = cell?.querySelector('.quotation-item-notes');
                const trigger = cell?.querySelector('[data-notes-trigger]');

                if (realInput) {
                    realInput.value = draftVal;
                    dispatchFieldInputAndChange(realInput);
                }
                syncQuotationNotesTriggerUi(cell, draftVal);

                if (popover) popover.hidden = true;
                trigger?.setAttribute('aria-expanded', 'false');
                return;
            }

            const notesCancel = target.closest('[data-notes-cancel]');
            if (notesCancel) {
                const cell = notesCancel.closest('td');
                const popover = cell?.querySelector('[data-notes-popover]');
                const trigger = cell?.querySelector('[data-notes-trigger]');
                if (popover) popover.hidden = true;
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
                return;
            }

            if (!target.closest('[data-notes-popover], [data-notes-trigger]')) {
                closeAllQuotationNotesPopovers();
            }

            const availabilityToggle = target.closest('[data-availability-toggle]');
            if (availabilityToggle) {
                e.preventDefault();
                const groupEl = availabilityToggle.closest('.quotation-item-group');
                const checkbox = groupEl?.querySelector('.item-not-available-toggle');
                const newUnavailable = !Boolean(checkbox?.checked);
                if (checkbox) checkbox.checked = newUnavailable;
                setItemUnavailable(groupEl, newUnavailable);
                recalculateQuotationTotals();
                updateCommercialTermsState();
            }
        });

        document.addEventListener('change', (e) => {
            const target = e.target;
            if (!(target instanceof Element)) return;

            const mtcInput = target.closest('.mtc-file-input');
            if (mtcInput) {
                const fileName = mtcInput.files?.[0]?.name;
                const container = mtcInput.closest('[data-mtc-container]');
                const card = container?.querySelector('[data-mtc-card]');
                const empty = container?.querySelector('[data-mtc-empty]');
                const fileNameEl = container?.querySelector('.mtc-file-name');
                const defaultName = fileNameEl?.dataset.defaultName;
                const copyInput = container?.querySelector('.mtc-copy-attachment-id');
                const keepInput = container?.querySelector('.mtc-keep-existing-attachment');
                const serverLinkEl = container?.querySelector('[data-mtc-server-link]');

                if (fileName) {
                    if (copyInput) copyInput.value = '';
                    if (keepInput) keepInput.value = '0';
                    if (fileNameEl) {
                        fileNameEl.textContent = fileName;
                        fileNameEl.setAttribute('title', fileName);
                    }
                    empty?.classList.add('d-none');
                    card?.classList.remove('d-none');
                    serverLinkEl?.classList.add('d-none');
                } else if (copyInput?.value) {
                    // If a copy attachment ID was set, keep card visible
                    empty?.classList.add('d-none');
                    card?.classList.remove('d-none');
                } else if (defaultName && keepInput?.value === '1') {
                    if (fileNameEl) {
                        fileNameEl.textContent = defaultName;
                        fileNameEl.setAttribute('title', defaultName);
                    }
                    empty?.classList.add('d-none');
                    card?.classList.remove('d-none');
                    serverLinkEl?.classList.remove('d-none');
                } else {
                    if (fileNameEl) {
                        fileNameEl.textContent = '';
                        fileNameEl.removeAttribute('title');
                    }
                    card?.classList.add('d-none');
                    empty?.classList.remove('d-none');
                }
                return;
            }

            if (target.closest('.quotation-item-notes')) {
                syncQuotationNotesTriggerUi(target.closest('td'), String(target.value || '').trim());
                return;
            }

            if (target.closest('.price-input, [data-availability-field="qty"]')) {
                const groupEl = target.closest('.quotation-item-group');
                validateOfferQuantity(groupEl);
                recalculateQuotationTotals();
                return;
            }

            if (target.closest('[data-availability-field="thickness"], [data-availability-field="width"], [data-availability-field="d_outer"], [data-availability-field="d_inner"]')) {
                const groupEl = target.closest('.quotation-item-group');
                autoCalculateOfferWeight(groupEl);
                recalculateQuotationTotals();
                return;
            }

            if (target.closest('.item-not-available-toggle')) {
                setItemUnavailable(target.closest('.quotation-item-group'), target.checked);
                recalculateQuotationTotals();
                updateCommercialTermsState();
            }
        });

        document.addEventListener('input', (e) => {
            const target = e.target;
            if (!(target instanceof Element)) return;

            if (target.closest('.quotation-item-notes')) {
                syncQuotationNotesTriggerUi(target.closest('td'), String(target.value || '').trim());
                return;
            }

            if (target.closest('.price-input, [data-availability-field="qty"]')) {
                const groupEl = target.closest('.quotation-item-group');
                validateOfferQuantity(groupEl);
                recalculateQuotationTotals();
                return;
            }

            if (target.closest('[data-availability-field="thickness"], [data-availability-field="width"], [data-availability-field="d_outer"], [data-availability-field="d_inner"]')) {
                const groupEl = target.closest('.quotation-item-group');
                autoCalculateOfferWeight(groupEl);
                recalculateQuotationTotals();
                return;
            }

            if (target.closest('.offered-weight-input')) {
                const groupEl = target.closest('.quotation-item-group');
                const overrideEl = groupEl?.querySelector('.offered-weight-manual-override');
                if (overrideEl) overrideEl.value = '1';
                updateOfferWeightState(groupEl);
                recalculateQuotationTotals();
                return;
            }

            if (target.closest('.offer-length-input')) {
                const groupEl = target.closest('.quotation-item-group');
                validateOfferLength(groupEl);
                autoCalculateOfferWeight(groupEl);
                recalculateQuotationTotals();
            }
        });

        document.addEventListener('focusout', (e) => {
            const lengthInput = e.target?.closest?.('.offer-length-input');
            if (!lengthInput) return;
            const groupEl = lengthInput.closest('.quotation-item-group');
            const parsed = parseOfferLength(lengthInput.value);
            if (parsed.valid && !parsed.empty) {
                lengthInput.value = formatOfferLength(parsed);
            }
            validateOfferLength(groupEl);
            autoCalculateOfferWeight(groupEl);
            recalculateQuotationTotals();
        });

        document.getElementById('quotationImportModal')?.addEventListener('hidden.bs.modal', () => {
            if (quotationImportRequestInFlight) {
                return;
            }

            const fileInput = document.getElementById('quotationImportFile');
            if (fileInput) fileInput.value = '';
            if (importModeEl) importModeEl.value = 'fill_empty';
            updateQuotationImportModeHelp();
            document.getElementById('quotationImportResult')?.classList.add('d-none');
            document.getElementById('quotationImportWarnings')?.replaceChildren();
            document.getElementById('quotationImportErrors')?.replaceChildren();
            document.getElementById('quotationImportPreviewBody')?.replaceChildren();
            const applyBtn = document.getElementById('btnApplyQuotationImport');
            if (applyBtn) applyBtn.disabled = true;
            quotationImportRows = [];
        });

        document.getElementById('quotationCurrency')?.addEventListener('change', refreshCurrencyState);

        refreshCurrencyState();
        document.querySelectorAll('.quotation-item-group').forEach((groupEl) => {
            setItemUnavailable(groupEl, itemIsUnavailable(groupEl));
            validateOfferQuantity(groupEl);
            validateOfferLength(groupEl);
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

        loadDraft();

        let isDirty = false;
        let autoSaveTimer;
        const quotationFormEl = document.getElementById('quotationForm');
        quotationFormEl?.addEventListener('adasi:quotation-cancelled', () => {
            quotationAvailabilityLocked = true;
            clearTimeout(autoSaveTimer);
            isDirty = false;
            clearQuotationDraftStorage();
        });
        quotationFormEl?.addEventListener('adasi:form-success', () => {
            clearTimeout(autoSaveTimer);
            isDirty = false;
            clearQuotationDraftStorage();
        });

        const handleQuotationDraftDirty = (e) => {
            if (!e.target?.matches('input, select, textarea') || quotationAvailabilityLocked) {
                return;
            }
            isDirty = true;
            window.AdasiUnsaved?.markDirty?.();
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraft, 1200);
        };
        quotationFormEl?.addEventListener('input', handleQuotationDraftDirty);
        quotationFormEl?.addEventListener('change', handleQuotationDraftDirty);

        quotationFormEl?.addEventListener('submit', () => {
            clearTimeout(autoSaveTimer);
            isDirty = false;
            window.AdasiUnsaved?.markClean?.();
            if (!window.AdasiAsyncForm) {
                clearQuotationDraftStorage();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootSupplierQuotationCreate, { once: true });
    } else {
        bootSupplierQuotationCreate();
    }
</script>
@endpush
