const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

const NON_RECONNECTABLE_REASONS = new Set([
    'absolute_timeout',
    'session_revoked',
    'account_deactivated',
]);

function isVisibleControl(el) {
    if (!el || el.hidden) return false;
    if (el.closest?.('[hidden]')) return false;
    if (typeof window !== 'undefined' && typeof window.getComputedStyle === 'function') {
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') return false;
    }
    return true;
}

export function isReconnectableReason(reason) {
    return !NON_RECONNECTABLE_REASONS.has(reason);
}

export function adasiSessionModal(initialCopy = {}) {
    let resolver = null;
    let onReconnectHandler = null;
    let onLoginRedirectHandler = null;
    let onStayHandler = null;

    return {
        open: false,
        showReminderBar: false,
        busy: false,
        reason: 'session_ended',
        reconnectable: true,
        isDirty: false,
        dirtyTabs: [],
        errorMessage: '',
        returnFocus: null,
        copy: initialCopy && typeof initialCopy === 'object' ? initialCopy : {},

        get reasonText() {
            const c = this.copy || {};
            if (this.reason === 'idle_timeout') return c.reasonIdle || '';
            if (this.reason === 'absolute_timeout') return c.reasonAbsolute || '';
            if (this.reason === 'session_revoked' || this.reason === 'account_deactivated') {
                return c.reasonRevoked || c.reasonDefault || '';
            }
            return c.reasonDefault || '';
        },

        get isTimeoutReason() {
            return this.reason === 'idle_timeout' || this.reason === 'absolute_timeout';
        },

        get descriptionText() {
            const c = this.copy || {};
            if (this.isDirty) {
                return c.descriptionDirty || '';
            }
            if (!this.reconnectable) {
                return c.descriptionNonReconnectable || c.descriptionClean || '';
            }
            return c.descriptionClean || '';
        },

        get primaryActionLabel() {
            const c = this.copy || {};
            if (!this.reconnectable) {
                return c.actionSignIn || '';
            }
            if (this.busy) {
                return c.actionReconnecting || c.actionChecking || '';
            }
            return c.actionReconnect || c.actionSignIn || '';
        },

        init() {
            this.$watch('open', (value) => {
                document.body.classList.toggle('ui-dialog-open', Boolean(value));
            });

            window.AdasiSessionModal = Object.freeze({
                open: (options = {}) => this.openModal(options),
                close: () => this.closeAll(),
                setReason: (reason, reconnectable = undefined) => {
                    if (typeof reason === 'string' && reason !== '') {
                        this.reason = reason;
                    }
                    if (typeof reconnectable === 'boolean') {
                        this.reconnectable = reconnectable;
                    } else {
                        this.reconnectable = isReconnectableReason(this.reason);
                    }
                },
                showReminderBar: (options = {}) => {
                    if (typeof options.reason === 'string' && options.reason !== '') {
                        this.reason = options.reason;
                    }
                    this.open = false;
                    this.busy = false;
                    this.errorMessage = '';
                    this.showReminderBar = true;
                },
                hideReminderBar: () => {
                    this.showReminderBar = false;
                },
                isOpen: () => this.open === true,
                isReminderVisible: () => this.showReminderBar === true,
            });
        },

        focusables() {
            const panel = this.$refs.panel;
            if (!panel) return [];
            return [...panel.querySelectorAll(FOCUSABLE_SELECTOR)].filter(isVisibleControl);
        },

        openModal(options = {}) {
            if (resolver) {
                const pending = resolver;
                resolver = null;
                pending({ isConfirmed: false, isDismissed: true });
            }

            this.returnFocus = typeof document.activeElement?.focus === 'function' ? document.activeElement : null;
            this.reason = typeof options.reason === 'string' && options.reason !== '' ? options.reason : 'session_ended';
            this.reconnectable = typeof options.reconnectable === 'boolean'
                ? options.reconnectable
                : isReconnectableReason(this.reason);
            this.isDirty = options.isDirty === true;
            this.dirtyTabs = Array.isArray(options.dirtyTabs) ? options.dirtyTabs : [];
            this.errorMessage = '';
            this.busy = false;
            this.showReminderBar = false;
            this.open = true;

            onReconnectHandler = typeof options.onReconnect === 'function' ? options.onReconnect : null;
            onLoginRedirectHandler = typeof options.onLoginRedirect === 'function' ? options.onLoginRedirect : null;
            onStayHandler = typeof options.onStay === 'function' ? options.onStay : null;

            this.$nextTick(() => {
                const target = this.$refs.reconnectButton || this.focusables()[0] || this.$refs.panel;
                target?.focus?.();
                if (options.autoConfirm === true) {
                    void this.triggerPrimaryAction();
                }
            });

            return new Promise((resolve) => {
                resolver = resolve;
            });
        },

        closeAll() {
            const wasOpen = this.open;
            this.open = false;
            this.showReminderBar = false;
            this.busy = false;
            this.errorMessage = '';
            onReconnectHandler = null;
            onLoginRedirectHandler = null;
            onStayHandler = null;
            if (resolver) {
                const pending = resolver;
                resolver = null;
                pending({ isConfirmed: false, isDismissed: true });
            }
            if (wasOpen) {
                this.$nextTick(() => this.returnFocus?.focus?.());
            }
        },

        triggerPrimaryAction() {
            if (!this.reconnectable) {
                return this.triggerLoginRedirect();
            }
            return this.triggerReconnect();
        },

        async triggerReconnect() {
            if (!this.open || this.busy) return;
            this.busy = true;
            this.errorMessage = '';

            if (!onReconnectHandler) {
                const done = resolver;
                resolver = null;
                done?.({ isConfirmed: true, isDismissed: false });
                return;
            }

            try {
                const outcome = await onReconnectHandler();
                if (outcome && typeof outcome.token === 'string') {
                    this.open = false;
                    this.showReminderBar = false;
                    this.busy = false;
                    this.errorMessage = '';
                    this.$nextTick(() => this.returnFocus?.focus?.());
                }
                const done = resolver;
                resolver = null;
                done?.({ isConfirmed: true, isDismissed: false, value: outcome });
            } catch (error) {
                this.busy = false;
                this.errorMessage = error?.message || this.copy.failed || '';
                this.$nextTick(() => {
                    (this.$refs.reconnectButton || this.$refs.panel)?.focus?.();
                });
            }
        },

        triggerLoginRedirect() {
            if (!this.open || this.busy) return;
            this.busy = true;
            this.errorMessage = '';
            const targetUrl = typeof onLoginRedirectHandler === 'function' ? onLoginRedirectHandler() : null;
            const done = resolver;
            resolver = null;
            done?.({
                isConfirmed: true,
                isDismissed: false,
                value: targetUrl ? { url: targetUrl } : { forceLogin: true },
            });
        },

        dismissToReminderBar() {
            if (!this.open || !this.isDirty || this.busy) return;
            this.open = false;
            this.errorMessage = '';
            this.showReminderBar = true;
            try { onStayHandler?.(); } catch { /* Best-effort callback. */ }

            const done = resolver;
            resolver = null;
            done?.({ isConfirmed: false, isDismissed: true, dismiss: 'cancel' });

            this.$nextTick(() => {
                if (this.returnFocus && document.contains(this.returnFocus)) {
                    this.returnFocus.focus();
                } else {
                    this.$refs.reminderAction?.focus?.();
                }
            });
        },

        reopenFromReminderBar() {
            if (this.open) return;
            window.AdasiSessionSync?.requestReconnect?.(false, {
                reason: this.reason,
                reconnectable: this.reconnectable,
                force: true,
                autoConfirm: true,
            });
        },

        handleEscape(event) {
            if (!this.open) return;
            event.preventDefault();
            event.stopPropagation();
            if (this.isDirty && !this.busy) {
                this.dismissToReminderBar();
            }
        },

        trapFocus(event) {
            if (!this.open) return;
            const items = this.focusables();
            if (!items.length) {
                event.preventDefault();
                this.$refs.panel?.focus?.();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            if (!this.$refs.panel?.contains(active)) {
                event.preventDefault();
                first.focus();
                return;
            }
            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
