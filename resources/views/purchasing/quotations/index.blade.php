@extends('layouts.app')
@section('title', __('purchasing.copy.quotation_list_adasi_portal'))
@section('page-title', __('purchasing.copy.supplier_quotations'))

@push('styles')
    <style>
        .quotation-pagination .pagination {
            align-items: center;
            justify-content: flex-end;
            gap: .25rem;
            margin-bottom: 0;
        }

        .quotation-pagination .page-link {
            min-width: 2rem;
            padding: .25rem .5rem;
            border-radius: var(--md-shape-xs);
            color: var(--md-primary);
            font-size: var(--ui-font-size-xs);
            font-weight: 600;
            line-height: 1.2;
            text-align: center;
            box-shadow: none;
        }

        .quotation-pagination .page-item:first-child .page-link,
        .quotation-pagination .page-item:last-child .page-link {
            min-width: auto;
            padding-inline: .5rem;
        }

        .quotation-pagination .page-item.active .page-link {
            border-color: var(--md-primary);
            background-color: var(--md-primary);
            color: var(--md-on-primary);
        }

        .quotation-pagination .page-item.disabled .page-link {
            color: var(--md-on-surface-variant);
            background-color: var(--md-surface-container-low);
        }

        @media (max-width: 575.98px) {
            .quotation-pagination .pagination {
                justify-content: center;
                flex-wrap: wrap;
            }
        }
    </style>
@endpush

