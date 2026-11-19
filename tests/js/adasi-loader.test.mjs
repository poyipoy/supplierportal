import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    MIN_VISIBLE,
    NAV_RELEASE,
    SHOW_DELAY,
    SLOW_AFTER,
    createDomView,
    createLoaderController,
    isDataTableRequest,
    isFullPageNavigation,
    isPdfDownloadLink,
    downloadFileWithLoader,
    setTableBusy,
} from '../../resources/js/adasi-loader.js';

function fakeTimers() {
    let current = 0;
    let seq = 0;
    const queue = new Map();

    return {
        setTimeout(fn, ms) {
            seq += 1;
            queue.set(seq, { fn, at: current + ms });
            return seq;
        },
        clearTimeout(id) {
            queue.delete(id);
        },
        now: () => current,
        advance(ms) {
            const target = current + ms;
            for (;;) {
                let next = null;
                for (const [id, entry] of queue) {
                    if (entry.at <= target && (next === null || entry.at < queue.get(next).at)) next = id;
                }
                if (next === null) break;
                const entry = queue.get(next);
                queue.delete(next);
                current = entry.at;
                entry.fn();
            }
            current = target;
        },
    };
}

function setup() {
    const timers = fakeTimers();
    const calls = [];
    const view = {
        activate: () => calls.push('activate'),
        deactivate: () => calls.push('deactivate'),
        showSlow: () => calls.push('slow'),
    };

    return { timers, calls, loader: createLoaderController({ view, timers }) };
}

test('requests faster than the show delay never display the overlay', () => {
    const { timers, calls, loader } = setup();
    const token = loader.show();

    timers.advance(SHOW_DELAY - 1);
    loader.hide(token);
    timers.advance(1000);

    assert.deepEqual(calls, []);
    assert.equal(loader.pendingCount(), 0);
});

test('the overlay appears after the delay and stays for the minimum visible time', () => {
    const { timers, calls, loader } = setup();
    const token = loader.show();

    timers.advance(SHOW_DELAY);
    assert.deepEqual(calls, ['activate']);

    timers.advance(50);
    loader.hide(token);
    assert.deepEqual(calls, ['activate'], 'hide is deferred until the minimum visible time passed');

    timers.advance(MIN_VISIBLE - 50);
    assert.deepEqual(calls, ['activate', 'deactivate']);
});

test('hide after the minimum visible time deactivates immediately', () => {
    const { timers, calls, loader } = setup();
    const token = loader.show();

    timers.advance(SHOW_DELAY + MIN_VISIBLE);
    loader.hide(token);

    assert.deepEqual(calls, ['activate', 'deactivate']);
});

test('immediate hide skips the minimum visible time', () => {
    const { timers, calls, loader } = setup();
    const token = loader.show();

    timers.advance(SHOW_DELAY);
    loader.hide(token, true);

    assert.deepEqual(calls, ['activate', 'deactivate']);
});

test('tokens are idempotent: unknown and repeated hides do not release other requests', () => {
    const { timers, calls, loader } = setup();
    const first = loader.show();
    const second = loader.show();

    timers.advance(SHOW_DELAY + MIN_VISIBLE);
    loader.hide(first);
    loader.hide(first);
    loader.hide(9999);

    assert.equal(loader.pendingCount(), 1);
    assert.deepEqual(calls, ['activate']);

    loader.hide(second);
    assert.deepEqual(calls, ['activate', 'deactivate']);
});

test('a navigation safety release only frees its own token, not later requests', () => {
    const { timers, calls, loader } = setup();

    loader.trackNavigation();
    timers.advance(NAV_RELEASE - 1000);
    const ajax = loader.show();

    timers.advance(1000);
    assert.equal(loader.pendingCount(), 1, 'navigation token released, ajax token kept');
    assert.equal(loader.isActive(), true);

    loader.hide(ajax);
    timers.advance(MIN_VISIBLE);
    assert.deepEqual(calls, ['activate', 'slow', 'deactivate']);
});

