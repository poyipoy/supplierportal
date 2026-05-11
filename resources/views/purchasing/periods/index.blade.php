@extends('layouts.app')
@section('title', 'Manajemen Periode — ADASI Portal')
@section('page-title', __('Manajemen Periode'))

@section('content')
    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">{{ __('Daftar Periode Penawaran') }}</h6>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="bi bi-plus-lg"></i> {{ __('Tambah Periode') }}
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle datatable" style="font-size: 0.9rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Nama Periode') }}</th>
                                    <th>{{ __('Bulan') }}</th>
                                    <th>{{ __('Tahun') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Dibuat Oleh') }}</th>
                                    <th class="text-end">{{ __('Aksi') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($periods as $period)
                                    <tr>
                                        <td class="fw-medium">{{ $period->name }}</td>
                                        <td>{{ date('F', mktime(0, 0, 0, $period->month, 1)) }} ({{ $period->month }})</td>
                                        <td>{{ $period->year }}</td>
                                        <td>
                                            @if($period->status === 'open')
                                                <span class="badge bg-success text-uppercase">{{ __('Open') }}</span>
                                            @else
                                                <span class="badge bg-secondary text-uppercase">{{ __('Closed') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $period->creator->name ?? '-' }}</td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $period->id }}">
                                                <i class="bi bi-pencil"></i> {{ __('Edit') }}
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Edit Modal -->
                                    <div class="modal fade" id="editModal{{ $period->id }}" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form action="{{ route('purchasing.periods.update', $period->id) }}" method="POST">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="modal-header">
                                                        <h5 class="modal-title fw-bold">{{ __('Edit Periode') }}</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">{{ __('Nama Periode') }}</label>
                                                            <input type="text" name="name" class="form-control" value="{{ $period->name }}" required>
                                                        </div>
                                                        <div class="row mb-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">{{ __('Bulan') }}</label>
                                                                <select name="month" class="form-select" required>
                                                                    @for($m=1; $m<=12; $m++)
                                                                        <option value="{{ $m }}" {{ $period->month == $m ? 'selected' : '' }}>
                                                                            {{ date('F', mktime(0, 0, 0, $m, 1)) }}
                                                                        </option>
                                                                    @endfor
                                                                </select>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">{{ __('Tahun') }}</label>
                                                                <input type="number" name="year" class="form-control" value="{{ $period->year }}" min="2000" required>
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">{{ __('Status') }}</label>
                                                            <select name="status" class="form-select" required>
                                                                <option value="open" {{ $period->status === 'open' ? 'selected' : '' }}>{{ __('Open (Menerima Penawaran)') }}</option>
                                                                <option value="closed" {{ $period->status === 'closed' ? 'selected' : '' }}>{{ __('Closed (Selesai)') }}</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Batal') }}</button>
                                                        <button type="submit" class="btn btn-primary">{{ __('Simpan Perubahan') }}</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('purchasing.periods.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">{{ __('Tambah Periode Baru') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">{{ __('Nama Periode') }}</label>
                            <input type="text" name="name" class="form-control" placeholder="{{ __('Contoh: Periode Mei 2026') }}" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Bulan') }}</label>
                                <select name="month" class="form-select" required>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}" {{ now()->month == $m ? 'selected' : '' }}>
                                            {{ date('F', mktime(0, 0, 0, $m, 1)) }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Tahun') }}</label>
                                <input type="number" name="year" class="form-control" value="{{ now()->year }}" min="2000" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Status') }}</label>
                            <select name="status" class="form-select" required>
                                <option value="open">{{ __('Open (Menerima Penawaran)') }}</option>
                                <option value="closed">{{ __('Closed (Selesai)') }}</option>
                            </select>
                            <div class="form-text">{{ __('PR hanya bisa dibuat pada periode berstatus Open.') }}</div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Batal') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Simpan Periode') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
