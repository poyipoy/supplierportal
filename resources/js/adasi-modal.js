const instances = new WeakMap();
const bootedDocuments = new WeakSet();

const TOP_DIALOG_BLOCKER_SELECTOR = '.swal2-container, [data-session-expired-modal][open]';
const INNER_POPUP_SELECTOR = '.ui-calendar-panel:not([hidden]), .ui-time-panel:not([hidden])';

function resolveElement(target, doc = typeof document !== 'undefined' ? document : null) {
    if (!target) return null;
    if (typeof target === 'string') {
        if (!doc) return null;
        if (target.startsWith('#')) {
            return doc.getElementById(target.slice(1));
        }
        return doc.querySelector(target);
    }
    return typeof target === 'object' ? target : null;
}

function getWindow(el) {
    if (el?.ownerDocument?.defaultView) {
        return el.ownerDocument.defaultView;
    }
    return typeof window !== 'undefined' ? window : null;
}

function supportsNativeClosedBy(win) {
    return Boolean(win?.HTMLDialogElement?.prototype && 'closedBy' in win.HTMLDialogElement.prototype);
}

function createModalEvent(win, name, { cancelable = false, detail = {}, relatedTarget = null } = {}) {
    const CustomEvt = win?.CustomEvent || CustomEvent;
    const evt = new CustomEvt(name, {
        bubbles: true,
        cancelable,
        detail,
    });
    if (relatedTarget !== undefined) {
        try {
            Object.defineProperty(evt, 'relatedTarget', {
                value: relatedTarget,
                configurable: true,
                enumerable: true,
            });
        } catch {
            // Event subclass in some non-browser mocks may seal custom properties; detail.relatedTarget remains readable.
        }
    }
    return evt;
}

function isPointInsideContent(dialogEl, clientX, clientY) {
    if (typeof clientX !== 'number' || typeof clientY !== 'number') return false;
    const contentEl = dialogEl.querySelector?.('.modal-content') || dialogEl.firstElementChild || dialogEl;
    if (typeof contentEl?.getBoundingClientRect !== 'function') return false;
    const rect = contentEl.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) return false;
    return clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom;
}

function findTopOpenNativeDialog(doc) {
    const openDialogs = [...(doc?.querySelectorAll?.('dialog[open]') || [])];
    return openDialogs.length ? openDialogs[openDialogs.length - 1] : null;
}

function safeFocus(el) {
    if (!el || el.isConnected === false || typeof el.focus !== 'function') return;
    try {
        el.focus({ preventScroll: true });
    } catch {
        el.focus();
    }
}

/**
 * Native <dialog> modal controller replacing legacy Modal with Top-Layer support.
 */
export class AdasiModal {
    constructor(element, options = {}) {
        if (!element) {
            throw new TypeError('AdasiModal requires a valid dialog element.');
        }

        this.el = element;
        this.options = options;
        this._doc = element.ownerDocument || (typeof document !== 'undefined' ? document : null);
        this._trackedOpen = Boolean(element.open || element.hasAttribute?.('open'));
        this._returnFocusEl = null;
        this._activeRelatedTarget = null;
        this._suppressNextHideEvent = false;
        this._pointerDownOnBackdrop = false;

        if (options.backdrop === 'static' || options.keyboard === false) {
            element.setAttribute?.('closedby', 'none');
        }

        this._onCancel = this._handleCancel.bind(this);
        this._onClose = this._handleClose.bind(this);
        this._onPointerDown = this._handlePointerDown.bind(this);
        this._onClick = this._handleClick.bind(this);

        element.addEventListener?.('cancel', this._onCancel);
        element.addEventListener?.('close', this._onClose);
        element.addEventListener?.('pointerdown', this._onPointerDown);
        element.addEventListener?.('click', this._onClick);

        instances.set(element, this);
    }

    static getInstance(target, doc = typeof document !== 'undefined' ? document : null) {
        const el = resolveElement(target, doc);
        return el ? instances.get(el) || null : null;
    }

    static getOrCreateInstance(target, options = {}, doc = typeof document !== 'undefined' ? document : null) {
        const el = resolveElement(target, doc);
        if (!el) return null;
        return instances.get(el) || new AdasiModal(el, options);
    }

    isOpen() {
        return Boolean(this.el.open || this.el.hasAttribute?.('open'));
    }