@section('content')
    <div class="tw-grid tw-gap-4">
        {{-- 1. Compact Page Header --}}
        <x-ui.page-header
            :title="__('purchasing.copy.supplier_quotations')"
            :eyebrow="__('purchasing.copy.purchasing')"
            :description="__('purchasing.copy.review_submitted_supplier_offers_validity_currency_and_workflow_status_across_requisitions')"
        >
            <x-slot:actions>
                <x-ui.button
                    :href="route('purchasing.export.quotations', request()->only(['pr_number', 'date_from', 'date_to', 'supplier_id', 'status', 'currency']))"
                    variant="outline"
                    size="sm"
                    data-async-export
                    id="exportQuotationsBtn"
                    :data-export-url="route('purchasing.export.quotations')"
                    data-export-source-singular="{{ __('exports.sources.quotation') }}"
                    data-export-source-plural="{{ __('exports.sources.quotations') }}"
                    :data-export-source-count="$quotations->total()"
                    data-export-row-label="{{ __('purchasing.copy.quotation_item_rows') }}"
                    data-export-row-explanation="{{ __('purchasing.copy.each_quotation_item_will_be_written_as_a_separate_excel_row') }}"
                >
                    <x-ui.icon name="file-spreadsheet" />
                    <span>{{ __('purchasing.copy.export_excel') }}</span>
                </x-ui.button>
                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums" id="quotationCountBadge">
                    {{ trans_choice('purchasing.summary.quotations', $quotations->total(), ['count' => $quotations->total()]) }}
                </span>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- 2. Operational Toolbar --}}
        <x-ui.toolbar :sticky="true">
            <x-slot:filters>
                <form method="GET" action="{{ route('purchasing.quotations.index') }}"
                    class="quotation-filter d-flex flex-wrap align-items-center gap-2 w-100" id="quotationFilterForm" data-managed-submit>

                    <div style="min-width: 170px; max-width: 210px;" class="flex-grow-1">
                        <input
                            type="text"
                            name="pr_number"
                            class="form-control form-control-sm"
                            value="{{ request('pr_number') }}"
                            placeholder="{{ __('purchasing.copy.pr_number_req') }}"
                            aria-label="{{ __('purchasing.copy.filter_by_pr_number') }}"
                        />
                    </div>

                    <div style="min-width: 250px;">
                        <x-ui.date-range-picker
                            id="quotationDateRangeControl"
                            granularity="month"
                            start-name="date_from"
                            start-id="quotationDateFrom"
                            :start-label="__('purchasing.copy.from')"
                            :start-value="request('date_from')"
                            end-name="date_to"
                            end-id="quotationDateTo"
                            :end-label="__('purchasing.copy.to')"
                            :end-value="request('date_to')"
                            error-id="quotationDateError"
                            compact
                        />
                    </div>

                    <x-ui.button type="submit" variant="primary" size="sm" data-calendar-native-submit>
                        <x-ui.icon name="filter" />
                        <span>{{ __('purchasing.copy.apply_filters_926161') }}</span>
                    </x-ui.button>

                    <div style="min-width: 160px;">
                        <select name="supplier_id" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_supplier') }}">
                            <option value="">{{ __('purchasing.copy.all_suppliers_3a7a53') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->getRouteKey() }}" {{ request('supplier_id') === $supplier->getRouteKey() ? 'selected' : '' }}>
                                    {{ $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div style="min-width: 130px;">
                        <select name="status" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_status') }}">
                            <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                            <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>{{ __('purchasing.copy.submitted') }}</option>
                            <option value="all_unavailable" {{ request('status') == 'all_unavailable' ? 'selected' : '' }}>{{ __('purchasing.copy.all_unavailable') }}</option>
                            <option value="revision_requested" {{ request('status') == 'revision_requested' ? 'selected' : '' }}>{{ __('purchasing.copy.needs_revision') }}</option>
                            <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>{{ __('purchasing.copy.accepted') }}</option>
                            <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>{{ __('purchasing.copy.rejected') }}</option>
                        </select>
                    </div>

                    <div style="min-width: 90px;">
                        <select name="currency" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_currency') }}">
                            <option value="">{{ __('purchasing.copy.all_curr') }}</option>
                            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                                <option value="{{ $currency }}" {{ request('currency') == $currency ? 'selected' : '' }}>{{ \App\Models\ExchangeRate::currencyLabel($currency) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-ui.button :href="route('purchasing.quotations.index')" variant="ghost" size="sm">
                        <x-ui.icon name="rotate-ccw" />
                        <span>{{ __('purchasing.copy.reset') }}</span>
                    </x-ui.button>
                </form>
            </x-slot:filters>
        </x-ui.toolbar>

        {{-- 3. Balanced Data Table --}}
        <x-ui.data-table density="compact">
            <div class="table-responsive" id="quotationTableContainer">
                <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 40px;" class="text-center">{{ __('purchasing.copy.no') }}</th>
                            <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                            <th scope="col">{{ __('purchasing.copy.pr_no') }}</th>
                            <th scope="col">{{ __('purchasing.copy.period') }}</th>
                            <th scope="col" class="text-center">{{ __('purchasing.copy.currency') }}</th>
                            <th scope="col" class="text-center">{{ __('purchasing.copy.items') }}</th>
                            <th scope="col" class="text-center">{{ __('purchasing.copy.status') }}</th>
                            <th scope="col">{{ __('purchasing.copy.date_submitted') }}</th>
                            <th scope="col">
                                {{ __('purchasing.copy.valid_until') }}
                                <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="{{ __('purchasing.copy.expired_quotations_cannot_be_used_to_create_a_po_until_the_supplier_submits_a_revision') }}" />
                            </th>
                            <th scope="col" class="text-end" style="width: 80px;">{{ __('purchasing.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($quotations as $i => $q)
                            <tr>
                                <td class="text-center tw-text-on-surface-variant">{{ $quotations->firstItem() + $i }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-ui.icon name="building" size="sm" class="text-primary flex-shrink-0" />
                                        <span class="fw-bold tw-text-on-surface">{{ $q->supplier->name }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary">{{ $q->purchaseRequisition->pr_number ?? '-' }}</span>
                                </td>
                                <td>{{ $q->purchaseRequisition->period->display_label ?? $q->purchaseRequisition->period->name ?? '-' }}</td>
                                <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ $q->currency }}</span></td>
                                <td class="text-center fw-medium">{{ $regionalFormatter->number((string) $q->items->count(), 'plain') }}</td>
                                <td class="text-center">
                                    <x-status-badge type="quotation" :status="$q->status" />
                                </td>
                                <td class="tw-text-on-surface-variant">{{ $q->submitted_at ? $regionalFormatter->timestamp($q->submitted_at, 'datetime_comma') : '-' }}</td>
                                <td>
                                    @php
                                        $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($q->validity_period, $q->status);
                                    @endphp
                                    @if($q->validity_period)
                                        <div class="fw-semibold tw-text-on-surface">{{ $regionalFormatter->date($q->validity_period, 'human') }}</div>
                                        {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                                    @else
                                        {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a
                                        href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.quotations.show', $q) }}"
                                        class="ui-data-action ui-data-action--primary ui-focus-ring"
                                        aria-label="{{ __('purchasing.copy.view_quotation') }}"
                                    >
                                        <x-ui.icon name="eye" size="sm" />
                                        <span class="d-none d-md-inline ms-1">{{ __('purchasing.copy.view') }}</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <x-ui.empty-state icon="inbox" :title="__('purchasing.copy.no_quotations_received')" :description="__('purchasing.copy.submitted_supplier_quotations_will_appear_here')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-slot:pagination>
                <div class="quotation-pagination tw-w-full" id="quotationPaginationContainer">
                    {{ $quotations->onEachSide(1)->links('pagination::bootstrap-5') }}
                </div>
            </x-slot:pagination>
        </x-ui.data-table>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filterForm = document.getElementById('quotationFilterForm');
            const dateFrom = document.getElementById('quotationDateFrom');
            const dateTo = document.getElementById('quotationDateTo');
            const dateRangeControl = document.getElementById('quotationDateRangeControl');
            const dateError = document.getElementById('quotationDateError');
            const exportButton = document.getElementById('exportQuotationsBtn');
            const textFilters = filterForm.querySelectorAll('input[type="text"]');
            const instantFilters = filterForm.querySelectorAll('select');
            let filterTimer;
            let filterRequest;

            const toggleDateError = (show) => {
                dateRangeControl.classList.toggle('is-invalid', show);
                dateRangeControl.dataset.calendarInvalid = String(show);
                dateError.querySelector('[data-calendar-error-message]').textContent = @json(__('purchasing.copy.end_month_cannot_be_before_start_month'));
                dateError.classList.toggle('tw-hidden', !show);
            };

            const hasInvalidDateRange = () => dateFrom.value && dateTo.value && dateTo.value < dateFrom.value;

            const buildFilterUrl = () => {
                const url = new URL(filterForm.action, window.location.origin);
                const formData = new FormData(filterForm);

                formData.forEach((value, key) => {
                    const normalized = String(value).trim();
                    if (normalized !== '') {
                        url.searchParams.set(key, normalized);
                    }
                });

                return url;
            };

            const updateExportUrl = (filterUrl) => {
                const exportUrl = new URL(exportButton.dataset.exportUrl, window.location.origin);

                ['pr_number', 'date_from', 'date_to', 'supplier_id', 'status', 'currency'].forEach((key) => {
                    const value = filterUrl.searchParams.get(key);
                    if (value) exportUrl.searchParams.set(key, value);
                });

                exportButton.href = exportUrl.toString();
            };

            const captureTextCursor = () => {
                const element = document.activeElement;
                if (!element || !filterForm.contains(element) || element.tagName !== 'INPUT' || element.type !== 'text') {
                    return null;
                }

                return {
                    name: element.name,
                    value: element.value,
                    start: element.selectionStart,
                    end: element.selectionEnd,
                };
            };

            const restoreTextCursor = (cursor) => {
                if (!cursor) return;

                const input = Array.from(filterForm.querySelectorAll('input[type="text"]'))
                    .find((element) => element.name === cursor.name);

                if (!input || input.value !== cursor.value) return;

                input.focus({ preventScroll: true });
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(cursor.start, cursor.end);
                }
            };

            const replaceFromResponse = (documentFragment) => {
                ['quotationCountBadge', 'quotationTableContainer', 'quotationPaginationContainer'].forEach((id) => {
                    const current = document.getElementById(id);
                    const incoming = documentFragment.getElementById(id);
                    if (current && incoming) {
                        current.replaceWith(incoming);
                    }
                });
                window.initAdasiTooltips?.(document.getElementById('quotationTableContainer'));
            };

            const submitFilters = async (targetUrl = null, preserveCursor = true) => {
                if (hasInvalidDateRange()) {
                    toggleDateError(true);
                    return;
                }

                toggleDateError(false);
                const url = targetUrl || buildFilterUrl();
                updateExportUrl(url);
                const cursor = preserveCursor ? captureTextCursor() : null;

                if (filterRequest) {
                    filterRequest.abort();
                }

                const currentRequest = new AbortController();
                filterRequest = currentRequest;
                filterForm.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: currentRequest.signal,
                    });

                    if (!response.ok) {
                        throw new Error(@json(__('purchasing.copy.failed_to_load_quotation_data')));
                    }

                    const html = await response.text();
                    const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                    replaceFromResponse(nextDocument);
                    window.history.replaceState({}, '', url);
                    restoreTextCursor(cursor);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        window.location.href = url.toString();
                    }
                } finally {
                    if (filterRequest === currentRequest) {
                        filterForm.removeAttribute('aria-busy');
                    }
                }
            };

            textFilters.forEach((input) => {
                input.addEventListener('input', () => {
                    clearTimeout(filterTimer);
                    filterTimer = setTimeout(() => submitFilters(), 500);
                });
            });

            instantFilters.forEach((input) => {
                input.addEventListener('change', () => {
                    clearTimeout(filterTimer);
                    submitFilters();
                });
            });

            dateRangeControl.addEventListener('adasi:date-range-commit', () => {
                clearTimeout(filterTimer);
                submitFilters();
            });

            [dateFrom, dateTo].forEach((input) => {
                input.addEventListener('change', () => {
                    if (dateRangeControl.dataset.calendarEnhanced === 'true') return;
                    clearTimeout(filterTimer);
                    submitFilters();
                });
            });

            filterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                clearTimeout(filterTimer);

                if (hasInvalidDateRange()) {
                    toggleDateError(true);
                    return;
                }

                toggleDateError(false);
                submitFilters();
            });

            document.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : event.target.parentElement;
                const link = target?.closest('#quotationPaginationContainer a.page-link');
                if (!link || link.closest('.disabled')) return;

                event.preventDefault();
                submitFilters(new URL(link.href), false);
            });
        });
    </script>
@endpush
