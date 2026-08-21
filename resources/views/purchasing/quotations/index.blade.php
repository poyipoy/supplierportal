@extends('layouts.app')
@section('title', 'Quotation List - ADASI Portal')
@section('page-title', 'Supplier Quotations')

@push('styles')
    <style>
        .quotation-filter .date-range-control {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            gap: .5rem;
            min-height: 31px;
            padding: .25rem .5rem;
            border: 1px solid var(--md-outline);
            border-radius: .375rem;
            background-color: var(--md-surface);
        }

        .quotation-filter .date-range-control.is-invalid {
            border-color: var(--md-error);
        }

        .quotation-filter .date-range-segment {
            display: flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
        }

        .quotation-filter .date-range-label {
            flex: 0 0 auto;
            font-size: .75rem;
            color: var(--md-on-surface-variant);
            white-space: nowrap;
        }

        .quotation-filter .date-range-control input[type="month"] {
            min-width: 0;
            height: 26px;
            padding: 0;
            border: 0;
            font-size: .875rem;
            background-color: transparent;
            box-shadow: none;
        }

        .quotation-filter .date-range-divider {
            color: var(--md-outline);
            font-size: .875rem;
            line-height: 1;
        }

        .quotation-pagination .pagination {
            align-items: center;
            justify-content: flex-end;
            gap: .25rem;
            margin-bottom: 0;
        }

        .quotation-pagination .page-link {
            min-width: 2rem;
            padding: .3rem .55rem;
            border-radius: .375rem;
            color: var(--md-primary);
            font-size: .78rem;
            font-weight: 600;
            line-height: 1.2;
            text-align: center;
            box-shadow: none;
        }

        .quotation-pagination .page-item:first-child .page-link,
        .quotation-pagination .page-item:last-child .page-link {
            min-width: auto;
            padding-inline: .65rem;
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
            .quotation-filter .date-range-control {
                grid-template-columns: minmax(0, 1fr);
                gap: .35rem;
                padding: .45rem .6rem;
            }

            .quotation-filter .date-range-segment {
                justify-content: space-between;
            }

            .quotation-filter .date-range-control input[type="month"] {
                max-width: 11rem;
                text-align: right;
            }

            .quotation-filter .date-range-divider {
                display: none;
            }

            .quotation-pagination .pagination {
                justify-content: center;
                flex-wrap: wrap;
            }
        }
    </style>
@endpush

