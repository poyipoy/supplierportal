import { isSameOriginHttpUrl, isTrustedSameOrigin } from './origin-validator.js';
import { onDataTableEvent } from './datatables-events.js';

export const SESSION_CHANNEL = 'adasi-session';
export const SESSION_EVENT_KEY = 'adasi:session-event';
// Shared with the classic public/assets/js/async-export.js; parity is tested.
export const PENDING_EXPORT_STORAGE_KEY = 'adasi:pending-export-jobs:v1';
export const PASSIVE_CHECK_HEADER = 'X-Adasi-Session-Check';
export const RECONNECT_HEADER = 'X-Adasi-Reconnect';

// Requests of one page fail together; stragglers right after a refusal must not reopen the dialog.
const REFUSAL_COOLDOWN_MS = 3000;
// SweetAlert reports no reason when another alert replaces ours, which is not a refusal.
const USER_DISMISSALS = ['cancel', 'esc', 'close', 'backdrop'];
const ACTIVITY_THROTTLE_MS = 5000;
const EXPIRY_CHECK_BUFFER_MS = 1500;
const VISIBILITY_WAKE_THRESHOLD_MS = 60_000;
const VISIBILITY_DEBOUNCE_MS = 5000;
const OFFLINE_RETRY_MS = 30_000;
const MAX_TIMEOUT_MS = 2_147_483_647;
const PASSIVE_POLL_PATH_RE = /(\/notifications\/unread-count|\/conversations\/unread-count|\/conversations\/[^/]+\/messages\/latest|\/exports\/[^/]+\/status|\/local-procurement\/imports\/[^/]+\/status|\/local-procurement\/po-documents\/[^/]+\/status)$/i;

export function storageKeysToClear(keys) {
    return keys.filter(
        (key) =>
            key === PENDING_EXPORT_STORAGE_KEY ||
            key === 'adasi_supplier_reg_draft' ||
            key === 'adasi_supplier_reg_draft_v2' ||
            key === 'finance_invoice_verify_scroll' ||
            key === 'adasi:local-import-toast' ||
            key === 'adasi:local-procurement-import-toast' ||
            /^adasi:export-download-claim:/.test(key) ||
            /^adasi\.po-documents\./.test(key) ||
            /^quotation_draft_\d+(?:_[A-Za-z0-9]+)?$/.test(key) ||
            /^adasi_chat_draft_/.test(key) ||
            /^adasi:workspace:recently-closed:/.test(key) ||
            /^adasi:workspace:[^:]+:guest(?::|$)/.test(key),
    );
}

export function clearUserScopedStorage(win) {
    for (const area of ['localStorage', 'sessionStorage']) {
        try {
            const storage = win?.[area];
            if (!storage) continue;
            const keys = [];
            for (let index = 0; index < storage.length; index++) keys.push(storage.key(index));
            for (const key of storageKeysToClear(keys)) {
                try { storage.removeItem(key); } catch { /* Continue with other allowed keys. */ }
            }
        } catch { /* Storage access and enumeration can be blocked independently. */ }
    }
}

export function isSignedOutMessage(message) {
    return message !== null && typeof message === 'object' && message.type === 'signed-out';
}

// Background polls are GET requests and only ever see 401.
export function sessionEndedByResponse(response) {
    return response?.status === 401;
}

// A request the user started can also see 419 (expired CSRF token). Reloading the page fixes
// that exactly like signing in again fixes 401, so both open the same dialog.
export function sessionExpiredByResponse(response) {
    return response?.status === 401 || response?.status === 419;
}

export function collectDirtyContexts(win) {
    const tabs = [];
    try {
        const shellDirty = win?.AdasiWorkspaceShell?.getDirtyFrames?.();
        if (Array.isArray(shellDirty)) {
            for (const frame of shellDirty) {
                const title = typeof frame?.title === 'string' && frame.title.trim() !== ''
                    ? frame.title.trim()
                    : (typeof frame?.url === 'string' ? frame.url : '');
                if (title) tabs.push({ frameId: frame.frameId ?? null, title });
            }
        }
    } catch { /* Workspace shell is optional. */ }
    const topDirty = win?.AdasiUnsaved?.isDirty?.() === true;
    return {
        isDirty: topDirty || tabs.length > 0,
        tabs,
    };
}

function reconnectCopy(win, dirty) {
    return {
        title: win.AdasiI18n.t('js.session.expired_title'),
        text: dirty ? win.AdasiI18n.t('js.session.expired_text_dirty') : win.AdasiI18n.t('js.session.expired_text'),
        type: 'warning',
        confirmText: win.AdasiI18n.t('js.session.reconnect'),
        cancelText: win.AdasiI18n.t('js.session.stay'),
    };
}

// With `checkSession`, the alert runs it behind the confirm button (loader, error kept in the
// dialog) and returns its outcome as `value`; the native fallback runs it after the answer.
function askToReconnectDialog(win, checkSession, dirty) {
    const options = reconnectCopy(win, dirty);
    if (typeof win.AdasiAlert?.confirm === 'function') {
        return Promise.resolve(win.AdasiAlert.confirm(checkSession ? { ...options, preConfirm: checkSession } : options));
    }
    const confirmed = win.confirm?.(`${options.title}\n\n${options.text}`) === true;
    return Promise.resolve({ isConfirmed: confirmed, isDismissed: !confirmed, dismiss: confirmed ? undefined : 'cancel' });
}

function isReconnectOutcome(value) {
    return value !== null && typeof value === 'object' && (typeof value.token === 'string' || typeof value.url === 'string');
}

