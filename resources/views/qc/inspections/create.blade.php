@extends('layouts.app')

@section('title', 'Mulai Inspeksi QC: ' . $po->po_number . ' — ADASI Portal')
@section('page-title', 'Inspeksi QC Material')

@push('styles')
<style>
    .qc-spec-grid,
    .qc-dimension-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: .75rem;
    }

    @media (min-width: 992px) {
        .qc-spec-grid,
        .qc-dimension-grid {
            grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
        }
    }

    .qc-spec-box {
        border: 1px solid #e5e7eb;
        border-radius: .5rem;
        padding: .65rem .75rem;
        background: #f8fafc;
        min-height: 64px;
    }
</style>
@endpush

@section('content')
<div class="mb-3">
    <a href="{{ route('qc.inspections.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Inspeksi
    </a>
</div>

{{-- Info PO Header --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="text-muted small">Nomor PO</div>
                <div class="fw-bold fs-6">{{ $po->po_number }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Supplier</div>
                <div class="fw-bold">{{ $po->supplier->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Tanggal Material Tiba</div>
                <div class="fw-bold">{{ $po->actual_arrival ? $po->actual_arrival->format('d F Y') : '-' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-success d-none mb-4" id="bannerOk">
    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
    <span class="fw-bold">Status Inspeksi: OK</span> - Semua material sesuai spesifikasi.
</div>

<div class="alert alert-danger d-none mb-4" id="bannerNg">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
    <span class="fw-bold">Status Inspeksi: NG (Not Good)</span> - Terdapat material yang tidak sesuai spesifikasi. Harap unggah foto bukti.
</div>

<form action="{{ route('qc.inspections.store', $po->id) }}" method="POST" enctype="multipart/form-data" id="inspectionForm">
    @csrf

    @php $allItems = $po->quotations->flatMap(fn($q) => $q->items); @endphp
    @php
        $qcDimensionLabels = [
            'thickness' => 'Thickness (mm)',
            'd_inner' => 'Inner Dia. (mm)',
            'd_outer' => 'Outer Dia. (mm)',
            'width' => 'Width (mm)',
            'length' => 'Length (mm)',
            'weight' => 'Berat/Unit (Kg)',
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
                        <h6 class="fw-bold mb-3 small text-muted text-uppercase">Spesifikasi Diminta</h6>
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
                            <div class="text-end" style="min-width: 150px;">
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
                                    <label class="form-label small">{{ $qcDimensionLabels[$dimension] }}</label>
                                    <input type="number" step="any" name="items[{{ $index }}][{{ $qcActualFields[$dimension] }}]" class="form-control form-control-sm actual-input" data-spec-type="{{ $dimension }}" value="{{ old('items.' . $index . '.' . $qcActualFields[$dimension]) }}">
                                </div>
                            @endforeach
                            </div>
                            <div class="mt-3">
                                <label class="form-label small">Catatan Item</label>
                                <textarea name="items[{{ $index }}][notes]" class="form-control form-control-sm" rows="1" placeholder="Opsional..."></textarea>
                            </div>
                        </div>

                        {{-- NG Photo Upload (Hidden by default) --}}
                        <div class="ng-photo-section mt-3 p-3 bg-danger bg-opacity-10 border border-danger rounded d-none" id="photo-section-{{ $index }}">
                            <label class="form-label fw-bold text-danger small mb-2"><i class="bi bi-camera me-1"></i>Foto Bukti NG (Wajib)</label>
                            <input type="file" name="attachments[{{ $index }}][]" class="form-control form-control-sm photo-input" accept=".jpg,.jpeg,.png" multiple disabled>
                            <div class="form-text text-danger small">Maks 10MB per file. Pilih minimal 1 foto karena status item ini NG.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="javascript:history.back()" class="btn btn-light">Batal</a>
        <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnSubmit">
            <i class="bi bi-save me-1"></i> Simpan Hasil Inspeksi
        </button>
    </div>
</form>
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

            Swal.fire({
                title: @json('Simpan Hasil Inspeksi?'),
                html: @json('Hasil inspeksi tidak dapat diubah setelah disimpan.<br>PO status akan diperbarui otomatis.'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--adasi-blue)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: @json('Ya, Simpan!'),
                cancelButtonText: @json('Batal')
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#inspectionForm').submit();
                }
            });
        });

    });
</script>
@endpush
