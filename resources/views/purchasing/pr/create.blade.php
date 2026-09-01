@extends('layouts.app')

@section('title', 'Create Purchase Requisition - ADASI Portal')
@section('page-title', 'Create Purchase Requisition')

@push('styles')
    @include('purchasing.pr._form_table_styles')
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Purchase Requisition' => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        'Create' => null,
    ]" />

    <x-ui.page-header
        title="Create Purchase Requisition"
        eyebrow="Purchasing Workflow"
        description="Select the procurement period, designate supplier audience, and specify material requirements."
    />

    {{-- Main Sectioned Form --}}
    <form id="prForm" action="{{ route('purchasing.requisitions.store') }}" method="POST">
        @csrf
        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
        <input type="hidden" name="action" id="formAction" value="draft">
        <input type="hidden" name="supplier_selection_present" value="1">

        <div class="tw-grid tw-gap-5">
            {{-- Section 1: General Information --}}
            <x-ui.form-section
                title="General Information"
                description="Define the procurement context, invitation scope, and requisition remarks."
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <x-ui.select name="period_id" id="period_id" label="Quotation Period" class="pr-period-field" required>
                        <option value="">-- Select Period --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ old('period_id') == $period->id ? 'selected' : '' }}>
                                {{ $period->display_label }}
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
                    </div>

                    <div class="sm:tw-col-span-2 lg:tw-col-span-1">
                        <x-ui.textarea
                            name="notes"
                            id="notes"
                            label="Additional Remarks / Notes"
                            :value="old('notes')"
                            rows="2"
                            placeholder="e.g. Required delivery Cikarang plant, strict thickness tolerance..."
                        />
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Section 2: Material Requirements --}}
            <x-ui.form-section
                title="Material Requirements"
                description="Add materials from master data, specify dimensional shapes, and inspect auto-computed weights."
                class="pr-material-section"
            >
                <x-slot:actions>
                    <div class="d-flex align-items-center gap-2">
                        @include('purchasing.pr._import_controls')
                        <x-ui.button type="button" variant="secondary" size="sm" id="btnAddRow">
                            <x-ui.icon name="plus" size="sm" />
                            <span>Add Material</span>
                        </x-ui.button>
                    </div>
                </x-slot:actions>

                <div class="table-responsive pr-form-table-scroll border rounded overflow-hidden">
                    <table class="table table-bordered table-hover table-sm align-middle pr-items-table mb-0 tw-text-ui-xs" id="itemsTable">
                        <caption class="visually-hidden">Required material entry table with shape-aware dimension columns</caption>
                        <colgroup>
                            <col style="width: 48px; min-width: 48px;">
                            <col style="width: 250px; min-width: 250px;">
                            <col style="width: 165px; min-width: 165px;">
                            <col style="width: 110px; min-width: 110px;">
                            <col style="width: 78px; min-width: 78px;">
                            <col style="width: 100px; min-width: 100px;">
                            <col style="width: 100px; min-width: 100px;">
                            <col style="width: 100px; min-width: 100px;">
                            <col style="width: 100px; min-width: 100px;">
                            <col style="width: 100px; min-width: 100px;">
                            <col style="width: 160px; min-width: 160px;">
                            <col style="width: 180px; min-width: 180px;">
                            <col style="width: 64px; min-width: 64px;">
                        </colgroup>
                        <thead class="table-light text-center">
                            <tr class="pr-group-header">
                                <th scope="col" class="pr-sticky-number">No</th>
                                <th scope="col" class="pr-sticky-material">Material <span class="text-danger">*</span></th>
                                <th scope="col">HS Code</th>
                                <th scope="col">Shape</th>
                                <th scope="col">Qty <span class="text-danger">*</span></th>
                                @foreach(\App\Models\PrItem::FIXED_DIMENSION_ORDER as $dimensionField)
                                    <th scope="col">{{ \App\Models\PrItem::DIMENSION_LABELS[$dimensionField] }} (mm)</th>
                                @endforeach
                                <th scope="col">KG / Unit (kg)</th>
                                <th scope="col">Remark</th>
                                <th scope="col" class="pr-sticky-action">Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            @if(old('items'))
                                @foreach(old('items') as $index => $item)
                                    @include('purchasing.pr._item_row', ['index' => $index, 'item' => $item])
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                </div>

                @error('items') <div class="text-danger small tw-mt-1.5">{{ $message }}</div> @enderror
                <div id="noItemAlert" class="text-danger small tw-mt-1.5 d-none" role="alert" aria-live="assertive">At least 1 material row is required before saving or submitting.</div>
            </x-ui.form-section>
        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw-mt-6">
            <x-slot:left>
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index')" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>Cancel</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" variant="secondary" size="sm" onclick="submitForm('draft')">
                    <span>Save Draft</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" onclick="confirmSubmit()">
                    <x-ui.icon name="send" size="sm" />
                    <span>Submit Requisition</span>
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
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
        renumberPrRows();
        checkRowCount();
    }

    function removeRow(btn) {
        AdasiAlert.confirmDanger({
            title: 'Delete this row?',
            confirmText: 'Yes, Delete',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(btn).closest('tr').remove();
                renumberPrRows();
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
            document.getElementById('btnAddRow')?.focus();
            return;
        }
        window.AdasiUnsaved?.markClean?.();
        $('#formAction').val(action);
        $('#prForm').submit();
    }

    function confirmSubmit() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            document.getElementById('btnAddRow')?.focus();
            return;
        }

        AdasiAlert.confirm({
            title: 'Submit Requisition?',
            text: 'Status will change to Submitted and will be open for quotation bidding.',
            confirmText: 'Yes, Submit!',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.AdasiUnsaved?.markClean?.();
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

        // Prevent accidental form submission on Enter in fields
        $('#prForm').on('keydown', 'input, select', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        // Horizontal drag-to-scroll for items table
        const prScroll = document.querySelector('.pr-form-table-scroll');
        if (prScroll) {
            let isDown = false;
            let startX = 0;
            let startScrollLeft = 0;

            prScroll.addEventListener('mousedown', function(e) {
                if (e.button !== 0) return;
                if (e.target.closest('input, select, textarea, button, a, label, [role="button"], .dropdown-menu, .modal, .material-search-results')) {
                    return;
                }
                isDown = true;
                startX = e.pageX;
                startScrollLeft = prScroll.scrollLeft;
                prScroll.style.cursor = 'grabbing';
                prScroll.style.userSelect = 'none';
            });

            window.addEventListener('mousemove', function(e) {
                if (!isDown) return;
                e.preventDefault();
                const walk = e.pageX - startX;
                prScroll.scrollLeft = startScrollLeft - walk;
            });

            window.addEventListener('mouseup', function() {
                if (isDown) {
                    isDown = false;
                    prScroll.style.cursor = '';
                    prScroll.style.removeProperty('user-select');
                }
            });
        }
    });
</script>
@endpush
