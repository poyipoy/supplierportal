@extends('layouts.app')
@section('title', 'Admin Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Admin')

@section('content')
    <div class="row g-4 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-medium mb-1">ACTIVE USERS</div>
                            <h3 class="fw-bold mb-0">{{ $totalUsersActive }}</h3>
                            <div class="mt-1">@foreach($usersByRole as $role => $count)<span
                                class="badge bg-light text-dark me-1" style="font-size:.6rem">{{ ucfirst($role) }}:
                            {{ $count }}</span>@endforeach</div>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i
                                class="bi bi-people text-primary fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-medium mb-1">TRANSACTIONS THIS MONTH</div>
                            <h3 class="fw-bold mb-0 text-success">{{ $transaksiBulanIni }}</h3>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-circle p-3"><i
                                class="bi bi-graph-up text-success fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-medium mb-1">REGISTERED SUPPLIERS</div>
                            <h3 class="fw-bold mb-0 text-info">{{ $supplierCount }}</h3>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded-circle p-3"><i class="bi bi-building text-info fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-medium mb-1">ACTIVE CLAIMS</div>
                            <h3 class="fw-bold mb-0 text-danger">{{ $klaimAktif }}</h3>
                        </div>
                        <div class="bg-danger bg-opacity-10 rounded-circle p-3"><i
                                class="bi bi-shield-exclamation text-danger fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-activity text-primary me-1"></i> Latest Activities (System)
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($recentActivities as $act)
                            <div class="list-group-item py-3">
                                <div class="d-flex gap-3 align-items-start">
                                    <div class="bg-primary bg-opacity-10 p-2 rounded-circle flex-shrink-0"><i
                                            class="bi {{ $act->data['icon'] ?? 'bi-bell' }} text-primary"></i></div>
                                    <div style="flex:1">
                                        <div class="fw-bold small">{{ $act->data['title'] ?? 'Notifications' }}</div>
                                        <div class="text-muted" style="font-size:.75rem">{{ $act->data['message'] ?? '-' }}
                                        </div>
                                        <div class="text-muted mt-1" style="font-size:.65rem">
                                            {{ $act->created_at->diffForHumans() }}</div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-center text-muted small">No aktivitas tercatat.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-currency-exchange me-1"></i> Exchange Rate Management</h6>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kursModal"><i
                            class="bi bi-plus-lg"></i> Update Exchange Rate</button>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            @php
                                $rate = $latestRates[$currency] ?? null;
                            @endphp
                            <div class="col-6">
                                <div class="p-3 bg-light rounded text-center h-100">
                                    <div class="text-muted small mb-1">{{ $currency }} → IDR</div>
                                    <h5 class="fw-bold mb-0">Rp
                                        {{ $rate ? number_format($rate->rate_to_idr, 0, ',', '.') : '-' }}</h5>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold small mb-0">Latest Exchange Rate History</h6>
                        <a href="{{ route('admin.exchange-rates.index') }}" class="small text-decoration-none">View all
                            {{ $riwayatKursTotal }}</a>
                    </div>
                    <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem">
                            <thead class="table-light">
                                <tr>
                                    <th>Currency</th>
                                    <th>Rate</th>
                                    <th>Valid From</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($riwayatKurs as $kurs)
                                    <tr>
                                        <td><span class="badge bg-secondary">{{ $kurs->currency }}</span></td>
                                        <td class="fw-medium">Rp {{ number_format($kurs->rate_to_idr, 0, ',', '.') }}</td>
                                        <td>{{ $kurs->valid_from->format('d M Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Fast Menu</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="{{ route('admin.announcements.index') }}"
                            class="btn btn-outline-primary btn-sm text-start"><i
                                class="bi bi-megaphone me-2"></i>Announcement Management</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="kursModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form action="{{ route('admin.kurs.update') }}" method="POST">@csrf
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Update Exchange Rate</h6><button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Currency</label>
                            <select name="currency" class="form-select form-select-sm" required>
                                @foreach(\App\Models\ExchangeRate::CURRENCY_LABELS as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label small fw-bold">Rate to IDR</label><input type="number"
                                step="0.01" name="rate_to_idr" class="form-control form-control-sm" required
                                placeholder="16500"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">Save</button></div>
                </form>
            </div>
        </div>
    </div>
@endsection