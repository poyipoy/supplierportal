import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { applyCsrfToken, bootSessionSync, SESSION_CHANNEL, SESSION_EVENT_KEY, sessionEndedByResponse, sessionExpiredByResponse, PENDING_EXPORT_STORAGE_KEY, storageKeysToClear, clearUserScopedStorage } from '../../resources/js/session-sync.js';

const ORIGIN = 'https://portal.example.test';
const PAGE_URL = `${ORIGIN}/supplier/purchase-orders/abc`;
const CONFIRMED = { isConfirmed: true, isDismissed: false };
const DECLINED = { isConfirmed: false, isDismissed: true, dismiss: 'cancel' };
// Another alert took over the dialog: dismissed, but not by the user.
const REPLACED = { isConfirmed: false, isDismissed: true };
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function fixture({ session = { crossTab: true, cleanup: false, userId: 41 }, login = null, broadcast = true, blocked = false, initialStorage = [], channelFailure = null, answers = [DECLINED], dirty = false, fetchImpl = null, jquery = null, dom = null, alertRunsPreConfirm = true, embed = false, parentWin = null, topWin = null, iframes = [] } = {}) {
    const win = new EventTarget();
    const doc = Object.assign(new EventTarget(), {
        documentElement: { dataset: embed ? { workspaceEmbed: 'true' } : {} },
        querySelector: (selector) => (dom ? dom.querySelector(selector) : login),
        querySelectorAll: (selector) => (dom ? dom.querySelectorAll(selector) : (selector === 'iframe' ? iframes : [])),
    });
    const redirects = [];
    const sent = [];
    const prompts = [];
    const submitting = [];
    const toasts = [];
    const validation = [];
    const values = new Map(initialStorage.map((key) => [key, 'saved']));
    const sessionValues = new Map(initialStorage.map((key) => [key, 'saved']));
    let disconnects = 0;
    let channel;
    Object.assign(win, {
        Event, AdasiSession: session, AdasiLoginUrl: `${ORIGIN}/login`,
        location: { href: `${PAGE_URL}#details`, origin: ORIGIN, assign: (url) => redirects.push(url) },
        ...(embed ? { self: win, parent: parentWin, top: topWin ?? parentWin } : {}),
        AdasiI18n: { t: (key) => key },
        AdasiAlert: {
            // Like SweetAlert: the confirm button runs preConfirm; a throw keeps the dialog open
            // with the message, after which this user gives up.
            confirm: async (options) => {
                prompts.push(options);
                const answer = answers[Math.min(prompts.length, answers.length) - 1];
                if (!alertRunsPreConfirm || !answer?.isConfirmed || typeof options.preConfirm !== 'function') return answer;
                try { return { ...answer, value: await options.preConfirm() }; } catch (error) { validation.push(error.message); return DECLINED; }
            },
            toast: (options) => toasts.push(options),
        },
        AdasiUnsaved: { isDirty: () => dirty, setSubmitting: (value) => submitting.push(value) },
        ...(fetchImpl ? { fetch: fetchImpl } : {}),
        ...(jquery ? { jQuery: jquery } : {}),
        Echo: { disconnect: () => { disconnects++; } },
        BroadcastChannel: broadcast ? class extends EventTarget {
            constructor(name) { super(); if (channelFailure === 'constructor') throw Error('blocked channel'); assert.equal(name, SESSION_CHANNEL); channel = this; }
            postMessage(message) { if (channelFailure === 'post') throw Error('closed channel'); sent.push(message); }
        } : undefined,
    });
    const storage = {
        get length() { return values.size; },
        key: (index) => [...values.keys()][index] ?? null,
        removeItem: (key) => { sent.push({ remove: key }); values.delete(key); },
        setItem: (key, value) => { sent.push({ key, value }); values.set(key, value); },
    };
    const sessionStorageArea = {
        get length() { return sessionValues.size; },
        key: (index) => [...sessionValues.keys()][index] ?? null,
        removeItem: (key) => { sessionValues.delete(key); },
        setItem: (key, value) => { sessionValues.set(key, value); },
    };
    Object.defineProperty(win, 'localStorage', { get() { if (blocked) throw Error('blocked'); return storage; } });
    Object.defineProperty(win, 'sessionStorage', { get() { if (blocked) throw Error('blocked'); return sessionStorageArea; } });
    const api = bootSessionSync(win, doc);
    return {
        win, doc, api, redirects, sent, values, sessionValues, storage, prompts, submitting, toasts, validation,
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
    assert.equal(f.prompts.length, 0);
    assert.equal(f.redirects.length, 0);
    f.api.handlePollResponse({ status: 401 });
    assert.equal(f.prompts.length, 1);
    assert.equal(f.redirects.length, 0, 'the user is asked first; nothing navigates until they agree');
});

