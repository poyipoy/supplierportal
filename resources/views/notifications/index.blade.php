@extends('layouts.app')
@section('title', 'Notifications - ADASI Portal')
@section('page-title', 'Notifications')

@section('content')
@php
    $selectedOption = $categoryOptions[$selectedCategory] ?? $categoryOptions[\App\Support\NotificationCategory::ALL];
@endphp

<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Notification Center"
        description="Review persistent workflow updates and account activity."
        eyebrow="Activity"
    />

    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-[17rem_minmax(0,1fr)] lg:tw-items-start">
        <x-ui.card title="Categories" description="Filter by activity type." padding="none">
            <div class="notification-page-menu tw-p-2">
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
                                <x-ui.icon :name="$category['icon']" class="flex-shrink-0" />
                                <span class="text-truncate">{{ $category['label'] }}</span>
                            </span>
                            <span class="tw-shrink-0 tw-text-ui-xs {{ $counts['unread'] > 0 ? 'tw-font-semibold tw-text-error' : 'tw-text-on-surface-variant' }}">
                                {{ $counts['unread'] > 0 ? $counts['unread'].' unread / ' : '' }}{{ $counts['total'] }} total
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card padding="none">
            <x-slot:header>
                <div>
                    <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-lg tw-font-semibold">
                        <x-ui.icon :name="$selectedOption['icon']" class="me-2 text-primary" />{{ $selectedOption['label'] }}
                    </h2>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">{{ $selectedOption['description'] }}</p>
                </div>
            </x-slot:header>
            <x-slot:actions>
                <form action="{{ route('notifications.mark-all-read') }}" method="POST">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">
                        <x-ui.icon name="check-check" size="sm" />Mark All as Read
                    </x-ui.button>
                </form>
            </x-slot:actions>

            <div>
                <div class="list-group list-group-flush">
                    @forelse($notifications as $notif)
                        @php
                            $notifCategoryKey = \App\Support\NotificationCategory::key($notif);
                            $notifCategory = $categoryOptions[$notifCategoryKey] ?? $categoryOptions[\App\Support\NotificationCategory::OTHER];
                        @endphp
                        <a href="{{ route('notifications.read', $notif->id) }}" class="list-group-item list-group-item-action py-3 {{ $notif->read_at ? '' : 'bg-light' }}">
                            <div class="d-flex gap-3 align-items-start">
                                <x-ui.icon :name="$notif->data['icon'] ?? $notifCategory['icon']" size="lg" class="text-primary flex-shrink-0 mt-1" />
                                <div class="min-w-0 flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-bold small text-truncate">{{ $notif->data['title'] ?? 'Notifications' }}</span>
                                        @if(!$notif->read_at)
                                            <x-ui.status-chip tone="error" size="sm" class="tw-shrink-0">New</x-ui.status-chip>
                                        @endif
                                    </div>
                                    <div class="tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">{{ $notif->data['message'] ?? '-' }}</div>
                                    <div class="tw-mt-2 tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-text-ui-xs tw-text-on-surface-variant">
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-font-semibold">
                                            <x-ui.icon :name="$notifCategory['icon']" class="me-1" />{{ $notifCategory['label'] }}
                                        </span>
                                        <span><x-ui.icon name="clock" class="me-1" />{{ $notif->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="py-4">
                            <x-empty-state :icon="$selectedOption['icon']" title="No notifications in this category yet." />
                        </div>
                    @endforelse
                </div>
            </div>
            @if($notifications->hasPages())
                <div class="tw-border-t tw-border-outline-variant tw-p-4">{{ $notifications->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</div>
@endsection