@section('content')
    <div class="tw-grid tw-gap-6">
        <x-ui.page-header
            title="Supplier quotations"
            eyebrow="Purchasing"
            description="Review submitted supplier offers, validity, currency, and workflow status across requisitions."
        />

        <x-ui.data-table title="Quotation list" description="Filters update this list without a full-page refresh.">
            <x-slot:toolbar>
                <x-ui.button
                    :href="route('purchasing.export.quotations', request()->only(['pr_number', 'date_from', 'date_to', 'supplier_id', 'status', 'currency']))"
                    variant="secondary"
                    size="sm"
                    data-async-export
                    id="exportQuotationsBtn"
                    :data-export-url="route('purchasing.export.quotations')"
                >
                    <x-ui.icon name="file-spreadsheet" />
                    Export Excel
                </x-ui.button>
                <x-ui.status-chip tone="info" id="quotationCountBadge">{{ $quotations->total() }} quotations</x-ui.status-chip>
            </x-slot:toolbar>

            <x-slot:filters>
            {{-- Filter --}}
            @error('date_to')
                <x-ui.alert tone="error" class="tw-basis-full">{{ $message }}</x-ui.alert>
            @enderror

            <form method="GET" action="{{ route('purchasing.quotations.index') }}"
                class="quotation-filter tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-12 xl:tw-items-end" id="quotationFilterForm">
                <x-ui.input
                    name="pr_number"
                    label="PR number"
                    :value="request('pr_number')"
                    placeholder="REQ/MM/YYYY/XXX"
                    class="xl:tw-col-span-2"
                />
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2 xl:tw-col-span-4">
                    <label class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Date range</label>
                    <div class="date-range-control" id="quotationDateRangeControl">
                        <div class="date-range-segment">
                            <span class="date-range-label">From</span>
                            <input type="month" name="date_from" id="quotationDateFrom" value="{{ request('date_from') }}"
                                placeholder="MM/YYYY" aria-label="Start date">
                        </div>
                        <span class="date-range-divider">-</span>
                        <div class="date-range-segment">
                            <span class="date-range-label">To</span>
                            <input type="month" name="date_to" id="quotationDateTo" value="{{ request('date_to') }}"
                                placeholder="MM/YYYY" aria-label="End date" aria-describedby="quotationDateError">
                        </div>
                    </div>
                    <div class="d-none tw-text-ui-xs tw-font-medium tw-text-error" id="quotationDateError" aria-live="polite">End date cannot be before start date.</div>
                </div>
                <x-ui.select name="supplier_id" label="Supplier" :value="request('supplier_id')" class="xl:tw-col-span-2">
                        <option value="">All Supplier</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}" {{ request('supplier_id') === $supplier->getRouteKey() ? 'selected' : '' }}>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                </x-ui.select>
                <x-ui.select name="status" label="Status" :value="request('status')" class="xl:tw-col-span-1">
                        <option value="">All Status</option>
                        <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>Submitted</option>
                        <option value="revision_requested" {{ request('status') == 'revision_requested' ? 'selected' : '' }}>
                            Needs Revision</option>
                        <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                </x-ui.select>
                <x-ui.select name="currency" label="Currency" :value="request('currency')" class="xl:tw-col-span-1">
                        <option value="">All</option>
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            <option value="{{ $currency }}" {{ request('currency') == $currency ? 'selected' : '' }}>{{ $currency }}</option>
                        @endforeach
                </x-ui.select>
                <x-ui.button :href="route('purchasing.quotations.index')" variant="ghost" size="sm" class="tw-w-full xl:tw-col-span-2">
                    <x-ui.icon name="rotate-ccw" />
                    Reset filters
                </x-ui.button>
            </form>
            </x-slot:filters>

            {{-- Tabel --}}
            <div class="table-responsive" id="quotationTableContainer">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Supplier</th>
                            <th>PR No.</th>
                            <th>Period</th>
                            <th class="text-center">Currency</th>
                            <th class="text-center">Amount Item</th>
                            <th class="text-center">Status</th>
                            <th>Date Submitted</th>
                            <th>
                                Valid Until
                                <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Expired quotations cannot be used to create a PO until the supplier submits a revision." />
                            </th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($quotations as $i => $q)
                            <tr>
                                <td>{{ $quotations->firstItem() + $i }}</td>
                                <td class="fw-medium">
                                    <div class="d-flex align-items-center gap-2">
                                        <x-ui.icon name="building" size="lg" class="text-primary tw-shrink-0" />
                                        {{ $q->supplier->name }}
                                    </div>
                                </td>
                                <td><span class="fw-bold text-primary">{{ $q->purchaseRequisition->pr_number ?? '-' }}</span>
                                </td>
                                <td>{{ $q->purchaseRequisition->period->display_label ?? $q->purchaseRequisition->period->name ?? '-' }}</td>
                                <td class="text-center"><span class="badge bg-dark">{{ $q->currency }}</span></td>
                                <td class="text-center">{{ $q->items->count() }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $q->statusBadgeClass() }} text-uppercase">{{ $q->statusLabel() }}</span>
                                </td>
                                <td>{{ $q->submitted_at ? $q->submitted_at->format('d M Y, H:i') : '-' }}</td>
                                <td>
                                    @php
                                        $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($q->validity_period);
                                    @endphp
                                    @if($q->validity_period)
                                        <div class="fw-medium">{{ $q->validity_period->format('d M Y') }}</div>
                                        {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                                    @else
                                        {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.quotations.show', $q)" variant="ghost" size="sm">
                                        <x-ui.icon name="eye" />
                                        Detail
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10"><x-ui.empty-state icon="inbox" title="No quotations received" description="Submitted supplier quotations will appear here." /></td>
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
            const instantFilters = filterForm.querySelectorAll('select, input[type="month"]');
            let filterTimer;
            let filterRequest;

            const toggleDateError = (show) => {
                dateRangeControl.classList.toggle('is-invalid', show);
                dateTo.classList.toggle('is-invalid', show);
                dateError.classList.toggle('d-none', !show);
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
                        throw new Error('Failed to load quotation data.');
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
