import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { bootSessionSync, SESSION_CHANNEL, SESSION_EVENT_KEY, sessionEndedByResponse, PENDING_EXPORT_STORAGE_KEY, storageKeysToClear, clearUserScopedStorage } from '../../resources/js/session-sync.js';

function fixture({ session = { crossTab: true, cleanup: false, userId: 41 }, login = null, broadcast = true, blocked = false, initialStorage = [] } = {}) {
    const win = new EventTarget();
    const doc = Object.assign(new EventTarget(), { querySelector: () => login });
    const redirects = [];
    const sent = [];
    const values = new Map(initialStorage.map((key) => [key, 'saved']));
    let disconnects = 0;
    let channel;
    Object.assign(win, {
        Event, AdasiSession: session, AdasiLoginUrl: 'https://portal.example.test/login',
        location: { assign: (url) => redirects.push(url) },
        Echo: { disconnect: () => { disconnects++; } },
        BroadcastChannel: broadcast ? class extends EventTarget {
            constructor(name) { super(); assert.equal(name, SESSION_CHANNEL); channel = this; }
            postMessage(message) { sent.push(message); }
        } : undefined,
    });
    const storage = {
        get length() { return values.size; },
        key: (index) => [...values.keys()][index] ?? null,
        removeItem: (key) => { sent.push({ remove: key }); values.delete(key); },
        setItem: (key, value) => { sent.push({ key, value }); values.set(key, value); },
    };
    Object.defineProperty(win, 'localStorage', { get() { if (blocked) throw Error('blocked'); return storage; } });
    const api = bootSessionSync(win, doc);
    return {
        win, doc, api, redirects, sent, values, storage,
        disconnects: () => disconnects,
        receive(data) { channel.dispatchEvent(Object.assign(new Event('message'), { data })); },
        storageEvent(newValue, key = SESSION_EVENT_KEY, storageArea = storage) {
            win.dispatchEvent(Object.assign(new Event('storage'), { key, newValue, storageArea }));
        },
        submit({ prevented = false, logout = true } = {}) {
            const event = new Event('submit', { cancelable: true });
            Object.defineProperty(event, 'target', { value: { matches: () => logout } });
            if (prevented) event.preventDefault();
            doc.dispatchEvent(event);
        },
    };
}

test('signed-out redirects once to the server URL and disconnects Echo once', () => {
    const f = fixture();
    f.receive({ type: 'other' });
    f.receive(null);
    assert.deepEqual(f.redirects, []);
    f.receive({ type: 'signed-out' });
    f.receive({ type: 'signed-out' });
    f.api.handlePollResponse({ status: 401 });
    assert.deepEqual(f.redirects, ['https://portal.example.test/login']);
    assert.equal(f.disconnects(), 1);
});

test('storage fallback ignores malformed, removed, unrelated and sessionStorage messages', () => {
    const f = fixture({ broadcast: false });
    f.storageEvent('{');
    f.storageEvent(null);
    f.storageEvent('{"type":"signed-out"}', 'another-key');
    f.storageEvent('{"type":"signed-out"}', SESSION_EVENT_KEY, {});
    assert.equal(f.redirects.length, 0);
    f.storageEvent('{"type":"signed-out"}');
    assert.equal(f.redirects.length, 1);
});

test('only 401 ends a polling session; ignored errors leave it intact', () => {
    const f = fixture();
    for (const status of [200, 302, 403, 419, 500, 503]) {
        assert.equal(sessionEndedByResponse({ status }), false);
        f.api.handlePollResponse({ status });
    }
    for (const error of [undefined, null, new Error('network failure')]) f.api.handlePollResponse(error);
    assert.equal(f.redirects.length, 0);
    f.api.handlePollResponse({ status: 401 });
    assert.equal(f.redirects.length, 1);
});

test('both badge polls use the tested response handler', async () => {
    const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    assert.equal(layout.match(/AdasiSessionSync\?\.handlePollResponse\(r\)/g).length, 2);
});

test('logout submission publishes without intercepting POST, while cancelled and other forms do nothing', () => {
    const f = fixture();
    f.submit({ prevented: true });
    f.submit({ logout: false });
    assert.equal(f.sent.length, 0);
    f.submit();
    assert.deepEqual(f.sent, [{ type: 'signed-out' }]);
    assert.equal(f.redirects.length, 0);
});

test('guest login publishes via fallback and removes its transient key without redirecting itself', () => {
    const f = fixture({ session: null, login: { dataset: { sessionCrossTab: '1' } }, broadcast: false });
    assert.equal(f.sent[0].key, SESSION_EVENT_KEY);
    assert.deepEqual(Object.keys(JSON.parse(f.sent[0].value)).sort(), ['timestamp', 'type']);
    assert.equal(f.values.has(SESSION_EVENT_KEY), false);
    assert.equal(f.redirects.length, 0);
});

