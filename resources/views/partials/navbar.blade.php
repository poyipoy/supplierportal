<nav class="top-navbar tw:flex tw:items-center tw:justify-between tw:gap-2">
    {{-- Left: Mobile drawer toggle + Page title --}}
    <div class="topbar-leading tw:flex tw:items-center tw:gap-2.5 tw:min-w-0 tw:flex-1">
        <x-ui.icon-button
            icon="panel-left"
            :label="__('navigation.toggle_sidebar')"
            size="lg"
            :class="'sidebar-toggle sidebar-toggle--mobile tw:text-on-surface'.(!($isWorkspaceShell ?? false) ? ' sidebar-toggle--desktop-fallback' : '')"
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
        <div class="topbar-divider tw:mx-1 tw:my-2 tw:h-[18px] tw:w-px tw:bg-outline-variant tw:hidden tw:sm:block tw:lg:hidden" aria-hidden="true"></div>
        <p class="topbar-page-title tw:truncate tw:min-w-0">@yield('page-title', __('navigation.dashboard'))</p>
    </div>

    {{-- Right: Global Utility + Activity Alerts + User Identity --}}
    <div class="topbar-actions tw:flex tw:items-center tw:gap-1.5 tw:sm:gap-2 tw:min-w-0 tw:shrink-0">
        <x-ui.environment-tag placement="navbar" />

        {{-- 1. Global System Utility: Language Switcher (Desktop only in topbar; mobile/compact accessed via sidebar drawer / user menu) --}}
        <form action="{{ route('locale.switch') }}" method="POST" data-locale-switcher data-managed-submit data-track-unsaved="false" class="lang-toggle-track topbar-lang-track tw:items-center tw:gap-0.5 tw:p-0.5 tw:rounded-ui-sm tw:bg-surface-container tw:border tw:border-outline tw:m-0" role="group" aria-label="{{ __('customization.language.label') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
            <button
                type="submit"
                name="locale"
                value="id"
                data-locale-option="id"
                class="lang-pill-btn ui-focus-ring tw:flex tw:items-center tw:justify-center tw:px-2 tw:py-0 tw:min-h-[1.625rem] tw:rounded-ui-xs tw:text-ui-xs {{ app()->getLocale() === 'id' ? 'is-active tw:bg-surface tw:text-primary tw:font-bold tw:border tw:border-outline' : 'tw:border-transparent tw:text-on-surface-variant tw:font-semibold tw:hover:text-on-surface' }}"
                aria-pressed="{{ app()->getLocale() === 'id' ? 'true' : 'false' }}"
                aria-disabled="{{ app()->getLocale() === 'id' ? 'true' : 'false' }}"
            >
                <span class="tw:tracking-wider">ID</span>
                <span class="tw:sr-only"> (Bahasa Indonesia)</span>
            </button>
            <button
                type="submit"
                name="locale"
                value="en"
                data-locale-option="en"
                class="lang-pill-btn ui-focus-ring tw:flex tw:items-center tw:justify-center tw:px-2 tw:py-0 tw:min-h-[1.625rem] tw:rounded-ui-xs tw:text-ui-xs {{ app()->getLocale() === 'en' ? 'is-active tw:bg-surface tw:text-primary tw:font-bold tw:border tw:border-outline' : 'tw:border-transparent tw:text-on-surface-variant tw:font-semibold tw:hover:text-on-surface' }}"
                aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}"
                aria-disabled="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}"
            >
                <span class="tw:tracking-wider">EN</span>
                <span class="tw:sr-only"> (English)</span>
            </button>
        </form>

        <div class="topbar-divider topbar-divider--desktop tw:h-4 tw:w-px tw:bg-outline-variant tw:shrink-0" aria-hidden="true"></div>

        {{-- 2. Operational Activity & Alerts: Chat + Notifications --}}
        <div class="tw:inline-flex tw:items-center tw:gap-1">
            {{-- Chat Icon (Only for Purchasing and Supplier in Material Procurement context) --}}
            @if(auth()->user()->isPurchasing() || \App\Support\PortalContext::isImport(auth()->user()))
                <x-ui.icon-button
                    :href="route(auth()->user()->role . '.conversations.index')"
                    icon="message-circle-more"
                    :label="__('navigation.chat')"
                    size="sm"
                    class="topbar-icon-btn"
                    data-chat-drawer
                >
                    <x-slot:badge>
                        <span class="chat-badge topbar-counter {{ $initChatCount > 0 ? '' : 'tw:hidden' }}" data-locale-ignore>{{ $initChatCount }}</span>
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
                    :label="$initNotifCount === 1 ? __('js.notification.unread_one', ['count' => $initNotifCount]) : ($initNotifCount > 1 ? __('js.notification.unread_many', ['count' => $initNotifCount]) : __('js.notification.button_label'))"
                    size="sm"
                    class="topbar-icon-btn"
                    data-ui-dropdown-trigger
                    popovertarget="topbarNotificationPopover"
                    aria-controls="topbarNotificationPopover"
                    aria-expanded="false"
                >
                    <x-slot:badge>
                        <span class="notif-badge topbar-counter {{ $initNotifCount > 0 ? '' : 'tw:hidden' }}" aria-hidden="true" data-locale-ignore>{{ $initNotifCount }}</span>
                    </x-slot:badge>
                </x-ui.icon-button>
                <div id="topbarNotificationPopover" popover="auto" data-ui-dropdown-menu data-ui-auto-close="outside" class="dropdown-menu dropdown-menu-end notification-dropdown tw:shadow-ui-1 tw:border tw:border-outline-variant">
                    <div
                        class="tw:flex tw:min-h-48 tw:min-w-[min(18rem,calc(100vw-2rem))] tw:w-full tw:items-center tw:justify-center tw:p-4 tw:text-ui-sm tw:text-on-surface-variant"
                        data-notification-summary-container
                        data-notification-summary-state="idle"
                        aria-live="polite"
                    >
                        {{ __('navigation.notification_open') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="topbar-divider topbar-divider--desktop tw:h-4 tw:w-px tw:bg-outline-variant tw:shrink-0" aria-hidden="true"></div>

        {{-- 3. User Identity & Role Cluster --}}
        <div class="tw:inline-flex tw:items-center tw:gap-1.5 tw:min-w-0">
            {{-- Role Badge (Desktop inline; on mobile or compact topbar rendered inside user dropdown & drawer header) --}}
            <span class="role-badge role-badge-{{ auth()->user()->role }} topbar-role-badge">
                {{ __('navigation.roles.'.auth()->user()->role) }}
            </span>

            {{-- User Dropdown --}}
            <div class="dropdown">
                <button class="topbar-user-trigger dropdown-toggle" type="button"
                    data-ui-dropdown-trigger popovertarget="topbarUserMenuPopover" aria-controls="topbarUserMenuPopover" aria-haspopup="menu" aria-expanded="false" title="{{ auth()->user()->name }} ({{ auth()->user()->email }})">
                    <x-ui.user-chip :name="auth()->user()->name" class="topbar-user-chip" />
                    <span class="tw:sr-only">{{ __('navigation.open_user') }}</span>
                </button>
                <ul id="topbarUserMenuPopover" popover="auto" data-ui-dropdown-menu @if(config('portal_session.logout_all_devices')) data-ui-auto-close="outside" @endif class="dropdown-menu dropdown-menu-end tw:shadow-ui-1 tw:border tw:border-outline-variant">
                    <li>
                        <div class="dropdown-item-text tw:text-ui-xs tw:text-on-surface-variant tw:flex tw:items-center tw:justify-between tw:gap-2">
                            <span class="tw:truncate">{{ auth()->user()->email }}</span>
                            <span class="role-badge role-badge-{{ auth()->user()->role }} topbar-menu-role-badge tw:text-ui-xs">
                                {{ __('navigation.roles.'.auth()->user()->role) }}
                            </span>
                        </div>
                    </li>
                    <li class="topbar-menu-lang-row">
                        <div class="dropdown-item-text tw:py-1.5 tw:flex tw:items-center tw:justify-between tw:gap-2 tw:bg-surface-container-low tw:rounded-ui-xs tw:mx-1 tw:my-1">
                            <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('customization.language.label') }}</span>
                            <form action="{{ route('locale.switch') }}" method="POST" data-locale-switcher data-managed-submit data-track-unsaved="false" class="lang-toggle-track tw:inline-flex tw:items-center tw:gap-0.5 tw:p-0.5 tw:rounded-ui-sm tw:bg-surface tw:border tw:border-outline tw:m-0" role="group" aria-label="{{ __('customization.language.label') }}">
                                @csrf
                                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                                <button
                                    type="submit"
                                    name="locale"
                                    value="id"
                                    data-locale-option="id"
                                    class="lang-pill-btn ui-focus-ring tw:flex tw:items-center tw:justify-center tw:px-2 tw:py-0.5 tw:min-h-7 tw:rounded-ui-xs tw:text-ui-xs {{ app()->getLocale() === 'id' ? 'is-active tw:bg-surface tw:text-primary tw:font-bold tw:border tw:border-outline' : 'tw:border-transparent tw:text-on-surface-variant tw:font-semibold tw:hover:text-on-surface' }}"
                                    aria-pressed="{{ app()->getLocale() === 'id' ? 'true' : 'false' }}"
                                    aria-disabled="{{ app()->getLocale() === 'id' ? 'true' : 'false' }}"
                                >
                                    <span class="tw:tracking-wider">ID</span>
                                    <span class="tw:sr-only"> (Bahasa Indonesia)</span>
                                </button>
                                <button
                                    type="submit"
                                    name="locale"
                                    value="en"
                                    data-locale-option="en"
                                    class="lang-pill-btn ui-focus-ring tw:flex tw:items-center tw:justify-center tw:px-2 tw:py-0.5 tw:min-h-7 tw:rounded-ui-xs tw:text-ui-xs {{ app()->getLocale() === 'en' ? 'is-active tw:bg-surface tw:text-primary tw:font-bold tw:border tw:border-outline' : 'tw:border-transparent tw:text-on-surface-variant tw:font-semibold tw:hover:text-on-surface' }}"
                                    aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}"
                                    aria-disabled="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}"
                                >
                                    <span class="tw:tracking-wider">EN</span>
                                    <span class="tw:sr-only"> (English)</span>
                                </button>
                            </form>
                        </div>
                    </li>
                <li>
                    <hr class="dropdown-divider tw:my-1">
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="dropdown-item tw:py-1.5 tw:text-ui-xs">
                        <x-ui.icon name="user-cog" class="tw:me-2" />{{ __('navigation.profile') }}
                    </a>
                </li>
                <li>
                    <a href="{{ route('profile.security') }}" class="dropdown-item tw:py-1.5 tw:text-ui-xs">
                        <x-ui.icon name="shield-check" class="tw:me-2" />{{ __('navigation.security') }}
                    </a>
                </li>
                <li>
                    <a href="{{ route('profile.notifications') }}" class="dropdown-item tw:py-1.5 tw:text-ui-xs">
                        <x-ui.icon name="bell" class="tw:me-2" />{{ __('common.notification.title') }}
                    </a>
                </li>
                <li>
                    <a href="{{ route('profile.customization') }}" class="dropdown-item tw:py-1.5 tw:text-ui-xs">
                        <x-ui.icon name="sliders-horizontal" class="tw:me-2" />{{ __('navigation.customization') }}
                    </a>
                </li>
                <li>
                    <hr class="dropdown-divider tw:my-1">
                </li>
                <li>
                    <form action="{{ route('logout') }}" method="POST" data-logout-form>
                        @csrf
                        @if(config('portal_session.logout_all_devices'))
                            <label class="tw:flex tw:items-center tw:gap-2 tw:px-4 tw:py-2 tw:text-ui-xs tw:cursor-pointer" for="logout-all-devices">
                                <input id="logout-all-devices" type="checkbox" name="logout_all" value="1" class="ui-check tw:m-0">
                                <span>{{ __('navigation.logout_all_devices') }}</span>
                            </label>
                        @endif
                        <button type="submit"
                            class="dropdown-item ui-focus-ring tw:text-error tw:flex tw:min-h-9 tw:items-center tw:gap-2 tw:whitespace-nowrap tw:py-1.5 tw:text-ui-xs"
                            data-loading-text="{{ __('navigation.logging_out') }}">
                            <x-ui.icon name="log-out" size="sm" />
                            <span>{{ __('navigation.logout') }}</span>
                        </button>
                    </form>
                </li>
            </ul>
        </div>
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
                container.textContent = @js(__('js.notification.loading'));

                try {
                    const response = await fetch(dropdown.dataset.notificationSummaryUrl, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(@js(__('common.review.failed_notifications')));
                    }

                    const documentFragment = new DOMParser().parseFromString(
                        await response.text(),
                        'text/html'
                    );
                    const panel = documentFragment.body.firstElementChild;

                    if (!panel) {
                        throw new Error(@js(__('common.review.empty_response')));
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
                    window.AdasiDebug?.error(error);
                    container.dataset.notificationSummaryState = 'error';
                    container.textContent = @js(__('js.notification.failed'));
                }
            }

            document.addEventListener('adasi:dropdown-show', function (event) {
                const dropdown = event.target.closest('[data-notification-dropdown]');
                if (dropdown) {
                    loadNotificationSummary(dropdown);
                }
            });

            document.addEventListener('click', function (event) {
                const toggle = event.target.closest('[data-notification-dropdown] [data-ui-dropdown-trigger]');
                if (toggle) {
                    loadNotificationSummary(toggle.closest('[data-notification-dropdown]'));
                }
            });

            function setNotificationBadgeState(badge, unread, total) {
                badge.textContent = unread > 0 ? unread : total;
                ['tw:bg-error', 'tw:text-error-foreground'].forEach((className) => {
                    badge.classList.toggle(className, unread > 0);
                });
                ['tw:border', 'tw:border-outline-variant', 'tw:bg-surface', 'tw:text-on-surface-variant'].forEach((className) => {
                    badge.classList.toggle(className, unread <= 0);
                });
            }

            function updateNotificationUnreadBadge(count) {
                const label = count > 0
                    ? window.AdasiI18n.choice('js.notification.unread_count', Number(count), { count })
                    : window.AdasiI18n.t('js.notification.button_label');

                document.querySelectorAll('.notif-badge').forEach((badge) => {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.classList.remove('tw:hidden');
                    } else {
                        badge.textContent = '0';
                        badge.classList.add('tw:hidden');
                    }

                    badge.closest('button[data-ui-dropdown-trigger]')?.setAttribute('aria-label', label);
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

                    item.classList.remove('tw:bg-primary-container');
                    item.dataset.notificationUnread = '0';
                    item.querySelectorAll('[data-notification-new-badge]').forEach((badge) => badge.remove());
                });
            }

            function markSingleNotificationRead(notificationId) {
                if (!notificationId) {
                    return;
                }

                const selector = `[data-notification-item][data-notification-id="${CSS.escape(String(notificationId))}"]`;
                document.querySelectorAll(selector).forEach((item) => {
                    item.classList.remove('tw:bg-primary-container');
                    item.dataset.notificationUnread = '0';
                    item.querySelectorAll('[data-notification-new-badge]').forEach((badge) => badge.remove());
                });
            }

            function syncNotificationReadState(data, notificationId = null) {
                if (notificationId) {
                    markSingleNotificationRead(notificationId);
                }

                const summaryContainer = document.querySelector('[data-notification-summary-container]');
                if (summaryContainer?.dataset.notificationSummaryState === 'loading') {
                    summaryContainer.dataset.notificationSummaryDirty = 'true';
                }

                if (data && typeof data.count !== 'undefined') {
                    updateNotificationUnreadBadge(Number(data.count || 0));
                }

                if (data?.category_counts) {
                    updateNotificationCategoryBadges(data.category_counts);
                }
            }

            function closeNotificationDropdown(item) {
                const dropdown = item?.closest?.('[data-notification-dropdown]');
                const toggle = dropdown?.querySelector?.('[data-ui-dropdown-trigger]');

                if (toggle && window.AdasiDropdown) {
                    window.AdasiDropdown.hide(toggle);
                } else {
                    window.AdasiDismissDropdowns?.();
                }
            }

            async function openNotificationTarget(rawUrl) {
                if (!rawUrl) {
                    return { navigated: false, inShell: false };
                }

                if (typeof window.AdasiNavigateUrl === 'function') {
                    return window.AdasiNavigateUrl(rawUrl);
                }

                try {
                    const target = new URL(rawUrl, window.location.href);
                    if (!['http:', 'https:'].includes(target.protocol)) {
                        return { navigated: false, inShell: false };
                    }

                    if (
                        window.AdasiWorkspaceShell
                        && typeof window.AdasiWorkspaceShell.openUrl === 'function'
                        && target.origin === window.location.origin
                    ) {
                        window.AdasiWorkspaceShell.openUrl(target.href);
                        return { navigated: true, inShell: true };
                    }

                    window.location.href = target.href;
                    return { navigated: true, inShell: false };
                } catch {
                    return { navigated: false, inShell: false };
                }
            }

            document.addEventListener('adasi:tab-shown', function (event) {
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
                    markButton.textContent = tab.dataset.notificationMarkLabel || @js(__('common.notification.mark_all'));
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
                    const activeTab = dropdown?.querySelector('[data-notification-category].active, [data-notification-category][aria-selected="true"]');
                    const scrollPane = dropdown?.querySelector('.notification-list');
                    const scrollTop = scrollPane?.scrollTop || 0;
                    const category = activeTab?.dataset.notificationCategory || categoryInput?.value || 'all';
                    const button = form.querySelector('[data-notification-mark-button]');
                const originalLabel = button?.textContent || @js(__('common.notification.mark_all'));
                const formData = new FormData(form);
                formData.set('category', category);

                if (categoryInput) {
                    categoryInput.value = category;
                }

                if (button) {
                    if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                        window.AdasiButton.startLoading(button, { text: @js(__('datatables.processing')) });
                    } else {
                        button.disabled = true;
                        button.textContent = @js(__('datatables.processing'));
                    }
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
                        throw new Error(@js(__('common.review.failed_mark_notification')));
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
                    window.AdasiDebug?.error(error);
                } finally {
                    if (button) {
                        if (window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                            window.AdasiButton.stopLoading(button);
                        } else {
                            button.disabled = false;
                            button.textContent = originalLabel;
                        }
                    }
                }
            });

            // Notification item click: POST mark-as-read, then open target in workspace shell or navigate
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

                let shouldResetItem = true;

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

                    window.AdasiSessionSync?.handlePollResponse?.(response);

                    if (response.ok) {
                        const data = await response.json();
                        syncNotificationReadState(data, item.dataset.notificationId || null);

                        if (data.redirect) {
                            const navResult = await openNotificationTarget(data.redirect);
                            if (navResult?.inShell) {
                                closeNotificationDropdown(item);
                            } else {
                                shouldResetItem = false;
                            }
                            return;
                        }
                    }

                    // Fallback: reload only when not inside the multi-workspace shell
                    const inWorkspaceShell = Boolean(window.AdasiWorkspaceShell)
                        || document.documentElement.dataset.workspaceShell === 'true';
                    if (!inWorkspaceShell) {
                        shouldResetItem = false;
                        window.location.reload();
                    }
                } catch (error) {
                    window.AdasiDebug?.error('Failed to mark notification:', error);
                } finally {
                    if (shouldResetItem) {
                        item.dataset.processing = 'false';
                        item.style.opacity = '';
                    }
                }
            });
        </script>
    @endpush
@endonce