test('401 and 419 both mean the page can no longer act as this user', () => {
    for (const status of [401, 419]) assert.equal(sessionExpiredByResponse({ status }), true);
    for (const status of [200, 302, 403, 422, 429, 500, 503]) assert.equal(sessionExpiredByResponse({ status }), false);
    for (const value of [undefined, null, {}]) assert.equal(sessionExpiredByResponse(value), false);
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

test('blocked storage, absent Echo and throwing Echo do not break redirect', async () => {
    const f = fixture({ broadcast: false, blocked: true });
    assert.doesNotThrow(() => f.submit());
    assert.doesNotThrow(() => f.storageEvent('{"type":"signed-out"}'));
    assert.equal(f.redirects.length, 0, 'a blocked storage area cannot deliver the message');
    const h = fixture({ broadcast: false, blocked: true, answers: [CONFIRMED] });
    h.win.Echo = undefined;
    h.api.handlePollResponse({ status: 401 });
    await settle();
    assert.equal(h.redirects.length, 1);
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

const removable = [
    PENDING_EXPORT_STORAGE_KEY,
    'adasi_supplier_reg_draft',
    'adasi_supplier_reg_draft_v2',
    'finance_invoice_verify_scroll',
    'adasi:local-import-toast',
    'adasi:export-download-claim:jobHash1',
    'adasi.po-documents./purchasing/local-pos/abc/documents',
    'quotation_draft_123',
    'quotation_draft_0',
    'quotation_draft_41_123',
    'quotation_draft_42_123',
    'quotation_draft_41_hash',
    'adasi_chat_draft_41_1',
    'adasi:workspace:recently-closed:41:import',
    'adasi:workspace:usage:guest:import',
];
const retained = [
    'quotation_draft_hash',
    'quotation_draft_123_456_extra',
    'adasi.sidebar.41',
    'supplierAuditScoreGuide',
    'adasi:workspace:usage:41:import',
    'adasi:workspace:favorites:41:import',
    'adasi:workspace:bookmarks:41:import',
    'adasi:workspace:shortcuts-layout:41:import',
    'adasi:workspace:shortcuts-meta:41:import',
    'adasi:workspace:pinned:41:import',
];

test('cleanup removes export key, legacy and account-scoped quotation drafts, and chat drafts in localStorage and sessionStorage', async () => {
    assert.deepEqual(storageKeysToClear([...removable, ...retained]), removable);
    for (const trigger of ['submit', 'receive', 'poll', 'login']) {
        const f = fixture({
            session: trigger === 'login' ? null : { crossTab: true, cleanup: true, userId: 41 },
            login: trigger === 'login' ? { dataset: { sessionCrossTab: '1', sessionCleanup: '1' } } : null,
            initialStorage: [...removable, ...retained],
            answers: [CONFIRMED],
        });
        if (trigger === 'submit') f.submit();
        if (trigger === 'receive') f.receive({ type: 'signed-out' });
        if (trigger === 'poll') {
            f.api.handlePollResponse({ status: 401 });
            assert.equal(f.values.size, removable.length + retained.length, 'nothing is cleared before the user agrees to reconnect');
            await settle();
        }
        assert.deepEqual([...f.values.keys()], retained, `${trigger} localStorage`);
        assert.deepEqual([...f.sessionValues.keys()], retained, `${trigger} sessionStorage`);
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

test('BroadcastChannel construction or send failures use transient storage fallback', () => {
    for (const channelFailure of ['constructor', 'post']) {
        const f = fixture({ channelFailure });
        assert.doesNotThrow(() => f.submit());
        assert.equal(f.sent[0].key, SESSION_EVENT_KEY);
        assert.equal(f.values.has(SESSION_EVENT_KEY), false);
    }
});

test('a BroadcastChannel-capable tab also receives storage fallback from another tab', () => {
    const f = fixture();
    f.storageEvent('{"type":"signed-out"}');
    assert.equal(f.redirects.length, 1);
});

test('an ended session asks once, then reconnects to the page the user was on', async () => {
    const f = fixture({ session: { crossTab: true, cleanup: true, userId: 41 }, answers: [CONFIRMED], initialStorage: [PENDING_EXPORT_STORAGE_KEY] });
    f.api.handlePollResponse({ status: 401 });
    f.api.handlePollResponse({ status: 401 });
    f.api.handleActionResponse({ status: 419 });
    assert.equal(f.prompts.length, 1, 'parallel failures share one dialog');
    assert.deepEqual(f.prompts[0], {
        title: 'js.session.expired_title',
        text: 'js.session.expired_text',
        type: 'warning',
        confirmText: 'js.session.reconnect',
        cancelText: 'js.session.stay',
    });
    assert.equal(f.redirects.length, 0);
    await settle();
    // An unauthenticated GET of this page is sent to login by the server and returns here after sign-in.
    assert.deepEqual(f.redirects, [PAGE_URL]);
    assert.deepEqual(f.submitting, [true]);
    assert.equal(f.disconnects(), 1);
    assert.equal(f.values.size, 0);
});

test('the dialog warns when the page holds unsaved changes', () => {
    const f = fixture({ dirty: true });
    f.api.handleActionResponse({ status: 401 });
    assert.equal(f.prompts[0].text, 'js.session.expired_text_dirty');
});

test('"not now" keeps the page, silences background polls, and asks again only after a later failed action', async (t) => {
    let now = 1_000_000;
    t.mock.method(Date, 'now', () => now);
    const f = fixture({ answers: [DECLINED, CONFIRMED] });
    f.api.handlePollResponse({ status: 401 });
    await settle();
    assert.equal(f.redirects.length, 0);
    assert.equal(f.api.isSessionExpired(), true);
    f.api.handlePollResponse({ status: 401 });
    f.api.handleActionResponse({ status: 401 }); // a late response from the same burst
    await settle();
    assert.equal(f.prompts.length, 1);
    now += 5_000;
    f.api.handlePollResponse({ status: 401 }); // background polls never reopen it
    await settle();
    assert.equal(f.prompts.length, 1);
    f.api.handleActionResponse({ status: 419 }); // the user clicked something that failed
    await settle();
    assert.equal(f.prompts.length, 2);
    assert.deepEqual(f.redirects, [PAGE_URL]);
});

test('a dialog replaced by another alert is not treated as a refusal', async () => {
    const f = fixture({ answers: [REPLACED, CONFIRMED] });
    f.api.handleActionResponse({ status: 401 });
    await settle();
    assert.equal(f.api.isSessionExpired(), false);
    f.api.handleActionResponse({ status: 401 });
    await settle();
    assert.equal(f.prompts.length, 2);
    assert.deepEqual(f.redirects, [PAGE_URL]);
});

test('a sign-out broadcast does not override a dialog the user is answering or has declined', async () => {
    const f = fixture({ answers: [DECLINED] });
    f.api.handleActionResponse({ status: 401 });
    f.receive({ type: 'signed-out' });
    await settle();
    f.receive({ type: 'signed-out' });
    assert.equal(f.redirects.length, 0);
});

test('the adasi:session-ended event asks instead of navigating', async () => {
    const f = fixture({ answers: [CONFIRMED] });
    f.win.dispatchEvent(new Event('adasi:session-ended'));
    assert.equal(f.redirects.length, 0);
    await settle();
    assert.deepEqual(f.redirects, [PAGE_URL]);
});

test('guest pages never ask', () => {
    const f = fixture({ session: null, login: { dataset: { sessionCrossTab: '1' } } });
    f.api.handleActionResponse({ status: 419 });
    assert.equal(f.prompts.length, 0);
});

test('without the alert library the native confirm is used', async () => {
    const f = fixture();
    f.win.AdasiAlert = undefined;
    const asked = [];
    f.win.confirm = (message) => { asked.push(message); return true; };
    f.api.handleActionResponse({ status: 401 });
    await settle();
    assert.equal(asked.length, 1);
    assert.deepEqual(f.redirects, [PAGE_URL]);
});

test('same-origin fetch responses that report an ended session open the dialog and pass through untouched', async () => {
    const responses = {
        ok: { status: 200, url: `${ORIGIN}/a` },
        expired: { status: 419, url: `${ORIGIN}/b` },
        foreign: { status: 401, url: 'https://cdn.example.test/c' },
    };
    const fetchImpl = async (input) => {
        if (input === 'fail') throw new Error('network');
        return responses[input];
    };
    const f = fixture({ fetchImpl });
    assert.notEqual(f.win.fetch, fetchImpl);
    assert.equal(await f.win.fetch('ok'), responses.ok);
    assert.equal(await f.win.fetch('foreign'), responses.foreign);
    await assert.rejects(f.win.fetch('fail'), /network/);
    assert.equal(f.prompts.length, 0);
    assert.equal(await f.win.fetch('expired'), responses.expired);
    await settle();
    assert.equal(f.prompts.length, 1);
});

test('observers are not installed when cross-tab handling is off or for guests', () => {
    const fetchImpl = async () => ({ status: 200, url: `${ORIGIN}/x` });
    const off = fixture({ session: { crossTab: false, cleanup: true, userId: 41 }, fetchImpl });
    assert.equal(off.win.fetch, fetchImpl);
    const guest = fixture({ session: null, login: { dataset: { sessionCrossTab: '1' } }, fetchImpl });
    assert.equal(guest.win.fetch, fetchImpl);
});

function jqueryStub() {
    const handlers = {};
    const prefilters = [];
    const dataTableExt = { errMode: 'alert' };
    const stub = Object.assign(() => ({ on: (name, handler) => { handlers[name] = handler; } }), {
        fn: { dataTable: { ext: dataTableExt } },
        ajaxPrefilter: (filter) => prefilters.push(filter),
    });
    return { stub, handlers, prefilters, dataTableExt };
}

test('jQuery ajax completions (DataTables, legacy pages) that report an ended session open the dialog', () => {
    const { stub, handlers } = jqueryStub();
    const f = fixture({ jquery: stub });
    handlers.ajaxComplete({}, { status: 200 }, {});
    handlers.ajaxComplete({}, { status: 401 }, { crossDomain: true });
    assert.equal(f.prompts.length, 0);
    handlers.ajaxComplete({}, { status: 401 }, { crossDomain: false });
    assert.equal(f.prompts.length, 1);
});

test('DataTables 3 native XHR events (xhr.dt, dt-error.dt, preXhr.dt) detect expired sessions once and swap retired CSRF tokens', async () => {
    const server = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token' } });
    const { stub, handlers } = jqueryStub();
    const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), fetchImpl: server.impl, jquery: stub });
    f.win.DataTable = { ajax: { defaults: { headers: { 'X-CSRF-TOKEN': 'old-token' } } }, ext: { errMode: 'alert' } };

    // Cross-origin DataTables response is ignored.
    handlers['xhr.dt']({}, { ajax: { url: 'https://elsewhere.example.test/table' } }, null, { status: 401 });
    assert.equal(f.prompts.length, 0);

    // Same-origin 401 on xhr.dt opens reconnect once; duplicate ajaxComplete/dt-error.dt for same XHR is deduplicated.
    const dtXhr = { status: 401 };
    handlers['xhr.dt']({}, { ajax: { url: '/purchasing/purchase-orders' }, jqXHR: dtXhr }, null, dtXhr);
    handlers.ajaxComplete({}, dtXhr, { crossDomain: false });
    handlers['dt-error.dt']({}, { ajax: { url: '/purchasing/purchase-orders' }, jqXHR: dtXhr }, 7, 'Ajax error');
    assert.equal(f.prompts.length, 1);
    await flush();

    // After restore, DataTable.ajax.defaults.headers and preXhr.dt same-origin headers/data swap retired token.
    assert.equal(f.win.DataTable.ajax.defaults.headers['X-CSRF-TOKEN'], 'new-token');
    const sameOriginOpts = { url: '/purchasing/purchase-orders', headers: { 'X-CSRF-TOKEN': 'old-token' }, data: { _token: 'old-token', draw: 2 } };
    handlers['preXhr.dt']({}, {}, sameOriginOpts.data, sameOriginOpts);
    assert.equal(sameOriginOpts.headers['X-CSRF-TOKEN'], 'new-token');
    assert.equal(sameOriginOpts.data._token, 'new-token');

    const crossOriginOpts = { url: 'https://elsewhere.example.test/table', headers: { 'X-CSRF-TOKEN': 'old-token' }, data: { _token: 'old-token' } };
    handlers['preXhr.dt']({}, {}, crossOriginOpts.data, crossOriginOpts);
    assert.equal(crossOriginOpts.headers['X-CSRF-TOKEN'], 'old-token');
    assert.equal(crossOriginOpts.data._token, 'old-token');
});

test('DataTables raises no alert of its own for an ended session but still reports other errors', () => {
    const { stub, dataTableExt } = jqueryStub();
    const f = fixture({ jquery: stub });
    const alerts = [];
    f.win.alert = (message) => alerts.push(message);
    assert.equal(typeof dataTableExt.errMode, 'function');
    dataTableExt.errMode({ jqXHR: { status: 401 } }, 7, 'DataTables warning: Ajax error');
    dataTableExt.errMode({ jqXHR: { status: 419 } }, 7, 'DataTables warning: Ajax error');
    assert.deepEqual(alerts, []);
    dataTableExt.errMode({ jqXHR: { status: 500 } }, 7, 'DataTables warning: boom');
    dataTableExt.errMode(undefined, 1, 'DataTables warning: no settings');
    assert.deepEqual(alerts, ['DataTables warning: boom', 'DataTables warning: no settings']);

    const own = jqueryStub();
    own.dataTableExt.errMode = 'none';
    fixture({ jquery: own.stub });
    assert.equal(own.dataTableExt.errMode, 'none', 'a page that handles table errors itself is left alone');
});

test('badge polling stops while the reconnect dialog is open or declined', async () => {
    const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    assert.match(layout, /function updateBadges\(\) \{[^}]*AdasiSessionSync\?\.isSessionExpired\?\.\(\)/s);
});

const STATUS_URL = `${ORIGIN}/session/status`;
const RESUMABLE = { crossTab: true, cleanup: true, userId: 41, statusUrl: STATUS_URL, copy: { restored: 'restored copy', failed: 'failed copy', unauthenticated: 'unauthenticated copy' } };
const flush = async () => { for (let i = 0; i < 6; i++) await settle(); };

function tokenDom(token = 'old-token') {
    const meta = { attrs: { content: token }, getAttribute(name) { return this.attrs[name]; }, setAttribute(name, value) { this.attrs[name] = value; } };
    const inputs = [{ value: token }, { value: token }];
    return {
        meta,
        inputs,
        querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? meta : null),
        querySelectorAll: (selector) => (selector === 'input[name="_token"]' ? inputs : []),
    };
}

function statusServer(...replies) {
    const calls = [];
    let checks = 0;
    const impl = (input, init) => {
        calls.push({ url: String(input), init });
        if (!String(input).startsWith(STATUS_URL)) return Promise.resolve({ status: 200, ok: true, url: new URL(String(input), ORIGIN).toString() });
        checks++;
        const reply = replies[Math.min(checks, replies.length) - 1];
        if (reply instanceof Error) return Promise.reject(reply);
        return Promise.resolve({ status: reply.status, ok: reply.status >= 200 && reply.status < 300, url: STATUS_URL, redirected: false, json: async () => reply.body });
    };
    return { impl, calls };
}

test('reconnect restores a live session in place without navigating or asking for a password', async () => {
    const dom = tokenDom();
    const server = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token' } });
    const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom, fetchImpl: server.impl, initialStorage: [PENDING_EXPORT_STORAGE_KEY] });
    f.api.handleActionResponse({ status: 419 });
    assert.equal(f.api.isSessionExpired(), true, 'polling pauses while the check runs');
    await flush();

    assert.deepEqual(f.redirects, [], 'the page and its unsaved input stay');
    assert.equal(typeof f.prompts[0].preConfirm, 'function', 'the check runs behind the confirm button');
    const [check] = server.calls;
    const url = new URL(check.url);
    assert.equal(url.origin + url.pathname, STATUS_URL);
    assert.equal(url.searchParams.get('return'), PAGE_URL, 'the page without its fragment');
    assert.equal(check.init.credentials, 'same-origin');
    assert.equal(check.init.cache, 'no-store');
    assert.equal(check.init.headers.Accept, 'application/json');
    assert.equal(dom.meta.getAttribute('content'), 'new-token');
    assert.deepEqual(dom.inputs.map((input) => input.value), ['new-token', 'new-token']);
    assert.equal(f.api.isSessionExpired(), false, 'badge polling resumes');
    assert.deepEqual(f.toasts, [{ type: 'success', text: 'restored copy' }]);
    assert.equal(f.values.size, 1, 'nothing user-scoped is cleared when the session survives');
    assert.deepEqual(f.submitting, []);
    assert.equal(f.disconnects(), 0);
});

test('requests still carrying the old token from inline scripts are sent with the restored one', async () => {
    const server = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token' } });
    const { stub, prefilters } = jqueryStub();
    const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), fetchImpl: server.impl, jquery: stub });
    f.win.Echo.connector = { options: { auth: { headers: { 'X-CSRF-TOKEN': 'old-token' } } } };
    f.api.handleActionResponse({ status: 419 });
    await flush();

    const headers = { 'x-csrf-token': 'old-token', Accept: 'application/json' };
    const body = new URLSearchParams({ _token: 'old-token', note: 'kept' });
    await f.win.fetch('/conversations/abc/messages', { method: 'POST', headers, body });
    assert.equal(headers['x-csrf-token'], 'new-token');
    assert.equal(body.get('_token'), 'new-token');
    assert.equal(body.get('note'), 'kept');
    const foreign = { 'X-CSRF-TOKEN': 'old-token' };
    await f.win.fetch('https://elsewhere.example.test/hook', { method: 'POST', headers: foreign });
    assert.equal(foreign['X-CSRF-TOKEN'], 'old-token', 'a token is never rewritten for another origin');
    const unrelated = { 'X-CSRF-TOKEN': 'some-other-value' };
    await f.win.fetch('/x', { method: 'POST', headers: unrelated });
    assert.equal(unrelated['X-CSRF-TOKEN'], 'some-other-value', 'only tokens this page held are swapped');
    assert.equal(f.win.Echo.connector.options.auth.headers['X-CSRF-TOKEN'], 'new-token');

    const options = { crossDomain: false, headers: { 'X-CSRF-TOKEN': 'old-token' }, data: 'a=1&_token=old-token&b=2' };
    prefilters[0](options);
    assert.deepEqual(options, { crossDomain: false, headers: { 'X-CSRF-TOKEN': 'new-token' }, data: 'a=1&_token=new-token&b=2' });
    const cross = { crossDomain: true, headers: { 'X-CSRF-TOKEN': 'old-token' }, data: '_token=old-token' };
    prefilters[0](cross);
    assert.deepEqual(cross, { crossDomain: true, headers: { 'X-CSRF-TOKEN': 'old-token' }, data: '_token=old-token' });
});

