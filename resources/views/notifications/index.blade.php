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
        description="Persistent workflow updates and account activity across the portal."
        eyebrow="Activity"
    >
        <x-slot:actions>
            <form action="{{ route('notifications.mark-all-read') }}" method="POST">
                @csrf
                <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-on-surface hover:tw-bg-surface-container">
                    <x-ui.icon name="check-check" />Mark All as Read
                </button>
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-gap-5 lg:tw-grid-cols-[16rem_minmax(0,1fr)] lg:tw-items-start">
        {{-- Category Sidebar --}}
        <nav class="tw-border tw-border-outline tw-bg-surface-container" aria-label="Notification categories">
            <div class="tw-border-b tw-border-outline-variant tw-bg-surface-low tw-px-4 tw-py-3">
                <h2 class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Categories</h2>
            </div>
            <div class="tw-divide-y tw-divide-outline-variant">
                @foreach($categoryOptions as $key => $category)
                    @php
                        $counts = $categoryCounts[$key] ?? ['total' => 0, 'unread' => 0];
                        $url = $key === \App\Support\NotificationCategory::ALL
                            ? route('notifications.index')
                            : route('notifications.index', ['category' => $key]);
                        $isActive = $selectedCategory === $key;
                    @endphp
                    <a href="{{ $url }}" class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-px-4 tw-py-2.5 tw-text-ui-sm tw-no-underline tw-transition-colors {{ $isActive ? 'tw-bg-primary/5 tw-font-semibold tw-text-primary tw-border-l-2 tw-border-l-primary' : 'tw-text-on-surface hover:tw-bg-surface-low' }}">
                        <span class="tw-inline-flex tw-items-center tw-gap-2 tw-min-w-0">
                            <x-ui.icon :name="$category['icon']" class="tw-shrink-0" />
                            <span class="tw-truncate">{{ $category['label'] }}</span>
                        </span>
                        @if($counts['unread'] > 0)
                            <span class="tw-inline-flex tw-h-5 tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-bold tw-text-error-foreground">{{ $counts['unread'] }}</span>
                        @elseif($counts['total'] > 0)
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $counts['total'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </nav>

        {{-- Notification Feed --}}
        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="notification-feed-title">
            <header class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-3">
                <div>
                    <h2 id="notification-feed-title" class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold">
                        <x-ui.icon :name="$selectedOption['icon']" class="tw-text-primary" />{{ $selectedOption['label'] }}
                    </h2>
                    <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $selectedOption['description'] }}</p>
                </div>
            </header>

            <div class="tw-divide-y tw-divide-outline-variant">
                @forelse($notifications as $notif)
                    @php
                        $notifCategoryKey = \App\Support\NotificationCategory::key($notif);
                        $notifCategory = $categoryOptions[$notifCategoryKey] ?? $categoryOptions[\App\Support\NotificationCategory::OTHER];
                        $isUnread = !$notif->read_at;
                    @endphp
                    <a href="{{ route('notifications.read', $notif->id) }}" class="tw-flex tw-gap-3 tw-px-5 tw-py-3 tw-text-on-surface tw-no-underline tw-transition-colors hover:tw-bg-surface-low {{ $isUnread ? 'tw-bg-primary/[0.03]' : '' }}">
                        <div class="tw-shrink-0 tw-mt-0.5">
                            <x-ui.icon :name="$notif->data['icon'] ?? $notifCategory['icon']" class="{{ $isUnread ? 'tw-text-primary' : 'tw-text-on-surface-variant' }}" />
                        </div>
                        <div class="tw-min-w-0 tw-flex-1">
                            <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
                                <span class="tw-text-ui-sm {{ $isUnread ? 'tw-font-semibold' : 'tw-font-medium' }} tw-truncate">{{ $notif->data['title'] ?? 'Notification' }}</span>
                                <span class="tw-shrink-0 tw-text-ui-xs tw-text-on-surface-variant tw-whitespace-nowrap">{{ $notif->created_at->diffForHumans(short: true) }}</span>
                            </div>
                            <div class="tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant tw-line-clamp-2">{{ $notif->data['message'] ?? '-' }}</div>
                            <div class="tw-mt-1.5 tw-flex tw-items-center tw-gap-3">
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">
                                    <x-ui.icon :name="$notifCategory['icon']" />{{ $notifCategory['label'] }}
                                </span>
                                @if($isUnread)
                                    <span class="tw-inline-flex tw-h-1.5 tw-w-1.5 tw-rounded-full tw-bg-primary" aria-label="Unread"></span>
                                @endif
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="tw-py-12 tw-px-5">
                        <x-empty-state :icon="$selectedOption['icon']" title="No notifications in this category." text="New activity will appear here when it arrives." />
                    </div>
                @endforelse
            </div>

            @if($notifications->hasPages())
                <div class="tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">{{ $notifications->links() }}</div>
            @endif
        </section>
    </div>
</div>
@endsection
