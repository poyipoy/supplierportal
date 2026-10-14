import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import vm from 'node:vm';

const sourcePath = new URL('../../resources/js/auth-splash.js', import.meta.url);
const source = existsSync(sourcePath) ? readFileSync(sourcePath, 'utf8') : '';

function environment({ mode = 'core', reduced = false, body = true, css = true, parsed = false, failFocus = false, autofocus = false, holdFrames = false } = {}) {
    let now = 0;
    let sequence = 0;
    const queue = new Map();
    const observations = [];
    const mutations = [];
    const events = [];
    const changes = [];
    const target = () => ({
        listeners: new Map(),
        addEventListener(type, callback) {
            if (!this.listeners.has(type)) this.listeners.set(type, new Set());
            this.listeners.get(type).add(callback);
        },
        removeEventListener(type, callback) { this.listeners.get(type)?.delete(callback); },
        dispatchEvent(event) {
            event.target ??= this;
            event.preventDefault ??= () => { event.defaultPrevented = true; };
            event.stopPropagation ??= () => {};
            event.stopImmediatePropagation ??= () => {};
            for (const callback of [...(this.listeners.get(event.type) || [])]) callback(event);
            return !event.defaultPrevented;
        },
    });
    const document = Object.assign(target(), { readyState: 'loading', hidden: false });
    const node = (tag, attributes = {}) => {
        const element = Object.assign(target(), {
            tagName: tag.toUpperCase(), attributes: { ...attributes }, children: [], dataset: {},
            style: { setProperty(name, value) { this[name] = value; } }, hidden: false, disabled: false,
            isConnected: true, animationCancellations: 0,
            getAttribute(name) { return this.attributes[name] ?? null; },
            hasAttribute(name) { return name in this.attributes; },
            setAttribute(name, value) {
                mutations.push({ type: 'attributes', attributeName: name, target: this, oldValue: this.getAttribute(name) });
                this.attributes[name] = String(value);
            },
            removeAttribute(name) {
                if (this.hasAttribute(name)) mutations.push({ type: 'attributes', attributeName: name, target: this, oldValue: this.getAttribute(name) });
                delete this.attributes[name];
            },
            appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
            contains(child) { return this === child || this.children.some(n => n.contains(child)); },
            matches(selector) {
                return selector.split(',').some(part => {
                    part = part.trim();
                    if (part.startsWith('#')) return this.attributes.id === part.slice(1);
                    if (part.startsWith('.')) return this.classList.contains(part.slice(1));
                    const attrs = [...part.matchAll(/\[([^=\]]+)(?:=["']?([^"'\]]+)["']?)?\]/g)];
                    const tagPart = part.split('[')[0];
                    return (!tagPart || tagPart.toUpperCase() === this.tagName)
                        && attrs.every(([, key, value]) => this.hasAttribute(key) && (value === undefined || this.getAttribute(key) === value));
                });
            },
            querySelectorAll(selector) {
                return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
            },
            querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
            focus() { document.activeElement = this; document.dispatchEvent({ type: 'focusin', target: this }); },
            remove() {
                this.isConnected = false;
                if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this);
            },
            getAnimations() { return [{ cancel: () => this.animationCancellations++ }]; },
        });
        const classes = new Set((attributes.class || '').split(' ').filter(Boolean));
        element.classList = {
            add(...names) { names.forEach(name => { classes.add(name); changes.push({ name, at: now, element }); }); },
            remove(...names) { names.forEach(name => classes.delete(name)); },
            contains(name) { return classes.has(name); },
        };
        Object.defineProperty(element, 'inert', {
            get: () => element.hasAttribute('inert'),
            set: value => value ? element.setAttribute('inert', '') : element.removeAttribute('inert'),
        });
        Object.defineProperty(element, 'id', { get: () => element.getAttribute('id') || '' });
        return element;
    };
    const root = node('body');
    document.body = body ? root : null;
    document.documentElement = node('html');
    document.activeElement = root;
    document.querySelector = selector => root.matches(selector) ? root : root.querySelector(selector);
    document.querySelectorAll = selector => root.querySelectorAll(selector);
    document.currentScript = { dataset: { hardTimeout: '10000', slowAfter: '2000', fadeMs: '650', cleanupMs: '750', layout: mode === 'native-login' ? 'auth' : 'app', readiness: mode } };
    const overlay = node('div', { 'data-auth-splash': '', class: 'auth-splash', 'aria-hidden': 'true' });
    overlay.hidden = true;
    overlay.dataset = { splashReason: 'post-login' };
    const panel = overlay.appendChild(node('div', { class: 'auth-splash__panel', tabindex: '-1' }));
    const letters = [...'ADASI'].map(letter => {
        const el = panel.appendChild(node('span', { class: 'auth-splash__letter' }));
        el.textContent = letter;
        return el;
    });
    const status = overlay.appendChild(node('span', { 'data-auth-splash-status': '' }));
    const skip = overlay.appendChild(node('button', { 'data-auth-splash-skip': '' }));
    skip.hidden = true;
    const workspace = node('main', { id: 'main-content' });
    const form = workspace.appendChild(node('form', { method: 'POST', action: '/login' }));
    const email = form.appendChild(node('input', { id: 'email', name: 'email', type: 'email' }));
    const password = form.appendChild(node('input', { id: 'password', name: 'password', type: 'password' }));
    const submit = form.appendChild(node('button', { type: 'submit' }));
    if (autofocus) document.activeElement = email;
    if (failFocus) panel.focus = () => { throw new Error('focus setup failed'); };
    const sentinel = node('template', { 'data-auth-splash-layout-ready': mode === 'native-login' ? 'auth' : 'app' });
    sentinel.dataset.readiness = mode;
    if (body) {
        root.appendChild(overlay);
        root.appendChild(workspace);
        if (parsed) root.appendChild(sentinel);
    }
    const motion = Object.assign(target(), { matches: reduced });
    const setTimeout = (callback, delay = 0) => {
        const id = ++sequence;
        queue.set(id, { callback, at: now + delay });
        return id;
    };
    const window = Object.assign(target(), {
        document, Element: Object, HTMLElement: Object,
        setTimeout, clearTimeout: id => queue.delete(id),
        requestAnimationFrame: callback => holdFrames ? ++sequence : setTimeout(callback, 16), cancelAnimationFrame: id => queue.delete(id),
        performance: { now: () => now, mark() {}, measure() {} },
        matchMedia: () => motion,
        getComputedStyle: element => ({ transform: 'matrix(1, 0, 0, 1, 0, 42)', getPropertyValue: () => css ? '1' : '' }),
        CustomEvent: class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } },
        MutationObserver: class {
            constructor(callback) { this.callback = callback; observations.push(this); }
            observe() { this.active = true; }
            disconnect() { this.active = false; }
        },
    });
    const dispatch = window.dispatchEvent;
    window.dispatchEvent = function (event) { events.push(event); return dispatch.call(this, event); };
    const context = vm.createContext({ window, document, console, performance: window.performance,
        MutationObserver: window.MutationObserver, CustomEvent: window.CustomEvent, HTMLElement: window.HTMLElement });
    const boot = () => vm.runInContext(source, context);
    const mutate = () => {
        const records = mutations.splice(0);
        observations.filter(o => o.active).forEach(o => o.callback(records));
    };
    const advance = ms => {
        const end = now + ms;
        for (;;) {
            const next = [...queue].filter(([, value]) => value.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (!next) break;
            queue.delete(next[0]);
            now = next[1].at;
            next[1].callback();
        }
        now = end;
    };
    boot();
    return { window, document, overlay, panel, letters, skip, status, workspace, root, sentinel, email, password, submit, motion,
        queue, events, changes, boot, mutate, advance, node,
        parsed() { root.appendChild(sentinel); mutate(); },
        domReady() { document.readyState = 'interactive'; document.dispatchEvent({ type: 'DOMContentLoaded' }); },
        coreReady() { assert.ok(window.AdasiAuthSplash, 'standalone bridge exists'); window.AdasiAuthSplash.markCoreReady(); },
    };
}

