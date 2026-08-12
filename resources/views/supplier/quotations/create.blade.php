@extends('layouts.app')

@section('title', 'Quotation Price Form - ADASI Portal')
@section('page-title', 'Form Quotation Price')

@push('styles')
<style>
    .quotation-items-table {
        min-width: 1780px;
    }

    .quotation-item-notes {
        min-width: 220px;
        min-height: 76px;
        line-height: 1.35;
        resize: vertical;
    }

    .availability-panel {
        min-width: 285px;
    }

    .availability-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(82px, 1fr));
        gap: .5rem;
    }

    .availability-field-label {
        display: block;
        margin-bottom: .2rem;
        color: #6c757d;
        font-size: .68rem;
        font-weight: 600;
    }

    .availability-copied .availability-panel {
        background-color: #edf7ef !important;
        transition: background-color .2s ease;
    }

    @media (max-width: 991.98px) {
        .availability-grid {
            grid-template-columns: repeat(2, minmax(100px, 1fr));
        }
    }
</style>
@endpush

@section('content')
<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <a href="{{ route('supplier.quotations.period', $pr->period_id) }}" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Back to Requisition List
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Purchase Requisition Details</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-2 text-muted small">Period</div>
            <div class="col-md-10 fw-medium">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</div>
        </div>
        <div class="row mt-2">
            <div class="col-md-2 text-muted small">Notes PR</div>
            <div class="col-md-10">{{ $pr->notes ?? '-' }}</div>
        </div>
    </div>
</div>

@if($quotation?->status === 'revision_requested')
    <div class="alert alert-warning border-0 shadow-sm">
        <div class="fw-semibold mb-1"><i class="bi bi-arrow-repeat me-1"></i> Quotation Revision Requested</div>
        <div class="small mb-0">
            Purchasing asked this quotation to be resubmitted. Update the price, estimated delivery, validity date, and notes if needed.
        </div>
    </div>
@endif

