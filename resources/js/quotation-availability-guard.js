import { isSameOriginHttpUrl } from './origin-validator.js';

const CANCELLATION_EVENTS = new Set(['pr.supplier_removed', 'pr.cancelled']);
const DEFAULT_POLL_INTERVAL_MS = 30000;

export function createQuotationAvailabilityController({
    form,
    banner,
    messageEl = null,
    linkEl = null,
    prHash = '',
    availabilityUrl = '',
    detailUrl = '',
    draftKey = '',
    prCancelledMessage = '',
    invitationCancelledMessage = '',
    fallbackCancelledMessage = '',
    pollIntervalMs = DEFAULT_POLL_INTERVAL_MS,
    windowRef = typeof window !== 'undefined' ? window : null,
    documentRef = typeof document !== 'undefined' ? document : null,
    fetchImpl = typeof fetch === 'function' ? fetch : null,
    storage = typeof sessionStorage !== 'undefined' ? sessionStorage : null,
    markClean = () => windowRef?.AdasiUnsaved?.markClean?.(),
    notify = (type, message) => windowRef?.AdasiToast?.[type]?.(message),
} = {}) {
    let locked = false;
    let pollTimer = null;
    let checkInFlight = false;
    let destroyed = false;

    const clearStoredDraft = () => {
        if (!draftKey) return;
        const stores = new Set([
            storage,
            windowRef?.sessionStorage,
            windowRef?.localStorage,
            typeof sessionStorage !== 'undefined' ? sessionStorage : null,
            typeof localStorage !== 'undefined' ? localStorage : null,
        ]);
        for (const candidate of stores) {
            if (!candidate || typeof candidate.removeItem !== 'function') continue;
            try {
                candidate.removeItem(draftKey);
            } catch {
                // Storage may be blocked in restricted browser contexts.
            }
        }
    };

    const resolveSafeRedirectUrl = (candidate) => {
        const origin = windowRef?.location?.origin || '';
        if (typeof candidate === 'string' && candidate !== '' && isSameOriginHttpUrl(candidate, origin)) {
            return candidate;
        }
        if (typeof detailUrl === 'string' && detailUrl !== '' && isSameOriginHttpUrl(detailUrl, origin)) {
            return detailUrl;
        }
        return '';
    };

    const disableFormControls = () => {
        if (!form) return;
        form.dataset.quotationCancelled = 'true';

        form.querySelectorAll('button, input, select, textarea').forEach((control) => {
            if (banner && banner.contains(control)) return;
            if (control.type === 'hidden') return;
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });

        form.querySelectorAll('a[data-quotation-form-export], [data-ui-modal-open="#quotationImportModal"]').forEach((anchor) => {
            if (banner && banner.contains(anchor)) return;
            anchor.setAttribute('aria-disabled', 'true');
            anchor.classList?.add('disabled');
            anchor.tabIndex = -1;
        });

        const importModal = documentRef?.getElementById?.('quotationImportModal');
        if (importModal) {
            importModal.querySelectorAll('button, input, select').forEach((control) => {
                if (control.hasAttribute('data-ui-modal-close')) return;
                control.disabled = true;
            });
            windowRef?.AdasiModal?.getInstance?.(importModal)?.hide?.() ?? importModal.close?.();
        }
    };

    const stopPolling = () => {
        if (pollTimer !== null && windowRef?.clearInterval) {
            windowRef.clearInterval(pollTimer);
        }
        pollTimer = null;
    };

    const lock = ({ message = '', redirectUrl = '', code = '' } = {}) => {
        if (locked) return;
        locked = true;
        stopPolling();
        clearStoredDraft();
        try {
            markClean?.();
        } catch {
            // Unsaved guard cleanup must not interrupt locking.
        }

        const resolvedMessage = message
            || (code === 'pr_cancelled' ? prCancelledMessage : '')
            || (code === 'invitation_cancelled' ? invitationCancelledMessage : '')
            || fallbackCancelledMessage;

        const safeRedirect = resolveSafeRedirectUrl(redirectUrl);

        if (messageEl && resolvedMessage) {
            messageEl.textContent = resolvedMessage;
        }
        if (linkEl && safeRedirect) {
            linkEl.setAttribute('href', safeRedirect);
        }

        disableFormControls();

        if (banner) {
            banner.hidden = false;
            banner.classList?.remove('tw:hidden', 'd-none');
            const prefersReduced = Boolean(windowRef?.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches);
            banner.scrollIntoView?.({ behavior: prefersReduced ? 'auto' : 'smooth', block: 'nearest' });
            banner.focus?.({ preventScroll: true });
        }

        if (resolvedMessage) {
            try {
                notify?.('warning', resolvedMessage);
            } catch {
                // Toast failure must not block form lock.
            }
        }

        form?.dispatchEvent?.(new CustomEvent('adasi:quotation-cancelled', {
            bubbles: true,
            detail: { code, message: resolvedMessage, redirectUrl: safeRedirect },
        }));
    };

    const checkAvailability = async () => {
        if (locked || destroyed || checkInFlight || !availabilityUrl || typeof fetchImpl !== 'function') {
            return;
        }
        checkInFlight = true;
        try {
            const response = await fetchImpl(availabilityUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.status === 401 || response.status === 403 || response.status === 419) {
                stopPolling();
                return;
            }

            if (!response.ok) {
                return;
            }

            const payload = await response.json().catch(() => null);
            if (!payload || payload.available === true) {
                return;
            }

            lock({
                code: String(payload.code || ''),
                message: String(payload.message || ''),
                redirectUrl: String(payload.redirect_url || ''),
            });
        } catch {
            // Network errors are transient; keep waiting for next check or submit.
        } finally {
            checkInFlight = false;
        }
    };

    const onRealtimeNotification = (event) => {
        if (locked || destroyed) return;
        const detail = event?.detail;
        if (!detail || !CANCELLATION_EVENTS.has(String(detail.event || ''))) return;
        if (prHash && String(detail.pr_hash || '') !== prHash) return;

        const code = detail.event === 'pr.cancelled' ? 'pr_cancelled' : 'invitation_cancelled';
        lock({
            code,
            message: '',
            redirectUrl: detailUrl,
        });
    };

    const onFormConflict = (event) => {
        if (destroyed) return;
        event?.preventDefault?.();
        const data = event?.detail?.data || {};
        lock({
            code: String(data.code || ''),
            message: String(data.message || ''),
            redirectUrl: String(data.redirect || detailUrl),
        });
    };

    const onFormSuccess = () => {
        clearStoredDraft();
        stopPolling();
    };

    const onVisibilityChange = () => {
        if (documentRef?.visibilityState === 'visible') {
            checkAvailability();
        }
    };

    const onWindowFocus = () => {
        checkAvailability();
    };

    const onFormSubmit = (event) => {
        if (locked) {
            event.preventDefault();
            event.stopImmediatePropagation?.();
        }
    };

    const start = () => {
        if (destroyed || locked) return;
        windowRef?.addEventListener?.('adasi:realtime-notification', onRealtimeNotification);
        windowRef?.addEventListener?.('focus', onWindowFocus);
        documentRef?.addEventListener?.('visibilitychange', onVisibilityChange);
        form?.addEventListener?.('adasi:form-conflict', onFormConflict);
        form?.addEventListener?.('adasi:form-success', onFormSuccess);
        form?.addEventListener?.('submit', onFormSubmit, true);

        if (availabilityUrl && pollIntervalMs > 0 && typeof windowRef?.setInterval === 'function') {
            pollTimer = windowRef.setInterval(() => {
                if (documentRef?.visibilityState === 'hidden') return;
                checkAvailability();
            }, pollIntervalMs);
        }
    };

    const destroy = () => {
        destroyed = true;
        stopPolling();
        windowRef?.removeEventListener?.('adasi:realtime-notification', onRealtimeNotification);
        windowRef?.removeEventListener?.('focus', onWindowFocus);
        documentRef?.removeEventListener?.('visibilitychange', onVisibilityChange);
        form?.removeEventListener?.('adasi:form-conflict', onFormConflict);
        form?.removeEventListener?.('adasi:form-success', onFormSuccess);
        form?.removeEventListener?.('submit', onFormSubmit, true);
    };

    return {
        start,
        destroy,
        lock,
        checkAvailability,
        isLocked: () => locked,
    };
}