    show(relatedTarget = null) {
        if (this.isOpen()) return this;

        const doc = this._doc;
        const win = getWindow(this.el);
        const normalizedTrigger = relatedTarget ?? null;

        const showEvt = createModalEvent(win, 'adasi:modal-show', {
            cancelable: true,
            detail: { relatedTarget: normalizedTrigger },
            relatedTarget: normalizedTrigger,
        });

        this.el.dispatchEvent?.(showEvt);
        if (showEvt.defaultPrevented) return this;

        const activeEl = doc?.activeElement;
        const fallbackFocus = activeEl && activeEl !== doc?.body && !this.el.contains?.(activeEl) ? activeEl : null;
        this._returnFocusEl = normalizedTrigger || fallbackFocus;
        this._activeRelatedTarget = normalizedTrigger;
        this._trackedOpen = true;
        this._suppressNextHideEvent = false;

        if (typeof this.el.showModal === 'function' && this.el.isConnected !== false) {
            if (!this.el.open) {
                this.el.showModal();
            }
        } else {
            this.el.setAttribute?.('open', '');
            try {
                this.el.open = true;
            } catch {
                // Readonly getter in environments with partial HTMLDialogElement shims.
            }
        }

        const shownEvt = createModalEvent(win, 'adasi:modal-shown', {
            cancelable: false,
            detail: { relatedTarget: normalizedTrigger },
            relatedTarget: normalizedTrigger,
        });
        this.el.dispatchEvent?.(shownEvt);

        return this;
    }

    hide(reason = 'programmatic') {
        if (!this.isOpen() && !this._trackedOpen) return this;

        const win = getWindow(this.el);
        const hideEvt = createModalEvent(win, 'adasi:modal-hide', {
            cancelable: true,
            detail: { reason },
        });
        this.el.dispatchEvent?.(hideEvt);
        if (hideEvt.defaultPrevented) return this;

        this._suppressNextHideEvent = true;

        if (typeof this.el.close === 'function' && this.el.open) {
            this.el.close(typeof reason === 'string' ? reason : undefined);
        } else {
            this.el.removeAttribute?.('open');
            try {
                this.el.open = false;
            } catch {
                // Readonly getter in environments with partial HTMLDialogElement shims.
            }
        }

        this._finalizeClose(reason);
        return this;
    }

    toggle(relatedTarget = null) {
        return this.isOpen() ? this.hide('toggle') : this.show(relatedTarget);
    }

    dispose() {
        this.el.removeEventListener?.('cancel', this._onCancel);
        this.el.removeEventListener?.('close', this._onClose);
        this.el.removeEventListener?.('pointerdown', this._onPointerDown);
        this.el.removeEventListener?.('click', this._onClick);
        instances.delete(this.el);
        this._returnFocusEl = null;
        this._activeRelatedTarget = null;
    }

    _isStaticDismissDisabled() {
        const closedBy = String(this.el.getAttribute?.('closedby') || '').toLowerCase();
        return closedBy === 'none' || this.el.hasAttribute?.('data-session-expired-modal');
    }

    _handleCancel(event) {
        const doc = this._doc;
        const win = getWindow(this.el);

        if (this._isStaticDismissDisabled()) {
            event.preventDefault?.();
            return;
        }

        const blocker = doc?.querySelector?.(TOP_DIALOG_BLOCKER_SELECTOR);
        if (blocker && blocker !== this.el) {
            event.preventDefault?.();
            return;
        }

        const topOpenDialog = findTopOpenNativeDialog(doc);
        if (topOpenDialog && topOpenDialog !== this.el) {
            event.preventDefault?.();
            return;
        }

        const innerPopup = doc?.querySelector?.(INNER_POPUP_SELECTOR);
        if (innerPopup && this.el.contains?.(innerPopup)) {
            event.preventDefault?.();
            return;
        }

        const hideEvt = createModalEvent(win, 'adasi:modal-hide', {
            cancelable: true,
            detail: { reason: 'escape' },
        });
        this.el.dispatchEvent?.(hideEvt);
        if (hideEvt.defaultPrevented) {
            event.preventDefault?.();
            return;
        }

        this._suppressNextHideEvent = true;
    }

    _handlePointerDown(event) {
        const win = getWindow(this.el);
        if (supportsNativeClosedBy(win)) {
            this._pointerDownOnBackdrop = false;
            return;
        }
        const closedBy = String(this.el.getAttribute?.('closedby') || '').toLowerCase();
        if (closedBy !== 'any' || !this.isOpen() || event.target !== this.el) {
            this._pointerDownOnBackdrop = false;
            return;
        }
        this._pointerDownOnBackdrop = !isPointInsideContent(this.el, event.clientX, event.clientY);
    }

