@extends('layouts.app')

@section('title', 'Buat Permintaan Material — ADASI Portal')
@section('page-title', 'Buat Permintaan Material Baru')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Formulir Permintaan Material</h5>
    </div>
    <div class="card-body">
        <form id="prForm" action="{{ route('purchasing.requirements.store') }}" method="POST">
            @csrf
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <div class="row mb-4">
                <div class="col-md-6">
                    <label for="period_id" class="form-label fw-medium">Periode Penawaran<span class="text-danger">*</span></label>
                    <select name="period_id" id="period_id" class="form-select @error('period_id') is-invalid @enderror" required>
                        <option value="">-- Pilih Periode --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ old('period_id') == $period->id ? 'selected' : '' }}>
                                {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                            </option>
                        @endforeach
                    </select>
                    @error('period_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="notes" class="form-label fw-medium">Catatan / Keterangan Tambahan</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Opsional...">{{ old('notes') }}</textarea>
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
                            <th width="10%">HS Code</th>
                            <th width="18%">Nama Material <span class="text-danger">*</span></th>
                            <th width="12%">Bentuk</th>
                            <th width="8%">Ketebalan</th>
                            <th width="8%">Diameter dalam</th>
                            <th width="8%">Diameter luar</th>
                            <th width="8%">Lebar</th>
                            <th width="8%">Panjang</th>
                            <th width="12%">Berat (Kg) <span class="text-danger">*</span></th>
                            <th width="8%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        {{-- Initially empty, row will be added by JS.
                             If there are old input (validation error), render them. --}}
                        @if(old('items'))
                            @foreach(old('items') as $index => $item)
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
                <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">Simpan Draft</button>
                <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">Ajukan Sekarang</button>
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
    let itemIndex = {{ old('items') ? count(old('items')) : 0 }};

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
        
        // Add one empty row initially if old input doesn't exist
        if ($('#itemsBody tr').length === 0) {
            addRow();
        } else {
            initializeMaterialShapeRows();
        }
    });
</script>
@endpush
