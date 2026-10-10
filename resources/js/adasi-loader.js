import { t } from './i18n.js';

export const SHOW_DELAY = 150;
export const MIN_VISIBLE = 300;
export const SLOW_AFTER = 20000;
export const NAV_RELEASE = 10000;

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
export function createLoaderController({ view, timers }) {
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
            if (!active && showTimer === null) {
                showTimer = timers.setTimeout(activate, SHOW_DELAY);
            }
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

/** DOM view: full-screen overlay, inert page shell, focus handling. */
export function createDomView(doc = document, translate = t) {
    let overlay = null;
    let text = null;
    let dismiss = null;
    let previousFocus = null;
    let inerted = [];

    const build = (onDismiss) => {
        overlay = doc.createElement('div');
        overlay.className = 'adasi-loader-overlay';
        overlay.id = 'adasiLoader';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<div class="adasi-loader-card" tabindex="-1">'
            + '<div class="adasi-loader-ring"><div class="adasi-loader-logo"></div></div>'
            + '<span class="adasi-loader-text"></span>'
            + '<button type="button" class="btn btn-outline-secondary btn-sm adasi-loader-dismiss" hidden></button>'
            + '</div>';
        text = overlay.querySelector('.adasi-loader-text');
        dismiss = overlay.querySelector('.adasi-loader-dismiss');
        dismiss.addEventListener('click', onDismiss);
        doc.body.appendChild(overlay);
    };

    return {
        bind(onDismiss) {
            this.onDismiss = onDismiss;
        },
        /**
         * Built at boot, not on first display: the logo is a CSS background, which the browser only requests
         * once the element exists. Built lazily, the logo waited for the server whenever the loader was shown.
         */
        mount() {
            if (!overlay && doc.body) build(() => this.onDismiss?.());
        },
        activate() {
            this.mount();
            text.textContent = translate('js.loader.loading');
            dismiss.hidden = true;
            previousFocus = doc.activeElement;
            inerted = Array.from(doc.querySelectorAll(INERT_SELECTOR));
            inerted.forEach((node) => { node.inert = true; });
            doc.body.setAttribute('aria-busy', 'true');
            overlay.classList.add('active');
            overlay.querySelector('.adasi-loader-card').focus({ preventScroll: true });
        },
        showSlow() {
            if (!overlay) return;
            text.textContent = translate('js.loader.slow');
            dismiss.textContent = translate('js.loader.dismiss');
            dismiss.hidden = false;
            dismiss.focus({ preventScroll: true });
        },
        deactivate() {
            if (!overlay) return;
            overlay.classList.remove('active');
            inerted.forEach((node) => { node.inert = false; });
            inerted = [];
            doc.body.removeAttribute('aria-busy');
            const target = previousFocus;
            previousFocus = null;
            if (target && target.isConnected && typeof target.focus === 'function') {
                target.focus({ preventScroll: true });
            }
        },
    };
}

function setTableBusy(wrapper, busy) {
    if (!wrapper) return;
    wrapper.classList.toggle('is-loading', busy);
    if (busy) {
        wrapper.setAttribute('aria-busy', 'true');
    } else {
        wrapper.removeAttribute('aria-busy');
    }
}

function isFullPageNavigation(link, win) {
    if ((link.target && link.target !== '_self') || link.hasAttribute('download')
        || link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-no-loader')) {
        return false;
    }

    let url;
    try {
        url = new URL(link.href, win.location.href);
    } catch (error) {
        return false;
    }

    if (!/^https?:$/.test(url.protocol) || url.origin !== win.location.origin) return false;

    return url.pathname !== win.location.pathname || url.search !== win.location.search;
}

export function bootAdasiLoader(win = globalThis.window) {
    if (!win?.document) return null;

    const view = createDomView(win.document);
    const loader = createLoaderController({
        view,
        timers: {
            setTimeout: (fn, ms) => win.setTimeout(fn, ms),
            clearTimeout: (id) => win.clearTimeout(id),
            now: () => win.performance.now(),
        },
    });
    view.bind(() => loader.reset());
    view.mount();
    win.AdasiLoader = loader;

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
        if (isFullPageNavigation(link, win)) loader.trackNavigation();
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