// The page keeps working with the session the server confirmed: every place that sends the
// CSRF token reads the meta tag or the form's `_token` at request time. Tokens printed into
// inline scripts cannot be updated, so the old values are remembered and swapped on the way out.
export function applyCsrfToken(doc, token, retired) {
    const meta = doc.querySelector('meta[name="csrf-token"]');
    const previous = meta?.getAttribute?.('content');
    if (previous && previous !== token) retired.add(previous);
    meta?.setAttribute?.('content', token);
    for (const input of doc.querySelectorAll?.('input[name="_token"]') ?? []) {
        if (input.value && input.value !== token) retired.add(input.value);
        input.value = token;
    }
}

function replaceRetiredHeader(headers, retired, token) {
    if (!headers || typeof headers !== 'object') return;
    if (typeof headers.get === 'function' && typeof headers.set === 'function') {
        if (retired.has(headers.get('X-CSRF-TOKEN'))) headers.set('X-CSRF-TOKEN', token);
        return;
    }
    if (Array.isArray(headers)) {
        for (const pair of headers) {
            if (Array.isArray(pair) && String(pair[0]).toLowerCase() === 'x-csrf-token' && retired.has(pair[1])) pair[1] = token;
        }
        return;
    }
    for (const key of Object.keys(headers)) {
        if (key.toLowerCase() === 'x-csrf-token' && retired.has(headers[key])) headers[key] = token;
    }
}

function replaceRetiredField(body, retired, token) {
    if (body && typeof body.get === 'function' && typeof body.set === 'function' && retired.has(body.get('_token'))) {
        body.set('_token', token);
    }
}

function readHeaderValue(headers, targetName) {
    if (!headers || typeof headers !== 'object') return null;
    const lowered = targetName.toLowerCase();
    if (typeof headers.get === 'function') {
        return headers.get(targetName) ?? headers.get(lowered);
    }
    if (Array.isArray(headers)) {
        for (const pair of headers) {
            if (Array.isArray(pair) && String(pair[0]).toLowerCase() === lowered) return pair[1];
        }
        return null;
    }
    for (const key of Object.keys(headers)) {
        if (key.toLowerCase() === lowered) return headers[key];
    }
    return null;
}

// Opening the current page again sends an unauthenticated user to login and brings them back
// after sign-in (Laravel stores it as the intended URL); with a live session it just reloads.
// The fragment is dropped because navigating to the same URL plus a fragment would not reload.
function currentPageUrl(win) {
    try {
        const url = new URL(win.location.href);
        url.hash = '';
        return url.toString();
    } catch {
        return win.AdasiLoginUrl;
    }
}

function reauthPageUrl(win) {
    try {
        const url = new URL(win.location.href);
        url.hash = '';
        url.searchParams.set('reauth', '1');
        return url.toString();
    } catch {
        return win.AdasiLoginUrl;
    }
}

function isSameOrigin(win, url) {
    return isSameOriginHttpUrl(url, win?.location?.href || win?.location?.origin);
}

function normalizePathname(win, url) {
    try {
        return new URL(url, win.location.href).pathname.replace(/\/+$/, '') || '/';
    } catch {
        return '';
    }
}

function isMatchingPath(win, candidateUrl, referenceUrl) {
    if (!candidateUrl || !referenceUrl) return false;
    try {
        const a = new URL(candidateUrl, win.location.href);
        const b = new URL(referenceUrl, win.location.href);
        return a.origin === b.origin && a.pathname.replace(/\/+$/, '') === b.pathname.replace(/\/+$/, '');
    } catch {
        return false;
    }
}

function isPassiveFetchRequest(win, input, init, statusUrl) {
    const headerVal = readHeaderValue(init?.headers, PASSIVE_CHECK_HEADER)
        ?? readHeaderValue(input?.headers, PASSIVE_CHECK_HEADER);
    if (typeof headerVal === 'string' && headerVal.toLowerCase() === 'passive') {
        return true;
    }
    const target = typeof input === 'string' || input instanceof URL ? String(input) : input?.url;
    if (!target || !isSameOrigin(win, target)) return false;
    if (isMatchingPath(win, target, statusUrl)) {
        try {
            const parsed = new URL(target, win.location.href);
            return parsed.searchParams.get('passive') === '1';
        } catch {
            return false;
        }
    }
    return PASSIVE_POLL_PATH_RE.test(normalizePathname(win, target));
}

function observeFetch(win, { onActionResponse, onPollResponse, onServerTouch, getEpoch, statusUrl, heartbeatUrl, reconnectUrl }, tokens) {
    if (typeof win.fetch !== 'function' || win.fetch.adasiSessionObserved) return;
    const original = win.fetch;
    const observed = function observedFetch(...args) {
        const [input, init] = args;
        const requestEpoch = typeof getEpoch === 'function' ? getEpoch() : 0;
        const target = typeof input === 'string' || input instanceof URL ? String(input) : input?.url;
        const sameOriginTarget = Boolean(target) && isSameOrigin(win, target);
        const isStatusCall = sameOriginTarget && isMatchingPath(win, target, statusUrl);
        const isHeartbeatCall = sameOriginTarget && isMatchingPath(win, target, heartbeatUrl);
        const isReconnectCall = sameOriginTarget && isMatchingPath(win, target, reconnectUrl);
        const isPassive = sameOriginTarget && isPassiveFetchRequest(win, input, init, statusUrl);

        if (tokens.retired.size > 0 && init && typeof init === 'object' && sameOriginTarget) {
            replaceRetiredHeader(init.headers, tokens.retired, tokens.current);
            replaceRetiredField(init.body, tokens.retired, tokens.current);
        }

        return original.apply(win, args).then((response) => {
            const currentEpoch = typeof getEpoch === 'function' ? getEpoch() : 0;
            if (requestEpoch !== currentEpoch) {
                return response;
            }
            const sameOriginResponse = isSameOrigin(win, response?.url || target);
            if (!sameOriginResponse || isStatusCall || isHeartbeatCall || isReconnectCall) {
                return response;
            }
            if (isPassive) {
                if (sessionEndedByResponse(response)) onPollResponse(response);
                return response;
            }
            if (sessionExpiredByResponse(response)) {
                onActionResponse(response);
            } else if (typeof response?.status === 'number' && response.status < 400) {
                onServerTouch();
            }
            return response;
        });
    };
    observed.adasiSessionObserved = true;
    win.fetch = observed;
}

