<!DOCTYPE html>
<html lang="en" class="js">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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

    <!-- Custom Overrides -->
    <style>
        /* ── ADASI Full-Screen Loader Overlay ── */
        .adasi-loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(var(--md-scrim-rgb), 0.45);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.2s ease;
        }

        .adasi-loader-overlay.active {
            display: flex;
        }

        .adasi-loader-card {
            background: var(--md-surface);
            border-radius: var(--md-shape-md);
            padding: 24px 32px;
            box-shadow: var(--ui-shadow-2);
            border: 1px solid var(--md-outline-variant);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            animation: loaderFadeIn 0.2s ease-out;
        }

        @keyframes loaderFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .adasi-loader-ring {
            width: 56px;
            height: 56px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .adasi-loader-ring::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid var(--md-surface-container-high);
            border-top-color: var(--md-primary);
            border-right-color: var(--md-error);
            animation: adasiSpin 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }

        .adasi-loader-logo {
            width: 28px;
            height: 28px;
            background-image: url("{{ asset('assets/images/logo-adasi.png') }}");
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            z-index: 2;
        }

        .adasi-loader-text {
            font-size: var(--ui-font-size-sm);
            font-weight: 700;
            color: var(--md-primary);
            letter-spacing: 0.5px;
        }

        @keyframes adasiSpin {
            to { transform: rotate(360deg); }
        }

        /* Hide DataTables default processing indicator */
        div.dataTables_wrapper div.dataTables_processing {
            display: none !important;
        }

        /* Chat Drawer */
        .chat-drawer {
            width: 420px !important;
            max-width: 100vw;
        }

        .chat-drawer .offcanvas-body {
            background-color: var(--md-background);
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 0;
        }

        .chat-drawer-pane {
            display: flex;
            flex: 1;
            flex-direction: column;
            min-height: 0;
        }

        .chat-thread-list,
        .chat-message-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .chat-message-list {
            min-height: 220px;
        }

        .chat-thread-button {
            background: var(--md-surface);
            border: 0;
            border-bottom: 1px solid var(--md-outline-variant);
            color: inherit;
            display: block;
            padding: 0.75rem 1rem;
            text-align: left;
            width: 100%;
            transition: background-color 0.15s ease;
        }

        .chat-thread-button:hover {
            background: var(--md-surface-container);
        }

        .chat-message-row {
            display: flex;
            margin-bottom: 0.75rem;
            width: 100%;
        }

        .chat-message-stack {
            display: flex;
            flex-direction: column;
            max-width: min(75%, 340px);
            min-width: 0;
        }

        .chat-message-bubble {
            border-radius: var(--md-shape-sm);
            display: inline-block;
            padding: 0.5rem 0.75rem;
            word-break: break-word;
            width: fit-content;
            max-width: 100%;
            line-height: 1.4;
            font-size: var(--ui-font-size-sm);
        }

        .chat-message-text {
            white-space: pre-wrap;
        }

        .chat-message-bubble.is-me {
            background: var(--md-primary);
            color: var(--md-on-primary);
            border-bottom-right-radius: 2px;
        }

        .chat-message-bubble.is-partner {
            background: var(--md-surface);
            border: 1px solid var(--ui-surface-border);
            color: var(--md-on-surface);
            border-bottom-left-radius: 2px;
        }

        .chat-message-meta {
            align-items: center;
            color: var(--md-on-surface-variant);
            display: flex;
            font-size: var(--ui-font-size-xs);
            gap: 0.25rem;
            line-height: 1.2;
            margin-top: 0.25rem;
            padding-inline: 0.2rem;
        }

        .chat-message-meta.text-end {
            justify-content: flex-end;
        }

        .chat-message-meta.text-start {
            justify-content: flex-start;
        }

        .chat-context-panel {
            flex: 0 0 auto;
            padding: 0.75rem 1rem !important;
        }

        .chat-context-grid {
            display: grid;
            gap: 0.4rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            max-height: 145px;
            overflow-y: auto;
            font-size: var(--ui-font-size-xs);
        }

        .chat-context-field {
            min-width: 0;
            border: 1px solid var(--ui-surface-border);
            border-radius: var(--md-shape-xs);
            background: var(--md-surface);
            padding: 0.35rem 0.5rem;
        }

        .chat-action-panel,
        .chat-template-strip {
            gap: 0.4rem;
        }

        .chat-action-panel {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 0.4rem !important;
            white-space: nowrap;
        }

        .chat-action-panel .btn {
            flex: 0 0 auto;
        }

        .chat-template-menu {
            max-height: 220px;
            max-width: 340px;
            min-width: 280px;
            overflow-y: auto;
        }

        #chatDrawerInput {
            min-height: 52px;
        }

        .chat-attachment-stack {
            display: grid;
            gap: 0.4rem;
        }

        .chat-attachment-link {
            align-items: center;
            background: rgba(var(--md-on-primary-rgb), 0.16);
            border: 1px solid rgba(var(--md-on-primary-rgb), 0.28);
            border-radius: var(--md-shape-xs);
            color: inherit;
            display: flex;
            max-width: 100%;
            padding: 0.35rem 0.5rem;
            text-decoration: none;
            font-size: var(--ui-font-size-sm);
        }

        .chat-message-bubble.is-partner .chat-attachment-link {
            background: var(--md-surface-container-low);
            border-color: var(--md-outline-variant);
            color: var(--md-primary);
        }

        .chat-read-receipt {
            color: var(--md-outline-strong);
            display: inline-flex;
            font-size: var(--ui-font-size-xs);
            line-height: 1;
            vertical-align: -0.05rem;
        }

        .chat-read-receipt.is-read {
            color: var(--md-primary);
        }

        .chat-fullpage-shell {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            height: calc(100vh - 100px);
            min-height: 600px;
            overflow: hidden;
            width: 100%;
        }

        .chat-fullpage-card {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            background: var(--md-surface);
            border: 1px solid var(--ui-surface-border);
            border-radius: var(--md-shape-md);
        }

        .chat-fullpage-card #chat-messages {
            background: var(--ui-surface-subtle);
            flex: 1 1 auto !important;
            min-height: 0;
            padding: 1rem 1.25rem !important;
        }

        /* Notification Dropdown Panel */
        .notification-dropdown {
            border-radius: var(--md-shape-md);
            box-shadow: var(--ui-shadow-2);
            overflow: hidden;
            padding: 0;
            width: min(720px, calc(100vw - 2rem));
            border: 1px solid var(--md-outline-variant);
            background: var(--md-surface);
        }

        .notification-panel {
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
            height: min(580px, calc(100vh - 100px));
            min-height: 360px;
            overflow: hidden;
        }

        .notification-menu {
            background: var(--md-surface-container-low);
            border-right: 1px solid var(--md-outline-variant);
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            padding: 0.75rem;
        }

        .notification-menu-heading {
            color: var(--md-on-surface-variant);
            font-size: var(--ui-font-size-xs);
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }

        .notification-menu .nav-link {
            align-items: center;
            border-radius: var(--md-shape-xs);
            color: var(--md-on-surface-variant);
            display: flex;
            font-size: var(--ui-font-size-sm);
            font-weight: 500;
            flex: 0 0 auto;
            gap: 0.5rem;
            justify-content: space-between;
            margin-bottom: 0.2rem;
            padding: 0.45rem 0.6rem;
            text-align: left;
            width: 100%;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .notification-menu .nav-link:hover {
            background: var(--md-surface-container);
            color: var(--md-on-surface);
        }

        .notification-menu .nav-link.active {
            background: var(--md-primary-container);
            color: var(--md-primary);
            font-weight: 600;
        }

        .notification-list-pane {
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            overflow: hidden;
        }

        .notification-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .notification-item {
            color: inherit;
            display: block;
            padding: 0.75rem 1rem;
            text-decoration: none;
            border-bottom: 1px solid var(--md-outline-variant);
            transition: background-color 0.15s ease;
        }

        .notification-item:hover {
            background: var(--md-surface-container-low);
        }

        @media (max-width: 991.98px) {
            .chat-drawer {
                width: 100vw !important;
            }

            .notification-panel {
                grid-template-columns: 1fr;
                height: min(580px, calc(100vh - 90px));
            }

            .notification-menu {
                border-bottom: 1px solid var(--md-outline-variant);
                border-right: 0;
                display: flex;
                flex-direction: row;
                gap: 0.25rem;
                overflow-x: auto;
                overflow-y: hidden;
            }

            .notification-menu-heading {
                align-items: center;
                display: flex;
                flex: 0 0 auto;
                margin-bottom: 0;
                margin-right: 0.25rem;
            }

            .notification-menu .nav-link {
                flex: 0 0 auto;
                margin-bottom: 0;
                white-space: nowrap;
                width: auto;
            }
        }
    </style>
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
            $dashboardUrl = \Illuminate\Support\Facades\Route::has($roleDashboardRoute)
                ? route($roleDashboardRoute)
                : route('dashboard');
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
                @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
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
    {{-- Global: Pencegahan Double Submit --}}
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

                // Disable all submit buttons inside the form.
                const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                buttons.forEach(function (btn) {
                    btn.disabled = true;

                    // Save the original text and replace it with a spinner.
                    if (btn.tagName === 'BUTTON') {
                        btn.dataset.originalHtml = btn.innerHTML;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Loading...';
                    }
                });

                // Safety reset after 10 seconds if the request fails or times out.
                setTimeout(function () {
                    form.dataset.submitting = 'false';
                    buttons.forEach(function (btn) {
                        btn.disabled = false;
                        if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml) {
                            btn.innerHTML = btn.dataset.originalHtml;
                        }
                    });
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
