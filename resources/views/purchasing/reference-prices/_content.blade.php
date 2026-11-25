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
                                        data-ui-modal-open="#referencePriceEditCategoryModal"
                                        data-rule-id="{{ $rule->id }}"
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
        @php
            $historicalItems = $historicalLines ?? collect();
            $poStatusFilter = $poStatusFilter ?? ($filters['po_status'] ?? 'completed');
            $targetCurrency = $targetCurrency ?? ($filters['currency'] ?? 'IDR');
            $benchmarkResetUrl = route($prefix.'.index', ['tab' => 'benchmark']);
            $benchmarkRemoveUrl = static function (array $dropKeys) use ($prefix): string {
                $query = array_merge(request()->query(), ['tab' => 'benchmark']);
                foreach ($dropKeys as $key) {
                    unset($query[$key]);
                }

                return route($prefix.'.index', $query);
            };
            $benchmarkLinkAttrs = ['data-server-tab' => '', 'data-tab-name' => 'benchmark'];
            $benchmarkPoStatusLabels = [
                'completed' => __('purchasing.copy.completed'),
                'all' => __('common.filters.all'),
                'active' => __('purchasing.copy.active'),
                'waiting_qc' => __('purchasing.copy.waiting_qc'),
            ];

            $benchmarkChips = [];
            if (! empty($filters['search'])) {
                $benchmarkChips[] = [
                    'key' => 'search',
                    'label' => __($rp.'.material_code'),
                    'value' => (string) $filters['search'],
                    'removeUrl' => $benchmarkRemoveUrl(['search']),
                    'removeAttrs' => $benchmarkLinkAttrs,
                ];
            }
            if (! empty($filters['shape'])) {
                $benchmarkChips[] = [
                    'key' => 'shape',
                    'label' => __($rp.'.shape'),
                    'value' => (string) $filters['shape'],
                    'removeUrl' => $benchmarkRemoveUrl(['shape']),
                    'removeAttrs' => $benchmarkLinkAttrs,
                ];
            }
            if ($poStatusFilter !== 'completed') {
                $benchmarkChips[] = [
                    'key' => 'po_status',
                    'label' => __('purchasing.copy.status'),
                    'value' => $benchmarkPoStatusLabels[$poStatusFilter] ?? (string) $poStatusFilter,
                    'removeUrl' => $benchmarkRemoveUrl(['po_status']),
                    'removeAttrs' => $benchmarkLinkAttrs,
                ];
            }
            if ($targetCurrency !== 'IDR') {
                $benchmarkChips[] = [
                    'key' => 'currency',
                    'label' => __($rp.'.currency'),
                    'value' => (string) $targetCurrency,
                    'removeUrl' => $benchmarkRemoveUrl(['currency']),
                    'removeAttrs' => $benchmarkLinkAttrs,
                ];
            }
        @endphp

        <x-ui.filter-bar
            id="benchmarkRefFilterForm"
            :action="route($prefix.'.index')"
            :server-tabs="true"
            :aria-label="__($rp.'.historical.title')"
            :has-active-filters="count($benchmarkChips) > 0"
            :reset-url="$benchmarkResetUrl"
            :reset-label="__($rp.'.reset')"
            :reset-attrs="$benchmarkLinkAttrs"
            :submit-label="__($rp.'.filter')"
            class="tw:mb-4"
        >
            <x-slot:hidden>
                <input type="hidden" name="tab" value="benchmark">
            </x-slot:hidden>

            <x-slot:search>
                <label for="historicalRefSearch" class="tw:sr-only">{{ __($rp.'.material_code') }}</label>
                <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                <input
                    type="search"
                    name="search"
                    id="historicalRefSearch"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="{{ __($rp.'.search_placeholder') }}"
                    class="form-control form-control-sm ui-filter-bar__search-input"
                    autocomplete="off"
                >
            </x-slot:search>

            <x-slot:filters>
                <label for="historicalRefShapeFilter" class="tw:sr-only">{{ __($rp.'.shape') }}</label>
                <select name="shape" id="historicalRefShapeFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-40">
                    <option value="">{{ __($rp.'.all_shapes') }}</option>
                    @foreach($shapes as $shape)
                        <option value="{{ $shape }}" @selected(($filters['shape'] ?? '') === $shape)>{{ $shape }}</option>
                    @endforeach
                </select>

                <label for="historicalRefPoStatusFilter" class="tw:sr-only">{{ __('purchasing.copy.status') }}</label>
                <select name="po_status" id="historicalRefPoStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44">
                    <option value="completed" @selected($poStatusFilter === 'completed')>{{ __('purchasing.copy.completed') }}</option>
                    <option value="all" @selected($poStatusFilter === 'all')>{{ __('common.filters.all') }}</option>
                    <option value="active" @selected($poStatusFilter === 'active')>{{ __('purchasing.copy.active') }}</option>
                    <option value="waiting_qc" @selected($poStatusFilter === 'waiting_qc')>{{ __('purchasing.copy.waiting_qc') }}</option>
                </select>

                <label for="historicalRefCurrencyFilter" class="tw:sr-only">{{ __($rp.'.currency') }}</label>
                <select name="currency" id="historicalRefCurrencyFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-32">
                    @foreach($currencies as $currency)
                        <option value="{{ $currency }}" @selected($targetCurrency === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </x-slot:filters>

            <x-slot:chips>
                <x-ui.filter-chips
                    :items="$benchmarkChips"
                    :clear-url="$benchmarkResetUrl"
                    :clear-attrs="$benchmarkLinkAttrs"
                    :server-tabs="true"
                />
            </x-slot:chips>
        </x-ui.filter-bar>

        <x-ui.data-table :title="__($rp.'.historical.title')" :description="__($rp.'.historical.description')" sticky>
            <x-slot:actions>
                <x-ui.status-chip tone="info" size="md">
                    <x-ui.icon name="globe" size="xs" class="tw:inline tw:mr-1" />
                    {{ __($rp.'.historical.company_wide_badge') }}
                </x-ui.status-chip>
            </x-slot:actions>

            <table class="table table-hover align-middle mb-0 tw:text-ui-xs" aria-label="{{ __($rp.'.historical.title') }}">
                <thead>
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.po_number') }}</th>
                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                        <th scope="col">{{ __('purchasing.copy.dimensions') }}</th>
                        <th scope="col">{{ __($rp.'.rules.category_name') }}</th>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.comparison_analytics.average_per_kg', ['currency' => $targetCurrency]) }}</th>
                        <th scope="col">{{ __('purchasing.copy.po_date') }}</th>
                        <th scope="col">{{ __('purchasing.copy.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($historicalItems as $hRow)
                        <tr>
                            <td class="fw-bold tw:text-on-surface ui-tabular-nums">
                                <a href="{{ route('purchasing.purchase-orders.show', \App\Support\HashidCodec::encode((int) $hRow['po_id'])) }}" class="text-primary text-decoration-none tw:hover:underline">
                                    {{ $hRow['po_number'] }}
                                </a>
                            </td>
                            <td>
                                <span class="fw-semibold tw:text-on-surface">{{ $hRow['material_name'] }}</span>
                                @if(!empty($hRow['shape']))
                                    <span class="ui-status-chip ui-status-chip--neutral tw:ms-1">{{ $hRow['shape'] }}</span>
                                @endif
                            </td>
                            <td class="ui-tabular-nums tw:font-medium">{{ $hRow['dimension_display'] ?? '-' }}</td>
                            <td>
                                <span class="ui-status-chip {{ ($hRow['range_classification'] ?? '-') !== '-' ? 'ui-status-chip--info' : 'ui-status-chip--neutral' }}">
                                    {{ $hRow['range_classification'] ?? '-' }}
                                </span>
                            </td>
                            <td>{{ $hRow['supplier'] ?? '-' }}</td>
                            <td class="text-end ui-tabular-nums">
                                {{ $regionalFormatter->groupedDecimal((float) $hRow['price_per_kg'], 4) }}
                                <span class="tw:text-on-surface-variant">{{ $hRow['currency'] }}</span>
                            </td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                @if($hRow['converted_price_per_kg'] !== null)
                                    {{ $targetCurrency }} {{ $regionalFormatter->groupedDecimal((float) $hRow['converted_price_per_kg'], 4) }}
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td class="ui-tabular-nums">
                                {{ !empty($hRow['po_created_at']) ? $regionalFormatter->timestamp(\Carbon\Carbon::parse($hRow['po_created_at'], 'UTC'), 'date') : '-' }}
                            </td>
                            <td>
                                @if(!empty($hRow['po_status']))
                                    <x-status-badge type="po" :status="$hRow['po_status']" />
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 tw:text-on-surface-variant">
                                {{ __($rp.'.historical.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($historicalItems instanceof \Illuminate\Contracts\Pagination\Paginator && $historicalItems->hasPages())
                <x-slot:footer>
                    {{ $historicalItems->links() }}
                </x-slot:footer>
            @endif
        </x-ui.data-table>

    {{-- TAB 3: LEGACY ARCHIVE --}}
    @elseif($activeTab === 'legacy')
        <div class="tw:p-3 tw:rounded-lg tw:bg-surface-variant/40 tw:border tw:border-outline-variant tw:flex tw:items-center tw:gap-3 tw:mb-4">
            <x-ui.icon name="info" size="sm" class="tw:text-primary tw:shrink-0" />
            <p class="tw:text-ui-xs tw:text-on-surface-variant tw:m-0">{{ __($rp.'.legacy.notice') }}</p>
        </div>

        @php
            $legacyStatus = $filters['status'] ?? 'active';
            $legacyResetUrl = route($prefix.'.index', ['tab' => 'legacy']);
            $legacyRemoveUrl = static function (array $dropKeys) use ($prefix): string {
                $query = array_merge(request()->query(), ['tab' => 'legacy']);
                foreach ($dropKeys as $key) {
                    unset($query[$key]);
                }

                return route($prefix.'.index', $query);
            };
            $legacyLinkAttrs = ['data-server-tab' => '', 'data-tab-name' => 'legacy'];
            $legacyStatusLabels = [
                'active' => __($rp.'.status_active'),
                'void' => __($rp.'.status_void'),
                'all' => __($rp.'.status_all'),
            ];

            $legacyChips = [];
            if (! empty($filters['search'])) {
                $legacyChips[] = [
                    'key' => 'search',
                    'label' => __($rp.'.material_code'),
                    'value' => (string) $filters['search'],
                    'removeUrl' => $legacyRemoveUrl(['search']),
                    'removeAttrs' => $legacyLinkAttrs,
                ];
            }
            if (! empty($filters['shape'])) {
                $legacyChips[] = [
                    'key' => 'shape',
                    'label' => __($rp.'.shape'),
                    'value' => (string) $filters['shape'],
                    'removeUrl' => $legacyRemoveUrl(['shape']),
                    'removeAttrs' => $legacyLinkAttrs,
                ];
            }
            if (! empty($filters['currency'])) {
                $legacyChips[] = [
                    'key' => 'currency',
                    'label' => __($rp.'.currency'),
                    'value' => (string) $filters['currency'],
                    'removeUrl' => $legacyRemoveUrl(['currency']),
                    'removeAttrs' => $legacyLinkAttrs,
                ];
            }
            if ($legacyStatus !== 'active') {
                $legacyChips[] = [
                    'key' => 'status',
                    'label' => __($rp.'.status'),
                    'value' => $legacyStatusLabels[$legacyStatus] ?? (string) $legacyStatus,
                    'removeUrl' => $legacyRemoveUrl(['status']),
                    'removeAttrs' => $legacyLinkAttrs,
                ];
            }
        @endphp

        <x-ui.filter-bar
            id="legacyRefFilterForm"
            :action="route($prefix.'.index')"
            :server-tabs="true"
            :aria-label="__($rp.'.legacy.title')"
            :has-active-filters="count($legacyChips) > 0"
            :reset-url="$legacyResetUrl"
            :reset-label="__($rp.'.reset')"
            :reset-attrs="$legacyLinkAttrs"
            :submit-label="__($rp.'.filter')"
            class="tw:mb-4"
        >
            <x-slot:hidden>
                <input type="hidden" name="tab" value="legacy">
            </x-slot:hidden>

            <x-slot:search>
                <label for="referencePriceSearch" class="tw:sr-only">{{ __($rp.'.material_code') }}</label>
                <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                <input
                    type="search"
                    name="search"
                    id="referencePriceSearch"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="{{ __($rp.'.search_placeholder') }}"
                    class="form-control form-control-sm ui-filter-bar__search-input"
                    autocomplete="off"
                >
            </x-slot:search>

            <x-slot:filters>
                <label for="referencePriceShapeFilter" class="tw:sr-only">{{ __($rp.'.shape') }}</label>
                <select name="shape" id="referencePriceShapeFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-40">
                    <option value="">{{ __($rp.'.all_shapes') }}</option>
                    @foreach($shapes as $shape)
                        <option value="{{ $shape }}" @selected(($filters['shape'] ?? '') === $shape)>{{ $shape }}</option>
                    @endforeach
                </select>

                <label for="referencePriceCurrencyFilter" class="tw:sr-only">{{ __($rp.'.currency') }}</label>
                <select name="currency" id="referencePriceCurrencyFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-36">
                    <option value="">{{ __($rp.'.all_currencies') }}</option>
                    @foreach($currencies as $currency)
                        <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>

                <label for="referencePriceStatusFilter" class="tw:sr-only">{{ __($rp.'.status') }}</label>
                <select name="status" id="referencePriceStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-40">
                    <option value="active" @selected($legacyStatus === 'active')>{{ __($rp.'.status_active') }}</option>
                    <option value="void" @selected($legacyStatus === 'void')>{{ __($rp.'.status_void') }}</option>
                    <option value="all" @selected($legacyStatus === 'all')>{{ __($rp.'.status_all') }}</option>
                </select>
            </x-slot:filters>

            <x-slot:chips>
                <x-ui.filter-chips
                    :items="$legacyChips"
                    :clear-url="$legacyResetUrl"
                    :clear-attrs="$legacyLinkAttrs"
                    :server-tabs="true"
                />
            </x-slot:chips>
        </x-ui.filter-bar>

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
                                            data-ui-modal-open="#referencePriceNotesModal"
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
                                                data-ui-modal-open="#referencePriceSupersedeModal"
                                                data-action="{{ route($prefix.'.supersede', $price) }}"
                                                data-material="{{ $price->materialMaster?->material_code }}"
                                            />
                                            <x-ui.icon-button
                                                type="button"
                                                icon="trash-2"
                                                variant="danger-outline"
                                                size="sm"
                                                :label="__($rp.'.rules.action_void', ['material' => $price->materialMaster?->material_code ?? ''])"
                                                data-ui-modal-open="#referencePriceVoidModal"
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