    _handleClick(event) {
        const wasBackdropPointerDown = this._pointerDownOnBackdrop;
        this._pointerDownOnBackdrop = false;

        const win = getWindow(this.el);
        if (supportsNativeClosedBy(win) || !wasBackdropPointerDown) return;

        const closedBy = String(this.el.getAttribute?.('closedby') || '').toLowerCase();
        if (closedBy !== 'any' || !this.isOpen() || event.target !== this.el) return;
        if (!isPointInsideContent(this.el, event.clientX, event.clientY)) {
            this.hide('backdrop');
        }
    }

    _handleClose() {
        // Native <dialog>.close() queues an async 'close' task; ignore stale close events if already reopened.
        if (this.isOpen()) {
            this._suppressNextHideEvent = false;
            return;
        }

        const doc = this._doc;
        const win = getWindow(this.el);

        if (!this._suppressNextHideEvent && this._trackedOpen) {
            const hideEvt = createModalEvent(win, 'adasi:modal-hide', {
                cancelable: false,
                detail: { reason: 'native-close' },
            });
            this.el.dispatchEvent?.(hideEvt);
        }

        this._suppressNextHideEvent = false;
        this._finalizeClose(this.el.returnValue || 'native-close');
        if (!doc) return;
    }

    _finalizeClose(reason = 'programmatic') {
        if (!this._trackedOpen) return;
        this._trackedOpen = false;
        this._suppressNextHideEvent = false;

        const doc = this._doc;
        const win = getWindow(this.el);
        const returnFocusEl = this._returnFocusEl;
        this._returnFocusEl = null;
        this._activeRelatedTarget = null;

        const hiddenEvt = createModalEvent(win, 'adasi:modal-hidden', {
            cancelable: false,
            detail: { reason },
        });
        this.el.dispatchEvent?.(hiddenEvt);

        if (!doc) return;
        const blocker = doc.querySelector?.('.swal2-container');
        const remainingDialog = findTopOpenNativeDialog(doc);
        if (blocker) return;
        if (remainingDialog && !remainingDialog.contains?.(returnFocusEl)) return;

        const active = doc.activeElement;
        if (returnFocusEl && (!active || active === doc.body || this.el.contains?.(active))) {
            safeFocus(returnFocusEl);
        }
    }
}

export function bootAdasiModals(root = typeof document !== 'undefined' ? document : null) {
    const doc = root?.nodeType === 9 ? root : root?.ownerDocument || root;
    if (!doc || typeof doc.addEventListener !== 'function') return;
    if (bootedDocuments.has(doc)) return;
    bootedDocuments.add(doc);

    doc.addEventListener('click', (event) => {
        const closeTrigger = event.target?.closest?.('[data-ui-modal-close]');
        if (closeTrigger) {
            const targetSelector = closeTrigger.getAttribute('data-ui-modal-close');
            const dialogEl = targetSelector
                ? resolveElement(targetSelector, doc)
                : closeTrigger.closest('dialog.ui-native-dialog, dialog');
            if (dialogEl) {
                if (closeTrigger.tagName === 'A') event.preventDefault();
                AdasiModal.getOrCreateInstance(dialogEl)?.hide('dismiss');
            }
            return;
        }

        const openTrigger = event.target?.closest?.('[data-ui-modal-open]');
        if (!openTrigger) return;
        if (openTrigger.disabled || openTrigger.getAttribute('aria-disabled') === 'true') return;

        const targetSelector = openTrigger.getAttribute('data-ui-modal-open') || openTrigger.getAttribute('href');
        const dialogEl = resolveElement(targetSelector, doc);
        if (!dialogEl) return;

        if (openTrigger.tagName === 'A' || targetSelector?.startsWith?.('#')) {
            event.preventDefault();
        }

        const openPopover = openTrigger.closest?.('[popover]');
        if (openPopover && typeof openPopover.hidePopover === 'function' && openPopover.matches?.(':popover-open')) {
            openPopover.hidePopover();
        }

        AdasiModal.getOrCreateInstance(dialogEl)?.show(openTrigger);
    });

    // Ensure raw <dialog.ui-native-dialog> elements closed natively emit lifecycle cleanup even before explicit JS instantiation.
    doc.addEventListener('close', (event) => {
        const dialogEl = event.target;
        if (dialogEl?.tagName !== 'DIALOG') return;
        const inst = instances.get(dialogEl);
        if (inst) {
            inst._handleClose();
        }
    }, true);
}
