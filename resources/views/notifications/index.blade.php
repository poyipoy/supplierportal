@extends('layouts.app')
@section('title', 'Notifikasi — ADASI Portal')
@section('page-title', 'Notifikasi')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold">Semua Notifikasi</h5>
        <form action="{{ route('notifications.mark-all-read') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-all me-1"></i>Tandai Semua Dibaca</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            @forelse($notifications as $notif)
                <a href="{{ route('notifications.read', $notif->id) }}" class="list-group-item list-group-item-action py-3 {{ $notif->read_at ? '' : 'bg-light' }}">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="bg-primary bg-opacity-10 p-2 rounded-circle flex-shrink-0">
                            <i class="bi {{ $notif->data['icon'] ?? 'bi-bell' }} text-primary"></i>
                        </div>
                        <div style="flex:1">
                            <div class="fw-bold small d-flex justify-content-between">
                                <span>{{ $notif->data['title'] }}</span>
                                @if(!$notif->read_at)<span class="badge bg-danger" style="font-size:.55rem">Baru</span>@endif
                            </div>
                            <div class="text-muted" style="font-size:.8rem">{{ $notif->data['message'] }}</div>
                            <div class="text-muted mt-1" style="font-size:.7rem"><i class="bi bi-clock me-1"></i>{{ $notif->created_at->diffForHumans() }}</div>
                        </div>
                    </div>
                </a>
            @empty
                <div class="p-5 text-center text-muted"><i class="bi bi-bell-slash" style="font-size:3rem"></i><p class="mt-3">Belum ada notifikasi.</p></div>
            @endforelse
        </div>
    </div>
    @if($notifications->hasPages())
    <div class="card-footer bg-white py-3">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
