<nav class="top-navbar d-flex align-items-center justify-content-between">
    {{-- Left: Mobile toggle + Page title --}}
    <div class="d-flex align-items-center tw-gap-2.5 tw-min-w-0">
        <x-ui.icon-button
            icon="panel-left"
            label="Toggle sidebar navigation"
            size="sm"
            class="sidebar-toggle tw-text-on-surface-variant"
            x-on:click="$dispatch('ui-sidebar-toggle')"
            x-bind:aria-expanded="viewportIsDesktop ? (!desktopCollapsed).toString() : mobileOpen.toString()"
            aria-controls="sidebar"
        />
        <div class="vr mx-1 my-2 tw-text-outline d-none d-sm-block" style="height: 18px;"></div>
        <p class="topbar-page-title text-truncate">@yield('page-title', 'Dashboard')</p>
    </div>

    {{-- Right: User info + Notifications + Chat --}}
    <div class="d-flex align-items-center gap-2">
        {{-- Chat Icon (Only for Purchasing and Supplier) --}}
        @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
            <x-ui.icon-button
                :href="route(auth()->user()->role . '.conversations.index')"
                icon="message-circle-more"
                label="Chat and Negotiation"
                size="sm"
                data-chat-drawer
            >
                <x-slot:badge>
                    <span class="chat-badge topbar-counter {{ $initChatCount > 0 ? '' : 'd-none' }}">{{ $initChatCount }}</span>
                </x-slot:badge>
            </x-ui.icon-button>
        @endif

        {{-- Notification Icon --}}
        <div class="dropdown">
            <x-ui.icon-button
                icon="bell"
                label="Notifications"
                size="sm"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
            >
                <x-slot:badge>
                    <span class="notif-badge topbar-counter {{ $initNotifCount > 0 ? '' : 'd-none' }}">{{ $initNotifCount }}</span>
                </x-slot:badge>
            </x-ui.icon-button>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown shadow-sm border">
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
            </div>
        </div>

        {{-- Role Badge --}}
        <span class="role-badge role-badge-{{ auth()->user()->role }}">
            {{ ucfirst(auth()->user()->role) }}
        </span>

        {{-- User Dropdown --}}
        <div class="dropdown">
            <button class="topbar-user-trigger dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open user menu">
                <x-ui.user-chip :name="auth()->user()->name" :meta="auth()->user()->email" class="topbar-user-chip" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border">
                <li>
                    <span class="dropdown-item-text small text-muted">
                        {{ auth()->user()->email }}
                    </span>
                </li>
                <li>
                    <hr class="dropdown-divider my-1">
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="dropdown-item tw-py-1.5 small">
                        <x-ui.icon name="user-cog" class="me-2" />Profile &amp; Security
                    </a>
                </li>
                <li>
                    <hr class="dropdown-divider my-1">
                </li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger tw-py-1.5 small">
                            <x-ui.icon name="log-out" class="me-2" />Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>

@once
    @push('scripts')
        <script>
            function setNotificationBadgeState(badge, unread, total) {
                badge.textContent = unread > 0 ? unread : total;
                ['tw-bg-error', 'tw-text-error-foreground'].forEach((className) => {
                    badge.classList.toggle(className, unread > 0);
                });
                ['tw-border', 'tw-border-outline-variant', 'tw-bg-surface', 'tw-text-on-surface-variant'].forEach((className) => {
                    badge.classList.toggle(className, unread <= 0);
                });
            }

            function updateNotificationUnreadBadge(count) {
                document.querySelectorAll('.notif-badge').forEach((badge) => {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.classList.remove('d-none');
                    } else {
                        badge.textContent = '0';
                        badge.classList.add('d-none');
                    }
                });
            }

            function updateNotificationCategoryBadges(categoryCounts) {
                if (!categoryCounts) {
                    return;
                }

                document.querySelectorAll('[data-notification-category-count]').forEach((badge) => {
                    const category = badge.dataset.notificationCategoryCount;
                    const counts = categoryCounts[category];

                    if (!counts) {
                        return;
                    }

                    const unread = Number(counts.unread || 0);
                    const total = Number(counts.total || badge.dataset.notificationTotal || 0);
                    badge.dataset.notificationTotal = total;
                    setNotificationBadgeState(badge, unread, total);
                });
            }

            function markNotificationItemsRead(dropdown, category) {
                dropdown.querySelectorAll('[data-notification-item]').forEach((item) => {
                    if (category !== 'all' && item.dataset.notificationCategory !== category) {
                        return;
                    }

                    item.classList.remove('tw-bg-primary-container');
                    item.dataset.notificationUnread = '0';
                    item.querySelectorAll('[data-notification-new-badge]').forEach((badge) => badge.remove());
                });
            }

            document.addEventListener('shown.bs.tab', function (event) {
                const tab = event.target.closest('[data-notification-category]');
                if (!tab) {
                    return;
                }

                const dropdown = tab.closest('.notification-dropdown');
                if (!dropdown) {
                    return;
                }

                const categoryInput = dropdown.querySelector('[data-notification-category-input]');
                const markButton = dropdown.querySelector('[data-notification-mark-button]');

                if (categoryInput) {
                    categoryInput.value = tab.dataset.notificationCategory || 'all';
                }

                if (markButton) {
                    markButton.textContent = tab.dataset.notificationMarkLabel || 'Mark All as Read';
                }
            });

            document.addEventListener('submit', async function (event) {
                const form = event.target.closest('[data-notification-mark-form]');
                if (!form) {
                    return;
                }

                event.preventDefault();

                const dropdown = form.closest('.notification-dropdown');
                    const categoryInput = form.querySelector('[data-notification-category-input]');
                    const activeTab = dropdown?.querySelector('[data-notification-category].active');
                    const scrollPane = dropdown?.querySelector('.notification-list');
                    const scrollTop = scrollPane?.scrollTop || 0;
                    const category = activeTab?.dataset.notificationCategory || categoryInput?.value || 'all';
                    const button = form.querySelector('[data-notification-mark-button]');
                const originalLabel = button?.textContent || 'Mark All as Read';
                const formData = new FormData(form);
                formData.set('category', category);

                if (categoryInput) {
                    categoryInput.value = category;
                }

                if (button) {
                    button.disabled = true;
                    button.textContent = 'Processing...';
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });

                    if (!response.ok) {
                        throw new Error('Failed to mark notification.');
                    }

                    const data = await response.json();
                    if (dropdown) {
                        markNotificationItemsRead(dropdown, data.category || category);
                    }
                    if (scrollPane) {
                        scrollPane.scrollTop = scrollTop;
                    }
                    updateNotificationUnreadBadge(Number(data.unread_count || 0));
                    updateNotificationCategoryBadges(data.category_counts);
                } catch (error) {
                    console.error(error);
                } finally {
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                    }
                }
            });

            // Notification item click: POST mark-as-read, then redirect
            document.addEventListener('click', async function (event) {
                const item = event.target.closest('[data-notification-read-url]');
                if (!item) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                const readUrl = item.dataset.notificationReadUrl;
                if (!readUrl) {
                    return;
                }

                // Disable double-click
                if (item.dataset.processing === 'true') {
                    return;
                }
                item.dataset.processing = 'true';
                item.style.opacity = '0.6';

                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                    const response = await fetch(readUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.ok) {
                        const data = await response.json();
                        if (data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                    }

                    // Fallback: reload current page
                    window.location.reload();
                } catch (error) {
                    console.error('Failed to mark notification:', error);
                    item.dataset.processing = 'false';
                    item.style.opacity = '1';
                }
            });
        </script>
    @endpush
@endonce
