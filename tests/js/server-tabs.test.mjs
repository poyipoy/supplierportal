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

globalThis.window = {
    history: { state: null, pushState() {}, replaceState() {} },
    location: { href: 'http://localhost/page', pathname: '/page' },
    addEventListener() {},
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
        assert.equal(navTabB.classList.contains('tw-bg-primary'), false, 'nav tabs are styled by aria-current, not by JS class lists');
        assert.equal(legacyPill.classList.contains('tw-bg-primary'), true, 'DRP/comparison pills keep the old behaviour');
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