test('a session that cannot be restored navigates to the current page for login or to a same-origin code challenge', async () => {
    const cases = [
        [{ status: 401, body: { authenticated: false } }, PAGE_URL],
        [{ status: 401, body: { authenticated: false, two_factor_url: `${ORIGIN}/two-factor-challenge` } }, `${ORIGIN}/two-factor-challenge`],
        [{ status: 401, body: { authenticated: false, two_factor_url: 'https://evil.example.test/two-factor-challenge' } }, PAGE_URL],
        [{ status: 403, body: { message: 'change password', redirect: `${ORIGIN}/profile/security` } }, PAGE_URL],
        [{ status: 200, body: { authenticated: true, user_id: 99, csrf_token: 'other-account' } }, PAGE_URL],
    ];
    for (const [reply, destination] of cases) {
        const dom = tokenDom();
        const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom, fetchImpl: statusServer(reply).impl });
        f.api.handleActionResponse({ status: 401 });
        await flush();
        assert.deepEqual(f.redirects, [destination], JSON.stringify(reply));
        assert.equal(dom.meta.getAttribute('content'), 'old-token', 'no token from another account or a failed check is applied');
        assert.deepEqual(f.toasts, [], JSON.stringify(reply));
        assert.equal(f.disconnects(), 1);
    }
});

