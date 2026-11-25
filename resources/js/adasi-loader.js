import { t } from './i18n.js';
import { isDownloadOrBinaryUrl } from './workspace-shell-core.js';
import { onDataTableEvent, offDataTableEvent } from './datatables-events.js';

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
    let isStageMounted = false;

    const resolveStageContainer = () => (
        doc.documentElement?.hasAttribute?.('data-workspace-shell')
            ? (doc.querySelector?.('[data-workspace-stage]') ?? null)
            : null
    );

    const createSvgEl = (tag, attrs = {}) => {
        const el = typeof doc.createElementNS === 'function'
            ? doc.createElementNS('http://www.w3.org/2000/svg', tag)
            : doc.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            el.setAttribute(key, String(value));
        }
        return el;
    };

    const build = (onDismiss) => {
        const stage = resolveStageContainer();
        isStageMounted = Boolean(stage);
        overlay = doc.createElement('div');
        overlay.className = stage ? 'adasi-loader-overlay adasi-loader-overlay--stage' : 'adasi-loader-overlay';
        overlay.id = 'adasiLoader';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');

        const card = doc.createElement('div');
        card.className = 'adasi-loader-card';
        card.setAttribute('tabindex', '-1');

        const ring = doc.createElement('div');
        ring.className = 'adasi-loader-ring';
        ring.setAttribute('aria-hidden', 'true');

        const svg = createSvgEl('svg', {
            class: 'adasi-loader-svg',
            viewBox: '0 0 64 64',
            width: '64',
            height: '64',
            focusable: 'false',
            'aria-hidden': 'true',
        });
        svg.appendChild(createSvgEl('circle', { class: 'adasi-loader-track', cx: '32', cy: '32', r: '29' }));
        svg.appendChild(createSvgEl('circle', { class: 'adasi-loader-arc adasi-loader-arc--secondary', cx: '32', cy: '32', r: '23.5' }));
        svg.appendChild(createSvgEl('circle', { class: 'adasi-loader-arc adasi-loader-arc--primary', cx: '32', cy: '32', r: '29' }));

        const medallion = doc.createElement('div');
        medallion.className = 'adasi-loader-medallion';
        const logo = doc.createElement('div');
        logo.className = 'adasi-loader-logo';
        medallion.appendChild(logo);

        ring.appendChild(svg);
        ring.appendChild(medallion);

        const textEl = doc.createElement('span');
        textEl.className = 'adasi-loader-text';

        const dismissBtn = doc.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'ui-data-action ui-motion adasi-loader-dismiss ui-focus-ring';
        dismissBtn.hidden = true;

        card.appendChild(ring);
        card.appendChild(textEl);
        card.appendChild(dismissBtn);
        overlay.appendChild(card);

        // Prefer querySelector when mocked in unit tests; fall back to constructed nodes.
        text = overlay.querySelector?.('.adasi-loader-text') ?? textEl;
        dismiss = overlay.querySelector?.('.adasi-loader-dismiss') ?? dismissBtn;
        dismiss.addEventListener('click', onDismiss);
        if (!stage && typeof overlay.showPopover === 'function') {
            overlay.setAttribute?.('popover', 'manual');
        }
        (stage || doc.body).appendChild(overlay);
        if (typeof doc.addEventListener === 'function') {
            doc.addEventListener('adasi:modal-shown', () => {
                if (!presented || !wantsActive || resolveStageContainer()) return;
                if (overlay?.getAttribute?.('popover') !== 'manual' || typeof overlay.showPopover !== 'function') return;
                try {
                    if (overlay.matches?.(':popover-open')) overlay.hidePopover();
                    overlay.showPopover();
                } catch {}
            });
        }
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
                return;
            }
            if (!overlay) return;

            const needsStageMove = Boolean(stage) && (overlay.parentElement ? overlay.parentElement !== stage : !isStageMounted);
            const needsBodyMove = !stage && (overlay.parentElement ? overlay.parentElement !== doc.body : isStageMounted);
            if (needsStageMove) {
                if (typeof overlay.hidePopover === 'function' && overlay.matches?.(':popover-open')) {
                    try { overlay.hidePopover(); } catch {}
                }
                overlay.removeAttribute?.('popover');
                overlay.classList.add('adasi-loader-overlay--stage');
                stage.appendChild(overlay);
                isStageMounted = true;
            } else if (needsBodyMove) {
                overlay.classList.remove('adasi-loader-overlay--stage');
                if (typeof overlay.showPopover === 'function') {
                    overlay.setAttribute?.('popover', 'manual');
                }
                doc.body?.appendChild?.(overlay);
                isStageMounted = false;
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
            if (!stage && typeof overlay.showPopover === 'function') {
                if (!overlay.hasAttribute?.('popover')) {
                    overlay.setAttribute?.('popover', 'manual');
                }
                try {
                    if (overlay.matches?.(':popover-open')) overlay.hidePopover();
                    overlay.showPopover();
                } catch {}
            }
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
            if (typeof overlay.hidePopover === 'function' && overlay.matches?.(':popover-open')) {
                try { overlay.hidePopover(); } catch {}
            }
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

const resolveBusyLabel = (options, translate) => {
    if (typeof options.label === 'string' && options.label !== '') {
        return options.label;
    }

    const tableText = translate('js.tables.loading');
    if (tableText && tableText !== 'js.tables.loading') {
        return tableText;
    }

    return translate('js.loader.loading');
};

const clearBusyGeometry = (wrapper) => {
    if (!wrapper?.style?.removeProperty) return;
    wrapper.style.removeProperty('--ui-loading-pill-top');
    wrapper.style.removeProperty('--ui-loading-pill-left');
    wrapper.style.removeProperty('--ui-loading-bar-top');
    wrapper.style.removeProperty('--ui-loading-bar-left');
    wrapper.style.removeProperty('--ui-loading-bar-width');
};

const syncBusyGeometry = (wrapper, doc, isRegionMode) => {
    if (typeof wrapper?.getBoundingClientRect !== 'function' || !wrapper?.style?.setProperty) return;

    const wrapRect = wrapper.getBoundingClientRect();
    if (!wrapRect || wrapRect.width <= 0 || wrapRect.height <= 0) {
        clearBusyGeometry(wrapper);
        return;
    }

    const scrollEl = wrapper.closest?.('.ui-data-table__scroll, .ui-table-responsive, .table-responsive') || wrapper;
    const sRect = (scrollEl !== wrapper && typeof scrollEl?.getBoundingClientRect === 'function')
        ? scrollEl.getBoundingClientRect()
        : wrapRect;

    const scrollLeft = Number(wrapper.scrollLeft) || 0;
    const scrollTop = Number(wrapper.scrollTop) || 0;
    const vpHeight = Number(doc?.defaultView?.innerHeight) || 800;

    const barLeftPx = Math.round((sRect.left - wrapRect.left) + scrollLeft);
    const barWidthPx = Math.round(scrollEl === wrapper ? (wrapper.clientWidth || sRect.width) : sRect.width);
    const pillLeftPx = Math.round(barLeftPx + (barWidthPx / 2));

    wrapper.style.setProperty('--ui-loading-bar-left', `${barLeftPx}px`);
    wrapper.style.setProperty('--ui-loading-bar-width', `${barWidthPx}px`);
    wrapper.style.setProperty('--ui-loading-pill-left', `${pillLeftPx}px`);

    const sectionHeader = wrapper.querySelector?.(':scope > .ui-data-table__header') ?? null;
    const headerRect = (sectionHeader && typeof sectionHeader.getBoundingClientRect === 'function')
        ? sectionHeader.getBoundingClientRect()
        : null;
    if (headerRect && headerRect.height > 0) {
        const barTopPx = Math.round(Math.max(0, (headerRect.bottom - wrapRect.top) + scrollTop));
        wrapper.style.setProperty('--ui-loading-bar-top', `${barTopPx}px`);
    } else {
        wrapper.style.removeProperty('--ui-loading-bar-top');
    }

    const thead = !isRegionMode ? wrapper.querySelector?.('table thead') : null;
    const tbody = !isRegionMode ? wrapper.querySelector?.('table tbody') : null;
    if (thead && typeof thead.getBoundingClientRect === 'function') {
        const theadRect = thead.getBoundingClientRect();
        const tbodyRect = (tbody && typeof tbody.getBoundingClientRect === 'function') ? tbody.getBoundingClientRect() : null;
        const dataVpTop = Math.max(theadRect.bottom, 12);
        const rawDataBottom = tbodyRect && tbodyRect.height > 8 ? tbodyRect.bottom : Math.max(wrapRect.bottom, theadRect.bottom + 56);
        const dataVpBottom = Math.min(rawDataBottom, vpHeight - 12);
        const pillVpY = dataVpBottom > dataVpTop + 28
            ? dataVpTop + ((dataVpBottom - dataVpTop) / 2)
            : theadRect.bottom + 24;
        const pillTopPx = Math.round(pillVpY - wrapRect.top + scrollTop);
        wrapper.style.setProperty('--ui-loading-pill-top', `${pillTopPx}px`);
        return;
    }

    const regionFloor = headerRect && headerRect.height > 0 ? headerRect.bottom : wrapRect.top;
    const visibleTop = Math.max(regionFloor, 12);
    const visibleBottom = Math.min(wrapRect.bottom, vpHeight - 12);
    if (visibleBottom > visibleTop + 28) {
        const pillVpY = visibleTop + ((visibleBottom - visibleTop) / 2);
        const pillTopPx = Math.round(pillVpY - wrapRect.top + scrollTop);
        wrapper.style.setProperty('--ui-loading-pill-top', `${pillTopPx}px`);
    } else if (headerRect && headerRect.height > 0) {
        const pillTopPx = Math.round((headerRect.bottom + 24) - wrapRect.top + scrollTop);
        wrapper.style.setProperty('--ui-loading-pill-top', `${pillTopPx}px`);
    } else {
        wrapper.style.removeProperty('--ui-loading-pill-top');
    }
};

const createSpinnerSvg = (doc, size = 16) => {
    const makeSvg = (tag, attrs = {}) => {
        const el = typeof doc.createElementNS === 'function'
            ? doc.createElementNS('http://www.w3.org/2000/svg', tag)
            : doc.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            el.setAttribute(key, String(value));
        }
        return el;
    };
    const spinner = makeSvg('svg', {
        class: 'ui-dt-loading-pill__spinner',
        viewBox: '0 0 20 20',
        width: String(size),
        height: String(size),
        focusable: 'false',
        'aria-hidden': 'true',
    });
    spinner.appendChild(makeSvg('circle', { class: 'ui-dt-loading-pill__track', cx: '10', cy: '10', r: '8' }));
    spinner.appendChild(makeSvg('circle', { class: 'ui-dt-loading-pill__arc', cx: '10', cy: '10', r: '8' }));
    return spinner;
};