export function initQuotationAvailabilityGuards(root = document) {
    const controllers = [];
    root.querySelectorAll('[data-quotation-availability-guard]').forEach((container) => {
        if (container.dataset.availabilityGuardBound === 'true') return;
        container.dataset.availabilityGuardBound = 'true';

        const form = container.matches('form') ? container : container.querySelector('form');
        if (!form) return;

        const banner = container.querySelector('[data-quotation-cancelled-banner]')
            || root.querySelector('[data-quotation-cancelled-banner]');

        const controller = createQuotationAvailabilityController({
            form,
            banner,
            messageEl: banner?.querySelector('[data-quotation-cancelled-message]') || null,
            linkEl: banner?.querySelector('[data-quotation-cancelled-link]') || null,
            prHash: container.dataset.prHash || '',
            availabilityUrl: container.dataset.availabilityUrl || '',
            detailUrl: container.dataset.detailUrl || '',
            draftKey: container.dataset.draftKey || '',
            prCancelledMessage: container.dataset.prCancelledMessage || '',
            invitationCancelledMessage: container.dataset.invitationCancelledMessage || '',
            fallbackCancelledMessage: container.dataset.fallbackCancelledMessage || '',
        });

        controller.start();
        controllers.push(controller);
    });

    return controllers;
}