test('an alert that does not run the check (native fallback) still restores, redirects when unauthenticated, or reports a failure as a toast', async () => {
    const ok = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token' } });
    const restored = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), alertRunsPreConfirm: false, fetchImpl: ok.impl });
    restored.api.handleActionResponse({ status: 419 });
    await flush();
    assert.deepEqual(restored.redirects, []);
    assert.deepEqual(restored.toasts, [{ type: 'success', text: 'restored copy' }]);

    const unauth = statusServer({ status: 401, body: { authenticated: false } });
    const redirected = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), alertRunsPreConfirm: false, fetchImpl: unauth.impl });
    redirected.api.handleActionResponse({ status: 419 });
    await flush();
    assert.deepEqual(redirected.redirects, [PAGE_URL]);
    assert.deepEqual(redirected.toasts, []);

    const offline = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), alertRunsPreConfirm: false, fetchImpl: statusServer(new TypeError('offline')).impl });
    offline.api.handleActionResponse({ status: 419 });
    await flush();
    assert.deepEqual(offline.redirects, []);
    assert.deepEqual(offline.toasts, [{ type: 'error', text: 'failed copy' }]);
    assert.equal(offline.api.isSessionExpired(), false, 'not a refusal: the next failed action asks again');
});

test('applyCsrfToken remembers every replaced value and tolerates a page without token holders', () => {
    const retired = new Set();
    const dom = tokenDom('a');
    dom.inputs.push({ value: 'b' }, { value: '' });
    applyCsrfToken(dom, 'c', retired);
    assert.deepEqual([...retired].sort(), ['a', 'b']);
    assert.deepEqual(dom.inputs.map((input) => input.value), ['c', 'c', 'c', 'c']);
    assert.doesNotThrow(() => applyCsrfToken({ querySelector: () => null }, 'c', new Set()));
});

test('embedded shell iframe does not hijack top navigation on signed-out broadcasts when top manages session sync', () => {
    const topRedirects = [];
    const topWin = {
        AdasiSession: { crossTab: true, cleanup: true, userId: 41 },
        AdasiSessionSync: { requestReconnect: () => {} },
        location: { origin: ORIGIN, assign: (url) => topRedirects.push(url) },
    };
    const child = fixture({ embed: true, parentWin: topWin, topWin });
    child.receive({ type: 'signed-out' });
    child.storageEvent('{"type":"signed-out"}');
    assert.deepEqual(topRedirects, []);
    assert.deepEqual(child.redirects, []);
    assert.equal(child.disconnects(), 0);
});

test('top shell cross-tab sign-out redirects top once and disarms dirty prompts inside embedded iframes', () => {
    const iframeStub = { contentWindow: null };
    const topShell = fixture({ iframes: [iframeStub] });
    const child = fixture({ embed: true, parentWin: topShell.win, topWin: topShell.win });
    iframeStub.contentWindow = child.win;
    child.win.addEventListener('beforeunload', (event) => {
        event.preventDefault();
        event.returnValue = 'dirty';
    });

    topShell.receive({ type: 'signed-out' });

    assert.deepEqual(topShell.redirects, [`${ORIGIN}/login`]);
    assert.equal(topShell.disconnects(), 1);
    const unloadEvent = new Event('beforeunload', { cancelable: true });
    child.win.dispatchEvent(unloadEvent);
    assert.equal(unloadEvent.defaultPrevented, false);
    assert.deepEqual(child.submitting, [true]);
});

