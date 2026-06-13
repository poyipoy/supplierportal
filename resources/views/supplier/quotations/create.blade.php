@extends('layouts.app')

@section('title', 'Quotation Price Form - ADASI Portal')
@section('page-title', 'Form Quotation Price')

@push('styles')
<style>
    .quotation-items-table {
        min-width: 1500px;
    }

    .quotation-item-notes {
        min-width: 220px;
        min-height: 76px;
        line-height: 1.35;
        resize: vertical;
    }
</style>
@endpush

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.quotations.period', $pr->period_id) }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Back to Requisition List
    </a>
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
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                Material Price Entry
                <span id="autoSaveBadge" class="badge bg-success ms-2 d-none opacity-75"><i class="bi bi-cloud-check me-1"></i>Draft Auto-saved</span>
            </h6>
            <div class="d-flex align-items-center gap-2">
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
                                    <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                    <input type="hidden" class="item-weight" value="{{ $item->total_weight }}">
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
                            <td colspan="6" class="text-end">TOTAL</td>
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

{{-- Exchange rate data for JS --}}
<div id="exchangeRates" class="d-none"></div>

@endsection

@push('scripts')
<script>
    const currencyRates = @json($currencyRates);

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

    $(document).ready(function() {
        function refreshCurrencyState() {
            const currency = selectedCurrency();
            $('.currency-label').text(currency || '-');
            $('#currencyWarningLabel').text(currency || '-');
            $('#currencyRateWarning').toggleClass('d-none', !currency || selectedRate() > 0);
            calculateTotal();
        }

        $('#quotationCurrency').on('change', refreshCurrencyState);
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
            Swal.fire('Error', 'Please complete all required fields: Currency, Price, Estimated Delivery Time, and Quotation Valid Until.', 'error');
            return;
        }

        Swal.fire({
            title: {!! json_encode($quotation?->status === 'revision_requested' ? 'Resubmit Quotation?' : 'Send Final Quotation?') !!},
            text: {!! json_encode($quotation?->status === 'revision_requested' ? 'The revised quotation will be sent back to Purchasing for evaluation.' : 'Submitted quotations cannot be changed anymore.') !!},
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: @json($quotation?->status === 'revision_requested' ? 'Yes, Resubmit!' : 'Yes, Send!'),
            cancelButtonText: @json('Cancel')
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
