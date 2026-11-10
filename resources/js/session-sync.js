export const SESSION_CHANNEL = 'adasi-session';
export const SESSION_EVENT_KEY = 'adasi:session-event';
// Shared with the classic public/assets/js/async-export.js; parity is tested.
export const PENDING_EXPORT_STORAGE_KEY = 'adasi:pending-export-jobs:v1';
// Requests of one page fail together; stragglers right after a refusal must not reopen the dialog.
const REFUSAL_COOLDOWN_MS = 3000;
// SweetAlert reports no reason when another alert replaces ours, which is not a refusal.
const USER_DISMISSALS = ['cancel', 'esc', 'close', 'backdrop'];

export function storageKeysToClear(keys) {
    return keys.filter((key) => key === PENDING_EXPORT_STORAGE_KEY || /^quotation_draft_\d+$/.test(key));
}

export function clearUserScopedStorage(win) {
    try {
        const storage = win.localStorage;
        const keys = [];
        for (let index = 0; index < storage.length; index++) keys.push(storage.key(index));
        for (const key of storageKeysToClear(keys)) {
            try { storage.removeItem(key); } catch { /* Continue with other allowed keys. */ }
        }
    } catch { /* Storage access and enumeration can be blocked independently. */ }
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

function reconnectCopy(win) {
    const dirty = win.AdasiUnsaved?.isDirty?.() === true;
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
function askToReconnectDialog(win, checkSession) {
    const options = reconnectCopy(win);
    if (typeof win.AdasiAlert?.confirm === 'function') {
        return Promise.resolve(win.AdasiAlert.confirm(checkSession ? { ...options, preConfirm: checkSession } : options));
    }
    const confirmed = win.confirm?.(`${options.title}\n\n${options.text}`) === true;
    return Promise.resolve({ isConfirmed: confirmed, isDismissed: !confirmed, dismiss: confirmed ? undefined : 'cancel' });
}

function isReconnectOutcome(value) {
    return value !== null && typeof value === 'object' && (typeof value.token === 'string' || typeof value.url === 'string' || value.unauthenticated === true);
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

function isSameOrigin(win, url) {
    try {
        return new URL(url, win.location.href).origin === win.location.origin;
    } catch {
        return false;
    }
}

function observeFetch(win, onResponse, tokens) {
    if (typeof win.fetch !== 'function' || win.fetch.adasiSessionObserved) return;
    const original = win.fetch;
    const observed = function observedFetch(...args) {
        const [input, init] = args;
        const target = typeof input === 'string' || input instanceof URL ? String(input) : input?.url;
        if (tokens.retired.size > 0 && init && typeof init === 'object' && isSameOrigin(win, target)) {
            replaceRetiredHeader(init.headers, tokens.retired, tokens.current);
            replaceRetiredField(init.body, tokens.retired, tokens.current);
        }
        return original.apply(win, args).then((response) => {
            if (sessionExpiredByResponse(response) && isSameOrigin(win, response.url)) onResponse(response);
            return response;
        });
    };
    observed.adasiSessionObserved = true;
    win.fetch = observed;
}

function observeJQuery(win, doc, onResponse, tokens) {
    if (typeof win.jQuery !== 'function') return;
    win.jQuery(doc).on('ajaxComplete', (event, xhr, settings) => {
        if (settings?.crossDomain !== true && sessionExpiredByResponse(xhr)) onResponse(xhr);
    });
    // Prefilters run after jQuery serialised `data` and decided `crossDomain`, before headers are sent.
    win.jQuery.ajaxPrefilter?.((options) => {
        if (tokens.retired.size === 0 || options?.crossDomain === true) return;
        replaceRetiredHeader(options.headers, tokens.retired, tokens.current);
        if (typeof options.data === 'string') {
            options.data = options.data.replace(/(^|&)_token=([^&]*)/g, (match, lead, value) => {
                let decoded;
                try { decoded = decodeURIComponent(value.replace(/\+/g, ' ')); } catch { return match; }
                return tokens.retired.has(decoded) ? `${lead}_token=${encodeURIComponent(tokens.current)}` : match;
            });
        } else {
            replaceRetiredField(options.data, tokens.retired, tokens.current);
        }
    });
}

// DataTables alerts "Ajax error" on any failed request, which would stack a raw browser alert
// on top of the reconnect dialog. Pages that set their own errMode keep it.
function guardDataTables(win) {
    const ext = win.jQuery?.fn?.dataTable?.ext;
    if (!ext || ext.errMode !== 'alert') return;
    ext.errMode = (settings, techNote, message) => {
        if (sessionExpiredByResponse(settings?.jqXHR)) return;
        win.alert?.(message);
    };
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

    const leave = (url) => {
        ended = true;
        clearStorage();
        win.AdasiUnsaved?.setSubmitting?.(true);
        try { win.Echo?.disconnect?.(); } catch { /* Navigation must still proceed. */ }
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
    const resumable = authenticated && typeof session?.statusUrl === 'string' && session.statusUrl !== '' && typeof win.fetch === 'function';
    const notify = (type, text) => {
        if (!text) return;
        try { win.AdasiAlert?.toast?.({ type, text }); } catch { /* Feedback only. */ }
    };

    // Asks the server whether this page can keep working, without any credentials. A live
    // session (signed in again in another tab, or only the token went stale) or a valid
    // remember-me cookie restores it in place; otherwise the server decides the next page.
    // Throws only when the answer is unknown (offline, 429, 5xx) so the user can try again.
    const checkSession = async () => {
        const url = new URL(session.statusUrl, win.location.href);
        url.searchParams.set('return', currentPageUrl(win));
        let response;
        try {
            response = await win.fetch(url.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
        } catch {
            throw new Error(failedText());
        }
        if (response.redirected) return { url: currentPageUrl(win) };
        let body = null;
        try { body = await response.json(); } catch { body = null; }
        if (response.ok && body?.authenticated === true && typeof body.csrf_token === 'string' && body.csrf_token !== '') {
            // Another account signed in on this browser: this page's data is not theirs.
            return body.user_id === session.userId ? { token: body.csrf_token } : { url: currentPageUrl(win) };
        }
        if ([401, 403, 419].includes(response.status)) {
            const challenge = typeof body?.two_factor_url === 'string' && isSameOrigin(win, body.two_factor_url) ? body.two_factor_url : null;
            if (challenge) return { url: challenge };
            return { unauthenticated: true };
        }
        throw new Error(failedText());
    };

    const resume = (token) => {
        applyCsrfToken(doc, token, tokens.retired);
        tokens.current = token;
        const echoHeaders = win.Echo?.connector?.options?.auth?.headers;
        if (echoHeaders && typeof echoHeaders === 'object') replaceRetiredHeader(echoHeaders, tokens.retired, token);
        refusedAt = null;
        notify('success', copy.restored);
        try { win.dispatchEvent(new win.Event('adasi:session-restored')); } catch { /* Listeners are optional. */ }
    };

    const askToReconnect = async (background) => {
        if (!authenticated || ended || asking || !win.AdasiLoginUrl) return;
        if (refusedAt !== null && (background || Date.now() - refusedAt < REFUSAL_COOLDOWN_MS)) return;
        asking = true;
        let answer = null;
        try {
            answer = await askToReconnectDialog(win, resumable ? checkSession : null);
        } catch { /* A failing alert must not strand the page; the next failed request asks again. */ }
        if (answer?.isConfirmed) {
            let outcome = isReconnectOutcome(answer.value) ? answer.value : null;
            let checkFailed = false;
            if (!outcome && resumable) {
                try { outcome = await checkSession(); } catch (error) { checkFailed = true; notify('error', error?.message); }
            }
            asking = false;
            if (checkFailed) return;
            if (typeof outcome?.token === 'string') {
                resume(outcome.token);
            } else if (typeof outcome?.url === 'string') {
                leave(outcome.url);
            } else if (!resumable) {
                leave(currentPageUrl(win));
            } else {
                refusedAt = Date.now();
                notify('warning', copy.unauthenticated || win.AdasiI18n.t('js.session.expired_text'));
            }
            return;
        }
        asking = false;
        if (answer?.isDismissed && USER_DISMISSALS.includes(answer.dismiss)) {
            refusedAt = Date.now();
        }
    };

    const receive = (message) => {
        if (isSignedOutMessage(message)) endSession();
    };

    try {
        if (crossTab && typeof win.BroadcastChannel === 'function') {
            channel = new win.BroadcastChannel(SESSION_CHANNEL);
            channel.addEventListener('message', (event) => receive(event.data));
        }
    } catch { channel = null; }

    // Also listen when this tab supports BroadcastChannel but another tab falls back.
    win.addEventListener('storage', (event) => {
        if (!crossTab) return;
        if (event.key !== SESSION_EVENT_KEY || !event.newValue) return;
        try {
            if (event.storageArea && event.storageArea !== win.localStorage) return;
            receive(JSON.parse(event.newValue));
        } catch { /* Storage may be blocked or contain an unrelated malformed value. */ }
    });

    const publish = () => {
        if (!crossTab) return;
        if (channel) {
            try { channel.postMessage({ type: 'signed-out' }); return; } catch { /* Try storage. */ }
        }
        try {
            win.localStorage.setItem(SESSION_EVENT_KEY, JSON.stringify({ type: 'signed-out', timestamp: Date.now() }));
            win.localStorage.removeItem(SESSION_EVENT_KEY);
        } catch { /* Polling still detects an ended session when both transports are blocked. */ }
    };

    if (crossTab) win.addEventListener('adasi:session-ended', () => { askToReconnect(false); });
    // A server-ended session cannot be saved by cancelling a dirty-form prompt.
    // Keep page handlers running so they can persist their own scoped drafts.
    win.addEventListener('beforeunload', (event) => {
        if (!ended) return;
        event.preventDefault = () => {};
        Object.defineProperty(event, 'returnValue', { configurable: true, get: () => '', set: () => {} });
    }, true);

    doc.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && event.target?.matches?.('[data-logout-form]')) {
            clearStorage();
            publish();
        }
    });

    if (loginPage) {
        clearStorage();
        publish();
    }

    const api = Object.freeze({
        handlePollResponse(response) {
            if (crossTab && sessionEndedByResponse(response)) askToReconnect(true);
        },
        handleActionResponse(response) {
            if (crossTab && sessionExpiredByResponse(response)) askToReconnect(false);
        },
        // True while the dialog is open or after the user chose to stay; background polling stops then.
        isSessionExpired() {
            return asking || refusedAt !== null;
        },
    });

    if (authenticated && crossTab) {
        observeFetch(win, api.handleActionResponse, tokens);
        observeJQuery(win, doc, api.handleActionResponse, tokens);
        guardDataTables(win);
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
