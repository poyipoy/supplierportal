<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="js">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="portal-scope" content="{{ \App\Support\PortalContext::current() ?? 'import' }}">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    @auth
        <script>
            (() => {
                const preferences = @js($preferenceFrontendPayload);
                const root = document.documentElement;
                const media = window.matchMedia('(prefers-color-scheme: dark)');
                const storageKey = 'adasi.sidebar.' + preferences.accountId;
                let sidebarCollapsed = preferences.sidebarState === 'collapsed';
                let previewTheme = null;
                let previewDensity = null;
                let previewAccent = null;

                try {
                    const cached = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
                    if (cached && cached.revision === preferences.sidebarRevision && typeof cached.collapsed === 'boolean') {
                        sidebarCollapsed = cached.collapsed;
                    }
                    window.localStorage.setItem(storageKey, JSON.stringify({ revision: preferences.sidebarRevision, collapsed: sidebarCollapsed }));
                } catch (error) {
                    // Restricted storage falls back to the account preference for this page load.
                }

                const savedTheme = preferences.theme;
                const savedDensity = preferences.density;
                const savedAccent = preferences.accent || 'brand';
                const accentKeys = preferences.accentKeys || ['brand'];
                const applyAccent = () => {
                    root.dataset.accent = previewAccent ?? savedAccent;
                    window.dispatchEvent(new CustomEvent('adasi:accent-change', { detail: { accent: root.dataset.accent } }));
                };
                const applyTheme = () => {
                    const choice = previewTheme ?? savedTheme;
                    const effective = choice === 'system' ? (media.matches ? 'dark' : 'light') : choice;
                    root.dataset.theme = effective;
                    root.dataset.bsTheme = effective;
                    window.dispatchEvent(new CustomEvent('adasi:theme-change', { detail: { theme: effective } }));
                };
                const applyDensity = () => {
                    root.dataset.density = previewDensity ?? savedDensity;
                };

                root.dataset.sidebarCollapsed = String(window.matchMedia('(min-width: 992px)').matches && sidebarCollapsed);
                window.__adasiSidebarInitialCollapsed = sidebarCollapsed;
                window.AdasiSidebarPreferences = Object.freeze({
                    read: () => sidebarCollapsed,
                    write: (collapsed) => {
                        sidebarCollapsed = Boolean(collapsed);
                        try { window.localStorage.setItem(storageKey, JSON.stringify({ revision: preferences.sidebarRevision, collapsed: sidebarCollapsed })); } catch (error) { /* Keep the in-memory state. */ }
                    },
                });
                // Regional display is explicit and in-memory; raw values remain business inputs.
                const regional = Object.freeze({ ...(preferences.regional || {}) });
                const regionalRegistry = preferences.regionalRegistry || {};
                const displayLocale = root.lang === 'id' ? 'id-ID' : 'en-GB';
                const displayMonthName = (month) => {
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

                    const timezone = regional.timezone === 'Asia/Jakarta' ? 'Asia/Jakarta' : 'system';
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
                    if (timezone === 'Asia/Jakarta') {
                        try {
                            const formatted = new Intl.DateTimeFormat('en-GB', {
                                timeZone: 'Asia/Jakarta', calendar: 'gregory', numberingSystem: 'latn',
                                year: 'numeric', month: '2-digit', day: '2-digit',
                                hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
                            }).formatToParts(new Date(value));
                            const values = Object.fromEntries(formatted.map(part => [part.type, part.value]));
                            parts = {
                                year: Number(values.year), month: Number(values.month), day: Number(values.day),
                                hour: Number(values.hour) % 24, minute: Number(values.minute),
                            };
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

                    return dateText + ' ' + timeText + (timezone === 'Asia/Jakarta' ? ' WIB' : '');
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
                    previewAccent: (value) => { if (accentKeys.includes(value)) { previewAccent = value; applyAccent(); } },
                    previewTheme: (value) => { previewTheme = value; applyTheme(); },
                    previewDensity: (value) => { previewDensity = value; applyDensity(); },
                    restoreSaved: () => { previewTheme = null; previewDensity = null; previewAccent = null; applyTheme(); applyDensity(); applyAccent(); },
                });
                applyTheme();
                applyDensity();
                applyAccent();
                media.addEventListener('change', () => { if ((previewTheme ?? savedTheme) === 'system') applyTheme(); });
            })();
        </script>
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


    <!-- Tailwind design foundation + Alpine entry (hybrid compatibility phase) -->
    @include('partials.i18n-bootstrap')
    @include('partials.loader-logo')
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
    <a href="#main-content" class="ui-skip-link">{{ __('navigation.skip') }}</a>
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
        window.AdasiDataTable = Object.freeze({
            defaults: () => ({ pageLength: window.AdasiPreferences?.pageSize || 25, lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]], language: @js(__('datatables')) }),
        });
        if (window.jQuery?.fn?.dataTable) {
            window.jQuery.extend(true, window.jQuery.fn.dataTable.defaults, window.AdasiDataTable.defaults());
            let generatedSearchId = 0;
            const labelDataTableSearch = (node) => {
                if (!(node instanceof Element)) return;

                const wrappers = [
                    ...(node.matches('.dataTables_filter') ? [node] : []),
                    ...node.querySelectorAll('.dataTables_filter'),
                ];

                wrappers.forEach((wrapper) => {
                    const searchInput = wrapper.querySelector('input[type="search"]');
                    if (!searchInput) return;

                    const tableId = wrapper.id?.replace(/_filter$/, '') || `adasi-data-table-${++generatedSearchId}`;
                    if (!searchInput.id) searchInput.id = `${tableId}-search`;
                    if (!searchInput.name) searchInput.name = `${tableId}_search`;
                    if (!searchInput.hasAttribute('aria-label')) {
                        searchInput.setAttribute('aria-label', window.AdasiI18n.t('datatables.search'));
                    }
                });
            };

            document.querySelectorAll('.dataTables_filter').forEach(labelDataTableSearch);
            new MutationObserver((records) => {
                records.forEach((record) => record.addedNodes.forEach(labelDataTableSearch));
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
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
            let badgeFetchActive = false;

            function updateBadges() {
                if (badgeFetchActive || (document.visibilityState && document.visibilityState === 'hidden')) {
                    return;
                }

                badgeFetchActive = true;
                const headers = {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };

                const notifCtrl = new AbortController();
                const notifTimeout = setTimeout(() => notifCtrl.abort(), 8000);

                // Notification badge
                const notifPromise = fetch("{{ route('notifications.unread-count') }}", {
                    headers,
                    signal: notifCtrl.signal
                })
                    .then(r => r.ok ? r.json() : null)
                    .then(data => {
                        if (!data) return;
                        document.querySelectorAll('.notif-badge').forEach(badge => {
                            const label = data.count > 0
                                ? window.AdasiI18n.choice('js.notification.unread_count', Number(data.count), { count: data.count })
                                : window.AdasiI18n.t('js.notification.button_label');
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.classList.remove('d-none');
                            } else {
                                badge.classList.add('d-none');
                            }
                            badge.closest('button[data-bs-toggle="dropdown"]')?.setAttribute('aria-label', label);
                        });

                        if (typeof updateNotificationCategoryBadges === 'function') {
                            updateNotificationCategoryBadges(data.category_counts);
                        }
                    })
                    .catch(() => {})
                    .finally(() => clearTimeout(notifTimeout));

                // Chat badge
                @if(auth()->user()->isPurchasing() || \App\Support\PortalContext::isImport(auth()->user()))
                    const chatCtrl = new AbortController();
                    const chatTimeout = setTimeout(() => chatCtrl.abort(), 8000);

                    const chatPromise = fetch("{{ route('conversations.unread-count') }}", {
                        headers,
                        signal: chatCtrl.signal
                    })
                        .then(r => r.ok ? r.json() : null)
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
                                    badge.classList.remove('d-none');
                                } else {
                                    badge.classList.add('d-none');
                                }
                            });
                        })
                        .catch(() => {})
                        .finally(() => clearTimeout(chatTimeout));

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
                    title: @js(__('navigation.keyboard_shortcuts')),
                    html: `
                        <div class="text-start">
                            <table class="table table-borderless table-sm mb-0">
                                <tr>
                                    <td width="40%"><kbd>Alt + D</kbd></td>
                                    <td>{{ __('navigation.back_dashboard') }}</td>
                                </tr>
                                <tr>
                                    <td><kbd>?</kbd></td>
                                    <td>{{ __('navigation.open_help') }}</td>
                                </tr>
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
                            @js(__('common.notification.single'))
                        );

                    const newBadge =
                        document.createElement('span');

                    newBadge.className =
                        'ui-status-chip ui-status-chip--error flex-shrink-0';
                    newBadge.dataset.notificationNewBadge = '';
                    newBadge.textContent = @js(__('common.states.new'));

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
