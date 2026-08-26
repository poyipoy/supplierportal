import Alpine from 'alpinejs';

window.Alpine = Alpine;

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
    success: 'Operation completed',
    error: 'Action could not be completed',
    warning: 'Attention required',
    info: 'Update',
    message: 'New message',
    progress: 'Processing',
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
        progressLabel: String(options.progressLabel || (progress === null ? 'Processing' : 'Progress')),
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
    if (changes.progressLabel !== undefined) toast.progressLabel = String(changes.progressLabel || 'Progress');
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
            if (['http:', 'https:'].includes(target.protocol)) window.location.assign(target.href);
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

    get sidebarIsExpanded() {
        return this.viewportIsDesktop ? !this.desktopCollapsed : this.mobileOpen;
    },

    get sidebarToggleLabel() {
        if (this.viewportIsDesktop) {
            return this.desktopCollapsed
                ? 'Expand sidebar navigation'
                : 'Collapse sidebar navigation';
        }

        return this.mobileOpen
            ? 'Close navigation menu'
            : 'Open navigation menu';
    },

    init() {
        this.viewportQuery = window.matchMedia('(min-width: 992px)');
        this.syncViewport();
        this.$nextTick(() => window.requestAnimationFrame(() => {
            document.documentElement.dataset.sidebarMotionReady = 'true';
        }));
        this.viewportQuery.addEventListener('change', () => this.syncViewport());
        window.addEventListener('pageshow', () => this.syncViewport(), { passive: true });
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
            return window.localStorage.getItem('sidebarCollapsed') === 'true';
        } catch (error) {
            return false;
        }
    },

    storeDesktopSidebarState() {
        try {
            window.localStorage.setItem('sidebarCollapsed', String(this.desktopCollapsed));
        } catch (error) {
            // Storage can be unavailable in restricted browser contexts.
        }
    },

    applySidebarState() {
        document.documentElement.dataset.sidebarCollapsed = String(
            this.viewportIsDesktop && this.desktopCollapsed,
        );
        this.syncSidebarTooltips();
    },

    syncSidebarTooltips() {
        this.$nextTick(() => window.requestAnimationFrame(() => {
            const Tooltip = window.bootstrap?.Tooltip;
            if (!Tooltip) return;

            const enabled = this.viewportIsDesktop && this.desktopCollapsed;
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
