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
const normalizeActions = (actions) => Array.isArray(actions)
    ? actions
        .filter((action) => action && typeof action.label === 'string' && action.label.trim())
        .slice(0, 2)
        .map((action) => ({
            label: action.label.trim(),
            variant: action.variant === 'primary' ? 'primary' : 'secondary',
            url: typeof action.url === 'string' ? action.url : null,
            onClick: typeof action.onClick === 'function' ? action.onClick : null,
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
    window.requestAnimationFrame(() => scheduleToast(next));
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

const dismissToast = (id) => {
    const toast = findToast(id);
    if (!toast) return false;
    clearToastTimer(id);

    if (toastState.queued.some((item) => item.id === id)) {
        removeToast(id);
        return true;
    }

    toast.visible = false;
    window.setTimeout(() => removeToast(id), 180);
    return true;
};

function scheduleToast(toast, duration = toast.autoClose) {
    clearToastTimer(toast.id);
    if (!duration || duration <= 0 || !toast.visible) return;

    const startedAt = Date.now();
    const handle = window.setTimeout(() => dismissToast(toast.id), duration);
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
    const actions = normalizeActions(options.actions);
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
        autoClose,
        hasProgress: type === 'progress' || progress !== null,
        indeterminate: type === 'progress' && progress === null,
        progress: progress ?? 0,
        progressLabel: String(options.progressLabel || (progress === null ? 'Processing' : 'Progress')),
        visible: true,
    };
};

const showToast = (options = {}) => {
    const toast = makeToast(options);
    if (toastState.visible.length < maxVisibleToasts) {
        toastState.visible.unshift(toast);
        window.requestAnimationFrame(() => scheduleToast(toast));
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
    if (changes.actions !== undefined) toast.actions = normalizeActions(changes.actions);

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
    if (!action) return;
    try {
        if (action.onClick) {
            action.onClick({ id, dismiss: () => dismissToast(id) });
        } else if (action.url) {
            const target = new URL(action.url, window.location.href);
            if (['http:', 'https:'].includes(target.protocol)) window.location.assign(target.href);
        }
    } finally {
        if (action.dismiss) dismissToast(id);
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
        toastState.visible.slice().forEach((toast) => dismissToast(toast.id));
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
    desktopCollapsed: false,
    mobileOpen: false,
    viewportIsDesktop: false,
    sidebarReturnFocus: null,

    init() {
        this.syncViewport();
        window.addEventListener('resize', () => this.syncViewport(), { passive: true });
    },

    syncViewport() {
        this.viewportIsDesktop = window.innerWidth > 992;

        if (this.viewportIsDesktop) {
            this.mobileOpen = false;
            this.desktopCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        } else {
            this.desktopCollapsed = false;
        }
    },

    toggleSidebar() {
        if (this.viewportIsDesktop) {
            this.desktopCollapsed = !this.desktopCollapsed;
            localStorage.setItem('sidebarCollapsed', String(this.desktopCollapsed));
            return;
        }

        this.mobileOpen = !this.mobileOpen;

        if (this.mobileOpen) {
            this.sidebarReturnFocus = document.activeElement;
            this.$nextTick(() => document.querySelector('#sidebar a[href]')?.focus());
        } else {
            this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
        }
    },

    closeMobileSidebar() {
        if (!this.viewportIsDesktop && this.mobileOpen) {
            this.mobileOpen = false;
            this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
        }
    },

    trapSidebarFocus(event) {
        if (this.viewportIsDesktop || !this.mobileOpen) return;

        const items = [...document.querySelectorAll('#sidebar a[href], #sidebar button:not([disabled]), #sidebar [tabindex]:not([tabindex="-1"])')];
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
