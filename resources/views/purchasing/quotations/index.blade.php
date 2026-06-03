@extends('layouts.app')
@section('title', 'Daftar Penawaran — ADASI Portal')
@section('page-title', 'Penawaran Supplier')

@push('styles')
    <style>
        .quotation-filter .date-range-control {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            gap: .5rem;
            min-height: 31px;
            padding: .25rem .5rem;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
            background-color: #fff;
        }

        .quotation-filter .date-range-control.is-invalid {
            border-color: #dc3545;
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
            color: #6c757d;
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
            color: #adb5bd;
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
            color: #1F5FA6;
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
            border-color: #1F5FA6;
            background-color: #1F5FA6;
            color: #fff;
        }

        .quotation-pagination .page-item.disabled .page-link {
            color: #98a2b3;
            background-color: #f8f9fa;
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
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-semibold">Daftar Penawaran Masuk</h5>
            <span class="badge bg-primary" id="quotationCountBadge">{{ $quotations->total() }} Penawaran</span>
        </div>
        <div class="card-body">
            {{-- Filter --}}
            @error('date_to')
                <div class="alert alert-danger small">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $message }}
                </div>
            @enderror

            <form method="GET" action="{{ route('purchasing.quotations.index') }}"
                class="quotation-filter row g-3 mb-4 align-items-end" id="quotationFilterForm">
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small fw-medium">No. PR</label>
                    <input type="text" name="pr_number" class="form-control form-control-sm"
                        value="{{ request('pr_number') }}" placeholder="REQ/MM/YYYY/XXX">
                </div>
                <div class="col-lg-4 col-md-12">
                    <label class="form-label small fw-medium">Rentang Tanggal</label>
                    <div class="date-range-control" id="quotationDateRangeControl">
                        <div class="date-range-segment">
                            <span class="date-range-label">Dari</span>
                            <input type="month" name="date_from" id="quotationDateFrom" value="{{ request('date_from') }}"
                                placeholder="MM/YYYY" aria-label="Dari tanggal">
                        </div>
                        <span class="date-range-divider">-</span>
                        <div class="date-range-segment">
                            <span class="date-range-label">Sampai</span>
                            <input type="month" name="date_to" id="quotationDateTo" value="{{ request('date_to') }}"
                                placeholder="MM/YYYY" aria-label="Sampai tanggal" aria-describedby="quotationDateError">
                        </div>
                    </div>
                    <div class="form-text text-danger d-none" id="quotationDateError" aria-live="polite">Tanggal akhir tidak
                        boleh sebelum tanggal awal</div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small fw-medium">Supplier</label>
                    <select name="supplier_id" class="form-select form-select-sm">
                        <option value="">Semua Supplier</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" {{ request('supplier_id') == $supplier->id ? 'selected' : '' }}>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-1 col-md-6">
                    <label class="form-label small fw-medium">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>Submitted</option>
                        <option value="revision_requested" {{ request('status') == 'revision_requested' ? 'selected' : '' }}>
                            Perlu Revisi</option>
                        <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                    </select>
                </div>
                <div class="col-lg-1 col-md-6">
                    <label class="form-label small fw-medium">Mata Uang</label>
                    <select name="currency" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            <option value="{{ $currency }}" {{ request('currency') == $currency ? 'selected' : '' }}>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <a href="{{ route('purchasing.quotations.index') }}" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </a>
                </div>
            </form>

            {{-- Tabel --}}
            <div class="table-responsive" id="quotationTableContainer">
                <table class="table table-hover align-middle" style="font-size:.85rem">
                    <thead class="table-light">
                        <tr>
                            <th width="4%">No</th>
                            <th>Supplier</th>
                            <th>No. PR</th>
                            <th>Periode</th>
                            <th class="text-center">Mata Uang</th>
                            <th class="text-center">Jumlah Item</th>
                            <th class="text-center">Status</th>
                            <th>Tanggal Diajukan</th>
                            <th>
                                Masa Berlaku
                                <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Penawaran kadaluarsa tidak bisa dibuat PO sebelum supplier mengirim revisi."></i>
                            </th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($quotations as $i => $q)
                            <tr>
                                <td>{{ $quotations->firstItem() + $i }}</td>
                                <td class="fw-medium">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center"
                                            style="width:32px;height:32px;">
                                            <i class="bi bi-building text-primary small"></i>
                                        </div>
                                        {{ $q->supplier->name }}
                                    </div>
                                </td>
                                <td><span class="fw-bold text-primary">{{ $q->purchaseRequirement->pr_number ?? '-' }}</span>
                                </td>
                                <td>{{ $q->purchaseRequirement->period->name ?? '-' }}</td>
                                <td class="text-center"><span class="badge bg-dark">{{ $q->currency }}</span></td>
                                <td class="text-center">{{ $q->items->count() }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $q->statusBadgeClass() }} text-uppercase"
                                        style="font-size:.65rem">{{ $q->statusLabel() }}</span>
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
                                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.quotations.show', $q->id) }}"
                                        class="btn btn-sm btn-outline-info py-0 px-2">
                                        <i class="bi bi-eye me-1"></i>Detail
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4"><i class="bi bi-inbox"
                                        style="font-size:2rem"></i>
                                    <p class="mt-2 mb-0">Belum ada penawaran masuk.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="quotation-pagination mt-3" id="quotationPaginationContainer">
                {{ $quotations->onEachSide(1)->links('pagination::bootstrap-5') }}
            </div>
        </div>
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
                        throw new Error('Gagal memuat data penawaran.');
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
