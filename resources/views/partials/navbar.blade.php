<nav class="top-navbar d-flex align-items-center justify-content-between">
    {{-- Left: Mobile drawer toggle + Page title --}}
    <div class="d-flex align-items-center tw-gap-2.5 tw-min-w-0">
        <x-ui.icon-button
            icon="panel-left"
            label="Toggle sidebar"
            size="lg"
            class="sidebar-toggle sidebar-toggle--mobile tw-text-on-surface-variant"
            x-on:click="$dispatch('ui-sidebar-toggle', { trigger: $el })"
            x-bind:aria-label="sidebarToggleLabel"
            x-bind:title="sidebarToggleLabel"
            x-bind:aria-expanded="viewportIsDesktop ? (!desktopCollapsed).toString() : mobileOpen.toString()"
            aria-controls="sidebar"
        >
            <x-slot:visual>
                <span class="sidebar-toggle-icons" x-bind:class="sidebarIsExpanded ? 'is-expanded' : 'is-collapsed'" aria-hidden="true">
                    <span class="sidebar-toggle-icon sidebar-toggle-icon--collapse">
                        <x-ui.icon name="panel-left-close" size="md" />
                    </span>
                    <span class="sidebar-toggle-icon sidebar-toggle-icon--expand">
                        <x-ui.icon name="panel-left-open" size="md" />
                    </span>
                </span>
            </x-slot:visual>
        </x-ui.icon-button>
        <div class="vr mx-1 my-2 tw-text-outline d-none d-sm-block d-lg-none" style="height: 18px;"></div>
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
        <div
            class="dropdown"
            data-notification-dropdown
            data-notification-summary-url="{{ route('notifications.summary') }}"
        >
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
                <div
                    class="tw-flex tw-min-h-48 tw-min-w-72 tw-items-center tw-justify-center tw-p-4 tw-text-ui-sm tw-text-on-surface-variant"
                    data-notification-summary-container
                    data-notification-summary-state="idle"
                    aria-live="polite"
                >
                    Open notifications to load the latest activity.
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
            async function loadNotificationSummary(dropdown) {
                const container = dropdown?.querySelector('[data-notification-summary-container]');
                const state = container?.dataset.notificationSummaryState;

                if (!container || state === 'loading' || state === 'loaded') {
                    return;
                }

                container.dataset.notificationSummaryState = 'loading';
                container.textContent = 'Loading notifications...';

                try {
                    const response = await fetch(dropdown.dataset.notificationSummaryUrl, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Failed to load notifications.');
                    }

                    const documentFragment = new DOMParser().parseFromString(
                        await response.text(),
                        'text/html'
                    );
                    const panel = documentFragment.body.firstElementChild;

                    if (!panel) {
                        throw new Error('Notification response was empty.');
                    }

                    container.className = '';
                    container.replaceChildren(panel);

                    if (container.dataset.notificationSummaryDirty === 'true') {
                        delete container.dataset.notificationSummaryDirty;
                        container.dataset.notificationSummaryState = 'idle';
                        await loadNotificationSummary(dropdown);

                        return;
                    }

                    container.dataset.notificationSummaryState = 'loaded';
                } catch (error) {
                    console.error(error);
                    container.dataset.notificationSummaryState = 'error';
                    container.textContent = 'Notifications could not be loaded. Close and reopen to retry.';
                }
            }

            document.addEventListener('show.bs.dropdown', function (event) {
                const dropdown = event.target.closest('[data-notification-dropdown]');
                if (dropdown) {
                    loadNotificationSummary(dropdown);
                }
            });

            document.addEventListener('click', function (event) {
                const toggle = event.target.closest('[data-notification-dropdown] [data-bs-toggle="dropdown"]');
                if (toggle) {
                    loadNotificationSummary(toggle.closest('[data-notification-dropdown]'));
                }
            });

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