<form id="quotationForm" action="{{ route('supplier.quotations.store', $pr) }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="action" id="formAction" value="draft">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold">
                Material Price Entry
                <span id="autoSaveBadge" class="badge bg-success ms-2 d-none opacity-75"><i class="bi bi-cloud-check me-1"></i>Draft Auto-saved</span>
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                @include('supplier.quotations._import_controls')
                <button type="button" id="copyAllRequested" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-clipboard-check me-1"></i> Copy All Requested Values
                </button>
                <label for="quotationCurrency" class="small fw-medium text-muted mb-0">Currency:</label>
                <select name="currency" id="quotationCurrency" class="form-select form-select-sm" style="width: 110px;" required>
                    <option value="" disabled @selected($supplierCurrency === '')>Select</option>
                    @foreach($currencyOptions as $currency)
                        <option value="{{ $currency }}" @selected(old('currency', $supplierCurrency) === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div id="currencyRateWarning" class="alert alert-warning rounded-0 border-0 border-top border-bottom mb-0 small {{ $supplierCurrency && ! $supplierRate ? '' : 'd-none' }}">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Exchange rate for <span id="currencyWarningLabel">{{ $supplierCurrency ?: '-' }}</span> is not available yet. Contact Admin before submitting the final quotation.
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 quotation-items-table" style="font-size: 0.85rem;">
                    <thead class="table-light text-center">
                        <tr>
                             <th width="3%">No</th>
                             <th width="15%" style="min-width: 150px;">Material & Specification</th>
                             <th width="16%" class="availability-panel">Supplier Availability</th>
                             <th width="4%">Qty</th>
                            <th width="7%">Weight/Unit (Kg)</th>
                            <th width="8%">Total Weight (Kg)</th>
                            <th width="12%" style="min-width: 130px;">Price per-KG (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>) <span class="text-danger">*</span></th>
                            <th width="12%" style="min-width: 130px;">Amount (<span class="currency-label">{{ $supplierCurrency ?: '-' }}</span>)</th>
                            <th width="12%" style="min-width: 130px;">Est. IDR</th>
                            <th width="13%" style="min-width: 150px;">Notes Item</th>
                            <th width="14%" style="min-width: 220px;">MTC</th>
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
                                <td class="text-center">{{ $index + 1 }}</td>
                                <td>
                                     <div class="fw-bold">{{ $item->material_name }}</div>
                                     <div class="text-muted" style="font-size: 0.75rem;">
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
                                                 class="form-control form-control-sm availability-input"
                                                 data-availability-field="qty"
                                                 value="{{ old("items.{$index}.available_qty", $qItem?->available_qty) }}"
                                             >
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
                                                     class="form-control form-control-sm availability-input"
                                                     data-availability-field="{{ $dimension }}"
                                                     value="{{ old("items.{$index}.available_{$dimension}", $qItem?->{'available_'.$dimension}) }}"
                                                 >
                                             </div>
                                         @endforeach
                                     </div>
                                 </td>
                                 <td class="text-center fw-medium">{{ number_format($item->quantity_value, 0) }}</td>
                                <td class="text-center">{{ number_format($item->weight_needed, 2) }}</td>
                                <td class="text-center fw-medium text-primary">{{ number_format($item->total_weight, 2) }}</td>
                                <td>
                                    <input type="number" step="0.0001" name="items[{{ $index }}][price_per_kg]" class="form-control form-control-sm price-input text-end" value="{{ $oldPrice }}" required>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm amount-display text-end bg-light" readonly>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm idr-display text-end bg-light" readonly>
                                </td>
                                <td>
                                    <textarea
                                        name="items[{{ $index }}][notes]"
                                        class="form-control form-control-sm quotation-item-notes"
                                        rows="3"
                                        placeholder="Optional, e.g. price tolerance, MOQ, or material notes"
                                    >{{ $oldNotes }}</textarea>
                                </td>
                                <td>
                                    <input type="file" name="items[{{ $index }}][mtc_file]" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                                    @if($mtcAttachment)
                                        <a href="{{ route('attachments.show', $mtcAttachment->id) }}" class="small d-inline-flex align-items-center gap-1 mt-1 text-decoration-none" target="_blank">
                                            <i class="bi bi-paperclip"></i>
                                            {{ $mtcAttachment->file_name }}
                                        </a>
                                    @else
                                        <div class="form-text small">Optional, PDF/JPG/PNG max. 5MB.</div>
                                    @endif
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">Additional Information</h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Estimated Delivery Time <span class="text-danger">*</span></label>
                    <input type="date" name="estimated_delivery" class="form-control" value="{{ old('estimated_delivery', optional($quotation?->estimated_delivery)->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Quotation Valid Until <span class="text-danger">*</span></label>
                    <input type="date"
                           name="validity_period"
                           id="validityPeriod"
                           class="form-control @error('validity_period') is-invalid @enderror"
                           value="{{ old('validity_period', optional($quotation?->validity_period)->format('Y-m-d')) }}"
                           min="{{ now()->toDateString() }}">
                    <div class="form-text">Required when submitting the final quotation. Prices and terms are valid until this date.</div>
                    @error('validity_period')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Payment Terms</label>
                    <textarea name="payment_terms" class="form-control" rows="2" maxlength="100" required placeholder="Contoh: TT 30 Days">{{ old('payment_terms', $quotation->payment_terms ?? 'TT 30 Days') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Notes Umum</label>
                    <textarea name="general_notes" class="form-control" rows="2" placeholder="Optional...">{{ old('general_notes', $quotation->general_notes ?? '') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">
            {{ $quotation?->status === 'revision_requested' ? 'Save Revision' : 'Save Draft' }}
        </button>
        <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">
            {{ $quotation?->status === 'revision_requested' ? 'Resubmit Quotation' : 'Send Final Quotation' }}
        </button>
    </div>
</form>

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
                        <div class="table-responsive border rounded" style="max-height: 330px;">
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

        $('tbody tr').each(function() {
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
