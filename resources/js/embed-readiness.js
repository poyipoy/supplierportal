/**
 * Initial embed page & data readiness coordinator.
 *
 * Ensures staged keep-alive workspace frames do not commit and reveal empty
 * table/widget shells while boot-time DataTables initial AJAX requests or
 * boot GET fetches are still rendering rows into the DOM.
 */

import { onDataTableEvent, offDataTableEvent } from './datatables-events.js';

const IGNORED_BOOT_PATHS_RE = /(?:\/notifications\/unread-count|\/conversations\/unread-count|\/broadcasting\/auth|\/locale\/|\/_debugbar|\/up)(?:\/|$)/i;

function defaultTimers(win = globalThis.window) {
    const hasWin = Boolean(win && typeof win.setTimeout === 'function');
    return {
        setTimeout: (fn, ms) => (hasWin ? win.setTimeout(fn, ms) : globalThis.setTimeout(fn, ms)),
        clearTimeout: (id) => (hasWin ? win.clearTimeout(id) : globalThis.clearTimeout(id)),
        raf: (fn) => {
            if (hasWin && typeof win.requestAnimationFrame === 'function') {
                let done = false;
                let timer = 0;
                const run = () => {
                    if (done) return;
                    done = true;
                    if (timer) win.clearTimeout(timer);
                    fn();
                };
                win.requestAnimationFrame(run);
                // Fallback guarantees progress even if host window/tab is throttled or backgrounded.
                timer = win.setTimeout(run, 64);
                return;
            }
            (hasWin ? win.setTimeout : globalThis.setTimeout)(fn, 0);
        },
        microtask: (fn) => {
            if (typeof queueMicrotask === 'function') {
                queueMicrotask(fn);
            } else {
                Promise.resolve().then(fn);
            }
        },
    };
}

export function isTrackableBootGetRequest({ url, method = 'GET', origin = 'http://localhost' } = {}) {
    if (String(method || 'GET').toUpperCase() !== 'GET') return false;
    if (!url || typeof url !== 'string') return false;

    try {
        const parsed = new URL(url, origin);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
        if (parsed.origin !== origin) return false;
        const normalizedPath = parsed.pathname.replace(/\/+$/, '') || '/';
        if (IGNORED_BOOT_PATHS_RE.test(normalizedPath)) return false;
        return true;
    } catch {
        return false;
    }
}