test('flags off and public pages other than login do nothing', () => {
    for (const options of [{ session: null }, { session: { crossTab: false, cleanup: false, userId: 41 } }, { session: null, login: { dataset: { sessionCrossTab: '0' } } }]) {
        const f = fixture(options);
        f.submit();
        f.win.dispatchEvent(new Event('adasi:session-ended'));
        assert.equal(f.api, null);
        assert.equal(f.sent.length, 0);
        assert.equal(f.redirects.length, 0);
    }
});

test('blocked storage, absent Echo and throwing Echo do not break redirect', () => {
    const f = fixture({ broadcast: false, blocked: true });
    assert.doesNotThrow(() => f.submit());
    assert.doesNotThrow(() => f.storageEvent('{"type":"signed-out"}'));
    f.win.Echo = undefined;
    f.api.handlePollResponse({ status: 401 });
    assert.equal(f.redirects.length, 1);
    const g = fixture();
    g.win.Echo.disconnect = () => { throw Error('socket gone'); };
    assert.doesNotThrow(() => g.receive({ type: 'signed-out' }));
    assert.equal(g.redirects.length, 1);
});

test('ended sessions suppress native dirty prompts while allowing draft-saving handlers to run', () => {
    const f = fixture();
    let saved = false;
    f.win.addEventListener('beforeunload', (event) => { saved = true; event.preventDefault(); event.returnValue = ''; });
    f.receive({ type: 'signed-out' });
    const event = new Event('beforeunload', { cancelable: true });
    f.win.dispatchEvent(event);
    assert.equal(saved, true);
    assert.equal(event.defaultPrevented, false);
});

const removable = [PENDING_EXPORT_STORAGE_KEY, 'quotation_draft_123', 'quotation_draft_0'];
const retained = ['quotation_draft_41_123', 'quotation_draft_42_123', 'quotation_draft_41_hash', 'quotation_draft_hash', 'quotation_draft_123_extra', 'adasi_supplier_reg_draft', 'adasi.sidebar.41', 'adasi_chat_draft_41_1', 'supplierAuditScoreGuide'];

test('cleanup removes only the exact export key and numeric legacy drafts, retaining account drafts and other state', () => {
    assert.deepEqual(storageKeysToClear([...removable, ...retained]), removable);
    for (const trigger of ['submit', 'receive', 'poll', 'login']) {
        const f = fixture({
            session: trigger === 'login' ? null : { crossTab: true, cleanup: true, userId: 41 },
            login: trigger === 'login' ? { dataset: { sessionCrossTab: '1', sessionCleanup: '1' } } : null,
            initialStorage: [...removable, ...retained],
        });
        if (trigger === 'submit') f.submit();
        if (trigger === 'receive') f.receive({ type: 'signed-out' });
        if (trigger === 'poll') f.api.handlePollResponse({ status: 401 });
        assert.deepEqual([...f.values.keys()], retained, trigger);
    }
});

test('cleanup is independent of cross-tab sync and honours its own disabled flag', () => {
    const f = fixture({ session: { crossTab: false, cleanup: true, userId: 41 }, initialStorage: removable });
    f.api.handlePollResponse({ status: 401 });
    assert.equal(f.redirects.length, 0);
    assert.equal(f.values.size, removable.length);
    f.submit();
    assert.equal(f.values.size, 0);
    assert.equal(f.sent.length, removable.length);
    const g = fixture({ initialStorage: removable });
    g.submit();
    assert.equal(g.values.size, removable.length);
});

test('cleanup tolerates blocked access, failed enumeration and individual failed removals', () => {
    const f = fixture({ session: { crossTab: true, cleanup: true, userId: 41 }, blocked: true });
    assert.doesNotThrow(() => f.submit());
    assert.doesNotThrow(() => f.receive({ type: 'signed-out' }));
    assert.equal(f.redirects.length, 1);
    assert.doesNotThrow(() => clearUserScopedStorage({ localStorage: { get length() { throw Error('blocked'); } } }));
    const removed = [];
    clearUserScopedStorage({ localStorage: { length: 2, key: (i) => removable[i], removeItem(key) { if (key === removable[0]) throw Error('blocked'); removed.push(key); } } });
    assert.deepEqual(removed, [removable[1]]);
});

test('classic export storage key stays in sync with cleanup', async () => {
    const source = await readFile(new URL('../../public/assets/js/async-export.js', import.meta.url), 'utf8');
    assert.ok(source.includes(`const pendingExportStorageKey = '${PENDING_EXPORT_STORAGE_KEY}';`));
});
