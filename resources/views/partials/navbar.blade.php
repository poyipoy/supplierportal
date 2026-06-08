<nav class="top-navbar d-flex align-items-center justify-content-between">
    {{-- Left: Mobile toggle + Page title --}}
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-sm btn-outline-secondary sidebar-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <h6 class="mb-0 fw-semibold text-dark">@yield('page-title', 'Dashboard')</h6>
    </div>

    {{-- Right: User info + Logout --}}
    <div class="d-flex align-items-center gap-3">
        {{-- Chat Icon (Only for Purchasing and Supplier) --}}
        @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
            <a href="{{ route(auth()->user()->role . '.conversations.index') }}" class="btn btn-sm btn-light position-relative" title="Chat & Negotiation" data-chat-drawer>
                <i class="bi bi-chat-dots" style="font-size:1.2rem;"></i>
                <span class="chat-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger {{ $initChatCount > 0 ? '' : 'd-none' }}" style="font-size:0.6rem;">
                    {{ $initChatCount }}
                </span>
            </a>
        @endif

        {{-- Notification Icon --}}
        @php
            $notificationCategories = \App\Support\NotificationCategory::options();
            $navbarNotifications = auth()->user()->notifications()->latest()->take(30)->get();
            $navbarUnreadCount = auth()->user()->unreadNotifications()->count();
            $navbarVisibleUnreadNotifications = $navbarNotifications->whereNull('read_at');
            $navbarNotificationCounts = collect($notificationCategories)->mapWithKeys(function ($option, $key) use ($navbarVisibleUnreadNotifications, $navbarUnreadCount) {
                if ($key === \App\Support\NotificationCategory::ALL) {
                    return [$key => $navbarUnreadCount];
                }

                $items = $key === \App\Support\NotificationCategory::ALL
                    ? $navbarVisibleUnreadNotifications
                    : $navbarVisibleUnreadNotifications->filter(fn ($notification) => \App\Support\NotificationCategory::key($notification) === $key);

                return [$key => $items->count()];
            });
            $navbarNotificationTotals = collect($notificationCategories)->mapWithKeys(function ($option, $key) use ($navbarNotifications) {
                $items = $key === \App\Support\NotificationCategory::ALL
                    ? $navbarNotifications
                    : $navbarNotifications->filter(fn ($notification) => \App\Support\NotificationCategory::key($notification) === $key);

                return [$key => $items->count()];
            });
            $navbarNotificationGroups = collect($notificationCategories)->mapWithKeys(function ($option, $key) use ($navbarNotifications) {
                $items = $key === \App\Support\NotificationCategory::ALL
                    ? $navbarNotifications
                    : $navbarNotifications->filter(fn ($notification) => \App\Support\NotificationCategory::key($notification) === $key)->values();

                return [$key => $items];
            });
        @endphp
        <div class="dropdown">
            <button class="btn btn-sm btn-light position-relative" type="button" title="Notifications"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <i class="bi bi-bell" style="font-size:1.2rem;"></i>
                <span class="notif-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger {{ $initNotifCount > 0 ? '' : 'd-none' }}" style="font-size:0.6rem;">
                    {{ $initNotifCount }}
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown">
                <div class="notification-panel">
                    <div class="notification-menu nav nav-pills" role="tablist" aria-label="Kategori notification">
                        <div class="notification-menu-heading">
                            <i class="bi bi-layers me-1"></i>Kategori
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
                                    <i class="bi {{ $category['icon'] }}"></i>
                                    {{ $category['short_label'] }}
                                </span>
                                @if(($navbarNotificationCounts[$key] ?? 0) > 0)
                                    <span class="badge rounded-pill bg-danger" data-notification-category-count="{{ $key }}" data-notification-total="{{ $navbarNotificationTotals[$key] ?? 0 }}">{{ $navbarNotificationCounts[$key] }}</span>
                                @else
                                    <span class="badge rounded-pill bg-white text-muted border" data-notification-category-count="{{ $key }}" data-notification-total="{{ $navbarNotificationTotals[$key] ?? 0 }}">{{ $navbarNotificationTotals[$key] ?? 0 }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    <div class="notification-list-pane">
                        <div class="px-3 py-2 border-bottom bg-white d-flex align-items-center justify-content-between">
                            <div>
                                <div class="fw-bold small">Notifications</div>
                                <div class="text-muted" style="font-size:.72rem">Dipisah berdasarkan jenis aktivitas</div>
                            </div>
                            <span class="badge bg-light text-muted border">{{ $navbarNotifications->count() }} notification</span>
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
                                            class="notification-item {{ $notif->read_at ? '' : 'bg-light' }}"
                                            data-notification-item
                                            data-notification-category="{{ $notifCategoryKey }}"
                                            data-notification-unread="{{ $notif->read_at ? '0' : '1' }}"
                                            data-notification-read-url="{{ route('notifications.read', $notif->id) }}"
                                            data-notification-id="{{ $notif->id }}"
                                            style="cursor: pointer;">
                                            <div class="d-flex gap-3">
                                                <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:34px;height:34px;">
                                                    <i class="bi {{ $notif->data['icon'] ?? $notifCategory['icon'] }} text-primary"></i>
                                                </div>
                                                <div class="min-w-0 flex-grow-1">
                                                    <div class="d-flex justify-content-between gap-2">
                                                        <div class="fw-semibold small text-truncate">{{ $notif->data['title'] ?? 'Notifications' }}</div>
                                                        @if(!$notif->read_at)
                                                            <span class="badge bg-danger flex-shrink-0" style="font-size:.55rem" data-notification-new-badge>New</span>
                                                        @endif
                                                    </div>
                                                    <div class="text-muted" style="font-size:.76rem">{{ \Illuminate\Support\Str::limit($notif->data['message'] ?? '-', 92) }}</div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 text-muted" style="font-size:.68rem">
                                                        <span class="badge bg-white text-muted border fw-semibold">
                                                            <i class="bi {{ $notifCategory['icon'] }} me-1"></i>{{ $notifCategory['label'] }}
                                                        </span>
                                                        <span><i class="bi bi-clock me-1"></i>{{ $notif->created_at->diffForHumans() }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-center text-muted py-5 px-3">
                                            <i class="bi {{ $category['icon'] }}" style="font-size:2rem;opacity:.45"></i>
                                            <div class="fw-semibold mt-2">No {{ strtolower($category['label']) }}</div>
                                            <div style="font-size:.75rem">{{ $category['description'] }}</div>
                                        </div>
                                    @endforelse
                                </div>
                            @endforeach
                        </div>
                        <div class="p-2 border-top bg-white d-flex gap-2">
                            <form action="{{ route('notifications.mark-all-read') }}" method="POST" class="flex-fill" data-notification-mark-form>
                                @csrf
                                <input type="hidden" name="category" value="{{ \App\Support\NotificationCategory::ALL }}" data-notification-category-input>
                                <button type="submit" class="btn btn-sm btn-primary w-100" style="background-color: var(--adasi-blue);" data-notification-mark-button>
                                    Mark All as Read
                                </button>
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
            <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2" type="button"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle" style="font-size:1.2rem;"></i>
                <span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <span class="dropdown-item-text small text-muted">
                        {{ auth()->user()->email }}
                    </span>
                </li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
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
                badge.classList.toggle('bg-danger', unread > 0);
                badge.classList.toggle('bg-white', unread <= 0);
                badge.classList.toggle('text-muted', unread <= 0);
                badge.classList.toggle('border', unread <= 0);
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

                    item.classList.remove('bg-light');
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
                    button.textContent = 'Memproses...';
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
