import test from 'node:test';
import assert from 'node:assert/strict';

// server-tabs.js only touches a handful of DOM members, so a small hand-rolled fake is enough (no DOM library installed).
function makeClassList() {
    const classes = new Set();
    return {
        add: (...names) => names.forEach((name) => classes.add(name)),
        remove: (...names) => names.forEach((name) => classes.delete(name)),
        contains: (name) => classes.has(name),
        toggle: (name, force) => {
            if (force === undefined) {
                if (classes.has(name)) {
                    classes.delete(name);
                    return false;
                }
                classes.add(name);
                return true;
            }
            if (force) {
                classes.add(name);
                return true;
            }
            classes.delete(name);
            return false;
        },
    };
}

function makeElement(overrides = {}) {
    const attributes = new Map();
    return {
        style: {},
        dataset: {},
        classList: makeClassList(),
        className: '',
        innerHTML: '',
        textContent: '',
        setAttribute: (name, value) => attributes.set(name, String(value)),
        removeAttribute: (name) => attributes.delete(name),
        getAttribute: (name) => attributes.get(name) ?? null,
        hasAttribute: (name) => attributes.has(name),
        querySelector: () => null,
        querySelectorAll: () => [],
        appendChild: (child) => child,
        ...overrides,
    };
}

function makeTabLink(href, tabName) {
    const link = makeElement({ dataset: { tabName } });
    const readAttribute = link.getAttribute;
    link.getAttribute = (name) => (name === 'href' ? href : readAttribute(name));
    link.closest = (selector) => (selector === '[data-server-tab]' ? link : null);
    return link;
}

let nextId = 0;
const timers = [];
const realSetTimeout = globalThis.setTimeout;
const realClearTimeout = globalThis.clearTimeout;
const realFetch = globalThis.fetch;
const toasts = [];
const containers = new Map();
const windowListeners = {};
const documentListeners = {};

globalThis.window = {
    history: { state: null, pushState() {}, replaceState() {} },
    location: { href: 'http://localhost/page', pathname: '/page' },
    addEventListener(type, handler) { (windowListeners[type] ??= []).push(handler); },
    dispatchEvent(event) { for (const handler of windowListeners[event.type] ?? []) handler(event); return true; },
    AdasiToast: { error: (message) => toasts.push(message) },
};
globalThis.document = {
    readyState: 'complete',
    querySelector: (selector) => containers.get(selector) ?? null,
    querySelectorAll: () => [],
    createElement: () => makeElement({ querySelector: () => ({}) }),
    addEventListener(type, handler) { (documentListeners[type] ??= []).push(handler); },
    dispatchEvent(event) { for (const handler of documentListeners[event.type] ?? []) handler(event); return true; },
};

await import('../../resources/js/server-tabs.js');

function setup(options = {}, pillTabs = []) {
    const selector = `#harness${++nextId}`;
    const listeners = {};
    const overlays = [];
    const content = makeElement();
    content.appendChild = (child) => { overlays.push(child); return child; };
    content.querySelector = (query) => (query === '.server-tabs-loading-overlay' ? overlays[0] ?? null : null);
    const elements = { '[data-server-tabs-content]': content };
    const container = makeElement({
        addEventListener: (type, handler) => { (listeners[type] ??= []).push(handler); },
        dispatchEvent: () => true,
        querySelector: (query) => elements[query] ?? null,
        querySelectorAll: (query) => (query === '[data-server-tab]:not([data-server-tab-card])' ? pillTabs : []),
    });
    containers.set(selector, container);
    window.AdasiServerTabs.init(selector, options);

    return {
        selector,
        content,
        elements,
        container,
        overlay: () => overlays[0],
        click: (link) => listeners.click.forEach((handler) => handler({ target: link, preventDefault() {} })),
        hover: (link) => listeners.pointerenter.forEach((handler) => handler({ target: link })),
    };
}

function stubNetwork() {
    // The fragment cache is module-wide; start every test cold so URLs can repeat across tests.
    window.AdasiServerTabs.invalidateCache();
    const calls = [];
    globalThis.fetch = (url, options = {}) => new Promise((resolve, reject) => {
        calls.push({
            url,
            options,
            respond: (data) => resolve({ ok: true, json: async () => data }),
        });
        options.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
    });
    globalThis.setTimeout = (fn, ms) => { timers.push({ fn, ms, cleared: false }); return timers.length; };
    globalThis.clearTimeout = (id) => { if (timers[id - 1]) timers[id - 1].cleared = true; };

    return calls;
}

