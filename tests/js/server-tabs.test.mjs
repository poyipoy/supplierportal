import test from 'node:test';
import assert from 'node:assert/strict';

// server-tabs.js only touches a handful of DOM members, so a small hand-rolled fake is enough (no DOM library installed).
function makeClassList() {
    const classes = new Set();
    return {
        add: (...names) => names.forEach((name) => classes.add(name)),
        remove: (...names) => names.forEach((name) => classes.delete(name)),
        contains: (name) => classes.has(name),
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
    addEventListener() {},
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

        // The 15s watchdog of B must still be able to cancel B.
        const watchdogs = timers.filter((timer) => timer.ms === 15000 && !timer.cleared);
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

        page.hover(makeTabLink('/page?tab=prefetched', 'prefetched'));
        const debounce = timers.find((timer) => timer.ms === 60);
        debounce.fn();
        await settle();
        assert.equal(calls[1].url, '/page?tab=prefetched');
        assert.equal(calls[1].options.headers['X-Adasi-Server-Tabs'], '1');
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
        const watchdog = timers.findLast((timer) => timer.ms === 15000 && !timer.cleared);
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