test('navigation remains active through slow transitions and triggers showSlow at SLOW_AFTER before NAV_RELEASE', () => {
    const { timers, calls, loader } = setup();

    loader.trackNavigation();
    timers.advance(SHOW_DELAY);
    assert.deepEqual(calls, ['activate']);

    // Advance 10 seconds (the old premature release threshold): it MUST STILL be active!
    timers.advance(10000);
    assert.equal(loader.isActive(), true, 'loader must not stop at the old 10s mark');
    assert.equal(loader.pendingCount(), 1);

    // Advance to SLOW_AFTER (20s from activation): triggers slow watchdog with dismiss button
    timers.advance(SLOW_AFTER - 10000);
    assert.deepEqual(calls, ['activate', 'slow'], 'slow watchdog must trigger at 20s while navigation is still active');
    assert.equal(loader.isActive(), true);

    // Advance to NAV_RELEASE (60s total): safety watchdog releases token
    timers.advance(NAV_RELEASE - SLOW_AFTER);
    assert.deepEqual(calls, ['activate', 'slow', 'deactivate'], 'navigation safety release only frees after NAV_RELEASE (60s)');
    assert.equal(loader.isActive(), false);
});

test('loader.reset calls window.AdasiButton.resetAll if present', () => {
    const { loader } = setup();
    let resetAllCalled = false;
    const originalWindow = globalThis.window;
    globalThis.window = {
        AdasiButton: {
            resetAll() {
                resetAllCalled = true;
            },
        },
    };

    try {
        loader.show();
        loader.reset();
        assert.equal(resetAllCalled, true, 'AdasiButton.resetAll must be called on loader reset');
    } finally {
        globalThis.window = originalWindow;
    }
});

test('a new request during the deferred hide keeps the overlay active', () => {
    const { timers, calls, loader } = setup();
    const first = loader.show();

    timers.advance(SHOW_DELAY + 50);
    loader.hide(first);
    const second = loader.show();
    timers.advance(MIN_VISIBLE);

    assert.deepEqual(calls, ['activate']);
    loader.hide(second);
    timers.advance(MIN_VISIBLE);
    assert.deepEqual(calls, ['activate', 'deactivate']);
});

test('the watchdog offers a way out after SLOW_AFTER and is cleared on hide', () => {
    const slow = setup();
    slow.loader.show();
    slow.timers.advance(SHOW_DELAY);
    slow.timers.advance(SLOW_AFTER);
    assert.deepEqual(slow.calls, ['activate', 'slow']);

    const quick = setup();
    const token = quick.loader.show();
    quick.timers.advance(SHOW_DELAY + MIN_VISIBLE);
    quick.loader.hide(token);
    quick.timers.advance(SLOW_AFTER * 2);
    assert.deepEqual(quick.calls, ['activate', 'deactivate']);
});

test('reset drops every token and hides at once', () => {
    const { timers, calls, loader } = setup();
    loader.show();
    loader.show();
    timers.advance(SHOW_DELAY);

    loader.reset();

    assert.equal(loader.pendingCount(), 0);
    assert.equal(loader.isActive(), false);
    assert.deepEqual(calls, ['activate', 'deactivate']);

    timers.advance(SLOW_AFTER);
    assert.deepEqual(calls, ['activate', 'deactivate'], 'no watchdog after reset');
});

test('DataTables requests are recognised by their draw counter', () => {
    assert.equal(isDataTableRequest({ data: 'draw=3&columns%5B0%5D%5Bdata%5D=id' }), true);
    assert.equal(isDataTableRequest({ data: 'search=x&draw=2' }), true);
    assert.equal(isDataTableRequest({ data: { draw: 1 } }), true);
    assert.equal(isDataTableRequest({ data: 'q=steel&redraw=1' }), false);
    assert.equal(isDataTableRequest({ data: { q: 'steel' } }), false);
    assert.equal(isDataTableRequest({}), false);
});

