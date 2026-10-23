@extends('layouts.app')
@section('title', __('materials.reference_prices.title'))
@section('page-title', __('materials.reference_prices.title'))

@php
    $rp = 'materials.reference_prices';
    $num = fn ($value, int $decimals = 4) => \App\Support\NumberFormat::maxDecimals($value, $decimals);
@endphp

@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.page-header :title="__($rp.'.title')" :description="__($rp.'.description')" :eyebrow="__('navigation.master_data')">
        <x-slot:actions>
            <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#referencePriceCreateModal">
                <x-ui.icon name="plus-circle" size="sm" />
                <span>{{ __($rp.'.add') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <x-ui.alert tone="error">
            <ul class="tw:m-0 tw:ps-4">
                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="GET" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" role="search">
        <x-ui.input name="search" id="referencePriceSearch" :label="__($rp.'.material_code')" :value="$filters['search'] ?? ''" :placeholder="__($rp.'.search_placeholder')" />
        <x-ui.select name="shape" id="referencePriceShapeFilter" :label="__($rp.'.shape')">
            <option value="">{{ __($rp.'.all_shapes') }}</option>
            @foreach($shapes as $shape)<option value="{{ $shape }}" @selected(($filters['shape'] ?? '') === $shape)>{{ $shape }}</option>@endforeach
        </x-ui.select>
        <x-ui.select name="currency" id="referencePriceCurrencyFilter" :label="__($rp.'.currency')">
            <option value="">{{ __($rp.'.all_currencies') }}</option>
            @foreach($currencies as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach
        </x-ui.select>
        <x-ui.select name="status" id="referencePriceStatusFilter" :label="__($rp.'.status')">
            <option value="active" @selected($filters['status'] === 'active')>{{ __($rp.'.status_active') }}</option>
            <option value="void" @selected($filters['status'] === 'void')>{{ __($rp.'.status_void') }}</option>
            <option value="all" @selected($filters['status'] === 'all')>{{ __($rp.'.status_all') }}</option>
        </x-ui.select>
        <x-ui.button type="submit" size="sm">{{ __($rp.'.filter') }}</x-ui.button>
        <x-ui.button :href="route('purchasing.master-data.reference-prices.index')" variant="ghost" size="sm">{{ __($rp.'.reset') }}</x-ui.button>
    </form>

    <x-ui.data-table density="compact" sticky :scroll-label="__($rp.'.title')">
        <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" data-reference-prices>
            <caption class="visually-hidden">{{ __($rp.'.title') }}</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __($rp.'.material_code') }}</th>
                    <th scope="col">{{ __($rp.'.shape') }}</th>
                    <th scope="col">{{ __($rp.'.key_dimension') }}</th>
                    <th scope="col" class="text-end">{{ __($rp.'.price_per_kg') }}</th>
                    <th scope="col">{{ __($rp.'.valid_from') }}</th>
                    <th scope="col">{{ __($rp.'.valid_until') }}</th>
                    <th scope="col" class="text-center">{{ __($rp.'.version') }}</th>
                    <th scope="col">{{ __($rp.'.status') }}</th>
                    <th scope="col">{{ __($rp.'.notes') }}</th>
                    <th scope="col" class="text-end">{{ __($rp.'.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($prices as $price)
                    @php($material = $price->materialMaster?->material_code ?? '-')
                    <tr>
                        <td class="fw-semibold">{{ $material }}</td>
                        <td>{{ $price->shape }}</td>
                        <td class="ui-tabular-nums">
                            {{ __($rp.'.dimension_'.$price->dimension_field) }}
                            {{ $num($price->dimension_min) }} – {{ $num($price->dimension_max) }} mm
                        </td>
                        <td class="text-end ui-tabular-nums">{{ $price->currency }} {{ $num($price->price_per_kg) }}</td>
                        <td class="ui-tabular-nums">{{ $price->valid_from->format('Y-m-d') }}</td>
                        <td class="ui-tabular-nums">{{ $price->valid_until?->format('Y-m-d') ?? __($rp.'.open_ended') }}</td>
                        <td class="text-center ui-tabular-nums">{{ $price->version_no }}</td>
                        <td>
                            <x-ui.status-chip :tone="$price->status === 'active' ? 'success' : 'neutral'" size="sm">
                                {{ __($rp.'.status_'.$price->status) }}
                            </x-ui.status-chip>
                            @if($price->status === 'void')
                                <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __($rp.'.voided_info', ['user' => $price->voider?->name ?? '-', 'date' => $price->voided_at ? \App\Support\BusinessTime::format($price->voided_at, 'd M Y', false) : '-', 'reason' => $price->void_reason]) }}</div>
                            @endif
                        </td>
                        <td class="tw:text-ui-xs" style="max-width: 16rem;">{{ $price->notes ?? '—' }}</td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="ui-data-action ui-focus-ring" data-bs-toggle="modal" data-bs-target="#referencePriceNotesModal"
                                        data-action="{{ route('purchasing.master-data.reference-prices.notes', $price) }}" data-notes="{{ $price->notes }}" data-material="{{ $material }}"
                                        aria-label="{{ __($rp.'.edit_notes') }} {{ $material }}">{{ __($rp.'.edit_notes') }}</button>
                                @if($price->status === 'active')
                                    <button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring" data-bs-toggle="modal" data-bs-target="#referencePriceSupersedeModal"
                                            data-action="{{ route('purchasing.master-data.reference-prices.supersede', $price) }}" data-material="{{ $material }}"
                                            aria-label="{{ __($rp.'.supersede') }} {{ $material }}">{{ __($rp.'.supersede') }}</button>
                                    <button type="button" class="ui-data-action ui-focus-ring text-danger" data-bs-toggle="modal" data-bs-target="#referencePriceVoidModal"
                                            data-action="{{ route('purchasing.master-data.reference-prices.void', $price) }}" data-material="{{ $material }}"
                                            aria-label="{{ __($rp.'.void') }} {{ $material }}">{{ __($rp.'.void') }}</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center tw:text-on-surface-variant">{{ __($rp.'.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
    {{ $prices->links() }}
</div>

{{-- Create --}}
<div class="modal fade" id="referencePriceCreateModal" tabindex="-1" aria-labelledby="referencePriceCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ route('purchasing.master-data.reference-prices.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw-text-ui-md fw-bold" id="referencePriceCreateTitle">{{ __($rp.'.add') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body tw:grid tw:items-start tw:gap-3 tw:md:grid-cols-2">
                <x-ui.searchable-select name="material_code" id="referencePriceMaterialCode" :label="__($rp.'.material_code')" :options="$materialOptions" :show-sublabel-on-trigger="false" required />
                <x-ui.select name="shape" id="referencePriceShape" :label="__($rp.'.shape')" required>
                    @foreach($shapes as $shape)<option value="{{ $shape }}" @selected(old('shape') === $shape)>{{ $shape }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="dimension_min" id="referencePriceDimensionMin" type="number" step="1" min="1" max="999999" inputmode="numeric" :label="__($rp.'.dimension_min')" :value="old('dimension_min')" :helper="__($rp.'.dimension_whole_number_help')" required />
                <x-ui.input name="dimension_max" id="referencePriceDimensionMax" type="number" step="1" min="1" max="999999" inputmode="numeric" :label="__($rp.'.dimension_max')" :value="old('dimension_max')" required />
                <x-ui.select name="currency" id="referencePriceCurrency" :label="__($rp.'.currency')" required>
                    @foreach($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="price_per_kg" id="referencePricePrice" type="number" step="0.0001" min="0.0001" :label="__($rp.'.price_per_kg')" :value="old('price_per_kg')" required />
                <x-ui.date-picker name="valid_from" id="referencePriceValidFrom" :label="__($rp.'.valid_from')" :value="old('valid_from')" required />
                <x-ui.date-picker name="valid_until" id="referencePriceValidUntil" :label="__($rp.'.valid_until')" :value="old('valid_until')" :helper="__($rp.'.open_ended')" />
                <div class="tw:md:col-span-2">
                    <x-ui.textarea name="notes" id="referencePriceNotes" :label="__($rp.'.notes')" :value="old('notes')" rows="2" maxlength="1000" />
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

{{-- Supersede --}}
<div class="modal fade" id="referencePriceSupersedeModal" tabindex="-1" aria-labelledby="referencePriceSupersedeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw-text-ui-md fw-bold" id="referencePriceSupersedeTitle" data-title-template="{{ __($rp.'.supersede_title', ['material' => '__MATERIAL__']) }}">{{ __($rp.'.supersede') }}</h2>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

{{-- Void --}}
<div class="modal fade" id="referencePriceVoidModal" tabindex="-1" aria-labelledby="referencePriceVoidTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            <div class="modal-header">
                <h2 class="modal-title tw-text-ui-md fw-bold" id="referencePriceVoidTitle" data-title-template="{{ __($rp.'.void_title', ['material' => '__MATERIAL__']) }}">{{ __($rp.'.void') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <x-ui.textarea name="void_reason" id="voidReason" :label="__($rp.'.void_reason')" rows="3" required minlength="3" maxlength="1000" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger" size="sm">{{ __($rp.'.void') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

{{-- Notes --}}
<div class="modal fade" id="referencePriceNotesModal" tabindex="-1" aria-labelledby="referencePriceNotesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-reference-price-form>
            @csrf
            @method('PATCH')
            <div class="modal-header">
                <h2 class="modal-title tw-text-ui-md fw-bold" id="referencePriceNotesTitle">{{ __($rp.'.edit_notes') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __($rp.'.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <x-ui.textarea name="notes" id="notesValue" :label="__($rp.'.notes')" rows="3" maxlength="1000" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const form = event.target.querySelector('[data-reference-price-form]');
    if (!trigger?.dataset.action || !form) return;
    form.action = trigger.dataset.action;
    const title = event.target.querySelector('[data-title-template]');
    if (title) title.textContent = title.dataset.titleTemplate.replace('__MATERIAL__', trigger.dataset.material || '');
    const notes = form.querySelector('#notesValue');
    if (notes) notes.value = trigger.dataset.notes || '';
});
@if($errors->any() && old('material_code') !== null)
document.addEventListener('DOMContentLoaded', () => window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('referencePriceCreateModal')).show());
@endif
</script>
@endpush