test('standalone controller exists and never depends on an application import', () => {
    assert.ok(source.length > 0);
    assert.doesNotMatch(source, /^\s*(?:import|export)\s/m);
    assert.doesNotMatch(source, /\b(?:fetch|XMLHttpRequest|localStorage)\s*[.(]/);
});

test('native login releases without app.js or DOMContentLoaded', () => {
    const e = environment({ mode: 'native-login' });
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.parsed();
    e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.ready, true);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.document.readyState, 'loading');
    e.advance(750);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    e.coreReady();
    e.boot();
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

for (const order of ['pdc', 'cdp', 'dpc']) test('core readiness requires all facts: ' + order, () => {
    const e = environment();
    for (const [index, signal] of [...order].entries()) {
        ({ p: () => e.parsed(), d: () => e.domReady(), c: () => e.coreReady() })[signal]();
        e.advance(32);
        assert.equal(e.window.AdasiAuthSplash.ready, index === 2);
    }
    e.coreReady();
    assert.equal(e.events.filter(event => event.type === 'adasi:page-ready').length, 1);
});

test('exact ADASI rise/sink stagger and loop timings', () => {
    const e = environment();
    e.advance(4370);
    const starts = name => e.changes.filter(change => change.name === name).map(change => [change.element.textContent, change.at]);
    assert.deepEqual(starts('is-rising'), [['A', 50], ['D', 140], ['A', 230], ['S', 320], ['I', 410]]);
    assert.deepEqual(starts('is-sinking'), [['A', 2310], ['D', 2400], ['A', 2490], ['S', 2580], ['I', 2670]]);
    e.advance(50);
    assert.equal(starts('is-rising').at(-1)[1], 4420);
});

for (const offset of [80, 2600]) test('ready cancels all wave work at ' + offset + 'ms and releases immediately', () => {
    const e = environment();
    e.advance(offset);
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    const count = e.changes.filter(c => ['is-rising', 'is-sinking'].includes(c.name)).length;
    assert.equal(e.workspace.inert, false);
    assert.equal(e.workspace.hasAttribute('aria-busy'), false);
    assert.equal(e.document.body.hasAttribute('aria-busy'), false);
    assert.ok(e.letters.every(letter => letter.animationCancellations > 0));
    assert.equal(e.letters[0].style.transform, 'matrix(1, 0, 0, 1, 0, 42)');
    e.advance(10000);
    assert.equal(e.changes.filter(c => ['is-rising', 'is-sinking'].includes(c.name)).length, count);
    assert.equal(e.queue.size, 0);
});

test('independent deadline survives absent body, late markup, late core and duplicate boot', () => {
    const e = environment({ body: false });
    e.advance(10000);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    e.document.body = e.root;
    e.root.appendChild(e.overlay); e.root.appendChild(e.workspace); e.parsed(); e.domReady(); e.coreReady(); e.boot(); e.advance(1000);
    assert.equal(e.overlay.hidden, true);
    assert.equal(e.workspace.inert, false);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('skip and Escape fail open immediately, never wait for the cosmetic fade', () => {
    for (const action of ['skip', 'Escape']) {
        const e = environment();
        e.advance(2000);
        assert.equal(e.skip.hidden, false);
        if (action === 'skip') e.skip.dispatchEvent({ type: 'click' });
        else e.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
        assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
        assert.equal(e.workspace.inert, false);
        assert.equal(e.queue.size, 0);
    }
});

test('reduced motion changes do not cancel the hard watchdog', () => {
    const e = environment();
    e.advance(100);
    e.motion.matches = true;
    e.motion.dispatchEvent({ type: 'change' });
    assert.ok(e.letters.every(letter => letter.classList.contains('is-up')));
    e.advance(9900);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.workspace.inert, false);
});

test('initial reduced motion has no wave and no fade at readiness', () => {
    const e = environment({ reduced: true });
    e.advance(500);
    assert.equal(e.changes.filter(c => c.name === 'is-rising').length, 0);
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('preexisting root state and added roots are restored exactly', () => {
    const e = environment();
    const extra = e.node('aside', { inert: '', 'aria-busy': 'false' });
    e.root.appendChild(extra); e.mutate();
    assert.equal(extra.inert, true);
    assert.equal(extra.getAttribute('aria-busy'), 'true');
    e.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
    assert.equal(extra.inert, true);
    assert.equal(extra.getAttribute('aria-busy'), 'false');
});

test('normal pageshow does not consume later bfcache cleanup', () => {
    const e = environment();
    e.window.dispatchEvent({ type: 'pageshow', persisted: false });
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.window.dispatchEvent({ type: 'pageshow', persisted: true });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.workspace.inert, false);
    e.coreReady(); e.advance(10000);
    assert.equal(e.queue.size, 0);
});

test('fade is interrupted before user interaction and child transitions cannot remove it', () => {
    const e = environment();
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    e.overlay.dispatchEvent({ type: 'transitionend', target: e.letters[0], propertyName: 'transform' });
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.document.dispatchEvent({ type: 'pointerdown', target: e.email });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('blocked CSS never activates or acquires interaction', () => {
    const e = environment({ css: false });
    assert.equal(e.overlay.hidden, true);
    assert.equal(e.workspace.inert, false);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('native DOM present at bootstrap still waits for visual readiness', () => {
    const e = environment({ mode: 'native-login', parsed: true, autofocus: true });
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    assert.equal(e.window.AdasiAuthSplash.nativeDomReady, true);
    assert.equal(e.window.AdasiAuthSplash.renderReady, false);
    assert.equal(e.workspace.inert, true);
    e.advance(16);
    assert.equal(e.window.AdasiAuthSplash.renderReady, false);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(16);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    assert.equal(e.document.activeElement, e.email);
});

test('a page visually ready before component insertion skips without focus or animation', () => {
    const e = environment({ mode: 'native-login', body: false });
    e.document.body = e.root;
    e.root.appendChild(e.workspace);
    e.document.activeElement = e.email;
    e.parsed(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    e.root.appendChild(e.overlay); e.mutate();
    assert.equal(e.overlay.hidden, true);
    assert.equal(e.workspace.inert, false);
    assert.equal(e.document.activeElement, e.email);
    assert.equal(e.changes.filter(c => c.name === 'is-rising').length, 0);
});

test('hard deadline remains independent during the cosmetic fade', () => {
    const e = environment();
    e.advance(9950);
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.advance(18);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.queue.size, 0);
});

test('setup exception after acquiring inert releases every root and timer', () => {
    const e = environment({ failFocus: true });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.workspace.hasAttribute('aria-busy'), false);
    assert.equal(e.queue.size, 0);
    assert.equal(e.events.find(event => event.type === 'adasi:auth-splash-hidden').detail.exit, 'error');
});

test('initial autofocus is restored and Tab stays in splash only until release', () => {
    const e = environment({ autofocus: true });
    assert.equal(e.document.activeElement, e.panel);
    e.document.dispatchEvent({ type: 'keydown', key: 'Tab' });
    assert.equal(e.document.activeElement, e.panel);
    e.advance(2000);
    e.document.dispatchEvent({ type: 'keydown', key: 'Tab' });
    assert.equal(e.document.activeElement, e.skip);
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    assert.equal(e.document.activeElement, e.email);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED', 'internal restore must not cut normal fade');
});

test('pagehide and pageswap remove immediately and only skip an active splash transition', () => {
    for (const type of ['pagehide', 'pageswap']) {
        const e = environment();
        let skipped = 0;
        e.window.dispatchEvent({ type, viewTransition: { skipTransition: () => skipped++ } });
        assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
        assert.equal(e.workspace.inert, false);
        assert.equal(skipped, type === 'pageswap' ? 1 : 0);
    }
});

test('Alpine root bindings cannot clear splash inert, and their latest intent survives release', () => {
    for (const desired of [false, true]) {
        const e = environment();
        e.mutate(); // Deliver splash-owned attribute writes before Alpine's update.
        e.workspace.inert = desired;
        e.mutate();
        assert.equal(e.workspace.inert, true, 'waiting keeps the background inaccessible');
        e.mutate(); // Deliver the corrective ownership write.
        e.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
        assert.equal(e.workspace.inert, desired, 'restore the current underlying binding, including mobile closed sidebar');
    }
});

test('withheld render frames use watchdog fail-open and never report healthy readiness', () => {
    const e = environment({ mode: 'native-login', holdFrames: true });
    e.parsed();
    e.advance(0);
    assert.equal(e.window.AdasiAuthSplash.ready, false);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(10000);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.window.AdasiAuthSplash.renderReady, false);
    assert.equal(e.workspace.inert, false);
    assert.equal(e.events.some(event => event.type === 'adasi:page-ready'), false);
    assert.equal(e.events.find(event => event.type === 'adasi:auth-splash-hidden').detail.exit, 'hard-timeout');
});

test('early core acknowledgement stays sticky and cannot dismiss before two render frames', () => {
    const e = environment();
    e.coreReady();
    assert.equal(e.window.AdasiAuthSplash.coreReady, true);
    assert.equal(e.window.AdasiAuthSplash.ready, false);
    e.parsed(); e.domReady();
    e.advance(16);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    assert.equal(e.window.AdasiAuthSplash.renderReady, false);
    e.advance(16);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    assert.equal(e.workspace.inert, false);
});

for (const late of [false, true]) test('actual app bridge acknowledges after DOM handlers and Alpine settles: late=' + late, () => {
    const e = environment();
    const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const bridge = app.slice(app.indexOf('const acknowledgeAuthSplashCore ='));
    const microtasks = [];
    const alpineTicks = [];
    const calls = [];
    e.document.readyState = 'interactive';
    if (late) e.domReady();
    vm.runInNewContext(bridge, {
        document: e.document, window: e.window,
        queueMicrotask: callback => microtasks.push(callback),
        Alpine: { nextTick: callback => alpineTicks.push(callback) },
    });
    if (!late) {
        assert.equal(microtasks.length, 0, 'interactive is not proof DOMContentLoaded already ran');
        e.document.addEventListener('DOMContentLoaded', () => calls.push('later-synchronous-handler'));
        e.domReady();
        assert.deepEqual(calls, ['later-synchronous-handler']);
    }
    assert.equal(e.window.AdasiAuthSplash.coreReady, false);
    assert.equal(microtasks.length, 1);
    microtasks.shift()();
    assert.equal(e.window.AdasiAuthSplash.coreReady, false);
    alpineTicks.shift()();
    assert.equal(e.window.AdasiAuthSplash.coreReady, true);
    e.parsed(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
});
