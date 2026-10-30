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

function askToReconnectDialog(win) {
    const options = reconnectCopy(win);
    if (typeof win.AdasiAlert?.confirm === 'function') return Promise.resolve(win.AdasiAlert.confirm(options));
    const confirmed = win.confirm?.(`${options.title}\n\n${options.text}`) === true;
    return Promise.resolve({ isConfirmed: confirmed, isDismissed: !confirmed, dismiss: confirmed ? undefined : 'cancel' });
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

function observeFetch(win, onResponse) {
    if (typeof win.fetch !== 'function' || win.fetch.adasiSessionObserved) return;
    const original = win.fetch;
    const observed = function observedFetch(...args) {
        return original.apply(win, args).then((response) => {
            if (sessionExpiredByResponse(response) && isSameOrigin(win, response.url)) onResponse(response);
            return response;
        });
    };
    observed.adasiSessionObserved = true;
    win.fetch = observed;
}

function observeJQuery(win, doc, onResponse) {
    if (typeof win.jQuery !== 'function') return;
    win.jQuery(doc).on('ajaxComplete', (event, xhr, settings) => {
        if (settings?.crossDomain !== true && sessionExpiredByResponse(xhr)) onResponse(xhr);
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

    const askToReconnect = async (background) => {
        if (!authenticated || ended || asking || !win.AdasiLoginUrl) return;
        if (refusedAt !== null && (background || Date.now() - refusedAt < REFUSAL_COOLDOWN_MS)) return;
        asking = true;
        let answer = null;
        try {
            answer = await askToReconnectDialog(win);
        } catch { /* A failing alert must not strand the page; the next failed request asks again. */ }
        asking = false;
        if (answer?.isConfirmed) {
            leave(currentPageUrl(win));
        } else if (answer?.isDismissed && USER_DISMISSALS.includes(answer.dismiss)) {
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
        observeFetch(win, api.handleActionResponse);
        observeJQuery(win, doc, api.handleActionResponse);
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
