import './debug-logger.js';
import { onDataTableEvent, offDataTableEvent } from './datatables-events.js';
import { AdasiModal, bootAdasiModals } from './adasi-modal.js';
import { AdasiDropdown, bootAdasiDropdowns } from './adasi-dropdown.js';
import { AdasiTooltip, bootAdasiTooltips } from './adasi-tooltip.js';
import { AdasiTabs, bootAdasiTabs } from './adasi-tabs.js';
import { t } from './i18n.js';
import Alpine from 'alpinejs';
import { searchableFreeInput } from './searchable-free-input';
import './password-assistance';
import './unsaved-changes';
import './session-sync';
import { adasiSessionModal } from './session-expired-modal';
import './number-input-helper';
import './chart-theme';
import './preferences';
import './locale-switcher';
import './submit-guard';
import './form-a11y';
import './overlay-modality';
import './async-form-submit';
import './adasi-loader';
import { bootEmbedReadiness } from './embed-readiness';
import './asset-protection';
import './server-tabs';
import './smart-panel';
import { navigateInWorkspaceOrTop } from './workspace-navigation';
import { adasiFileUploadComponent } from './file-upload';
import { searchableSelect } from './searchable-select';
import { bindSidebarSplitter } from './splitter';
import { registerToastCenter } from './toast-center';

window.Alpine = Alpine;
window.AdasiDataTableEvents = Object.freeze({ on: onDataTableEvent, off: offDataTableEvent });
window.AdasiModal = AdasiModal;
window.AdasiDropdown = AdasiDropdown;
window.AdasiTooltip = AdasiTooltip;
window.AdasiTabs = AdasiTabs;
bootAdasiModals(document);
bootAdasiDropdowns(document);
bootAdasiTooltips(document);
bootAdasiTabs(document);
Alpine.data('searchableSelect', searchableSelect);
Alpine.data('searchableFreeInput', searchableFreeInput);
Alpine.data('adasiFileUploadComponent', adasiFileUploadComponent);
Alpine.data('adasiSessionModal', adasiSessionModal);

// Page-specific modules load only where their markup is present. Module scripts run after parsing,
// so the markers are already in the DOM here.
const hasMarker = (selector) => document.querySelector(selector) !== null;

// Alpine components must be registered before Alpine.start(), so their imports are awaited below.
const pageComponents = [
    ['[x-data^="customizationForm"]', () => Promise.all([import('./regional-sample'), import('./customization-form')])
        .then(([, module]) => Alpine.data('customizationForm', module.customizationForm))],
    ['[x-data^="notificationPreferencesForm"]', () => import('./notification-preferences')
        .then((module) => Alpine.data('notificationPreferencesForm', module.notificationPreferencesForm))],
    ['[x-data^="supplierAuditForm"]', () => import('./supplier-audit-form')
        .then((module) => Alpine.data('supplierAuditForm', module.supplierAuditForm))],
    ['[x-data^="paymentVoucherForm"]', () => import('./payment-voucher-form')
        .then((module) => Alpine.data('paymentVoucherForm', module.paymentVoucherForm))],
    ['[x-data^="psiForm"]', () => import('./psi-form')
        .then((module) => Alpine.data('psiForm', module.psiForm))],
].filter(([selector]) => hasMarker(selector)).map(([, load]) => load());

// Calendars initialize asynchronously (the engine itself is lazy), so this module loads on demand.
// Until it does, this stand-in serves later callers such as server-tab swaps; calendar.js replaces it.
const CALENDAR_SELECTOR = '[data-adasi-date-picker], [data-adasi-date-range]';
let calendarModule = null;
const loadCalendars = () => (calendarModule ??= import('./calendar').then(async (module) => {
    await module.bootAdasiCalendars();
    return module;
}));
window.AdasiCalendar = Object.freeze({
    initialize: (root = document) => {
        if (root?.matches?.(CALENDAR_SELECTOR) || root?.querySelector?.(CALENDAR_SELECTOR)) {
            loadCalendars().then((module) => module.initializeAdasiCalendars(root));
        }
    },
    // Nothing can be open or need a reset before the module has loaded.
    reset: () => {},
    closeActive: () => {},
});

