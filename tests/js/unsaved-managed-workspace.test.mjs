import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/unsaved-changes.js', import.meta.url), 'utf8');
const managedSelector = '[data-server-tabs-unsaved="managed"]';

// Dispatch capture listeners before bubble listeners and honor immediate propagation stops,
// so a simulated workspace owner only runs when the real shared guard yields to it.
function eventTarget() {
    const listeners = new Map();
    return {
        addEventListener(type, handler, options = false) {
            const capture = options === true || Boolean(options?.capture);
            listeners.set(type, [...(listeners.get(type) ?? []), { handler, capture }]);
        },
        dispatchEvent(event) {
            const handlers = listeners.get(event.type) ?? [];
            for (const capture of [true, false]) {
                for (const listener of handlers.filter((entry) => entry.capture === capture)) {
                    listener.handler(event);
                    if (event.immediatePropagationStopped) return !event.defaultPrevented;
                }
            }
            return !event.defaultPrevented;
        },
    };
}

function makeEvent(type, properties = {}) {
    return {
        type,
        defaultPrevented: false,
        immediatePropagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopImmediatePropagation() { this.immediatePropagationStopped = true; },
        ...properties,
    };
}

function loadGuard({ managed = true, confirmed = false } = {}) {
    const workspace = {};
    const initialState = {
        _serverTabs: '#exchange-rates-workspace',
        _serverTabsIndex: 3,
        _serverTabsView: 3,
        url: 'http://localhost/admin/exchange-rates?tab=annual&edit_year=2026',
        unrelated: 'keep existing state',
    };
    const pushes = [];
    const prompts = [];
    const historyGuards = [];
    const timers = new Map();
    let nextTimer = 0;
    let reloads = 0;
    let backs = 0;
    const document = Object.assign(eventTarget(), {
        title: 'Exchange Rates',
        readyState: 'complete',
        querySelector: (selector) => managed && selector === managedSelector ? workspace : null,
        querySelectorAll: () => [],
    });
    const window = Object.assign(eventTarget(), {
        location: { href: initialState.url, reload() { reloads += 1; } },
        history: {
            state: initialState,
            pushState(state, title, url) {
                this.state = { ...state };
                pushes.push({ state: this.state, title, url });
            },
            back() { backs += 1; },
        },
        AdasiI18n: { t: (key) => key },
        AdasiAlert: {
            confirmDanger(copy) {
                prompts.push(copy);
                return Promise.resolve({ isConfirmed: confirmed });
            },
        },
    });
    window.addEventListener('adasi:server-tabs:history-guard', (event) => {
        historyGuards.push({ ...event.detail });
    });

    vm.runInNewContext(source, {
        window,
        document,
        HTMLFormElement: class {},
        CustomEvent: class {
            constructor(type, options = {}) {
                Object.assign(this, makeEvent(type, { detail: options.detail }));
            }
        },
        setTimeout(callback) { timers.set(++nextTimer, callback); return nextTimer; },
        clearTimeout(id) { timers.delete(id); },
    }, { filename: 'unsaved-changes.js' });

    function link(href, owned = false) {
        return {
            href: new URL(href, initialState.url).href,
            getAttribute(name) { return name === 'href' ? href : null; },
            hasAttribute() { return false; },
            closest(selector) {
                if (selector === 'a[href]') return this;
                return managed && owned && selector === managedSelector ? workspace : null;
            },
        };
    }

    return {
        window, document, initialState, pushes, prompts, historyGuards, link,
        guard: window.AdasiUnsaved,
        get reloads() { return reloads; },
        get backs() { return backs; },
    };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('managed dirty traps preserve the view and notify ServerTabs of each new physical position', () => {
    const fixture = loadGuard();
    fixture.guard.markDirty();
    fixture.guard.isDirty();

    assert.equal(fixture.pushes.length, 1, 'rechecking dirty state must not add another trap entry');
    assert.deepEqual(fixture.window.history.state, {
        ...fixture.initialState,
        _serverTabsIndex: 4,
        adasiUnsaved: true,
    });
    assert.equal(fixture.initialState.adasiUnsaved, undefined, 'the original state is not mutated');
    assert.equal(fixture.pushes[0].url, fixture.initialState.url);
    assert.deepEqual(fixture.historyGuards, [{ selector: fixture.initialState._serverTabs, index: 4 }]);

    fixture.guard.markClean();
    fixture.guard.markDirty();
    assert.equal(fixture.pushes.length, 2);
    assert.deepEqual(fixture.window.history.state, {
        ...fixture.initialState,
        _serverTabsIndex: 5,
        adasiUnsaved: true,
    });
    assert.deepEqual(fixture.historyGuards, [
        { selector: fixture.initialState._serverTabs, index: 4 },
        { selector: fixture.initialState._serverTabs, index: 5 },
    ]);
});

test('managed workspace link reaches its owner and produces only the owner confirmation', async () => {
    const fixture = loadGuard();
    fixture.guard.markDirty();
    let ownerCalls = 0;
    fixture.document.addEventListener('click', (event) => {
        ownerCalls += 1;
        event.preventDefault();
        fixture.guard.showLeaveConfirmation(() => assert.fail('cancelled navigation must stay put'));
    });

    const event = makeEvent('click', { target: fixture.link('?tab=daily', true) });
    fixture.document.dispatchEvent(event);
    await settle();

    assert.equal(ownerCalls, 1);
    assert.equal(event.immediatePropagationStopped, false, 'shared guard leaves workspace handling available');
    assert.equal(fixture.prompts.length, 1);
    assert.equal(fixture.guard.isDirty(), true);
    assert.equal(fixture.window.location.href, fixture.initialState.url);
});

test('managed popstate leaves history recovery and the single confirmation to its owner', async () => {
    const fixture = loadGuard();
    fixture.guard.markDirty();
    let ownerCalls = 0;
    fixture.window.addEventListener('popstate', () => {
        ownerCalls += 1;
        fixture.guard.showLeaveConfirmation(() => assert.fail('cancelled Back must stay put'));
    });

    fixture.window.dispatchEvent(makeEvent('popstate', { state: fixture.initialState }));
    await settle();

    assert.equal(ownerCalls, 1);
    assert.equal(fixture.prompts.length, 1);
    assert.equal(fixture.pushes.length, 1, 'shared guard must not add its own popstate recovery entry');
    assert.equal(fixture.backs, 0);
    assert.equal(fixture.guard.isDirty(), true);
});

test('resuming tracking after an accepted AJAX navigation detects new edits before the leave timer expires', async () => {
    const fixture = loadGuard({ confirmed: true });
    fixture.guard.markDirty();
    await fixture.guard.showLeaveConfirmation();

    fixture.guard.markDirty();
    assert.equal(fixture.guard.isDirty(), false, 'accepted leave temporarily suppresses dirty tracking');

    // Fixture timers never advance: the AJAX lifecycle must explicitly restore tracking.
    fixture.guard.resumeTracking();
    fixture.guard.markDirty();
    assert.equal(fixture.guard.isDirty(), true, 'new edits are detected immediately in the same document');
    assert.equal(fixture.window.isFormDirty, true);
    assert.equal(fixture.prompts.length, 1);
});

test('managed workspace still guards a sidebar link outside its owned content', async () => {
    const fixture = loadGuard({ confirmed: true });
    fixture.guard.markDirty();
    const sidebarLink = fixture.link('/admin/dashboard');
    const event = makeEvent('click', { target: sidebarLink });
    let laterClickCalls = 0;
    fixture.document.addEventListener('click', () => { laterClickCalls += 1; });

    fixture.document.dispatchEvent(event);
    await settle();

    assert.equal(event.defaultPrevented, true);
    assert.equal(laterClickCalls, 0, 'shared guard captures navigation outside the workspace');
    assert.equal(fixture.prompts.length, 1);
    assert.equal(fixture.window.location.href, sidebarLink.href);
});

for (const shortcut of [{ key: 'F5' }, { key: 'r', ctrlKey: true }, { key: 'R', metaKey: true }]) {
    test(`managed workspace retains reload guard for ${JSON.stringify(shortcut)}`, async () => {
        const fixture = loadGuard({ confirmed: true });
        fixture.guard.markDirty();
        const event = makeEvent('keydown', shortcut);

        fixture.window.dispatchEvent(event);
        fixture.window.dispatchEvent(makeEvent('keydown', shortcut));
        await settle();

        assert.equal(event.defaultPrevented, true);
        assert.equal(event.immediatePropagationStopped, true);
        assert.equal(fixture.prompts.length, 1, 'repeated shortcuts share the pending confirmation');
        assert.equal(fixture.prompts[0].title, 'js.navigation.reload_title');
        assert.equal(fixture.reloads, 1);
    });
}

test('a workspace without managed opt-in keeps the default history, link and Back guards', async () => {
    const fixture = loadGuard({ managed: false });
    fixture.guard.markDirty();
    assert.deepEqual(fixture.window.history.state, { adasiUnsaved: true });
    assert.deepEqual(fixture.historyGuards, [], 'unmanaged dirty entries do not notify ServerTabs');

    const event = makeEvent('click', { target: fixture.link('?tab=daily', true) });
    fixture.document.dispatchEvent(event);
    await settle();
    assert.equal(event.defaultPrevented, true);
    assert.equal(event.immediatePropagationStopped, true);
    assert.equal(fixture.prompts.length, 1, 'unmanaged workspace links retain the shared confirmation');

    fixture.window.dispatchEvent(makeEvent('popstate', { state: fixture.initialState }));
    await settle();
    assert.equal(fixture.pushes.length, 2, 'default Back handling re-pushes the trap entry');
    assert.equal(fixture.prompts.length, 2, 'default Back still asks before leaving');
    assert.equal(fixture.backs, 0);
    assert.equal(fixture.guard.isDirty(), true);
});