test('the DOM overlay is built at boot and activation reuses it', () => {
    const doc = fakeDocument();
    const view = createDomView(doc, (key) => key);
    view.bind(() => {});

    view.mount();
    view.mount();
    assert.equal(doc.body.children.length, 1, 'mount builds the overlay exactly once');
    assert.equal(doc.body.children[0].id, 'adasiLoader');

    view.activate();
    assert.equal(doc.body.children.length, 1, 'activation reuses the mounted overlay');
});

test('splash owns presentation while loader tokens remain intact, then pending work takes over', () => {
    const doc = fakeDocument();
    const listeners = new Map();
    const actions = [];
    let waiting = true;
    let visual = true;
    doc.defaultView = {
        AdasiAuthSplash: {
            isWaiting: () => waiting,
            hasVisual: () => visual,
            removeVisual: () => { visual = false; actions.push('splash-removed'); },
        },
        addEventListener: (name, callback) => listeners.set(name, callback),
    };
    const timers = fakeTimers();
    const view = createDomView(doc, key => key);
    const loader = createLoaderController({ view, timers });
    const token = loader.show();
    timers.advance(SHOW_DELAY);
    const overlay = doc.body.children[0];
    assert.equal(loader.pendingCount(), 1);
    assert.equal(overlay.classList.contains('active'), false);
    assert.equal(doc.body.attributes['aria-busy'], undefined);
    waiting = false;
    listeners.get('adasi:auth-splash-released')();
    assert.deepEqual(actions, ['splash-removed']);
    assert.equal(overlay.classList.contains('active'), true);
    assert.equal(loader.pendingCount(), 1);
    loader.hide(token, true);
    assert.equal(overlay.classList.contains('active'), false);
});

test('requests completed under splash never flash or clear state they did not acquire', () => {
    const doc = fakeDocument();
    let released;
    doc.body.setAttribute('aria-busy', 'original');
    doc.defaultView = {
        AdasiAuthSplash: { isWaiting: () => true, hasVisual: () => true, removeVisual() { throw Error('must not hand off completed work'); } },
        addEventListener: (name, callback) => { if (name === 'adasi:auth-splash-released') released = callback; },
    };
    const view = createDomView(doc, key => key);
    view.activate();
    view.showSlow();
    view.deactivate();
    released();
    assert.equal(doc.body.attributes['aria-busy'], 'original');
    assert.equal(doc.body.children[0].classList.contains('active'), false);
});

test('foreground loader activation during cosmetic fade removes splash first', () => {
    const doc = fakeDocument();
    let removed = false;
    doc.defaultView = {
        AdasiAuthSplash: { isWaiting: () => false, hasVisual: () => true, removeVisual: () => { removed = true; } },
        addEventListener() {},
    };
    const view = createDomView(doc, key => key);
    view.activate();
    assert.equal(removed, true);
    assert.equal(doc.body.children[0].classList.contains('active'), true);
});

test('completed suppressed work does not flash during minimum visibility, and new work still appears', () => {
    const doc = fakeDocument();
    let waiting = true;
    let released;
    doc.defaultView = {
        AdasiAuthSplash: { isWaiting: () => waiting, hasVisual: () => false },
        addEventListener: (name, callback) => { if (name === 'adasi:auth-splash-released') released = callback; },
    };
    const view = createDomView(doc, key => key);
    const timers = fakeTimers();
    const loader = createLoaderController({ view, timers });
    view.bind(() => loader.reset(), () => loader.pendingCount() > 0);
    const first = loader.show();
    timers.advance(SHOW_DELAY + 50);
    loader.hide(first);
    waiting = false;
    released();
    assert.equal(doc.body.children[0].classList.contains('active'), false);
    const second = loader.show();
    assert.equal(doc.body.children[0].classList.contains('active'), true);
    loader.hide(second, true);
});