export function createEmbedReadinessController({
    origin = 'http://localhost',
    maxDataWaitMs = 60000,
    maxDomLoadWaitMs = 15000,
    timers = defaultTimers(),
    scanActiveTables = null,
    onReady = null,
} = {}) {
    let domReady = false;
    let windowLoaded = false;
    let settleWindowClosed = false;
    let settleClosingScheduled = false;
    let finalPaintScheduled = false;
    let ready = false;
    let fallbackTimerId = 0;
    let domLoadFallbackTimerId = 0;

    let pendingPromises = 0;
    const pendingTables = new Set();
    const settledTables = new Set();
    const seenPreXhrTables = new Set();
    const pendingRequests = new Set();

    const { promise: readyPromise, resolve: resolveReadyPromise } = Promise.withResolvers();

    const finalizeReady = (reason = 'settled') => {
        if (ready) return;
        ready = true;
        settleWindowClosed = true;
        if (fallbackTimerId) {
            timers.clearTimeout(fallbackTimerId);
            fallbackTimerId = 0;
        }
        if (domLoadFallbackTimerId) {
            timers.clearTimeout(domLoadFallbackTimerId);
            domLoadFallbackTimerId = 0;
        }
        const detail = { reason };
        resolveReadyPromise(detail);
        try {
            onReady?.(detail);
        } catch {}
    };

    const evaluate = () => {
        if (ready || !windowLoaded || !settleWindowClosed) return;
        if (pendingPromises > 0 || pendingTables.size > 0 || pendingRequests.size > 0) {
            return;
        }
        if (finalPaintScheduled) return;
        finalPaintScheduled = true;

        timers.raf(() => {
            finalPaintScheduled = false;
            if (ready) return;
            if (pendingPromises > 0 || pendingTables.size > 0 || pendingRequests.size > 0) {
                return;
            }
            finalizeReady('settled');
        });
    };

    const tryCloseSettleWindow = () => {
        if (ready || settleWindowClosed || settleClosingScheduled) return;
        if (!windowLoaded || pendingPromises > 0) return;

        settleClosingScheduled = true;
        timers.microtask(() => {
            timers.raf(() => {
                settleClosingScheduled = false;
                if (ready || settleWindowClosed) return;
                if (pendingPromises > 0) return;
                try {
                    scanActiveTables?.();
                } catch {}
                settleWindowClosed = true;
                evaluate();
            });
        });
    };

    return {
        get isReady() {
            return ready;
        },
        whenReady() {
            return readyPromise;
        },
        trackPromise(promise) {
            if (!promise || typeof promise.then !== 'function' || ready) {
                return promise;
            }
            pendingPromises += 1;
            Promise.resolve(promise).finally(() => {
                pendingPromises = Math.max(0, pendingPromises - 1);
                tryCloseSettleWindow();
                evaluate();
            });
            return promise;
        },
        markDomReady() {
            if (domReady) return;
            domReady = true;
            if (!windowLoaded && maxDomLoadWaitMs > 0 && !domLoadFallbackTimerId) {
                // Prevent a stalled external CDN asset or image from blocking readiness after DOM parsed.
                domLoadFallbackTimerId = timers.setTimeout(() => {
                    domLoadFallbackTimerId = 0;
                    if (ready || windowLoaded) return;
                    windowLoaded = true;
                    if (maxDataWaitMs > 0 && !fallbackTimerId) {
                        fallbackTimerId = timers.setTimeout(() => {
                            fallbackTimerId = 0;
                            finalizeReady('timeout-fallback');
                        }, maxDataWaitMs);
                    }
                    tryCloseSettleWindow();
                    evaluate();
                }, maxDomLoadWaitMs);
            }
            tryCloseSettleWindow();
        },
        markWindowLoaded() {
            domReady = true;
            if (domLoadFallbackTimerId) {
                timers.clearTimeout(domLoadFallbackTimerId);
                domLoadFallbackTimerId = 0;
            }
            if (!windowLoaded) {
                windowLoaded = true;
                if (maxDataWaitMs > 0 && !fallbackTimerId) {
                    fallbackTimerId = timers.setTimeout(() => {
                        fallbackTimerId = 0;
                        finalizeReady('timeout-fallback');
                    }, maxDataWaitMs);
                }
            }
            tryCloseSettleWindow();
            evaluate();
        },
        onTableInitStart(tableKey) {
            if (!tableKey || ready || settledTables.has(tableKey)) return;
            if (!settleWindowClosed || pendingTables.has(tableKey)) {
                pendingTables.add(tableKey);
            }
        },
        onTablePreXhr(tableKey) {
            if (!tableKey || ready) return;
            seenPreXhrTables.add(tableKey);
            if (!settleWindowClosed || pendingTables.has(tableKey)) {
                pendingTables.add(tableKey);
            }
        },
        hasSeenPreXhr(tableKey) {
            return seenPreXhrTables.has(tableKey);
        },
        onTableSettled(tableKey) {
            if (!tableKey) return;
            settledTables.add(tableKey);
            if (pendingTables.delete(tableKey)) {
                evaluate();
            }
        },
        onTableProcessing(tableKey, busy) {
            if (!tableKey || ready) return;
            if (busy) {
                if (!settleWindowClosed || pendingTables.has(tableKey)) {
                    pendingTables.add(tableKey);
                }
                return;
            }
            if (seenPreXhrTables.has(tableKey) || pendingTables.has(tableKey)) {
                settledTables.add(tableKey);
                if (pendingTables.delete(tableKey)) {
                    evaluate();
                }
            }
        },
        onRequestStart(reqId, { url, method = 'GET' } = {}) {
            if (ready || settleWindowClosed) return false;
            if (!isTrackableBootGetRequest({ url, method, origin })) return false;
            pendingRequests.add(reqId);
            return true;
        },
        onRequestEnd(reqId) {
            if (pendingRequests.delete(reqId)) {
                evaluate();
            }
        },
        getPendingState() {
            return {
                isReady: ready,
                domReady,
                windowLoaded,
                settleWindowClosed,
                pendingPromises,
                pendingTables: pendingTables.size,
                pendingRequests: pendingRequests.size,
            };
        },
    };
}