test('direct logout form submission disarms dirty prompts inside embedded iframes', () => {
    const iframeStub = { contentWindow: null };
    const topShell = fixture({ iframes: [iframeStub] });
    const child = fixture({ embed: true, parentWin: topShell.win, topWin: topShell.win });
    iframeStub.contentWindow = child.win;
    child.win.addEventListener('beforeunload', (event) => {
        event.preventDefault();
        event.returnValue = 'dirty';
    });

    topShell.submit();

    assert.deepEqual(topShell.sent, [{ type: 'signed-out' }]);
    assert.deepEqual(topShell.redirects, []);
    const unloadEvent = new Event('beforeunload', { cancelable: true });
    child.win.dispatchEvent(unloadEvent);
    assert.equal(unloadEvent.defaultPrevented, false);
    assert.deepEqual(child.submitting, [true]);
});

test('embedded frame without top session sync still forwards cross-tab sign-out to top and falls back if top blocks navigation', () => {
    const topRedirects = [];
    const topWin = {
        location: { origin: ORIGIN, assign: (url) => topRedirects.push(url) },
    };
    const child = fixture({ embed: true, parentWin: topWin, topWin });
    child.receive({ type: 'signed-out' });
    assert.deepEqual(topRedirects, [`${ORIGIN}/login`]);
    assert.equal(child.disconnects(), 1);

    const restrictedTop = {
        location: {
            origin: ORIGIN,
            assign() { throw Error('sandbox blocked top navigation'); },
        },
    };
    const fallbackChild = fixture({ embed: true, parentWin: restrictedTop, topWin: restrictedTop });
    fallbackChild.receive({ type: 'signed-out' });
    assert.deepEqual(fallbackChild.redirects, [`${ORIGIN}/login`]);
});

test('applyExternalToken updates CSRF tokens, Echo headers, retires stale tokens, and clears expired state', async () => {
    const dom = tokenDom('stale-token');
    const fetchImpl = async () => ({ status: 200, url: `${ORIGIN}/ok` });
    const child = fixture({ session: RESUMABLE, answers: [DECLINED], dom, fetchImpl });
    child.win.Echo.connector = { options: { auth: { headers: { 'X-CSRF-TOKEN': 'stale-token' } } } };

    child.api.handleActionResponse({ status: 419 });
    await flush();
    assert.equal(child.api.isSessionExpired(), true);

    child.api.applyExternalToken('');
    assert.equal(dom.meta.getAttribute('content'), 'stale-token');

    child.api.applyExternalToken('fresh-token');
    assert.equal(child.api.isSessionExpired(), false);
    assert.equal(dom.meta.getAttribute('content'), 'fresh-token');
    assert.deepEqual(dom.inputs.map((input) => input.value), ['fresh-token', 'fresh-token']);
    assert.equal(child.win.Echo.connector.options.auth.headers['X-CSRF-TOKEN'], 'fresh-token');

    const headers = { 'X-CSRF-TOKEN': 'stale-token' };
    await child.win.fetch('/action', { method: 'POST', headers });
    assert.equal(headers['X-CSRF-TOKEN'], 'fresh-token');
});

test('an unknown answer keeps the page and the dialog message so the user can retry', async () => {
    for (const reply of [new TypeError('offline'), { status: 503, body: null }, { status: 429, body: { message: 'Too Many Attempts.' } }]) {
        const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), fetchImpl: statusServer(reply).impl });
        f.api.handleActionResponse({ status: 419 });
        await flush();
        assert.deepEqual(f.redirects, []);
        assert.deepEqual(f.validation, ['failed copy'], 'shown inside the dialog');
    }
});

test('proactive deadline timer verifies passively, opens native ERP modal with reason, and broadcasts expiry to sibling tabs', async (t) => {
    let now = 10_000_000;
    t.mock.method(Date, 'now', () => now);
    const scheduled = [];
    const modalOpens = [];
    const server = statusServer({ status: 401, body: { authenticated: false, reason: 'idle_timeout' } });

    const session = {
        ...RESUMABLE,
        heartbeatUrl: `${ORIGIN}/session/heartbeat`,
        timing: {
            idle_timeout_seconds: 60,
            idle_remaining_seconds: 60,
            absolute_timeout_seconds: 3600,
            absolute_remaining_seconds: 3600,
            heartbeat_interval_seconds: 30,
        },
    };
    const f = fixture({ session, fetchImpl: server.impl, dom: tokenDom() });
    f.api.destroy();

    // Re-boot with controlled setTimeout and native AdasiSessionModal stub.
    const win = new EventTarget();
    const doc = Object.assign(new EventTarget(), {
        documentElement: { dataset: {} },
        visibilityState: 'visible',
        querySelector: () => null,
        querySelectorAll: () => [],
    });
    const sent = [];
    Object.assign(win, {
        Event,
        AdasiSession: session,
        AdasiLoginUrl: `${ORIGIN}/login`,
        location: { href: PAGE_URL, origin: ORIGIN, assign: () => {} },
        AdasiI18n: { t: (key) => key },
        fetch: server.impl,
        setTimeout: (fn, ms) => { const handle = { fn, ms, cleared: false }; scheduled.push(handle); return handle; },
        clearTimeout: (handle) => { if (handle) handle.cleared = true; },
        AdasiSessionModal: {
            open: (options) => {
                modalOpens.push(options);
                return new Promise(() => {});
            },
        },
        BroadcastChannel: class extends EventTarget {
            postMessage(msg) { sent.push(msg); }
        },
    });

    const api = bootSessionSync(win, doc);
    assert.equal(scheduled.length, 1);
    assert.equal(scheduled[0].ms, 61_500);

    now += 61_500;
    scheduled[0].fn();
    await flush();

    assert.equal(server.calls.length, 1);
    assert.match(server.calls[0].url, /\/session\/status\?passive=1$/);
    assert.equal(server.calls[0].init.headers['X-Adasi-Session-Check'], 'passive');
    assert.equal(modalOpens.length, 1);
    assert.equal(modalOpens[0].reason, 'idle_timeout');
    assert.equal(modalOpens[0].isDirty, false);
    assert.deepEqual(modalOpens[0].dirtyTabs, []);
    assert.deepEqual(sent, [{ type: 'session-expired', userId: 41, reason: 'idle_timeout' }]);
    api.destroy();
});

