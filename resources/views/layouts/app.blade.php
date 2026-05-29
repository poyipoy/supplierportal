<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ADASI Supplier Portal')</title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">

    <!-- Custom CSS -->
    <style>
        :root {
            --adasi-blue: #1F5FA6;
            --adasi-red: #C0392B;
            --bg-light: #f0f0f0;
            --sidebar-width: 260px;
            --sidebar-width-collapsed: 70px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: #333;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #fff;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }

        .sidebar-brand {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            height: 70px;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-brand {
            padding: 1rem;
            justify-content: center;
        }

        .sidebar.collapsed .brand-text {
            display: none;
        }

        .sidebar-menu {
            padding: 1rem 0;
            overflow-y: auto;
            overflow-x: hidden;
            flex-grow: 1;
        }

        .sidebar-heading {
            padding: 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
            color: #999;
            margin-top: 1rem;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-heading {
            display: none;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #555;
            text-decoration: none;
            transition: all 0.2s;
            font-weight: 500;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-link {
            padding: 0.75rem 0;
            justify-content: center;
        }

        .sidebar-link:hover {
            background-color: #f8f9fa;
            color: var(--adasi-blue);
        }

        .sidebar-link i {
            margin-right: 10px;
            font-size: 1.2rem;
            color: #888;
            transition: color 0.2s;
            min-width: 24px;
            text-align: center;
        }

        .sidebar.collapsed .sidebar-link i {
            margin-right: 0;
        }

        .sidebar-link span {
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .sidebar-link span {
            display: none;
        }

        .sidebar-link:hover i {
            color: var(--adasi-blue);
        }

        .sidebar-link.active {
            background-color: rgba(31, 95, 166, 0.05);
            color: var(--adasi-blue);
            border-right: 3px solid var(--adasi-blue);
        }

        .sidebar-link.active i {
            color: var(--adasi-blue);
        }

        /* Main Content */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .main-wrapper.expanded {
            margin-left: var(--sidebar-width-collapsed);
        }

        /* Top Navbar */
        .top-navbar {
            background-color: #fff;
            height: 70px;
            padding: 0 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .content-area {
            padding: 2rem;
            flex-grow: 1;
        }

        /* Role Badge */
        .role-badge {
            padding: 0.35rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .role-badge-admin {
            background-color: #e0e7ff;
            color: #3730a3;
        }

        .role-badge-purchasing {
            background-color: #dcfce7;
            color: #166534;
        }

        .role-badge-supplier {
            background-color: #fef08a;
            color: #854d0e;
        }

        .role-badge-qc {
            background-color: #fee2e2;
            color: #991b1b;
        }

        /* Global Table Styling */
        .table {
            border: 1px solid #cbd5e1 !important; /* Slate-300 untuk border lebih tegas */
            border-collapse: collapse !important;
            background-color: #fff;
        }

        .table thead th {
            background-color: #f1f5f9 !important; /* Slate-100 */
            border-bottom: 2px solid #94a3b8 !important; /* Slate-400 */
            color: #1e293b !important; /* Slate-800 */
            font-weight: 700 !important;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.025em;
        }

        .table td, .table th {
            border: 1px solid #cbd5e1 !important; /* Slate-300 */
            vertical-align: middle !important;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8fafc !important; /* Slate-50 */
        }

        .table-hover tbody tr:hover {
            background-color: rgba(31, 95, 166, 0.05) !important;
        }

        /* DataTables Adjustment */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--adasi-blue) !important;
            color: white !important;
            border: 1px solid var(--adasi-blue) !important;
        }

        /* Chat Drawer */
        .chat-drawer {
            width: 430px !important;
            max-width: 100vw;
        }

        .chat-drawer .offcanvas-body {
            background-color: #f4f7f6;
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

        .chat-thread-button {
            background: #fff;
            border: 0;
            border-bottom: 1px solid #e2e8f0;
            color: inherit;
            display: block;
            padding: 0.9rem 1rem;
            text-align: left;
            width: 100%;
        }

        .chat-thread-button:hover {
            background: rgba(31, 95, 166, 0.06);
        }

        .chat-message-bubble {
            border-radius: 0.9rem;
            max-width: 82%;
            padding: 0.75rem 0.9rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .chat-message-bubble.is-me {
            background: var(--adasi-blue);
            color: #fff;
        }

        .chat-message-bubble.is-partner {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .notification-dropdown {
            border: 0;
            border-radius: 0.75rem;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.16);
            overflow: hidden;
            padding: 0;
            width: min(780px, calc(100vw - 2rem));
        }

        .notification-panel {
            display: grid;
            grid-template-columns: 210px minmax(0, 1fr);
            height: min(620px, calc(100vh - 110px));
            min-height: 380px;
            overflow: hidden;
        }

        .notification-menu {
            background: #f8fafc;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            padding: 0.85rem;
        }

        .notification-menu-heading {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 0.65rem;
            text-transform: uppercase;
        }

        .notification-menu .nav-link {
            align-items: center;
            border-radius: 0.55rem;
            color: #475569;
            display: flex;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 0 0 auto;
            gap: 0.5rem;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            padding: 0.55rem 0.65rem;
            text-align: left;
            width: 100%;
        }

        .notification-menu .nav-link.active {
            background: rgba(31, 95, 166, 0.1);
            color: var(--adasi-blue);
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
            padding: 0.85rem 1rem;
            text-decoration: none;
        }

        .notification-item:hover {
            background: rgba(31, 95, 166, 0.05);
        }

        .min-w-0 {
            min-width: 0;
        }

        .notification-page-menu {
            background: #f8fafc;
            border-radius: 0.75rem;
            padding: 0.85rem;
        }

        .notification-page-menu .list-group-item {
            align-items: center;
            border: 0;
            border-radius: 0.55rem;
            color: #475569;
            display: flex;
            font-size: 0.88rem;
            font-weight: 600;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            padding: 0.7rem 0.75rem;
        }

        .notification-page-menu .list-group-item.active {
            background: rgba(31, 95, 166, 0.1);
            color: var(--adasi-blue);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width) !important;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .content-area {
                padding: 1.5rem;
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100vh;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
            }

            .sidebar-overlay.show {
                display: block;
            }

            .chat-drawer {
                width: 100vw !important;
            }

            .notification-panel {
                grid-template-columns: 1fr;
                height: min(620px, calc(100vh - 95px));
            }

            .notification-menu {
                border-bottom: 1px solid #e2e8f0;
                border-right: 0;
                display: flex;
                flex-direction: row;
                gap: 0.35rem;
                overflow-x: auto;
                overflow-y: hidden;
            }

            .notification-menu-heading {
                align-items: center;
                display: flex;
                flex: 0 0 auto;
                margin-bottom: 0;
                margin-right: 0.35rem;
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

<body>
    @php
        $initNotifCount = 0;
        $initChatCount = 0;
        if(auth()->check()) {
            $initNotifCount = auth()->user()->unreadNotifications->count();
            if(in_array(auth()->user()->role, ['purchasing', 'supplier'])) {
                $initChatCount = \App\Models\Conversation::forUser(auth()->id())
                    ->withCount(['messages' => function($q) {
                        $q->where('sender_id', '!=', auth()->id())->whereNull('read_at');
                    }])
                    ->get()
                    ->sum('messages_count');
            }
        }
    @endphp
    {{-- Sidebar --}}
    @include('partials.sidebar')

    {{-- Mobile Overlay --}}
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    {{-- Main Wrapper --}}
    <div class="main-wrapper" id="mainWrapper">
        {{-- Navbar --}}
        @include('partials.navbar')

        {{-- Content Area --}}
        <div class="content-area">
            @include('partials.alerts')
            @yield('content')
        </div>
    </div>

    @include('partials.chat-drawer')

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>

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
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainWrapper = document.getElementById('mainWrapper');
            const overlay = document.getElementById('sidebarOverlay');

            if (window.innerWidth > 992) {
                // Desktop: Toggle collapsed state
                sidebar.classList.toggle('collapsed');
                mainWrapper.classList.toggle('expanded');

                // Save preference to localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            } else {
                // Mobile: Toggle slide-in menu
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            }
        }

        // Apply saved preference on load
        document.addEventListener('DOMContentLoaded', () => {
            if (window.innerWidth > 992) {
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    document.getElementById('sidebar').classList.add('collapsed');
                    document.getElementById('mainWrapper').classList.add('expanded');
                }
            }
        });

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
                    });

                // Chat badge
                @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
                    fetch("{{ route('conversations.unread-count') }}")
                        .then(r => r.json())
                        .then(data => {
                            document.querySelectorAll('.chat-badge').forEach(badge => {
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

                // Skip jika form sudah di-tag submitting
                if (form.dataset.submitting === 'true') {
                    e.preventDefault();
                    return;
                }

                form.dataset.submitting = 'true';

                // Disable semua tombol submit di dalam form
                const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                buttons.forEach(function (btn) {
                    btn.disabled = true;

                    // Simpan teks asli & ganti dengan spinner
                    if (btn.tagName === 'BUTTON') {
                        btn.dataset.originalHtml = btn.innerHTML;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
                    }
                });

                // Safety reset setelah 10 detik (jika request gagal/timeout)
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
    @stack('scripts')
</body>

</html>