const FILTER_BAR_SELECTOR = '[data-adasi-filter-bar]';
let filterBarModule = null;
const loadFilterBar = () => (filterBarModule ??= import('./filter-bar'));
window.AdasiFilterBar = Object.freeze({
    bindLive: (container, options = {}) => loadFilterBar().then((module) => module.bindLive(container, options)),
    sync: (container) => (filterBarModule ? filterBarModule.then((module) => module.sync(container)) : Promise.resolve()),
    reset: (container) => (filterBarModule ? filterBarModule.then((module) => module.reset(container)) : Promise.resolve()),
});

const embedReadiness = bootEmbedReadiness(window);

// A failed chunk only leaves its own component unregistered; the shell and toasts still start.
if (pageComponents.length) {
    pageComponents.forEach((componentPromise) => embedReadiness?.trackPromise?.(componentPromise));
    await Promise.allSettled(pageComponents);
}

let workspaceShellBootPromise = null;

const ensureWorkspaceShell = () => {
    if (window.AdasiWorkspaceShell) {
        return Promise.resolve(window.AdasiWorkspaceShell);
    }
    if (document.documentElement.dataset.workspaceShell !== 'true') {
        return Promise.resolve(null);
    }
    if (!workspaceShellBootPromise) {
        workspaceShellBootPromise = import('./workspace-shell')
            .then((module) => module.bootWorkspaceShell())
            .catch(() => null);
    }
    return workspaceShellBootPromise;
};

window.AdasiNavigateUrl = (url) => navigateInWorkspaceOrTop(url, {
    win: window,
    ensureShell: ensureWorkspaceShell,
});

const toastCenterOptions = {
    navigateUrl: (url) => navigateInWorkspaceOrTop(url, {
        win: window,
        ensureShell: ensureWorkspaceShell,
    }),
    flushQueue: false,
};
const isWorkspaceEmbedFrame = document.documentElement.dataset.workspaceEmbed === 'true'
    && window.self !== window.top;
let toastCenter = null;

if (!isWorkspaceEmbedFrame) {
    toastCenter = registerToastCenter(Alpine, toastCenterOptions);
}

window.AdasiQuickFlow = Object.freeze({
    isEnabled(trigger = null, actionKey = null) {
        if (trigger instanceof Element && trigger.dataset?.quickFlow === 'true') {
            return true;
        }
        try {
            const accountId = document.querySelector('meta[name="user-id"]')?.content?.trim();
            const key = accountId ? `adasi.quick_flow.${accountId}` : 'adasi.quick_flow';
            const raw = window.localStorage.getItem(key);
            if (raw !== null) {
                if (raw === 'true' || raw === '1') return true;
                if (raw === 'false' || raw === '0') return false;
                const parsed = JSON.parse(raw);
                if (typeof parsed === 'boolean') return parsed;
                if (parsed && typeof parsed === 'object') {
                    return actionKey ? Boolean(parsed[actionKey] ?? parsed.enabled) : Boolean(parsed.enabled);
                }
                return false;
            }
        } catch {
            // Fall back to server-delivered document dataset when localStorage is blocked.
        }

        return document.documentElement.dataset.quickFlow === 'true';
    },
});

if (document.documentElement.dataset.workspaceEmbed === 'true') {
    if (window.self === window.top) {
        try {
            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('_embed');
            window.location.replace(cleanUrl.toString());
        } catch {}
    } else {
        await import('./workspace-embed-bridge').then((module) => module.bootWorkspaceEmbedBridge());
    }
}

if (toastCenter) {
    toastCenter.flushQueue();
} else if (window.AdasiToast && Array.isArray(window.__adasiToastQueue) && window.__adasiToastQueue.length > 0) {
    window.__adasiToastQueue.splice(0).forEach((options) => window.AdasiToast.show(options));
} else if (!window.AdasiToast) {
    registerToastCenter(Alpine, { ...toastCenterOptions, flushQueue: true });
}

