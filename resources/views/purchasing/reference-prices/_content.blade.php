@php
    $rp = $rp ?? 'materials.reference_prices';
    $prefix = $prefix ?? (request()->user()?->role === 'admin' ? 'admin.reference-prices' : 'purchasing.master-data.reference-prices');
@endphp

<div>
    {{-- TAB 1: RULES & CATEGORIES --}}
    @if($activeTab === 'rules')
        @if($preview)
            {{-- Import Preview Card --}}
            <x-ui.card class="tw:border-2 {{ $preview['valid'] ? 'tw:border-primary/40 tw:bg-primary-container/10' : 'tw:border-error/40 tw:bg-error-container/10' }} tw:mb-4">
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
                            <x-ui.button type="submit" variant="outline" size="sm">{{ __($rp.'.rules.cancel_button') }}</x-ui.button>
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
                    <x-ui.alert tone="error" :title="__($rp.'.rules.conflicts_title')" class="tw:mb-3">
                        <ul class="tw:m-0 tw:ps-4 tw:text-ui-xs">
                            @foreach($preview['conflicts'] as $conflict)
                                <li>{{ $conflict }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif

                <div class="tw:overflow-x-auto">
                    <table class="table table-sm table-bordered align-middle mb-0 tw:text-ui-xs">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 48px;">#</th>
                                <th>{{ __($rp.'.rules.category_key') }}</th>
                                <th>{{ __($rp.'.rules.dimension_min') }}</th>
                                <th>{{ __($rp.'.rules.dimension_max') }}</th>
                                <th>{{ __($rp.'.rules.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview['candidate_rules'] as $idx => $cand)
                                <tr>
                                    <td class="ui-tabular-nums text-muted">{{ $idx + 1 }}</td>
                                    <td class="tw:font-medium ui-tabular-nums">{{ $cand['category_key'] }}</td>
                                    <td class="ui-tabular-nums">{{ $cand['dimension_min'] }} mm</td>
                                    <td class="ui-tabular-nums">{{ $cand['dimension_max'] }} mm</td>
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
                            <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $rule->sort_order }}</td>
                            <td class="tw:font-semibold tw:text-on-surface ui-tabular-nums">{{ $rule->category_key }}</td>
                            <td>{{ $rule->category_name }}</td>
                            <td class="text-end ui-tabular-nums">{{ $rule->dimension_min }} mm</td>
                            <td class="text-end ui-tabular-nums">{{ $rule->dimension_max }} mm</td>
                            <td class="text-center">
                                @if($rule->status === 'active')
                                    <x-ui.status-chip tone="success" size="sm">{{ __($rp.'.rules.status_active') }}</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="neutral" size="sm">{{ __($rp.'.rules.status_inactive') }}</x-ui.status-chip>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="tw:inline-flex tw:items-center tw:gap-1.5">
                                    @if($rule->status === 'active')
                                        <form
                                            method="POST"
                                            action="{{ route($prefix.'.rules.category.toggle', $rule) }}"
                                            class="tw:inline"
                                            data-managed-submit
                                            data-toggle-confirm="deactivate"
                                            data-category-key="{{ $rule->category_key }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <x-ui.icon-button
                                                type="button"
                                                icon="eye-off"
                                                variant="warning-outline"
                                                size="sm"
                                                data-no-auto-spinner
                                                data-toggle-category-trigger
                                                :label="__($rp.'.rules.action_deactivate', ['key' => $rule->category_key])"
                                            />
                                        </form>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route($prefix.'.rules.category.toggle', $rule) }}"
                                            class="tw:inline"
                                            data-managed-submit
                                            data-toggle-confirm="activate"
                                            data-category-key="{{ $rule->category_key }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <x-ui.icon-button
                                                type="button"
                                                icon="eye"
                                                variant="success-outline"
                                                size="sm"
                                                data-no-auto-spinner
                                                data-toggle-category-trigger
                                                :label="__($rp.'.rules.action_activate', ['key' => $rule->category_key])"
                                            />
                                        </form>
                                    @endif
                                    <x-ui.icon-button
                                        type="button"
                                        icon="pencil"
                                        variant="secondary-outline"
                                        size="sm"
                                        :label="__($rp.'.rules.action_edit', ['key' => $rule->category_key])"
                                        data-bs-toggle="modal"
                                        data-bs-target="#referencePriceEditCategoryModal"
                                        data-category-key="{{ $rule->category_key }}"
                                        data-category-name="{{ $rule->category_name }}"
                                        data-dimension-min="{{ $rule->dimension_min }}"
                                        data-dimension-max="{{ $rule->dimension_max }}"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 tw:text-on-surface-variant">
                                {{ __('common.states.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

    {{-- TAB 2: HISTORICAL BENCHMARK --}}
    @elseif($activeTab === 'benchmark' || $activeTab === 'historical')
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
                    Historical calculation service is integrated for automatic benchmark resolution.
                </p>
            </div>
        </x-ui.card>

    {{-- TAB 3: LEGACY ARCHIVE --}}
    @elseif($activeTab === 'legacy')
        <div class="tw:p-3 tw:rounded-lg tw:bg-surface-variant/40 tw:border tw:border-outline-variant tw:flex tw:items-center tw:gap-3 tw:mb-4">
            <x-ui.icon name="info" size="sm" class="tw:text-primary tw:shrink-0" />
            <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">{{ __($rp.'.legacy.notice') }}</p>
        </div>

        <x-ui.toolbar aria-label="{{ __($rp.'.legacy.title') }}" class="tw:mb-4">
            <x-slot:filters>
                <form method="GET" action="{{ route($prefix.'.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" data-server-tabs-form role="search">
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
                    <x-ui.button type="submit" variant="primary" size="sm">{{ __($rp.'.filter') }}</x-ui.button>
                    <x-ui.button :href="route($prefix.'.index', ['tab' => 'legacy'])" variant="outline" size="sm">{{ __($rp.'.reset') }}</x-ui.button>
                </form>
            </x-slot:filters>
        </x-ui.toolbar>

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
                        <th scope="col" class="text-end">{{ __($rp.'.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @if($legacyPrices)
                        @forelse($legacyPrices as $price)
                            <tr>
                                <td class="tw:font-medium">{{ $price->materialMaster?->material_code }}</td>
                                <td>{{ $price->shape }}</td>
                                <td class="ui-tabular-nums">{{ $price->dimension_min }}–{{ $price->dimension_max }} mm</td>
                                <td class="text-end ui-tabular-nums tw:font-medium">{{ $price->currency }} {{ number_format($price->price_per_kg, 4) }}</td>
                                <td>{{ $price->valid_from?->format('d/m/Y') }}</td>
                                <td>{{ $price->valid_until ? $price->valid_until->format('d/m/Y') : __($rp.'.open_ended') }}</td>
                                <td class="text-center ui-tabular-nums">v{{ $price->version }}</td>
                                <td>
                                    <x-ui.status-chip :tone="$price->status === 'active' ? 'success' : 'neutral'" size="sm">
                                        {{ $price->status === 'active' ? __($rp.'.status_active') : __($rp.'.status_void') }}
                                    </x-ui.status-chip>
                                </td>
                                <td class="text-end">
                                    <div class="tw:inline-flex tw:items-center tw:gap-1.5">
                                        <x-ui.icon-button
                                            type="button"
                                            icon="pencil"
                                            variant="secondary-outline"
                                            size="sm"
                                            :label="__($rp.'.edit_notes')"
                                            data-bs-toggle="modal"
                                            data-bs-target="#referencePriceNotesModal"
                                            data-action="{{ route($prefix.'.notes', $price) }}"
                                            data-notes="{{ $price->notes }}"
                                        />
                                        @if($price->status === 'active')
                                            <x-ui.icon-button
                                                type="button"
                                                icon="refresh-cw"
                                                variant="secondary-outline"
                                                size="sm"
                                                :label="__($rp.'.supersede')"
                                                data-bs-toggle="modal"
                                                data-bs-target="#referencePriceSupersedeModal"
                                                data-action="{{ route($prefix.'.supersede', $price) }}"
                                                data-material="{{ $price->materialMaster?->material_code }}"
                                            />
                                            <x-ui.icon-button
                                                type="button"
                                                icon="trash-2"
                                                variant="danger-outline"
                                                size="sm"
                                                :label="__($rp.'.rules.action_void', ['material' => $price->materialMaster?->material_code ?? ''])"
                                                data-bs-toggle="modal"
                                                data-bs-target="#referencePriceVoidModal"
                                                data-action="{{ route($prefix.'.void', $price) }}"
                                                data-material="{{ $price->materialMaster?->material_code }}"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-4 tw:text-on-surface-variant">{{ __($rp.'.empty') }}</td>
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

