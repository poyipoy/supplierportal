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

        /* Micro-animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .card { transition: box-shadow 0.2s ease, transform 0.2s ease; }
        .card:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08) !important; }

        /* Action button consistency in tables */
        .table .btn {
            padding: 0.25rem 0.6rem;
            font-size: 0.85rem;
            border-radius: 4px;
        }
        .table .btn i { margin-right: 0.2rem; }

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
            animation: fadeInContent 0.25s ease-out;
        }
        @keyframes fadeInContent {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
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

        /* Sticky Table Header — keeps column context visible when scrolling */
        .table-responsive {
            max-height: none; /* allow natural scroll */
        }
        .table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
        }
        /* When table is inside content-area (below sticky navbar), offset for navbar */
        .content-area .card .table thead th {
            top: 0; /* relative to card scroll container */
        }

        /* Action button sizing — consistent minimum touch target */
        .table .btn-sm {
            padding: 0.3rem 0.55rem;
            font-size: 0.78rem;
            min-width: 32px;
            min-height: 30px;
        }

        /* Clickable KPI cards */
        a.kpi-card {
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        a.kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(31, 95, 166, 0.12) !important;
        }
        a.kpi-card .kpi-arrow {
            opacity: 0;
            transition: opacity 0.15s ease;
        }
        a.kpi-card:hover .kpi-arrow {
            opacity: 1;
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

        .chat-message-list {
            min-height: 220px;
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

        .chat-message-row {
            display: flex;
            margin-bottom: 0.85rem;
            width: 100%;
        }

        .chat-message-stack {
            display: flex;
            flex-direction: column;
            max-width: min(72%, 320px);
            min-width: 0;
        }

        .chat-message-bubble {
            border-radius: 0.72rem;
            display: inline-block;
            padding: 0.52rem 0.68rem;
            word-break: break-word;
            width: fit-content;
            max-width: 100%;
            line-height: 1.35;
            font-size: 0.92rem;
        }

        .chat-message-text {
            white-space: pre-wrap;
        }

        .chat-message-bubble.is-me {
            background: var(--adasi-blue);
            color: #fff;
            border-bottom-right-radius: 0.28rem;
        }

        .chat-message-bubble.is-partner {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            border-bottom-left-radius: 0.28rem;
        }

        .chat-message-meta {
            align-items: center;
            color: #64748b;
            display: flex;
            font-size: 0.72rem;
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
            font-size: 0.78rem;
        }

        .chat-context-field {
            min-width: 0;
            border: 1px solid #e2e8f0;
            border-radius: 0.45rem;
            background: #f8fafc;
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

        .chat-composer-tools {
            min-height: 32px;
        }

        #chatDrawerInput {
            min-height: 58px;
        }

        .chat-attachment-stack {
            display: grid;
            gap: 0.4rem;
        }

        .chat-attachment-link {
            align-items: center;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 0.45rem;
            color: inherit;
            display: flex;
            max-width: 100%;
            padding: 0.42rem 0.55rem;
            text-decoration: none;
        }

        .chat-message-bubble.is-partner .chat-attachment-link {
            background: #f8fafc;
            border-color: #dbe3ec;
            color: var(--adasi-blue);
        }

        .chat-read-receipt {
            color: #94a3b8;
            display: inline-flex;
            font-size: 0.82rem;
            line-height: 1;
            vertical-align: -0.08rem;
        }

        .chat-read-receipt.is-read {
            color: #3b82f6;
        }

        .chat-fullpage-shell {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            height: calc(100vh - 105px);
            min-height: 620px;
            overflow: hidden;
            width: 100%;
        }

        .chat-fullpage-back,
        .chat-fullpage-context {
            flex: 0 0 auto;
        }

        .chat-fullpage-context .card-body {
            padding: 0.65rem 1rem;
        }

        .chat-fullpage-context-details summary {
            cursor: pointer;
            list-style: none;
            width: fit-content;
        }

        .chat-fullpage-context-details summary::-webkit-details-marker {
            display: none;
        }

        .chat-fullpage-context-details summary::after {
            content: " ▾";
            font-size: 0.75rem;
        }

        .chat-fullpage-context-details[open] summary::after {
            content: " ▴";
        }

        .chat-fullpage-context-details .row.g-2 {
            max-height: 96px;
            overflow-y: auto;
        }

        .chat-fullpage-context:not(:has(.chat-fullpage-context-details[open])) {
            overflow: visible;
        }

        .chat-fullpage-card {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
        }

        .chat-fullpage-card .card-header,
        .chat-fullpage-card .card-footer {
            flex: 0 0 auto;
        }

        .chat-fullpage-card .card-header {
            min-height: 58px;
            padding: 0.55rem 1rem !important;
        }

        .chat-fullpage-card .card-footer {
            padding: 0.6rem 1rem !important;
        }

        .chat-fullpage-card #chat-messages {
            flex: 1 1 auto !important;
            min-height: 0;
            padding: 1.25rem 1.5rem !important;
        }

        .chat-fullpage-avatar {
            height: 36px;
            width: 36px;
        }

        .chat-fullpage-avatar i {
            font-size: 1.1rem !important;
        }

        .chat-fullpage-card .chat-message-stack {
            max-width: min(88%, 1180px);
        }

        .chat-fullpage-card .chat-message-bubble {
            font-size: 0.96rem;
            line-height: 1.42;
        }

        .chat-fullpage-card #message-body {
            min-height: 46px;
            max-height: 92px;
            flex: 1 1 auto;
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
            $initNotifCount = auth()->user()->unreadNotifications()->count();
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

    <script>
        window.initAdasiTooltips = function(root = document) {
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

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script>
        // SweetAlert2 global defaults: keyboard-friendly confirmations
        if (window.Swal) {
            const SwalDefault = Swal.mixin({
                focusConfirm: true,
                reverseButtons: true,
            });
            window.Swal = SwalDefault;
        }
    </script>

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

    {{-- Global Script untuk Export Preview --}}
    <script>
        document.addEventListener('click', function(e) {
            const exportBtn = e.target.closest('a[href*="/export/"]');
            if (exportBtn) {
                e.preventDefault();
                
                let recordsTotal = 'seluruh';
                if (typeof $ !== 'undefined' && $.fn.dataTable) {
                    const tables = $.fn.dataTable.tables(true);
                    if (tables.length > 0) {
                        const info = $(tables[0]).DataTable().page.info();
                        recordsTotal = info.recordsTotal;
                    }
                }
                
                Swal.fire({
                    title: 'Konfirmasi Export',
                    html: `Anda akan mengekspor <strong>${recordsTotal}</strong> baris data ke Excel.<br>Proses ini mungkin memakan waktu. Lanjutkan?`,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonText: '<i class="bi bi-file-earmark-excel me-1"></i> Ya, Export',
                    cancelButtonText: 'Batal',
                    reverseButtons: true,
                    confirmButtonColor: '#198754'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.isExporting = true;
                        window.location.href = exportBtn.href;
                        setTimeout(() => window.isExporting = false, 3000);
                    }
                });
            }
        });
    </script>

    {{-- Keyboard Shortcuts --}}
    <script>
        document.addEventListener('keydown', function(e) {
            // Abaikan jika fokus ada pada input/textarea
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                return;
            }

            // Alt + D -> Dashboard
            if (e.altKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                window.location.href = '{{ auth()->check() ? route(auth()->user()->role . ".dashboard") : "/" }}';
            }

            // ? -> Modal Shortcut
            if (e.key === '?') {
                e.preventDefault();
                Swal.fire({
                    title: 'Keyboard Shortcuts',
                    html: `
                        <div class="text-start">
                            <table class="table table-borderless table-sm mb-0">
                                <tr>
                                    <td width="40%"><kbd>Alt + D</kbd></td>
                                    <td>Kembali ke Dashboard</td>
                                </tr>
                                <tr>
                                    <td><kbd>?</kbd></td>
                                    <td>Buka Bantuan Ini</td>
                                </tr>
                            </table>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'Tutup'
                });
            }
        });
    </script>

    @stack('scripts')
</body>

</html>