function chainAjaxComplete(ajaxOpts, callback, flagName) {
    if (!ajaxOpts || typeof ajaxOpts !== 'object' || ajaxOpts.complete?.[flagName]) return;
    const prev = ajaxOpts.complete;
    const wrapped = function chainedComplete(...args) {
        if (Array.isArray(prev)) {
            prev.forEach((fn) => { if (typeof fn === 'function') fn.apply(this, args); });
        } else if (typeof prev === 'function') {
            prev.apply(this, args);
        }
        callback.apply(this, args);
    };
    wrapped[flagName] = true;
    ajaxOpts.complete = wrapped;
}

function observeDataTableAjaxLifecycle(win, settings, ajaxOpts, isSameOriginDataTable, handleXhrResponse) {
    if (!ajaxOpts || typeof ajaxOpts !== 'object') return;
    const targetUrl = ajaxOpts.url || (typeof settings?.ajax === 'string' ? settings.ajax : settings?.ajax?.url) || win.location?.href;
    if ((targetUrl && !isSameOrigin(win, targetUrl)) || !isSameOriginDataTable(settings)) return;

    if (typeof ajaxOpts.error === 'function' && !ajaxOpts.error.adasiSessionWrapped) {
        const originalError = ajaxOpts.error;
        const hasCustomAjaxError = Boolean(
            settings?.ajax
            && typeof settings.ajax === 'object'
            && typeof settings.ajax.error === 'function',
        );
        const wrappedError = function adasiSessionDtError(xhr, ...rest) {
            const resolvedXhr = xhr || settings?.jqXHR;
            handleXhrResponse(resolvedXhr, false);
            if (hasCustomAjaxError && sessionExpiredByResponse(resolvedXhr)) {
                return undefined;
            }
            return originalError.call(this, xhr, ...rest);
        };
        wrappedError.adasiSessionWrapped = true;
        ajaxOpts.error = wrappedError;
    }

    chainAjaxComplete(ajaxOpts, (xhr) => {
        handleXhrResponse(xhr || settings?.jqXHR, false);
    }, 'adasiSessionWrapped');
}

function observeDataTables(win, doc, { onActionResponse, onServerTouch }, tokens, handledXhrs) {
    const handleXhrResponse = (xhr, isCrossDomain) => {
        if (isCrossDomain === true || !xhr || typeof xhr !== 'object') return;
        if (handledXhrs.has(xhr)) return;
        handledXhrs.add(xhr);
        if (sessionExpiredByResponse(xhr)) {
            onActionResponse(xhr);
        } else if (typeof xhr.status === 'number' && xhr.status > 0 && xhr.status < 400) {
            onServerTouch();
        }
    };
    const isSameOriginDataTable = (settings) => {
        const ajaxUrl = typeof settings?.ajax === 'string' ? settings.ajax : settings?.ajax?.url;
        return !ajaxUrl || isSameOrigin(win, ajaxUrl);
    };

    // DataTables 3 uses native XMLHttpRequest directly, so observe .dt events via DataTables 3.
    onDataTableEvent(doc, 'xhr.dt', (_event, settings, _json, xhr) => {
        handleXhrResponse(xhr || settings?.jqXHR, !isSameOriginDataTable(settings));
    }, win);
    onDataTableEvent(doc, 'dt-error.dt', (_event, settings) => {
        if (!settings?.jqXHR) return;
        handleXhrResponse(settings.jqXHR, !isSameOriginDataTable(settings));
    }, win);
    onDataTableEvent(doc, 'preXhr.dt', (_event, settings, data, ajaxOpts) => {
        observeDataTableAjaxLifecycle(win, settings, ajaxOpts, isSameOriginDataTable, handleXhrResponse);
        if (tokens.retired.size === 0 || !ajaxOpts || typeof ajaxOpts !== 'object') return;
        const targetUrl = ajaxOpts.url || win.location?.href;
        if (targetUrl && !isSameOrigin(win, targetUrl)) return;
        replaceRetiredHeader(ajaxOpts.headers, tokens.retired, tokens.current);
        if (typeof ajaxOpts.data === 'string') {
            ajaxOpts.data = ajaxOpts.data.replace(/(^|&)_token=([^&]*)/g, (match, lead, value) => {
                let decoded;
                try { decoded = decodeURIComponent(value.replace(/\+/g, ' ')); } catch { return match; }
                return tokens.retired.has(decoded) ? `${lead}_token=${encodeURIComponent(tokens.current)}` : match;
            });
        } else {
            replaceRetiredField(ajaxOpts.data, tokens.retired, tokens.current);
            if (ajaxOpts.data && typeof ajaxOpts.data === 'object' && tokens.retired.has(ajaxOpts.data._token)) {
                ajaxOpts.data._token = tokens.current;
            }
            if (data && typeof data === 'object' && tokens.retired.has(data._token)) {
                data._token = tokens.current;
            }
        }
    }, win);
}

// DataTables alerts "Ajax error" on any failed request, which would stack a raw browser alert
// on top of the reconnect dialog. Pages that set their own errMode keep it.
function guardDataTables(win) {
    const ext = win.DataTable?.ext;
    if (!ext || ext.errMode !== 'alert') return;
    ext.errMode = (settings, techNote, message) => {
        if (sessionExpiredByResponse(settings?.jqXHR)) return;
        win.alert?.(message);
    };
}

function extractResponseReasonAsync(response, callback) {
    if (!response || typeof response.clone !== 'function') return;
    try {
        response.clone().json()
            .then((body) => {
                if (typeof body?.reason === 'string' && body.reason !== '') {
                    callback(body.reason, typeof body.reconnectable === 'boolean' ? body.reconnectable : undefined);
                }
            })
            .catch(() => { /* Non-JSON 401/419 keeps default reason. */ });
    } catch { /* Clone can fail on synthetic response objects. */ }
}

