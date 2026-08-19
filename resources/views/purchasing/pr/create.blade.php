@extends('layouts.app')

@section('title', 'Create Purchase Requisition - ADASI Portal')
@section('page-title', 'Create New Purchase Requisition')

@push('styles')
    @include('purchasing.pr._form_table_styles')
@endpush

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.breadcrumb :items="[
        'Purchase Requisition' => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        'Create' => null,
    ]" />

    <x-ui.page-header
        title="Create purchase requisition"
        eyebrow="Purchasing"
        description="Choose the procurement context, invite suppliers, then add every required material before saving or submitting."
    />

    <x-ui.card
        title="Requisition details"
        description="Required fields are marked with an asterisk. You can keep incomplete work as a draft."
    >
        <form id="prForm" action="{{ route('purchasing.requisitions.store') }}" method="POST">
            @csrf
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <input type="hidden" name="supplier_selection_present" value="1">

            <div class="tw-grid tw-gap-5 shell:tw-grid-cols-3">
                <x-ui.select name="period_id" id="period_id" label="Quotation period" required>
                        <option value="">-- Select Period --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ old('period_id') == $period->id ? 'selected' : '' }}>
                                {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                            </option>
                        @endforeach
                </x-ui.select>
                <div class="tw-min-w-0">
                    @php
                        $selectedSupplierIds = collect(old('supplier_ids', []));
                        if (old('supplier_id')) {
                            $selectedSupplierIds->push(old('supplier_id'));
                        }
                    @endphp
                    @include('purchasing.pr._supplier_picker_modal', [
                        'modalId' => 'createSupplierPickerModal',
                        'suppliers' => $suppliers,
                        'selectedSupplierIds' => $selectedSupplierIds,
                    ])
                    {{--
                    <div class="form-text">Select one supplier, or leave “All Registered Suppliers” so the PR can be viewed by all suppliers.</div>
                    --}}
                </div>
                <x-ui.textarea
                    name="notes"
                    id="notes"
                    label="Additional notes / remarks"
                    :value="old('notes')"
                    rows="3"
                    placeholder="Optional..."
                />
            </div>

            <div class="tw-my-6 tw-border-t tw-border-outline-variant"></div>

            <section class="tw-grid tw-gap-4" aria-labelledby="required-materials-title">
                <div class="pr-material-toolbar tw-flex tw-flex-col tw-gap-3 shell:tw-flex-row shell:tw-items-end shell:tw-justify-between">
                    <div>
                        <h2 id="required-materials-title" class="tw-m-0 tw-text-ui-lg tw-font-semibold tw-text-on-surface">Required materials</h2>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">Search the material master, then complete shape, quantity, dimensions, and weight.</p>
                    </div>
                    <div class="tw-flex tw-flex-wrap tw-gap-2 shell:tw-justify-end">
                    @include('purchasing.pr._import_controls')
                        <x-ui.button type="button" variant="secondary" size="sm" id="btnAddRow">
                            <x-slot:leading><i class="bi bi-plus" aria-hidden="true"></i></x-slot:leading>
                            Add material
                        </x-ui.button>
                    </div>
                </div>

                <div class="table-responsive pr-form-table-scroll">
                <table class="table table-bordered table-sm align-middle pr-items-table" id="itemsTable">
                    <caption class="visually-hidden">Required material entry table with adaptive dimension columns</caption>
                    <colgroup>
                        <col class="tw-w-[300px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[125px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-20">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[120px]">
                        <col class="tw-w-[120px]">
                        <col class="tw-w-[120px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[145px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[190px]">
                    </colgroup>
                    <colgroup>
                        <col class="tw-w-[72px]">
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
                <div id="noItemAlert" class="text-danger small mt-1 d-none">At least 1 material is required.</div>
                </div>
            </section>

            <div class="tw-mt-6 tw-flex tw-flex-col-reverse tw-gap-2 sm:tw-flex-row sm:tw-justify-end">
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="button" variant="secondary" onclick="submitForm('draft')">Save draft</x-ui.button>
                <x-ui.button type="button" onclick="confirmSubmit()">
                    <x-slot:leading><i class="bi bi-send-check" aria-hidden="true"></i></x-slot:leading>
                    Submit now
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>

@include('purchasing.pr._import')

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

        // Add one empty row initially if old input doesn't exist
        if ($('#itemsBody tr').length === 0) {
            addRow();
        } else {
            initializeMaterialShapeRows();
        }

        // Enter edits fields; PR submission is available only through the action buttons.
        $('#prForm').on('keydown', 'input, select', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    });
</script>
@endpush
