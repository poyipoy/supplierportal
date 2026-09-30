/**
 * ADASI Portal Supplier — Unsaved Changes & Custom Navigation Alert
 * Intercepts internal link clicks, F5/Ctrl+R/Cmd+R reloads, and browser Back button
 * using AdasiAlert (SweetAlert2) with ADASI theme instead of the browser default prompt.
 *
 * Employs Baseline Snapshotting & Dynamic Differential Checking:
 * Untouched forms or forms reverted back to original values never trigger false-positive alerts.
 * Supports dual-language alerts: English for Material Procurement ('import') and Indonesian for Local Supplier ('local').
 */
(function (window, document) {
    'use strict';

    let manualDirty = false;
    let isSubmitting = false;
    let hasPushedHistoryState = false;
    let userHasInteracted = false;

    const formSnapshots = new WeakMap();

    const I18N = {
        local: {
            leaveTitle: 'Tinggalkan halaman ini?',
            leaveText: 'Perubahan yang Anda buat belum disimpan. Yakin ingin meninggalkan halaman ini?',
            stayButton: 'Tetap di Sini',
            leaveButton: 'Tinggalkan Halaman',
            reloadTitle: 'Muat ulang halaman?',
            reloadText: 'Perubahan yang Anda buat belum disimpan. Yakin ingin memuat ulang?',
            reloadButton: 'Muat Ulang',
            cancelButton: 'Batal',
        },
        import: {
            leaveTitle: 'Leave this page?',
            leaveText: 'Changes you made may not be saved if you leave this page. Do you want to proceed?',
            stayButton: 'Stay on Page',
            leaveButton: 'Leave Page',
            reloadTitle: 'Reload page?',
            reloadText: 'Changes you made may not be saved. Are you sure you want to reload?',
            reloadButton: 'Reload',
            cancelButton: 'Cancel',
        }
    };

    function getPortalScope() {
        const meta = document.querySelector('meta[name="portal-scope"]');
        if (meta && meta.content) {
            return meta.content.toLowerCase();
        }
        const path = window.location.pathname.toLowerCase();
        if (path.startsWith('/local-supplier') || path.startsWith('/finance') || path.startsWith('/accounting') || path.startsWith('/ga')) {
            return 'local';
        }
        return 'import';
    }

    function getCopy() {
        const scope = getPortalScope();
        return I18N[scope] || I18N.import;
    }

    function pushDirtyHistoryState() {
        if (!hasPushedHistoryState && window.history && window.history.pushState) {
            try {
                window.history.pushState({ adasiUnsaved: true }, document.title, window.location.href);
                hasPushedHistoryState = true;
            } catch (e) {
                // Ignore history state errors if sandbox restricts
            }
        }
    }

    function shouldTrackForm(form) {
        if (!form || !(form instanceof HTMLFormElement)) return false;
        if (form.matches('[data-track-unsaved="false"]')) return false;

        // Skip auth/session action forms with no real form inputs
        const action = (form.getAttribute('action') || '').toLowerCase();
        if (action.endsWith('/logout') || form.id === 'logout-form') return false;

        // Explicitly tracked forms
        if (form.matches('[data-track-unsaved="true"], #prForm, #quotationForm, #poForm, #inspectionForm, #localInvoiceForm, .form-track-unsaved')) {
            return true;
        }

        // Track standard POST forms
        return Boolean(form.method && form.method.toLowerCase() === 'post');
    }

    function shouldTrackElement(el) {
        if (!el || !el.tagName) return false;
        if (el.disabled) return false;
        if (el.hasAttribute('data-ignore-dirty') || el.closest('[data-ignore-dirty="true"]')) return false;

        const tag = el.tagName.toLowerCase();
        if (tag === 'button') return false;

        if (tag === 'input') {
            const type = (el.type || 'text').toLowerCase();
            if (type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return false;
            // Ignore hidden inputs so internal tokens or IDs never cause false dirty signals
            if (type === 'hidden') return false;

            // Skip un-named search filter fields inside custom popovers
            if (!el.name && (type === 'search' || el.classList.contains('form-control') || el.hasAttribute('x-ref'))) {
                return false;
            }
            return true;
        }

        return tag === 'select' || tag === 'textarea';
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
        return initial !== current;
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
        if (isSubmitting) {
            window.isFormDirty = false;
            return false;
        }

        const reallyDirty = isAnyFormDirty() || manualDirty;
        window.isFormDirty = reallyDirty;

        if (reallyDirty) {
            pushDirtyHistoryState();
        } else {
            hasPushedHistoryState = false;
        }

        return reallyDirty;
    }

    const AdasiUnsaved = {
        markDirty() {
            manualDirty = true;
            syncDirtyState();
        },
        markClean() {
            manualDirty = false;
            userHasInteracted = false;
            hasPushedHistoryState = false;
            initSnapshots(true);
            syncDirtyState();
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
        resetSnapshot(form) {
            if (form) {
                snapshotForm(form);
            } else {
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
                        AdasiUnsaved.markClean();
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
                        AdasiUnsaved.markClean();
                        if (typeof onConfirm === 'function') onConfirm();
                    }
                });
            } else {
                if (window.confirm(`${title}\n\n${text}`)) {
                    AdasiUnsaved.markClean();
                    if (typeof onConfirm === 'function') onConfirm();
                }
                return Promise.resolve();
            }
        },
        showReloadConfirmation() {
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
                        AdasiUnsaved.markClean();
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
                        AdasiUnsaved.markClean();
                        window.location.reload();
                    }
                });
            } else {
                if (window.confirm(`${title}\n\n${text}`)) {
                    AdasiUnsaved.markClean();
                    window.location.reload();
                }
                return Promise.resolve();
            }
        }
    };

    window.AdasiUnsaved = Object.freeze(AdasiUnsaved);
    window.isFormDirty = false;

    function initUnsavedTracker() {
        // Initial snapshot of server-rendered forms
        initSnapshots();

        // Re-snapshot after Alpine components and DOM scripts settle
        setTimeout(() => initSnapshots(false), 250);
        window.addEventListener('load', () => setTimeout(() => initSnapshots(false), 100));

        // Track inputs inside relevant forms
        document.addEventListener('input', (event) => {
            const target = event.target;
            if (!target || target.type === 'hidden' || target.type === 'search') return;
            if (event.isTrusted) {
                userHasInteracted = true;
            }
            const form = target.closest('form');
            if (shouldTrackForm(form)) {
                syncDirtyState();
            }
        }, true);

        document.addEventListener('change', (event) => {
            const target = event.target;
            if (!target || target.type === 'hidden') return;
            if (event.isTrusted) {
                userHasInteracted = true;
            }
            const form = target.closest('form');
            if (shouldTrackForm(form)) {
                syncDirtyState();
            }
        }, true);

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

            // Allow elements explicitly marked to bypass dirty check or modal triggers
            if (link.closest('[data-allow-dirty="true"], [data-bs-toggle], [data-bs-dismiss]')) return;

            // Prevent direct browser navigation and show custom AdasiAlert
            event.preventDefault();
            event.stopImmediatePropagation();

            AdasiUnsaved.showLeaveConfirmation(() => {
                window.location.href = link.href;
            });
        }, true);

        // Intercept Browser Back / Forward buttons via popstate
        window.addEventListener('popstate', () => {
            if (AdasiUnsaved.isDirty()) {
                // Re-push history entry so we stay on the page until user decides
                try {
                    window.history.pushState({ adasiUnsaved: true }, document.title, window.location.href);
                } catch (e) {}

                AdasiUnsaved.showLeaveConfirmation(() => {
                    AdasiUnsaved.markClean();
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
