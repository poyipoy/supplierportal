<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="js" data-pagination-label="{{ __('pagination.navigation') }}" @auth data-quick-flow="{{ ! empty($preferenceFrontendPayload['quickFlow'] ?? false) ? 'true' : 'false' }}" @endauth @if($isWorkspaceShell ?? false) data-workspace-shell="true" @endif @if(($isWorkspaceEmbed ?? false) && ! isset($exception)) data-workspace-embed="true" @endif>

<head>
    @php
        $isWorkspaceEmbed = ($isWorkspaceEmbed ?? false) && ! isset($exception);
    @endphp
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="text-scale" content="scale">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        window.AdasiLoginUrl = @js(route('login'));
        window.AdasiSession = @js(auth()->check() && (config('portal_session.cross_tab_logout') || config('portal_session.scoped_storage_cleanup')) ? [
            'crossTab' => (bool) config('portal_session.cross_tab_logout'),
            'cleanup' => (bool) config('portal_session.scoped_storage_cleanup'),
            'userId' => (int) auth()->id(),
            'statusUrl' => route('session.status'),
            'reconnectUrl' => route('session.reconnect'),
            'heartbeatUrl' => route('session.heartbeat'),
            'timing' => \App\Support\Auth\SessionLifecycle::remainingTimings(request()),
            'copy' => [
                'restored' => __('security.session_reconnect.restored'),
                'failed' => __('security.session_reconnect.failed'),
                'unauthenticated' => __('security.session_reconnect.unauthenticated'),
            ],
        ] : null);
    </script>
    <script>
        window.AdasiPortal = Object.assign(window.AdasiPortal || {}, {
            basePath: @js(\App\Support\PortalPath::baseUrl()),
            pathPrefix: @js(\App\Support\PortalPath::prefix()),
            httpPrefix: @js(\App\Support\PortalPath::httpPrefix()),
        });
    </script>
    @if($workspaceShellEnabled ?? config('ui.workspace_shell.enabled', false))
        <script>
            (() => {
                const root = document.documentElement;
                if (@js($isWorkspaceShell ?? false)) {
                    root.dataset.workspaceShell = 'true';
                }
                if (@js($isWorkspaceEmbed ?? false)) {
                    root.dataset.workspaceEmbed = 'true';
                } else if (window.self !== window.top) {
                    try {
                        if (window.top.location && window.top.location.origin === window.location.origin) {
                            root.dataset.workspaceEmbed = 'true';
                        }
                    } catch (e) {}
                }
                window.AdasiWorkspaceShellConfig = @js($workspaceConfig ?? null);
            })();
        </script>
        @if(isset($workspaceConfig))
            <script id="adasi-workspace-shell-config" type="application/json">{!! json_encode($workspaceConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
        @endif
    @endif
    @if(!($isWorkspaceEmbed ?? false))
        @include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'app', 'authSplashReadiness' => 'core'])
    @endif
    @include('partials.i18n-bootstrap')
    <meta name="robots" content="noindex, nofollow">
    <meta name="portal-scope" content="{{ \App\Support\PortalContext::current() ?? 'import' }}">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    @auth
        @php
            $preferenceFrontendPayload['densityPreview'] = $densityPreview ?? null;
        @endphp
        <script>
            (() => {
                const preferences = @js($preferenceFrontendPayload);
                const densityKeys = preferences.densityKeys || [];
                const initialDensity = preferences.densityPreview ?? null;
                const root = document.documentElement;
                const media = window.matchMedia('(prefers-color-scheme: dark)');
                const storageKey = 'adasi.sidebar.' + preferences.accountId;
                const DEFAULT_SIDEBAR_WIDTH = 256;
                const MIN_EXPANDED_SIDEBAR_WIDTH = 81;
                const MAX_STORED_SIDEBAR_WIDTH = 4096;
                const clampSidebarWidth = (value) => {
                    if (typeof value !== 'number' || !Number.isFinite(value)) return null;
                    const viewportCap = typeof window.innerWidth === 'number' && window.innerWidth > 0
                        ? Math.floor(window.innerWidth)
                        : MAX_STORED_SIDEBAR_WIDTH;
                    const maxAllowed = Math.max(MIN_EXPANDED_SIDEBAR_WIDTH, Math.min(MAX_STORED_SIDEBAR_WIDTH, viewportCap));
                    return Math.min(maxAllowed, Math.max(MIN_EXPANDED_SIDEBAR_WIDTH, Math.round(value)));
                };
                const applySidebarWidth = (width) => {
                    if (root.dataset.workspaceEmbed === 'true') {
                        root.style?.removeProperty?.('--sidebar-width');
                        delete root.dataset.sidebarNarrow;
                        return;
                    }
                    if (typeof width === 'number' && Number.isFinite(width) && width !== DEFAULT_SIDEBAR_WIDTH) {
                        root.style?.setProperty?.('--sidebar-width', `${width}px`);
                    } else {
                        root.style?.removeProperty?.('--sidebar-width');
                    }
                    if (typeof width === 'number' && width < 180) {
                        root.dataset.sidebarNarrow = 'true';
                    } else {
                        delete root.dataset.sidebarNarrow;
                    }
                };
                let sidebarCollapsed = preferences.sidebarState === 'collapsed';
                let sidebarWidth = null;
                let previewTheme = null;
                let previewDensity = densityKeys.includes(initialDensity) ? initialDensity : null;
                let previewAccent = null;
                let previewShellStyle = null;

                const writeSidebarCache = () => {
                    const nextPayload = { revision: preferences.sidebarRevision, collapsed: sidebarCollapsed };
                    if (sidebarWidth !== null) {
                        nextPayload.width = sidebarWidth;
                    }
                    window.localStorage.setItem(storageKey, JSON.stringify(nextPayload));
                };

                try {
                    const cached = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
                    if (cached && typeof cached === 'object') {
                        if (cached.revision === preferences.sidebarRevision && typeof cached.collapsed === 'boolean') {
                            sidebarCollapsed = cached.collapsed;
                        }
                        sidebarWidth = clampSidebarWidth(cached.width);
                    }
                    writeSidebarCache();
                } catch (error) {
                    // Restricted storage falls back to the account preference for this page load.
                }

                applySidebarWidth(sidebarWidth);

                const savedTheme = preferences.theme;
                const savedDensity = densityKeys.includes(preferences.density) ? preferences.density : 'comfortable';
                const savedAccent = preferences.accent || 'brand';
                const accentKeys = preferences.accentKeys || ['brand'];
                const shellStyleKeys = preferences.shellStyleKeys || ['classic', 'slate'];
                const savedShellStyle = shellStyleKeys.includes(preferences.shellStyle) ? preferences.shellStyle : 'classic';
                const applyAccent = () => {
                    root.dataset.accent = previewAccent ?? savedAccent;
                    window.dispatchEvent(new CustomEvent('adasi:accent-change', { detail: { accent: root.dataset.accent } }));
                };
                const applyShellStyle = () => {
                    root.dataset.shell = previewShellStyle ?? savedShellStyle;
                    window.dispatchEvent(new CustomEvent('adasi:shell-change', { detail: { shellStyle: root.dataset.shell } }));
                };
                const applyTheme = () => {
                    const choice = previewTheme ?? savedTheme;
                    const effective = choice === 'system' ? (media.matches ? 'dark' : 'light') : choice;
                    root.dataset.theme = effective;
                    window.dispatchEvent(new CustomEvent('adasi:theme-change', { detail: { theme: effective } }));
                };
                const applyDensity = () => {
                    root.dataset.density = previewDensity ?? savedDensity;
                    window.dispatchEvent(new CustomEvent('adasi:density-change', { detail: { density: root.dataset.density } }));
                };

                root.dataset.sidebarCollapsed = String(window.matchMedia('(min-width: 992px)').matches && sidebarCollapsed);
                const quickFlowEnabled = Boolean(preferences.quickFlow);
                root.dataset.quickFlow = String(quickFlowEnabled);
                try { window.localStorage.setItem('adasi.quick_flow.' + preferences.accountId, String(quickFlowEnabled)); } catch (error) { /* Keep the in-memory state. */ }
                window.__adasiSidebarInitialCollapsed = sidebarCollapsed;
                window.AdasiSidebarPreferences = Object.freeze({
                    read: () => sidebarCollapsed,
                    write: (collapsed) => {
                        sidebarCollapsed = Boolean(collapsed);
                        try { writeSidebarCache(); } catch (error) { /* Keep the in-memory state. */ }
                    },
                    readWidth: () => sidebarWidth,
                    writeWidth: (width) => {
                        sidebarWidth = (width === null || width === undefined) ? null : clampSidebarWidth(Number(width));
                        applySidebarWidth(sidebarWidth);
                        try { writeSidebarCache(); } catch (error) { /* Keep the in-memory state. */ }
                    },
                    bounds: Object.freeze({
                        defaultWidth: DEFAULT_SIDEBAR_WIDTH,
                        minWidth: MIN_EXPANDED_SIDEBAR_WIDTH,
                        maxWidth: MAX_STORED_SIDEBAR_WIDTH,
                    }),
                });
                // Regional display is explicit and in-memory; raw values remain business inputs.
                const regional = Object.freeze({ ...(preferences.regional || {}) });
                const regionalRegistry = preferences.regionalRegistry || {};
                const displayZone = (() => {
                    const zone = regional.timezone;
                    if (typeof zone !== 'string' || zone === '' || zone === 'system') return 'system';
                    try {
                        new Intl.DateTimeFormat('en-GB', { timeZone: zone });
                        return zone;
                    } catch (error) {
                        return 'system';
                    }
                })();
                const displayMonthName = (month) => {
                    const displayLocale = root.lang === 'id' ? 'id-ID' : 'en-GB';
                    if (root.lang !== 'id' && typeof regionalRegistry.months?.[month - 1] === 'string') {
                        return regionalRegistry.months[month - 1];
                    }
                    return new Intl.DateTimeFormat(displayLocale, {
                        month: 'short',
                        timeZone: 'UTC',
                    }).format(new Date(Date.UTC(2000, month - 1, 1)));
                };
                const displayNumber = (text, profile = 'international') => {
                    const target = regional.number_format;
                    if (typeof text !== 'string' || !['international', 'indonesian'].includes(target)) return text;
                    const source = regionalRegistry.number_profiles?.[profile];
                    const destination = regionalRegistry.number_profiles?.[target];
                    if (!source || !destination) return text;
                    const parts = text.match(/^(\s*)([+-]?)([\d.,]+)(\s*)$/);
                    if (!parts) return text;
                    const decimalParts = parts[3].split(source.decimal);
                    if (decimalParts.length > 2 || (decimalParts.length === 2 && !/^\d+$/.test(decimalParts[1]))) return text;
                    const integer = decimalParts[0];
                    const groups = source.group ? integer.split(source.group) : [integer];
                    if (groups.length > 1 && (!/^\d{1,3}$/.test(groups[0]) || groups.slice(1).some(group => !/^\d{3}$/.test(group)))) return text;
                    if (groups.some(group => !/^\d+$/.test(group))) return text;
                    const digits = groups.join('');
                    const grouped = destination.group ? digits.replace(/\B(?=(\d{3})+(?!\d))/g, destination.group) : digits;
                    const fraction = decimalParts.length === 2 ? destination.decimal + decimalParts[1] : '';
                    return parts[1] + parts[2] + grouped + fraction + parts[4];
                };
                const displayDate = (value, profile = 'iso') => {
                    if (typeof value !== 'string') return value;
                    const parts = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (!parts) return value;
                    const year = Number(parts[1]), month = Number(parts[2]), day = Number(parts[3]);
                    const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
                    const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                    if (year < 1 || month < 1 || month > 12 || day < 1 || day > days[month - 1]) return value;
                    const choice = regional.date_format === 'system' || !regionalRegistry.date_formats?.includes(regional.date_format) ? profile : regional.date_format;
                    if (choice === 'human') return parts[3] + ' ' + displayMonthName(month) + ' ' + parts[1];
                    if (choice === 'dmy') return parts[3] + '/' + parts[2] + '/' + parts[1];
                    return value;
                };

                const displayTimestamp = (value, legacyRenderer) => {
                    if (typeof value !== 'string') return '-';
                    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|([+-])(\d{2}):(\d{2}))$/);
                    if (!match) return '-';

                    const source = {
                        year: Number(match[1]), month: Number(match[2]), day: Number(match[3]),
                        hour: Number(match[4]), minute: Number(match[5]), second: Number(match[6]),
                    };
                    const leap = source.year % 4 === 0 && (source.year % 100 !== 0 || source.year % 400 === 0);
                    const monthDays = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                    if (source.year < 1 || source.month < 1 || source.month > 12 || source.day < 1 || source.day > monthDays[source.month - 1]
                        || source.hour > 23 || source.minute > 59 || source.second > 59
                        || (match[8] && (Number(match[9]) > 23 || Number(match[10]) > 59))
                        || !Number.isFinite(Date.parse(value))) return '-';

                    // Server-resolved zone: the global choice, or the Supplier Import work-location zone (any IANA id).
                    const timezone = displayZone;
                    const dateFormat = regionalRegistry.date_formats?.includes(regional.date_format) ? regional.date_format : 'system';
                    const timeFormat = ['system', '24h', '12h'].includes(regional.time_format) ? regional.time_format : 'system';
                    const explicitTimestampPreference = timezone !== 'system' || dateFormat !== 'system' || timeFormat !== 'system';

                    if (!explicitTimestampPreference) {
                        try {
                            return typeof legacyRenderer === 'function' ? String(legacyRenderer(value) ?? '-') : '-';
                        } catch (error) {
                            return '-';
                        }
                    }

                    let parts = source;
                    let suffix = '';
                    if (timezone !== 'system') {
                        try {
                            const instant = new Date(value);
                            const formatted = new Intl.DateTimeFormat('en-GB', {
                                timeZone: timezone, calendar: 'gregory', numberingSystem: 'latn',
                                year: 'numeric', month: '2-digit', day: '2-digit',
                                hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
                            }).formatToParts(instant);
                            const values = Object.fromEntries(formatted.map(part => [part.type, part.value]));
                            parts = {
                                year: Number(values.year), month: Number(values.month), day: Number(values.day),
                                hour: Number(values.hour) % 24, minute: Number(values.minute),
                            };
                            // Mirrors regional-sample.js zoneSuffix(): WIB for Jakarta, otherwise the offset at this instant.
                            if (timezone === 'Asia/Jakarta') {
                                suffix = 'WIB';
                            } else {
                                const wall = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, Number(values.second));
                                const offset = Math.round((wall - Math.floor(instant.getTime() / 1000) * 1000) / 60000);
                                const absolute = Math.abs(offset);
                                suffix = 'UTC' + (offset < 0 ? '-' : '+') + String(Math.floor(absolute / 60)).padStart(2, '0') + ':' + String(absolute % 60).padStart(2, '0');
                            }
                        } catch (error) {
                            return '-';
                        }
                    }

                    const year = String(parts.year).padStart(4, '0');
                    const month = String(parts.month).padStart(2, '0');
                    const day = String(parts.day).padStart(2, '0');
                    let dateText;
                    if (dateFormat === 'iso') dateText = year + '-' + month + '-' + day;
                    else if (dateFormat === 'dmy') dateText = day + '/' + month + '/' + year;
                    else {
                        const monthName = displayMonthName(parts.month);
                        dateText = day + ' ' + monthName + ' ' + year;
                    }

                    let timeText;
                    if (timeFormat === '12h') {
                        const hour = parts.hour % 12 || 12;
                        timeText = hour + ':' + String(parts.minute).padStart(2, '0') + (parts.hour < 12 ? ' AM' : ' PM');
                    } else {
                        timeText = String(parts.hour).padStart(2, '0') + ':' + String(parts.minute).padStart(2, '0');
                    }

                    return dateText + ' ' + timeText + (suffix ? ' ' + suffix : '');
                };

                window.AdasiPreferences = Object.freeze({
                    regional,
                    displayNumber,
                    displayDate,
                    displayTimestamp,
                    pageSize: Number(preferences.pageSize),
                    theme: savedTheme,
                    density: savedDensity,
                    accent: savedAccent,
                    shellStyle: savedShellStyle,
                    previewAccent: (value) => { if (accentKeys.includes(value)) { previewAccent = value; applyAccent(); } },
                    previewShellStyle: (value) => { if (shellStyleKeys.includes(value)) { previewShellStyle = value; applyShellStyle(); } },
                    previewTheme: (value) => { previewTheme = value; applyTheme(); },
                    previewDensity: (value) => { if (densityKeys.includes(value)) { previewDensity = value; applyDensity(); } },
                    restoreSaved: () => { previewTheme = null; previewDensity = null; previewAccent = null; previewShellStyle = null; applyTheme(); applyDensity(); applyAccent(); applyShellStyle(); },
                });
                applyTheme();
                applyDensity();
                applyAccent();
                applyShellStyle();
                media.addEventListener('change', () => { if ((previewTheme ?? savedTheme) === 'system') applyTheme(); });
            })();
        </script>
    @endauth
    <title>@if($isWorkspaceEmbed ?? false)@yield('title', 'ADASI Supplier Portal')@else{{ 'ADASI Supplier Portal' }}@endif</title>

    {{-- Favicon --}}
    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

    {{-- SweetAlert2 CSS --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" integrity="sha384-e9JoBUb50niLuTodlxX3NLZZfrt9fQkX5bihGXOGWD/7QFJoXEH37S2df8UA2ehO" crossorigin="anonymous">

    {{-- ADASI Alert Theme --}}
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}?v={{ filemtime(public_path('assets/css/adasi-alert.css')) }}">


    {{-- Tailwind design foundation + Alpine entry --}}
    @include('partials.loader-logo')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    @if(($isWorkspaceShell ?? false) || ($isWorkspaceEmbed ?? false) || \App\Support\WorkspaceShell::enabled())
        <style>
            @view-transition {
                navigation: none;
            }
        </style>
    @endif
</head>

<body
    x-data="adasiShell"
    x-on:ui-sidebar-toggle.window="toggleSidebar($event.detail?.trigger)"
    x-on:ui-sidebar-close.window="closeMobileSidebar(false)"
    x-on:keydown.escape.window="closeMobileSidebar()"
    x-on:keydown.tab.window="trapSidebarFocus($event)"
    x-effect="document.body.classList.toggle('ui-nav-open', mobileOpen)"
>
    @if(!($isWorkspaceEmbed ?? false))
        <x-ui.auth-splash :error="isset($exception)" />
    @endif
    <a href="#main-content" class="ui-skip-link">{{ __('navigation.skip') }}</a>
    @php
        $initNotifCount = 0;
        $initChatCount = 0;
        $dashboardUrl = url('/');
        if (auth()->check()) {
            $roleDashboardRoute = auth()->user()->role
                ? auth()->user()->role . '.dashboard'
                : 'dashboard';
            $dashboardUrl = \App\Support\PortalContext::dashboard(auth()->user(), absolute: true);
        }
    @endphp
    @if(!($isWorkspaceEmbed ?? false))
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
    @endif

    {{-- Main Wrapper --}}
    <div class="main-wrapper" id="mainWrapper" x-bind:class="{ 'expanded': desktopCollapsed }">
        @if(!($isWorkspaceEmbed ?? false))
            {{-- Navbar --}}
            @include('partials.navbar')
            @auth
                <x-ui.session-expired-modal />
            @endauth
        @endif

        @if($isWorkspaceShell ?? false)
            @include('partials.workspace-session-bar')

            {{-- Workspace Iframe Stage --}}
            <main class="content-area workspace-stage" id="main-content" data-workspace-stage tabindex="-1">
                @yield('content')
            </main>
        @else
            {{-- Content Area --}}
            <main class="content-area ui-page-fade" id="main-content" tabindex="-1">
                <div class="content-container">
                    @include('partials.alerts')
                    @yield('content')
                </div>
            </main>
        @endif
    </div>

    @if(!($isWorkspaceEmbed ?? false))
        @include('partials.chat-drawer')
        <x-ui.smart-panel />
        <x-ui.toast-container context="app" />
        <x-ui.image-lightbox />
    @endif

    <script>
        window.initAdasiTooltips = function (root = document) {
            window.AdasiTooltip?.refresh?.(root);
        };

        document.addEventListener('DOMContentLoaded', () => window.initAdasiTooltips());

        document.addEventListener('animationend', (event) => {
            if (event.animationName === 'ui-page-fade-in' && event.target instanceof Element) {
                event.target.classList.remove('ui-page-fade');
            }
        });

        // Drop any active page-fade animation before a dialog opens so no ancestor stalls mid-transition.
        document.addEventListener('adasi:modal-show', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            let ancestor = event.target.parentElement;
            while (ancestor) {
                if (ancestor.classList?.contains('ui-page-fade')) {
                    ancestor.classList.remove('ui-page-fade');
                }
                ancestor = ancestor.parentElement;
            }
        });
    </script>

    @hasSection('uses-datatables')
        {{-- DataTables JS (only on pages that initialize a DataTable) --}}
        <script src="https://cdn.datatables.net/3.1.5/js/dataTables.min.js" integrity="sha384-RhzIACKRVYIyijzmm7bA1mSofISYiTQ/KsnClfUSGSHu6ZeRsTQVWL4LqPHTrJcT" crossorigin="anonymous"></script>
    @endif
    <script>
        window.AdasiDataTable = Object.freeze({
            defaults: () => ({ processing: true, pageLength: window.AdasiPreferences?.pageSize || 25, lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]], language: @js(__('datatables')) }),
        });
        if (window.DataTable?.Dom?.s) {
            if (typeof window.DataTable.util?.object?.assignDeepObjects === 'function') {
                window.DataTable.util.object.assignDeepObjects(window.DataTable.defaults, window.AdasiDataTable.defaults(), true);
            } else {
                Object.assign(window.DataTable.defaults, window.AdasiDataTable.defaults());
            }
            if (window.DataTable?.ajax?.defaults) {
                window.DataTable.ajax.defaults.headers = {
                    ...(window.DataTable.ajax.defaults.headers || {}),
                    'X-Requested-With': 'XMLHttpRequest',
                };
            }
            let generatedSearchId = 0;
            const labelDataTableSearch = (node) => {
                if (!(node instanceof Element)) return;

                const wrappers = [
                    ...(node.matches('.dt-search') ? [node] : []),
                    ...node.querySelectorAll('.dt-search'),
                ];

                wrappers.forEach((wrapper) => {
                    const searchInput = wrapper.querySelector('input[type="search"]');
                    if (!searchInput) return;

                    const tableId = wrapper.id?.replace(/_filter$/, '')
                        || searchInput.getAttribute('aria-controls')
                        || `adasi-data-table-${++generatedSearchId}`;
                    if (!searchInput.id) searchInput.id = `${tableId}-search`;
                    if (!searchInput.name) searchInput.name = `${tableId}_search`;
                    if (!searchInput.hasAttribute('aria-label')) {
                        searchInput.setAttribute('aria-label', window.AdasiI18n.t('datatables.search'));
                    }
                });
            };

            document.querySelectorAll('.dt-search').forEach(labelDataTableSearch);
            new MutationObserver((records) => {
                records.forEach((record) => record.addedNodes.forEach(labelDataTableSearch));
            }).observe(document.documentElement, { childList: true, subtree: true });

            // A single page of results keeps the summary but drops the pager (see .ui-dt-single-page in app.css).
            // The pager also becomes a labelled navigation landmark, like the Laravel pagination view.
            const defaultPaginationLabel = @js(__('pagination.navigation'));
            const initializedTables = new WeakSet();
            const lastSortSignature = new WeakMap();
            let spaceArmedSortControl = null;

            const orderSignature = (api) => {
                const order = typeof api?.order === 'function' ? api.order() : [];
                if (!Array.isArray(order) || order.length === 0) return '';
                return order
                    .filter((entry) => Array.isArray(entry) && entry.length >= 2)
                    .map(([colIdx, dir]) => `${colIdx}:${dir}`)
                    .join('|');
            };

            const ensureSortLiveRegion = (wrapper) => {
                const host = wrapper.closest('.ui-data-table') || wrapper.parentElement || wrapper;
                let region = host.querySelector(':scope > [data-adasi-dt-sort-status="true"]');
                if (!region) {
                    region = document.createElement('p');
                    region.className = 'tw:sr-only';
                    region.setAttribute('data-adasi-dt-sort-status', 'true');
                    region.setAttribute('role', 'status');
                    region.setAttribute('aria-live', 'polite');
                    region.setAttribute('aria-atomic', 'true');
                    host.appendChild(region);
                }
                return region;
            };

            const announceDataTableSort = (api, tableNode, wrapper) => {
                const signature = orderSignature(api);
                if (!initializedTables.has(tableNode)) {
                    lastSortSignature.set(tableNode, signature);
                    return;
                }
                const previousSignature = lastSortSignature.get(tableNode) ?? '';
                if (signature === previousSignature) {
                    return;
                }
                lastSortSignature.set(tableNode, signature);

                const region = ensureSortLiveRegion(wrapper);
                const info = api.page.info();
                const count = String(info?.recordsDisplay ?? 0);
                const order = api.order();
                if (!Array.isArray(order) || order.length === 0) {
                    region.textContent = window.AdasiI18n.t('js.tables.sort_cleared', { count });
                    return;
                }

                const [colIdx, rawDir] = order[0];
                const headerCell = api.column(colIdx)?.header?.();
                const columnLabel = (
                    headerCell?.querySelector('.dt-column-title')?.textContent
                    || headerCell?.textContent
                    || ''
                ).trim();
                if (!columnLabel) {
                    return;
                }

                const directionLabel = window.AdasiI18n.t(
                    rawDir === 'desc' ? 'js.tables.sort_desc' : 'js.tables.sort_asc'
                );
                region.textContent = window.AdasiI18n.t('js.tables.sorted_status', {
                    column: columnLabel,
                    direction: directionLabel,
                    count,
                });
            };

            const syncDataTableDrawState = (event, settings) => {
                const ApiCtor = window.DataTable?.Api;
                if (!ApiCtor || !settings) return;
                const api = new ApiCtor(settings);
                const wrapper = api.table().container();
                const tableNode = settings?.table;
                if (!wrapper || !tableNode) return;

                wrapper.querySelectorAll('th[aria-sort]').forEach((th) => {
                    if (th.getAttribute('aria-sort') === 'none') {
                        th.removeAttribute('aria-sort');
                    }
                });

                const pages = api.page.info().pages;
                wrapper.classList.toggle('ui-dt-single-page', pages <= 1);

                const pager = wrapper.querySelector('.dt-paging');
                if (pager) {
                    const navTarget = pager.matches('nav') ? pager : pager.querySelector('nav');
                    const title = wrapper.closest('.ui-data-table')?.querySelector('.ui-data-table__header h2')?.textContent.trim();
                    const paginationLabel = document.documentElement?.dataset?.paginationLabel || defaultPaginationLabel;
                    const label = title ? `${paginationLabel}: ${title}` : paginationLabel;
                    if (navTarget) {
                        navTarget.setAttribute('aria-label', label);
                    } else if (!pager.hasAttribute('role')) {
                        pager.setAttribute('role', 'navigation');
                        pager.setAttribute('aria-label', label);
                    }
                }

                if (event?.type === 'draw') {
                    announceDataTableSort(api, tableNode, wrapper);
                }
            };

            // DataTables 3.x renders div.dt-column-order[role="button"] with Enter support only; add native Space-key activation per ARIA APG.
            document.addEventListener('keydown', (event) => {
                if (event.key !== ' ' || event.altKey || event.ctrlKey || event.metaKey) return;
                const sortControl = event.target instanceof Element
                    ? event.target.closest('table.dataTable thead th .dt-column-order[role="button"]')
                    : null;
                if (!sortControl) return;
                event.preventDefault();
                spaceArmedSortControl = sortControl;
            });
            document.addEventListener('keyup', (event) => {
                if (event.key !== ' ' || !spaceArmedSortControl) return;
                const armed = spaceArmedSortControl;
                spaceArmedSortControl = null;
                if (document.activeElement === armed && armed.isConnected) {
                    armed.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: event.shiftKey }));
                }
            });
            document.addEventListener('blur', () => {
                spaceArmedSortControl = null;
            }, true);

            window.DataTable.Dom.s(document).on('draw.dt', syncDataTableDrawState);
            window.DataTable.Dom.s(document).on('init.dt', (_event, settings) => {
                const ApiCtor = window.DataTable?.Api;
                const tableNode = settings?.table;
                if (!ApiCtor || !tableNode) return;
                const api = new ApiCtor(settings);
                initializedTables.add(tableNode);
                lastSortSignature.set(tableNode, orderSignature(api));
            });
            window.DataTable.Dom.s(document).on('preInit.dt', (_event, settings) => {
                const tableNode = settings?.table;
                if (tableNode) {
                    window.DataTable.Dom.s(tableNode).off('order.dt.adasiUi').on('order.dt.adasiUi', syncDataTableDrawState);
                }
            });
        }
    </script>
    {{-- SweetAlert2 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js" integrity="sha384-VNUenFzuMm/yuCawx3aXQgp6+Z5QL6nRMkX1fNFfqPzI1fdaooshPNCHt/Llz/bJ" crossorigin="anonymous"></script>
    <script src="{{ asset('assets/js/adasi-alert.js') }}?v={{ filemtime(public_path('assets/js/adasi-alert.js')) }}"></script>

    @unless(app()->isProduction() || config('app.env') === 'production')
    {{-- Ngrok browser warning bypass for internal async requests --}}
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

            if (window.DataTable?.Dom?.s) {
                window.DataTable.Dom.s(document).on('preXhr.dt', (_event, _settings, _data, ajaxOpts) => {
                    if (!ajaxOpts || typeof ajaxOpts !== 'object') return;
                    if (isInternalUrl(ajaxOpts.url || window.location.href)) {
                        ajaxOpts.headers = {
                            ...(window.DataTable?.ajax?.defaults?.headers || {}),
                            ...(ajaxOpts.headers || {}),
                            [headerName]: headerValue,
                        };
                    }
                });
            }
        })();
    </script>
    @endunless

    {{-- Custom JS --}}
    @if(!($isWorkspaceEmbed ?? false))
    <script>
        @auth
            let badgeFetchActive = false;
            let lastBadgeFetchAt = 0;
            const BADGE_VISIBILITY_THROTTLE_MS = 5000;

            function isPusherConnected() {
                return window.Echo?.connector?.pusher?.connection?.state === 'connected';
            }

            function updateBadges() {
                if (badgeFetchActive || document.hidden === true || (document.visibilityState && document.visibilityState === 'hidden') || window.AdasiSessionSync?.isSessionExpired?.()) {
                    return;
                }

                badgeFetchActive = true;
                lastBadgeFetchAt = Date.now();
                const headers = {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Adasi-Session-Check': 'passive',
                };

                // Notification badge
                const notifSignal = typeof AbortSignal?.timeout === 'function' ? AbortSignal.timeout(8000) : undefined;
                const notifPromise = fetch("{{ route('notifications.unread-count') }}", {
                    headers,
                    priority: 'low',
                    ...(notifSignal ? { signal: notifSignal } : {}),
                })
                    .then(r => {
                        window.AdasiSessionSync?.handlePollResponse(r);
                        return r.ok ? r.json() : null;
                    })
                    .then(data => {
                        if (!data) return;
                        document.querySelectorAll('.notif-badge').forEach(badge => {
                            const label = data.count > 0
                                ? window.AdasiI18n.choice('js.notification.unread_count', Number(data.count), { count: data.count })
                                : window.AdasiI18n.t('js.notification.button_label');
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.classList.remove('tw:hidden');
                            } else {
                                badge.classList.add('tw:hidden');
                            }
                            badge.closest('button[data-ui-dropdown-trigger]')?.setAttribute('aria-label', label);
                        });

                        if (typeof updateNotificationCategoryBadges === 'function') {
                            updateNotificationCategoryBadges(data.category_counts);
                        }
                    })
                    .catch(() => {});

                // Chat badge
                @if(auth()->user()->isPurchasing() || \App\Support\PortalContext::isImport(auth()->user()))
                    const chatSignal = typeof AbortSignal?.timeout === 'function' ? AbortSignal.timeout(8000) : undefined;
                    const chatPromise = fetch("{{ route('conversations.unread-count') }}", {
                        headers,
                        priority: 'low',
                        ...(chatSignal ? { signal: chatSignal } : {}),
                    })
                        .then(r => {
                            window.AdasiSessionSync?.handlePollResponse(r);
                            return r.ok ? r.json() : null;
                        })
                        .then(data => {
                            if (!data) return;
                            document.querySelectorAll('.chat-badge').forEach(badge => {
                                badge.setAttribute('aria-label', window.AdasiI18n.t('js.shell.unread_chats', { count: data.count }));
                                const sidebarLink = badge.closest('.sidebar-link');
                                if (sidebarLink) {
                                    sidebarLink.setAttribute(
                                        'aria-label',
                                        window.AdasiI18n.choice('js.shell.chat_link', Number(data.count), { count: data.count })
                                    );
                                }
                                if (data.count > 0) {
                                    badge.textContent = data.count;
                                    badge.classList.remove('tw:hidden');
                                } else {
                                    badge.classList.add('tw:hidden');
                                }
                            });
                        })
                        .catch(() => {});

                    Promise.allSettled([notifPromise, chatPromise]).finally(() => {
                        badgeFetchActive = false;
                    });
                @else
                    notifPromise.finally(() => {
                        badgeFetchActive = false;
                    });
                @endif
            }

            // Run after initial page assets have completely finished loading to avoid holding tab spinner
            if (document.readyState === 'complete') {
                setTimeout(updateBadges, 1000);
            } else {
                window.addEventListener('load', () => {
                    setTimeout(updateBadges, 1000);
                });
            }

            // Adaptive polling: skip hidden tabs and 30s ticks while Pusher WebSocket is connected (reconcile every 300s as fallback)
            let pollTicksSinceSync = 0;
            setInterval(() => {
                if (document.hidden === true) {
                    return;
                }
                pollTicksSinceSync += 1;
                if (isPusherConnected() && pollTicksSinceSync < 10) {
                    return;
                }
                pollTicksSinceSync = 0;
                updateBadges();
            }, 30000);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState !== 'visible' || document.hidden === true) {
                    return;
                }
                if (isPusherConnected()) {
                    return;
                }
                if (Date.now() - lastBadgeFetchAt < BADGE_VISIBILITY_THROTTLE_MS) {
                    return;
                }
                pollTicksSinceSync = 0;
                updateBadges();
            });

            window.addEventListener('adasi:session-restored', () => {
                pollTicksSinceSync = 0;
                lastBadgeFetchAt = 0;
                updateBadges();
            });
        @endauth
    </script>
    @endif
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
                        || link.hasAttribute('data-ui-dropdown-trigger')
                        || link.hasAttribute('data-ui-tab')
                        || link.hasAttribute('popovertarget')
                        || link.hasAttribute('data-ui-modal-open')
                        || link.hasAttribute('data-ui-modal-close')
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
                        || !targetUrl.pathname.startsWith(@js(\App\Support\PortalPath::httpPath(\App\Support\PortalPath::path('purchasing/'))))
                        || targetUrl.pathname.startsWith(@js(\App\Support\PortalPath::httpPath(\App\Support\PortalPath::path('purchasing/export/'))))
                        || listPaths.has(targetUrl.pathname)
                        || targetUrl.searchParams.has('return_url')
                    ) {
                        return;
                    }

                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete('return_url');
                    currentUrl.searchParams.delete('_embed');
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
                        const closestSpan = icon ? icon.closest('span') : null;
                        const iconTarget = (closestSpan && closestSpan !== activeSubmitter && closestSpan.textContent.trim() === '' && !closestSpan.querySelector(':scope > span'))
                            ? closestSpan
                            : icon;
                        const spinner = document.createElement('span');
                        spinner.className = 'ui-spinner';
                        spinner.setAttribute('aria-hidden', 'true');
                        if (iconTarget && iconTarget.parentNode) {
                            iconTarget.style.display = 'none';
                            iconTarget.parentNode.insertBefore(spinner, iconTarget);
                        } else {
                            spinner.classList.add('tw:mr-1.5');
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

                // Safety reset after 60 seconds if the request fails or times out.
                const safetyTimeoutMs = (window.AdasiButton && window.AdasiButton.TIMEOUT_MS) ? window.AdasiButton.TIMEOUT_MS : 60000;
                if (form._submitGuardTimeout) {
                    clearTimeout(form._submitGuardTimeout);
                }
                form._submitGuardTimeout = setTimeout(function () {
                    delete form._submitGuardTimeout;
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
                }, safetyTimeoutMs);
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

            if (pdfBtn.dataset.quickFlow === 'true' || window.AdasiQuickFlow?.isEnabled?.(pdfBtn, 'pdf')) {
                return;
            }

            e.preventDefault();

            if (window.pdfConfirmationOpen) {
                return;
            }

            window.pdfConfirmationOpen = true;

            AdasiAlert.confirm({
                title: @js(__('navigation.pdf_confirm')),
                text: @js(__('navigation.pdf_help')),
                confirmText: @js(__('js.actions.yes_download')),
                cancelText: @js(__('common.actions.cancel'))
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

            if (exportBtn.dataset.quickFlow === 'true' || window.AdasiQuickFlow?.isEnabled?.(exportBtn, 'export')) {
                return;
            }

            e.preventDefault();

            if (window.exportConfirmationOpen) {
                return;
            }

            window.exportConfirmationOpen = true;

            let recordsTotal = 'all';

            AdasiAlert.confirm({
                title: @js(__('navigation.excel_confirm')),
                text: @js(__('navigation.excel_help')),
                confirmText: @js(__('js.actions.yes_export')),
                cancelText: @js(__('common.actions.cancel'))
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
            const active = document.activeElement;
            if (active && (['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName) || active.isContentEditable)) {
                return;
            }
            if (document.querySelector('dialog[open], .modal.show')) {
                return;
            }

            if (e.altKey && (e.code === 'BracketLeft' || e.key === '[')) {
                const prevLink = document.querySelector('a[data-record-nav="prev"][href]');
                if (prevLink) {
                    e.preventDefault();
                    prevLink.click();
                }
                return;
            }

            if (e.altKey && (e.code === 'BracketRight' || e.key === ']')) {
                const nextLink = document.querySelector('a[data-record-nav="next"][href]');
                if (nextLink) {
                    e.preventDefault();
                    nextLink.click();
                }
                return;
            }

            // Alt + D -> Dashboard
            if (e.altKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                const dashUrl = @json($dashboardUrl);
                let parentShell = null;
                if (document.documentElement.dataset.workspaceEmbed === 'true') {
                    try {
                        if (window.parent?.location?.origin === window.location.origin) {
                            parentShell = window.parent.AdasiWorkspaceShell || null;
                        }
                    } catch (err) {
                        parentShell = null;
                    }
                }
                if (window.AdasiWorkspaceShell) {
                    window.AdasiWorkspaceShell.openUrl(dashUrl);
                } else if (parentShell) {
                    parentShell.openUrl(dashUrl);
                } else {
                    window.location.href = dashUrl;
                }
            }

            // ? -> Modal Shortcut
            if (e.key === '?') {
                e.preventDefault();
                AdasiAlert.info({
                    title: @js(__('navigation.keyboard_shortcuts')),
                    html: `
                        <div class="tw:text-start">
                            <table class="ui-table ui-table--borderless ui-table--sm tw:mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="tw:w-2/5">{{ __('navigation.keyboard_shortcuts') }}</th>
                                        <th scope="col">{{ __('common.table.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><kbd>Alt + D</kbd></td>
                                        <td>{{ __('navigation.back_dashboard') }}</td>
                                    </tr>
                                    <tr>
                                        <td><kbd>Alt + [</kbd></td>
                                        <td>{{ __('navigation.shortcut_prev_record') }}</td>
                                    </tr>
                                    <tr>
                                        <td><kbd>Alt + ]</kbd></td>
                                        <td>{{ __('navigation.shortcut_next_record') }}</td>
                                    </tr>
                                    <tr>
                                        <td><kbd>?</kbd></td>
                                        <td>{{ __('navigation.open_help') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    `,
                    confirmText: @js(__('common.actions.close'))
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
    @if($pusherClientReady && !($isWorkspaceEmbed ?? false))
        {{-- Laravel Echo + Pusher Channels --}}
        <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js" integrity="sha384-F0kpynobTwTZnUdhUu3W6P6QcdLW2hKKDTh6ECx+p/osH1JYIKlYqUjNc4ObdiTj" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.19.0/dist/echo.iife.js" integrity="sha384-ScNZkQp2Eq+lxW1uQEWSn+2+fQYxKc1pCX6Vl1lxSRzJBvJ5DRR98jdTDqdde2iX" crossorigin="anonymous"></script>

        <script>
            (() => {
                const csrfToken =
                    document.querySelector('meta[name="csrf-token"]')?.content;

                const userId =
                    document.querySelector('meta[name="user-id"]')?.content;

                const readUrlBase =
                    @json(route('notifications.read', '__NOTIFICATION_ID__'));

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

                const navigateNotificationTarget = async (rawUrl) => {
                    if (!rawUrl) return { navigated: false, inShell: false };

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
                };

                const markReadAndRedirect = async (readUrl, notificationId = null) => {
                    if (!readUrl) return;

                    try {
                        const activeCsrfToken =
                            document.querySelector('meta[name="csrf-token"]')?.content || csrfToken;
                        const response = await fetch(readUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': activeCsrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        window.AdasiSessionSync?.handlePollResponse?.(response);

                        const data = response.ok
                            ? await response.json()
                            : null;

                        if (!data) return;

                        if (typeof window.syncNotificationReadState === 'function') {
                            window.syncNotificationReadState(data, notificationId);
                        } else if (typeof updateBadges === 'function') {
                            updateBadges();
                        }

                        if (data.redirect) {
                            await navigateNotificationTarget(data.redirect);
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
                    const item = document.createElement('button');

                    item.type = 'button';
                    item.className = 'notification-item ui-focus-ring tw:block tw:w-full tw:text-start tw:bg-surface-low';
                    item.dataset.notificationItem = '';
                    item.dataset.notificationCategory = category;
                    item.dataset.notificationUnread = '1';
                    item.dataset.notificationReadUrl = readUrl;
                    item.dataset.notificationId = String(notification.id);

                    const row = document.createElement('div');
                    row.className = 'tw:flex tw:gap-3';

                    const categoryIcon = document.querySelector(
                        `[data-notification-category="${category}"] .ui-icon`
                    );
                    const icon = categoryIcon?.cloneNode(true)
                        || document.querySelector('.notification-menu-heading .ui-icon')?.cloneNode(true);

                    if (icon) {
                        icon.classList.add('tw:text-primary', 'tw:shrink-0', 'tw:mt-1');
                        icon.setAttribute('width', '20');
                        icon.setAttribute('height', '20');
                    }

                    const content =
                        document.createElement('div');

                    content.className =
                        'tw:min-w-0 tw:flex-1';

                    const heading =
                        document.createElement('div');

                    heading.className =
                        'tw:flex tw:justify-between tw:gap-2';

                    const title =
                        document.createElement('div');

                    title.className =
                        'tw:font-semibold tw:text-ui-xs tw:truncate';

                    title.textContent =
                        String(
                            notification.title ||
                            @js(__('common.notification.single'))
                        );

                    const newBadge =
                        document.createElement('span');

                    newBadge.className =
                        'ui-status-chip ui-status-chip--error tw:shrink-0';
                    newBadge.dataset.notificationNewBadge = '';
                    newBadge.textContent = @js(__('common.states.new'));

                    heading.append(title, newBadge);

                    const message =
                        document.createElement('div');

                    message.className = 'tw:text-on-surface-variant tw:text-ui-xs';

                    message.textContent =
                        String(
                            notification.message || '-'
                        );

                    const time =
                        document.createElement('div');

                    time.className =
                        'tw:text-on-surface-variant tw:mt-2 tw:text-ui-xs';
                    time.textContent = @js(__('navigation.just_now'));

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
                                    '[data-notification-empty]'
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
                            @js(__('common.notification.new')),
                        message:
                            notification.message ||
                            '',
                        timestamp: @js(__('navigation.just_now')),
                        icon:
                            notification.icon ||
                            'bell',
                        actions: [
                            {
                                label: @js(__('common.notification.dismiss_action')),
                                variant: 'secondary',
                            },
                            {
                                label: @js(__('common.actions.view')),
                                variant: 'primary',
                                onClick: () =>
                                    markReadAndRedirect(
                                        readUrl,
                                        notification.id
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

                        authEndpoint: @js(url('/broadcasting/auth')),

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
                                window.dispatchEvent(
                                    new CustomEvent('adasi:realtime-notification', {
                                        detail: notification,
                                    })
                                );
                                window.AdasiWorkspaceShell?.broadcastNotification?.(notification);
                            }
                        );

                    userChannel.listen(
                        '.export.progress',
                        (progress) => window.AdasiAsyncExport
                            ?.handleProgress?.(progress)
                    );

                } catch (error) {
                    window.AdasiDebug?.error(
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
    @if (!($isWorkspaceEmbed ?? false) && ! isset($exception) && request()->attributes->get('ui.auth_splash_rendered'))
        <template data-auth-splash-layout-ready="app" data-readiness="core"></template>
    @endif
</body>

</html>
