export const SESSION_CHANNEL = 'adasi-session';
export const SESSION_EVENT_KEY = 'adasi:session-event';
// Shared with the classic public/assets/js/async-export.js; parity is tested.
export const PENDING_EXPORT_STORAGE_KEY = 'adasi:pending-export-jobs:v1';

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

export function sessionEndedByResponse(response) {
    return response?.status === 401;
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
    let channel = null;

    const endSession = () => {
        if (!authenticated || ended || !win.AdasiLoginUrl) return;
        ended = true;
        clearStorage();
        win.AdasiUnsaved?.setSubmitting?.(true);
        try { win.Echo?.disconnect?.(); } catch { /* Navigation must still proceed. */ }
        win.location.assign(win.AdasiLoginUrl);
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

    if (crossTab) win.addEventListener('adasi:session-ended', endSession);
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
            if (crossTab && sessionEndedByResponse(response)) win.dispatchEvent(new win.Event('adasi:session-ended'));
        },
    });
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
