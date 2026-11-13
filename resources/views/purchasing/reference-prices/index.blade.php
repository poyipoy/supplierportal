@extends('layouts.app')
@section('title', __($rp.'.title'))
@section('page-title', __($rp.'.title'))

@php
    $rp = $rp ?? 'materials.reference_prices';
    $prefix = $prefix ?? (request()->user()?->role === 'admin' ? 'admin.reference-prices' : 'purchasing.master-data.reference-prices');
@endphp

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__($rp.'.title')" :description="__($rp.'.description')" :eyebrow="__('navigation.master_data')">
        <x-slot:actions>
            <span data-reference-prices-rules-action @if($activeTab !== 'rules') hidden @endif class="tw:inline-flex tw:items-center tw:gap-2">
                <x-ui.button type="button" size="sm" variant="outline" data-bs-toggle="modal" data-bs-target="#referencePriceAddCategoryModal">
                    <x-ui.icon name="plus" size="sm" />
                    <span>{{ __($rp.'.rules.add_category') }}</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" variant="primary" @click="$dispatch('open-ui-dialog', 'referencePriceImportDialog')">
                    <x-ui.icon name="upload" size="sm" />
                    <span>{{ __($rp.'.rules.import_excel') }}</span>
                </x-ui.button>
            </span>
            <span data-reference-prices-legacy-action @if($activeTab !== 'legacy') hidden @endif>
                <x-ui.button type="button" size="sm" variant="primary" data-bs-toggle="modal" data-bs-target="#referencePriceCreateModal">
                    <x-ui.icon name="plus" size="sm" />
                    <span>{{ __($rp.'.add') }}</span>
                </x-ui.button>
            </span>
        </x-slot:actions>
    </x-ui.page-header>

    <div id="referencePricesWorkspace" class="tw:grid tw:min-w-0 tw:gap-6" data-server-tabs-container>
        <nav class="tw:inline-flex tw:w-full tw:sm:w-auto tw:gap-1.5 tw:overflow-x-auto tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:p-1.5 tw:shadow-xs" data-server-tabs-nav aria-label="{{ __($rp.'.title') }}">
            @include('purchasing.reference-prices._tabs')
        </nav>
        <div class="tw:min-w-0 tw:max-w-full" data-server-tabs-content>
            @include('purchasing.reference-prices._content')
        </div>
    </div>
</div>

