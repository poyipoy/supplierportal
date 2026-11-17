import { t } from './i18n.js';
import Alpine from 'alpinejs';
import { searchableFreeInput } from './searchable-free-input';
import './password-assistance';
import './unsaved-changes';
import './session-sync';
import './number-input-helper';
import './chart-theme';
import './preferences';
import './locale-switcher';
import './submit-guard';
import './async-form-submit';
import './adasi-loader';
import { bootEmbedReadiness } from './embed-readiness';
import './asset-protection';
import './server-tabs';
import './smart-panel';
import { adasiFileUploadComponent } from './file-upload';
import { searchableSelect } from './searchable-select';

window.Alpine = Alpine;
Alpine.data('searchableSelect', searchableSelect);
Alpine.data('searchableFreeInput', searchableFreeInput);
Alpine.data('adasiFileUploadComponent', adasiFileUploadComponent);

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

const embedReadiness = bootEmbedReadiness(window);

// A failed chunk only leaves its own component unregistered; the shell and toasts still start.
if (pageComponents.length) {
    pageComponents.forEach((componentPromise) => embedReadiness?.trackPromise?.(componentPromise));
    await Promise.allSettled(pageComponents);
}

const toastState = Alpine.reactive({
    visible: [],
    queued: [],
});

const toastTimers = new Map();
const toastDefaults = {
    success: 4000,
    info: 5000,
    warning: 6000,
    error: 8000,
    message: 0,
    progress: 0,
};
const toastIcons = {
    success: 'circle-check',
    error: 'circle-x',
    warning: 'triangle-alert',
    info: 'info',
    message: 'message-square-text',
    progress: 'loader-circle',
};
const toastTitles = {
    success: t('js.toast.completed'),
    error: t('js.toast.failed'),
    warning: t('js.toast.attention'),
    info: t('js.toast.update'),
    message: t('js.toast.message'),
    progress: t('js.processing'),
};
const allowedToastTypes = new Set(Object.keys(toastDefaults));
const maxVisibleToasts = 4;
let toastSequence = 0;

const normalizeToastType = (type) => type === 'action'
    ? 'message'
    : allowedToastTypes.has(type) ? type : 'info';
const normalizeToastDuration = (value, type) => {
    if (value === false || value === 0) return 0;
    const duration = Number(value);
    return Number.isFinite(duration) && duration > 0 ? duration : toastDefaults[type];
};
const normalizeProgress = (value) => {
    if (value === undefined || value === null || value === '') return null;
    const progress = Number(value);
    return Number.isFinite(progress) ? Math.min(100, Math.max(0, Math.round(progress))) : null;
};
const normalizeActionLimit = (value) => {
    const limit = Number(value);

    return Number.isInteger(limit) && limit > 2 ? Math.min(3, limit) : 2;
};
const normalizeActions = (actions, limit = 2) => Array.isArray(actions)
    ? actions
        .filter((action) => action && typeof action.label === 'string' && action.label.trim())
        .slice(0, normalizeActionLimit(limit))
        .map((action) => ({
            label: action.label.trim(),
            variant: ['primary', 'danger'].includes(action.variant) ? action.variant : 'secondary',
            url: typeof action.url === 'string' ? action.url : null,
            onClick: typeof action.onClick === 'function' ? action.onClick : null,
            disabled: action.disabled === true,
            dismiss: action.dismiss !== false,
        }))
    : [];

const findToast = (id) => toastState.visible.find((toast) => toast.id === id)
    || toastState.queued.find((toast) => toast.id === id);

const clearToastTimer = (id) => {
    const timer = toastTimers.get(id);
    if (timer?.handle) window.clearTimeout(timer.handle);
    toastTimers.delete(id);
};

const promoteQueuedToast = () => {
    if (toastState.visible.length >= maxVisibleToasts || !toastState.queued.length) return;
    const next = toastState.queued.shift();
    toastState.visible.unshift(next);
    window.requestAnimationFrame(() => {
        next.restored = false;
        scheduleToast(next);
    });
};

const removeToast = (id) => {
    clearToastTimer(id);
    const visibleIndex = toastState.visible.findIndex((toast) => toast.id === id);
    if (visibleIndex >= 0) {
        toastState.visible.splice(visibleIndex, 1);
        promoteQueuedToast();
        return;
    }

    const queuedIndex = toastState.queued.findIndex((toast) => toast.id === id);
    if (queuedIndex >= 0) toastState.queued.splice(queuedIndex, 1);
};