export function createInlineLoader(label, options = {}, doc = (typeof document !== 'undefined' ? document : null)) {
    if (!doc) return null;
    const container = doc.createElement('div');
    const inline = Boolean(options.inline);
    container.className = inline ? 'ui-inline-loading ui-inline-loading--compact' : 'ui-inline-loading';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');

    const pill = doc.createElement('div');
    pill.className = 'ui-inline-loading__pill';
    pill.appendChild(createSpinnerSvg(doc, Number(options.size) || 16));

    const textEl = doc.createElement('span');
    textEl.className = 'ui-inline-loading__text';
    textEl.textContent = String(label || resolveBusyLabel({}, t));
    pill.appendChild(textEl);

    container.appendChild(pill);
    return container;
}

export function setTableBusy(wrapper, busy, optionsOrTranslate = t) {
    if (!wrapper) return;
    const options = typeof optionsOrTranslate === 'function'
        ? { translate: optionsOrTranslate }
        : (optionsOrTranslate && typeof optionsOrTranslate === 'object' ? optionsOrTranslate : {});
    const translate = typeof options.translate === 'function' ? options.translate : t;
    const ariaTarget = options.ariaTarget || wrapper;
    const isRegionMode = options.mode === 'region';
    const doc = wrapper.ownerDocument || (typeof document !== 'undefined' ? document : null);

    let bar = wrapper.querySelector?.(':scope > .ui-dt-loading-bar') ?? wrapper.querySelector?.('.ui-dt-loading-bar') ?? null;
    let pill = wrapper.querySelector?.(':scope > .ui-dt-loading-pill') ?? wrapper.querySelector?.('.ui-dt-loading-pill') ?? null;

    if (busy && doc) {
        if (!bar) {
            bar = doc.createElement('div');
            bar.className = 'ui-dt-loading-bar';
            bar.setAttribute('aria-hidden', 'true');
            const fill = doc.createElement('span');
            fill.className = 'ui-dt-loading-bar__fill';
            bar.appendChild(fill);
            wrapper.appendChild(bar);
        }
        if (!pill) {
            pill = doc.createElement('div');
            pill.className = 'ui-dt-loading-pill';
            pill.setAttribute('role', 'status');
            pill.setAttribute('aria-live', 'polite');
            const spinner = createSpinnerSvg(doc, 16);
            const pillText = doc.createElement('span');
            pillText.className = 'ui-dt-loading-pill__text';
            pill.appendChild(spinner);
            pill.appendChild(pillText);
            wrapper.appendChild(pill);
        }
    }

    const labelEl = pill?.querySelector?.('.ui-dt-loading-pill__text') ?? null;
    if (labelEl) {
        labelEl.textContent = busy ? resolveBusyLabel(options, translate) : '';
    }

    if (busy && isRegionMode) {
        wrapper.setAttribute?.('data-loading-mode', 'region');
    } else if (!busy && isRegionMode) {
        wrapper.removeAttribute?.('data-loading-mode');
    }

    if (busy) {
        syncBusyGeometry(wrapper, doc, isRegionMode);
    } else {
        clearBusyGeometry(wrapper);
    }

    wrapper.classList.toggle('ui-data-loading-host', Boolean(busy));
    wrapper.classList.toggle('is-loading', Boolean(busy));
    if (ariaTarget) {
        if (busy) {
            ariaTarget.setAttribute?.('aria-busy', 'true');
        } else {
            ariaTarget.removeAttribute?.('aria-busy');
        }
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
        || link.hasAttribute('data-ui-dropdown-trigger') || link.hasAttribute('data-ui-tab')
        || link.hasAttribute('popovertarget') || link.hasAttribute('data-ui-modal-open')
        || link.hasAttribute('data-ui-modal-close') || link.hasAttribute('data-no-loader')
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
    if (isDownloadOrBinaryUrl(url.href, url.origin)) return false;

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
        if (Boolean(import.meta.env?.DEV)) {
            win.AdasiDebug?.error ? win.AdasiDebug.error('Download error:', error) : globalThis.AdasiDebug?.error?.('Download error:', error);
        }
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
    loader.setTableBusy = (wrapper, busy, optionsOrTranslate) => setTableBusy(wrapper, busy, optionsOrTranslate);
    loader.createInlineLoader = (label, options = {}) => createInlineLoader(label, options, win.document);

    const chainAjaxComplete = (ajaxOpts, callback) => {
        if (!ajaxOpts || typeof ajaxOpts !== 'object' || ajaxOpts.complete?.adasiLoaderWrapped) return;
        const prev = ajaxOpts.complete;
        const wrapped = function adasiLoaderComplete(...args) {
            if (Array.isArray(prev)) {
                prev.forEach((fn) => { if (typeof fn === 'function') fn.apply(this, args); });
            } else if (typeof prev === 'function') {
                prev.apply(this, args);
            }
            callback.apply(this, args);
        };
        wrapped.adasiLoaderWrapped = true;
        ajaxOpts.complete = wrapped;
    };

    const resolveTableWrapper = (settings) => {
        const ApiCtor = win.DataTable?.Api;
        if (settings && ApiCtor) {
            try {
                return new ApiCtor(settings).table().container();
            } catch {
                // Fall back to direct property lookup for minimal test stubs.
            }
        }
        return settings?.tableWrapper || null;
    };
    const onDataTableProcessing = (_event, settings, busy) => {
        setTableBusy(resolveTableWrapper(settings), Boolean(busy));
    };
    const bindTableProcessing = (tableNode) => {
        if (!tableNode) return;
        offDataTableEvent(tableNode, 'processing.dt.adasiLoader', undefined, win);
        onDataTableEvent(tableNode, 'processing.dt.adasiLoader', onDataTableProcessing, win);
    };

    onDataTableEvent(win.document, 'processing.dt', onDataTableProcessing, win);
    onDataTableEvent(win.document, 'preInit.dt', (_event, settings) => {
        bindTableProcessing(settings?.table);
    }, win);
    onDataTableEvent(win.document, 'preXhr.dt', (_event, settings, _data, ajaxOpts) => {
        chainAjaxComplete(ajaxOpts, (xhr, textStatus) => {
            const failed = (textStatus && textStatus !== 'success' && textStatus !== 'nocontent' && textStatus !== 'notmodified')
                || Number(xhr?.status) >= 400;
            if (failed) {
                setTableBusy(resolveTableWrapper(settings), false);
            }
        });
    }, win);
    const existingTables = win.DataTable?.tables?.();
    if (Array.isArray(existingTables)) {
        existingTables.forEach(bindTableProcessing);
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