function restoreNetwork() {
    globalThis.fetch = realFetch;
    globalThis.setTimeout = realSetTimeout;
    globalThis.clearTimeout = realClearTimeout;
    timers.length = 0;
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a superseded request cannot clear the busy state or timeout of the request that replaced it', async () => {
    const calls = stubNetwork();
    const realConsoleError = console.error;
    console.error = () => {}; // the watchdog below makes the module log its own timeout failure
    try {
        const page = setup();

        page.click(makeTabLink('/page?tab=a', 'a'));
        page.click(makeTabLink('/page?tab=b', 'b'));
        // Request A was aborted by B; let A's rejection and its finally block run.
        await settle();

        assert.equal(calls.length, 2);
        assert.equal(calls[0].options.signal.aborted, true, 'the first request is cancelled by the second click');
        assert.equal(page.content.getAttribute('aria-busy'), 'true', 'B is still loading, so the container stays busy');
        assert.equal(page.overlay().style.display, '', 'B still shows its loading overlay');

        // The 30s default watchdog of B must still be able to cancel B.
        const watchdogs = timers.filter((timer) => timer.ms === 30000 && !timer.cleared);
        assert.equal(watchdogs.length, 1, 'only the live request keeps a watchdog');
        watchdogs[0].fn();
        assert.equal(calls[1].options.signal.aborted, true, 'the watchdog aborts the request it belongs to');
        await settle();
        assert.equal(page.content.getAttribute('aria-busy'), null, 'the live request clears the busy state when it ends');
    } finally {
        console.error = realConsoleError;
        restoreNetwork();
    }
});

test('a cache hit that replaces an in-flight request clears the busy state it left behind', async () => {
    const calls = stubNetwork();
    try {
        const page = setup();

        page.click(makeTabLink('/page?tab=cached', 'cached'));
        calls[0].respond({ html: '<p>cached</p>', tab: 'cached', url: '/page?tab=cached' });
        await settle();
        assert.equal(page.content.innerHTML, '<p>cached</p>');

        page.click(makeTabLink('/page?tab=slow', 'slow'));
        assert.equal(page.content.getAttribute('aria-busy'), 'true');
        page.click(makeTabLink('/page?tab=cached', 'cached'));
        await settle();

        assert.equal(calls.length, 2, 'the second visit to a cached tab does not hit the network');
        assert.equal(page.content.getAttribute('aria-busy'), null);
        assert.equal(page.overlay().style.display, 'none');
    } finally {
        restoreNetwork();
    }
});

test('a server-rendered tab nav replaces the nav markup and keeps keyboard focus on the same tab', async () => {
    const calls = stubNetwork();
    const nav = makeElement();
    const oldTab = makeTabLink('/page?tab=b', 'b');
    const otherTab = makeTabLink('/page?tab=c', 'c');
    const newTab = makeTabLink('/page?tab=b&period=2026', 'b');
    let focused = 0;
    newTab.focus = () => { focused += 1; };
    oldTab.closest = (selector) => (selector === '[data-server-tab]' ? oldTab : selector === '[data-server-tabs-nav]' ? nav : null);
    nav.querySelectorAll = () => [otherTab, newTab];
    const realActive = Object.getOwnPropertyDescriptor(globalThis.document, 'activeElement');
    globalThis.document.activeElement = oldTab;
    try {
        const page = setup();
        page.elements['[data-server-tabs-nav]'] = nav;

        page.click(oldTab);
        calls[0].respond({ html: '<p>b</p>', tab: 'b', url: '/page?tab=b&period=2026', nav: '<a data-server-tab data-tab-name="b">fresh</a>' });
        await settle();

        assert.equal(page.content.innerHTML, '<p>b</p>');
        assert.equal(nav.innerHTML, '<a data-server-tab data-tab-name="b">fresh</a>');
        assert.equal(focused, 1, 'focus returns to the re-rendered tab the user activated');
    } finally {
        if (realActive) Object.defineProperty(globalThis.document, 'activeElement', realActive); else delete globalThis.document.activeElement;
        restoreNetwork();
    }
});

test('pages that do not send a nav keep their existing tab markup untouched', async () => {
    const calls = stubNetwork();
    const nav = makeElement({ innerHTML: '<a>original</a>' });
    try {
        const page = setup();
        page.elements['[data-server-tabs-nav]'] = nav;

        page.click(makeTabLink('/page?tab=a', 'a'));
        calls[0].respond({ html: '<p>a</p>', tab: 'a', url: '/page?tab=a' });
        await settle();

        assert.equal(nav.innerHTML, '<a>original</a>');
    } finally {
        restoreNetwork();
    }
});

test('tabs inside a server-rendered nav only move aria-current, while legacy pills still get their classes', async () => {
    const calls = stubNetwork();
    const nav = makeElement();
    const navTabA = makeTabLink('/page?tab=a', 'a');
    const navTabB = makeTabLink('/page?tab=b', 'b');
    for (const tab of [navTabA, navTabB]) {
        tab.closest = (selector) => (selector === '[data-server-tab]' ? tab : selector === '[data-server-tabs-nav]' ? nav : null);
    }
    navTabA.setAttribute('aria-current', 'page');
    const legacyPill = makeTabLink('/page?tab=b', 'b');
    try {
        const page = setup({}, [navTabA, navTabB, legacyPill]);

        page.click(navTabB);
        calls[0].respond({ html: '<p>b</p>', tab: 'b', url: '/page?tab=b' });
        await settle();

        assert.equal(navTabA.getAttribute('aria-current'), null);
        assert.equal(navTabB.getAttribute('aria-current'), 'page');
        assert.equal(navTabB.classList.contains('tw:bg-primary'), false, 'nav tabs are styled by aria-current, not by JS class lists');
        assert.equal(legacyPill.classList.contains('tw:bg-primary'), true, 'DRP/comparison pills keep the old behaviour');
    } finally {
        restoreNetwork();
    }
});

