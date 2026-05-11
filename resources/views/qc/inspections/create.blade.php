@extends('layouts.app')

@section('title', 'Mulai Inspeksi QC: ' . $po->po_number . ' — ADASI Portal')
@section('page-title', __('Inspeksi QC Material'))

@section('content')
<div class="mb-3">
    <a href="{{ route('qc.inspections.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> {{ __('Kembali ke Daftar Inspeksi') }}
    </a>
</div>

{{-- Info PO Header --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="text-muted small">{{ __('Nomor PO') }}</div>
                <div class="fw-bold fs-6">{{ $po->po_number }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">{{ __('Supplier') }}</div>
                <div class="fw-bold">{{ $po->quotation->supplier->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">{{ __('Tanggal Material Tiba') }}</div>
                <div class="fw-bold">{{ $po->actual_arrival ? $po->actual_arrival->format('d F Y') : '-' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-success d-none mb-4" id="bannerOk">
    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
    <span class="fw-bold">{{ __('Status Inspeksi') }}: OK</span> - {{ __('Semua material sesuai spesifikasi.') }}
</div>

<div class="alert alert-danger d-none mb-4" id="bannerNg">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
    <span class="fw-bold">{{ __('Status Inspeksi') }}: NG (Not Good)</span> - {{ __('Terdapat material yang tidak sesuai spesifikasi. Harap unggah foto bukti.') }}
</div>

<form action="{{ route('qc.inspections.store', $po->id) }}" method="POST" enctype="multipart/form-data" id="inspectionForm">
    @csrf

    @foreach($po->quotation->items as $index => $item)
        @php
            $prItem = $item->prItem;
        @endphp
        <div class="card border-0 shadow-sm mb-4 item-card">
            <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Item #{{ $index + 1 }}: {{ $prItem->material_name }}</h6>
                <span class="badge bg-success item-status-badge" id="badge-status-{{ $index }}">OK</span>
            </div>
            <div class="card-body">
                <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $prItem->id }}">
                <input type="hidden" name="items[{{ $index }}][status]" value="ok" class="item-status-input" id="input-status-{{ $index }}">

                <div class="row g-4">
                    {{-- Read Only Specs --}}
                    <div class="col-md-5 border-end pe-4">
                        <h6 class="fw-bold mb-3 small text-muted text-uppercase">{{ __('Spesifikasi Diminta') }}</h6>
                        <table class="table table-sm table-borderless small mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted" width="40%">{{ __('Shape') }}</td>
                                    <td class="fw-medium">{{ $prItem->shape ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Thickness') }} (mm)</td>
                                    <td class="fw-medium spec-val" data-spec-type="thickness" data-val="{{ $prItem->thickness }}">{{ $prItem->thickness ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Inner Dia.') }} (mm)</td>
                                    <td class="fw-medium spec-val" data-spec-type="d_inner" data-val="{{ $prItem->d_inner }}">{{ $prItem->d_inner ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Outer Dia.') }} (mm)</td>
                                    <td class="fw-medium spec-val" data-spec-type="d_outer" data-val="{{ $prItem->d_outer }}">{{ $prItem->d_outer ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Width') }} (mm)</td>
                                    <td class="fw-medium spec-val" data-spec-type="width" data-val="{{ $prItem->width }}">{{ $prItem->width ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Length') }} (mm)</td>
                                    <td class="fw-medium spec-val" data-spec-type="length" data-val="{{ $prItem->length }}">{{ $prItem->length ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">{{ __('Weight (Kg)') }}</td>
                                    <td class="fw-medium spec-val" data-spec-type="weight" data-val="{{ $prItem->weight_needed }}">{{ $prItem->weight_needed ?? '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Actual Inputs --}}
                    <div class="col-md-7 ps-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold small text-primary text-uppercase mb-0">{{ __('Input Aktual') }}</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input manual-override-switch" type="checkbox" role="switch" id="override-{{ $index }}" data-index="{{ $index }}">
                                <label class="form-check-label small" for="override-{{ $index }}">{{ __('Set Manual NG') }}</label>
                            </div>
                        </div>
                        
                        <div class="row g-3 input-row" data-index="{{ $index }}">
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Thickness') }} (mm)</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_thickness]" class="form-control form-control-sm actual-input" data-spec-type="thickness">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Inner Dia.') }} (mm)</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_d_inner]" class="form-control form-control-sm actual-input" data-spec-type="d_inner">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Outer Dia.') }} (mm)</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_d_outer]" class="form-control form-control-sm actual-input" data-spec-type="d_outer">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Width') }} (mm)</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_width]" class="form-control form-control-sm actual-input" data-spec-type="width">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Length') }} (mm)</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_length]" class="form-control form-control-sm actual-input" data-spec-type="length">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">{{ __('Weight (Kg)') }}</label>
                                <input type="number" step="any" name="items[{{ $index }}][actual_weight]" class="form-control form-control-sm actual-input" data-spec-type="weight">
                            </div>
                            <div class="col-12 mt-3">
                                <label class="form-label small">{{ __('Catatan Item') }}</label>
                                <textarea name="items[{{ $index }}][notes]" class="form-control form-control-sm" rows="1" placeholder="{{ __('Opsional...') }}"></textarea>
                            </div>
                        </div>

                        {{-- NG Photo Upload (Hidden by default) --}}
                        <div class="ng-photo-section mt-3 p-3 bg-danger bg-opacity-10 border border-danger rounded d-none" id="photo-section-{{ $index }}">
                            <label class="form-label fw-bold text-danger small mb-2"><i class="bi bi-camera me-1"></i>{{ __('Foto Bukti NG (Wajib)') }}</label>
                            <input type="file" name="attachments[{{ $index }}][]" class="form-control form-control-sm photo-input" accept=".jpg,.jpeg,.png" multiple>
                            <div class="form-text text-danger small">{{ __('Maks 10MB per file. Pilih minimal 1 foto karena status item ini NG.') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="javascript:history.back()" class="btn btn-light">{{ __('Batal') }}</a>
        <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnSubmit">
            <i class="bi bi-save me-1"></i> {{ __('Simpan Hasil Inspeksi') }}
        </button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        
        function evaluateItem(index) {
            const row = $(`.input-row[data-index="${index}"]`);
            const isManualNg = $(`#override-${index}`).is(':checked');
            let isNg = false;

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
                            isNg = true;
                        }
                    }
                }
            });

            if (isManualNg) {
                isNg = true;
            }

            // Update UI for this item
            const badge = $(`#badge-status-${index}`);
            const input = $(`#input-status-${index}`);
            const photoSection = $(`#photo-section-${index}`);
            const photoInput = photoSection.find('.photo-input');

            if (isNg) {
                badge.removeClass('bg-success').addClass('bg-danger').text('NG');
                input.val('ng');
                photoSection.removeClass('d-none');
                photoInput.prop('required', true);
            } else {
                badge.removeClass('bg-danger').addClass('bg-success').text('OK');
                input.val('ok');
                photoSection.addClass('d-none');
                photoInput.prop('required', false);
            }

            evaluateOverall();
        }

        function evaluateOverall() {
            let overallNg = false;
            $('.item-status-input').each(function() {
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

        $('.manual-override-switch').on('change', function() {
            const index = $(this).data('index');
            evaluateItem(index);
        });

        // Initial evaluation
        $('.input-row').each(function() {
            evaluateItem($(this).data('index'));
        });

        // Submit Action
        $('#btnSubmit').on('click', function() {
            // Check HTML5 validity for required photos
            if (!$('#inspectionForm')[0].checkValidity()) {
                $('#inspectionForm')[0].reportValidity();
                return;
            }

            Swal.fire({
                title: @json(__('Simpan Hasil Inspeksi?')),
                html: @json(__('Hasil inspeksi tidak dapat diubah setelah disimpan.<br>PO status akan diperbarui otomatis.')),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--adasi-blue)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: @json(__('Ya, Simpan!')),
                cancelButtonText: @json(__('Batal'))
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#inspectionForm').submit();
                }
            });
        });

    });
</script>
@endpush