export function bootSessionSync(win, doc) {
    const session = win.AdasiSession;
    const loginPage = session ? null : doc.querySelector('[data-auth-login-page]');
    const crossTab = session?.crossTab === true || loginPage?.dataset.sessionCrossTab === '1';
    const cleanup = session?.cleanup === true || loginPage?.dataset.sessionCleanup === '1';
    if (!crossTab && !cleanup) return null;

    const clearStorage = () => { if (cleanup) clearUserScopedStorage(win); };

    const authenticated = Number.isInteger(session?.userId) && session.userId > 0;
    let ended = false;
    let asking = false;
    let refusedAt = null;
    let channel = null;

    const isEmbeddedInShell = (() => {
        try {
            return doc.documentElement?.dataset?.workspaceEmbed === 'true'
                && win.self !== win.top
                && win.parent?.location?.origin === win.location?.origin;
        } catch {
            return false;
        }
    })();

    // When the parent shell already runs cross-tab sync, child iframes must not react to
    // BroadcastChannel/storage "signed-out" events by calling top.location.assign('/login'):
    // the top window's own synchronous broadcast would otherwise have its child iframe cancel
    // the top window's in-flight POST /logout submission before it reaches the server.
    const isTopManagedEmbed = () => {
        if (!isEmbeddedInShell) return false;
        try {
            return Boolean(win.top?.AdasiSessionSync || win.top?.AdasiSession?.crossTab === true);
        } catch {
            return false;
        }
    };

    const setTimer = typeof win.setTimeout === 'function' ? win.setTimeout.bind(win) : setTimeout;
    const clearTimer = typeof win.clearTimeout === 'function' ? win.clearTimeout.bind(win) : clearTimeout;

    const initialTiming = session?.timing && typeof session.timing === 'object' ? session.timing : null;
    const hasProactiveTiming = authenticated
        && crossTab
        && !isEmbeddedInShell
        && typeof session?.statusUrl === 'string'
        && session.statusUrl !== ''
        && typeof win.fetch === 'function'
        && initialTiming !== null
        && Number.isFinite(Number(initialTiming.idle_remaining_seconds))
        && Number.isFinite(Number(initialTiming.absolute_remaining_seconds));
    const heartbeatUrl = typeof session?.heartbeatUrl === 'string' && session.heartbeatUrl !== '' ? session.heartbeatUrl : null;

    let idleTimeoutMs = hasProactiveTiming ? Math.max(60_000, Number(initialTiming.idle_timeout_seconds || 3600) * 1000) : 0;
    let heartbeatIntervalMs = hasProactiveTiming ? Math.max(15_000, Number(initialTiming.heartbeat_interval_seconds || 120) * 1000) : 120_000;
    let idleExpiresAt = hasProactiveTiming ? Date.now() + Math.max(0, Number(initialTiming.idle_remaining_seconds)) * 1000 : Infinity;
    let absoluteExpiresAt = hasProactiveTiming ? Date.now() + Math.max(0, Number(initialTiming.absolute_remaining_seconds)) * 1000 : Infinity;
    let lastServerTouchAt = Date.now();
    let lastActivityAt = 0;
    let lastBroadcastActivityAt = 0;
    let lastHeartbeatAttemptAt = 0;
    let lastVisibilityCheckAt = 0;
    let hiddenSince = null;
    let expiryTimer = null;
    let heartbeatInFlight = false;
    let verifyingExpiry = false;
    let sessionEpoch = 0;

    const clearExpiryTimer = () => {
        if (expiryTimer !== null) {
            clearTimer(expiryTimer);
            expiryTimer = null;
        }
    };

    const markEnded = () => {
        ended = true;
        clearExpiryTimer();
        win.AdasiUnsaved?.setSubmitting?.(true);
    };

    const disarmEmbeddedFrames = () => {
        if (isEmbeddedInShell) return;
        try {
            const frames = doc.querySelectorAll?.('iframe') ?? [];
            for (const frame of frames) {
                try {
                    if (frame.contentWindow?.location?.origin === win.location?.origin) {
                        frame.contentWindow.AdasiSessionSync?.markEnded?.();
                    }
                } catch { /* Cross-origin or unloaded frame cannot be accessed. */ }
            }
        } catch { /* Frame query unsupported in constrained contexts. */ }
    };

    const syncTokenToEmbeddedFrames = (token) => {
        if (isEmbeddedInShell) return;
        try {
            const frames = doc.querySelectorAll?.('iframe') ?? [];
            for (const frame of frames) {
                try {
                    if (frame.contentWindow?.location?.origin === win.location?.origin) {
                        frame.contentWindow.AdasiSessionSync?.applyExternalToken?.(token);
                    }
                } catch { /* Cross-origin or unloaded frame cannot be accessed. */ }
            }
        } catch { /* Frame query unsupported in constrained contexts. */ }
    };

    const leave = (url) => {
        markEnded();
        disarmEmbeddedFrames();
        clearStorage();
        try { win.Echo?.disconnect?.(); } catch { /* Navigation must still proceed. */ }
        if (isEmbeddedInShell && win.top) {
            try {
                win.top.location.assign(url);
                return;
            } catch { /* Sandboxed or restricted top navigation falls back to current frame. */ }
        }
        win.location.assign(url);
    };

    // Another tab signed out on purpose. A dialog the user is answering, or already declined,
    // is not overridden: the session is gone either way and they chose to stay on this page.
    const endSession = () => {
        if (!authenticated || ended || asking || refusedAt !== null || !win.AdasiLoginUrl) return;
        leave(win.AdasiLoginUrl);
    };

    const tokens = { retired: new Set(), current: null };
    const copy = session?.copy ?? {};
    const failedText = () => copy.failed || win.AdasiI18n.t('js.toast.failed');
    const reconnectUrl = typeof session?.reconnectUrl === 'string' && session.reconnectUrl !== '' ? session.reconnectUrl : null;
    const hasStatusUrl = typeof session?.statusUrl === 'string' && session.statusUrl !== '';
    const resumable = authenticated && (Boolean(reconnectUrl) || hasStatusUrl) && typeof win.fetch === 'function';
    const notify = (type, text) => {
        if (!text) return;
        try { win.AdasiAlert?.toast?.({ type, text }); } catch { /* Feedback only. */ }
    };
    const currentCsrfToken = () => tokens.current || doc.querySelector?.('meta[name="csrf-token"]')?.getAttribute?.('content') || '';

    const broadcastMessage = (payload) => {
        if (!crossTab) return;
        if (channel) {
            try { channel.postMessage(payload); return; } catch { /* Try storage. */ }
        }
        try {
            win.localStorage.setItem(SESSION_EVENT_KEY, JSON.stringify({ ...payload, timestamp: Date.now() }));
            win.localStorage.removeItem(SESSION_EVENT_KEY);
        } catch { /* Polling still detects an ended session when both transports are blocked. */ }
    };

    const publish = () => broadcastMessage({ type: 'signed-out' });

    const scheduleExpiryCheck = (explicitDelayMs = null) => {
        clearExpiryTimer();
        if (!hasProactiveTiming || ended || asking || refusedAt !== null) return;
        const deadline = Math.min(idleExpiresAt, absoluteExpiresAt);
        if (!Number.isFinite(deadline)) return;
        const rawDelay = explicitDelayMs !== null
            ? Math.max(250, explicitDelayMs)
            : Math.max(0, deadline - Date.now()) + EXPIRY_CHECK_BUFFER_MS;
        const delay = Math.min(rawDelay, MAX_TIMEOUT_MS);
        expiryTimer = setTimer(() => {
            expiryTimer = null;
            if (ended || asking || refusedAt !== null) return;
            if (explicitDelayMs === null && Date.now() < Math.min(idleExpiresAt, absoluteExpiresAt)) {
                scheduleExpiryCheck();
                return;
            }
            void verifyExpiryFromServer();
        }, delay);
        expiryTimer?.unref?.();
    };

    const applyServerTiming = (payload, { minimumRescheduleMs = null } = {}) => {
        if (!hasProactiveTiming || !payload || typeof payload !== 'object') return;
        const now = Date.now();
        if (Number.isFinite(Number(payload.idle_timeout_seconds)) && Number(payload.idle_timeout_seconds) > 0) {
            idleTimeoutMs = Math.max(60_000, Number(payload.idle_timeout_seconds) * 1000);
        }
        if (Number.isFinite(Number(payload.heartbeat_interval_seconds)) && Number(payload.heartbeat_interval_seconds) > 0) {
            heartbeatIntervalMs = Math.max(15_000, Number(payload.heartbeat_interval_seconds) * 1000);
        }
        if (Number.isFinite(Number(payload.idle_remaining_seconds))) {
            idleExpiresAt = now + Math.max(0, Number(payload.idle_remaining_seconds)) * 1000;
        }
        if (Number.isFinite(Number(payload.absolute_remaining_seconds))) {
            absoluteExpiresAt = now + Math.max(0, Number(payload.absolute_remaining_seconds)) * 1000;
        }
        const computedDelay = Math.max(0, Math.min(idleExpiresAt, absoluteExpiresAt) - now) + EXPIRY_CHECK_BUFFER_MS;
        scheduleExpiryCheck(minimumRescheduleMs !== null ? Math.max(minimumRescheduleMs, computedDelay) : null);
    };

    const recordServerTouch = () => {
        if (!hasProactiveTiming || ended || asking || refusedAt !== null) return;
        const now = Date.now();
        lastServerTouchAt = now;
        idleExpiresAt = Math.min(absoluteExpiresAt, now + idleTimeoutMs);
        scheduleExpiryCheck();
    };

    const sendHeartbeat = async () => {
        if (!hasProactiveTiming || !heartbeatUrl || heartbeatInFlight || ended || asking || refusedAt !== null) return;
        const now = Date.now();
        if (now - lastHeartbeatAttemptAt < 10_000) return;
        const heartbeatEpoch = sessionEpoch;
        heartbeatInFlight = true;
        lastHeartbeatAttemptAt = now;
        try {
            const csrf = currentCsrfToken();
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            if (csrf) headers['X-CSRF-TOKEN'] = csrf;
            const response = await win.fetch(heartbeatUrl, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                cache: 'no-store',
                priority: 'low',
            });
            if (heartbeatEpoch !== sessionEpoch) return;
            let body = null;
            try { body = await response.json(); } catch { body = null; }
            if (response.ok) {
                lastServerTouchAt = Date.now();
                applyServerTiming(body);
            } else if (sessionExpiredByResponse(response)) {
                const reason = typeof body?.reason === 'string' && body.reason !== '' ? body.reason : 'session_ended';
                if (response.status === 401) {
                    broadcastMessage({ type: 'session-expired', userId: session.userId, reason });
                }
                void askToReconnect(false, { reason });
            }
        } catch { /* Offline or transient failure: proactive timer still validates on deadline. */ }
        finally {
            heartbeatInFlight = false;
        }
    };

    const verifyExpiryFromServer = async () => {
        if (!hasProactiveTiming || ended || asking || refusedAt !== null || verifyingExpiry) return;
        const checkEpoch = sessionEpoch;
        verifyingExpiry = true;
        try {
            const url = new URL(session.statusUrl, win.location.href);
            url.searchParams.set('passive', '1');
            const response = await win.fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    [PASSIVE_CHECK_HEADER]: 'passive',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                priority: 'low',
            });
            if (checkEpoch !== sessionEpoch) return;
            let body = null;
            try { body = await response.json(); } catch { body = null; }

            if (response.ok && body?.authenticated === true) {
                if (body.user_id !== session.userId) {
                    void askToReconnect(true, { reason: 'session_ended' });
                    return;
                }
                applyServerTiming(body, { minimumRescheduleMs: 2000 });
                return;
            }

            if (response.status === 401 || response.status === 403 || response.redirected) {
                const reason = typeof body?.reason === 'string' && body.reason !== '' ? body.reason : 'session_ended';
                const reconnectable = typeof body?.reconnectable === 'boolean' ? body.reconnectable : undefined;
                broadcastMessage({
                    type: 'session-expired',
                    userId: session.userId,
                    reason,
                    ...(typeof reconnectable === 'boolean' ? { reconnectable } : {}),
                });
                void askToReconnect(true, { reason, reconnectable });
                return;
            }

            // 429 / 5xx: retry later without dropping expiry enforcement.
            scheduleExpiryCheck(OFFLINE_RETRY_MS);
        } catch {
            scheduleExpiryCheck(OFFLINE_RETRY_MS);
        } finally {
            verifyingExpiry = false;
        }
    };

    const recordActivity = ({ fromRemote = false } = {}) => {
        if (!authenticated || ended || asking || refusedAt !== null) return;
        const now = Date.now();
        if (isEmbeddedInShell) {
            if (now - lastActivityAt < ACTIVITY_THROTTLE_MS) return;
            lastActivityAt = now;
            try {
                win.parent?.AdasiSessionSync?.recordActivity?.();
            } catch { /* Cross-origin or detached parent. */ }
            return;
        }

        if (!hasProactiveTiming) return;
        if (!fromRemote && now - lastActivityAt < ACTIVITY_THROTTLE_MS) return;

        // If the local deadline already elapsed (e.g. laptop woke from sleep), verify
        // immediately against the server instead of optimistically sliding the clock.
        if (now >= Math.min(idleExpiresAt, absoluteExpiresAt)) {
            void verifyExpiryFromServer();
            return;
        }

        lastActivityAt = now;
        idleExpiresAt = Math.min(absoluteExpiresAt, now + idleTimeoutMs);
        scheduleExpiryCheck();

        if (!fromRemote && now - lastBroadcastActivityAt >= ACTIVITY_THROTTLE_MS) {
            lastBroadcastActivityAt = now;
            broadcastMessage({ type: 'activity', userId: session.userId, at: now });
        }

        if (!fromRemote && heartbeatUrl && now - lastServerTouchAt >= heartbeatIntervalMs) {
            void sendHeartbeat();
        }
    };

    // Performs 1-click server-side in-place session reconnection without page reload when
    // `reconnectUrl` (`POST /session/reconnect`) is configured, falling back to `statusUrl`
    // (`GET /session/status`). Throws only on unknown answers (offline, 429, 5xx) so the
    // operator can retry inside the modal.
    const checkSession = async () => {
        let response;
        try {
            if (reconnectUrl) {
                const url = new URL(reconnectUrl, win.location.href);
                response = await win.fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        [RECONNECT_HEADER]: '1',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
            } else {
                const url = new URL(session.statusUrl, win.location.href);
                url.searchParams.set('return', currentPageUrl(win));
                response = await win.fetch(url.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
            }
        } catch {
            throw new Error(failedText());
        }
        if (response.redirected) return { url: currentPageUrl(win) };
        let body = null;
        try { body = await response.json(); } catch { body = null; }
        if (response.ok && body?.authenticated === true && typeof body.csrf_token === 'string' && body.csrf_token !== '') {
            // Another account signed in on this browser: this page's data is not theirs.
            return body.user_id === session.userId
                ? { token: body.csrf_token, timing: body }
                : { url: currentPageUrl(win) };
        }
        if ([401, 403, 419].includes(response.status)) {
            const challenge = typeof body?.two_factor_url === 'string' && isSameOrigin(win, body.two_factor_url)
                ? body.two_factor_url
                : null;
            return { url: challenge || currentPageUrl(win) };
        }
        throw new Error(failedText());
    };

    const resume = (token, timing = null, { broadcast = true } = {}) => {
        sessionEpoch += 1;
        const now = Date.now();
        applyCsrfToken(doc, token, tokens.retired);
        tokens.current = token;
        const echoHeaders = win.Echo?.connector?.options?.auth?.headers;
        if (echoHeaders && typeof echoHeaders === 'object') replaceRetiredHeader(echoHeaders, tokens.retired, token);
        const dtAjaxHeaders = win.DataTable?.ajax?.defaults?.headers;
        if (dtAjaxHeaders && typeof dtAjaxHeaders === 'object') replaceRetiredHeader(dtAjaxHeaders, tokens.retired, token);
        syncTokenToEmbeddedFrames(token);
        refusedAt = null;
        lastServerTouchAt = now;
        lastActivityAt = now;
        lastVisibilityCheckAt = now;
        if (timing) {
            applyServerTiming(timing);
        } else {
            recordServerTouch();
        }
        try { win.AdasiSessionModal?.close?.(); } catch { /* Modal optional. */ }
        notify('success', copy.restored);
        if (broadcast && hasProactiveTiming) {
            broadcastMessage({ type: 'session-restored', userId: session.userId, at: now });
        }
        try {
            const evt = new win.Event('adasi:session-restored');
            evt.token = token;
            win.dispatchEvent(evt);
        } catch { /* Listeners are optional. */ }
    };

    const askToReconnect = async (background, options = {}) => {
        if (isEmbeddedInShell && typeof win.parent?.AdasiSessionSync?.requestReconnect === 'function') {
            win.parent.AdasiSessionSync.requestReconnect(background, options);
            return;
        }
        if (!authenticated || ended || asking || !win.AdasiLoginUrl) return;
        const force = options?.force === true;
        if (!force && refusedAt !== null && (background || Date.now() - refusedAt < REFUSAL_COOLDOWN_MS)) return;
        asking = true;
        clearExpiryTimer();
        const reason = typeof options?.reason === 'string' && options.reason !== '' ? options.reason : 'session_ended';
        const reconnectable = typeof options?.reconnectable === 'boolean' ? options.reconnectable : undefined;
        const dirtyCtx = collectDirtyContexts(win);
        let answer = null;
        try {
            if (typeof win.AdasiSessionModal?.open === 'function') {
                answer = await win.AdasiSessionModal.open({
                    reason,
                    reconnectable,
                    isDirty: dirtyCtx.isDirty,
                    dirtyTabs: dirtyCtx.tabs,
                    autoConfirm: options?.autoConfirm === true,
                    onReconnect: resumable ? checkSession : async () => ({ url: currentPageUrl(win) }),
                    onLoginRedirect: () => reauthPageUrl(win),
                });
            } else {
                answer = await askToReconnectDialog(win, resumable ? checkSession : null, dirtyCtx.isDirty);
            }
        } catch { /* A failing alert must not strand the page; the next failed request asks again. */ }
        if (answer?.isConfirmed) {
            if (answer.value?.forceLogin === true) {
                asking = false;
                leave(reauthPageUrl(win));
                return;
            }
            let outcome = isReconnectOutcome(answer.value) ? answer.value : null;
            let checkFailed = false;
            if (!outcome && resumable) {
                try { outcome = await checkSession(); } catch (error) { checkFailed = true; notify('error', error?.message); }
            }
            if (checkFailed) {
                asking = false;
                scheduleExpiryCheck();
                return;
            }
            if (typeof outcome?.token === 'string') {
                // Update sessionEpoch, CSRF tokens, and idleExpiresAt BEFORE clearing `asking`
                // so any wake/focus listener or post-restore badge poll sees the fresh deadline.
                resume(outcome.token, outcome.timing);
                asking = false;
                scheduleExpiryCheck();
            } else if (typeof outcome?.url === 'string') {
                asking = false;
                leave(outcome.url);
            } else {
                asking = false;
                leave(currentPageUrl(win));
            }
            return;
        }
        asking = false;
        if (answer?.isDismissed && USER_DISMISSALS.includes(answer.dismiss)) {
            refusedAt = Date.now();
        }
    };

    const handleSessionRestoredHint = async () => {
        if (!resumable || ended || isTopManagedEmbed()) return;
        if (!asking && refusedAt === null && !win.AdasiSessionModal?.isOpen?.() && !win.AdasiSessionModal?.isReminderVisible?.()) {
            return;
        }
        try {
            const outcome = await checkSession();
            if (typeof outcome?.token === 'string') {
                resume(outcome.token, outcome.timing, { broadcast: false });
                asking = false;
                scheduleExpiryCheck();
            }
        } catch { /* Keep existing dialog/bar untouched if verification fails. */ }
    };

    const receive = (message) => {
        if (!message || typeof message !== 'object') return;
        if (isSignedOutMessage(message)) {
            if (!isTopManagedEmbed()) endSession();
            return;
        }
        if (isTopManagedEmbed() || !authenticated || ended) return;
        if (message.type === 'activity' && message.userId === session.userId) {
            recordActivity({ fromRemote: true });
            return;
        }
        if (message.type === 'session-expired' && message.userId === session.userId) {
            void askToReconnect(true, {
                reason: message.reason || 'session_ended',
                reconnectable: typeof message.reconnectable === 'boolean' ? message.reconnectable : undefined,
            });
            return;
        }
        if (message.type === 'session-restored' && message.userId === session.userId) {
            void handleSessionRestoredHint();
        }
    };

    try {
        if (crossTab && typeof win.BroadcastChannel === 'function') {
            channel = new win.BroadcastChannel(SESSION_CHANNEL);
            channel.addEventListener('message', (event) => {
                if (typeof event?.origin === 'string' && event.origin !== '' && !isTrustedSameOrigin(event, win)) {
                    return;
                }
                receive(event.data);
            });
        }
    } catch { channel = null; }

    // Also listen when this tab supports BroadcastChannel but another tab falls back.
    win.addEventListener('storage', (event) => {
        if (!crossTab) return;
        if (event.key !== SESSION_EVENT_KEY || !event.newValue) return;
        if (typeof event.url === 'string' && event.url !== '' && !isSameOrigin(win, event.url)) return;
        try {
            if (event.storageArea && event.storageArea !== win.localStorage) return;
            receive(JSON.parse(event.newValue));
        } catch { /* Storage may be blocked or contain an unrelated malformed value. */ }
    });

    if (crossTab) {
        win.addEventListener('adasi:session-ended', (event) => {
            void askToReconnect(false, { reason: event?.detail?.reason || 'session_ended' });
        });
    }

    // A server-ended session cannot be saved by cancelling a dirty-form prompt.
    // Keep page handlers running so they can persist their own scoped drafts.
    win.addEventListener('beforeunload', (event) => {
        if (!ended) return;
        event.preventDefault = () => {};
        Object.defineProperty(event, 'returnValue', { configurable: true, get: () => '', set: () => {} });
    }, true);

    doc.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && event.target?.matches?.('[data-logout-form]')) {
            // Disarm embedded keep-alive iframes without marking the top shell `ended`,
            // so a failed POST /logout never permanently disables cross-tab sign-out here.
            disarmEmbeddedFrames();
            clearStorage();
            publish();
        }
    });

    if (loginPage) {
        const embeddedLogin = (() => {
            try {
                return win.self !== win.top && win.parent?.location?.origin === win.location?.origin;
            } catch {
                return false;
            }
        })();
        if (!embeddedLogin) {
            clearStorage();
            publish();
        }
    }

    const triggerWithOptionalReason = (background, response) => {
        const initialReason = typeof response?.sessionReason === 'string' ? response.sessionReason : 'session_ended';
        void askToReconnect(background, { reason: initialReason });
        if (typeof win.AdasiSessionModal?.setReason === 'function') {
            extractResponseReasonAsync(response, (serverReason, serverReconnectable) => {
                win.AdasiSessionModal.setReason(serverReason, serverReconnectable);
            });
        }
    };

    let statusProbePromise = null;
    const probeSessionStatus = async () => {
        if (!authenticated || ended || !session?.statusUrl) {
            return { authenticated: false, reason: 'session_ended', status: 401 };
        }
        if (asking || refusedAt !== null) {
            return { authenticated: false, reason: 'session_ended', status: 401 };
        }
        if (statusProbePromise) return statusProbePromise;
        const checkEpoch = sessionEpoch;
        statusProbePromise = (async () => {
            try {
                const url = new URL(session.statusUrl, win.location.href);
                url.searchParams.set('passive', '1');
                const response = await win.fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        [PASSIVE_CHECK_HEADER]: 'passive',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    priority: 'low',
                });
                if (checkEpoch !== sessionEpoch) {
                    return { authenticated: true, status: 200 };
                }
                let body = null;
                try { body = await response.json(); } catch { body = null; }
                if (response.ok && body?.authenticated === true) {
                    if (body.user_id !== session.userId) {
                        return { authenticated: false, reason: 'session_ended', status: 401 };
                    }
                    return { authenticated: true, status: response.status };
                }
                if (response.status === 401 || response.status === 403 || response.redirected) {
                    const reason = typeof body?.reason === 'string' && body.reason !== '' ? body.reason : 'session_ended';
                    const reconnectable = typeof body?.reconnectable === 'boolean' ? body.reconnectable : undefined;
                    return { authenticated: false, reason, reconnectable, status: response.status || 401 };
                }
                return { authenticated: null, status: response.status };
            } catch {
                return { authenticated: null, networkError: true, status: 0 };
            } finally {
                statusProbePromise = null;
            }
        })();
        return statusProbePromise;
    };

    const api = Object.freeze({
        handlePollResponse(response) {
            if (crossTab && sessionEndedByResponse(response)) {
                triggerWithOptionalReason(true, response);
            }
        },
        handleActionResponse(response) {
            if (crossTab && sessionExpiredByResponse(response)) {
                triggerWithOptionalReason(false, response);
            }
        },
        requestReconnect(background = false, options = {}) {
            return askToReconnect(background, options);
        },
        probeSessionStatus,
        recordActivity() {
            recordActivity({ fromRemote: false });
        },
        applyExternalToken(token) {
            if (typeof token !== 'string' || token === '') return;
            applyCsrfToken(doc, token, tokens.retired);
            tokens.current = token;
            const echoHeaders = win.Echo?.connector?.options?.auth?.headers;
            if (echoHeaders && typeof echoHeaders === 'object') replaceRetiredHeader(echoHeaders, tokens.retired, token);
            const dtAjaxHeaders = win.DataTable?.ajax?.defaults?.headers;
            if (dtAjaxHeaders && typeof dtAjaxHeaders === 'object') replaceRetiredHeader(dtAjaxHeaders, tokens.retired, token);
            refusedAt = null;
        },
        // True while the dialog is open or after the user chose to stay; background polling stops then.
        isSessionExpired() {
            return asking || refusedAt !== null;
        },
        markEnded,
        destroy() {
            clearExpiryTimer();
        },
    });

    if (authenticated && crossTab) {
        observeFetch(win, {
            onActionResponse: api.handleActionResponse,
            onPollResponse: api.handlePollResponse,
            onServerTouch: recordServerTouch,
            getEpoch: () => sessionEpoch,
            statusUrl: session?.statusUrl,
            heartbeatUrl,
            reconnectUrl,
        }, tokens);
        const handledXhrs = new WeakSet();
        const callbacks = {
            onActionResponse: api.handleActionResponse,
            onServerTouch: recordServerTouch,
        };
        observeDataTables(win, doc, callbacks, tokens, handledXhrs);
        guardDataTables(win);
    }

    if (hasProactiveTiming || (authenticated && crossTab && isEmbeddedInShell)) {
        const onUserActivity = () => recordActivity({ fromRemote: false });
        for (const eventName of ['pointerdown', 'keydown', 'input', 'change', 'submit']) {
            doc.addEventListener(eventName, onUserActivity, { passive: true, capture: true });
        }
    }

    if (hasProactiveTiming) {
        const checkOnWake = ({ forceIfSlept = false } = {}) => {
            if (ended || asking || refusedAt !== null) return;
            const now = Date.now();
            const pastDeadline = now >= Math.min(idleExpiresAt, absoluteExpiresAt);
            if (!pastDeadline && !forceIfSlept) return;
            if (now - lastVisibilityCheckAt < VISIBILITY_DEBOUNCE_MS) return;
            lastVisibilityCheckAt = now;
            void verifyExpiryFromServer();
        };

        doc.addEventListener('visibilitychange', () => {
            if (doc.visibilityState === 'hidden') {
                hiddenSince = Date.now();
                return;
            }
            if (doc.visibilityState === 'visible') {
                const sleptLong = hiddenSince !== null && (Date.now() - hiddenSince) >= VISIBILITY_WAKE_THRESHOLD_MS;
                hiddenSince = null;
                checkOnWake({ forceIfSlept: sleptLong });
            }
        });

        win.addEventListener('focus', () => checkOnWake());
        win.addEventListener('online', () => checkOnWake({ forceIfSlept: true }));
        win.addEventListener('pageshow', (event) => {
            if (event?.persisted) checkOnWake({ forceIfSlept: true });
        });

        scheduleExpiryCheck();
    }

    win.AdasiSessionSync = api;
    return api;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootSessionSync(window, document), { once: true });
    } else {
        bootSessionSync(window, document);
    }
}
