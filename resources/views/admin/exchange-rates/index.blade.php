@extends('layouts.app')
@section('title', 'Exchange Rate Management - ADASI Portal')
@section('page-title', 'Exchange Rate Management & History')

@push('styles')
    <style>
        .exchange-rate-filter-buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: .4rem;
        }

        .exchange-rate-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .7rem .85rem;
            border: 1px solid #e9eef4;
            border-radius: .5rem;
            background-color: #f8fafc;
        }

        .exchange-rate-pagination .pagination {
            align-items: center;
            justify-content: flex-end;
            gap: .25rem;
            margin-bottom: 0;
        }

        .exchange-rate-pagination .pagination-links nav > div.d-none.flex-sm-fill > div:first-child {
            display: none !important;
        }

        .exchange-rate-pagination .pagination-links nav > div.d-none.flex-sm-fill {
            justify-content: flex-end !important;
        }

        .exchange-rate-pagination .page-link {
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

        .exchange-rate-pagination .page-item:first-child .page-link,
        .exchange-rate-pagination .page-item:last-child .page-link {
            min-width: auto;
            padding-inline: .65rem;
        }

        .exchange-rate-pagination .page-item.active .page-link {
            border-color: #1F5FA6;
            background-color: #1F5FA6;
            color: #fff;
        }

        .exchange-rate-pagination .page-item.disabled .page-link {
            color: #98a2b3;
            background-color: #f1f4f8;
        }

        @media (max-width: 575.98px) {
            .exchange-rate-filter-buttons {
                justify-content: flex-start;
                margin-top: .75rem;
            }

            .exchange-rate-pagination {
                flex-direction: column;
                align-items: stretch;
            }

            .exchange-rate-pagination .pagination {
                justify-content: center;
                flex-wrap: wrap;
            }

            .exchange-rate-pagination .pagination-summary {
                text-align: center;
            }
        }
    </style>
@endpush

@section('content')
    <div class="row g-4">
        {{-- Exchange Rate History Table --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0 fw-bold">Exchange Rate Update History</h6>
                        <div class="small text-muted">Total {{ $totalRates }} exchange rate records saved.</div>
                    </div>
                    <div class="exchange-rate-filter-buttons">
                        <a href="{{ route('admin.exchange-rates.index') }}" class="btn btn-sm btn-outline-secondary {{ !request('currency') ? 'active' : '' }}">All <span class="badge text-bg-light">{{ $totalRates }}</span></a>
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            <a href="{{ route('admin.exchange-rates.index', ['currency' => $currency]) }}" class="btn btn-sm btn-outline-secondary {{ request('currency') == $currency ? 'active' : '' }}">{{ $currency }} <span class="badge text-bg-light">{{ $currencyCounts[$currency] ?? 0 }}</span></a>
                        @endforeach
                    </div>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted">
                            Showing {{ $rates->count() }} of {{ $rates->total() }} records
                            @if(request('currency'))
                                for currency {{ request('currency') }}
                            @endif
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="font-size: 0.9rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Currency</th>
                                    <th>Value to IDR</th>
                                    <th>Valid From</th>
                                    <th>Updated By</th>
                                    <th>Update Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rates as $rate)
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark text-uppercase px-2 py-1">
                                                {{ $rate->currency }}
                                            </span>
                                        </td>
                                        <td class="fw-medium text-end">Rp {{ number_format($rate->rate_to_idr, 2, ',', '.') }}</td>
                                        <td>{{ \Carbon\Carbon::parse($rate->valid_from)->format('d M Y') }}</td>
                                        <td>{{ $rate->creator->name ?? '-' }}</td>
                                        <td class="text-muted small">{{ $rate->created_at->format('d M Y H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">No exchange rate history entered.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    @if($rates->hasPages())
                        <div class="exchange-rate-pagination mt-3">
                            <div class="pagination-summary small text-muted">
                                Page {{ $rates->currentPage() }} of {{ $rates->lastPage() }}
                                <span class="d-none d-sm-inline">- {{ $rates->firstItem() }} to {{ $rates->lastItem() }} of {{ $rates->total() }} records</span>
                            </div>
                            <div class="pagination-links">
                                {{ $rates->onEachSide(1)->links('pagination::bootstrap-5') }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- New Exchange Rate Form --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-plus-circle me-1"></i> Input New Exchange Rate</h6>
                </div>
                <div class="card-body bg-light">
                    <p class="small text-muted mb-3">
                        <i class="bi bi-info-circle"></i> Entering a new exchange rate <strong>will not delete</strong> the old rate. It adds a new history record that will be used from the valid date.
                    </p>
                    <form action="{{ route('admin.exchange-rates.store') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Currency <span class="text-danger">*</span></label>
                            <select name="currency" class="form-select @error('currency') is-invalid @enderror" required>
                                @foreach(\App\Models\ExchangeRate::CURRENCY_LABELS as $code => $label)
                                    <option value="{{ $code }}" {{ old('currency') === $code ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Value to Rupiah (IDR) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" step="0.01" name="rate_to_idr" class="form-control @error('rate_to_idr') is-invalid @enderror" required placeholder="Example: 15500">
                            </div>
                            @error('rate_to_idr')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-medium text-muted">Valid From <span class="text-danger">*</span></label>
                            <input type="date" name="valid_from" class="form-control @error('valid_from') is-invalid @enderror" value="{{ date('Y-m-d') }}" required>
                            @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-medium">
                            <i class="bi bi-save me-1"></i> Save Exchange Rate History
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