test('real activity in embedded iframe and top shell sends throttled heartbeat and slides idle timer', async (t) => {
    let now = 20_000_000;
    t.mock.method(Date, 'now', () => now);
    const requests = [];
    const fetchImpl = async (input, init) => {
        requests.push({ url: String(input), init });
        return {
            status: 200,
            ok: true,
            url: String(input),
            redirected: false,
            json: async () => ({
                ok: true,
                idle_timeout_seconds: 120,
                idle_remaining_seconds: 120,
                absolute_timeout_seconds: 3600,
                absolute_remaining_seconds: 3500,
                heartbeat_interval_seconds: 30,
            }),
        };
    };

    const session = {
        ...RESUMABLE,
        heartbeatUrl: `${ORIGIN}/session/heartbeat`,
        timing: {
            idle_timeout_seconds: 120,
            idle_remaining_seconds: 120,
            absolute_timeout_seconds: 3600,
            absolute_remaining_seconds: 3600,
            heartbeat_interval_seconds: 30,
        },
    };
    const top = fixture({ session, dom: tokenDom('live-csrf'), fetchImpl });
    const child = fixture({ session, embed: true, parentWin: top.win, topWin: top.win });

    // Early activity (< 30s) broadcasts activity without hitting heartbeat yet.
    now += 10_000;
    child.doc.dispatchEvent(new Event('keydown'));
    await flush();
    assert.equal(requests.length, 0);
    assert.equal(top.sent.some((m) => m.type === 'activity' && m.userId === 41), true);

    // After heartbeat interval (>= 30s), real activity triggers POST /session/heartbeat with CSRF token.
    now += 25_000;
    child.doc.dispatchEvent(new Event('pointerdown'));
    await flush();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, `${ORIGIN}/session/heartbeat`);
    assert.equal(requests[0].init.method, 'POST');
    assert.equal(requests[0].init.headers['X-CSRF-TOKEN'], 'live-csrf');

    top.api.destroy();
    child.api.destroy();
});

test('visibilitychange after passing deadline immediately checks server expiry without waiting for polling', async (t) => {
    let now = 30_000_000;
    t.mock.method(Date, 'now', () => now);
    const server = statusServer({ status: 401, body: { authenticated: false, reason: 'idle_timeout' } });
    const session = {
        ...RESUMABLE,
        heartbeatUrl: `${ORIGIN}/session/heartbeat`,
        timing: {
            idle_timeout_seconds: 60,
            idle_remaining_seconds: 60,
            absolute_timeout_seconds: 3600,
            absolute_remaining_seconds: 3600,
            heartbeat_interval_seconds: 30,
        },
    };
    const f = fixture({ session, dom: tokenDom(), fetchImpl: server.impl, answers: [DECLINED] });
    f.doc.visibilityState = 'hidden';
    f.doc.dispatchEvent(new Event('visibilitychange'));

    // Simulate laptop sleep past idle timeout.
    now += 120_000;
    f.doc.visibilityState = 'visible';
    f.doc.dispatchEvent(new Event('visibilitychange'));
    await flush();

    assert.equal(server.calls.length, 1);
    assert.match(server.calls[0].url, /\/session\/status\?passive=1$/);
    assert.equal(f.prompts.length, 1);
    f.api.destroy();
});

test('dirty workspace shell iframe tabs are forwarded to native modal and force-reconnect bypasses refusal cooldown', async (t) => {
    let now = 40_000_000;
    t.mock.method(Date, 'now', () => now);
    const modalCalls = [];
    const server = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'restored-csrf' } });
    const dom = tokenDom('stale-csrf');
    const iframeStub = { contentWindow: null };
    const f = fixture({ session: RESUMABLE, dom, fetchImpl: server.impl, iframes: [iframeStub] });
    const child = fixture({ session: RESUMABLE, dom: tokenDom('stale-csrf'), embed: true, parentWin: f.win, topWin: f.win });
    iframeStub.contentWindow = child.win;

    let closeCalls = 0;
    f.win.AdasiWorkspaceShell = {
        getDirtyFrames: () => [{ frameId: 'f-2', title: 'Create Purchase Requisition', url: `${ORIGIN}/purchasing/pr/create` }],
    };
    f.win.AdasiSessionModal = {
        open: async (options) => {
            modalCalls.push(options);
            if (modalCalls.length === 1) {
                return { isConfirmed: false, isDismissed: true, dismiss: 'cancel' };
            }
            const outcome = await options.onReconnect();
            return { isConfirmed: true, isDismissed: false, value: outcome };
        },
        close: () => { closeCalls += 1; },
    };

    f.api.handlePollResponse({ status: 401 });
    await flush();
    assert.equal(modalCalls.length, 1);
    assert.equal(modalCalls[0].isDirty, true);
    assert.deepEqual(modalCalls[0].dirtyTabs, [{ frameId: 'f-2', title: 'Create Purchase Requisition' }]);
    assert.equal(f.api.isSessionExpired(), true);

    // Clicking "Sign In Again" on the reminder bar passes force: true and restores CSRF in top shell + child iframe.
    await f.api.requestReconnect(false, { force: true, autoConfirm: true });
    await flush();
    assert.equal(modalCalls.length, 2);
    assert.equal(dom.meta.getAttribute('content'), 'restored-csrf');
    assert.equal(f.api.isSessionExpired(), false);
    assert.ok(closeCalls >= 1);
});

test('login page rendered inside a same-origin iframe does not clear storage or broadcast signed-out', () => {
    const parentWin = { location: { origin: ORIGIN } };
    const f = fixture({
        session: null,
        login: { dataset: { sessionCrossTab: '1', sessionCleanup: '1' } },
        initialStorage: [PENDING_EXPORT_STORAGE_KEY],
        embed: true,
        parentWin,
        topWin: parentWin,
    });
    assert.deepEqual(f.sent, []);
    assert.equal(f.values.has(PENDING_EXPORT_STORAGE_KEY), true);
});

test('adasiSessionModal enforces hard-lock on clean pages and adaptive reminder bar on dirty pages', async () => {
    const { adasiSessionModal } = await import('../../resources/js/session-expired-modal.js');
    const prevDocument = globalThis.document;
    const prevWindow = globalThis.window;
    const bodyClasses = new Set();
    const focused = [];
    const primaryBtn = { focus: () => focused.push('primary'), hidden: false };
    const stayBtn = { focus: () => focused.push('stay'), hidden: false };
    const reminderBtn = { focus: () => focused.push('reminder'), hidden: false };
    const panel = {
        focus: () => focused.push('panel'),
        contains: (node) => node === primaryBtn || node === stayBtn,
        querySelectorAll: () => [stayBtn, primaryBtn],
    };

    globalThis.document = {
        activeElement: primaryBtn,
        body: { classList: { toggle: (name, on) => (on ? bodyClasses.add(name) : bodyClasses.delete(name)) } },
        contains: () => false,
    };
    globalThis.window = { AdasiSessionSync: { requestReconnect: () => {} } };

    try {
        const modal = adasiSessionModal({
            reasonIdle: 'Idle timeout',
            reasonAbsolute: 'Absolute timeout',
            descriptionClean: 'Clean desc',
            descriptionDirty: 'Dirty desc',
            failed: 'Network error',
        });
        modal.$refs = { panel, reconnectButton: primaryBtn, reminderAction: reminderBtn };
        modal.$watch = () => {};
        modal.$nextTick = (cb) => cb();
        modal.init();

        // Clean page: Escape is blocked, dialog remains open.
        const cleanPromise = modal.openModal({ reason: 'idle_timeout', isDirty: false, dirtyTabs: [] });
        assert.equal(modal.open, true);
        assert.equal(modal.reasonText, 'Idle timeout');
        assert.equal(modal.descriptionText, 'Clean desc');
        let escapedPrevented = false;
        modal.handleEscape({ preventDefault: () => { escapedPrevented = true; }, stopPropagation: () => {} });
        assert.equal(escapedPrevented, true);
        assert.equal(modal.open, true, 'clean modal stays hard-locked on Escape');
        modal.dismissToReminderBar();
        assert.equal(modal.open, true, 'dismiss is a no-op when clean');

        // Dirty page: failed reconnect keeps modal open with inline error, Escape dismisses to reminder bar.
        const dirtyPromise = modal.openModal({
            reason: 'absolute_timeout',
            isDirty: true,
            dirtyTabs: [{ frameId: 'f-1', title: 'Invoice Form' }],
            onReconnect: async () => { throw new Error('Portal unreachable'); },
        });
        await cleanPromise;
        assert.equal(modal.reasonText, 'Absolute timeout');
        assert.equal(modal.descriptionText, 'Dirty desc');
        await modal.triggerReconnect();
        assert.equal(modal.open, true);
        assert.equal(modal.busy, false);
        assert.equal(modal.errorMessage, 'Portal unreachable');

        modal.handleEscape({ preventDefault: () => {}, stopPropagation: () => {} });
        const dirtyResult = await dirtyPromise;
        assert.equal(dirtyResult.isDismissed, true);
        assert.equal(modal.open, false);
        assert.equal(modal.showReminderBar, true);

        // Secondary "Sign In Again" button or non-reconnectable reason triggers login redirect.
        const loginPromise = modal.openModal({
            reason: 'absolute_timeout',
            reconnectable: false,
            isDirty: false,
            onLoginRedirect: () => `${PAGE_URL}?reauth=1`,
        });
        assert.equal(modal.reconnectable, false);
        modal.triggerPrimaryAction();
        const loginResult = await loginPromise;
        assert.equal(loginResult.isConfirmed, true);
        assert.deepEqual(loginResult.value, { url: `${PAGE_URL}?reauth=1` });
    } finally {
        globalThis.document = prevDocument;
        globalThis.window = prevWindow;
    }
});

