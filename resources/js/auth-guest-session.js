const SESSION_CHANNEL = 'adasi-session';
const SESSION_EVENT_KEY = 'adasi:session-event';
const PENDING_EXPORT_STORAGE_KEY = 'adasi:pending-export-jobs:v1';

function storageKeysToClear(keys) {
    return keys.filter(
        (key) =>
            key === PENDING_EXPORT_STORAGE_KEY ||
            /^quotation_draft_\d+(?:_[A-Za-z0-9]+)?$/.test(key) ||
            /^adasi_chat_draft_/.test(key) ||
            /^adasi:workspace:/.test(key),
    );
}

function clearUserScopedStorage(win) {
    for (const area of ['localStorage', 'sessionStorage']) {
        try {
            const storage = win?.[area];
            if (!storage) continue;
            const keys = [];
            for (let index = 0; index < storage.length; index++) keys.push(storage.key(index));
            for (const key of storageKeysToClear(keys)) {
                try { storage.removeItem(key); } catch { /* Continue with allowed keys. */ }
            }
        } catch { /* Storage access can be blocked independently. */ }
    }
}

export function bootAuthGuestSession(win = window, doc = document) {
    if (win?.AdasiSession) return;
    const loginPage = doc?.querySelector?.('[data-auth-login-page]');
    if (!loginPage) return;

    const crossTab = loginPage.dataset?.sessionCrossTab === '1';
    const cleanup = loginPage.dataset?.sessionCleanup === '1';
    if (!crossTab && !cleanup) return;

    const embeddedLogin = (() => {
        try {
            return win.self !== win.top && win.parent?.location?.origin === win.location?.origin;
        } catch {
            return false;
        }
    })();
    if (embeddedLogin) return;

    if (cleanup) {
        clearUserScopedStorage(win);
    }
    if (!crossTab) return;

    const payload = { type: 'signed-out' };
    try {
        if (typeof win.BroadcastChannel === 'function') {
            const channel = new win.BroadcastChannel(SESSION_CHANNEL);
            try {
                channel.postMessage(payload);
                return;
            } finally {
                channel.close?.();
            }
        }
    } catch { /* Fall back to transient localStorage broadcast. */ }

    try {
        win.localStorage?.setItem(SESSION_EVENT_KEY, JSON.stringify({ ...payload, timestamp: Date.now() }));
        win.localStorage?.removeItem(SESSION_EVENT_KEY);
    } catch { /* Storage can be blocked in restricted contexts. */ }
}
