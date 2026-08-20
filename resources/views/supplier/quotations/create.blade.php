@extends('layouts.app')

@section('title', 'Quotation Price Form - ADASI Portal')
@section('page-title', 'Form Quotation Price')

@push('styles')
<style>
    .quotation-entry-card {
        overflow: hidden;
    }

    .quotation-entry-toolbar {
        gap: 1rem;
    }

    .quotation-toolbar-heading {
        min-width: 230px;
    }

    .quotation-toolbar-actions {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        justify-content: flex-end;
    }

    .quotation-currency-control {
        align-items: center;
        display: flex;
        gap: .5rem;
    }

    .quotation-currency-field {
        width: 110px;
    }

    .quotation-table-scroll {
        --quotation-number-width: 52px;
        --quotation-material-width: 260px;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        position: relative;
        scrollbar-color: var(--md-on-surface-variant) var(--md-surface-container);
        scrollbar-width: thin;
    }

    .quotation-table-scroll::-webkit-scrollbar {
        height: 10px;
    }

    .quotation-table-scroll::-webkit-scrollbar-track {
        background: var(--md-surface-container);
    }

    .quotation-table-scroll::-webkit-scrollbar-thumb {
        background: var(--md-on-surface-variant);
        border: 2px solid var(--md-surface-container);
        border-radius: 999px;
    }

    .quotation-items-table {
        border: 0 !important;
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: .82rem;
        min-width: calc(var(--quotation-number-width) + var(--quotation-material-width) + 1605px);
        table-layout: fixed;
        width: 100% !important;
    }

    .quotation-items-table th,
    .quotation-items-table td {
        padding: .7rem .6rem;
    }

    .quotation-items-table .quotation-group-header th {
        background: var(--md-primary-container) !important;
        border-bottom: 1px solid var(--md-outline) !important;
        color: var(--md-on-primary-container) !important;
        height: 38px;
        top: 0;
        z-index: 8;
    }

    .quotation-items-table .quotation-field-header th {
        background: var(--md-surface-container-low) !important;
        height: 48px;
        top: 38px;
        z-index: 8;
    }

    .quotation-items-table .quotation-group-item,
    .quotation-items-table .quotation-sticky-material {
        border-right: 1px solid var(--md-outline) !important;
    }

    .quotation-items-table .quotation-group-item {
        left: 0;
        position: sticky;
        width: calc(var(--quotation-number-width) + var(--quotation-material-width));
        z-index: 12 !important;
    }

    .quotation-items-table .quotation-sticky-number {
        background: var(--md-surface);
        left: 0;
        min-width: var(--quotation-number-width);
        position: sticky;
        width: var(--quotation-number-width);
    }

    .quotation-items-table .quotation-sticky-material {
        background: var(--md-surface);
        left: var(--quotation-number-width);
        min-width: var(--quotation-material-width);
        position: sticky;
        width: var(--quotation-material-width);
        z-index: 6;
    }

    .quotation-items-table thead .quotation-sticky-number,
    .quotation-items-table thead .quotation-sticky-material {
        background: var(--md-surface-container-low) !important;
        z-index: 11;
    }

    .quotation-items-table tbody .quotation-sticky-number {
        z-index: 5;
    }

    .quotation-items-table tbody .quotation-sticky-material {
        box-shadow: 6px 0 10px -9px rgba(var(--md-on-surface-rgb), .75);
        z-index: 6;
    }

    .quotation-items-table tbody tr:hover > .quotation-sticky-number,
    .quotation-items-table tbody tr:hover > .quotation-sticky-material {
        background: rgba(var(--md-primary-rgb), .05);
    }

    .quotation-items-table .form-control,
    .quotation-items-table .form-select {
        min-height: 38px;
    }

    .quotation-items-table .quotation-editable {
        background: var(--md-surface);
        border-color: var(--md-outline);
    }

    .quotation-items-table .quotation-editable:focus {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 .18rem rgba(var(--md-primary-rgb), .14);
        position: relative;
        z-index: 2;
    }

    .quotation-items-table .quotation-calculated {
        background: var(--md-surface-container) !important;
        border-color: var(--md-outline-variant);
        color: var(--md-on-surface);
        cursor: default;
    }

    .quotation-number {
        font-variant-numeric: tabular-nums;
    }

    .quotation-item-notes {
        min-height: 88px;
        line-height: 1.35;
        resize: vertical;
    }

    .availability-panel {
        min-width: 315px;
    }

    .availability-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(112px, 1fr));
        gap: .5rem;
    }

    .availability-field-label {
        display: block;
        margin-bottom: .2rem;
        color: var(--md-on-surface-variant);
        font-size: .68rem;
        font-weight: 600;
    }

    .mtc-upload {
        background: var(--md-surface-container-low);
        border: 1px solid var(--md-outline-variant);
        border-radius: .5rem;
        padding: .65rem;
    }

    .mtc-file-button {
        align-items: center;
        display: inline-flex;
        min-height: 36px;
    }

    .mtc-file-input:focus-visible + .mtc-file-button {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 .2rem rgba(var(--md-primary-rgb), .18);
    }

    .mtc-file-name {
        color: var(--md-on-surface-variant);
        font-size: .72rem;
        line-height: 1.35;
        margin-top: .4rem;
        overflow-wrap: anywhere;
    }

    .availability-copied .availability-panel {
        background-color: var(--md-success-container) !important;
        transition: background-color .2s ease;
    }

    @media (max-width: 991.98px) {
        .quotation-entry-toolbar {
            align-items: flex-start !important;
        }

        .quotation-toolbar-actions {
            justify-content: flex-start;
        }
    }

    @media (max-width: 767.98px) {
        .quotation-entry-toolbar {
            align-items: stretch !important;
            flex-direction: column;
        }

        .quotation-toolbar-actions {
            align-items: stretch;
            flex-direction: column;
            width: 100%;
        }

        .quotation-toolbar-actions > .btn,
        .quotation-toolbar-actions > .dropdown,
        .quotation-toolbar-actions > .btn-group {
            width: 100%;
        }

        .quotation-toolbar-actions > .btn-group > .btn {
            width: 100%;
        }

        .quotation-currency-control {
            justify-content: space-between;
            width: 100%;
        }

        .quotation-currency-field {
            width: min(180px, 55vw);
        }

        .quotation-currency-control .form-select {
            min-height: 44px;
            width: 100% !important;
        }

        .quotation-table-scroll {
            --quotation-number-width: 44px;
            --quotation-material-width: 200px;
        }

        .quotation-items-table .form-control,
        .quotation-items-table .form-select {
            min-height: 44px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .quotation-items-table *,
        .quotation-table-scroll * {
            scroll-behavior: auto !important;
            transition: none !important;
        }
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="$quotation?->status === 'revision_requested' ? 'Revise Quotation' : 'Create Quotation'"
        :description="'Complete availability, commercial values, and supporting MTC files for ' . ($pr->pr_number ?? 'this requisition') . '.'"
        eyebrow="Supplier Portal"
    >
        <x-slot:actions><x-ui.button :href="route('supplier.quotations.period', $pr->period_id)" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to Requisition List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<x-ui.card title="Purchase Requisition Details" description="Reference information supplied by Purchasing; quotation inputs remain supplier-owned.">
        <div class="row">
            <div class="col-md-2 text-muted small">Period</div>
            <div class="col-md-10 fw-medium">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</div>
        </div>
        <div class="row mt-2">
            <div class="col-md-2 text-muted small">Notes PR</div>
            <div class="col-md-10">{{ $pr->notes ?? '-' }}</div>
        </div>
</x-ui.card>

@if($quotation?->status === 'revision_requested')
    <x-ui.alert tone="warning" title="Quotation Revision Requested">Purchasing asked this quotation to be resubmitted. Update the price, estimated delivery, validity date, and notes if needed.</x-ui.alert>
@endif

<form id="quotationForm" action="{{ route('supplier.quotations.store', $pr) }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="action" id="formAction" value="draft">

    <div class="card border-0 shadow-sm mb-4 quotation-entry-card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap quotation-entry-toolbar">
            <div class="quotation-toolbar-heading">
                <h6 class="mb-0 fw-bold">
                    Material Price Entry
                    <span id="autoSaveBadge" class="badge bg-success ms-2 d-none opacity-75"><i class="bi bi-cloud-check me-1"></i>Draft Auto-saved</span>
                </h6>
                <div class="small text-muted mt-1">Review the requested specifications, then complete your availability and commercial offer.</div>
            </div>
            <div class="quotation-toolbar-actions">
                @include('supplier.quotations._import_controls')
                <button type="button" id="copyAllRequested" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-clipboard-check me-1"></i> Copy All Requested Values
                </button>
                <div class="quotation-currency-control">
                    <label for="quotationCurrency" class="small fw-semibold text-muted mb-0">Currency</label>
                    <div class="quotation-currency-field">
                        <select name="currency" id="quotationCurrency" class="form-select form-select-sm @error('currency') is-invalid @enderror" required>
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
            </div>
        </div>
        <div id="currencyRateWarning" class="alert alert-warning rounded-0 border-0 border-top border-bottom mb-0 small {{ $supplierCurrency && ! $supplierRate ? '' : 'd-none' }}">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Exchange rate for <span id="currencyWarningLabel">{{ $supplierCurrency ?: '-' }}</span> is not available yet. Contact Admin before submitting the final quotation.
        </div>
        <div class="card-body p-0">
            <div class="table-responsive quotation-table-scroll">
                <table class="table table-bordered align-middle mb-0 quotation-items-table">
                    <caption class="visually-hidden">Supplier quotation entry table with requested material, availability, pricing, notes, and MTC fields</caption>
                    <colgroup>
                        <col class="tw-w-[var(--quotation-number-width)]">
                        <col class="tw-w-[var(--quotation-material-width)]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[315px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-20">
                        <col class="tw-w-[120px]">
                        <col class="tw-w-[135px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[165px]">
                        <col class="tw-w-[165px]">
                        <col class="tw-w-[165px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[220px]">
                        <col class="tw-w-[240px]">
                    </colgroup>
                    <thead class="table-light text-center">
                        <tr class="quotation-group-header">
                            <th colspan="2" scope="colgroup" class="quotation-group-item">Item</th>
                            <th scope="colgroup">Supplier Offer</th>
                            <th colspan="3" scope="colgroup">Requested</th>
                            <th colspan="3" scope="colgroup">Commercial</th>
                            <th colspan="2" scope="colgroup">Supporting</th>
                        </tr>
                        <tr class="quotation-field-header">
                            <th scope="col" class="quotation-sticky-number">No</th>
                            <th scope="col" class="quotation-sticky-material">Material &amp; Specification</th>
                            <th scope="col" class="availability-panel">Supplier Availability</th>
                            <th scope="col">Qty</th>
                            <th scope="col">Weight/Unit (kg)</th>
                            <th scope="col">Total Weight (kg)</th>
                            <th scope="col">Price/KG (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>) <span class="text-danger">*</span></th>
                            <th scope="col">Amount (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                            <th scope="col">Est. IDR</th>
                            <th scope="col">Notes Item</th>
                            <th scope="col">MTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pr->items as $index => $item)
                            @php
                                $qItem = null;
                                if ($quotation) {
                                    $qItem = $quotation->items->where('pr_item_id', $item->id)->first();
                                }
                                 $oldPrice = old("items.{$index}.price_per_kg", $qItem ? $qItem->price_per_kg : '');
                                 $oldNotes = old("items.{$index}.notes", $qItem ? $qItem->notes : '');
                                 $mtcAttachment = $qItem?->attachments?->first();
                                 $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($item->shape);
                             @endphp
                            <tr>
                                <td class="text-center quotation-sticky-number quotation-number">{{ $index + 1 }}</td>
                                <td class="quotation-sticky-material">
                                     <div class="fw-bold">{{ $item->material_name }}</div>
                                     <div class="text-muted tw-text-ui-xs">
                                        @if($item->hs_code) HS: {{ $item->hs_code }} | @endif
                                        @if($item->shape)
                                            {{ $item->shape }}: {{ $item->dimension_label }}
                                        @else
                                             -
                                         @endif
                                     </div>
                                     <div class="small text-muted mt-2"><i class="bi bi-building me-1"></i>Requested by Purchasing</div>
                                     @if($item->remark)
                                         <div class="small mt-1 text-break"><span class="text-muted">Remark:</span> {{ $item->remark }}</div>
                                     @endif
                                     <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                     <input type="hidden" class="item-weight" value="{{ $item->total_weight }}">
                                     @error("items.{$index}.pr_item_id")
                                         <div class="text-danger small mt-1">{{ $message }}</div>
                                     @enderror
                                 </td>
                                 <td class="availability-panel">
                                     <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                         <span class="small fw-semibold text-primary"><i class="bi bi-box-seam me-1"></i>Offered by Supplier</span>
                                         <button
                                             type="button"
                                             class="btn btn-sm btn-outline-secondary py-0 copy-from-pr-btn"
                                             data-requested-qty="{{ $item->quantity_value }}"
                                             data-requested-thickness="{{ $item->thickness ?? '' }}"
                                             data-requested-d-inner="{{ $item->d_inner ?? '' }}"
                                             data-requested-d-outer="{{ $item->d_outer ?? '' }}"
                                             data-requested-width="{{ $item->width ?? '' }}"
                                             data-requested-length="{{ $item->length ?? '' }}"
                                             title="Copy requested quantity and relevant dimensions"
                                         >
                                             <i class="bi bi-clipboard-check"></i> Copy
                                         </button>
                                     </div>
                                     <div class="availability-grid">
                                         <div>
                                             <label class="availability-field-label" for="availableQty{{ $index }}">Qty</label>
                                             <input
                                                 id="availableQty{{ $index }}"
                                                 type="number"
                                                 min="1"
                                                 step="1"
                                                 name="items[{{ $index }}][available_qty]"
                                                 class="form-control form-control-sm availability-input quotation-editable quotation-number @error("items.{$index}.available_qty") is-invalid @enderror"
                                                 data-availability-field="qty"
                                                 value="{{ old("items.{$index}.available_qty", $qItem?->available_qty) }}"
                                             >
                                             @error("items.{$index}.available_qty")
                                                 <div class="invalid-feedback">{{ $message }}</div>
                                             @enderror
                                         </div>
                                         @foreach($relevantDimensions as $dimension)
                                             <div>
                                                 <label class="availability-field-label" for="available{{ ucfirst(str_replace('_', '', $dimension)) }}{{ $index }}">
                                                     {{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }}
                                                 </label>
                                                 <input
                                                     id="available{{ ucfirst(str_replace('_', '', $dimension)) }}{{ $index }}"
                                                     type="number"
                                                     min="0"
                                                     step="0.0001"
                                                     name="items[{{ $index }}][available_{{ $dimension }}]"
                                                     class="form-control form-control-sm availability-input quotation-editable quotation-number @error("items.{$index}.available_{$dimension}") is-invalid @enderror"
                                                     data-availability-field="{{ $dimension }}"
                                                     value="{{ old("items.{$index}.available_{$dimension}", $qItem?->{'available_'.$dimension}) }}"
                                                 >
                                                 @error("items.{$index}.available_{$dimension}")
                                                     <div class="invalid-feedback">{{ $message }}</div>
                                                 @enderror
                                             </div>
                                         @endforeach
                                     </div>
                                 </td>
                                 <td class="text-end fw-medium quotation-number">{{ number_format($item->quantity_value, 0) }}</td>
                                <td class="text-end quotation-number">{{ number_format($item->weight_needed, 2) }}</td>
                                <td class="text-end fw-semibold text-primary quotation-number">{{ number_format($item->total_weight, 2) }}</td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.01"
                                        name="items[{{ $index }}][price_per_kg]"
                                        class="form-control form-control-sm price-input text-end quotation-editable quotation-number @error("items.{$index}.price_per_kg") is-invalid @enderror"
                                        value="{{ $oldPrice }}"
                                        aria-label="Price per kilogram for {{ $item->material_name }}"
                                        required
                                    >
                                    @error("items.{$index}.price_per_kg")
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm amount-display text-end quotation-calculated quotation-number" aria-label="Calculated amount for {{ $item->material_name }}" readonly>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm idr-display text-end quotation-calculated quotation-number" aria-label="Estimated IDR for {{ $item->material_name }}" readonly>
                                </td>
                                <td>
                                    <textarea
                                        name="items[{{ $index }}][notes]"
                                        class="form-control form-control-sm quotation-item-notes quotation-editable @error("items.{$index}.notes") is-invalid @enderror"
                                        rows="3"
                                        aria-label="Item notes for {{ $item->material_name }}"
                                        placeholder="Optional, e.g. price tolerance, MOQ, or material notes"
                                    >{{ $oldNotes }}</textarea>
                                    @error("items.{$index}.notes")
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td>
                                    <div class="mtc-upload @error("items.{$index}.mtc_file") border-danger @enderror">
                                        <input
                                            id="mtcFile{{ $index }}"
                                            type="file"
                                            name="items[{{ $index }}][mtc_file]"
                                            class="visually-hidden mtc-file-input @error("items.{$index}.mtc_file") is-invalid @enderror"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                        >
                                        <label for="mtcFile{{ $index }}" class="btn btn-sm btn-outline-primary mtc-file-button">
                                            <i class="bi bi-paperclip me-1"></i> Choose MTC File
                                        </label>
                                        <div
                                            class="mtc-file-name"
                                            data-default-name="{{ $mtcAttachment?->file_name ?? 'No file selected' }}"
                                            aria-live="polite"
                                        >{{ $mtcAttachment?->file_name ?? 'No file selected' }}</div>
                                        @if($mtcAttachment)
                                            <a href="{{ route('attachments.show', $mtcAttachment->id) }}" class="small d-inline-flex align-items-center gap-1 mt-2 text-decoration-none" target="_blank" rel="noopener">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                                View current file
                                            </a>
                                        @endif
                                        <div class="form-text small">Optional, PDF/JPG/PNG, max. 5MB.</div>
                                    </div>
                                    @error("items.{$index}.mtc_file")
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="7" class="text-end">TOTAL</td>
                            <td class="text-end" id="totalAmount">0.00</td>
                            <td class="text-end text-primary" id="totalIdr">0</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <x-ui.card title="Additional Information" description="These dates and terms are required for Purchasing evaluation.">
        <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
            <x-ui.input type="date" name="estimated_delivery" label="Estimated Delivery Time" :value="optional($quotation?->estimated_delivery)->format('Y-m-d')" required />
            <x-ui.input type="date" name="validity_period" id="validityPeriod" label="Quotation Valid Until" :value="optional($quotation?->validity_period)->format('Y-m-d')" :min="now()->toDateString()" helper="Required for final submission. Prices and terms remain valid until this date." />
            <x-ui.textarea name="payment_terms" label="Payment Terms" :rows="2" maxlength="100" required placeholder="Example: TT 30 Days" :value="$quotation->payment_terms ?? 'TT 30 Days'" />
            <x-ui.textarea name="general_notes" label="General Notes" :rows="2" placeholder="Optional..." :value="$quotation->general_notes ?? ''" />
        </div>
    </x-ui.card>

    <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2 tw-pb-4">
        <x-ui.button type="button" variant="secondary" onclick="submitForm('draft')">{{ $quotation?->status === 'revision_requested' ? 'Save Revision' : 'Save Draft' }}</x-ui.button>
        <x-ui.button type="button" onclick="confirmSubmit()"><i class="bi bi-send-check"></i> {{ $quotation?->status === 'revision_requested' ? 'Resubmit Quotation' : 'Send Final Quotation' }}</x-ui.button>
    </div>
</form>

</div>

<div class="modal fade" id="quotationImportModal" tabindex="-1" aria-labelledby="quotationImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="quotationImportModalLabel">Import Quotation Items from Excel</h5>
                    <div class="small text-muted">Imported values are mapped by PR Item ID and are not saved automatically.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="quotationImportFile" class="form-label fw-medium">Spreadsheet File</label>
                        <input type="file" id="quotationImportFile" class="form-control" accept=".xlsx,.xls,.csv">
                        <div class="form-text">Use the template for this PR. XLSX, XLS, or CSV; maximum 10 MB and 1,000 data rows.</div>
                    </div>
                    <div class="col-md-5">
                        <label for="quotationImportMode" class="form-label fw-medium">Import Mode</label>
                        <select id="quotationImportMode" class="form-select" aria-describedby="quotationImportModeHelp">
                            <option value="fill_empty" selected>Fill Empty Fields Only</option>
                            <option value="replace">Replace Imported Fields</option>
                        </select>
                        <div id="quotationImportModeHelp" class="form-text">Choose how validated Excel values update the current quotation.</div>
                    </div>
                </div>

                <div id="quotationImportResult" class="d-none mt-4">
                    <div id="quotationImportSummary" class="alert alert-light border py-2 mb-3"></div>

                    <div id="quotationImportWarningsPanel" class="alert alert-warning d-none">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Warnings</div>
                        <ul id="quotationImportWarnings" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="quotationImportErrorsPanel" class="alert alert-danger d-none">
                        <div class="fw-semibold mb-1"><i class="bi bi-x-circle me-1"></i>Import Errors</div>
                        <ul id="quotationImportErrors" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="quotationImportPreviewPanel" class="d-none">
                        <div class="fw-semibold mb-2">Parsed Item Preview</div>
                        <div class="table-responsive border rounded tw-max-h-[330px]">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>PR Item ID</th>
                                        <th>Price/Kg</th>
                                        <th>Available Qty</th>
                                        <th>Thickness</th>
                                        <th>Inner D.</th>
                                        <th>Outer D.</th>
                                        <th>Width</th>
                                        <th>Length</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="quotationImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-primary" id="btnParseQuotationImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="quotationImportSpinner"></span>
                    Parse &amp; Validate
                </button>
                <button type="button" class="btn btn-primary" id="btnApplyQuotationImport" disabled>
                    <i class="bi bi-check2-circle me-1"></i> Apply to Form
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Exchange rate data for JS --}}
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
        available_d_inner: '[data-availability-field="d_inner"]',
        available_d_outer: '[data-availability-field="d_outer"]',
        available_width: '[data-availability-field="width"]',
        available_length: '[data-availability-field="length"]',
        notes: '.quotation-item-notes'
    };

    function quotationRowForPrItem(prItemId) {
        return $('input[name^="items"][name$="[pr_item_id]"]').filter(function() {
            return String($(this).val()) === String(prItemId);
        }).first().closest('tr');
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
            `Total: ${summary.total || 0} | Valid: ${summary.valid || 0} | Invalid: ${summary.invalid || 0}`
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
                row.price_per_kg,
                row.available_qty ?? '-',
                row.available_thickness ?? '-',
                row.available_d_inner ?? '-',
                row.available_d_outer ?? '-',
                row.available_width ?? '-',
                row.available_length ?? '-',
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

        const file = document.getElementById('quotationImportFile').files[0];
        if (!file) {
            AdasiAlert.warning({
                title: 'File Required',
                text: 'Select an XLSX, XLS, or CSV file first.'
            });
            return;
        }

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

            return Object.entries(quotationImportFieldSelectors).some(([field, selector]) => {
                const $input = $formRow.find(selector);
                if ($input.length === 0) {
                    return false;
                }

                const current = String($input.val() ?? '').trim();
                const incoming = String(row[field] ?? '').trim();
                return current !== '' && current !== incoming;
            });
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

            Object.entries(quotationImportFieldSelectors).forEach(([field, selector]) => {
                const $input = $formRow.find(selector);
                if ($input.length === 0) {
                    return;
                }

                const incoming = row[field] ?? '';
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
        });

        calculateTotal();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('quotationImportModal')).hide();
        AdasiAlert.toast({
            type: 'success',
            title: 'Import Applied',
            text: `${changedFields} field(s) across ${importedItems} item(s) were applied. Review the quotation before saving.`,
            duration: 2400
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
                text: 'Imported values, including blank optional fields, will replace values currently entered for matching PR items.',
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

    function calculateRow(row) {
        const weight = parseFloat(row.find('.item-weight').val()) || 0;
        const price = parseFloat(row.find('.price-input').val()) || 0;

        const amount = weight * price;
        const idr = amount * selectedRate();

        row.find('.amount-display').val(amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        row.find('.idr-display').val(Math.round(idr).toLocaleString('id-ID'));

        return { amount, idr };
    }

    function calculateTotal() {
        let totalAmount = 0;
        let totalIdr = 0;

        $('.quotation-items-table tbody tr').each(function() {
            const rowTotals = calculateRow($(this));
            totalAmount += rowTotals.amount;
            totalIdr += rowTotals.idr;
        });

        $('#totalAmount').text(totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#totalIdr').text('Rp ' + Math.round(totalIdr).toLocaleString('id-ID'));
    }

    $('.price-input').on('input', function() {
        calculateTotal();
    });

    function copyRequestedValues(button) {
        const row = button.closest('tr');
        const values = {
            qty: button.dataset.requestedQty,
            thickness: button.dataset.requestedThickness,
            d_inner: button.dataset.requestedDInner,
            d_outer: button.dataset.requestedDOuter,
            width: button.dataset.requestedWidth,
            length: button.dataset.requestedLength,
        };
        let copied = false;

        Object.entries(values).forEach(([field, value]) => {
            const input = row.querySelector(`[data-availability-field="${field}"]`);
            if (input && value !== undefined && value !== '') {
                input.value = value;
                $(input).trigger('input');
                copied = true;
            }
        });

        if (copied) {
            row.classList.add('availability-copied');
            setTimeout(() => row.classList.remove('availability-copied'), 900);
        }

        return copied;
    }

    function showCopyFeedback(message) {
        if (typeof AdasiAlert !== 'undefined') {
            AdasiAlert.toast({
                type: 'success',
                title: message,
                duration: 1800
            });
        }
    }

    $(document).ready(function() {
        $('#btnParseQuotationImport').on('click', parseQuotationImport);
        $('#btnApplyQuotationImport').on('click', applyQuotationImport);
        $('.mtc-file-input').on('change', function() {
            const fileName = this.files?.[0]?.name;
            const $fileName = $(this).closest('.mtc-upload').find('.mtc-file-name');
            $fileName.text(fileName || $fileName.data('default-name') || 'No file selected');
        });
        $('#quotationImportModal').on('hidden.bs.modal', function() {
            if (quotationImportRequestInFlight) {
                return;
            }

            document.getElementById('quotationImportFile').value = '';
            $('#quotationImportMode').val('fill_empty');
            $('#quotationImportResult').addClass('d-none');
            $('#quotationImportWarnings, #quotationImportErrors, #quotationImportPreviewBody').empty();
            $('#btnApplyQuotationImport').prop('disabled', true);
            quotationImportRows = [];
        });

        function refreshCurrencyState() {
            const currency = selectedCurrency();
            $('.currency-label').text(currency || '-');
            $('#currencyWarningLabel').text(currency || '-');
            $('#currencyRateWarning').toggleClass('d-none', !currency || selectedRate() > 0);
            calculateTotal();
        }

        $('#quotationCurrency').on('change', refreshCurrencyState);
        $('.copy-from-pr-btn').on('click', function() {
            if (copyRequestedValues(this)) {
                showCopyFeedback('Requested values copied. Review before saving.');
            }
        });

        $('#copyAllRequested').on('click', function() {
            let copiedRows = 0;
            $('.copy-from-pr-btn').each(function() {
                if (copyRequestedValues(this)) {
                    copiedRows++;
                }
            });

            if (copiedRows > 0) {
                showCopyFeedback(`Requested values copied for ${copiedRows} item(s). Review before saving.`);
            }
        });
        refreshCurrencyState();
        calculateTotal(); // initial calculation if pre-filled
    });

    function submitForm(action) {
        $('#formAction').val(action);
        $('#quotationForm').submit();
    }

    function confirmSubmit() {
        // Validate required fields visually
        let isValid = true;
        $('#quotationForm').find('input[required], select[required], #validityPeriod').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        if (!isValid) {
            AdasiAlert.error({
                title: 'Error',
                text: 'Please complete all required fields: Currency, Price, Estimated Delivery Time, and Quotation Valid Until.'
            });
            return;
        }

        AdasiAlert.confirm({
            title: {!! json_encode($quotation?->status === 'revision_requested' ? 'Resubmit Quotation?' : 'Send Final Quotation?') !!},
            text: {!! json_encode($quotation?->status === 'revision_requested' ? 'The revised quotation will be sent back to Purchasing for evaluation.' : 'Submitted quotations cannot be changed anymore.') !!},
            type: 'warning',
            confirmText: @json($quotation?->status === 'revision_requested' ? 'Yes, Resubmit!' : 'Yes, Send!'),
            cancelText: @json('Cancel')
        }).then((result) => {
            if (result.isConfirmed) {
                // Clear draft on submit
                localStorage.removeItem(draftKey);
                $('#formAction').val('submitted');
                $('#quotationForm').submit();
            }
        });
    }

    // Auto-save logic
    const prId = '{{ $pr->id }}';
    const draftKey = 'quotation_draft_' + prId;

    function saveDraft() {
        const formData = $('#quotationForm').serializeArray();
        const data = {};
        $(formData).each(function(index, obj) {
            if(obj.name !== '_token' && obj.name !== 'action') {
                data[obj.name] = obj.value;
            }
        });
        localStorage.setItem(draftKey, JSON.stringify(data));
        
        $('#autoSaveBadge').removeClass('d-none').addClass('d-inline-block').html('<i class="bi bi-cloud-check me-1"></i>Draft Auto-saved');
        setTimeout(() => {
            $('#autoSaveBadge').removeClass('d-inline-block').addClass('d-none');
        }, 2000);
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
            calculateTotal();
            
            // Show badge permanently if draft loaded
            $('#autoSaveBadge').removeClass('d-none').addClass('d-inline-block').html('<i class="bi bi-cloud-check me-1"></i>Draft Saved');
        }
    }

    $(document).ready(function() {
        loadDraft();

        let isDirty = false;
        let autoSaveTimer;
        $('#quotationForm input, #quotationForm select, #quotationForm textarea').on('input change', function() {
            isDirty = true;
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraft, 1000);
        });

        $('#quotationForm').on('submit', function() {
            isDirty = false;
        });

        $(window).on('beforeunload', function() {
            if (isDirty) {
                return 'You have unsaved changes. Are you sure you want to leave this page?';
            }
        });
    });
</script>
@endpush
