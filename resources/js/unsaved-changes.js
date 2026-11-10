/**
 * ADASI Portal Supplier — Unsaved Changes & Custom Navigation Alert
 * Intercepts internal link clicks, F5/Ctrl+R/Cmd+R reloads, and browser Back button
 * using AdasiAlert (SweetAlert2) with ADASI theme instead of the browser default prompt.
 *
 * Employs Baseline Snapshotting & Dynamic Differential Checking:
 * Untouched forms or forms reverted back to original values never trigger false-positive alerts.
 * Alert copy follows the account language independently of the active portal.
 */
(function (window, document) {
    'use strict';

    let manualDirty = false;
    let isSubmitting = false;
    let hasPushedHistoryState = false;
    let userHasInteracted = false;
    let leaveConfirmed = false;
    let leaveConfirmedTimer = null;
    let reloadConfirmation = null;

    const formSnapshots = new WeakMap();
    const interactedForms = new WeakSet();
    const dirtySources = new Map();

    const EXCLUDED_ACTION_SUFFIXES = [
        '/login',
        '/logout',
        '/confirm-password',
        '/two-factor-challenge',
        '/supplier/registration/access',
        '/supplier/registration/access/credentials',
        '/supplier/registration/logout',
        '/supplier-context',
        '/notifications/mark-all-read',
    ];

    function getCopy() {
        const t = window.AdasiI18n.t;
        return {
            leaveTitle: t('js.navigation.leave_title'),
            leaveText: t('js.navigation.leave_text'),
            stayButton: t('js.navigation.stay'),
            leaveButton: t('js.navigation.leave'),
            reloadTitle: t('js.navigation.reload_title'),
            reloadText: t('js.navigation.reload_text'),
            reloadButton: t('js.navigation.reload'),
            cancelButton: t('js.actions.cancel'),
        };
    }

    function pushDirtyHistoryState() {
        if (!hasPushedHistoryState && window.history && window.history.pushState) {
            try {
                const workspace = document.querySelector('[data-server-tabs-unsaved="managed"]');
                const previous = window.history.state || {};
                const next = workspace
                    ? { ...previous, adasiUnsaved: true, _serverTabsIndex: previous._serverTabsIndex + 1 }
                    : { adasiUnsaved: true };
                window.history.pushState(next, document.title, window.location.href);
                if (workspace) {
                    window.dispatchEvent(new CustomEvent('adasi:server-tabs:history-guard', {
                        detail: { selector: next._serverTabs, index: next._serverTabsIndex },
                    }));
                }
                hasPushedHistoryState = true;
            } catch (e) {
                // Ignore history state errors if sandbox restricts
            }
        }
    }

    function shouldTrackForm(form) {
        if (!form || !(form instanceof HTMLFormElement)) return false;
        if (form.matches?.('[data-track-unsaved="false"], [data-logout-form], [data-notification-mark-form], [data-async-export]')) return false;
        // Component-owned state (presets/autosave) is authoritative for its form.
        if ([...dirtySources.values()].some((source) => source.form === form)) return false;

        // Ignore forms inside closed Bootstrap modals or hidden containers
        const modal = form.closest?.('.modal');
        if (modal && !modal.classList?.contains?.('show')) return false;
        if (form.closest?.('[hidden]')) return false;

        // Skip auth/session/credential action forms
        const rawAction = (form.getAttribute?.('action') || '').toLowerCase();
        const actionPath = rawAction.split('?')[0].replace(/\/+$/, '');
        if (
            form.id === 'logout-form'
            || EXCLUDED_ACTION_SUFFIXES.some((suffix) => actionPath === suffix || actionPath.endsWith(suffix))
        ) {
            return false;
        }

        // Explicitly tracked forms
        if (form.matches?.('[data-track-unsaved="true"], #prForm, #quotationForm, #poForm, #inspectionForm, #qcForm, #claimForm, #localInvoiceForm, .form-track-unsaved')) {
            return true;
        }

        // Track standard POST forms only if they contain at least one trackable user input
        if (!form.method || form.method.toLowerCase() !== 'post') {
            return false;
        }

        const elements = form.querySelectorAll?.('input, select, textarea') || [];
        for (let i = 0; i < elements.length; i++) {
            if (shouldTrackElement(elements[i])) {
                return true;
            }
        }
        return false;
    }

    function shouldTrackElement(el) {
        if (!el || !el.tagName) return false;
        if (el.disabled) return false;
        if (
            el.hasAttribute?.('data-ignore-dirty')
            || el.hasAttribute?.('data-unsaved-ignore')
            || el.closest?.('[data-ignore-dirty="true"], [data-unsaved-ignore="true"]')
        ) {
            return false;
        }

        const tag = el.tagName.toLowerCase();
        if (tag === 'button') return false;

        if (tag === 'input') {
            const type = (el.type || 'text').toLowerCase();
            if (type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return false;
            // Ignore hidden inputs and search filter inputs so internal tokens or filters never cause false dirty signals
            if (type === 'hidden' || type === 'search') return false;

            // Skip un-named search filter fields inside custom popovers
            if (!el.name && (el.classList?.contains?.('form-control') || el.hasAttribute?.('x-ref'))) {
                return false;
            }
            return true;
        }

        return tag === 'select' || tag === 'textarea';
    }

    function hasAutofilledElements(form) {
        const elements = form?.querySelectorAll?.('input, select, textarea') || [];
        for (let i = 0; i < elements.length; i++) {
            const el = elements[i];
            try {
                if (el?.matches?.(':-webkit-autofill, :autofill')) {
                    return true;
                }
            } catch (e) {
                // Ignore unsupported pseudo-class in older engines
            }
        }
        return false;
    }

    function getFormSnapshot(form) {
        if (!form) return '';
        const elements = form.querySelectorAll('input, select, textarea');
        const parts = [];

        // Track dynamic rows in items tables if present
        const tableRows = form.querySelectorAll('#itemsBody tr.item-row, .pr-items-table tbody tr.item-row, #quotationItemsBody tr');
        if (tableRows.length > 0) {
            parts.push(`__rows__:${tableRows.length}`);
        }

        for (let i = 0; i < elements.length; i++) {
            const el = elements[i];
            if (!shouldTrackElement(el)) continue;

            const name = el.name || el.id || `field_${i}`;
            const tag = el.tagName.toLowerCase();

            if (tag === 'input') {
                const type = (el.type || 'text').toLowerCase();
                if (type === 'checkbox' || type === 'radio') {
                    parts.push(`${name}:${el.checked ? '1' : '0'}`);
                } else if (type === 'file') {
                    const files = el.files ? Array.from(el.files).map(f => `${f.name}_${f.size}`).sort().join(';') : '';
                    parts.push(`${name}:[${files}]`);
                } else {
                    parts.push(`${name}:${el.value || ''}`);
                }
            } else if (tag === 'select') {
                if (el.multiple) {
                    const selected = Array.from(el.selectedOptions).map(o => o.value).sort().join(';');
                    parts.push(`${name}:[${selected}]`);
                } else {
                    parts.push(`${name}:${el.value || ''}`);
                }
            } else if (tag === 'textarea') {
                parts.push(`${name}:${el.value || ''}`);
            }
        }

        return parts.join('||');
    }

    function snapshotForm(form) {
        if (!shouldTrackForm(form)) return;
        const snapshot = getFormSnapshot(form);
        formSnapshots.set(form, snapshot);
    }

    function initSnapshots(force = false) {
        document.querySelectorAll('form').forEach(form => {
            if (!shouldTrackForm(form)) return;
            if (!force && userHasInteracted && isFormDirty(form)) {
                return;
            }
            snapshotForm(form);
        });
    }

    function isFormDirty(form) {
        if (!shouldTrackForm(form)) return false;
        if (!formSnapshots.has(form)) {
            snapshotForm(form);
            return false;
        }
        const initial = formSnapshots.get(form);
        const current = getFormSnapshot(form);
        if (initial === current) return false;

        if (!interactedForms.has(form) && hasAutofilledElements(form)) {
            formSnapshots.set(form, current);
            return false;
        }

        return true;
    }

    function isAnyFormDirty() {
        const forms = document.querySelectorAll('form');
        for (let i = 0; i < forms.length; i++) {
            const form = forms[i];
            if (shouldTrackForm(form) && isFormDirty(form)) {
                return true;
            }
        }
        return false;
    }

    function syncDirtyState() {
        if (isSubmitting || leaveConfirmed) {
            window.isFormDirty = false;
            return false;
        }

        const reallyDirty = isAnyFormDirty() || manualDirty
            || [...dirtySources.values()].some((source) => source.isDirty());
        window.isFormDirty = reallyDirty;

        if (reallyDirty) {
            pushDirtyHistoryState();
        } else {
            hasPushedHistoryState = false;
        }

        return reallyDirty;
    }

    // The visitor already said "leave" in one of our own dialogs. For a few seconds page-level beforeunload handlers
    // may still run (some of them persist drafts), but their request for the browser's native prompt is ignored, so
    // the same decision is never asked twice ("Reload page?" followed by the browser's "Reload site?").
    function confirmLeave() {
        leaveConfirmed = true;
        clearTimeout(leaveConfirmedTimer);
        leaveConfirmedTimer = setTimeout(() => { leaveConfirmed = false; }, 4000);
        AdasiUnsaved.markClean();
    }

    function ignoreNativePromptAfterConfirmedLeave(event) {
        if (!leaveConfirmed) {
            try {
                if (!Object.getOwnPropertyDescriptor(event, 'returnValue')) {
                    let val = '';
                    Object.defineProperty(event, 'returnValue', {
                        configurable: true,
                        get: () => val,
                        set: (v) => { val = v; },
                    });
                }
            } catch (e) {
                // Ignore non-configurable returnValue on synthetic Event instances
            }
            return;
        }
        event.preventDefault = () => {};
        try {
            Object.defineProperty(event, 'returnValue', { configurable: true, get: () => '', set: () => {} });
        } catch (e) {
            // Ignore non-configurable returnValue on synthetic Event instances
        }
    }

    const AdasiUnsaved = {
        registerDirtySource(isDirty, form = null) {
            if (typeof isDirty !== 'function') throw new TypeError('A dirty-state check is required.');
            const owner = Symbol('dirty-source');
            dirtySources.set(owner, { isDirty, form });
            return () => { dirtySources.delete(owner); };
        },
        markDirty() {
            manualDirty = true;
            syncDirtyState();
        },
        markClean() {
            manualDirty = false;
            userHasInteracted = false;
            hasPushedHistoryState = false;
            document.querySelectorAll('form').forEach((form) => interactedForms.delete(form));
            initSnapshots(true);
            syncDirtyState();
        },
        hasUserInteracted(form = null) {
            if (form) {
                return interactedForms.has(form);
            }
            return userHasInteracted;
        },
        isDirty() {
            return syncDirtyState();
        },
        setSubmitting(submitting = true) {
            isSubmitting = submitting;
            if (submitting) {
                manualDirty = false;
                window.isFormDirty = false;
                hasPushedHistoryState = false;
            }
        },
        resumeTracking() {
            // A fragment navigation has settled in the same document; subsequent edits need a new decision.
            leaveConfirmed = false;
            clearTimeout(leaveConfirmedTimer);
            isSubmitting = false;
        },
        resetSnapshot(form) {
            if (form) {
                interactedForms.delete(form);
                snapshotForm(form);
            } else {
                document.querySelectorAll('form').forEach((f) => interactedForms.delete(f));
                initSnapshots(true);
            }
            syncDirtyState();
        },
        showLeaveConfirmation(onConfirm, customTitle, customText) {
            const copy = getCopy();
            const title = customTitle || copy.leaveTitle;
            const text = customText || copy.leaveText;
            const confirmText = copy.leaveButton;
            const cancelText = copy.stayButton;

            if (window.AdasiAlert && typeof window.AdasiAlert.confirmDanger === 'function') {
                return window.AdasiAlert.confirmDanger({
                    title: title,
                    text: text,
                    confirmText: confirmText,
                    cancelText: cancelText
                }).then((result) => {
                    if (result && result.isConfirmed) {
                        confirmLeave();
                        if (typeof onConfirm === 'function') onConfirm();
                    }
                });
            } else if (window.Swal) {
                return window.Swal.fire({
                    icon: 'warning',
                    title: title,
                    text: text,
                    showCancelButton: true,
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    confirmButtonColor: '#C0392B',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true
                }).then((result) => {
                    if (result && result.isConfirmed) {
                        confirmLeave();
                        if (typeof onConfirm === 'function') onConfirm();
                    }
                });
            } else {
                if (window.confirm(`${title}\n\n${text}`)) {
                    confirmLeave();
                    if (typeof onConfirm === 'function') onConfirm();
                }
                return Promise.resolve();
            }
        },
        showReloadConfirmation() {
            if (reloadConfirmation) return reloadConfirmation;
            const ask = () => {
                const copy = getCopy();
                const title = copy.reloadTitle;
                const text = copy.reloadText;
                const confirmText = copy.reloadButton;
                const cancelText = copy.cancelButton;

                if (window.AdasiAlert && typeof window.AdasiAlert.confirmDanger === 'function') {
                    return window.AdasiAlert.confirmDanger({
                        title: title,
                        text: text,
                        confirmText: confirmText,
                        cancelText: cancelText
                    }).then((result) => {
                        if (result && result.isConfirmed) {
                            confirmLeave();
                            window.location.reload();
                        }
                    });
                } else if (window.Swal) {
                    return window.Swal.fire({
                        icon: 'warning',
                        title: title,
                        text: text,
                        showCancelButton: true,
                        confirmButtonText: confirmText,
                        cancelButtonText: cancelText,
                        confirmButtonColor: '#C0392B',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true
                    }).then((result) => {
                        if (result && result.isConfirmed) {
                            confirmLeave();
                            window.location.reload();
                        }
                    });
                } else {
                    if (window.confirm(`${title}\n\n${text}`)) {
                        confirmLeave();
                        window.location.reload();
                    }
                    return Promise.resolve();
                }
            };
            reloadConfirmation = Promise.resolve().then(ask).finally(() => { reloadConfirmation = null; });
            return reloadConfirmation;
        }
    };

    window.AdasiUnsaved = Object.freeze(AdasiUnsaved);
    window.isFormDirty = false;

    // Capture phase on window: runs before any page-level beforeunload handler, so it can neutralise their prompt request.
    window.addEventListener('beforeunload', ignoreNativePromptAfterConfirmedLeave, true);
    // A page restored from the back/forward cache must start with no pending confirmation or stale autofill diffs.
    window.addEventListener('pageshow', (event) => {
        leaveConfirmed = false;
        if (event?.persisted) {
            userHasInteracted = false;
            document.querySelectorAll('form').forEach((form) => interactedForms.delete(form));
            initSnapshots(true);
            syncDirtyState();
        }
    });

    function initUnsavedTracker() {
        // Initial snapshot of server-rendered forms
        initSnapshots();

        // Re-snapshot after Alpine components and DOM scripts settle
        setTimeout(() => initSnapshots(false), 250);
        window.addEventListener('load', () => setTimeout(() => initSnapshots(false), 100));

        function handleFieldMutation(event) {
            const target = event.target;
            if (!target || target.type === 'hidden' || target.type === 'search') return;
            const form = target.closest?.('form');
            if (!shouldTrackForm(form) || !shouldTrackElement(target)) return;
            if (event.isTrusted) {
                userHasInteracted = true;
                interactedForms.add(form);
            }
            syncDirtyState();
        }

        // Track inputs inside relevant forms
        document.addEventListener('input', handleFieldMutation, true);
        document.addEventListener('change', handleFieldMutation, true);

        // Reset form snapshots and interaction state when a Bootstrap modal is dismissed
        document.addEventListener('hidden.bs.modal', (event) => {
            const modal = event.target;
            const forms = modal?.querySelectorAll?.('form') || [];
            forms.forEach((form) => {
                interactedForms.delete(form);
                formSnapshots.delete(form);
            });
            syncDirtyState();
        });

        // Reset dirty flag on legitimate form submission
        document.addEventListener('submit', () => {
            AdasiUnsaved.setSubmitting(true);
        }, true);

        // Intercept link clicks across the page, navbar, sidebar, breadcrumbs, action bars
        document.addEventListener('click', (event) => {
            if (!AdasiUnsaved.isDirty()) return;

            const link = event.target.closest('a[href]');
            if (!link) return;

            // Skip links opening in new tab, downloads, or pure hash/js links
            const target = link.getAttribute('target');
            if (target && target === '_blank') return;
            if (link.hasAttribute('download')) return;

            const href = link.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('#')) return;
            if (link.closest('[data-server-tabs-unsaved="managed"]')) return;

            // Allow elements explicitly marked to bypass dirty check or modal triggers
            if (link.closest('[data-allow-dirty="true"], [data-quotation-form-export], [data-bs-toggle], [data-bs-dismiss]')) return;

            // Prevent direct browser navigation and show custom AdasiAlert
            event.preventDefault();
            event.stopImmediatePropagation();

            AdasiUnsaved.showLeaveConfirmation(() => {
                window.location.href = link.href;
            });
        }, true);

        // Intercept Browser Back / Forward buttons via popstate
        window.addEventListener('popstate', () => {
            if (document.querySelector('[data-server-tabs-unsaved="managed"]')) return;
            if (AdasiUnsaved.isDirty()) {
                // Re-push history entry so we stay on the page until user decides
                try {
                    window.history.pushState({ adasiUnsaved: true }, document.title, window.location.href);
                } catch (e) {}

                AdasiUnsaved.showLeaveConfirmation(() => {
                    confirmLeave();
                    window.history.back();
                });
            }
        });

        // Intercept Reload shortcuts: F5, Ctrl+R, Cmd+R
        window.addEventListener('keydown', (event) => {
            if (!AdasiUnsaved.isDirty()) return;

            const isReloadKey = event.key === 'F5' || 
                ((event.ctrlKey || event.metaKey) && (event.key === 'r' || event.key === 'R'));

            if (isReloadKey) {
                event.preventDefault();
                event.stopImmediatePropagation();
                AdasiUnsaved.showReloadConfirmation();
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUnsavedTracker);
    } else {
        initUnsavedTracker();
    }
})(window, document);