test('loader handoff preserves a closed sidebar inert state and preexisting body busy state', () => {
    const doc = fakeDocument();
    const sidebar = { inert: true };
    doc.querySelectorAll = () => [sidebar];
    doc.body.setAttribute('aria-busy', 'false');
    const view = createDomView(doc, key => key);
    view.activate();
    assert.equal(doc.body.attributes['aria-busy'], 'true');
    view.deactivate();
    assert.equal(sidebar.inert, true, 'a closed mobile sidebar must stay inaccessible after foreground work settles');
    assert.equal(doc.body.attributes['aria-busy'], 'false');
});

test('isPdfDownloadLink identifies PDF and receipt download links', () => {
    const win = { location: { href: 'http://localhost/supplier/local/invoices/123/receipt', origin: 'http://localhost' } };

    const mockLink = (props = {}) => ({
        href: props.href || 'http://localhost/test',
        target: props.target || '',
        attributes: props.attributes || {},
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); },
        getAttribute(name) { return this.attributes[name] ?? null; },
    });

    // Receipt print URLs should match
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/supplier/local/invoices/123/receipt/print' }), win), true);
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/finance/invoices/123/receipt/print' }), win), true);
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/ga/claims/456/receipt/print' }), win), true);

    // Links with data-download-pdf or data-pdf-download should match
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/custom-pdf', attributes: { 'data-download-pdf': '' } }), win), true);
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/custom-pdf', attributes: { 'data-pdf-download': '' } }), win), true);

    // Download query param on print routes should match
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/finance/vouchers/123/print?download=1' }), win), true);

    // target="_blank" should NOT be intercepted (user explicitly wants new tab)
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/supplier/local/invoices/123/receipt/print', target: '_blank' }), win), false);

    // Standard HTML pages should not match
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/supplier/local/invoices/123' }), win), false);
    assert.equal(isPdfDownloadLink(mockLink({ href: 'http://localhost/dashboard' }), win), false);

    // Cross-origin links should not match
    assert.equal(isPdfDownloadLink(mockLink({ href: 'https://example.com/receipt/print' }), win), false);
});

test('isFullPageNavigation excludes downloads, PDF print routes, and non-navigating links', () => {
    const win = {
        location: {
            href: 'http://localhost/supplier/local/invoices/123/receipt',
            origin: 'http://localhost',
            pathname: '/supplier/local/invoices/123/receipt',
            search: '',
        },
    };

    const mockLink = (props = {}) => ({
        href: props.href || 'http://localhost/supplier/local/invoices',
        target: props.target || '',
        attributes: props.attributes || {},
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); },
        getAttribute(name) { return this.attributes[name] ?? null; },
    });

    // Real navigation to another page should be true
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/supplier/local/invoices' }), win), true);

    // Receipt print route should be false (file download, does not unload page)
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/supplier/local/invoices/123/receipt/print' }), win), false);

    // Links with download attribute should be false
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/export', attributes: { download: '' } }), win), false);

    // Links with data-no-loader, data-download-pdf, or data-pdf-download should be false
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/nav', attributes: { 'data-no-loader': '' } }), win), false);
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/nav', attributes: { 'data-download-pdf': '' } }), win), false);
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/nav', attributes: { 'data-pdf-download': '' } }), win), false);

    // Direct binary file extensions should be false
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/files/report.pdf' }), win), false);
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/files/export.xlsx' }), win), false);
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/files/data.csv' }), win), false);

    // URL with download query param should be false
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/vouchers/1/print?download=1' }), win), false);

    // Target _blank should be false
    assert.equal(isFullPageNavigation(mockLink({ href: 'http://localhost/supplier/local/invoices', target: '_blank' }), win), false);
});

