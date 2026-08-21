@extends('layouts.app')

@section('title', 'Edit Purchase Requisition - ADASI Portal')
@section('page-title', 'Edit Purchase Requisition')

@push('styles')
    @include('purchasing.pr._form_table_styles')
@endpush

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.breadcrumb :items="[
        'Purchase Requisition' => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        'Edit' => null,
    ]" />

    <x-ui.page-header
        title="Edit purchase requisition"
        eyebrow="Purchasing"
        description="Review the procurement context and material rows before saving the draft or submitting the requisition."
    >
        <x-slot:meta>
            @if($pr->status === 'rejected')
                <x-ui.status-chip tone="error" icon="refresh-cw">Rejected — revision required</x-ui.status-chip>
            @else
                <x-ui.status-chip tone="neutral" icon="square-pen">Draft</x-ui.status-chip>
            @endif
        </x-slot:meta>
    </x-ui.page-header>

    <x-ui.card
        title="Requisition details"
        description="Required fields are marked with an asterisk. Existing identifiers and workflow state are preserved."
    >
        <form id="prForm" action="{{ route('purchasing.requisitions.update', $pr) }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <input type="hidden" name="supplier_selection_present" value="1">

            <div class="tw-grid tw-gap-5 shell:tw-grid-cols-3">
                <x-ui.select name="period_id" id="period_id" label="Quotation period" class="pr-period-field" required>
                        <option value="">-- Select Period --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ (old('period_id', $pr->period_id) == $period->id) ? 'selected' : '' }}>
                                {{ $period->display_label }}
                            </option>
                        @endforeach
                </x-ui.select>
                <div class="tw-min-w-0">
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
                <x-ui.textarea
                    name="notes"
                    id="notes"
                    label="Additional notes / remarks"
                    :value="old('notes', $pr->notes)"
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
                    @if($pr->status === 'draft')
                        @include('purchasing.pr._import_controls')
                    @endif
                        <x-ui.button type="button" variant="secondary" size="sm" id="btnAddRow">
                            <x-slot:leading><x-ui.icon name="plus" /></x-slot:leading>
                            Add material
                        </x-ui.button>
                    </div>
                </div>

                <div class="table-responsive pr-form-table-scroll">
                <table class="table table-bordered table-sm align-middle pr-items-table" id="itemsTable">
                    <caption class="visually-hidden">Required material entry table with shape-aware dimension columns</caption>
                    <colgroup>
                        <col style="width: 300px; min-width: 300px;">
                        <col style="width: 130px; min-width: 130px;">
                        <col style="width: 80px; min-width: 80px;">
                        <col data-dimension-slot-col="1" style="width: 140px; min-width: 140px;">
                        <col data-dimension-slot-col="2" style="width: 140px; min-width: 140px;">
                        <col data-dimension-slot-col="3" style="width: 140px; min-width: 140px;">
                        <col style="width: 150px; min-width: 150px;">
                        <col style="width: 220px; min-width: 220px;">
                        <col style="width: 72px; min-width: 72px;">
                    </colgroup>
                    <thead class="table-light text-center">
                        <tr class="pr-group-header">
                            <th scope="col" class="pr-sticky-material">Master Material &amp; HS Code <span class="text-danger">*</span></th>
                            <th scope="col">Shape</th>
                            <th scope="col">Qty <span class="text-danger">*</span></th>
                            <th scope="col" data-dimension-slot-header="1">Dimension 1 (mm)</th>
                            <th scope="col" data-dimension-slot-header="2">Dimension 2 (mm)</th>
                            <th scope="col" data-dimension-slot-header="3">Dimension 3 (mm)</th>
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
            </section>

            <div class="tw-mt-6 tw-flex tw-flex-col-reverse tw-gap-2 sm:tw-flex-row sm:tw-justify-end">
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="button" variant="secondary" onclick="submitForm('draft')">Save draft</x-ui.button>
                <x-ui.button type="button" onclick="confirmSubmit()">
                    <x-slot:leading><x-ui.icon name="send" /></x-slot:leading>
                    {{ $pr->status === 'rejected' ? 'Revise & Resubmit' : 'Submit Now' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
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
        updateMaterialDimensionHeaders();
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
                updateMaterialDimensionHeaders();
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

        // Enable horizontal scroll on mouse wheel for PR items table
        const prScroll = document.querySelector('.pr-form-table-scroll');
        if (prScroll) {
            prScroll.addEventListener('wheel', function(e) {
                if (e.target.closest?.('.material-search-results')) {
                    return;
                }

                if (e.deltaY === 0 || this.scrollWidth <= this.clientWidth) {
                    return;
                }

                const maxScrollLeft = this.scrollWidth - this.clientWidth;
                const currentScrollLeft = this.scrollLeft;
                const nextScrollLeft = Math.max(0, Math.min(maxScrollLeft, currentScrollLeft + e.deltaY));
                const consumedDelta = nextScrollLeft - currentScrollLeft;
                const remainingDelta = e.deltaY - consumedDelta;

                this.scrollLeft = nextScrollLeft;
                e.preventDefault();

                // Once the table reaches an edge, pass the remaining wheel delta to the page.
                if (remainingDelta !== 0) {
                    window.scrollBy({ top: remainingDelta, left: 0, behavior: 'auto' });
                }
            }, { passive: false });
        }
    });
</script>
@endpush