Alpine.data('adasiShell', () => ({
    desktopCollapsed: window.__adasiSidebarInitialCollapsed === true,
    mobileOpen: false,
    viewportIsDesktop: false,
    sidebarReturnFocus: null,
    viewportQuery: null,
    menuSearchQuery: '',
    menuSearchMatchCount: null,
    menuSearchStatusText: '',
    sidebarSplitterController: null,

    get sidebarIsExpanded() {
        return this.viewportIsDesktop ? !this.desktopCollapsed : this.mobileOpen;
    },

    get sidebarToggleLabel() {
        if (this.viewportIsDesktop) {
            return this.desktopCollapsed
                ? t('js.sidebar.expand')
                : t('js.sidebar.collapse');
        }

        return this.mobileOpen
            ? t('js.sidebar.close_menu')
            : t('js.sidebar.open_menu');
    },

    init() {
        this.viewportQuery = window.matchMedia('(min-width: 992px)');
        this.syncViewport();
        this.initSidebarSplitter();
        this.$nextTick(() => window.requestAnimationFrame(() => {
            document.documentElement.dataset.sidebarMotionReady = 'true';
        }));
        this.viewportQuery.addEventListener('change', () => this.syncViewport());
        window.addEventListener('pageshow', () => this.syncViewport(), { passive: true });
    },

    destroy() {
        this.sidebarSplitterController?.destroy?.();
        this.sidebarSplitterController = null;
    },

    initSidebarSplitter() {
        if (this.sidebarSplitterController || document.documentElement.dataset.workspaceEmbed === 'true') {
            return;
        }

        const sidebarEl = document.getElementById('sidebar');
        const splitterEl = sidebarEl?.querySelector('[data-sidebar-splitter]');
        const liveRegionEl = sidebarEl?.querySelector('[data-sidebar-resize-live]');
        if (!sidebarEl || !splitterEl) {
            return;
        }

        const valueTemplate = liveRegionEl?.dataset.valueTemplate || ':width px';
        const announcementTemplate = liveRegionEl?.dataset.announcementTemplate || 'Sidebar width :width px';
        const resetAnnouncement = liveRegionEl?.dataset.resetText || 'Sidebar width reset to default (256 px)';
        const collapsedAnnouncement = liveRegionEl?.dataset.collapsedText || 'Sidebar collapsed (64 px)';

        this.sidebarSplitterController = bindSidebarSplitter({
            splitterEl,
            sidebarEl,
            rootEl: document.documentElement,
            windowRef: window,
            getCollapsed: () => this.desktopCollapsed,
            setCollapsed: (collapsed, { persist = false } = {}) => {
                const nextCollapsed = Boolean(collapsed);
                const changed = this.desktopCollapsed !== nextCollapsed;
                this.desktopCollapsed = nextCollapsed;
                if (persist) {
                    this.storeDesktopSidebarState();
                }
                if (changed || document.documentElement.dataset.sidebarCollapsed !== String(this.viewportIsDesktop && nextCollapsed)) {
                    const isCollapsedDesktop = this.viewportIsDesktop && nextCollapsed;
                    document.documentElement.dataset.sidebarCollapsed = String(isCollapsedDesktop);
                    this.syncSidebarTooltips();
                }
            },
            getIsDesktop: () => this.viewportIsDesktop,
            readStoredWidth: () => window.AdasiSidebarPreferences?.readWidth?.() ?? null,
            writeStoredWidth: (width) => window.AdasiSidebarPreferences?.writeWidth?.(width),
            formatValueText: (width) => valueTemplate.replace(':width', String(width)),
            formatAnnouncement: (width) => announcementTemplate.replace(':width', String(width)),
            resetAnnouncement,
            collapsedAnnouncement,
            liveRegionEl,
        });
    },

    syncViewport() {
        const wasDesktop = this.viewportIsDesktop;
        this.viewportIsDesktop = this.viewportQuery?.matches
            ?? window.matchMedia('(min-width: 992px)').matches;

        if (this.viewportIsDesktop) {
            this.mobileOpen = false;
            if (!wasDesktop) this.desktopCollapsed = this.readStoredSidebarState();
        } else {
            this.desktopCollapsed = false;
        }

        this.applySidebarState();
    },

    readStoredSidebarState() {
        try {
            return window.AdasiSidebarPreferences?.read() === true;
        } catch (error) {
            return false;
        }
    },

    storeDesktopSidebarState() {
        try {
            window.AdasiSidebarPreferences?.write(this.desktopCollapsed);
        } catch (error) {
            // Storage can be unavailable in restricted browser contexts.
        }
    },

    applySidebarState() {
        const isCollapsedDesktop = this.viewportIsDesktop && this.desktopCollapsed;
        document.documentElement.dataset.sidebarCollapsed = String(isCollapsedDesktop);
        this.syncSidebarTooltips();
        this.sidebarSplitterController?.sync?.();
    },

    syncSidebarTooltips() {
        this.$nextTick(() => window.requestAnimationFrame(() => {
            window.AdasiTooltip?.syncSidebarState?.(document);
        }));
    },

    toggleSidebar(trigger = null) {
        if (this.viewportIsDesktop) {
            this.desktopCollapsed = !this.desktopCollapsed;
            this.storeDesktopSidebarState();
            this.applySidebarState();
            return;
        }

        this.mobileOpen = !this.mobileOpen;

        if (this.mobileOpen) {
            this.sidebarReturnFocus = trigger instanceof HTMLElement
                ? trigger
                : document.activeElement;
            this.$nextTick(() => document.querySelector('#sidebar .sidebar-menu a[href]')?.focus());
        } else {
            this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
        }
    },

    closeMobileSidebar(restoreFocus = true) {
        if (!this.viewportIsDesktop && this.mobileOpen) {
            this.mobileOpen = false;
            if (restoreFocus) {
                this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
            }
        }
    },

    activateSidebarSearch() {
        if (this.viewportIsDesktop && this.desktopCollapsed) {
            this.desktopCollapsed = false;
            this.storeDesktopSidebarState();
            this.applySidebarState();
        }
        this.$nextTick(() => document.getElementById('sidebarMenuSearch')?.focus());
    },

    filterSidebarMenu() {
        const nav = document.querySelector('#sidebar .sidebar-menu');
        if (!nav) return;

        const q = String(this.menuSearchQuery || '').trim().toLowerCase();
        let matchCount = 0;
        let currentHeading = null;
        let currentHeadingText = '';
        let headingHasMatch = false;

        const finalizeHeading = () => {
            if (currentHeading) {
                currentHeading.hidden = Boolean(q) && !headingHasMatch;
            }
        };

        for (const child of nav.children) {
            if (child.classList.contains('sidebar-heading')) {
                finalizeHeading();
                currentHeading = child;
                currentHeadingText = (child.textContent || '').trim().toLowerCase();
                headingHasMatch = false;
                continue;
            }

            if (child.matches('a.sidebar-link')) {
                const labelText = (
                    child.querySelector('.sidebar-link-label')?.textContent
                    || child.getAttribute('aria-label')
                    || child.textContent
                    || ''
                ).trim().toLowerCase();
                const matches = !q || labelText.includes(q) || currentHeadingText.includes(q);
                child.hidden = !matches;
                if (matches) {
                    matchCount += 1;
                    headingHasMatch = true;
                }
            }
        }
        finalizeHeading();

        this.menuSearchMatchCount = q ? matchCount : null;
        const liveEl = nav.querySelector('[data-sidebar-search-live]');
        if (!q) {
            this.menuSearchStatusText = '';
        } else if (matchCount === 0) {
            this.menuSearchStatusText = liveEl?.dataset.emptyText || '';
        } else {
            const tpl = liveEl?.dataset.resultsTemplate || ':count';
            this.menuSearchStatusText = tpl.replace(':count', String(matchCount));
        }
    },

    clearMenuSearch(event = null) {
        if (!this.menuSearchQuery) return;
        event?.preventDefault?.();
        event?.stopPropagation?.();
        this.menuSearchQuery = '';
        this.filterSidebarMenu();
        this.$nextTick(() => document.getElementById('sidebarMenuSearch')?.focus());
    },

    trapSidebarFocus(event) {
        if (this.viewportIsDesktop || !this.mobileOpen) return;

        const toggle = document.querySelector('.sidebar-toggle--mobile');
        const sidebarControls = [...document.querySelectorAll('#sidebar .sidebar-control input, #sidebar .sidebar-control button:not([disabled]), #sidebar .sidebar-menu a[href], #sidebar .sidebar-menu button:not([disabled]), #sidebar .sidebar-menu [tabindex]:not([tabindex="-1"])')]
            .filter((el) => !el.hidden && el.offsetParent !== null);
        const items = toggle ? [toggle, ...sidebarControls] : sidebarControls;
        if (!items.length) return;

        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
}));

Alpine.start();
window.AdasiCalendar.initialize(document);

window.AdasiDismissDropdowns = (exceptToggle = null) => {
    window.AdasiDropdown?.dismissAll?.(exceptToggle, document);
};

document.addEventListener('pointerdown', (event) => {
    const openMenus = document.querySelectorAll('[data-ui-dropdown-menu]:popover-open, .dropdown-menu:popover-open, [data-ui-dropdown-menu].show, .dropdown-menu.show');
    if (!openMenus.length) return;

    const target = event.target instanceof Element ? event.target : event.target?.parentElement;
    openMenus.forEach((menu) => {
        const id = menu.id;
        const escapedId = id && typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(id) : id;
        const trigger = escapedId
            ? document.querySelector(`[popovertarget="${escapedId}"], [data-ui-dropdown-trigger][aria-controls="${escapedId}"]`)
            : null;
        if (target && (menu.contains(target) || trigger?.contains(target))) return;
        window.AdasiDropdown?.hide?.(menu, document);
    });
}, true);

const bootImports = () => {
    if (hasMarker('[data-exchange-rates-workspace]')) import('./exchange-rates').then(module => module.bootExchangeRates());
    if (document.querySelector('[data-local-import]')) import('./local-procurement-import');
    if (document.querySelector('[data-gr-table]')) {
        const grTableImport = import('./local-po-gr-table').then(module => module.bootLocalPoGrTable());
        embedReadiness?.trackPromise?.(grTableImport);
    }
    if (hasMarker('[data-advanced-export], [data-format-export-form]')) import('./advanced-export');
    if (hasMarker('[data-dashboard-audience] [data-editor-config]')) import('./dashboard-editor');
    if (hasMarker('[data-quotation-form-export]')) import('./quotation-form-export');
    if (hasMarker('[data-quotation-availability-guard]')) import('./quotation-availability-guard').then(module => module.initQuotationAvailabilityGuards());
    if (hasMarker('[data-pr-form-export]')) import('./pr-form-export');
    if (hasMarker('#prFormTotals')) import('./pr-totals-sync');
    if (hasMarker('[data-po-document-upload]')) import('./po-document-upload').then(module => module.bootPoDocumentUploads());
    if (hasMarker('[data-adasi-time-picker]')) import('./time-picker').then(module => module.bootTimePickers(document));
    if (hasMarker(FILTER_BAR_SELECTOR)) loadFilterBar();
};

const setLoginButtonLoading = (submitBtn, isLoading) => {
    if (!submitBtn) return;
    const spinner = submitBtn.querySelector('[data-login-submit-spinner]');
    const label = submitBtn.querySelector('[data-login-submit-label]');
    submitBtn.disabled = Boolean(isLoading);
    if (isLoading) {
        submitBtn.setAttribute('aria-busy', 'true');
    } else {
        submitBtn.removeAttribute('aria-busy');
    }
    if (spinner) spinner.hidden = !isLoading;
    if (label) label.hidden = Boolean(isLoading);
};

const bootLoginSplashForms = () => {
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form[data-login-splash-form]').forEach((form) => {
            delete form.dataset.loginNativeFallback;
            setLoginButtonLoading(form.querySelector('[data-login-submit-btn], button[type="submit"]'), false);
        });
    });

    document.querySelectorAll('form[data-login-splash-form]').forEach((form) => {
        if (form.dataset.loginSplashBound === 'true') return;
        form.dataset.loginSplashBound = 'true';

        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) return;

            const submitBtn = form.querySelector('[data-login-submit-btn], button[type="submit"]');
            if (form.dataset.loginNativeFallback === 'true' || typeof window.fetch !== 'function') {
                setLoginButtonLoading(submitBtn, true);
                return;
            }

            event.preventDefault();

            const errorAlert = form.querySelector('[data-login-error-alert]');
            if (errorAlert) {
                errorAlert.hidden = true;
                errorAlert.textContent = '';
            }
            form.querySelectorAll('[data-login-ajax-invalid="true"]').forEach((field) => {
                field.removeAttribute('data-login-ajax-invalid');
                field.setAttribute('aria-invalid', 'false');
                if (errorAlert?.id) {
                    const remaining = (field.getAttribute('aria-describedby') || '')
                        .split(/\s+/)
                        .filter((id) => id && id !== errorAlert.id);
                    if (remaining.length) field.setAttribute('aria-describedby', remaining.join(' '));
                    else field.removeAttribute('aria-describedby');
                }
            });
            setLoginButtonLoading(submitBtn, true);
            let keepLoading = false;

            try {
                const response = await window.fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Auth-Splash-Client': '1',
                    },
                });

                const payload = await response.json().catch(() => null);

                if (response.ok && payload?.ok && typeof payload.redirect_url === 'string') {
                    keepLoading = true;
                    window.location.assign(payload.redirect_url);
                    return;
                }

                if (response.status === 422 && payload?.errors) {
                    if (payload.turnstile_required && !form.querySelector('.cf-turnstile')) {
                        keepLoading = true;
                        form.dataset.loginNativeFallback = 'true';
                        HTMLFormElement.prototype.submit.call(form);
                        return;
                    }
                    const firstFieldErrors = Object.values(payload.errors)[0];
                    const firstMessage = Array.isArray(firstFieldErrors) ? firstFieldErrors[0] : (payload.message || '');
                    if (errorAlert && firstMessage) {
                        errorAlert.textContent = firstMessage;
                        errorAlert.hidden = false;
                    }
                    for (const fieldKey of Object.keys(payload.errors)) {
                        const field = form.querySelector(`[name="${CSS.escape(fieldKey)}"]`);
                        if (!field) continue;
                        field.setAttribute('data-login-ajax-invalid', 'true');
                        field.setAttribute('aria-invalid', 'true');
                        if (errorAlert?.id) {
                            const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                            field.setAttribute('aria-describedby', [...new Set([...ids, errorAlert.id])].join(' '));
                        }
                    }
                    window.turnstile?.reset?.();
                    form.querySelector('input[name="password"], input[name="code"]')?.focus?.();
                    return;
                }

                if (response.status === 419) {
                    keepLoading = true;
                    window.location.reload();
                    return;
                }

                // Unknown non-OK or throttling response: fall back cleanly to standard POST rendering.
                keepLoading = true;
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } catch {
                keepLoading = true;
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } finally {
                if (!keepLoading) {
                    setLoginButtonLoading(submitBtn, false);
                }
            }
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        bootImports();
        bootLoginSplashForms();
    });
} else {
    bootImports();
    bootLoginSplashForms();
}

// Registered last so the synchronous DOM-ready work above completes first.
// Optional imports and network requests do not gate core readiness.
const acknowledgeAuthSplashCore = () => {
    if (document.documentElement.dataset.workspaceShell === 'true') {
        ensureWorkspaceShell()
            .then((shell) => shell?.whenInitialFrameReady?.())
            .catch(() => null)
            .finally(() => {
                queueMicrotask(() => Alpine.nextTick(() => window.AdasiAuthSplash?.markCoreReady()));
            });
        return;
    }

    // The microtask runs after synchronous DOM-ready listeners, including
    // handlers registered later in the parsed document. Alpine settles next.
    queueMicrotask(() => Alpine.nextTick(() => window.AdasiAuthSplash?.markCoreReady()));
};
if (document.readyState !== 'complete' && !window.AdasiAuthSplash?.domReady) {
    document.addEventListener('DOMContentLoaded', acknowledgeAuthSplashCore, { once: true });
} else {
    acknowledgeAuthSplashCore();
}
