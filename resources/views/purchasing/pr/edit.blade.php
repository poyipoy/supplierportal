@extends('layouts.app')

@section('title', 'Edit Purchase Requisition - ADASI Portal')
@section('page-title', 'Edit Purchase Requisition')

@push('styles')
    @include('purchasing.pr._form_table_styles')
@endpush

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Edit Purchase Requisition Form</h5>
        @if($pr->status === 'rejected')
            <span class="badge bg-danger">Current Status: Rejected (Revision Required)</span>
        @else
            <span class="badge bg-secondary">Current Status: Draft</span>
        @endif
    </div>
    <div class="card-body">
        <form id="prForm" action="{{ route('purchasing.requisitions.update', $pr) }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <input type="hidden" name="supplier_selection_present" value="1">

            <div class="row mb-4">
                <div class="col-md-4">
                    <label for="period_id" class="form-label fw-medium">Quotation Period <span class="text-danger">*</span></label>
                    <select name="period_id" id="period_id" class="form-select @error('period_id') is-invalid @enderror" required>
                        <option value="">-- Select Period --</option>
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
                    <div class="form-text">Select one supplier, or leave “All Registered Suppliers” so the PR can be viewed by all suppliers.</div>
                    @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @error('supplier_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    --}}
                </div>
                <div class="col-md-4">
                    <label for="notes" class="form-label fw-medium">Additional Notes / Remarks</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional...">{{ old('notes', $pr->notes) }}</textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between align-items-center mb-3 pr-material-toolbar">
                <h6 class="fw-bold mb-0">Required Material List</h6>
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    @if($pr->status === 'draft')
                        @include('purchasing.pr._import_controls')
                    @endif
                    <button type="button" class="btn btn-sm btn-success" id="btnAddRow">
                        <i class="bi bi-plus"></i> Add Material
                    </button>
                </div>
            </div>

            <div class="table-responsive pr-form-table-scroll">
                <table class="table table-bordered table-sm align-middle pr-items-table" id="itemsTable">
                    <caption class="visually-hidden">Required material entry table with adaptive dimension columns</caption>
                    <colgroup>
                        <col style="width: 300px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 125px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 80px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 145px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 190px;">
                    </colgroup>
                    <colgroup>
                        <col style="width: 72px;">
                    </colgroup>
                    <thead class="table-light text-center">
                        <tr class="pr-group-header">
                            <th scope="col" class="pr-sticky-material">Master Material &amp; HS Code <span class="text-danger">*</span></th>
                            <th scope="col">Shape</th>
                            <th scope="col">Qty <span class="text-danger">*</span></th>
                            <th colspan="3" scope="colgroup">Dimensions (mm)</th>
                            <th scope="col">KG / Unit (kg)</th>
                            <th scope="col">Remark</th>
                            <th scope="col" class="pr-sticky-action">Action</th>
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
                <div id="noItemAlert" class="text-danger small mt-1 d-none">At least 1 material is required.</div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index') }}" class="btn btn-light">Cancel</a>
                <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">Save Draft  </button>
                <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">
                    {{ $pr->status === 'rejected' ? 'Revise & Resubmit' : 'Submit Now' }}
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Template for new row --}}
<template id="rowTemplate">
    @include('purchasing.pr._item_row', ['index' => '{INDEX}', 'item' => null])
</template>

@if($pr->status === 'draft')
    @include('purchasing.pr._import')
@endif

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
        resetMaterialPreview($('#itemsBody tr.item-row').last());
        itemIndex++;
        checkRowCount();
    }

    function removeRow(btn) {
        AdasiAlert.confirmDanger({
            title: 'Delete this row?',
            confirmText: 'Yes',
            cancelText: 'Cancel'
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
            AdasiAlert.error({ title: 'Error', text: 'At least 1 material must be added.' });
            return;
        }
        $('#formAction').val(action);
        $('#prForm').submit();
    }

    function confirmSubmit() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            AdasiAlert.error({ title: 'Error', text: 'At least 1 material must be added.' });
            return;
        }

        AdasiAlert.confirm({
            title: 'Submit Requisition?',
            text: 'Status will change to Submitted and cannot be edited anymore.',
            confirmText: 'Yes, Submit!',
            cancelText: 'Cancel'
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

        // Enter edits fields; PR submission is available only through the action buttons.
        $('#prForm').on('keydown', 'input, select', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    });
</script>
@endpush