test('1-click server-side reconnect posts to reconnectUrl with X-Adasi-Reconnect header and restores in place without page reload', async () => {
    const RECONNECT_URL = `${ORIGIN}/session/reconnect`;
    const calls = [];
    const fetchImpl = async (input, init) => {
        calls.push({ url: String(input), init });
        return {
            status: 200,
            ok: true,
            url: RECONNECT_URL,
            redirected: false,
            json: async () => ({
                ok: true,
                authenticated: true,
                user_id: 41,
                csrf_token: 'reconnected-server-csrf',
                idle_timeout_seconds: 3600,
                idle_remaining_seconds: 3600,
                absolute_timeout_seconds: 28800,
                absolute_remaining_seconds: 25000,
                heartbeat_interval_seconds: 120,
            }),
        };
    };

    const dom = tokenDom('expired-csrf');
    const session = { ...RESUMABLE, reconnectUrl: RECONNECT_URL };
    const f = fixture({ session, answers: [CONFIRMED], dom, fetchImpl });

    f.api.handlePollResponse({ status: 401 });
    await flush();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, RECONNECT_URL);
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.headers['X-Adasi-Reconnect'], '1');
    assert.equal(calls[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.deepEqual(f.redirects, [], 'reconnects in place with zero page reload');
    assert.equal(dom.meta.getAttribute('content'), 'reconnected-server-csrf');
    assert.equal(f.api.isSessionExpired(), false);
    assert.deepEqual(f.toasts, [{ type: 'success', text: 'restored copy' }]);
});

test('clicking secondary Sign In Again button in native modal navigates to reauth URL to open login page', async () => {
    const dom = tokenDom('expired-csrf');
    const f = fixture({ session: RESUMABLE, dom });
    f.win.AdasiSessionModal = {
        open: async (options) => ({
            isConfirmed: true,
            isDismissed: false,
            value: { url: options.onLoginRedirect() },
        }),
    };

    f.api.handlePollResponse({ status: 401 });
    await flush();

    assert.deepEqual(f.redirects, [`${PAGE_URL}?reauth=1`]);
    assert.equal(f.disconnects(), 1);
});

test('in-flight 401 fetch responses started before reconnect finishes are ignored and do not reopen the modal', async () => {
    const RECONNECT_URL = `${ORIGIN}/session/reconnect`;
    let resolveLatePoll;
    const latePollPromise = new Promise((resolve) => {
        resolveLatePoll = resolve;
    });

    const fetchImpl = (input) => {
        const url = String(input);
        if (url === `${ORIGIN}/late-action`) {
            return latePollPromise;
        }
        if (url === RECONNECT_URL) {
            return Promise.resolve({
                status: 200,
                ok: true,
                url: RECONNECT_URL,
                redirected: false,
                json: async () => ({
                    ok: true,
                    authenticated: true,
                    user_id: 41,
                    csrf_token: 'epoch-2-csrf',
                }),
            });
        }
        return Promise.resolve({ status: 200, ok: true, url });
    };

    const dom = tokenDom('epoch-1-csrf');
    const session = { ...RESUMABLE, reconnectUrl: RECONNECT_URL };
    const f = fixture({ session, answers: [CONFIRMED], dom, fetchImpl });

    // Start an in-flight request BEFORE the session is reconnected.
    const inFlight = f.win.fetch(`${ORIGIN}/late-action`);

    // Modal opens and user clicks Reconnect -> sessionEpoch advances.
    f.api.handlePollResponse({ status: 401 });
    await flush();
    assert.equal(f.prompts.length, 1);
    assert.equal(f.api.isSessionExpired(), false);
    assert.equal(dom.meta.getAttribute('content'), 'epoch-2-csrf');

    // Now the stale in-flight request resolves with 401 -> must NOT reopen the modal.
    resolveLatePoll({ status: 401, ok: false, url: `${ORIGIN}/late-action` });
    await inFlight;
    await flush();
    assert.equal(f.prompts.length, 1, 'stale pre-reconnect 401 must not reopen modal');
    assert.equal(f.api.isSessionExpired(), false);
});

test('probeSessionStatus deduplicates concurrent probes and classifies authenticated, expired, and offline outcomes', async () => {
    let calls = 0;
    let mode = 'auth';
    let releaseFirst = null;

    const fetchImpl = () => {
        calls += 1;
        if (mode === 'auth') {
            return new Promise((resolve) => {
                releaseFirst = () => resolve({
                    ok: true,
                    status: 200,
                    redirected: false,
                    json: async () => ({ authenticated: true, user_id: 41 }),
                });
            });
        }
        if (mode === 'expired') {
            return Promise.resolve({
                ok: false,
                status: 401,
                redirected: false,
                json: async () => ({ authenticated: false, reason: 'concurrent_login', reconnectable: false }),
            });
        }
        return Promise.reject(new TypeError('Failed to fetch'));
    };

    const f = fixture({ session: RESUMABLE, fetchImpl });

    // Concurrent calls share a single in-flight fetch.
    const p1 = f.api.probeSessionStatus();
    const p2 = f.api.probeSessionStatus();
    assert.equal(calls, 1);
    releaseFirst();
    const [r1, r2] = await Promise.all([p1, p2]);
    assert.deepEqual(r1, { authenticated: true, status: 200 });
    assert.deepEqual(r2, { authenticated: true, status: 200 });

    // 401 response propagates server expiry reason and reconnectable flag.
    mode = 'expired';
    const rExpired = await f.api.probeSessionStatus();
    assert.deepEqual(rExpired, {
        authenticated: false,
        reason: 'concurrent_login',
        reconnectable: false,
        status: 401,
    });

    // Offline network rejection reports networkError without misclassifying as expired session.
    mode = 'offline';
    const rOffline = await f.api.probeSessionStatus();
    assert.deepEqual(rOffline, { authenticated: null, networkError: true, status: 0 });
});

