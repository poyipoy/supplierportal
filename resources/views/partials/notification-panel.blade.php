<div class="notification-panel">
    <div class="notification-menu nav nav-pills" role="tablist" aria-label="Notification categories">
        <div class="notification-menu-heading">
            <x-ui.icon name="layers" class="me-1" />Categories
        </div>
        @foreach($notificationCategories as $key => $category)
            <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                id="notif-tab-{{ $key }}"
                data-bs-toggle="pill"
                data-bs-target="#notif-pane-{{ $key }}"
                data-notification-category="{{ $key }}"
                data-notification-mark-label="{{ $key === \App\Support\NotificationCategory::ALL ? 'Mark All as Read' : 'Mark All ' . $category['short_label'] . ' Read' }}"
                type="button"
                role="tab"
                aria-controls="notif-pane-{{ $key }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                <span class="d-flex align-items-center gap-2">
                    <x-ui.icon :name="$category['icon']" />
                    {{ $category['short_label'] }}
                </span>
                @if(($navbarNotificationCounts[$key] ?? 0) > 0)
                    <span class="tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground" data-notification-category-count="{{ $key }}" data-notification-total="{{ $navbarNotificationTotals[$key] ?? 0 }}">{{ $navbarNotificationCounts[$key] }}</span>
                @else
                    <span class="tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-border tw-border-outline-variant tw-bg-surface tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" data-notification-category-count="{{ $key }}" data-notification-total="{{ $navbarNotificationTotals[$key] ?? 0 }}">{{ $navbarNotificationTotals[$key] ?? 0 }}</span>
                @endif
            </button>
        @endforeach
    </div>
    <div class="notification-list-pane">
        <div class="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-outline-variant tw-bg-surface tw-px-3 tw-py-2">
            <div>
                <div class="fw-bold small">Notifications</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant">Grouped by activity type</div>
            </div>
            <span class="text-muted small">{{ $navbarNotifications->count() }} {{ \Illuminate\Support\Str::plural('notification', $navbarNotifications->count()) }}</span>
        </div>
        <div class="tab-content notification-list">
            @foreach($notificationCategories as $key => $category)
                <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                    id="notif-pane-{{ $key }}"
                    role="tabpanel"
                    aria-labelledby="notif-tab-{{ $key }}">
                    @forelse($navbarNotificationGroups[$key] as $notif)
                        @php
                            $notifCategoryKey = \App\Support\NotificationCategory::key($notif);
                            $notifCategory = $notificationCategories[$notifCategoryKey] ?? $notificationCategories[\App\Support\NotificationCategory::OTHER];
                        @endphp
                        <div role="button"
                            class="notification-item {{ $notif->read_at ? '' : 'tw-bg-primary-container' }}"
                            data-notification-item
                            data-notification-category="{{ $notifCategoryKey }}"
                            data-notification-unread="{{ $notif->read_at ? '0' : '1' }}"
                            data-notification-read-url="{{ route('notifications.read', $notif->id) }}"
                            data-notification-id="{{ $notif->id }}"
                            style="cursor: pointer;">
                            <div class="d-flex gap-3">
                                <x-ui.icon :name="$notif->data['icon'] ?? $notifCategory['icon']" size="lg" class="text-primary flex-shrink-0 mt-1" />
                                <div class="tw-min-w-0 flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold small text-truncate">{{ $notif->data['title'] ?? 'Notifications' }}</div>
                                        @if(!$notif->read_at)
                                            <span class="tw-shrink-0 tw-rounded-ui-xs tw-bg-error-container tw-px-1.5 tw-py-0.5 tw-text-ui-xs tw-font-semibold tw-text-error-container-foreground" data-notification-new-badge>New</span>
                                        @endif
                                    </div>
                                    <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ \Illuminate\Support\Str::limit($notif->data['message'] ?? '-', 92) }}</div>
                                    <div class="tw-mt-2 tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-text-ui-xs tw-text-on-surface-variant">
                                        <span class="tw-inline-flex tw-items-center tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-px-2 tw-py-0.5 tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">
                                            <x-ui.icon :name="$notifCategory['icon']" class="me-1" />{{ $notifCategory['label'] }}
                                        </span>
                                        <span><x-ui.icon name="clock" class="me-1" />{{ $notif->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5 px-3">
                            <x-ui.icon :name="$category['icon']" size="lg" class="tw-opacity-60" />
                            <div class="fw-semibold mt-2">No {{ strtolower($category['label']) }}</div>
                            <div class="tw-text-ui-xs">{{ $category['description'] }}</div>
                        </div>
                    @endforelse
                </div>
            @endforeach
        </div>
        <div class="tw-flex tw-gap-2 tw-border-t tw-border-outline-variant tw-bg-surface tw-p-2">
            <form action="{{ route('notifications.mark-all-read') }}" method="POST" class="flex-fill" data-notification-mark-form>
                @csrf
                <input type="hidden" name="category" value="{{ \App\Support\NotificationCategory::ALL }}" data-notification-category-input>
                <x-ui.button type="submit" size="sm" class="tw-w-full" data-notification-mark-button>Mark All as Read</x-ui.button>
            </form>
        </div>
    </div>
</div>