const emitToastDismissed = (id, reason) => {
    window.dispatchEvent(new CustomEvent('adasi:toast-dismissed', {
        detail: { id, reason },
    }));
};

const dismissToast = (id, reason = 'manual') => {
    const toast = findToast(id);
    if (!toast || toast.visible === false) return false;
    clearToastTimer(id);

    if (toastState.queued.some((item) => item.id === id)) {
        emitToastDismissed(id, reason);
        removeToast(id);
        return true;
    }

    toast.visible = false;
    emitToastDismissed(id, reason);
    window.setTimeout(() => removeToast(id), 180);
    return true;
};

function scheduleToast(toast, duration = toast.autoClose) {
    clearToastTimer(toast.id);
    if (!duration || duration <= 0 || !toast.visible) return;

    const startedAt = Date.now();
    const handle = window.setTimeout(() => dismissToast(toast.id, 'timeout'), duration);
    toastTimers.set(toast.id, { handle, startedAt, remaining: duration });
}

const pauseToast = (id) => {
    const timer = toastTimers.get(id);
    if (!timer?.handle) return;
    window.clearTimeout(timer.handle);
    timer.remaining = Math.max(0, timer.remaining - (Date.now() - timer.startedAt));
    timer.handle = null;
};

const resumeToast = (id) => {
    const toast = findToast(id);
    const timer = toastTimers.get(id);
    if (!toast || !timer || timer.handle || timer.remaining <= 0) return;
    scheduleToast(toast, timer.remaining);
};

const makeToast = (rawOptions = {}) => {
    const options = rawOptions && typeof rawOptions === 'object' ? rawOptions : {};
    const type = normalizeToastType(options.type);
    const progress = normalizeProgress(options.progress);
    const maxActions = normalizeActionLimit(options.maxActions);
    const actions = normalizeActions(options.actions, maxActions);
    const autoClose = actions.length && options.autoClose === undefined
        ? 0
        : normalizeToastDuration(options.autoClose, type);

    return {
        id: options.id || `adasi-toast-${Date.now()}-${++toastSequence}`,
        type,
        title: String(options.title || toastTitles[type]),
        message: String(options.message ?? options.text ?? ''),
        timestamp: options.timestamp ? String(options.timestamp) : '',
        icon: Object.values(toastIcons).includes(options.icon) ? options.icon : toastIcons[type],
        actions,
        maxActions,
        autoClose,
        hasProgress: type === 'progress' || progress !== null,
        indeterminate: type === 'progress' && progress === null,
        progress: progress ?? 0,
        progressLabel: String(options.progressLabel || (progress === null ? t('js.processing') : t('js.progress'))),
        restored: options.restored === true,
        visible: true,
    };
};

const showToast = (options = {}) => {
    if (options?.id) {
        const existing = findToast(options.id);
        if (existing) {
            updateToast(existing.id, options);
            window.requestAnimationFrame(() => {
                existing.restored = false;
            });
            return existing.id;
        }
    }

    const toast = makeToast(options);
    if (toastState.visible.length < maxVisibleToasts) {
        toastState.visible.unshift(toast);
        window.requestAnimationFrame(() => {
            toast.restored = false;
            scheduleToast(toast);
        });
    } else {
        toastState.queued.push(toast);
    }
    return toast.id;
};