function tableKeyFor(settings) {
    if (!settings || typeof settings !== 'object') return null;
    return settings.table || settings;
}

function isAjaxDataTable(settings) {
    if (!settings || typeof settings !== 'object') return false;
    return Boolean(settings.ajax || settings.features?.serverSide);
}

function chainAjaxComplete(ajaxOpts, callback) {
    if (!ajaxOpts || typeof ajaxOpts !== 'object' || ajaxOpts.complete?.adasiEmbedWrapped) return;
    const prev = ajaxOpts.complete;
    const wrapped = function adasiEmbedComplete(...args) {
        if (Array.isArray(prev)) {
            prev.forEach((fn) => { if (typeof fn === 'function') fn.apply(this, args); });
        } else if (typeof prev === 'function') {
            prev.apply(this, args);
        }
        callback.apply(this, args);
    };
    wrapped.adasiEmbedWrapped = true;
    ajaxOpts.complete = wrapped;
}

export function bootEmbedReadiness(win = globalThis.window) {
    if (!win?.document?.documentElement) return null;
    const isEmbed = win.document.documentElement.dataset.workspaceEmbed === 'true' && win.self !== win.top;
    if (!isEmbed) return null;
    if (win.AdasiEmbedReadiness) return win.AdasiEmbedReadiness;

    const origin = win.location?.origin || 'http://localhost';
    const doc = win.document;
    let reqSeq = 0;

    let controller = null;
    const tableWrapperFor = (settings) => {
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

    const scanActiveTables = () => {
        const apiTables = win.DataTable?.tables?.({ api: true });
        const dtSettings = apiTables?.settings?.() || win.DataTable?.settings;
        if (!Array.isArray(dtSettings) && !(dtSettings && typeof dtSettings.length === 'number')) return;
        for (let i = 0; i < dtSettings.length; i += 1) {
            const settings = dtSettings[i];
            if (!isAjaxDataTable(settings)) continue;
            const key = tableKeyFor(settings);
            const wrapper = tableWrapperFor(settings);
            const xhr = settings.jqXHR;
            const jqXhrPending = Boolean(xhr && xhr.readyState !== 4);
            const domLoading = Boolean(wrapper?.classList?.contains?.('is-loading'));
            const isInitialised = Boolean(settings.initialised);
            if (!isInitialised || jqXhrPending || domLoading) {
                controller.onTableInitStart(key);
                const onXhrDone = () => {
                    win.setTimeout(() => {
                        const latestWrapper = tableWrapperFor(settings);
                        if (!latestWrapper?.classList?.contains?.('is-loading')) {
                            controller.onTableSettled(key);
                        }
                    }, 32);
                };
                if (jqXhrPending) {
                    if (typeof xhr.always === 'function') {
                        xhr.always(onXhrDone);
                    } else if (typeof xhr.addEventListener === 'function') {
                        xhr.addEventListener('loadend', onXhrDone, { once: true });
                    }
                }
            }
        }
    };

    controller = createEmbedReadinessController({
        origin,
        timers: defaultTimers(win),
        scanActiveTables,
        onReady: () => {
            try {
                win.dispatchEvent(new win.CustomEvent('adasi:embed-ready'));
            } catch {}
        },
    });

    if (typeof win.fetch === 'function') {
        const prevFetch = win.fetch.bind(win);
        win.fetch = (input, init = {}) => {
            const method = String(init?.method || (input instanceof win.Request ? input.method : 'GET')).toUpperCase();
            const rawUrl = typeof input === 'string' ? input : (input && typeof input.url === 'string' ? input.url : '');
            let absUrl = '';
            try {
                absUrl = rawUrl ? new URL(rawUrl, win.location.href).toString() : '';
            } catch {
                absUrl = '';
            }
            const reqId = `fetch-${++reqSeq}`;
            const tracked = controller.onRequestStart(reqId, { url: absUrl, method });
            const promise = prevFetch(input, init);
            if (!tracked) return promise;
            return promise.finally(() => controller.onRequestEnd(reqId));
        };
    }

    let dtBound = false;
    let jqPrefilterBound = false;

    const onTableProcessing = (_event, settings, busy) => {
        if (isAjaxDataTable(settings)) {
            controller.onTableProcessing(tableKeyFor(settings), Boolean(busy));
        }
    };
    const bindTableProcessing = (tableNode) => {
        if (!tableNode) return;
        offDataTableEvent(tableNode, 'processing.dt.adasiEmbed', undefined, win);
        onDataTableEvent(tableNode, 'processing.dt.adasiEmbed', onTableProcessing, win);
    };

    const bindDataTableListeners = () => {
        if (!dtBound) {
            dtBound = true;
            onDataTableEvent(doc, 'preInit.dt', (_event, settings) => {
                bindTableProcessing(settings?.table);
                if (isAjaxDataTable(settings)) {
                    controller.onTableInitStart(tableKeyFor(settings));
                }
            }, win);
            onDataTableEvent(doc, 'preXhr.dt', (_event, settings, _data, ajaxOpts) => {
                const key = tableKeyFor(settings);
                controller.onTablePreXhr(key);
                chainAjaxComplete(ajaxOpts, (xhr, textStatus) => {
                    const failed = (textStatus && textStatus !== 'success' && textStatus !== 'nocontent' && textStatus !== 'notmodified')
                        || Number(xhr?.status) >= 400;
                    if (failed) {
                        controller.onTableProcessing(key, false);
                        controller.onTableSettled(key);
                    }
                });
            }, win);
            onDataTableEvent(doc, 'draw.dt error.dt dt-error.dt', (_event, settings) => {
                controller.onTableSettled(tableKeyFor(settings));
            }, win);
            onDataTableEvent(doc, 'init.dt', (_event, settings) => {
                const key = tableKeyFor(settings);
                const activeXhr = Boolean(settings?.jqXHR && settings.jqXHR.readyState !== 4);
                if (!controller.hasSeenPreXhr(key) && !activeXhr) {
                    controller.onTableSettled(key);
                }
            }, win);
            onDataTableEvent(doc, 'xhr.dt', (_event, settings, json, xhr) => {
                if (!json || (xhr && xhr.status >= 400)) {
                    controller.onTableSettled(tableKeyFor(settings));
                }
            }, win);
            onDataTableEvent(doc, 'processing.dt', onTableProcessing, win);
        }

        const existingTables = win.DataTable?.tables?.();
        if (Array.isArray(existingTables)) {
            existingTables.forEach(bindTableProcessing);
        }

        if (!jqPrefilterBound && typeof win.jQuery?.ajaxPrefilter === 'function') {
            jqPrefilterBound = true;
            win.jQuery.ajaxPrefilter((options, _original, jqXHR) => {
                const method = String(options?.type || options?.method || 'GET').toUpperCase();
                const isDt = Boolean(
                    typeof options?.data === 'string' && /(?:^|&)draw=\d+/.test(options.data)
                    || (options?.data && typeof options.data === 'object' && Object.prototype.hasOwnProperty.call(options.data, 'draw')),
                );
                if (isDt) return;

                let absUrl = '';
                try {
                    absUrl = options?.url ? new URL(options.url, win.location.href).toString() : '';
                } catch {
                    absUrl = '';
                }
                const reqId = `jq-${++reqSeq}`;
                if (controller.onRequestStart(reqId, { url: absUrl, method })) {
                    jqXHR?.always?.(() => controller.onRequestEnd(reqId));
                }
            });
        }
    };

    bindDataTableListeners();

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', () => {
            bindDataTableListeners();
            controller.markDomReady();
        }, { once: true });
    } else {
        controller.markDomReady();
    }

    if (doc.readyState === 'complete') {
        bindDataTableListeners();
        controller.markWindowLoaded();
    } else {
        win.addEventListener('load', () => {
            bindDataTableListeners();
            controller.markWindowLoaded();
        }, { once: true });
    }

    const api = Object.freeze({
        get isReady() {
            return controller.isReady;
        },
        whenReady: () => controller.whenReady(),
        trackPromise: (promise) => controller.trackPromise(promise),
        getPendingState: () => controller.getPendingState(),
    });

    win.AdasiEmbedReadiness = api;
    return api;
}
