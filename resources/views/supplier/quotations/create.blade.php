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
        font-size: var(--ui-font-size-sm);
        max-width: none !important;
        min-width: 1957px !important;
        table-layout: fixed !important;
        width: 1957px !important;
    }

    .quotation-items-table th,
    .quotation-items-table td {
        padding: 0.65rem 0.55rem;
    }

    .quotation-col-number { width: 50px; }
    .quotation-col-material { width: 280px; }
    .quotation-col-availability { width: 310px; }
    .quotation-col-qty { width: 80px; }
    .quotation-col-weight { width: 120px; }
    .quotation-col-total-weight { width: 130px; }
    .quotation-col-commercial { width: 160px; }
    .quotation-col-notes { width: 220px; }
    .quotation-col-mtc { width: 230px; }

    .quotation-items-table .quotation-group-header th {
        background: var(--md-primary) !important;
        color: var(--md-on-primary) !important;
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        height: 36px;
        position: sticky;
        top: 0;
        z-index: 8;
    }

    .quotation-items-table .quotation-field-header th {
        background: var(--md-surface-container-low) !important;
        color: var(--md-on-surface-variant) !important;
        font-size: var(--ui-font-size-xs);
        font-weight: 600;
        height: 44px;
        position: sticky;
        top: 36px;
        z-index: 8;
    }

    .quotation-items-table .quotation-group-item,
    .quotation-items-table .quotation-sticky-material {
        border-right: 1px solid var(--md-outline) !important;
    }

    .quotation-items-table .quotation-group-item {
        left: 0;
        position: sticky;
        width: 330px;
        min-width: 330px;
        z-index: 12 !important;
    }

    .quotation-items-table .quotation-sticky-number {
        background-color: var(--md-surface) !important;
        border-right: 1px solid var(--md-outline) !important;
        left: 0;
        min-width: 50px;
        width: 50px;
        position: sticky;
        z-index: 5;
    }

    .quotation-items-table .quotation-sticky-material {
        background-color: var(--md-surface) !important;
        border-right: 1px solid var(--md-outline) !important;
        left: 50px;
        min-width: 280px;
        width: 280px;
        position: sticky;
        z-index: 6;
    }

    .quotation-items-table thead .quotation-sticky-number,
    .quotation-items-table thead .quotation-sticky-material {
        background-color: var(--md-surface-container-low) !important;
        z-index: 11;
    }

    .quotation-items-table tbody tr:hover > td,
    .quotation-items-table tbody tr:hover > .quotation-sticky-number,
    .quotation-items-table tbody tr:hover > .quotation-sticky-material {
        background-color: var(--md-surface-container) !important;
    }

    .quotation-items-table .quotation-editable {
        background: var(--md-surface);
        border-color: var(--md-outline);
    }

    .quotation-items-table .quotation-editable:focus {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 var(--ui-focus-ring-width) rgba(var(--md-primary-rgb), .15);
    }

    .quotation-items-table .quotation-calculated {
        background: var(--md-surface-container-low) !important;
        border-color: var(--md-outline-variant);
        color: var(--md-on-surface);
        font-weight: 600;
        cursor: default;
    }

    .quotation-item-notes {
        min-height: 80px;
        font-size: var(--ui-font-size-xs);
        resize: vertical;
    }

    .availability-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(110px, 1fr));
        gap: 0.35rem;
    }

    .availability-field-label {
        display: block;
        margin-bottom: 0.15rem;
        color: var(--md-on-surface-variant);
        font-size: var(--ui-font-size-xs);
        font-weight: 600;
        text-transform: uppercase;
    }

    .mtc-upload {
        background: var(--md-surface-container-low);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        padding: 0.5rem;
    }

    .mtc-file-name {
        color: var(--md-on-surface-variant);
        font-size: var(--ui-font-size-xs);
        margin-top: 0.3rem;
        overflow-wrap: anywhere;
    }

    .availability-copied .availability-panel {
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

                {{-- Material Entry Table --}}
                <div class="border rounded overflow-hidden">
                    <div id="quotationTableScrollHint" class="tw-bg-surface-low border-bottom px-3 tw-py-1.5 tw-text-on-surface-variant tw-text-ui-xs d-flex align-items-center tw-gap-1.5">
                    <x-ui.icon name="move-horizontal" size="sm" />
                        <span>Scroll horizontally to review all availability and commercial columns.</span>
                    </div>

                    <div class="table-responsive quotation-table-scroll" role="region" tabindex="0" aria-label="Material quotation price entry" aria-describedby="quotationTableScrollHint">
                        <table class="table table-bordered align-middle mb-0 quotation-items-table tw-text-ui-xs">
                            <caption class="visually-hidden">Supplier quotation entry table</caption>
                            <colgroup>
                                <col class="quotation-col-number">
                                <col class="quotation-col-material">
                                <col class="quotation-col-availability">
                                <col class="quotation-col-qty">
                                <col class="quotation-col-weight">
                                <col class="quotation-col-total-weight">
                                <col class="quotation-col-commercial">
                                <col class="quotation-col-commercial">
                                <col class="quotation-col-commercial">
                                <col class="quotation-col-notes">
                                <col class="quotation-col-mtc">
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
                                        <td class="text-center quotation-sticky-number tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                        <td class="quotation-sticky-material">
                                            <div class="fw-bold tw-text-on-surface">{{ $item->material_name }}</div>
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">
                                                @if($item->hs_code) <span class="fw-semibold">HS:</span> {{ $item->hs_code }} &bull; @endif
                                                @if($item->shape)
                                                    {{ $item->shape }}: {{ $item->dimension_label }}
                                                @else
                                                    -
                                                @endif
                                            </div>
                                            @if($item->remark)
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs mt-1"><span class="fw-semibold">Remark:</span> {{ $item->remark }}</div>
                                            @endif
                                            <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                            <input type="hidden" class="item-weight" value="{{ $item->total_weight }}">
                                            @error("items.{$index}.pr_item_id")
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td class="availability-panel">
                                            <div class="d-flex justify-content-between align-items-center gap-2 tw-mb-1.5">
                                                <span class="tw-text-ui-xs fw-semibold text-primary"><x-ui.icon name="package" size="sm" class="me-1" />Offered Specs</span>
                                                <x-ui.button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    class="copy-from-pr-btn"
                                                    data-requested-qty="{{ $item->quantity_value }}"
                                                    data-requested-thickness="{{ $item->thickness !== null ? (float) $item->thickness : '' }}"
                                                    data-requested-d-inner="{{ $item->d_inner !== null ? (float) $item->d_inner : '' }}"
                                                    data-requested-d-outer="{{ $item->d_outer !== null ? (float) $item->d_outer : '' }}"
                                                    data-requested-width="{{ $item->width !== null ? (float) $item->width : '' }}"
                                                    data-requested-length="{{ $item->length !== null ? (float) $item->length : '' }}"
                                                    title="Copy requested quantity and dimensions"
                                                >
                                                        <x-ui.icon name="clipboard-check" size="sm" /> Copy
                                                </x-ui.button>
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
                                                        class="form-control form-control-sm availability-input quotation-editable ui-tabular-nums @error("items.{$index}.available_qty") is-invalid @enderror"
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
                                                            class="form-control form-control-sm availability-input quotation-editable ui-tabular-nums @error("items.{$index}.available_{$dimension}") is-invalid @enderror"
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
                                        <td class="text-center fw-bold ui-tabular-nums">{{ number_format($item->quantity_value, 0) }}</td>
                                        <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ number_format($item->weight_needed, 2) }}</td>
                                        <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($item->total_weight, 2) }}</td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.0001"
                                                min="0.01"
                                                name="items[{{ $index }}][price_per_kg]"
                                                class="form-control form-control-sm price-input text-end quotation-editable ui-tabular-nums @error("items.{$index}.price_per_kg") is-invalid @enderror"
                                                value="{{ $oldPrice }}"
                                                aria-label="Price per kilogram for {{ $item->material_name }}"
                                                placeholder="0.0000"
                                                required
                                            >
                                            @error("items.{$index}.price_per_kg")
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm amount-display text-end quotation-calculated ui-tabular-nums" aria-label="Calculated amount for {{ $item->material_name }}" readonly>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm idr-display text-end quotation-calculated ui-tabular-nums" aria-label="Estimated IDR for {{ $item->material_name }}" readonly>
                                        </td>
                                        <td>
                                            <textarea
                                                name="items[{{ $index }}][notes]"
                                                class="form-control form-control-sm quotation-item-notes quotation-editable @error("items.{$index}.notes") is-invalid @enderror"
                                                rows="2"
                                                aria-label="Item notes for {{ $item->material_name }}"
                                                placeholder="Optional tolerances, MOQ, or notes..."
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
                                                <label for="mtcFile{{ $index }}" class="ui-motion ui-focus-ring tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-w-full tw-cursor-pointer tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">
                                                        <x-ui.icon name="paperclip" size="sm" class="me-1" /> Choose MTC File
                                                </label>
                                                <div
                                                    class="mtc-file-name text-truncate"
                                                    data-default-name="{{ $mtcAttachment?->file_name ?? 'No file selected' }}"
                                                    aria-live="polite"
                                                >{{ $mtcAttachment?->file_name ?? 'No file selected' }}</div>
                                                @if($mtcAttachment)
                                                    <a href="{{ route('attachments.show', $mtcAttachment->id) }}" class="tw-text-ui-xs d-inline-flex align-items-center gap-1 mt-1 text-decoration-none" target="_blank" rel="noopener">
                                                            <x-ui.icon name="external-link" size="sm" />
                                                        View file
                                                    </a>
                                                @endif
                                                <div class="tw-text-outline tw-text-ui-xs tw-mt-0.5">PDF/JPG/PNG, max 5MB.</div>
                                            </div>
                                            @error("items.{$index}.mtc_file")
                                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                            @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light fw-bold border-top">
                                <tr>
                                    <td colspan="7" class="text-end tw-text-on-surface">GRAND TOTAL:</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums" id="totalAmount">0.00</td>
                                    <td class="text-end text-primary ui-tabular-nums fs-6" id="totalIdr">Rp 0</td>
                                    <td colspan="2"></td>
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
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <x-ui.input
                        type="date"
                        name="estimated_delivery"
                        label="Estimated Material Delivery Time"
                        :value="optional($quotation?->estimated_delivery)->format('Y-m-d')"
                        required
                    />
                    <x-ui.input
                        type="date"
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
                        placeholder="Example: TT 30 Days after BL date"
                        :value="$quotation->payment_terms ?? 'TT 30 Days'"
                    />
                    <x-ui.textarea
                        name="general_notes"
                        label="General Supplier Notes"
                        :rows="2"
                        placeholder="Optional instructions or terms..."
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
                <x-ui.button type="button" variant="secondary" size="sm" onclick="submitForm('draft')">
                    <span>{{ $quotation?->status === 'revision_requested' ? 'Save Revision Draft' : 'Save Draft' }}</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" onclick="confirmSubmit()">
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
                        <div id="quotationImportModeHelp" class="form-text tw-text-ui-xs">Choose how validated Excel values update the current quotation.</div>
                    </div>
                </div>

                <div id="quotationImportResult" class="d-none mt-3">
                    <div id="quotationImportSummary" class="tw-mb-2.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-px-3 tw-py-2 tw-text-ui-xs tw-font-semibold tw-text-on-surface" role="status"></div>

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
                                        <th scope="col">Price/Kg</th>
                                        <th scope="col">Available Qty</th>
                                        <th scope="col">Thickness</th>
                                        <th scope="col">Inner D.</th>
                                        <th scope="col">Outer D.</th>
                                        <th scope="col">Width</th>
                                        <th scope="col">Length</th>
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

    function calculateRow(row) {
        const weight = parseFloat(row.find('.item-weight').val()) || 0;
        const price = parseFloat(row.find('.price-input').val()) || 0;

        const amount = weight * price;
        const idr = amount * selectedRate();

        row.find('.amount-display').val(amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        row.find('.idr-display').val('Rp ' + Math.round(idr).toLocaleString('id-ID'));

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

    const requestedValueAttributes = {
        qty: 'data-requested-qty',
        thickness: 'data-requested-thickness',
        d_inner: 'data-requested-d-inner',
        d_outer: 'data-requested-d-outer',
        width: 'data-requested-width',
        length: 'data-requested-length',
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
        const row = button?.closest('tr');

        if (!row) {
            return false;
        }

        let copied = false;

        Object.entries(requestedValueAttributes).forEach(([field, attribute]) => {
            const value = normalizedRequestedValue(button.getAttribute(attribute));

            if (value === '') {
                return;
            }

            const input = row.querySelector(`[data-availability-field="${field}"]`);

            if (!input) {
                return;
            }

            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            copied = true;
        });

        if (copied) {
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
        $('.price-input').on('input', calculateTotal);
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

        refreshCurrencyState();
        calculateTotal();

        // Horizontal wheel scroll
        const qScroll = document.querySelector('.quotation-table-scroll');
        if (qScroll) {
            qScroll.addEventListener('wheel', function(e) {
                if (e.deltaY === 0 || this.scrollWidth <= this.clientWidth) return;
                const maxScroll = this.scrollWidth - this.clientWidth;
                const nextScroll = Math.max(0, Math.min(maxScroll, this.scrollLeft + e.deltaY));
                const consumed = nextScroll - this.scrollLeft;
                const remaining = e.deltaY - consumed;
                this.scrollLeft = nextScroll;
                e.preventDefault();
                if (remaining !== 0) window.scrollBy({ top: remaining, left: 0, behavior: 'auto' });
            }, { passive: false });
        }
    });

    function submitForm(action) {
        $('#formAction').val(action);
        $('#quotationForm').submit();
    }

    function confirmSubmit() {
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
            const firstInvalid = document.querySelector('#quotationForm .is-invalid');
            firstInvalid?.focus();
            firstInvalid?.reportValidity?.();
            return;
        }

        AdasiAlert.confirm({
            title: {!! json_encode($quotation?->status === 'revision_requested' ? 'Resubmit Quotation?' : 'Send Final Quotation?') !!},
            text: {!! json_encode($quotation?->status === 'revision_requested' ? 'The revised quotation will be sent back to Purchasing for evaluation.' : 'Submitted quotations cannot be modified after sending.') !!},
            type: 'warning',
            confirmText: @json($quotation?->status === 'revision_requested' ? 'Yes, Resubmit!' : 'Yes, Send!'),
            cancelText: @json('Cancel')
        }).then((result) => {
            if (result.isConfirmed) {
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
            calculateTotal();
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
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraft, 1200);
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
