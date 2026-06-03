@extends('layouts.app')
@section('title', 'Notifikasi - ADASI Portal')
@section('page-title', 'Notifikasi')

@section('content')
@php
    $selectedOption = $categoryOptions[$selectedCategory] ?? $categoryOptions[\App\Support\NotificationCategory::ALL];
@endphp

<div class="row g-3">
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-1 fw-bold">Kategori Notifikasi</h6>
                <div class="text-muted small">Pilih jenis aktivitas yang ingin dilihat.</div>
            </div>
            <div class="card-body notification-page-menu">
                <div class="list-group list-group-flush">
                    @foreach($categoryOptions as $key => $category)
                        @php
                            $counts = $categoryCounts[$key] ?? ['total' => 0, 'unread' => 0];
                            $url = $key === \App\Support\NotificationCategory::ALL
                                ? route('notifications.index')
                                : route('notifications.index', ['category' => $key]);
                        @endphp
                        <a href="{{ $url }}" class="list-group-item list-group-item-action {{ $selectedCategory === $key ? 'active' : '' }}">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <i class="bi {{ $category['icon'] }} flex-shrink-0"></i>
                                <span class="text-truncate">{{ $category['label'] }}</span>
                            </span>
                            <span class="d-flex align-items-center gap-1 flex-shrink-0">
                                @if($counts['unread'] > 0)
                                    <span class="badge rounded-pill bg-danger">{{ $counts['unread'] }}</span>
                                @endif
                                <span class="badge rounded-pill bg-white text-muted border">{{ $counts['total'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-9">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1 fw-bold">
                        <i class="bi {{ $selectedOption['icon'] }} me-2 text-primary"></i>{{ $selectedOption['label'] }}
                    </h5>
                    <div class="text-muted small">{{ $selectedOption['description'] }}</div>
                </div>
                <form action="{{ route('notifications.mark-all-read') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-check2-all me-1"></i>Tandai Semua Dibaca
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @forelse($notifications as $notif)
                        @php
                            $notifCategoryKey = \App\Support\NotificationCategory::key($notif);
                            $notifCategory = $categoryOptions[$notifCategoryKey] ?? $categoryOptions[\App\Support\NotificationCategory::OTHER];
                        @endphp
                        <a href="{{ route('notifications.read', $notif->id) }}" class="list-group-item list-group-item-action py-3 {{ $notif->read_at ? '' : 'bg-light' }}">
                            <div class="d-flex gap-3 align-items-start">
                                <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;">
                                    <i class="bi {{ $notif->data['icon'] ?? $notifCategory['icon'] }} text-primary"></i>
                                </div>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-bold small text-truncate">{{ $notif->data['title'] ?? 'Notifikasi' }}</span>
                                        @if(!$notif->read_at)
                                            <span class="badge bg-danger flex-shrink-0" style="font-size:.55rem">Baru</span>
                                        @endif
                                    </div>
                                    <div class="text-muted mt-1" style="font-size:.82rem">{{ $notif->data['message'] ?? '-' }}</div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 text-muted" style="font-size:.72rem">
                                        <span class="badge bg-light text-muted border">
                                            <i class="bi {{ $notifCategory['icon'] }} me-1"></i>{{ $notifCategory['label'] }}
                                        </span>
                                        <span><i class="bi bi-clock me-1"></i>{{ $notif->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="py-4">
                            <x-empty-state :icon="$selectedOption['icon']" title="Belum ada notifikasi pada kategori ini." />
                        </div>
                    @endforelse
                </div>
            </div>
            @if($notifications->hasPages())
                <div class="card-footer bg-white py-3">{{ $notifications->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