test('cacheTtlMs lets a page opt into a shorter fragment cache than the default', async () => {
    const calls = stubNetwork();
    const realNow = Date.now;
    let offset = 0;
    Date.now = () => realNow() + offset;
    try {
        const shortLived = setup({ cacheTtlMs: 30_000 });
        shortLived.click(makeTabLink('/page?tab=short', 'short'));
        calls[0].respond({ html: '<p>short</p>', tab: 'short', url: '/page?tab=short' });
        await settle();

        const defaultTtl = setup();
        defaultTtl.click(makeTabLink('/page?tab=default', 'default'));
        calls[1].respond({ html: '<p>default</p>', tab: 'default', url: '/page?tab=default' });
        await settle();

        offset = 60_000;
        shortLived.click(makeTabLink('/page?tab=short', 'short'));
        defaultTtl.click(makeTabLink('/page?tab=default', 'default'));
        await settle();

        assert.equal(calls.length, 3, 'only the 30s page refetches after a minute; the default 5 minute cache still serves the other');
        assert.equal(calls[2].url, '/page?tab=short');
    } finally {
        Date.now = realNow;
        restoreNetwork();
    }
});

test('tab and prefetch requests identify themselves as server-tabs fragment requests', async () => {
    const calls = stubNetwork();
    try {
        const page = setup();

        page.click(makeTabLink('/page?tab=a', 'a'));
        assert.equal(calls[0].options.headers['X-Adasi-Server-Tabs'], '1');
        assert.equal(calls[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.equal(calls[0].options.headers['X-Adasi-Prefetch'], undefined);

        page.hover(makeTabLink('/page?tab=prefetched', 'prefetched'));
        const debounce = timers.find((timer) => timer.ms === 60);
        debounce.fn();
        await settle();
        assert.equal(calls[1].url, '/page?tab=prefetched');
        assert.equal(calls[1].options.headers['X-Adasi-Server-Tabs'], '1');
        assert.equal(calls[1].options.headers['X-Adasi-Prefetch'], '1');
    } finally {
        restoreNetwork();
    }
});

test('programmatic navigation refreshes a server fragment without navigating the document', async () => {
    const calls = stubNetwork();
    try {
        const page = setup();
        const navigation = window.AdasiServerTabs.navigate(page.selector, '/page?tab=annual');
        assert.equal(calls.length, 1);
        assert.equal(calls[0].options.headers['X-Adasi-Server-Tabs'], '1');
        calls[0].respond({ html: '<p>saved annual rates</p>', tab: 'annual', url: '/page?tab=annual' });
        assert.equal(await navigation, true);
        assert.equal(page.content.innerHTML, '<p>saved annual rates</p>');
    } finally {
        restoreNetwork();
    }
});

test('zero cache TTL always fetches fresh edit tokens, even within the same millisecond', async () => {
    const calls = stubNetwork();
    const now = Date.now;
    Date.now = () => 1000;
    try {
        const page = setup({ cacheTtlMs: 0 });
        page.click(makeTabLink('/page?annual_edit_year=2024', 'annual'));
        calls[0].respond({ html: '<input value="1">', tab: 'annual' });
        await settle();
        page.click(makeTabLink('/page?annual_edit_year=2024', 'annual'));
        assert.equal(calls.length, 2);
        calls[1].respond({ html: '<input value="2">', tab: 'annual' });
        await settle();
        assert.equal(page.content.innerHTML, '<input value="2">');
    } finally {
        Date.now = now;
        restoreNetwork();
    }
});

test('a page can disable speculative requests for financial edit forms', () => {
    stubNetwork();
    try {
        const page = setup({ prefetch: false });
        page.hover(makeTabLink('/page?annual_edit_year=2024', 'annual'));
        assert.equal(timers.some((timer) => timer.ms === 60), false);
    } finally {
        restoreNetwork();
    }
});

test('navigation waits for the page guard and keeps the editor when the user stays', async () => {
    const calls = stubNetwork();
    let answer;
    try {
        const page = setup({ beforeNavigate: () => new Promise((resolve) => { answer = resolve; }) });
        page.content.innerHTML = '<input value="unsaved">';
        const navigation = window.AdasiServerTabs.navigate(page.selector, '/page?tab=effective');
        assert.equal(calls.length, 0);
        answer(false);
        assert.equal(await navigation, false);
        assert.equal(calls.length, 0);
        assert.equal(page.content.innerHTML, '<input value="unsaved">');
    } finally {
        restoreNetwork();
    }
});

test('a rejected managed Back navigation restores the history position and preserves the editor', async () => {
    const calls = stubNetwork();
    const originalHistory = window.history;
    const originalLocation = window.location;
    const moves = [];
    let allowed = true;
    window.history = {
        state: null,
        replaceState(value) { this.state = value; },
        pushState(value) { this.state = value; },
        go(delta) { moves.push(delta); },
    };
    window.location = { href: 'http://localhost/page?tab=annual', pathname: '/page' };
    try {
        const page = setup({ managedHistory: true, beforeNavigate: () => allowed });
        const navigation = window.AdasiServerTabs.navigate(page.selector, '/page?annual_edit_year=2024');
        await settle();
        calls[0].respond({ html: '<input value="unsaved">', tab: 'annual', url: '/page?annual_edit_year=2024' });
        await navigation;
        const editorState = window.history.state;
        allowed = false;
        window.location.href = 'http://localhost/page?tab=annual';
        for (const handler of windowListeners.popstate ?? []) handler({ state: { _serverTabs: page.selector, _serverTabsIndex: 0 } });
        await settle();
        assert.deepEqual(moves, [1]);
        assert.equal(calls.length, 1, 'a rejected Back must not fetch or discard the form');
        assert.equal(page.content.innerHTML, '<input value="unsaved">');
        for (const handler of windowListeners.popstate ?? []) handler({ state: editorState });
        await settle();
        assert.equal(calls.length, 1, 'restoring the URL must not trigger a second load');
    } finally {
        window.history = originalHistory;
        window.location = originalLocation;
        restoreNetwork();
    }
});

test('managed history guards the existing dirty trap before leaving the initial workspace document', async () => {
    const calls = stubNetwork();
    const previous = window.history;
    const moves = [];
    let allowed = false;
    window.history = { state: null, replaceState(value) { this.state = value; }, go(delta) { moves.push(delta); } };
    try {
        const page = setup({ managedHistory: true, beforeNavigate: () => allowed });
        page.content.innerHTML = '<input value="unsaved initial form">';
        const initial = { _serverTabs: page.selector, _serverTabsIndex: 0 };
        for (const handler of windowListeners.popstate ?? []) handler({ state: initial });
        await settle();
        assert.deepEqual(moves, [1], 'stay restores the dirty guard entry without a reload');
        assert.equal(calls.length, 0);
        for (const handler of windowListeners.popstate ?? []) handler({ state: { ...initial, adasiUnsaved: true } });
        allowed = true;
        for (const handler of windowListeners.popstate ?? []) handler({ state: initial });
        await settle();
        assert.deepEqual(moves, [1, -1], 'leave advances past the synthetic guard to the preceding page');
        assert.equal(calls.length, 0);
        assert.equal(page.content.innerHTML, '<input value="unsaved initial form">');
    } finally {
        window.history = previous;
        restoreNetwork();
    }
});

function installManagedHistoryStack() {
    const originalHistory = window.history;
    const originalLocation = window.location;
    const entries = [
        { state: null, url: 'http://localhost/previous' },
        { state: null, url: 'http://localhost/page?view=a' },
    ];
    const moves = [];
    let position = 1;
    window.location = { href: entries[position].url, pathname: '/page' };
    const sync = () => {
        window.history.state = entries[position].state;
        window.location.href = entries[position].url;
    };
    window.history = {
        state: null,
        replaceState(state, title, url = window.location.href) {
            entries[position] = { state, url: new URL(url, 'http://localhost').href };
            sync();
        },
        pushState(state, title, url = window.location.href) {
            entries.splice(position + 1);
            entries.push({ state, url: new URL(url, 'http://localhost').href });
            position++;
            sync();
        },
        go(delta) {
            moves.push(delta);
            const next = position + delta;
            if (next < 0 || next >= entries.length) return;
            position = next;
            sync();
            window.dispatchEvent({ type: 'popstate', state: this.state });
        },
    };

    return {
        entries,
        moves,
        get position() { return position; },
        guard(selector) {
            const prior = window.history.state;
            const index = prior._serverTabsIndex + 1;
            window.history.pushState({ ...prior, _serverTabsIndex: index, adasiUnsaved: true }, '', window.location.href);
            window.dispatchEvent({ type: 'adasi:server-tabs:history-guard', detail: { selector, index } });
        },
        restore() { window.history = originalHistory; window.location = originalLocation; },
    };
}

test('staying after a multi-entry Back jump restores the exact physical entry across earlier dirty guards', async () => {
    const calls = stubNetwork();
    const history = installManagedHistoryStack();
    let allowed = true;
    try {
        const page = setup({ managedHistory: true, cacheTtlMs: 0, beforeNavigate: () => allowed });
        history.guard(page.selector);
        for (const view of ['b', 'c']) {
            const navigation = window.AdasiServerTabs.navigate(page.selector, `/page?view=${view}`);
            await settle();
            calls.at(-1).respond({ html: `<input value="${view} unsaved">`, tab: 'annual', url: `/page?view=${view}` });
            assert.equal(await navigation, true);
            history.guard(page.selector);
        }
        const currentPosition = history.position;
        const currentState = window.history.state;
        const currentUrl = window.location.href;
        allowed = false;

        // A, guard(A), B, guard(B), C, guard(C): the history-menu jump crosses five real entries.
        window.history.go(-5);
        await settle();

        assert.deepEqual(history.moves, [-5, 5], 'Stay must undo the physical jump, including all earlier guard entries.');
        assert.equal(history.position, currentPosition);
        assert.equal(window.location.href, currentUrl);
        assert.deepEqual(window.history.state, currentState);
        assert.equal(calls.length, 2, 'A canceled history jump must not fetch a replacement fragment.');
        assert.equal(page.content.innerHTML, '<input value="c unsaved">');
    } finally {
        history.restore();
        restoreNetwork();
    }
});

test('Back skips same-view dirty guards and Forward consumes them without extra fragment loads', async () => {
    const calls = stubNetwork();
    const history = installManagedHistoryStack();
    try {
        const page = setup({ managedHistory: true, cacheTtlMs: 0, beforeNavigate: () => true });
        page.content.innerHTML = '<p>a</p>';
        history.guard(page.selector);
        for (const view of ['b', 'c']) {
            const navigation = window.AdasiServerTabs.navigate(page.selector, `/page?view=${view}`);
            await settle();
            calls.at(-1).respond({ html: `<p>${view}</p>`, tab: 'annual', url: `/page?view=${view}` });
            await navigation;
            if (view === 'b') history.guard(page.selector);
        }

        for (const [direction, view, fetches] of [[-1, 'b', true], [-1, 'a', true], [1, 'b', true], [1, 'b', false], [1, 'c', true]]) {
            const before = calls.length;
            window.history.go(direction);
            await settle();
            assert.equal(calls.length, before + Number(fetches), 'A same-view Forward guard must not request a duplicate fragment.');
            if (fetches) {
                assert.equal(calls.at(-1).url, `http://localhost/page?view=${view}`);
                calls.at(-1).respond({ html: `<p>${view}</p>`, tab: 'annual', url: `/page?view=${view}` });
                await settle();
            }
            assert.equal(page.content.innerHTML, `<p>${view}</p>`);
            assert.equal(window.location.href, `http://localhost/page?view=${view}`);
        }

        assert.deepEqual(history.moves, [-1, -1, -1, 1, 1, 1]);
        assert.equal(window.history.state._serverTabsIndex, 4);
        assert.equal(calls.length, 6, 'The two guard entries are skipped without fetching their duplicate view.');
    } finally {
        history.restore();
        restoreNetwork();
    }
});

test('Forward into a terminal dirty guard stays on its view without confirmation or stepping beyond the stack', async () => {
    const calls = stubNetwork();
    const history = installManagedHistoryStack();
    let allowed = true;
    let guardCalls = 0;
    try {
        const page = setup({
            managedHistory: true,
            cacheTtlMs: 0,
            beforeNavigate: () => { guardCalls++; return allowed; },
        });
        history.guard(page.selector);
        const navigation = window.AdasiServerTabs.navigate(page.selector, '/page?view=b');
        await settle();
        calls[0].respond({ html: '<input value="b retained">', tab: 'annual', url: '/page?view=b' });
        await navigation;
        history.guard(page.selector);

        window.history.go(-1);
        await settle();
        calls[1].respond({ html: '<p>a</p>', tab: 'annual', url: '/page?view=a' });
        await settle();
        window.history.go(1);
        await settle();
        calls[2].respond({ html: '<input value="b retained">', tab: 'annual', url: '/page?view=b' });
        await settle();

        const priorGuardCalls = guardCalls;
        allowed = false;
        window.history.go(1);
        await settle();

        assert.deepEqual(history.moves, [-1, -1, 1, 1]);
        assert.equal(guardCalls, priorGuardCalls, 'Reaching the same view through Forward is not a leave operation.');
        assert.equal(calls.length, 3);
        assert.equal(history.position, history.entries.length - 1);
        assert.equal(window.history.state._serverTabsIndex, 3);
        assert.equal(window.location.href, 'http://localhost/page?view=b');
        assert.equal(page.content.innerHTML, '<input value="b retained">');

        // Its physical position must still be remembered, so a subsequent rejected Back restores this guard.
        window.history.go(-1);
        await settle();
        assert.deepEqual(history.moves, [-1, -1, 1, 1, -1, 1]);
        assert.equal(history.position, history.entries.length - 1);
        assert.equal(calls.length, 3);
    } finally {
        history.restore();
        restoreNetwork();
    }
});

test('a failed managed Back restores its original physical position before notifying the page error hook', async () => {
    const calls = stubNetwork();
    const history = installManagedHistoryStack();
    const originalConsoleError = console.error;
    const failures = [];
    console.error = () => {}; // The request watchdog intentionally produces a fetch failure below.
    try {
        const page = setup({
            managedHistory: true,
            cacheTtlMs: 0,
            beforeNavigate: () => true,
            onError: () => failures.push({
                position: history.position,
                index: window.history.state?._serverTabsIndex,
                url: window.location.href,
                html: page.content.innerHTML,
                busy: page.content.getAttribute('aria-busy'),
                pointerEvents: page.content.style.pointerEvents,
            }),
        });
        const navigation = window.AdasiServerTabs.navigate(page.selector, '/page?view=b');
        await settle();
        calls[0].respond({ html: '<input value="b unsaved">', tab: 'annual', url: '/page?view=b' });
        await navigation;
        history.guard(page.selector);
        const originalPosition = history.position;
        const originalIndex = window.history.state._serverTabsIndex;
        const originalUrl = window.location.href;

        window.history.go(-2);
        await settle();
        assert.equal(calls.length, 2);
        assert.equal(page.content.getAttribute('aria-busy'), 'true');
        const watchdog = timers.findLast((timer) => timer.ms === 30000 && !timer.cleared);
        assert.ok(watchdog);
        watchdog.fn();
        await settle();

        assert.deepEqual(history.moves, [-2, 2]);
        assert.deepEqual(failures, [{
            position: originalPosition,
            index: originalIndex,
            url: originalUrl,
            html: '<input value="b unsaved">',
            busy: null,
            pointerEvents: '',
        }], 'The page may resume dirty tracking only after the original URL and position have been restored.');
        assert.equal(page.content.innerHTML, '<input value="b unsaved">');
        assert.equal(page.content.getAttribute('aria-busy'), null);
        assert.equal(calls.length, 2, 'The failed Back must not swap content or retry another GET.');
    } finally {
        console.error = originalConsoleError;
        history.restore();
        restoreNetwork();
    }
});

test('MMT row click, Enter/Space delegation, error retry, and clearMmtSelection work without pushState', async () => {
    const calls = stubNetwork();
    const realDocQuery = globalThis.document.querySelector;
    const realWindowSelection = globalThis.window.getSelection;
    const realLocation = globalThis.window.location;
    const pushCalls = [];
    const realPushState = globalThis.window.history.pushState;
    const realCreateElement = globalThis.document.createElement;

    try {
        globalThis.window.location = { href: 'http://localhost/purchasing/purchase-orders', pathname: '/purchasing/purchase-orders', origin: 'http://localhost' };
        globalThis.window.getSelection = () => ({ toString: () => '' });
        globalThis.window.history.pushState = (...args) => pushCalls.push(args);

        const navEl = makeElement();
        const contentEl = makeElement({ innerHTML: '<p>empty prompt</p>' });
        const overlays = [];
        contentEl.appendChild = (child) => { overlays.push(child); return child; };
        contentEl.querySelector = (sel) => (sel === '.server-tabs-loading-overlay' ? overlays.find((c) => c.className?.includes('loading-overlay')) ?? null : null);

        const satListeners = {};
        const satEl = makeElement({
            id: 'sat-pane',
            hasAttribute: (name) => name === 'data-mmt-satellite',
            addEventListener: (type, fn) => { (satListeners[type] ??= []).push(fn); },
            dispatchEvent: (event) => { for (const fn of satListeners[event.type] ?? []) fn(event); return true; },
            querySelector: (sel) => (
                sel === '[data-server-tabs-nav]' ? navEl
                    : sel === '[data-server-tabs-content]' ? contentEl
                        : null
            ),
        });
        const refBadge = makeElement({ hidden: true });
        const refBar = makeElement({ hidden: true });
        let rowA;
        let rowB;
        const workspace = makeElement({
            dataset: { mmtSatelliteTarget: '#sat-pane' },
            querySelector: (sel) => (
                sel === '[data-mmt-active-ref]' ? refBadge
                    : sel === '[data-mmt-ref-bar]' ? refBar
                        : sel === '[data-mmt-satellite][data-server-tabs-container]' ? satEl
                            : null
            ),
            querySelectorAll: (sel) => (sel === 'tr[data-mmt-row-url].is-mmt-selected'
                ? [rowA, rowB].filter((r) => r.classList.contains('is-mmt-selected'))
                : []),
        });
        const masterPane = makeElement({
            querySelectorAll: (sel) => (sel === 'tr[data-mmt-row-url].is-mmt-selected'
                ? [rowA, rowB].filter((r) => r.classList.contains('is-mmt-selected'))
                : []),
        });
        rowA = makeElement({
            dataset: { mmtRowUrl: '/purchasing/purchase-orders/hashA/satellite', mmtRowRef: 'PO-001' },
            closest: (sel) => (sel === '[data-mmt-workspace]' ? workspace : sel === '[data-mmt-master-pane]' ? masterPane : sel === 'tr[data-mmt-row-url]' ? rowA : null),
        });
        rowB = makeElement({
            dataset: { mmtRowUrl: '/purchasing/purchase-orders/hashB/satellite', mmtRowRef: 'PO-002' },
            closest: (sel) => (sel === '[data-mmt-workspace]' ? workspace : sel === '[data-mmt-master-pane]' ? masterPane : sel === 'tr[data-mmt-row-url]' ? rowB : null),
        });

        containers.set('#sat-pane', satEl);
        globalThis.document.querySelector = (sel) => (
            sel === '#sat-pane' ? satEl
                : sel === '#mmt-ws' ? workspace
                    : realDocQuery(sel)
        );
        globalThis.document.createElement = () => {
            const el = makeElement({ children: [] });
            el.appendChild = (child) => { el.children.push(child); return child; };
            el.querySelector = () => ({});
            return el;
        };

        window.AdasiServerTabs.init('#sat-pane');

        const childButton = {
            closest: (sel) => (sel === 'tr[data-mmt-row-url]' ? rowA : sel.includes('button') ? childButton : null),
        };
        const cellTarget = {
            closest: (sel) => (sel === 'tr[data-mmt-row-url]' ? rowA : null),
        };

        // Clicking an inner button must not hijack MMT selection.
        globalThis.document.dispatchEvent({ type: 'click', target: childButton, defaultPrevented: false });
        assert.equal(calls.length, 0);

        // Clicking row cell activates MMT and loads satellite without pushState.
        globalThis.document.dispatchEvent({ type: 'click', target: cellTarget, defaultPrevented: false });
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, 'http://localhost/purchasing/purchase-orders/hashA/satellite');
        assert.equal(calls[0].options.headers['X-Adasi-Server-Tabs'], '1');
        assert.equal(rowA.classList.contains('is-mmt-selected'), true);
        assert.equal(rowA.getAttribute('aria-selected'), 'true');
        assert.equal(refBadge.textContent, 'PO-001');
        assert.equal(refBadge.hidden, false);

        calls[0].respond({ html: '<p>PO-001 details</p>', nav: '<a>Items</a>', tab: 'items', url: calls[0].url });
        await settle();
        assert.equal(contentEl.innerHTML, '<p>PO-001 details</p>');
        assert.equal(navEl.innerHTML, '<a>Items</a>');
        assert.equal(pushCalls.length, 0);

        // Pressing Enter on row B switches selection and active badge.
        let keyPrevented = false;
        globalThis.document.dispatchEvent({
            type: 'keydown',
            key: 'Enter',
            target: rowB,
            defaultPrevented: false,
            preventDefault() { keyPrevented = true; },
        });
        assert.equal(keyPrevented, true);
        assert.equal(calls.length, 2);
        assert.equal(rowA.classList.contains('is-mmt-selected'), false);
        assert.equal(rowB.classList.contains('is-mmt-selected'), true);
        assert.equal(refBadge.textContent, 'PO-002');
        calls[1].respond({ html: '<p>PO-002 details</p>', nav: '<a>Items</a>', tab: 'items', url: calls[1].url });
        await settle();

        // clearMmtSelection clears highlight, badge, nav, and restores initial prompt.
        window.AdasiServerTabs.clearMmtSelection('#mmt-ws');
        assert.equal(rowB.classList.contains('is-mmt-selected'), false);
        assert.equal(rowB.getAttribute('aria-selected'), 'false');
        assert.equal(refBadge.hidden, true);
        assert.equal(navEl.innerHTML, '');
        assert.equal(contentEl.innerHTML, '<p>empty prompt</p>');

        // Network failure inside [data-mmt-satellite] renders accessible [role="alert"] and allows retry.
        const realConsoleError = console.error;
        console.error = () => {};
        const appended = [];
        contentEl.appendChild = (child) => {
            appended.push(child);
            overlays.push(child);
            return child;
        };
        try {
            globalThis.fetch = async () => ({ ok: false, status: 500 });
            await window.AdasiServerTabs.navigate('#sat-pane', 'http://localhost/purchasing/purchase-orders/hashFail/satellite', { pushHistory: false });
            const alertBox = appended.find((node) => node.getAttribute('role') === 'alert');
            assert.ok(alertBox, 'failed MMT load renders an alert panel');
            const retryButton = alertBox.children.find((node) => node.getAttribute('data-mmt-retry-url'));
            assert.equal(retryButton?.getAttribute('data-mmt-retry-url'), 'http://localhost/purchasing/purchase-orders/hashFail/satellite');
        } finally {
            console.error = realConsoleError;
        }
    } finally {
        globalThis.document.querySelector = realDocQuery;
        globalThis.document.createElement = realCreateElement;
        globalThis.window.getSelection = realWindowSelection;
        globalThis.window.location = realLocation;
        globalThis.window.history.pushState = realPushState;
        restoreNetwork();
    }
});

test('sanitizeServerFragment preserves valid HTML application/json scripts, strips unsafe attributes, escapes angle brackets, and drops foreign-content or executable scripts', async () => {
    const calls = stubNetwork();
    const realDOMParser = globalThis.DOMParser;

    function makeNode(tag, attrs = {}, text = '', namespaceURI = 'http://www.w3.org/1999/xhtml', inForeign = false) {
        const attrMap = new Map(Object.entries(attrs));
        const node = {
            tagName: tag.toUpperCase(),
            namespaceURI,
            textContent: text,
            removed: false,
            get attributes() {
                return Array.from(attrMap.entries()).map(([name, value]) => ({ name, value }));
            },
            getAttribute: (name) => attrMap.get(name) ?? null,
            removeAttribute: (name) => attrMap.delete(name),
            closest: (sel) => (inForeign && sel.includes('svg') ? {} : null),
            remove() { this.removed = true; },
            serialize() {
                if (this.removed) return '';
                const a = Array.from(attrMap.entries()).map(([k, v]) => `${k}="${v}"`).join(' ');
                return `<${tag}${a ? ' ' + a : ''}>${this.textContent}</${tag}>`;
            },
        };
        return node;
    }

    globalThis.DOMParser = class {
        parseFromString() {
            const validJsonScript = makeNode(
                'script',
                { id: 'interSupplierChartPayload', type: 'application/json', 'data-json-config': '1', onload: 'evil()', onerror: 'evil2()' },
                '{"label":"</script><img src=x onerror=alert(1)>","total":500}'
            );
            const invalidJsonScript = makeNode('script', { type: 'application/json' }, '{not-valid-json');
            const executableScript = makeNode('script', { type: 'text/javascript' }, 'evilExec()');
            const svgForeignJsonScript = makeNode(
                'script',
                { type: 'application/json' },
                '{"x":"</script><img src=x onerror=evilSvg()>"}',
                'http://www.w3.org/2000/svg',
                true
            );
            const allScripts = [validJsonScript, invalidJsonScript, executableScript, svgForeignJsonScript];

            return {
                querySelectorAll: (sel) => {
                    if (sel.startsWith('script')) return allScripts.filter((n) => !n.removed);
                    return [];
                },
                body: {
                    querySelectorAll: () => [],
                    get innerHTML() {
                        return '<div>chart</div>' + allScripts.map((n) => n.serialize()).join('');
                    },
                },
            };
        }
    };

    try {
        const page = setup({ cacheTtlMs: 0 });
        page.click(makeTabLink('/page?tab=chart', 'chart'));
        calls[0].respond({ html: '<div>raw</div>', tab: 'chart', url: '/page?tab=chart' });
        await settle();

        const html = page.content.innerHTML;
        assert.equal(html.includes('id="interSupplierChartPayload"'), true, 'valid JSON config script is preserved');
        assert.equal(html.includes('onload='), false, 'event handler attributes on JSON script are stripped');
        assert.equal(html.includes('onerror="evil2()"'), false, 'onerror attribute on JSON script is stripped');
        assert.equal(html.includes('</script><img'), false, 'raw angle brackets inside JSON textContent are escaped to \\u003c');
        assert.equal(html.includes('\\u003c/script\\u003e'), true, 'closing script sequence is safely unicode-escaped');
        assert.equal(html.includes('evilExec()'), false, 'executable script is removed');
        assert.equal(html.includes('evilSvg()'), false, 'SVG foreign JSON script is removed');
    } finally {
        if (realDOMParser) globalThis.DOMParser = realDOMParser; else delete globalThis.DOMParser;
        restoreNetwork();
    }
});

test('server-tabs suppresses duplicate error toast on 401/419 session expiry and includes Retry action on genuine 500 failure', async () => {
    const realConsoleError = console.error;
    const realToast = globalThis.window.AdasiToast;
    const realSessionSync = globalThis.window.AdasiSessionSync;
    const toastCalls = [];
    const sessionResponses = [];
    console.error = () => {};
    globalThis.window.AdasiToast = {
        error: (message, options) => toastCalls.push({ message, options }),
    };
    globalThis.window.AdasiSessionSync = {
        handleActionResponse: (response) => sessionResponses.push(response.status),
        isSessionExpired: () => false,
    };

    try {
        stubNetwork();
        const page = setup({ cacheTtlMs: 0, timeoutMs: 8000 });
        page.container.id = page.selector.slice(1);

        // 1. 401 session expiry delegates to AdasiSessionSync without firing duplicate error toast.
        globalThis.fetch = async () => ({ ok: false, status: 401, redirected: false, url: 'http://localhost/page?tab=expired' });
        const expiredOk = await window.AdasiServerTabs.navigate(page.selector, '/page?tab=expired', { pushHistory: false });
        assert.equal(expiredOk, false);
        assert.deepEqual(sessionResponses, [401]);
        assert.equal(toastCalls.length, 0, 'must not show redundant error toast on 401 session expiry');

        // 2. Genuine 500 error displays error toast with actionable Retry button.
        globalThis.fetch = async () => ({ ok: false, status: 500, redirected: false, url: 'http://localhost/page?tab=err' });
        const failedOk = await window.AdasiServerTabs.navigate(page.selector, '/page?tab=err', { pushHistory: false });
        assert.equal(failedOk, false);
        assert.equal(toastCalls.length, 1);
        assert.equal(Array.isArray(toastCalls[0].options?.actions), true);
        assert.equal(typeof toastCalls[0].options.actions[0].onClick, 'function');
    } finally {
        console.error = realConsoleError;
        globalThis.window.AdasiToast = realToast;
        if (realSessionSync === undefined) delete globalThis.window.AdasiSessionSync;
        else globalThis.window.AdasiSessionSync = realSessionSync;
        restoreNetwork();
    }
});