const updateToast = (id, rawChanges = {}) => {
    const toast = findToast(id);
    if (!toast || !rawChanges || typeof rawChanges !== 'object') return false;

    const changes = rawChanges;
    const previousType = toast.type;
    const nextType = changes.type === undefined ? toast.type : normalizeToastType(changes.type);
    const typeChanged = nextType !== previousType;
    toast.type = nextType;

    if (changes.title !== undefined) toast.title = String(changes.title);
    if (changes.message !== undefined || changes.text !== undefined) {
        toast.message = String(changes.message ?? changes.text ?? '');
    }
    if (changes.timestamp !== undefined) toast.timestamp = String(changes.timestamp || '');
    if (changes.icon !== undefined && Object.values(toastIcons).includes(String(changes.icon))) {
        toast.icon = String(changes.icon);
    } else if (typeChanged) {
        toast.icon = toastIcons[nextType];
    }
    if (changes.maxActions !== undefined) toast.maxActions = normalizeActionLimit(changes.maxActions);
    if (changes.actions !== undefined) toast.actions = normalizeActions(changes.actions, toast.maxActions);

    if (changes.progress !== undefined || changes.indeterminate === true) {
        const progress = changes.indeterminate === true ? null : normalizeProgress(changes.progress);
        toast.hasProgress = true;
        toast.indeterminate = progress === null;
        toast.progress = progress ?? 0;
    } else if (typeChanged && nextType !== 'progress') {
        toast.hasProgress = false;
        toast.indeterminate = false;
    }
    if (changes.progressLabel !== undefined) toast.progressLabel = String(changes.progressLabel || t('js.progress'));
    if (changes.restored !== undefined) toast.restored = changes.restored === true;

    const actionsRequireManualClose = toast.actions.length && changes.autoClose === undefined;
    toast.autoClose = actionsRequireManualClose
        ? 0
        : normalizeToastDuration(
            changes.autoClose === undefined && typeChanged ? toastDefaults[nextType] : changes.autoClose ?? toast.autoClose,
            nextType,
        );

    if (toastState.visible.some((item) => item.id === id)) scheduleToast(toast);
    return true;
};

const runToastAction = (id, action) => {
    if (!action || action.disabled) return;
    try {
        if (action.onClick) {
            action.onClick({ id, dismiss: () => dismissToast(id, 'action') });
        } else if (action.url) {
            const target = new URL(action.url, window.location.href);
            if (['http:', 'https:'].includes(target.protocol)) {
                if (window.AdasiWorkspaceShell && target.origin === window.location.origin) {
                    window.AdasiWorkspaceShell.openUrl(target.href);
                } else {
                    window.location.assign(target.href);
                }
            }
        }
    } finally {
        if (action.dismiss) dismissToast(id, 'action');
    }
};

window.AdasiToast = Object.freeze({
    show: showToast,
    success: (message, options = {}) => showToast({ ...options, type: 'success', message }),
    error: (message, options = {}) => showToast({ ...options, type: 'error', message }),
    warning: (message, options = {}) => showToast({ ...options, type: 'warning', message }),
    info: (message, options = {}) => showToast({ ...options, type: 'info', message }),
    progress: (options = {}) => showToast({ ...options, type: 'progress', autoClose: 0 }),
    update: updateToast,
    dismiss: dismissToast,
    clear: () => {
        toastState.queued.splice(0);
        toastState.visible.slice().forEach((toast) => dismissToast(toast.id, 'clear'));
    },
});

