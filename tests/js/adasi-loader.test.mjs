import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MIN_VISIBLE,
    NAV_RELEASE,
    SHOW_DELAY,
    SLOW_AFTER,
    createDomView,
    createLoaderController,
    isDataTableRequest,
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
    assert.deepEqual(calls, ['activate', 'deactivate']);
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
        classList: { add() {}, remove() {} },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener() {},
        appendChild(child) { this.children.push(child); return child; },
        querySelector() { return node('stub'); },
        focus() {},
    });
    const body = node('body');

    return { body, activeElement: null, createElement: node, querySelectorAll: () => [] };
}
