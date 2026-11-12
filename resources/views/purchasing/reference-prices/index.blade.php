@extends('layouts.app')
@section('title', __('materials.reference_prices.title'))
@section('page-title', __('materials.reference_prices.title'))

@php
    $rp = 'materials.reference_prices';
    $prefix = request()->routeIs('admin.*') ? 'admin.reference-prices' : 'purchasing.master-data.reference-prices';
    $preview = session('import_preview');
    $importId = session('import_id');
    $importFilename = session('import_filename');
@endphp

@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.page-header :title="__($rp.'.title')" :description="__($rp.'.description')" :eyebrow="__('navigation.master_data')">
        <x-slot:actions>
            @if($activeTab === 'rules')
                <x-ui.button type="button" size="sm" variant="outline" data-bs-toggle="modal" data-bs-target="#referencePriceAddCategoryModal">
                    <x-ui.icon name="plus" size="sm" />
                    <span>{{ __($rp.'.rules.add_category') }}</span>
                </x-ui.button>
                <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#referencePriceImportModal">
                    <x-ui.icon name="upload" size="sm" />
                    <span>{{ __($rp.'.rules.import_excel') }}</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <x-ui.alert tone="success">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    @if(session('error') || $errors->any())
        <x-ui.alert tone="error">
            @if(session('error'))
                <p class="tw:m-0">{{ session('error') }}</p>
            @endif
            @if($errors->any())
                <ul class="tw:m-0 tw:ps-4">
                    @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </x-ui.alert>
    @endif

    {{-- Tabs Navigation --}}
    <div class="tw:flex tw:border-b tw:border-outline-variant tw:gap-2">
        <a href="{{ route($prefix.'.index', ['tab' => 'rules']) }}"
           class="tw:px-4 tw:py-2.5 tw:text-ui-sm tw:font-semibold tw:border-b-2 tw:transition-colors {{ $activeTab === 'rules' ? 'tw:border-primary tw:text-primary' : 'tw:border-transparent tw:text-on-surface-variant hover:tw:text-on-surface hover:tw:border-outline' }}">
            <x-ui.icon name="list-filter" size="sm" class="tw:inline tw:mr-1.5" />
            {{ __($rp.'.tabs.rules') }}
        </a>
        <a href="{{ route($prefix.'.index', ['tab' => 'historical']) }}"
           class="tw:px-4 tw:py-2.5 tw:text-ui-sm tw:font-semibold tw:border-b-2 tw:transition-colors {{ $activeTab === 'historical' ? 'tw:border-primary tw:text-primary' : 'tw:border-transparent tw:text-on-surface-variant hover:tw:text-on-surface hover:tw:border-outline' }}">
            <x-ui.icon name="history" size="sm" class="tw:inline tw:mr-1.5" />
            {{ __($rp.'.tabs.historical') }}
        </a>
        <a href="{{ route($prefix.'.index', ['tab' => 'legacy']) }}"
           class="tw:px-4 tw:py-2.5 tw:text-ui-sm tw:font-semibold tw:border-b-2 tw:transition-colors {{ $activeTab === 'legacy' ? 'tw:border-primary tw:text-primary' : 'tw:border-transparent tw:text-on-surface-variant hover:tw:text-on-surface hover:tw:border-outline' }}">
            <x-ui.icon name="archive" size="sm" class="tw:inline tw:mr-1.5" />
            {{ __($rp.'.tabs.legacy') }}
        </a>
    </div>

    {{-- TAB 1: RULES & CATEGORIES --}}
    @if($activeTab === 'rules')
        @if($preview)
            {{-- Import Preview Card --}}
            <x-ui.card class="tw:border-2 {{ $preview['valid'] ? 'tw:border-primary/40 tw:bg-primary-container/10' : 'tw:border-error/40 tw:bg-error-container/10' }}">
                <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:mb-3">
                    <div>
                        <h3 class="tw:text-ui-base tw:font-semibold tw:m-0">{{ __($rp.'.rules.preview_title') }}</h3>
                        <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">
                            {{ $importFilename }} (Mode: {{ strtoupper($preview['mode']) }}) &bull;
                            {{ __($rp.'.rules.preview_summary', [
                                'new' => count($preview['new']),
                                'updated' => count($preview['updated']),
                                'unchanged' => count($preview['unchanged']),
                                'removed' => count($preview['removed']),
                            ]) }}
                        </p>
                    </div>
                    <div class="tw:flex tw:items-center tw:gap-2">
                        <form method="GET" action="{{ route($prefix.'.index') }}">
                            <input type="hidden" name="tab" value="rules">
                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __($rp.'.rules.cancel_button') }}</x-ui.button>
                        </form>
                        @if($preview['valid'])
                            <form method="POST" action="{{ route($prefix.'.rules.import-confirm') }}">
                                @csrf
                                <input type="hidden" name="import_id" value="{{ $importId }}">
                                <x-ui.button type="submit" variant="primary" size="sm">
                                    <x-ui.icon name="check" size="sm" />
                                    <span>{{ __($rp.'.rules.apply_button') }}</span>
                                </x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>

                @if(!empty($preview['conflicts']))
                    <div class="tw:p-3 tw:rounded-lg tw:bg-error-container/20 tw:border tw:border-error/30 tw:mb-3">
                        <h4 class="tw:text-ui-sm tw:font-semibold tw:text-error tw:m-0 tw:mb-1">{{ __($rp.'.rules.conflicts_title') }}</h4>
                        <ul class="tw:m-0 tw:ps-4 tw:text-ui-xs tw:text-on-error-container">
                            @foreach($preview['conflicts'] as $conflict)
                                <li>{{ $conflict }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="tw:overflow-x-auto">
                    <table class="table table-sm table-bordered align-middle mb-0 tw:text-ui-xs">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>{{ __($rp.'.rules.category_key') }}</th>
                                <th>{{ __($rp.'.rules.dimension_min') }}</th>
                                <th>{{ __($rp.'.rules.dimension_max') }}</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview['candidate_rules'] as $idx => $cand)
                                <tr>
                                    <td>{{ $idx + 1 }}</td>
                                    <td class="tw:font-medium">{{ $cand['category_key'] }}</td>
                                    <td>{{ $cand['dimension_min'] }} mm</td>
                                    <td>{{ $cand['dimension_max'] }} mm</td>
                                    <td>
                                        <x-ui.status-chip tone="success" size="sm">{{ $cand['status'] ?? 'active' }}</x-ui.status-chip>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        {{-- Active Rule Set Header Card --}}
        <x-ui.card>
            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
                <div class="tw:flex tw:items-center tw:gap-3">
                    <div class="tw:w-10 tw:h-10 tw:rounded-full tw:bg-primary/10 tw:text-primary tw:flex tw:items-center tw:justify-center">
                        <x-ui.icon name="layers" size="md" />
                    </div>
                    <div>
                        <div class="tw:flex tw:items-center tw:gap-2">
                            <h3 class="tw:text-ui-base tw:font-bold tw:m-0">
                                @if($displayRuleSet)
                                    {{ __($rp.'.rules.version_no') }} {{ $displayRuleSet->version_no }}
                                @else
                                    {{ __($rp.'.rules.no_active_set') }}
                                @endif
                            </h3>
                            @if($displayRuleSet && $displayRuleSet->id === $activeRuleSet?->id)
                                <x-ui.status-chip tone="success" size="sm">{{ __($rp.'.rules.status_active') }}</x-ui.status-chip>
                            @elseif($displayRuleSet)
                                <x-ui.status-chip tone="neutral" size="sm">{{ __($rp.'.rules.status_inactive') }}</x-ui.status-chip>
                            @endif
                        </div>
                        <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">
                            @if($displayRuleSet)
                                {{ $rules->count() }} {{ strtolower(__('materials.reference_prices.reasons.dimension_not_covered') ? 'categories' : 'kategori') }} &bull;
                                {{ __($rp.'.rules.activated_at') }}: {{ $displayRuleSet->activated_at?->format('d M Y H:i') ?? '-' }} &bull;
                                {{ __($rp.'.rules.activated_by') }}: {{ $displayRuleSet->activator?->name ?? $displayRuleSet->creator?->name ?? 'System' }}
                            @else
                                {{ __($rp.'.rules.description') }}
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Version Switcher & Activate Button --}}
                <div class="tw:flex tw:items-center tw:gap-2">
                    @if($ruleSetVersions->count() > 1)
                        <form method="GET" action="{{ route($prefix.'.index') }}" class="tw:flex tw:items-center tw:gap-2">
                            <input type="hidden" name="tab" value="rules">
                            <x-ui.select name="version_id" onchange="this.form.submit()" class="tw:w-auto tw:text-ui-xs">
                                @foreach($ruleSetVersions as $v)
                                    <option value="{{ $v->id }}" @selected($displayRuleSet?->id === $v->id)>
                                        v{{ $v->version_no }} ({{ $v->activated_at?->format('d/m/Y') ?? 'Draft' }}) {{ $v->id === $activeRuleSet?->id ? '— Active' : '' }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </form>
                    @endif

                    @if($displayRuleSet && $displayRuleSet->id !== $activeRuleSet?->id)
                        <form method="POST" action="{{ route($prefix.'.rules.activate', $displayRuleSet) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary" size="sm">
                                <x-ui.icon name="check-circle" size="sm" />
                                <span>{{ __($rp.'.rules.activate') }}</span>
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </x-ui.card>

        {{-- Rules Categories Table --}}
        <x-ui.data-table density="compact" sticky :scroll-label="__($rp.'.rules.title')">
            <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" data-reference-price-rules>
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 60px;">#</th>
                        <th scope="col">{{ __($rp.'.rules.category_key') }}</th>
                        <th scope="col">{{ __($rp.'.rules.category_name') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.rules.dimension_min') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.rules.dimension_max') }}</th>
                        <th scope="col" class="text-center">{{ __($rp.'.rules.status') }}</th>
                        <th scope="col" class="text-end" style="width: 140px;">{{ __($rp.'.rules.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td class="tw:text-on-surface-variant">{{ $rule->sort_order }}</td>
                            <td class="tw:font-semibold tw:text-on-surface">{{ $rule->category_key }}</td>
                            <td>{{ $rule->category_name }}</td>
                            <td class="text-end font-monospace">{{ $rule->dimension_min }} mm</td>
                            <td class="text-end font-monospace">{{ $rule->dimension_max }} mm</td>
                            <td class="text-center">
                                @if($rule->status === 'active')
                                    <x-ui.status-chip tone="success" size="sm">{{ __($rp.'.rules.status_active') }}</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="neutral" size="sm">{{ __($rp.'.rules.status_inactive') }}</x-ui.status-chip>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="tw:inline-flex tw:items-center tw:gap-1">
                                    <form method="POST" action="{{ route($prefix.'.rules.category.toggle', $rule) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="ghost" size="xs" :title="__($rp.'.rules.toggle_status')">
                                            <x-ui.icon :name="$rule->status === 'active' ? 'eye-off' : 'eye'" size="xs" />
                                        </x-ui.button>
                                    </form>
                                    <x-ui.button type="button" variant="ghost" size="xs"
                                                 data-bs-toggle="modal" data-bs-target="#referencePriceEditCategoryModal"
                                                 data-category-key="{{ $rule->category_key }}"
                                                 data-category-name="{{ $rule->category_name }}"
                                                 data-dimension-min="{{ $rule->dimension_min }}"
                                                 data-dimension-max="{{ $rule->dimension_max }}">
                                        <x-ui.icon name="edit-2" size="xs" />
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 tw:text-on-surface-variant">
                                {{ __($rp.'.rules.no_active_set') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

    {{-- TAB 2: HISTORICAL BENCHMARK (Stage 2/4) --}}
    @elseif($activeTab === 'historical')
        <x-ui.card>
            <div class="tw:flex tw:items-center tw:gap-3 tw:mb-4">
                <div class="tw:w-10 tw:h-10 tw:rounded-full tw:bg-primary/10 tw:text-primary tw:flex tw:items-center tw:justify-center">
                    <x-ui.icon name="trending-up" size="md" />
                </div>
                <div>
                    <h3 class="tw:text-ui-base tw:font-bold tw:m-0">{{ __($rp.'.historical.title') }}</h3>
                    <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">{{ __($rp.'.historical.description') }}</p>
                </div>
                <div class="tw:ms-auto">
                    <x-ui.status-chip tone="info" size="md">
                        <x-ui.icon name="globe" size="xs" class="tw:inline tw:mr-1" />
                        {{ __($rp.'.historical.company_wide_badge') }}
                    </x-ui.status-chip>
                </div>
            </div>

            <div class="tw:p-6 tw:text-center tw:rounded-lg tw:bg-surface-variant/30 tw:border tw:border-dashed tw:border-outline-variant">
                <x-ui.icon name="bar-chart-3" size="lg" class="tw:mx-auto tw:text-on-surface-variant tw:mb-2" />
                <p class="tw:text-ui-sm tw:font-medium tw:text-on-surface tw:m-0">{{ __($rp.'.historical.no_data') }}</p>
                <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0 tw:mt-1">
                    Historical calculation service is integrated in Stage 2 & 4.
                </p>
            </div>
        </x-ui.card>

    {{-- TAB 3: LEGACY ARCHIVE (Stage 6) --}}
    @elseif($activeTab === 'legacy')
        <div class="tw:p-3 tw:rounded-lg tw:bg-surface-variant/40 tw:border tw:border-outline-variant tw:flex tw:items-center tw:gap-3">
            <x-ui.icon name="info" size="sm" class="tw:text-primary tw:shrink-0" />
            <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">{{ __($rp.'.legacy.notice') }}</p>
        </div>

        <form method="GET" action="{{ route($prefix.'.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" role="search">
            <input type="hidden" name="tab" value="legacy">
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
            <x-ui.button :href="route($prefix.'.index', ['tab' => 'legacy'])" variant="ghost" size="sm">{{ __($rp.'.reset') }}</x-ui.button>
        </form>

        <x-ui.data-table density="compact" sticky :scroll-label="__($rp.'.legacy.title')">
            <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" data-reference-prices>
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
                    </tr>
                </thead>
                <tbody>
                    @if($legacyPrices)
                        @forelse($legacyPrices as $price)
                            <tr>
                                <td>{{ $price->materialMaster?->material_code }}</td>
                                <td>{{ $price->shape }}</td>
                                <td>{{ $price->dimension_min }}–{{ $price->dimension_max }} mm</td>
                                <td class="text-end font-monospace">{{ $price->currency }} {{ number_format($price->price_per_kg, 4) }}</td>
                                <td>{{ $price->valid_from?->format('d/m/Y') }}</td>
                                <td>{{ $price->valid_until ? $price->valid_until->format('d/m/Y') : __($rp.'.open_ended') }}</td>
                                <td class="text-center">v{{ $price->version }}</td>
                                <td>
                                    <x-ui.status-chip :tone="$price->status === 'active' ? 'success' : 'neutral'" size="sm">
                                        {{ $price->status === 'active' ? __($rp.'.status_active') : __($rp.'.status_void') }}
                                    </x-ui.status-chip>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 tw:text-on-surface-variant">{{ __($rp.'.empty') }}</td>
                            </tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </x-ui.data-table>

        @if($legacyPrices && $legacyPrices->hasPages())
            <div class="tw:mt-4">
                {{ $legacyPrices->links() }}
            </div>
        @endif
    @endif
</div>

{{-- MODAL: Import Excel --}}
<div class="modal fade" id="referencePriceImportModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route($prefix.'.rules.import-preview') }}" enctype="multipart/form-data" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">{{ __($rp.'.rules.import_excel') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tw:grid tw:gap-3">
                <x-ui.select name="import_mode" :label="__($rp.'.rules.import_mode')" required>
                    <option value="{{ \App\Models\ReferencePriceRuleSet::MODE_REPLACE }}">{{ __($rp.'.rules.mode_replace') }}</option>
                    <option value="{{ \App\Models\ReferencePriceRuleSet::MODE_MERGE }}">{{ __($rp.'.rules.mode_merge') }}</option>
                </x-ui.select>
                <x-ui.input name="sheet_name" :label="'Worksheet Name'" value="Kategori" required />
                <div>
                    <label class="form-label tw:text-ui-xs tw:font-semibold">{{ __($rp.'.rules.upload_file') }}</label>
                    <input type="file" name="file" accept=".xlsx" class="form-control form-control-sm" required>
                    <p class="tw:text-ui-xs tw:text-on-surface-variant tw:mt-1">
                        Format: Workbook dengan sheet <code>Kategori</code>, kolom <code>Thick/Dia (mm)</code> dan <code>Kategori</code>.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm">
                    <x-ui.icon name="file-search" size="sm" />
                    <span>{{ __($rp.'.rules.preview_button') }}</span>
                </x-ui.button>
            </div>
        </form>
    </div>
</div>

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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
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
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __($rp.'.cancel') }}</x-ui.button>
                <x-ui.button type="submit" size="sm">{{ __($rp.'.save') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('referencePriceEditCategoryModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('editCatKey').value = btn.getAttribute('data-category-key') || '';
            document.getElementById('editCatName').value = btn.getAttribute('data-category-name') || '';
            document.getElementById('editCatMin').value = btn.getAttribute('data-dimension-min') || '';
            document.getElementById('editCatMax').value = btn.getAttribute('data-dimension-max') || '';
        });
    }

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
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('referencePriceCreateModal'))?.show();
    @endif
});
</script>
@endpush
@endsection