test('downloadFileWithLoader activates loader and hides it immediately after download completes', async () => {
    const { loader } = setup();

    let appendedElement = null;
    let clicked = false;
    let revoked = false;

    const fakeAnchor = {
        href: '',
        download: '',
        click() { clicked = true; },
        remove() {},
    };

    const win = {
        location: { href: 'http://localhost' },
        fetch: async () => ({
            ok: true,
            status: 200,
            headers: new Map([
                ['content-disposition', 'attachment; filename="Receipt_REC-2026-0001.pdf"'],
            ]),
            blob: async () => ({ size: 1024, type: 'application/pdf' }),
        }),
        URL: {
            createObjectURL: () => 'blob:http://localhost/test-blob-uuid',
            revokeObjectURL: () => { revoked = true; },
        },
        document: {
            body: {
                appendChild: (el) => { appendedElement = el; return el; },
            },
            createElement: (tag) => tag === 'a' ? fakeAnchor : {},
        },
        setTimeout: (fn) => fn(),
    };

    const link = {
        href: 'http://localhost/supplier/local/invoices/123/receipt/print',
        dataset: {},
        hasAttribute: () => false,
        getAttribute: () => null,
    };

    assert.equal(loader.isActive(), false);
    await downloadFileWithLoader(link, loader, win);

    assert.equal(clicked, true, 'download link should have been clicked');
    assert.equal(fakeAnchor.download, 'Receipt_REC-2026-0001.pdf', 'filename should be parsed from disposition header');
    assert.equal(link.dataset.downloadActive, undefined, 'downloadActive should be cleared');
    assert.equal(loader.isActive(), false, 'loader must be inactive after download completes');
    assert.equal(loader.pendingCount(), 0, 'no pending loader tokens remaining');
});

test('downloadFileWithLoader hides loader and reports error when fetch fails', async () => {
    const { loader } = setup();
    let toastErrorCalled = false;

    const win = {
        location: { href: 'http://localhost' },
        fetch: async () => ({
            ok: false,
            status: 500,
            headers: new Map(),
            blob: async () => null,
        }),
        AdasiToast: {
            error: () => { toastErrorCalled = true; },
        },
        AdasiI18n: {
            t: (key) => key,
        },
    };

    const link = {
        href: 'http://localhost/supplier/local/invoices/123/receipt/print',
        dataset: {},
        hasAttribute: () => false,
        getAttribute: () => null,
    };

    await downloadFileWithLoader(link, loader, win);

    assert.equal(toastErrorCalled, true, 'AdasiToast.error must be called on download failure');
    assert.equal(link.dataset.downloadActive, undefined, 'downloadActive should be cleared on failure');
    assert.equal(loader.isActive(), false, 'loader must be inactive after error');
    assert.equal(loader.pendingCount(), 0, 'no pending loader tokens remaining after error');
});

test('createLoaderController honors custom showDelay from runtime UI config', () => {
    const timers = fakeTimers();
    let active = false;
    const loader = createLoaderController({
        timers,
        showDelay: 4200,
        view: {
            activate: () => { active = true; },
            deactivate: () => { active = false; },
            showSlow: () => {},
        },
    });

    const token = loader.show();
    timers.advance(2500);
    assert.equal(active, false, 'loader must not show at default 2500ms when configured to 4200ms');

    timers.advance(1699);
    assert.equal(active, false);

    timers.advance(1);
    assert.equal(active, true, 'loader shows once configured 4200ms threshold elapses');
    loader.hide(token, true);
});

