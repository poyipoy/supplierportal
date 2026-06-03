@extends('layouts.app')

@section('title', 'Edit Permintaan Material — ADASI Portal')
@section('page-title', 'Edit Permintaan Material')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Edit Formulir Permintaan Material</h5>
        @if($pr->status === 'rejected')
            <span class="badge bg-danger">Status Saat Ini: Rejected (Mohon Direvisi)</span>
        @else
            <span class="badge bg-secondary">Status Saat Ini: Draft</span>
        @endif
    </div>
    <div class="card-body">
        <form id="prForm" action="{{ route('purchasing.requirements.update', $pr->id) }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <input type="hidden" name="supplier_selection_present" value="1">

            <div class="row mb-4">
                <div class="col-md-4">
                    <label for="period_id" class="form-label fw-medium">Periode Penawaran <span class="text-danger">*</span></label>
                    <select name="period_id" id="period_id" class="form-select @error('period_id') is-invalid @enderror" required>
                        <option value="">-- Pilih Periode --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ (old('period_id', $pr->period_id) == $period->id) ? 'selected' : '' }}>
                                {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                            </option>
                        @endforeach
                    </select>
                    @error('period_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    @php
                        $selectedSupplierIds = collect(session()->hasOldInput() ? old('supplier_ids', []) : $pr->invitedSuppliers->pluck('id')->all());
                        if (old('supplier_id')) {
                            $selectedSupplierIds->push(old('supplier_id'));
                        }
                    @endphp
                    @include('purchasing.pr._supplier_picker_modal', [
                        'modalId' => 'editSupplierPickerModal',
                        'suppliers' => $suppliers,
                        'selectedSupplierIds' => $selectedSupplierIds,
                    ])
                    {{--
                    <div class="form-text">Pilih satu supplier, atau biarkan “Semua Supplier Terdaftar” agar PR bisa dilihat semua supplier.</div>
                    @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @error('supplier_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    --}}
                </div>
                <div class="col-md-4">
                    <label for="notes" class="form-label fw-medium">Catatan / Keterangan Tambahan</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Opsional...">{{ old('notes', $pr->notes) }}</textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Daftar Material yang Dibutuhkan</h6>
                <button type="button" class="btn btn-sm btn-success" id="btnAddRow">
                    <i class="bi bi-plus"></i> Tambah Material
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle" id="itemsTable">
                    <thead class="table-light text-center" style="font-size: 0.8rem;">
                        <tr>
                            <th width="28%">Material & HS Code <span class="text-danger">*</span></th>
                            <th width="12%">Bentuk</th>
                            <th width="8%">Qty <span class="text-danger">*</span></th>
                            <th width="34%">Dimensi (mm)</th>
                            <th width="10%">Berat/Unit (Kg) <span class="text-danger">*</span></th>
                            <th width="8%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        @php
                            $oldItems = old('items', $pr->items->toArray());
                        @endphp
                        
                        @if($oldItems)
                            @foreach($oldItems as $index => $item)
                                @include('purchasing.pr._item_row', ['index' => $index, 'item' => $item])
                            @endforeach
                        @endif
                    </tbody>
                </table>
                @error('items') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                <div id="noItemAlert" class="text-danger small mt-1 d-none">Minimal harus ada 1 material.</div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.requirements.index') }}" class="btn btn-light">Batal</a>
                <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">Simpan Draft  </button>
                <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">
                    {{ $pr->status === 'rejected' ? 'Revisi & Ajukan Ulang' : 'Ajukan Sekarang' }}
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Template for new row --}}
<template id="rowTemplate">
    @include('purchasing.pr._item_row', ['index' => '{INDEX}', 'item' => null])
</template>

@endsection

@push('scripts')
<script>
    let itemIndex = {{ count($oldItems ?? []) }};

    @include('purchasing.pr._material_shape_script')

    function addRow() {
        const template = document.getElementById('rowTemplate').innerHTML;
        const html = template.replace(/{INDEX}/g, itemIndex);
        $('#itemsBody').append(html);
        applyMaterialShapeRules($('#itemsBody tr.item-row').last(), true);
        itemIndex++;
        checkRowCount();
    }

    function removeRow(btn) {
        Swal.fire({
            title: 'Hapus baris ini?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $(btn).closest('tr').remove();
                checkRowCount();
            }
        });
    }

    function checkRowCount() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
        } else {
            $('#noItemAlert').addClass('d-none');
        }
    }

    function submitForm(action) {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            Swal.fire('Error', 'Minimal 1 material wajib ditambahkan.', 'error');
            return;
        }
        $('#formAction').val(action);
        $('#prForm').submit();
    }

    function confirmSubmit() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            Swal.fire('Error', 'Minimal 1 material wajib ditambahkan.', 'error');
            return;
        }

        Swal.fire({
            title: 'Ajukan Permintaan?',
            text: 'Status akan berubah menjadi Submitted dan tidak bisa diedit lagi.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Ajukan!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formAction').val('submitted');
                $('#prForm').submit();
            }
        });
    }

    $(document).ready(function() {
        $('#btnAddRow').click(addRow);
        initializeMaterialShapeRows();
        checkRowCount();

        let isDirty = false;
        $('#prForm').on('input change', 'input, select, textarea', function() {
            isDirty = true;
        });
        $('#prForm').on('submit', function() {
            isDirty = false;
        });
        $(window).on('beforeunload', function() {
            if (isDirty) {
                return 'Anda memiliki perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
            }
        });
    });
</script>
@endpush
