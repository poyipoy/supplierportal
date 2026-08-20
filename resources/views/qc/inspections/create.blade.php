@extends('layouts.app')

@section('title', 'Start QC Inspection: ' . $po->po_number . ' - ADASI Portal')
@section('page-title', 'QC Inspection Material')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="'Inspect ' . $po->po_number" description="Compare each requested specification with actual measurements, mark OK/NG, and attach evidence for every NG item." eyebrow="QC">
        <x-slot:actions><x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to Inspection List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

{{-- Info PO Header --}}
<x-ui.card title="Purchase Order Reference" description="Read-only arrival and supplier context for this inspection.">
        <div class="row">
            <div class="col-md-4">
                <div class="text-muted small">Number PO</div>
                <div class="fw-bold fs-6">{{ $po->po_number }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Supplier</div>
                <div class="fw-bold">{{ $po->supplier->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Date Material Arrived</div>
                <div class="fw-bold">{{ $po->actual_arrival ? $po->actual_arrival->format('d F Y') : '-' }}</div>
            </div>
        </div>
</x-ui.card>

<x-ui.alert tone="success" title="Inspection Status: OK" class="d-none" id="bannerOk">All materials meet specifications.</x-ui.alert>

<x-ui.alert tone="error" title="Inspection Status: NG (Not Good)" class="d-none" id="bannerNg">One or more materials do not meet specifications. Upload evidence photos for every NG item.</x-ui.alert>

<form action="{{ route('qc.inspections.store', $po) }}" method="POST" enctype="multipart/form-data" id="inspectionForm">
    @csrf

    @php $allItems = $po->quotations->flatMap(fn($q) => $q->items); @endphp
    @php
        $qcDimensionLabels = [
            'thickness' => 'Thickness (mm)',
            'd_inner' => 'Inner Dia. (mm)',
            'd_outer' => 'Outer Dia. (mm)',
            'width' => 'Width (mm)',
            'length' => 'Length (mm)',
            'weight' => 'Weight/Unit (Kg)',
        ];
        $qcSpecFields = [
            'thickness' => 'thickness',
            'd_inner' => 'd_inner',
            'd_outer' => 'd_outer',
            'width' => 'width',
            'length' => 'length',
            'weight' => 'weight_needed',
        ];
        $qcActualFields = [
            'thickness' => 'actual_thickness',
            'd_inner' => 'actual_d_inner',
            'd_outer' => 'actual_d_outer',
            'width' => 'actual_width',
            'length' => 'actual_length',
            'weight' => 'actual_weight',
        ];
    @endphp
    @foreach($allItems as $index => $item)
        @php
            $prItem = $item->prItem;
            $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($prItem?->shape);
            $visibleDimensions = array_merge($relevantDimensions, ['weight']);
        @endphp
        <div class="card border-0 shadow-sm mb-4 item-card">
            <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Item #{{ $index + 1 }}: {{ $prItem->material_name }}</h6>
                <span class="badge bg-success item-status-badge" id="badge-status-{{ $index }}">OK</span>
            </div>
            <div class="card-body">
                <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $prItem->id }}">

                <div class="row g-4">
                    {{-- Read Only Specs --}}
                    <div class="col-md-5 border-md-end border-bottom border-md-bottom-0 pb-4 pb-md-0 mb-2 mb-md-0 pe-md-4">
                        <h6 class="fw-bold mb-3 small text-muted text-uppercase">Requested Specification</h6>
                        <div class="qc-spec-grid">
                            <div class="qc-spec-box">
                                <div class="small text-muted">Shape</div>
                                <div class="fw-semibold">{{ $prItem->shape ?? '-' }}</div>
                            </div>
                            <div class="qc-spec-box">
                                <div class="small text-muted">Quantity</div>
                                <div class="fw-semibold">{{ number_format($prItem->quantity_value, 0) }}</div>
                            </div>
                            @foreach($visibleDimensions as $dimension)
                                @php
                                    $specField = $qcSpecFields[$dimension];
                                    $specValue = $prItem?->{$specField};
                                @endphp
                                <div class="qc-spec-box">
                                    <div class="small text-muted">{{ $qcDimensionLabels[$dimension] }}</div>
                                    <div class="fw-semibold spec-val" data-spec-type="{{ $dimension }}" data-val="{{ $specValue }}">{{ $specValue ?? '-' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Actual Inputs --}}
                    <div class="col-md-7 ps-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold small text-primary text-uppercase mb-0">Input Aktual</h6>
                            <div class="text-end tw-min-w-[150px]">
                                @php $itemStatus = old('items.' . $index . '.status', 'ok'); @endphp
                                <div class="small text-muted mb-1">Status</div>
                                <div class="form-check form-switch d-inline-flex align-items-center gap-2 mb-0">
                                    <input type="hidden" name="items[{{ $index }}][status]" id="input-status-{{ $index }}" class="item-status-value" value="{{ $itemStatus === 'ng' ? 'ng' : 'ok' }}">
                                    <input class="form-check-input item-status-switch m-0" type="checkbox" role="switch" id="switch-status-{{ $index }}" data-index="{{ $index }}" @checked($itemStatus === 'ng')>
                                    <label class="form-check-label small fw-semibold {{ $itemStatus === 'ng' ? 'text-danger' : 'text-success' }}" for="switch-status-{{ $index }}" id="status-label-{{ $index }}">
                                        {{ $itemStatus === 'ng' ? 'NG' : 'OK' }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="input-row" data-index="{{ $index }}">
                            <div class="qc-dimension-grid">
                            @foreach($visibleDimensions as $dimension)
                                <div>
                                    <label class="form-label small" for="actual-{{ $index }}-{{ $dimension }}">{{ $qcDimensionLabels[$dimension] }}</label>
                                    <input type="number" step="any" id="actual-{{ $index }}-{{ $dimension }}" name="items[{{ $index }}][{{ $qcActualFields[$dimension] }}]" class="form-control form-control-sm actual-input" data-spec-type="{{ $dimension }}" value="{{ old('items.' . $index . '.' . $qcActualFields[$dimension]) }}">
                                </div>
                            @endforeach
                            </div>
                            <div class="mt-3">
                                <label class="form-label small" for="item-notes-{{ $index }}">Notes Item</label>
                                <textarea id="item-notes-{{ $index }}" name="items[{{ $index }}][notes]" class="form-control form-control-sm" rows="1" placeholder="Optional..."></textarea>
                            </div>
                        </div>

                        {{-- NG Photo Upload (Hidden by default) --}}
                        <div class="ng-photo-section mt-3 p-3 bg-danger bg-opacity-10 border border-danger rounded d-none" id="photo-section-{{ $index }}">
                            <label for="photo-input-{{ $index }}" class="form-label fw-bold text-danger small mb-2"><i class="bi bi-camera me-1"></i>NG Evidence Photos (Required)</label>
                            <input type="file" id="photo-input-{{ $index }}" name="attachments[{{ $index }}][]" class="form-control form-control-sm photo-input" accept=".jpg,.jpeg,.png" multiple disabled aria-describedby="photo-input-help-{{ $index }}">
                            <div class="form-text text-danger small" id="photo-input-help-{{ $index }}">Max 10MB per file. Select at least 1 photo because this item status is NG.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2 tw-pb-4">
        <x-ui.button :href="route('qc.inspections.index')" variant="ghost">Cancel</x-ui.button>
        <x-ui.button type="button" id="btnSubmit"><i class="bi bi-save"></i> Save Inspection Results</x-ui.button>
    </div>
</form>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        
        function evaluateItem(index) {
            const row = $(`.input-row[data-index="${index}"]`);
            const selectedStatus = $(`#input-status-${index}`).val();

            row.find('.actual-input').each(function() {
                const specType = $(this).data('spec-type');
                const actualVal = parseFloat($(this).val());
                
                const specEl = row.closest('.card-body').find(`.spec-val[data-spec-type="${specType}"]`);
                const specValStr = specEl.data('val');

                // Reset styling
                $(this).removeClass('is-invalid');

                if (!isNaN(actualVal) && specValStr !== '' && specValStr !== undefined) {
                    const specVal = parseFloat(specValStr);
                    if (!isNaN(specVal) && specVal > 0) {
                        const diff = Math.abs(actualVal - specVal);
                        const ratio = diff / specVal;
                        
                        if (ratio > 0.05) { // Lebih dari 5%
                            $(this).addClass('is-invalid');
                        }
                    }
                }
            });

            const isNg = selectedStatus === 'ng';

            // Update UI for this item
            const badge = $(`#badge-status-${index}`);
            const statusLabel = $(`#status-label-${index}`);
            const photoSection = $(`#photo-section-${index}`);
            const photoInput = photoSection.find('.photo-input');

            if (isNg) {
                badge.removeClass('bg-success').addClass('bg-danger').text('NG');
                statusLabel.removeClass('text-success').addClass('text-danger').text('NG');
                photoSection.removeClass('d-none');
                photoInput.prop('disabled', false);
                photoInput.prop('required', true);
            } else {
                badge.removeClass('bg-danger').addClass('bg-success').text('OK');
                statusLabel.removeClass('text-danger').addClass('text-success').text('OK');
                photoSection.addClass('d-none');
                photoInput.prop('required', false);
                photoInput.prop('disabled', true);
                photoInput.val('');
            }

            evaluateOverall();
        }

        function evaluateOverall() {
            let hasInspectionInput = false;
            let overallNg = false;

            $('.actual-input').each(function() {
                if ($(this).val() !== '') {
                    hasInspectionInput = true;
                    return false;
                }
            });

            if (!hasInspectionInput) {
                $('.item-status-value').each(function() {
                    if ($(this).val() === 'ng') {
                        hasInspectionInput = true;
                        return false;
                    }
                });
            }

            if (!hasInspectionInput) {
                $('#bannerNg').addClass('d-none');
                $('#bannerOk').addClass('d-none');
                return;
            }

            $('.item-status-value').each(function() {
                if ($(this).val() === 'ng') {
                    overallNg = true;
                }
            });

            if (overallNg) {
                $('#bannerOk').addClass('d-none');
                $('#bannerNg').removeClass('d-none');
            } else {
                $('#bannerNg').addClass('d-none');
                $('#bannerOk').removeClass('d-none');
            }
        }

        // Listeners
        $('.actual-input').on('input', function() {
            const index = $(this).closest('.input-row').data('index');
            evaluateItem(index);
        });

        $('.item-status-switch').on('change', function() {
            const index = $(this).data('index');
            const status = $(this).is(':checked') ? 'ng' : 'ok';
            $(`#input-status-${index}`).val(status);
            evaluateItem(index);
        });

        // Initial evaluation
        $('.input-row').each(function() {
            evaluateItem($(this).data('index'));
        });
        evaluateOverall();

        // Submit Action
        $('#btnSubmit').on('click', function() {
            $('.input-row').each(function() {
                evaluateItem($(this).data('index'));
            });

            // Check HTML5 validity for required photos
            if (!$('#inspectionForm')[0].checkValidity()) {
                $('#inspectionForm')[0].reportValidity();
                return;
            }

            AdasiAlert.confirm({
                title: @json('Save Inspection Results?'),
                html: @json('Inspection results cannot be changed after saving.<br>PO status will be updated automatically.'),
                type: 'warning',
                confirmText: @json('Yes, Save!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#inspectionForm').submit();
                }
            });
        });

    });
</script>
@endpush
