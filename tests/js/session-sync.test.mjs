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

function fixture({ session = { crossTab: true, cleanup: false, userId: 41 }, login = null, broadcast = true, blocked = false, initialStorage = [], channelFailure = null, answers = [DECLINED], dirty = false, fetchImpl = null, jquery = null, dom = null, alertRunsPreConfirm = true } = {}) {
    const win = new EventTarget();
    const doc = Object.assign(new EventTarget(), {
        querySelector: (selector) => (dom ? dom.querySelector(selector) : login),
        querySelectorAll: (selector) => (dom ? dom.querySelectorAll(selector) : []),
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
    'quotation_draft_123',
    'quotation_draft_0',
    'quotation_draft_41_123',
    'quotation_draft_42_123',
    'quotation_draft_41_hash',
    'adasi_chat_draft_41_1',
];
const retained = ['quotation_draft_hash', 'quotation_draft_123_456_extra', 'adasi_supplier_reg_draft', 'adasi.sidebar.41', 'supplierAuditScoreGuide'];

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

test('a session that cannot be restored stays on the page with a warning toast or goes to the code challenge', async () => {
    const cases = [
        [{ status: 401, body: { authenticated: false } }, null],
        [{ status: 401, body: { authenticated: false, two_factor_url: `${ORIGIN}/two-factor-challenge` } }, `${ORIGIN}/two-factor-challenge`],
        [{ status: 401, body: { authenticated: false, two_factor_url: 'https://evil.example.test/two-factor-challenge' } }, null],
        [{ status: 403, body: { message: 'change password', redirect: `${ORIGIN}/profile/security` } }, null],
        [{ status: 200, body: { authenticated: true, user_id: 99, csrf_token: 'other-account' } }, PAGE_URL],
    ];
    for (const [reply, destination] of cases) {
        const dom = tokenDom();
        const f = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom, fetchImpl: statusServer(reply).impl });
        f.api.handleActionResponse({ status: 401 });
        await flush();
        assert.deepEqual(f.redirects, destination ? [destination] : [], JSON.stringify(reply));
        assert.equal(dom.meta.getAttribute('content'), 'old-token', 'no token from another account or a failed check is applied');
        if (!destination) {
            assert.deepEqual(f.toasts, [{ type: 'warning', text: 'unauthenticated copy' }], JSON.stringify(reply));
            assert.equal(f.api.isSessionExpired(), true, 'polling remains paused when staying unauthenticated');
        } else {
            assert.deepEqual(f.toasts, [], JSON.stringify(reply));
        }
    }
});

test('an unauthenticated session without custom copy falls back to js.session.expired_text', async () => {
    const session = { ...RESUMABLE, copy: { restored: 'restored copy', failed: 'failed copy' } };
    const unauth = statusServer({ status: 401, body: { authenticated: false } });
    const f = fixture({ session, answers: [CONFIRMED], dom: tokenDom(), fetchImpl: unauth.impl });
    f.api.handleActionResponse({ status: 401 });
    await flush();
    assert.deepEqual(f.redirects, []);
    assert.deepEqual(f.toasts, [{ type: 'warning', text: 'js.session.expired_text' }]);
    assert.equal(f.api.isSessionExpired(), true);
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

test('an alert that does not run the check (native fallback) still restores, stays with warning, or reports a failure as a toast', async () => {
    const ok = statusServer({ status: 200, body: { authenticated: true, user_id: 41, csrf_token: 'new-token' } });
    const restored = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), alertRunsPreConfirm: false, fetchImpl: ok.impl });
    restored.api.handleActionResponse({ status: 419 });
    await flush();
    assert.deepEqual(restored.redirects, []);
    assert.deepEqual(restored.toasts, [{ type: 'success', text: 'restored copy' }]);

    const unauth = statusServer({ status: 401, body: { authenticated: false } });
    const stayed = fixture({ session: RESUMABLE, answers: [CONFIRMED], dom: tokenDom(), alertRunsPreConfirm: false, fetchImpl: unauth.impl });
    stayed.api.handleActionResponse({ status: 419 });
    await flush();
    assert.deepEqual(stayed.redirects, []);
    assert.deepEqual(stayed.toasts, [{ type: 'warning', text: 'unauthenticated copy' }]);
    assert.equal(stayed.api.isSessionExpired(), true);

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
