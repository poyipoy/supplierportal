import { t } from './i18n.js';
import { isSameOriginHttpUrl } from './origin-validator.js';

export function registerToastCenter(Alpine, { navigateUrl = null, flushQueue = true } = {}) {
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
    let activeOverlayCount = 0;
    let toastRegionEl = null;
    let toastAnnouncerPoliteEl = null;
    let toastAnnouncerAssertiveEl = null;
    let lastFocusBeforeToast = null;
    const deferredOverlayToasts = [];
    const toastAnnounceTimers = new Map();

    const supportsAriaNotify = typeof document !== 'undefined' && typeof document.ariaNotify === 'function';
    const supportsManualPopover = typeof HTMLElement !== 'undefined'
        && 'popover' in HTMLElement.prototype
        && typeof HTMLElement.prototype.showPopover === 'function';

    let toastPromotionBound = false;

    const promoteToastPopover = () => {
        if (!supportsManualPopover || !toastRegionEl || !toastRegionEl.isConnected) return;
        if (toastState.visible.length === 0) return;
        try {
            if (toastRegionEl.matches(':popover-open')) {
                toastRegionEl.hidePopover();
            }
            toastRegionEl.showPopover();
        } catch {}
    };

    const ensureToastPromotionListener = () => {
        if (toastPromotionBound || typeof document === 'undefined' || typeof document.addEventListener !== 'function') return;
        toastPromotionBound = true;
        document.addEventListener('adasi:modal-shown', promoteToastPopover);
    };

    const syncToastPopover = () => {
        ensureToastPromotionListener();
        if (!supportsManualPopover || !toastRegionEl || !toastRegionEl.isConnected) return;
        if (!toastRegionEl.hasAttribute('popover')) {
            toastRegionEl.setAttribute('popover', 'manual');
        }
        try {
            if (toastState.visible.length > 0) {
                if (!toastRegionEl.matches(':popover-open')) {
                    toastRegionEl.showPopover();
                }
            } else if (toastRegionEl.matches(':popover-open')) {
                toastRegionEl.hidePopover();
            }
        } catch {}
    };

    const writeToastLiveRegion = (regionEl, key, text) => {
        if (!regionEl) return;
        const prevTimer = toastAnnounceTimers.get(key);
        if (prevTimer) window.clearTimeout(prevTimer);
        regionEl.textContent = '';
        const handle = window.setTimeout(() => {
            toastAnnounceTimers.delete(key);
            regionEl.textContent = text;
        }, 40);
        toastAnnounceTimers.set(key, handle);
    };

    const announceToast = (toast) => {
        if (!toast || toast.restored) return;
        const parts = [toast.title, toast.message].filter((part) => typeof part === 'string' && part.trim() !== '');
        if (toast.actions.length > 0) {
            parts.push(t('js.toast.actions_available'));
        }
        const text = parts.join('. ');
        if (!text) return;
        if (supportsAriaNotify) {
            try {
                document.ariaNotify(text, { priority: toast.type === 'error' ? 'high' : 'normal' });
                return;
            } catch {}
        }
        const isError = toast.type === 'error';
        const region = isError
            ? (toastAnnouncerAssertiveEl || document.getElementById('adasi-toast-announcer-assertive'))
            : (toastAnnouncerPoliteEl || document.getElementById('adasi-toast-announcer'));
        writeToastLiveRegion(region, isError ? 'assertive' : 'polite', text);
    };

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
        || toastState.queued.find((toast) => toast.id === id)
        || deferredOverlayToasts.find((toast) => toast.id === id);

    const clearToastTimer = (id) => {
        const timer = toastTimers.get(id);
        if (timer?.handle) window.clearTimeout(timer.handle);
        toastTimers.delete(id);
    };

    const emitToastDismissed = (id, reason) => {
        window.dispatchEvent(new CustomEvent('adasi:toast-dismissed', {
            detail: { id, reason },
        }));
    };

    const captureReturnFocus = (event) => {
        const prior = event?.relatedTarget;
        if (prior instanceof Element && toastRegionEl && !toastRegionEl.contains(prior)) {
            lastFocusBeforeToast = prior;
        }
    };

    const moveFocusAfterToastDismiss = (dismissedId) => {
        const active = document.activeElement;
        const currentArticle = toastRegionEl?.querySelector(`[data-toast-id="${CSS.escape(dismissedId)}"]`);
        if (!active || !currentArticle || !currentArticle.contains(active)) return;

        const remainingArticles = Array.from(toastRegionEl.querySelectorAll('[data-toast-id]'))
            .filter((el) => el !== currentArticle && el.offsetParent !== null);
        if (remainingArticles.length > 0) {
            const nextClose = remainingArticles[0].querySelector('.adasi-toast__close');
            nextClose?.focus?.({ preventScroll: true });
            return;
        }
        const prior = lastFocusBeforeToast;
        lastFocusBeforeToast = null;
        if (
            prior
            && prior.isConnected !== false
            && !prior.closest?.('[inert]')
            && typeof prior.focus === 'function'
        ) {
            prior.focus({ preventScroll: true });
            return;
        }
        const main = document.getElementById('main-content');
        main?.focus?.({ preventScroll: true });
    };

    function promoteQueuedToast() {
        if (activeOverlayCount > 0 || toastState.visible.length >= maxVisibleToasts || !toastState.queued.length) return;
        const next = toastState.queued.shift();
        toastState.visible.unshift(next);
        syncToastPopover();
        announceToast(next);
        window.requestAnimationFrame(() => {
            next.restored = false;
            scheduleToast(next);
        });
    }

    function removeToast(id) {
        clearToastTimer(id);
        const visibleIndex = toastState.visible.findIndex((toast) => toast.id === id);
        if (visibleIndex >= 0) {
            toastState.visible.splice(visibleIndex, 1);
            promoteQueuedToast();
            syncToastPopover();
            return;
        }

        const queuedIndex = toastState.queued.findIndex((toast) => toast.id === id);
        if (queuedIndex >= 0) {
            toastState.queued.splice(queuedIndex, 1);
            return;
        }

        const deferredIndex = deferredOverlayToasts.findIndex((toast) => toast.id === id);
        if (deferredIndex >= 0) deferredOverlayToasts.splice(deferredIndex, 1);
    }

    function dismissToast(id, reason = 'manual') {
        const toast = findToast(id);
        if (!toast || toast.visible === false) return false;
        clearToastTimer(id);

        if (
            toastState.queued.some((item) => item.id === id)
            || deferredOverlayToasts.some((item) => item.id === id)
        ) {
            emitToastDismissed(id, reason);
            removeToast(id);
            return true;
        }

        if (reason === 'manual') {
            moveFocusAfterToastDismiss(id);
        }
        toast.visible = false;
        emitToastDismissed(id, reason);
        window.setTimeout(() => removeToast(id), 180);
        return true;
    }

    function scheduleToast(toast, duration = toast.autoClose) {
        clearToastTimer(toast.id);
        if (!duration || duration <= 0 || !toast.visible) return;

        if (activeOverlayCount > 0) {
            toastTimers.set(toast.id, { handle: null, startedAt: Date.now(), remaining: duration });
            return;
        }

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

    const flushDeferredOverlayToasts = () => {
        if (activeOverlayCount > 0 || deferredOverlayToasts.length === 0) return;
        const pending = deferredOverlayToasts.splice(0);
        for (const toast of pending) {
            if (toastState.visible.length < maxVisibleToasts) {
                toastState.visible.unshift(toast);
                syncToastPopover();
                announceToast(toast);
                window.requestAnimationFrame(() => {
                    toast.restored = false;
                    scheduleToast(toast);
                });
            } else {
                toastState.queued.push(toast);
            }
        }
    };

    window.addEventListener('adasi:overlay-modality-change', (event) => {
        const nextCount = Number(event.detail?.activeCount || 0);
        const prevCount = activeOverlayCount;
        activeOverlayCount = nextCount;
        if (nextCount > 0 && prevCount === 0) {
            toastState.visible.forEach((toast) => pauseToast(toast.id));
        } else if (nextCount === 0 && prevCount > 0) {
            toastState.visible.forEach((toast) => resumeToast(toast.id));
            flushDeferredOverlayToasts();
        }
    });

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
        if (activeOverlayCount > 0 && !toast.restored) {
            deferredOverlayToasts.push(toast);
            return toast.id;
        }
        if (toastState.visible.length < maxVisibleToasts) {
            toastState.visible.unshift(toast);
            syncToastPopover();
            announceToast(toast);
            window.requestAnimationFrame(() => {
                toast.restored = false;
                scheduleToast(toast);
            });
        } else {
            toastState.queued.push(toast);
        }
        return toast.id;
    };

    const runToastAction = (id, action) => {
        if (!action || action.disabled) return;
        try {
            if (action.onClick) {
                action.onClick({ id, dismiss: () => dismissToast(id, 'action') });
            } else if (action.url) {
                if (typeof navigateUrl === 'function') {
                    void navigateUrl(action.url);
                } else if (isSameOriginHttpUrl(action.url, window.location?.href || window.location?.origin)) {
                    window.location.assign(action.url);
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
            deferredOverlayToasts.splice(0);
            toastState.queued.splice(0);
            toastState.visible.slice().forEach((toast) => dismissToast(toast.id, 'clear'));
        },
    });

    const flushToastQueue = () => {
        if (Array.isArray(window.__adasiToastQueue) && window.__adasiToastQueue.length > 0) {
            window.__adasiToastQueue.splice(0).forEach((options) => window.AdasiToast.show(options));
        }
    };

    if (flushQueue) {
        flushToastQueue();
    }

    Alpine.data('adasiToastCenter', () => ({
        state: toastState,
        supportsAriaNotify,
        hasDedicatedAnnouncer: supportsAriaNotify,
        init() {
            toastRegionEl = this.$refs.region || this.$el;
            toastAnnouncerPoliteEl = this.$refs.announcerPolite || document.getElementById('adasi-toast-announcer');
            toastAnnouncerAssertiveEl = this.$refs.announcerAssertive || document.getElementById('adasi-toast-announcer-assertive');
            this.hasDedicatedAnnouncer = supportsAriaNotify || Boolean(toastAnnouncerPoliteEl && toastAnnouncerAssertiveEl);
            syncToastPopover();
        },
        captureReturnFocus,
        dismiss: dismissToast,
        pause: pauseToast,
        resume: resumeToast,
        runAction: runToastAction,
    }));

    return { flushQueue: flushToastQueue };
}

