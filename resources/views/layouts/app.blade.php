<!DOCTYPE html>
<html lang="en" class="js">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    <title>@yield('title', 'ADASI Supplier Portal')</title>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    @hasSection('uses-datatables')
        <!-- DataTables CSS (only on pages that initialize a DataTable) -->
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    @endif

    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">

    <!-- ADASI Alert Theme -->
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">

    <script>
        (() => {
            const desktop = window.matchMedia('(min-width: 992px)').matches;
            let collapsed = false;

            try {
                collapsed = desktop && window.localStorage.getItem('sidebarCollapsed') === 'true';
            } catch (error) {
                collapsed = false;
            }

            window.__adasiSidebarInitialCollapsed = collapsed;
            document.documentElement.dataset.sidebarCollapsed = collapsed ? 'true' : 'false';
        })();
    </script>

    <!-- Tailwind design foundation + Alpine entry (hybrid compatibility phase) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>

<body
    x-data="adasiShell"
    x-on:ui-sidebar-toggle.window="toggleSidebar($event.detail?.trigger)"
    x-on:keydown.escape.window="closeMobileSidebar()"
    x-on:keydown.tab.window="trapSidebarFocus($event)"
    x-effect="document.body.classList.toggle('ui-nav-open', mobileOpen)"
>
    <a href="#main-content" class="ui-skip-link">Skip to main content</a>
    @php
        $initNotifCount = 0;
        $initChatCount = 0;
        $dashboardUrl = url('/');
        if (auth()->check()) {
            $roleDashboardRoute = auth()->user()->role
                ? auth()->user()->role . '.dashboard'
                : 'dashboard';
            $dashboardUrl = \App\Support\PortalContext::dashboard(auth()->user());
        }
    @endphp
    {{-- Sidebar --}}
    @include('partials.sidebar')

    {{-- Mobile Overlay --}}
    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
        x-bind:class="{ 'show': mobileOpen }"
        x-on:click="closeMobileSidebar()"
        aria-hidden="true"
    ></div>

    {{-- Main Wrapper --}}
    <div class="main-wrapper" id="mainWrapper" x-bind:class="{ 'expanded': desktopCollapsed }">
        {{-- Navbar --}}
        @include('partials.navbar')

        {{-- Content Area --}}
        <main class="content-area" id="main-content" tabindex="-1">
            <div class="content-container">
                @include('partials.alerts')
                @yield('content')
            </div>
        </main>
    </div>

    @include('partials.chat-drawer')
    <x-ui.toast-container context="app" />
    <x-ui.image-lightbox />

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        window.initAdasiTooltips = function (root = document) {
            if (!window.bootstrap?.Tooltip) {
                return;
            }

            root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
                bootstrap.Tooltip.getOrCreateInstance(element);
            });
        };

        document.addEventListener('DOMContentLoaded', () => window.initAdasiTooltips());
    </script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    @hasSection('uses-datatables')
        <!-- DataTables JS (only on pages that initialize a DataTable) -->
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    @endif
    <script>
        // ADASI Loader — Inject overlay ke body
        const isDataTableRequest = (options = {}) => {
            const data = options.data;

            if (data && typeof data === 'object') {
                return Object.prototype.hasOwnProperty.call(data, 'draw');
            }

            return typeof data === 'string' && /(?:^|&)draw(?:=|%5B|\[)/i.test(data);
        };

        $.ajaxPrefilter((options) => {
            if (isDataTableRequest(options)) {
                options.global = false;
            }
        });

        $(function () {
            // Create the loading overlay once.
            $('body').append(
                '<div class="adasi-loader-overlay" id="adasiLoader">' +
                '<div class="adasi-loader-card">' +
                '<div class="adasi-loader-ring">' +
                '<div class="adasi-loader-logo"></div>' +
                '</div>' +
                '<span class="adasi-loader-text">Loading...</span>' +
                '</div>' +
                '</div>'
            );

            // Show when an AJAX request starts, including DataTables requests.
            $(document).ajaxStart(function () {
                $('#adasiLoader').addClass('active');
            });

            // Hide when the AJAX request completes.
            $(document).ajaxStop(function () {
                $('#adasiLoader').removeClass('active');
            });
        });
    </script>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>

    <!-- Ngrok browser warning bypass for internal async requests -->
    <script>
        (() => {
            const headerName = 'ngrok-skip-browser-warning';
            const headerValue = 'true';

            const isInternalUrl = (url) => {
                try {
                    return new URL(url, window.location.href).origin === window.location.origin;
                } catch (error) {
                    return false;
                }
            };

            const mergeHeaders = (...headerSets) => {
                const headers = new Headers();

                headerSets
                    .filter(Boolean)
                    .forEach((headerSet) => {
                        new Headers(headerSet).forEach((value, key) => {
                            headers.set(key, value);
                        });
                    });

                if (!headers.has(headerName)) {
                    headers.set(headerName, headerValue);
                }

                return headers;
            };

            if (window.fetch) {
                const originalFetch = window.fetch.bind(window);

                window.fetch = (input, init = {}) => {
                    const targetUrl = input instanceof Request ? input.url : input;

                    if (!isInternalUrl(targetUrl)) {
                        return originalFetch(input, init);
                    }

                    return originalFetch(input, {
                        ...init,
                        headers: mergeHeaders(input instanceof Request ? input.headers : null, init.headers),
                    });
                };
            }

            if (window.jQuery) {
                $.ajaxPrefilter((options, originalOptions, jqXHR) => {
                    if (isInternalUrl(options.url || window.location.href)) {
                        jqXHR.setRequestHeader(headerName, headerValue);
                    }
                });
            }
        })();
    </script>

    <!-- Custom JS -->
    <script>
        @auth
            function updateBadges() {
                // Notification badge
                fetch("{{ route('notifications.unread-count') }}")
                    .then(r => r.json())
                    .then(data => {
                        document.querySelectorAll('.notif-badge').forEach(badge => {
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.classList.remove('d-none');
                            } else {
                                badge.classList.add('d-none');
                            }
                        });

                        if (typeof updateNotificationCategoryBadges === 'function') {
                            updateNotificationCategoryBadges(data.category_counts);
                        }
                    });

                // Chat badge
                @if((auth()->user()->isPurchasing() || (auth()->user()->hasSupplierScope('import') && ! \App\Support\PortalContext::isLocal(auth()->user()))))
                    fetch("{{ route('conversations.unread-count') }}")
                        .then(r => r.json())
                        .then(data => {
                            document.querySelectorAll('.chat-badge').forEach(badge => {
                                badge.setAttribute('aria-label', `Unread conversations: ${data.count}`);
                                const sidebarLink = badge.closest('.sidebar-link');
                                if (sidebarLink) {
                                    sidebarLink.setAttribute(
                                        'aria-label',
                                        data.count > 0
                                            ? `Negotiation and Chat, ${data.count} unread conversations`
                                            : 'Negotiation and Chat'
                                    );
                                }
                                if (data.count > 0) {
                                    badge.textContent = data.count;
                                    badge.classList.remove('d-none');
                                } else {
                                    badge.classList.add('d-none');
                                }
                            });
                        });
                @endif
                }

            // Run immediately on load
            updateBadges();

            // Polling every 30 seconds
            setInterval(updateBadges, 30000);
        @endauth
    </script>
    @auth
        @if(auth()->user()->role === 'purchasing')
            <script>
                document.addEventListener('click', (event) => {
                    const link = event.target.closest('a[href]');

                    if (!link || event.defaultPrevented || event.button !== 0) {
                        return;
                    }

                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    if (
                        link.target && link.target !== '_self'
                        || link.hasAttribute('download')
                        || link.closest('.sidebar-menu')
                        || link.hasAttribute('data-chat-drawer')
                        || link.hasAttribute('data-open-chat-conversation')
                        || link.hasAttribute('data-bs-toggle')
                    ) {
                        return;
                    }

                    const href = link.getAttribute('href');

                    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                        return;
                    }

                    const targetUrl = new URL(href, window.location.origin);
                    const listPaths = new Set(@json(\App\Support\PurchasingNavigation::listRoutePaths()));

                    if (
                        targetUrl.origin !== window.location.origin
                        || !targetUrl.pathname.startsWith('/purchasing/')
                        || targetUrl.pathname.startsWith('/purchasing/export/')
                        || listPaths.has(targetUrl.pathname)
                        || targetUrl.searchParams.has('return_url')
                    ) {
                        return;
                    }

                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete('return_url');
                    targetUrl.searchParams.set('return_url', currentUrl.toString());
                    link.href = targetUrl.toString();
                }, true);
            </script>
        @endif
    @endauth
    {{-- Global: Pencegahan Double Submit & Single-Button Spinner --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!(form instanceof HTMLFormElement)) return;

                // AJAX forms manage their own loading state and duplicate-submit guard.
                if (form.hasAttribute('data-managed-submit')) return;

                // Skip forms already marked as submitting.
                if (form.dataset.submitting === 'true') {
                    e.preventDefault();
                    return;
                }

                form.dataset.submitting = 'true';

                // Disabled submit controls are omitted from the request payload.
                // Preserve the clicked button's name/value before disabling all
                // submit buttons so named actions (for example generate_pos or
                // submit) still reach the server.
                const submitter = e.submitter;
                let submitterMirror = null;
                if (submitter && submitter.name) {
                    const previousMirror = form.querySelector('[data-submit-submitter-mirror]');
                    if (previousMirror) previousMirror.remove();

                    submitterMirror = document.createElement('input');
                    submitterMirror.type = 'hidden';
                    submitterMirror.name = submitter.name;
                    submitterMirror.value = submitter.value;
                    submitterMirror.setAttribute('data-submit-submitter-mirror', 'true');
                    form.appendChild(submitterMirror);
                }

                // Apply loading state ONLY to the clicked submitter button.
                const activeSubmitter = submitter || form._lastClickedButton;
                if (activeSubmitter && !activeSubmitter.hasAttribute('data-no-auto-spinner')) {
                    if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                        window.AdasiButton.startLoading(activeSubmitter);
                    } else if (activeSubmitter.tagName === 'BUTTON') {
                        activeSubmitter.dataset.originalHtml = activeSubmitter.innerHTML;
                        const icon = activeSubmitter.querySelector('.ui-icon');
                        const spinner = document.createElement('span');
                        spinner.className = 'ui-spinner';
                        spinner.setAttribute('aria-hidden', 'true');
                        if (icon && icon.parentNode) {
                            icon.style.display = 'none';
                            icon.parentNode.insertBefore(spinner, icon);
                        } else {
                            spinner.classList.add('tw-mr-1.5');
                            activeSubmitter.prepend(spinner);
                        }
                    }
                }

                // Disable all submit buttons inside the form. Sibling buttons retain their original markup.
                if (window.AdasiButton && typeof window.AdasiButton.disableSiblingButtons === 'function') {
                    window.AdasiButton.disableSiblingButtons(form, activeSubmitter);
                }
                const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                buttons.forEach(function (btn) {
                    btn.disabled = true;
                });

                // Safety reset after 10 seconds if the request fails or times out.
                setTimeout(function () {
                    form.dataset.submitting = 'false';
                    if (window.AdasiButton && typeof window.AdasiButton.resetForm === 'function') {
                        window.AdasiButton.resetForm(form);
                    } else {
                        buttons.forEach(function (btn) {
                            btn.disabled = false;
                            if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml) {
                                btn.innerHTML = btn.dataset.originalHtml;
                                delete btn.dataset.originalHtml;
                            }
                        });
                    }
                    if (submitterMirror) submitterMirror.remove();
                }, 10000);
            });
        });
    </script>

    {{-- Async export: server-side generation, automatic download, no page refresh. --}}
    <script src="{{ asset('assets/js/async-export.js') }}?v={{ file_exists(public_path('assets/js/async-export.js')) ? filemtime(public_path('assets/js/async-export.js')) : '1' }}"></script>

    {{-- Shared PDF preview script. --}}
    <script>
        document.addEventListener('click', function (e) {
            const pdfBtn = e.target.closest('a[href*="/pdf/"][data-pdf-confirm]');
            if (!pdfBtn || e.defaultPrevented || e.button !== 0) {
                return;
            }

            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();

            if (window.pdfConfirmationOpen) {
                return;
            }

            window.pdfConfirmationOpen = true;

            AdasiAlert.confirm({
                title: 'Download PDF Document?',
                text: 'The PDF document will be downloaded. Do you want to continue?',
                confirmText: 'Yes, Download',
                cancelText: 'Cancel'
            }).then((result) => {
                window.pdfConfirmationOpen = false;

                if (!result.isConfirmed) {
                    return;
                }

                const target = pdfBtn.getAttribute('target');
                if (target === '_blank') {
                    window.open(pdfBtn.href, '_blank', 'noopener,noreferrer');
                } else {
                    window.location.href = pdfBtn.href;
                }
            }).catch(() => {
                window.pdfConfirmationOpen = false;
            });
        });
    </script>

    {{-- Shared Excel export preview script. --}}
    <script>
        document.addEventListener('click', function (e) {
            const exportBtn = e.target.closest('a[href*="/export/"][data-export-confirm]');
            if (!exportBtn || e.defaultPrevented || e.button !== 0) {
                return;
            }

            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();

            if (window.exportConfirmationOpen) {
                return;
            }

            window.exportConfirmationOpen = true;

            let recordsTotal = 'all';

            AdasiAlert.confirm({
                title: 'Export Data to Excel?',
                text: 'The data will be exported based on current filters. Do you want to continue?',
                confirmText: 'Yes, Export',
                cancelText: 'Cancel'
            }).then((result) => {
                window.exportConfirmationOpen = false;

                if (!result.isConfirmed) {
                    return;
                }

                window.location.href = exportBtn.href;
            }).catch(() => {
                window.exportConfirmationOpen = false;
            });
        });
    </script>

    {{-- Keyboard Shortcuts --}}
    <script>
        document.addEventListener('keydown', function (e) {
            // Ignore shortcuts while focus is inside a form field.
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                return;
            }

            // Alt + D -> Dashboard
            if (e.altKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                window.location.href = @json($dashboardUrl);
            }

            // ? -> Modal Shortcut
            if (e.key === '?') {
                e.preventDefault();
                AdasiAlert.info({
                    title: 'Keyboard Shortcuts',
                    html: `
                        <div class="text-start">
                            <table class="table table-borderless table-sm mb-0">
                                <tr>
                                    <td width="40%"><kbd>Alt + D</kbd></td>
                                    <td>Back to Dashboard</td>
                                </tr>
                                <tr>
                                    <td><kbd>?</kbd></td>
                                    <td>Open This Help</td>
                                </tr>
                            </table>
                        </div>
                    `,
                    confirmText: 'Close'
                });
            }
        });
    </script>

    @php
    $pusherClient = config('broadcasting.connections.pusher', []);
    $pusherOptions = $pusherClient['options'] ?? [];

    $pusherClientReady = config('broadcasting.default') === 'pusher'
        && filled($pusherClient['key'] ?? null)
        && filled($pusherOptions['cluster'] ?? null);
