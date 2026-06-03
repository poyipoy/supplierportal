@extends('layouts.app')
@section('title', 'Manajemen Pengumuman — ADASI Portal')
@section('page-title', 'Manajemen Pengumuman')
@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Daftar Pengumuman</h5>
        <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> Buat Pengumuman</a>
    </div>
    <div class="card-body"><div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light"><tr><th width="5%">No</th><th>Judul</th><th>Dibuat Oleh</th><th>Status</th><th>Tanggal</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
                @forelse($announcements as $i => $ann)
                <tr>
                    <td>{{ $announcements->firstItem() + $i }}</td><td class="fw-medium">{{ $ann->title }}</td><td>{{ $ann->creator->name }}</td>
                    <td>@if($ann->published_at)<span class="badge bg-success">Published</span>@else<span class="badge bg-secondary">Draft</span>@endif</td>
                    <td>{{ $ann->published_at ? $ann->published_at->format('d M Y H:i') : '-' }}</td>
                    <td class="text-end">
                        <form action="{{ route('admin.announcements.toggle-publish', $ann->id) }}" method="POST" class="d-inline">@csrf<button type="submit" class="btn btn-sm btn-outline-{{ $ann->published_at ? 'warning' : 'success' }}"><i class="bi bi-{{ $ann->published_at ? 'eye-slash' : 'eye' }}"></i></button></form>
                        <a href="{{ route('admin.announcements.edit', $ann->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('admin.announcements.destroy', $ann->id) }}" method="POST" class="d-inline">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger" onclick='return confirm(@json('Yakin ingin menghapus?'))'><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty<tr><td colspan="6" class="p-0"><x-empty-state icon="bi-megaphone" title="Belum ada pengumuman" class="py-4 border-0" /></td></tr>@endforelse
            </tbody>
        </table>
    </div><div class="mt-3">{{ $announcements->links() }}</div></div>
</div>
@endsection
