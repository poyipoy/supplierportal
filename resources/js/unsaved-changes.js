/**
 * ADASI Portal Supplier — Unsaved Changes & Custom Navigation Alert
 * Intercepts internal link clicks, F5/Ctrl+R/Cmd+R reloads, and browser Back button
 * using AdasiAlert (SweetAlert2) with ADASI theme instead of the browser default prompt.
 */
(function (window, document) {
    'use strict';

    let isDirty = false;
    let isSubmitting = false;
    let hasPushedHistoryState = false;

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

    const AdasiUnsaved = {
        markDirty() {
            isDirty = true;
            window.isFormDirty = true;
            pushDirtyHistoryState();
        },
        markClean() {
            isDirty = false;
            window.isFormDirty = false;
            hasPushedHistoryState = false;
        },
        isDirty() {
            return isDirty && !isSubmitting;
        },
        setSubmitting(submitting = true) {
            isSubmitting = submitting;
            if (submitting) {
                isDirty = false;
                window.isFormDirty = false;
                hasPushedHistoryState = false;
            }
        },
        showLeaveConfirmation(onConfirm, customTitle, customText) {
            const title = customTitle || 'Leave this page?';
            const text = customText || 'Changes you made may not be saved if you leave this page. Do you want to proceed?';

            if (window.AdasiAlert && typeof window.AdasiAlert.confirmDanger === 'function') {
                return window.AdasiAlert.confirmDanger({
                    title: title,
                    text: text,
                    confirmText: 'Leave Page',
                    cancelText: 'Stay on Page'
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
                    confirmButtonText: 'Leave Page',
                    cancelButtonText: 'Stay on Page',
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
            const title = 'Reload page?';
            const text = 'Changes you made may not be saved. Are you sure you want to reload?';

            if (window.AdasiAlert && typeof window.AdasiAlert.confirmDanger === 'function') {
                return window.AdasiAlert.confirmDanger({
                    title: title,
                    text: text,
                    confirmText: 'Reload',
                    cancelText: 'Cancel'
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
                    confirmButtonText: 'Reload',
                    cancelButtonText: 'Cancel',
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

    function shouldTrackForm(form) {
        if (!form) return false;
        if (form.matches('[data-track-unsaved="false"]')) return false;
        if (form.matches('[data-track-unsaved="true"], #prForm, #quotationForm, #poForm, #inspectionForm, .form-track-unsaved')) return true;
        return form.method && form.method.toLowerCase() === 'post';
    }

    function initUnsavedTracker() {
        // Track inputs inside relevant forms
        document.addEventListener('input', (event) => {
            const target = event.target;
            if (!target || target.type === 'hidden' || target.type === 'search') return;
            const form = target.closest('form');
            if (shouldTrackForm(form)) {
                AdasiUnsaved.markDirty();
            }
        }, true);

        document.addEventListener('change', (event) => {
            const target = event.target;
            if (!target || target.type === 'hidden') return;
            const form = target.closest('form');
            if (shouldTrackForm(form)) {
                AdasiUnsaved.markDirty();
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
        window.addEventListener('popstate', (event) => {
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

        // Note on beforeunload:
        // By W3C/Chromium security specifications, beforeunload event handlers CANNOT be customized with HTML/CSS.
        // If event.returnValue is assigned, Chromium displays its own hardcoded C++ modal ("Leave site?").
        // To ensure only the custom ADASI designed alert is presented, we do not call event.returnValue here.
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUnsavedTracker);
    } else {
        initUnsavedTracker();
    }
})(window, document);