@endphp

@auth
    @if($pusherClientReady)
        <!-- Laravel Echo + Pusher Channels -->
        <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.19.0/dist/echo.iife.js"></script>

        <script>
            (() => {
                const csrfToken =
                    document.querySelector('meta[name="csrf-token"]')?.content;

                const userId =
                    document.querySelector('meta[name="user-id"]')?.content;

                const readUrlBase =
                    @json(url('/notifications/__NOTIFICATION_ID__/read'));

                const allowedCategories = new Set(
                    @json(array_keys(\App\Support\NotificationCategory::options()))
                );

                if (!window.Echo || !window.Pusher || !csrfToken || !userId) {
                    return;
                }

                const readUrlFor = (id) => id
                    ? readUrlBase.replace(
                        '__NOTIFICATION_ID__',
                        encodeURIComponent(String(id))
                    )
                    : null;

                const markReadAndRedirect = async (readUrl) => {
                    if (!readUrl) return;

                    try {
                        const response = await fetch(readUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const data = response.ok
                            ? await response.json()
                            : null;

                        if (data?.redirect) {
                            window.location.href = data.redirect;
                        }
                    } catch (error) {
                        // Polling remains the fallback delivery path.
                    }
                };

                const createNotificationItem = (
                    notification,
                    category,
                    readUrl
                ) => {
                    const item = document.createElement('div');

                    item.className = 'notification-item bg-light';
                    item.setAttribute('role', 'button');
                    item.dataset.notificationItem = '';
                    item.dataset.notificationCategory = category;
                    item.dataset.notificationUnread = '1';
                    item.dataset.notificationReadUrl = readUrl;
                    item.dataset.notificationId = String(notification.id);
                    item.style.cursor = 'pointer';

                    const row = document.createElement('div');
                    row.className = 'd-flex gap-3';

                    const categoryIcon = document.querySelector(
                        `[data-notification-category="${category}"] .ui-icon`
                    );
                    const icon = categoryIcon?.cloneNode(true)
                        || document.querySelector('.notification-menu-heading .ui-icon')?.cloneNode(true);

                    if (icon) {
                        icon.classList.add('text-primary', 'flex-shrink-0', 'mt-1');
                        icon.setAttribute('width', '20');
                        icon.setAttribute('height', '20');
                    }

                    const content =
                        document.createElement('div');

                    content.className =
                        'tw-min-w-0 flex-grow-1';

                    const heading =
                        document.createElement('div');

                    heading.className =
                        'd-flex justify-content-between gap-2';

                    const title =
                        document.createElement('div');

                    title.className =
                        'fw-semibold small text-truncate';

                    title.textContent =
                        String(
                            notification.title ||
                            'Notification'
                        );

                    const newBadge =
                        document.createElement('span');

                    newBadge.className =
                        'ui-status-chip ui-status-chip--error flex-shrink-0';
                    newBadge.dataset.notificationNewBadge = '';
                    newBadge.textContent = 'New';

                    heading.append(title, newBadge);

                    const message =
                        document.createElement('div');

                    message.className = 'text-muted small';

                    message.textContent =
                        String(
                            notification.message || '-'
                        );

                    const time =
                        document.createElement('div');

                    time.className =
                        'text-muted mt-2 small';
                    time.textContent = 'Just now';

                    content.append(
                        heading,
                        message,
                        time
                    );

                    if (icon) row.append(icon);
                    row.append(content);
                    item.append(row);

                    return item;
                };

                const insertNotification = (
                    notification,
                    readUrl
                ) => {
                    const summaryContainer = document.querySelector(
                        '[data-notification-summary-container]'
                    );

                    if (
                        summaryContainer?.dataset.notificationSummaryState
                        === 'loading'
                    ) {
                        summaryContainer.dataset.notificationSummaryDirty = 'true';
                    }

                    if (!document.querySelector('#notif-pane-all')) {
                        return;
                    }

                    const category =
                        allowedCategories.has(
                            notification.category
                        ) &&
                        notification.category !== 'all'
                            ? notification.category
                            : 'other';

                    ['all', category].forEach(
                        (paneCategory) => {
                            const pane =
                                document.querySelector(
                                    `#notif-pane-${paneCategory}`
                                );

                            if (
                                !pane ||
                                pane.querySelector(
                                    `[data-notification-id="${CSS.escape(
                                        String(notification.id)
                                    )}"]`
                                )
                            ) {
                                return;
                            }

                            pane
                                .querySelector(
                                    '.text-center.text-muted.py-5'
                                )
                                ?.remove();

                            pane.prepend(
                                createNotificationItem(
                                    notification,
                                    category,
                                    readUrl
                                )
                            );
                        }
                    );
                };

                const isExportLifecycleNotification = (
                    notification
                ) => [
                    'export.completed',
                    'export.failed',
                ].includes(notification.event) &&
                    Boolean(notification.export_job_id);

                const shouldSuppressTransientNotification = (
                    notification
                ) => Boolean(
                    window.AdasiAsyncExport
                        ?.isTrackingNotification?.(notification)
                );

                const showTransientNotification = (
                    notification,
                    readUrl
                ) => {
                    if (shouldSuppressTransientNotification(notification)) {
                        return;
                    }

                    if (!window.AdasiToast) return;

                    AdasiToast.show({
                        type: 'message',
                        title:
                            notification.title ||
                            'New Notification',
                        message:
                            notification.message ||
                            '',
                        timestamp: 'Just now',
                        icon:
                            notification.icon ||
                            'bell',
                        actions: [
                            {
                                label: 'Dismiss',
                                variant: 'secondary',
                            },
                            {
                                label: 'View',
                                variant: 'primary',
                                onClick: () =>
                                    markReadAndRedirect(
                                        readUrl
                                    ),
                            },
                        ],
                    });
                };

                const deliverTransientNotification = (
                    notification,
                    readUrl
                ) => {
                    if (isExportLifecycleNotification(notification)) {
                        window.setTimeout(
                            () => showTransientNotification(
                                notification,
                                readUrl
                            ),
                            750
                        );
                        return;
                    }

                    showTransientNotification(notification, readUrl);
                };

                try {
                    window.Echo = new Echo({
                        broadcaster: 'pusher',

                        key: @json($pusherClient['key']),

                        cluster: @json(
                            $pusherOptions['cluster']
                        ),

                        forceTLS: true,

                        enabledTransports: [
                            'ws',
                            'wss'
                        ],

                        authEndpoint:
                            '/broadcasting/auth',

                        auth: {
                            headers: {
                                'X-CSRF-TOKEN':
                                    csrfToken
                            }
                        }
                    });

                    const userChannel = window.Echo.private(
                        'App.Models.User.' + userId
                    );

                    userChannel.notification(
                            (notification) => {
                                const readUrl =
                                    readUrlFor(
                                        notification.id
                                    );

                                if (!readUrl) return;

                                insertNotification(
                                    notification,
                                    readUrl
                                );

                                updateBadges();
                                deliverTransientNotification(
                                    notification,
                                    readUrl
                                );
                            }
                        );

                    userChannel.listen(
                        '.export.progress',
                        (progress) => window.AdasiAsyncExport
                            ?.handleProgress?.(progress)
                    );

                } catch (error) {
                    console.error(
                        'Pusher/Echo initialization failed:',
                        error
                    );

                    window.Echo = null;
                }
            })();
        </script>
    @endif
@endauth

    @stack('scripts')
</body>

</html>