test('DataTables 3 custom ajax.error still triggers session-expired modal on 401/419 even before any CSRF rotation', () => {
    const { stub, handlers } = jqueryStub();
    const f = fixture({ jquery: stub });
    const customCalls = [];
    const customErrorFn = (xhr, textStatus, errThrown) => {
        customCalls.push({ status: xhr?.status, textStatus, errThrown });
    };

    // Initial load: tokens.retired.size === 0, table configures ajax: { url, error: customErrorFn }.
    const ajaxOpts401 = {
        url: `${ORIGIN}/finance/local-procurement/1/goods-receipts/data`,
        error: customErrorFn,
    };
    const settings = { ajax: { url: ajaxOpts401.url, error: customErrorFn } };
    handlers['preXhr.dt']({}, settings, {}, ajaxOpts401);

    const xhr401 = { status: 401, getResponseHeader: () => null };
    settings.jqXHR = xhr401;
    // DT 3 skips xhr.dt/dt-error.dt when ajax.error is overridden; invoke error + complete directly.
    ajaxOpts401.error(xhr401, 'error', 'Unauthorized');
    ajaxOpts401.complete?.(xhr401, 'error');

    assert.equal(f.prompts.length, 1, '401 via custom ajax.error must still open session reconnect prompt');
    assert.equal(customCalls.length, 0, 'custom generic error toast must be suppressed on 401 session expiry');

    // Non-session HTTP 500 on same-origin custom ajax.error must still run customErrorFn without prompting.
    const ajaxOpts500 = { url: ajaxOpts401.url, error: customErrorFn };
    handlers['preXhr.dt']({}, settings, {}, ajaxOpts500);
    const xhr500 = { status: 500, getResponseHeader: () => null };
    settings.jqXHR = xhr500;
    ajaxOpts500.error(xhr500, 'error', 'Internal Server Error');
    ajaxOpts500.complete?.(xhr500, 'error');

    assert.equal(f.prompts.length, 1);
    assert.deepEqual(customCalls, [{ status: 500, textStatus: 'error', errThrown: 'Internal Server Error' }]);

    // Cross-origin 401 must be ignored by session-sync and invoke customErrorFn normally.
    const ajaxOptsCross = { url: 'https://elsewhere.example.test/data', error: customErrorFn };
    const crossSettings = { ajax: { url: ajaxOptsCross.url, error: customErrorFn } };
    handlers['preXhr.dt']({}, crossSettings, {}, ajaxOptsCross);
    const xhrCross401 = { status: 401, getResponseHeader: () => null };
    crossSettings.jqXHR = xhrCross401;
    ajaxOptsCross.error(xhrCross401, 'error', 'Unauthorized');
    ajaxOptsCross.complete?.(xhrCross401, 'error');

    assert.equal(f.prompts.length, 1);
    assert.equal(customCalls.length, 2);
    assert.equal(customCalls[1].status, 401);
});

test('DataTables 3 events work without window.jQuery: detects 401/419 via xhr.dt, dt-error.dt, and custom ajax.error, swaps CSRF tokens, and guards ext.errMode', async () => {
    const server = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token-no-jq' } });
    const dtState = { ajax: { defaults: { headers: { 'X-CSRF-TOKEN': 'old-token' } } }, ext: { errMode: 'alert' } };
    const alerts = [];
    const prompts = [];
    const doc = Object.assign(new EventTarget(), {
        documentElement: { dataset: {} },
        querySelector: (selector) => tokenDom().querySelector(selector),
        querySelectorAll: () => [],
    });
    const win = Object.assign(new EventTarget(), {
        Event,
        AdasiSession: RESUMABLE,
        AdasiLoginUrl: `${ORIGIN}/login`,
        location: { href: `${PAGE_URL}#details`, origin: ORIGIN, assign: () => {} },
        AdasiI18n: { t: (key) => key },
        AdasiAlert: {
            confirm: async (options) => {
                prompts.push(options);
                return { ...CONFIRMED, value: await options.preConfirm() };
            },
            toast: () => {},
        },
        AdasiUnsaved: { isDirty: () => false, setSubmitting: () => {} },
        fetch: server.impl,
        Echo: { disconnect: () => {} },
        DataTable: dtState,
        alert: (message) => alerts.push(message),
    });

    const api = bootSessionSync(win, doc);
    assert.ok(api);
    assert.equal(typeof dtState.ext.errMode, 'function');

    dtState.ext.errMode({ jqXHR: { status: 401 } }, 7, 'Ajax error');
    dtState.ext.errMode({ jqXHR: { status: 500 } }, 7, 'Boom error');
    assert.deepEqual(alerts, ['Boom error']);

    const dtXhr = { status: 401 };
    doc.dispatchEvent(Object.assign(new Event('xhr'), {
        namespace: 'dt',
        _args: [{ ajax: { url: '/purchasing/purchase-orders' }, jqXHR: dtXhr }, null, dtXhr],
    }));
    doc.dispatchEvent(Object.assign(new Event('dt-error'), {
        namespace: 'dt',
        _args: [{ ajax: { url: '/purchasing/purchase-orders' }, jqXHR: dtXhr }, 7, 'Ajax error'],
    }));
    assert.equal(prompts.length, 1);
    await flush();

    assert.equal(dtState.ajax.defaults.headers['X-CSRF-TOKEN'], 'new-token-no-jq');
    const sameOriginOpts = { url: '/purchasing/purchase-orders', headers: { 'X-CSRF-TOKEN': 'old-token' }, data: { _token: 'old-token', draw: 2 } };
    doc.dispatchEvent(Object.assign(new Event('preXhr'), {
        namespace: 'dt',
        _args: [{ ajax: { url: sameOriginOpts.url } }, sameOriginOpts.data, sameOriginOpts],
    }));
    assert.equal(sameOriginOpts.headers['X-CSRF-TOKEN'], 'new-token-no-jq');
    assert.equal(sameOriginOpts.data._token, 'new-token-no-jq');

    const customCalls = [];
    const customErrorFn = (xhr) => customCalls.push(xhr?.status);
    const customOpts401 = { url: `${ORIGIN}/finance/local-procurement/1/goods-receipts/data`, error: customErrorFn };
    const customSettings = { ajax: { url: customOpts401.url, error: customErrorFn }, jqXHR: { status: 401, getResponseHeader: () => null } };
    doc.dispatchEvent(Object.assign(new Event('preXhr'), {
        namespace: 'dt',
        _args: [customSettings, {}, customOpts401],
    }));
    customOpts401.error(customSettings.jqXHR, 'error', 'Unauthorized');
    customOpts401.complete?.(customSettings.jqXHR, 'error');
    assert.equal(prompts.length, 2);
    assert.equal(customCalls.length, 0);
});