test('createDomView mounts loader inside workspace stage only and keeps sidebar/topbar interactive in workspace shell', () => {
    const base = fakeDocument();
    const sidebar = { inert: false };
    const topbar = { inert: false };
    const framesHost = {
        inert: false,
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] ?? null; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    const stageChildren = [];
    const stage = {
        children: stageChildren,
        appendChild(child) { stageChildren.push(child); child.parentElement = stage; return child; },
        querySelector(sel) { return sel === '[data-workspace-frames]' ? framesHost : null; },
    };
    const doc = {
        ...base,
        documentElement: { hasAttribute: (name) => name === 'data-workspace-shell' },
        activeElement: { id: 'sidebar-link', closest: () => null },
        querySelector: (sel) => (sel === '[data-workspace-stage]' ? stage : null),
        querySelectorAll: (sel) => (sel.includes('#sidebar') ? [sidebar, topbar] : []),
    };

    const view = createDomView(doc, (k) => k);
    view.bind(() => {});
    view.mount();

    assert.equal(base.body.children.length, 0, 'outer shell loader must not mount on body');
    assert.equal(stageChildren.length, 1, 'loader must mount inside [data-workspace-stage]');
    assert.equal(stageChildren[0].className.includes('adasi-loader-overlay--stage'), true);

    view.activate();
    assert.equal(sidebar.inert, false, 'sidebar must remain interactive while iframe stage loads');
    assert.equal(topbar.inert, false, 'top navbar must remain interactive while iframe stage loads');
    assert.equal(framesHost.inert, true, 'only [data-workspace-frames] is marked inert inside stage');
    assert.equal(framesHost.attributes['aria-busy'], 'true');
    assert.equal(base.body.attributes['aria-busy'], undefined);

    view.deactivate();
    assert.equal(framesHost.inert, false);
    assert.equal(framesHost.attributes['aria-busy'], undefined);
});

test('createDomView renders precision dual-arc SVG medallion with aria-hidden and ui-focus-ring dismiss button', () => {
    const doc = fakeDocument();
    const view = createDomView(doc, (k) => k);
    view.bind(() => {});
    view.mount();

    const overlay = doc.body.children[0];
    assert.equal(overlay.innerHTML.includes('class="adasi-loader-ring" aria-hidden="true"'), true);
    assert.equal(overlay.innerHTML.includes('class="adasi-loader-svg"'), true);
    assert.equal(overlay.innerHTML.includes('adasi-loader-arc--primary'), true);
    assert.equal(overlay.innerHTML.includes('adasi-loader-arc--secondary'), true);
    assert.equal(overlay.innerHTML.includes('adasi-loader-medallion'), true);
    assert.equal(overlay.innerHTML.includes('adasi-loader-logo'), true);
    assert.equal(overlay.innerHTML.includes('adasi-loader-dismiss ui-focus-ring'), true);
});

test('Escape dismisses the loader only when the slow-state close button is visible', () => {
    const keydownHandlers = [];
    const doc = fakeDocument();
    doc.addEventListener = (name, fn) => {
        if (name === 'keydown') keydownHandlers.push(fn);
    };

    const dismissButton = {
        hidden: true,
        textContent: '',
        addEventListener() {},
        focus() {},
    };
    const origCreateElement = doc.createElement;
    doc.createElement = (tag) => {
        const el = origCreateElement(tag);
        el.querySelector = (sel) => (sel === '.adasi-loader-dismiss' ? dismissButton : origCreateElement('stub'));
        return el;
    };

    let dismissed = 0;
    const view = createDomView(doc, (k) => k);
    view.bind(() => { dismissed += 1; });

    view.activate();
    assert.equal(dismissButton.hidden, true);

    // Normal loading: Escape must be ignored.
    let prevented = false;
    keydownHandlers.forEach((fn) => fn({ key: 'Escape', preventDefault() { prevented = true; } }));
    assert.equal(dismissed, 0);
    assert.equal(prevented, false);

    // Slow loading: Close button is shown -> Escape dismisses cleanly.
    view.showSlow();
    assert.equal(dismissButton.hidden, false);
    keydownHandlers.forEach((fn) => fn({ key: 'Escape', preventDefault() { prevented = true; } }));
    assert.equal(dismissed, 1);
    assert.equal(prevented, true);
});