window.AdasiQuickFlow = Object.freeze({
    isEnabled(trigger = null, actionKey = null) {
        if (trigger instanceof Element && trigger.dataset?.quickFlow === 'true') {
            return true;
        }
        if (document.documentElement.dataset.quickFlow === 'true') {
            return true;
        }
        try {
            const accountId = document.querySelector('meta[name="user-id"]')?.content?.trim();
            const key = accountId ? `adasi.quick_flow.${accountId}` : 'adasi.quick_flow';
            const raw = window.localStorage.getItem(key);
            if (!raw) return false;
            if (raw === 'true' || raw === '1') return true;
            const parsed = JSON.parse(raw);
            if (typeof parsed === 'boolean') return parsed;
            if (parsed && typeof parsed === 'object') {
                return actionKey ? Boolean(parsed[actionKey] ?? parsed.enabled) : Boolean(parsed.enabled);
            }
            return false;
        } catch {
            return false;
        }
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

if (Array.isArray(window.__adasiToastQueue) && window.__adasiToastQueue.length > 0) {
    window.__adasiToastQueue.splice(0).forEach((options) => window.AdasiToast.show(options));
}

Alpine.data('adasiToastCenter', () => ({
    state: toastState,
    dismiss: dismissToast,
    pause: pauseToast,
    resume: resumeToast,
    runAction: runToastAction,
}));

Alpine.data('adasiShell', () => ({
    desktopCollapsed: window.__adasiSidebarInitialCollapsed === true,
    mobileOpen: false,
    viewportIsDesktop: false,
    sidebarReturnFocus: null,
    viewportQuery: null,
    hoverPeek: false,
    hoverTimer: null,

    get sidebarIsExpanded() {
        return this.viewportIsDesktop ? (!this.desktopCollapsed || this.hoverPeek) : this.mobileOpen;
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
        this.$nextTick(() => window.requestAnimationFrame(() => {
            document.documentElement.dataset.sidebarMotionReady = 'true';
        }));
        this.viewportQuery.addEventListener('change', () => this.syncViewport());
        window.addEventListener('pageshow', () => this.syncViewport(), { passive: true });
        document.getElementById('sidebar')?.addEventListener('hidden.bs.dropdown', () => {
            if (this.hoverPeek && !document.getElementById('sidebar')?.matches(':hover')) {
                this.setHoverPeek(false);
            }
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

    clearHoverTimer() {
        if (this.hoverTimer) {
            window.clearTimeout(this.hoverTimer);
            this.hoverTimer = null;
        }
    },

    setHoverPeek(active) {
        this.hoverPeek = Boolean(active);
        if (this.hoverPeek) {
            document.documentElement.dataset.sidebarHoverPeek = 'true';
        } else {
            delete document.documentElement.dataset.sidebarHoverPeek;
        }
        this.syncSidebarTooltips();
    },

    onSidebarPointerEnter() {
        if (!this.viewportIsDesktop || !this.desktopCollapsed) {
            return;
        }
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.clearHoverTimer();
        this.hoverTimer = window.setTimeout(() => {
            this.hoverTimer = null;
            if (this.viewportIsDesktop && this.desktopCollapsed) {
                this.setHoverPeek(true);
            }
        }, 140);
    },

    onSidebarPointerLeave() {
        this.clearHoverTimer();
        if (!this.hoverPeek) {
            return;
        }

        this.hoverTimer = window.setTimeout(() => {
            this.hoverTimer = null;
            const sidebar = document.getElementById('sidebar');
            if (sidebar?.querySelector('.dropdown-menu.show')) {
                return;
            }
            this.setHoverPeek(false);
        }, 120);
    },

    applySidebarState() {
        const isCollapsedDesktop = this.viewportIsDesktop && this.desktopCollapsed;
        document.documentElement.dataset.sidebarCollapsed = String(isCollapsedDesktop);
        if (!isCollapsedDesktop) {
            this.clearHoverTimer();
            this.hoverPeek = false;
            delete document.documentElement.dataset.sidebarHoverPeek;
        }
        this.syncSidebarTooltips();
    },

    syncSidebarTooltips() {
        this.$nextTick(() => window.requestAnimationFrame(() => {
            const Tooltip = window.bootstrap?.Tooltip;
            if (!Tooltip) return;

            const enabled = this.viewportIsDesktop && this.desktopCollapsed && !this.hoverPeek;
            document.querySelectorAll('[data-sidebar-tooltip]').forEach((element) => {
                const instance = Tooltip.getInstance(element);

                if (enabled) {
                    Tooltip.getOrCreateInstance(element, {
                        placement: 'right',
                        container: document.body,
                        customClass: 'sidebar-nav-tooltip',
                        delay: { show: 180, hide: 60 },
                    });
                } else {
                    instance?.dispose();
                }
            });
        }));
    },

    toggleSidebar(trigger = null) {
        if (this.viewportIsDesktop) {
            this.clearHoverTimer();
            this.hoverPeek = false;
            delete document.documentElement.dataset.sidebarHoverPeek;
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

    trapSidebarFocus(event) {
        if (this.viewportIsDesktop || !this.mobileOpen) return;

        const toggle = document.querySelector('.sidebar-toggle--mobile');
        const navigationItems = [...document.querySelectorAll('#sidebar .sidebar-menu a[href], #sidebar .sidebar-menu button:not([disabled]), #sidebar .sidebar-menu [tabindex]:not([tabindex="-1"])')];
        const items = toggle ? [toggle, ...navigationItems] : navigationItems;
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

const resolveDropdownParts = (node) => {
    const container = node.closest('.dropdown, .dropup, .dropstart, .dropend, .btn-group') || node.parentElement;
    const toggle = node.matches('[data-bs-toggle="dropdown"]')
        ? node
        : container?.querySelector('[data-bs-toggle="dropdown"]')
            || (node.getAttribute('aria-labelledby') ? document.getElementById(node.getAttribute('aria-labelledby')) : null);
    return { container, toggle };
};

const hideDropdownNode = (node) => {
    const { container, toggle } = resolveDropdownParts(node);
    if (toggle && window.bootstrap?.Dropdown) {
        window.bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
        return;
    }

    node.classList.remove('show');
    toggle?.classList.remove('show');
    toggle?.setAttribute('aria-expanded', 'false');
    container?.querySelector('.dropdown-menu.show')?.classList.remove('show');
};

window.AdasiDismissDropdowns = (exceptToggle = null) => {
    document.querySelectorAll('.dropdown-menu.show, [data-bs-toggle="dropdown"].show').forEach((node) => {
        const { toggle } = resolveDropdownParts(node);
        if (exceptToggle && toggle === exceptToggle) return;
        hideDropdownNode(node);
    });
};

document.addEventListener('pointerdown', (event) => {
    const openNodes = document.querySelectorAll('.dropdown-menu.show, [data-bs-toggle="dropdown"].show');
    if (!openNodes.length) return;

    const target = event.target instanceof Element ? event.target : event.target?.parentElement;
    const keepToggles = new Set();
    if (target) {
        openNodes.forEach((node) => {
            const { container, toggle } = resolveDropdownParts(node);
            if (toggle && (container?.contains(target) || node.contains(target))) {
                keepToggles.add(toggle);
            }
        });
    }

    openNodes.forEach((node) => {
        const { container, toggle } = resolveDropdownParts(node);
        if (toggle && keepToggles.has(toggle)) return;
        if (!toggle && target && (container?.contains(target) || node.contains(target))) return;
        hideDropdownNode(node);
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
    if (hasMarker('[data-pr-form-export]')) import('./pr-form-export');
    if (hasMarker('#prFormTotals')) import('./pr-totals-sync');
    if (hasMarker('[data-po-document-upload]')) import('./po-document-upload').then(module => module.bootPoDocumentUploads());
    if (hasMarker('[data-adasi-time-picker]')) import('./time-picker').then(module => module.bootTimePickers(document));
};

const showFallbackClientSplash = (redirectUrl) => {
    try {
        window.sessionStorage?.setItem('adasi.auth_splash.client_start', String(Date.now()));
    } catch {}

    const gifSrc = document.body?.dataset?.splashGif || '';
    const fallbackSrc = document.body?.dataset?.splashWordmark || '';
    const reduced = Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);

    if (document.body && !document.querySelector('[data-auth-splash]')) {
        const overlay = document.createElement('div');
        overlay.className = 'adasi-client-splash';
        overlay.setAttribute('data-auth-splash', '');
        overlay.setAttribute('data-splash-reason', 'post-login');
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');

        const img = document.createElement('img');
        img.className = 'adasi-client-splash__media';
        img.width = 384;
        img.height = 153;
        img.alt = '';
        img.decoding = 'sync';
        img.src = (reduced && fallbackSrc) ? fallbackSrc : (gifSrc || fallbackSrc);
        overlay.appendChild(img);
        document.body.appendChild(overlay);
    }

    if (typeof redirectUrl === 'string' && redirectUrl !== '') {
        window.setTimeout(() => window.location.assign(redirectUrl), 24);
    }
};

const bootLoginSplashForms = () => {
    document.querySelectorAll('form[data-login-splash-form]').forEach((form) => {
        if (form.dataset.loginSplashBound === 'true') return;
        form.dataset.loginSplashBound = 'true';

        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented || form.dataset.loginNativeFallback === 'true') return;
            if (!document.body?.dataset?.splashGif || typeof window.fetch !== 'function') return;

            event.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"]');
            const errorAlert = form.querySelector('[data-login-error-alert]');
            if (errorAlert) {
                errorAlert.hidden = true;
                errorAlert.textContent = '';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');
            }

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
                    if (payload.splash && window.AdasiAuthSplash?.activateClientPostLoginSplash) {
                        window.AdasiAuthSplash.activateClientPostLoginSplash(payload.redirect_url);
                    } else if (payload.splash) {
                        showFallbackClientSplash(payload.redirect_url);
                    } else {
                        window.location.assign(payload.redirect_url);
                    }
                    return;
                }

                if (response.status === 422 && payload?.errors) {
                    if (payload.turnstile_required && !form.querySelector('.cf-turnstile')) {
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
                    window.turnstile?.reset?.();
                    form.querySelector('input[name="password"], input[name="code"]')?.focus?.();
                    return;
                }

                if (response.status === 419) {
                    window.location.reload();
                    return;
                }

                // Unknown non-OK or throttling response: fall back cleanly to standard POST rendering.
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } catch {
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } finally {
                if (!document.querySelector('[data-auth-splash]') && submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
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
        import('./workspace-shell')
            .then((module) => module.bootWorkspaceShell())
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