{{-- DIALOG: Import Excel (Shared Component) --}}
<x-ui.dialog name="referencePriceImportDialog" maxWidth="lg" :title="__($rp.'.rules.dialog_title')" :description="__($rp.'.rules.dialog_description')">
    <form method="POST" action="{{ route($prefix.'.rules.import-preview') }}" enctype="multipart/form-data" class="tw:grid tw:gap-4">
        @csrf
        <x-ui.select name="import_mode" :label="__($rp.'.rules.import_mode')" required>
            <option value="{{ \App\Models\ReferencePriceImport::MODE_REPLACE }}">{{ __($rp.'.rules.mode_replace') }}</option>
            <option value="{{ \App\Models\ReferencePriceImport::MODE_MERGE }}">{{ __($rp.'.rules.mode_merge') }}</option>
        </x-ui.select>
        <x-ui.input name="sheet_name" :label="'Worksheet Name'" value="Kategori" required />
        <x-ui.file-upload
            name="file"
            id="referencePriceUploadFile"
            :label="__($rp.'.rules.upload_file')"
            :max-size-mb="10"
            accept=".xlsx"
            required
        />
        <div class="tw:flex tw:items-center tw:justify-end tw:gap-2 tw:pt-2">
            <x-ui.button type="button" variant="outline" size="sm" @click="closeDialog()">{{ __($rp.'.cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" size="sm">
                <x-ui.icon name="file-search" size="sm" />
                <span>{{ __($rp.'.rules.preview_button') }}</span>
            </x-ui.button>
        </div>
    </form>
</x-ui.dialog>

{{-- MODAL: Add Category --}}
<div class="modal fade" id="referencePriceAddCategoryModal" tabindex="-1" aria-labelledby="addCatModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route($prefix.'.rules.category.save') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="addCatModalLabel">{{ __($rp.'.rules.add_category') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tw:grid tw:gap-3">
                <x-ui.input name="category_key" :label="__($rp.'.rules.category_key')" placeholder="e.g. 701-800" required />
                <x-ui.input name="category_name" :label="__($rp.'.rules.category_name')" placeholder="e.g. 701-800" required />
                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                    <x-ui.input type="number" name="dimension_min" :label="__($rp.'.rules.dimension_min')" min="1" required />
                    <x-ui.input type="number" name="dimension_max" :label="__($rp.'.rules.dimension_max')" min="1" required />
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL: Edit Category --}}
<div class="modal fade" id="referencePriceEditCategoryModal" tabindex="-1" aria-labelledby="editCatModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route($prefix.'.rules.category.save') }}" class="modal-content" id="referencePriceEditCategoryForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="editCatModalLabel">{{ __($rp.'.rules.edit_category') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tw:grid tw:gap-3">
                <input type="hidden" name="category_key" id="editCatKey">
                <x-ui.input name="category_name" id="editCatName" :label="__($rp.'.rules.category_name')" required />
                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                    <x-ui.input type="number" name="dimension_min" id="editCatMin" :label="__($rp.'.rules.dimension_min')" min="1" required />
                    <x-ui.input type="number" name="dimension_max" id="editCatMax" :label="__($rp.'.rules.dimension_max')" min="1" required />
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

{{-- Legacy Modals (Create, Supersede, Void, Notes) --}}
<div class="modal fade" id="referencePriceCreateModal" tabindex="-1" aria-labelledby="referencePriceCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route($prefix.'.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw:text-ui-md tw:font-bold" id="referencePriceCreateTitle">{{ __($rp.'.add') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body tw:grid tw:gap-3">
                <x-ui.searchable-select name="material_code" id="referencePriceMaterialCode" :label="__($rp.'.material_code')" :options="$materialOptions" required :placeholder="__($rp.'.search_placeholder')" />
                <x-ui.select name="shape" id="referencePriceShape" :label="__($rp.'.shape')" required>
                    <option value="">{{ __($rp.'.all_shapes') }}</option>
                    @foreach($shapes as $shape)<option value="{{ $shape }}" @selected(old('shape') === $shape)>{{ $shape }}</option>@endforeach
                </x-ui.select>
                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                    <x-ui.input name="dimension_min" id="referencePriceDimMin" type="number" step="1" min="1" :label="__($rp.'.dimension_min')" :value="old('dimension_min')" required />
                    <x-ui.input name="dimension_max" id="referencePriceDimMax" type="number" step="1" min="1" :label="__($rp.'.dimension_max')" :value="old('dimension_max')" required />
                </div>
                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                    <x-ui.select name="currency" id="referencePriceCurrency" :label="__($rp.'.currency')" required>
                        @foreach($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency', 'USD') === $currency)>{{ $currency }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.input name="price_per_kg" id="referencePricePerKg" type="number" step="0.0001" min="0.0001" :label="__($rp.'.price_per_kg')" :value="old('price_per_kg')" required />
                </div>
                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                    <x-ui.date-picker name="valid_from" id="referencePriceValidFrom" :label="__($rp.'.valid_from')" :value="old('valid_from', now()->toDateString())" required />
                    <x-ui.date-picker name="valid_until" id="referencePriceValidUntil" :label="__($rp.'.valid_until')" :value="old('valid_until')" :helper="__($rp.'.open_ended')" />
                </div>
                <x-ui.textarea name="notes" id="referencePriceNotes" :label="__($rp.'.notes')" rows="2" maxlength="1000">{{ old('notes') }}</x-ui.textarea>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="referencePriceSupersedeModal" tabindex="-1" aria-labelledby="referencePriceSupersedeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw:text-ui-md tw:font-bold" id="referencePriceSupersedeTitle" data-title-template="{{ __($rp.'.supersede_title', ['material' => '__MATERIAL__']) }}">{{ __($rp.'.supersede') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body tw:grid tw:gap-3">
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __($rp.'.supersede_help') }}</p>
                <x-ui.input name="price_per_kg" id="supersedePrice" type="number" step="0.0001" min="0.0001" :label="__($rp.'.price_per_kg')" required />
                <x-ui.date-picker name="valid_from" id="supersedeValidFrom" :label="__($rp.'.valid_from')" required />
                <x-ui.date-picker name="valid_until" id="supersedeValidUntil" :label="__($rp.'.valid_until')" :helper="__($rp.'.open_ended')" />
                <x-ui.textarea name="notes" id="supersedeNotes" :label="__($rp.'.notes')" rows="2" maxlength="1000" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="referencePriceVoidModal" tabindex="-1" aria-labelledby="referencePriceVoidTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw:text-ui-md tw:font-bold" id="referencePriceVoidTitle" data-title-template="{{ __($rp.'.void_title', ['material' => '__MATERIAL__']) }}">{{ __($rp.'.void') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <x-ui.textarea name="void_reason" id="voidReason" :label="__($rp.'.void_reason')" rows="3" required minlength="3" maxlength="1000" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger" size="sm">{{ __($rp.'.void') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="referencePriceNotesModal" tabindex="-1" aria-labelledby="referencePriceNotesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            @method('PATCH')
            <div class="modal-header">
                <h2 class="modal-title tw:text-ui-md tw:font-bold" id="referencePriceNotesTitle">{{ __($rp.'.edit_notes') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <x-ui.textarea name="notes" id="notesValue" :label="__($rp.'.notes')" rows="3" maxlength="1000" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Event delegation for Edit Category modal trigger
    document.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-bs-target="#referencePriceEditCategoryModal"]');
        if (!btn) return;
        const keyInput = document.getElementById('editCatKey');
        const nameInput = document.getElementById('editCatName');
        const minInput = document.getElementById('editCatMin');
        const maxInput = document.getElementById('editCatMax');
        if (keyInput) keyInput.value = btn.getAttribute('data-category-key') || '';
        if (nameInput) nameInput.value = btn.getAttribute('data-category-name') || '';
        if (minInput) minInput.value = btn.getAttribute('data-dimension-min') || '';
        if (maxInput) maxInput.value = btn.getAttribute('data-dimension-max') || '';
    });

    // Event delegation for Toggle Category status trigger
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-toggle-category-trigger]');
        if (!trigger) return;
        event.preventDefault();

        const form = trigger.closest('form');
        if (!form) return;

        const actionType = form.dataset.toggleConfirm;
        const key = form.dataset.categoryKey || '';
        const isDeactivate = actionType === 'deactivate';

        const title = isDeactivate
            ? @js(__('materials.reference_prices.rules.action_deactivate', ['key' => '__KEY__'])).replace('__KEY__', key)
            : @js(__('materials.reference_prices.rules.action_activate', ['key' => '__KEY__'])).replace('__KEY__', key);

        const text = isDeactivate
            ? @js(__('materials.reference_prices.rules.toggle_confirm_deactivate', ['key' => '__KEY__'])).replace('__KEY__', key)
            : @js(__('materials.reference_prices.rules.toggle_confirm_activate', ['key' => '__KEY__'])).replace('__KEY__', key);

        const confirmText = isDeactivate
            ? @js(__('materials.reference_prices.rules.status_inactive'))
            : @js(__('materials.reference_prices.rules.status_active'));

        const cancelText = @js(__('common.actions.cancel'));

        if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
            const ask = isDeactivate ? window.AdasiAlert.confirmDanger : window.AdasiAlert.confirm;
            ask({
                title: title,
                text: text,
                confirmText: confirmText,
                cancelText: cancelText,
            }).then(function (result) {
                if (result && result.isConfirmed) {
                    if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                        window.AdasiButton.startLoading(trigger);
                    }
                    form.submit();
                }
            });
        } else {
            if (window.confirm(title + '\n\n' + text)) {
                if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                    window.AdasiButton.startLoading(trigger);
                }
                form.submit();
            }
        }
    });

    // Event delegation for Legacy modals (supersede, void, notes)
    document.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        const modal = event.target;
        const form = modal ? modal.querySelector('[data-reference-price-form]') : null;
        if (!trigger || !form) return;

        if (trigger.dataset.action) {
            form.action = trigger.dataset.action;
        }
        const title = modal.querySelector('[data-title-template]');
        if (title && trigger.dataset.material) {
            title.textContent = title.dataset.titleTemplate.replace('__MATERIAL__', trigger.dataset.material);
        }
        const notes = form.querySelector('#notesValue');
        if (notes && trigger.dataset.notes !== undefined) {
            notes.value = trigger.dataset.notes || '';
        }
    });

    // React to server-tabs update to toggle action buttons in header
    document.addEventListener('adasi:server-tabs:updated', function (event) {
        const tab = event.detail?.tab;
        const rulesAction = document.querySelector('[data-reference-prices-rules-action]');
        const legacyAction = document.querySelector('[data-reference-prices-legacy-action]');
        if (rulesAction) rulesAction.hidden = (tab !== 'rules');
        if (legacyAction) legacyAction.hidden = (tab !== 'legacy');
    });

    @if($errors->any() && old('material_code') !== null)
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('referencePriceCreateModal'))?.show();
    @endif
});
</script>
@endpush
@endsection