test('app.css enforces dual-arc SVG loader tokens, pause-when-hidden, dark elevation, high contrast, and reduced-motion', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const css = readFileSync(resolve(testDir, '../../resources/css/app.css'), 'utf8');

    assert.equal(css.includes('.adasi-loader-arc--primary'), true);
    assert.equal(css.includes('.adasi-loader-arc--secondary'), true);
    assert.equal(css.includes('animation-play-state: paused;'), true);
    assert.equal(css.includes('animation-play-state: running;'), true);
    assert.equal(css.includes(':root[data-theme="dark"] .adasi-loader-card'), true);
    assert.equal(css.includes('background: var(--ui-dialog-bg);'), true);
    assert.equal(css.includes(':root[data-theme="dark"] .adasi-loader-medallion'), true);

    const textRule = css.match(/\.adasi-loader-text\s*\{[^}]*\}/);
    assert.ok(textRule && textRule[0].includes('color: var(--md-on-surface);'), '.adasi-loader-text must use high-contrast --md-on-surface');

    const reducedMotion = css.match(/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[\s\S]*?\.adasi-loader-arc--primary[\s\S]*?\n\}/);
    assert.ok(reducedMotion && reducedMotion[0].includes('animation: none;'), 'reduced motion must halt SVG loader animations');
    assert.equal(css.includes('.ui-dt-loading-bar'), true);
    assert.equal(css.includes('.ui-dt-loading-pill'), true);
    assert.equal(css.includes(':root[data-theme="dark"] .ui-dt-loading-pill'), true);
    assert.equal(css.includes('180ms'), true, 'table loading pill must apply anti-flicker enter delay');
});

test('setTableBusy mounts clipped progress bar and status pill once and toggles aria-busy cleanly', () => {
    const doc = fakeDocument();
    const labelNode = { textContent: '' };
    const origCreate = doc.createElement;
    doc.createElement = (tag) => {
        const el = origCreate(tag);
        el.querySelector = (sel) => (sel === '.ui-dt-loading-pill__text' ? labelNode : null);
        return el;
    };

    const wrapper = {
        ownerDocument: doc,
        children: [],
        attributes: {},
        classList: {
            names: new Set(),
            toggle(name, force) {
                if (force) this.names.add(name);
                else this.names.delete(name);
            },
            contains(name) { return this.names.has(name); },
        },
        setAttribute(k, v) { this.attributes[k] = String(v); },
        removeAttribute(k) { delete this.attributes[k]; },
        appendChild(child) { this.children.push(child); return child; },
        querySelector(sel) {
            if (sel.includes('.ui-dt-loading-bar')) return this.children.find((c) => c.className === 'ui-dt-loading-bar') ?? null;
            if (sel.includes('.ui-dt-loading-pill')) return this.children.find((c) => c.className === 'ui-dt-loading-pill') ?? null;
            return null;
        },
    };

    setTableBusy(wrapper, true, () => 'Loading');
    setTableBusy(wrapper, true, () => 'Loading');

    assert.equal(wrapper.children.length, 2, 'bar and pill are mounted once');
    assert.equal(wrapper.classList.contains('is-loading'), true);
    assert.equal(wrapper.attributes['aria-busy'], 'true');
    assert.equal(labelNode.textContent, 'Loading');

    setTableBusy(wrapper, false, () => 'Loading');
    assert.equal(wrapper.classList.contains('is-loading'), false);
    assert.equal(wrapper.attributes['aria-busy'], undefined);
    assert.equal(labelNode.textContent, '');
});

/** Just enough DOM for createDomView: nodes are plain objects, unknown queries return an inert stub. */
function fakeDocument() {
    const node = (tag) => ({
        tag,
        id: '',
        className: '',
        textContent: '',
        innerHTML: '',
        hidden: false,
        children: [],
        attributes: {},
        classList: {
            names: new Set(),
            add(name) { this.names.add(name); },
            remove(name) { this.names.delete(name); },
            contains(name) { return this.names.has(name); },
        },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] ?? null; },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener() {},
        appendChild(child) { this.children.push(child); return child; },
        querySelector() { return node('stub'); },
        focus() {},
    });
    const body = node('body');

    return { body, activeElement: null, createElement: node, querySelectorAll: () => [] };
}
