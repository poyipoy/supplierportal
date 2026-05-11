@extends('layouts.app')
@section('title', 'Manajemen Kurs — ADASI Portal')
@section('page-title', 'Manajemen Kurs & Riwayat')

@section('content')
    <div class="row g-4">
        {{-- Tabel Histori Kurs --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Riwayat Pembaruan Kurs</h6>
                    <div>
                        <a href="{{ route('admin.exchange-rates.index') }}" class="btn btn-sm btn-outline-secondary {{ !request('currency') ? 'active' : '' }}">Semua</a>
                        <a href="{{ route('admin.exchange-rates.index', ['currency' => 'USD']) }}" class="btn btn-sm btn-outline-secondary {{ request('currency') == 'USD' ? 'active' : '' }}">USD</a>
                        <a href="{{ route('admin.exchange-rates.index', ['currency' => 'JPY']) }}" class="btn btn-sm btn-outline-secondary {{ request('currency') == 'JPY' ? 'active' : '' }}">JPY</a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="font-size: 0.9rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Mata Uang</th>
                                    <th>Nilai ke IDR</th>
                                    <th>Berlaku Sejak</th>
                                    <th>Diperbarui Oleh</th>
                                    <th>Waktu Update</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rates as $rate)
                                    <tr>
                                        <td>
                                            <span class="badge {{ $rate->currency == 'USD' ? 'bg-success' : 'bg-primary' }} text-uppercase px-2 py-1">
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
                                        <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat kurs yang dimasukkan.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        {{ $rates->links() }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Form Tambah Kurs Baru --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-plus-circle me-1"></i> Input Kurs Baru</h6>
                </div>
                <div class="card-body bg-light">
                    <p class="small text-muted mb-3">
                        <i class="bi bi-info-circle"></i> Memasukkan kurs baru <strong>tidak akan menghapus</strong> kurs lama, melainkan menambah histori baru yang akan digunakan mulai tanggal berlaku.
                    </p>
                    <form action="{{ route('admin.exchange-rates.store') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Mata Uang <span class="text-danger">*</span></label>
                            <select name="currency" class="form-select @error('currency') is-invalid @enderror" required>
                                <option value="USD">USD - US Dollar</option>
                                <option value="JPY">JPY - Japanese Yen</option>
                            </select>
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Nilai ke Rupiah (IDR) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" step="0.01" name="rate_to_idr" class="form-control @error('rate_to_idr') is-invalid @enderror" required placeholder="Misal: 15500">
                            </div>
                            @error('rate_to_idr')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-medium text-muted">Berlaku Sejak <span class="text-danger">*</span></label>
                            <input type="date" name="valid_from" class="form-control @error('valid_from') is-invalid @enderror" value="{{ date('Y-m-d') }}" required>
                            @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-medium">
                            <i class="bi bi-save me-1"></i> Simpan Histori Kurs
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
