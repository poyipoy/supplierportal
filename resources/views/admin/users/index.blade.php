@extends('layouts.app')
@section('title', 'Manajemen User — ADASI Portal')
@section('page-title', 'Manajemen User')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Daftar Pengguna</h6>
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm fw-medium">
                <i class="bi bi-plus-lg me-1"></i> Tambah User
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Terdaftar Sejak</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $index => $user)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    <div class="fw-medium">{{ $user->name }}</div>
                                    @if($user->role === 'supplier' && $user->supplier)
                                        <small class="text-muted"><i class="bi bi-building me-1"></i>{{ $user->supplier->company_name }}</small>
                                    @endif
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @if($user->role === 'admin')
                                        <span class="badge bg-danger text-uppercase">Admin</span>
                                    @elseif($user->role === 'purchasing')
                                        <span class="badge bg-primary text-uppercase">Purchasing</span>
                                    @elseif($user->role === 'supplier')
                                        <span class="badge bg-info text-dark text-uppercase">Supplier</span>
                                    @elseif($user->role === 'qc')
                                        <span class="badge bg-warning text-dark text-uppercase">QC</span>
                                    @endif
                                </td>
                                <td>
                                    @if($user->is_active)
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success">Aktif</span>
                                    @else
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary">Nonaktif</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at->format('d M Y') }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @if($user->id !== auth()->id())
                                        <form action="{{ route('admin.users.destroy', $user->id) }}" method="POST" class="d-inline" onsubmit='return confirm(@json('Yakin ingin menghapus user ini?'));'>
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Hapus">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
