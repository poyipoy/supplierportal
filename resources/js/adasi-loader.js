import { t } from './i18n.js';

export const SHOW_DELAY = 2500;
export const MIN_VISIBLE = 300;
export const SLOW_AFTER = 20000;
export const NAV_RELEASE = 60000;

const INERT_SELECTOR = '#sidebar, .top-navbar, #main-content';

/** DataTables server-side requests always carry a `draw` counter. */
export function isDataTableRequest(options = {}) {
    const data = options.data;

    if (data && typeof data === 'object') {
        return Object.prototype.hasOwnProperty.call(data, 'draw');
    }

    return typeof data === 'string' && /(?:^|&)draw(?:=|%5B|\[)/i.test(data);
}

/**
 * Token-based loader state machine, independent of the DOM so it can be unit tested.
 * `view` supplies activate() / deactivate() / showSlow(); `timers` supplies setTimeout/clearTimeout/now.
 */
export function createLoaderController({ view, timers, showDelay = SHOW_DELAY }) {
    const parsedShowDelay = Number(showDelay);
    const delayMs = Number.isFinite(parsedShowDelay) && parsedShowDelay >= 0 ? parsedShowDelay : SHOW_DELAY;
    const pending = new Set();
    let seq = 0;
    let active = false;
    let shownAt = 0;
    let showTimer = null;
    let hideTimer = null;
    let slowTimer = null;

    const clear = (id) => {
        if (id !== null) timers.clearTimeout(id);
        return null;
    };

    const activate = () => {
        showTimer = null;
        if (active || pending.size === 0) return;
        active = true;
        shownAt = timers.now();
        view.activate();
        slowTimer = timers.setTimeout(() => {
            slowTimer = null;
            if (active) view.showSlow();
        }, SLOW_AFTER);
    };

    const deactivate = () => {
        hideTimer = null;
        if (!active) return;
        active = false;
        slowTimer = clear(slowTimer);
        view.deactivate();
    };

    return {
        /** Returns a token; pass it to hide(). */
        show() {
            seq += 1;
            pending.add(seq);
            hideTimer = clear(hideTimer);
            if (active) view.reconcile?.();
            if (!active && showTimer === null) {
                showTimer = timers.setTimeout(activate, delayMs);
            }
            return seq;
        },
        /** Bypasses SHOW_DELAY when an external controller has already waited out its slow-load timer. */
        showImmediate() {
            seq += 1;
            pending.add(seq);
            hideTimer = clear(hideTimer);
            showTimer = clear(showTimer);
            if (active) view.reconcile?.();
            else activate();
            return seq;
        },
        /**
         * Releases one token. Unknown or already released tokens are ignored.
         * `immediate` skips the minimum visible time, for callers that move focus right after (inert must be gone).
         */
        hide(token, immediate = false) {
            if (!pending.delete(token) || pending.size > 0) return;
            showTimer = clear(showTimer);
            if (!active) return;
            const wait = MIN_VISIBLE - (timers.now() - shownAt);
            if (wait > 0 && !immediate) {
                hideTimer = timers.setTimeout(deactivate, wait);
            } else {
                deactivate();
            }
        },
        /** Drops every token and hides immediately (bfcache restore, dismiss button). */
        reset() {
            pending.clear();
            showTimer = clear(showTimer);
            hideTimer = clear(hideTimer);
            deactivate();
            if (typeof globalThis.window !== 'undefined' && globalThis.window?.AdasiButton?.resetAll) {
                globalThis.window.AdasiButton.resetAll();
            }
        },
        /** For navigations: the page unload ends it, otherwise (e.g. a file download) release after NAV_RELEASE. */
        trackNavigation() {
            const token = this.show();
            timers.setTimeout(() => this.hide(token), NAV_RELEASE);
            return token;
        },
        isActive: () => active,
        pendingCount: () => pending.size,
    };
}

/** DOM view: full-screen overlay (or stage-scoped overlay in workspace shell), inert target shell, focus handling. */
export function createDomView(doc = document, translate = t) {
    let overlay = null;
    let text = null;
    let dismiss = null;
    let previousFocus = null;
    let inerted = [];
    let busyHost = null;
    let previousBusy = null;
    let wantsActive = false;
    let wantsSlow = false;
    let presented = false;

    const resolveStageContainer = () => (
        doc.documentElement?.hasAttribute?.('data-workspace-shell')
            ? (doc.querySelector?.('[data-workspace-stage]') ?? null)
            : null
    );

    const build = (onDismiss) => {
        const stage = resolveStageContainer();
        overlay = doc.createElement('div');
        overlay.className = stage ? 'adasi-loader-overlay adasi-loader-overlay--stage' : 'adasi-loader-overlay';
        overlay.id = 'adasiLoader';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<div class="adasi-loader-card" tabindex="-1">'
            + '<div class="adasi-loader-ring" aria-hidden="true">'
            + '<svg class="adasi-loader-svg" viewBox="0 0 64 64" width="64" height="64" focusable="false" aria-hidden="true">'
            + '<circle class="adasi-loader-track" cx="32" cy="32" r="29"></circle>'
            + '<circle class="adasi-loader-arc adasi-loader-arc--secondary" cx="32" cy="32" r="23.5"></circle>'
            + '<circle class="adasi-loader-arc adasi-loader-arc--primary" cx="32" cy="32" r="29"></circle>'
            + '</svg>'
            + '<div class="adasi-loader-medallion"><div class="adasi-loader-logo"></div></div>'
            + '</div>'
            + '<span class="adasi-loader-text"></span>'
            + '<button type="button" class="btn btn-outline-secondary btn-sm adasi-loader-dismiss ui-focus-ring" hidden></button>'
            + '</div>';
        text = overlay.querySelector('.adasi-loader-text');
        dismiss = overlay.querySelector('.adasi-loader-dismiss');
        dismiss.addEventListener('click', onDismiss);
        (stage || doc.body).appendChild(overlay);
    };

    const view = {
        bind(onDismiss, hasPending = () => true) {
            this.onDismiss = onDismiss;
            this.hasPending = hasPending;
        },
        /**
         * Built at boot, not on first display: the logo is a CSS background, which the browser only requests
         * once the element exists. Built lazily, the logo waited for the server whenever the loader was shown.
         */
        mount() {
            const stage = resolveStageContainer();
            if (!overlay && (stage || doc.body)) {
                build(() => this.onDismiss?.());
            } else if (overlay && stage && overlay.parentElement !== stage) {
                overlay.classList.add('adasi-loader-overlay--stage');
                stage.appendChild(overlay);
            }
        },
        activate() {
            wantsActive = true;
            const splash = doc.defaultView?.AdasiAuthSplash;
            this.mount();
            if (splash?.isWaiting()) return;
            if (splash?.hasVisual()) splash.removeVisual('loader-handoff');
            if (presented) return;
            presented = true;
            text.textContent = translate('js.loader.loading');
            dismiss.hidden = true;
            previousFocus = doc.activeElement;
            const stage = resolveStageContainer();
            if (stage) {
                const framesHost = stage.querySelector?.('[data-workspace-frames]') ?? null;
                busyHost = framesHost || stage;
                const targets = framesHost ? [framesHost] : Array.from(doc.querySelectorAll('#main-content'));
                inerted = targets.map((node) => ({ node, wasInert: node.inert }));
            } else {
                busyHost = doc.body;
                inerted = Array.from(doc.querySelectorAll(INERT_SELECTOR), (node) => ({ node, wasInert: node.inert }));
            }
            inerted.forEach(({ node }) => { node.inert = true; });
            previousBusy = busyHost?.getAttribute?.('aria-busy') ?? null;
            busyHost?.setAttribute?.('aria-busy', 'true');
            overlay.classList.add('active');
            const focusInsideStage = !stage || Boolean(previousFocus?.closest?.('[data-workspace-frames]'));
            if (focusInsideStage) {
                overlay.querySelector('.adasi-loader-card').focus({ preventScroll: true });
            }
            if (wantsSlow) this.showSlow();
        },
        reconcile() {
            if (wantsActive && this.hasPending?.() !== false) this.activate();
        },
        showSlow() {
            wantsSlow = true;
            if (!overlay || !presented) return;
            text.textContent = translate('js.loader.slow');
            dismiss.textContent = translate('js.loader.dismiss');
            dismiss.hidden = false;
            const stage = resolveStageContainer();
            const focusInsideStage = !stage
                || Boolean(overlay.contains?.(doc.activeElement))
                || Boolean(doc.activeElement?.closest?.('[data-workspace-frames]'));
            if (focusInsideStage) {
                dismiss.focus({ preventScroll: true });
            }
        },
        deactivate() {
            wantsActive = false;
            wantsSlow = false;
            if (!overlay || !presented) return;
            presented = false;
            overlay.classList.remove('active');
            inerted.forEach(({ node, wasInert }) => { node.inert = wasInert; });
            inerted = [];
            const host = busyHost || doc.body;
            busyHost = null;
            if (previousBusy === null) host?.removeAttribute?.('aria-busy');
            else host?.setAttribute?.('aria-busy', previousBusy);
            previousBusy = null;
            const target = previousFocus;
            previousFocus = null;
            const stage = resolveStageContainer();
            const shouldRestoreFocus = !stage || Boolean(overlay.contains?.(doc.activeElement));
            if (shouldRestoreFocus && target && target.isConnected && typeof target.focus === 'function') {
                target.focus({ preventScroll: true });
            }
        },
    };

    // Tokens continue settling while the auth splash owns interaction. Only
    // pending foreground work takes presentation after its owner has released.
    doc.defaultView?.addEventListener('adasi:auth-splash-released', () => {
        view.reconcile();
    });

    // Escape is a keyboard way out only once the slow-state Close button is offered;
    // a normal load cannot be cancelled by a stray key press.
    doc.addEventListener?.('keydown', (event) => {
        if (event.key !== 'Escape' || !presented || !dismiss || dismiss.hidden) return;
        event.preventDefault?.();
        view.onDismiss?.();
    });

    return view;
}

export function setTableBusy(wrapper, busy, translate = t) {
    if (!wrapper) return;
    const doc = wrapper.ownerDocument || (typeof document !== 'undefined' ? document : null);

    let bar = wrapper.querySelector?.(':scope > .ui-dt-loading-bar') ?? wrapper.querySelector?.('.ui-dt-loading-bar') ?? null;
    let pill = wrapper.querySelector?.(':scope > .ui-dt-loading-pill') ?? wrapper.querySelector?.('.ui-dt-loading-pill') ?? null;

    if (busy && doc) {
        if (!bar) {
            bar = doc.createElement('div');
            bar.className = 'ui-dt-loading-bar';
            bar.setAttribute('aria-hidden', 'true');
            bar.innerHTML = '<span class="ui-dt-loading-bar__fill"></span>';
            wrapper.appendChild(bar);
        }
        if (!pill) {
            pill = doc.createElement('div');
            pill.className = 'ui-dt-loading-pill';
            pill.setAttribute('role', 'status');
            pill.setAttribute('aria-live', 'polite');
            pill.innerHTML = '<svg class="ui-dt-loading-pill__spinner" viewBox="0 0 20 20" width="16" height="16" focusable="false" aria-hidden="true">'
                + '<circle class="ui-dt-loading-pill__track" cx="10" cy="10" r="8"></circle>'
                + '<circle class="ui-dt-loading-pill__arc" cx="10" cy="10" r="8"></circle>'
                + '</svg>'
                + '<span class="ui-dt-loading-pill__text"></span>';
            wrapper.appendChild(pill);
        }
    }

    const labelEl = pill?.querySelector?.('.ui-dt-loading-pill__text') ?? null;
    if (labelEl) {
        labelEl.textContent = busy ? translate('js.loader.loading') : '';
    }

    wrapper.classList.toggle('is-loading', busy);
    if (busy) {
        wrapper.setAttribute('aria-busy', 'true');
    } else {
        wrapper.removeAttribute('aria-busy');
    }
}

export function isPdfDownloadLink(link, win = globalThis.window) {
    if (!link || !link.href) return false;
    if (link.target && link.target !== '_self') return false;

    if (link.hasAttribute('data-download-pdf') || link.hasAttribute('data-pdf-download')) {
        return true;
    }

    let url;
    try {
        url = new URL(link.href, win?.location?.href || 'http://localhost');
    } catch {
        return false;
    }

    if (win?.location && url.origin !== win.location.origin) return false;

    if (/\/receipt\/print(\/|$)/i.test(url.pathname)) return true;
    if (url.searchParams.has('download') && /\/print(\/|$)/i.test(url.pathname)) return true;

    return false;
}

export function isFullPageNavigation(link, win) {
    if ((link.target && link.target !== '_self') || link.hasAttribute('download')
        || link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-no-loader')
        || link.hasAttribute('data-download-pdf') || link.hasAttribute('data-pdf-download')) {
        return false;
    }

    let url;
    try {
        url = new URL(link.href, win?.location?.href || 'http://localhost');
    } catch (error) {
        return false;
    }

    if (!/^https?:$/.test(url.protocol) || (win?.location && url.origin !== win.location.origin)) return false;

    if (/\.(pdf|xlsx|xls|csv|zip|docx|doc)$/i.test(url.pathname)) return false;
    if (url.searchParams.has('download')) return false;
    if (/\/receipt\/print(\/|$)/i.test(url.pathname)) return false;

    return url.pathname !== win.location.pathname || url.search !== win.location.search;
}

export async function downloadFileWithLoader(link, loader, win = globalThis.window) {
    if (!link || !link.href) return;
    if (link.dataset.downloadActive === 'true') return;
    link.dataset.downloadActive = 'true';

    const token = loader?.show();
    if (win.AdasiButton?.startLoading) {
        win.AdasiButton.startLoading(link);
    }

    try {
        const fetchFn = win.fetch ? win.fetch.bind(win) : globalThis.fetch;
        if (!fetchFn) {
            win.location.href = link.href;
            return;
        }

        const response = await fetchFn(link.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/pdf, application/octet-stream, */*',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP error ${response.status}`);
        }

        const blob = await response.blob();
        let disposition = '';
        if (response.headers) {
            if (typeof response.headers.get === 'function') {
                disposition = response.headers.get('Content-Disposition') || response.headers.get('content-disposition') || '';
            } else {
                disposition = response.headers['content-disposition'] || response.headers['Content-Disposition'] || '';
            }
        }
        let filename = 'document.pdf';
        const match = disposition.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)["']?/i);
        if (match && match[1]) {
            filename = decodeURIComponent(match[1]);
        } else if (link.hasAttribute('download') && link.getAttribute('download')) {
            filename = link.getAttribute('download');
        }

        const URLObj = win.URL || globalThis.URL;
        const blobUrl = URLObj.createObjectURL(blob);
        const a = win.document.createElement('a');
        a.href = blobUrl;
        a.download = filename;
        win.document.body.appendChild(a);
        a.click();
        a.remove();
        win.setTimeout(() => URLObj.revokeObjectURL(blobUrl), 1000);
    } catch (error) {
        console.error('Download error:', error);
        if (win.AdasiToast) {
            win.AdasiToast.error(win.AdasiI18n?.t?.('js.toast.failed') || 'Download failed');
        }
    } finally {
        delete link.dataset.downloadActive;
        if (token && loader) {
            loader.hide(token, true);
        }
        if (win.AdasiButton?.stopLoading) {
            win.AdasiButton.stopLoading(link);
        }
    }
}

export function bootAdasiLoader(win = globalThis.window) {
    if (!win?.document) return null;

    const view = createDomView(win.document);
    const configuredDelay = win.AdasiUiConfig?.slowLoaderThresholdMs
        ?? win.AdasiWorkspaceShellConfig?.slowLoaderThresholdMs
        ?? SHOW_DELAY;
    const loader = createLoaderController({
        view,
        showDelay: configuredDelay,
        timers: {
            setTimeout: (fn, ms) => win.setTimeout(fn, ms),
            clearTimeout: (id) => win.clearTimeout(id),
            now: () => win.performance.now(),
        },
    });
    view.bind(() => loader.reset(), () => loader.pendingCount() > 0);
    view.mount();
    win.AdasiLoader = loader;
    loader.download = (link) => downloadFileWithLoader(link, loader, win);

    const $ = win.jQuery;
    if ($) {
        // jQuery AJAX. Background requests opt out with `global: false` (existing convention);
        // DataTables shows an inline indicator on its own table instead of blocking the screen.
        $.ajaxPrefilter((options, original, jqXHR) => {
            if (options.global === false || isDataTableRequest(options)) return;
            const token = loader.show();
            jqXHR.always(() => loader.hide(token));
        });

        $(win.document).on('processing.dt', (event, settings, busy) => {
            setTableBusy(settings?.nTableWrapper, Boolean(busy));
        });
    }

    win.document.addEventListener('click', (event) => {
        const link = event.target instanceof win.Element ? event.target.closest('a[href]') : null;
        if (!link || event.defaultPrevented || event.button !== 0
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        if (isPdfDownloadLink(link, win)) {
            event.preventDefault();
            downloadFileWithLoader(link, loader, win);
            return;
        }

        if (isFullPageNavigation(link, win)) {
            // Defer until synchronous bubble handlers (such as workspace embed click routing) finish,
            // matching the submit listener so prevented clicks never leave #adasiLoader active.
            win.setTimeout(() => {
                if (!event.defaultPrevented) loader.trackNavigation();
            }, 0);
        }
    });

    win.document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof win.HTMLFormElement) || form.matches('[data-async-submit], [data-no-loader]')) return;
        if (form.target && form.target !== '_self') return;
        // Other handlers (validation) may still call preventDefault(); check after they ran.
        win.setTimeout(() => {
            if (!event.defaultPrevented) loader.trackNavigation();
        }, 0);
    });

    // Back/forward restores the page from bfcache with the overlay still active.
    win.addEventListener('pageshow', (event) => {
        if (event.persisted) loader.reset();
    });

    return loader;
}

bootAdasiLoader();
