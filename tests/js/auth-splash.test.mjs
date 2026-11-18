import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import vm from 'node:vm';

const sourcePath = new URL('../../resources/js/auth-splash.js', import.meta.url);
const source = existsSync(sourcePath) ? readFileSync(sourcePath, 'utf8') : '';

const GIF_SRC = '/assets/images/logo-adasi.gif';
const FALLBACK_SRC = '/assets/images/logo-adasi-wordmark.png';

function environment({
    mode = 'core',
    reduced = false,
    body = true,
    css = true,
    parsed = false,
    failFocus = false,
    autofocus = false,
    holdFrames = false,
    minVisible = '0',
    mediaFallbackMs = '500',
    mediaComplete = true,
    mediaWidth = 384,
    mediaSrc = GIF_SRC,
    fallbackSrc = FALLBACK_SRC,
    withMedia = true,
} = {}) {
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
    document.currentScript = {
        dataset: {
            hardTimeout: '10000',
            slowAfter: '2000',
            fadeMs: '650',
            cleanupMs: '750',
            mediaFallbackMs,
            layout: mode === 'native-login' ? 'auth' : 'app',
            readiness: mode,
        },
    };
    // Existing readiness-only cases explicitly disable the branding minimum.
    if (minVisible !== null) document.currentScript.dataset.minVisibleMs = minVisible;
    const overlay = node('div', { 'data-auth-splash': '', class: 'auth-splash', 'aria-hidden': 'true' });
    overlay.hidden = true;
    overlay.dataset = { splashReason: 'post-login' };
    const panel = overlay.appendChild(node('div', { class: 'auth-splash__panel', tabindex: '-1' }));
    const brand = panel.appendChild(node('div', { class: 'auth-splash__brand', 'aria-hidden': 'true' }));
    const picture = brand.appendChild(node('picture', { class: 'auth-splash__picture' }));
    picture.appendChild(node('source', { media: '(prefers-reduced-motion: reduce)', srcset: fallbackSrc }));
    const media = withMedia
        ? picture.appendChild(node('img', {
            class: 'auth-splash__media',
            'data-auth-splash-media': '',
            'data-fallback-src': fallbackSrc,
            src: mediaSrc,
            width: '384',
            height: '153',
            alt: '',
        }))
        : null;
    if (media) {
        media.complete = mediaComplete;
        media.naturalWidth = mediaComplete ? mediaWidth : 0;
        media.naturalHeight = mediaComplete && mediaWidth > 0 ? 153 : 0;
        Object.defineProperty(media, 'src', {
            get() { return this.getAttribute('src') || ''; },
            set(value) {
                this.setAttribute('src', value);
                this.complete = false;
                this.naturalWidth = 0;
                this.naturalHeight = 0;
            },
        });
        Object.defineProperty(media, 'currentSrc', {
            get() { return reduced ? fallbackSrc : (this.getAttribute('src') || ''); },
        });
    }
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
        getComputedStyle: () => ({ transform: 'none', getPropertyValue: () => css ? '1' : '' }),
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
    return {
        window, document, overlay, panel, brand, picture, media, skip, status, workspace, root, sentinel, email, password, submit, motion,
        queue, events, changes, boot, mutate, advance, node,
        parsed() { root.appendChild(sentinel); mutate(); },
        domReady() { document.readyState = 'interactive'; document.dispatchEvent({ type: 'DOMContentLoaded' }); },
        coreReady() { assert.ok(window.AdasiAuthSplash, 'standalone bridge exists'); window.AdasiAuthSplash.markCoreReady(); },
        mediaLoad(width = 384) {
            media.complete = true;
            media.naturalWidth = width;
            media.naturalHeight = width > 0 ? 153 : 0;
            media.dispatchEvent({ type: 'load', target: media });
        },
        mediaError() {
            media.complete = true;
            media.naturalWidth = 0;
            media.naturalHeight = 0;
            media.dispatchEvent({ type: 'error', target: media });
        },
    };
}

test('standalone controller exists and never depends on an application import', () => {
    assert.ok(source.length > 0);
    assert.doesNotMatch(source, /^\s*(?:import|export)\s/m);
    assert.doesNotMatch(source, /\b(?:fetch|XMLHttpRequest|localStorage)\s*[.(]/);
});

for (const mode of ['native-login', 'core']) test('minimum holds interaction after real readiness: ' + mode, () => {
    const e = environment({ mode, minVisible: null, autofocus: true });
    e.advance(268);
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
    assert.equal(e.events.filter(event => event.type === 'adasi:page-ready').length, 1);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    assert.equal(e.document.activeElement, e.panel);
    assert.equal(e.workspace.inert, true);
    e.coreReady(); e.mutate(); e.advance(1699);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.document.activeElement, e.email);
    assert.equal(e.events.filter(event => event.type === 'adasi:page-ready').length, 1);
    assert.equal(e.events.filter(event => event.type === 'adasi:auth-splash-released').length, 1);
    e.advance(750);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.queue.size, 0);
});

for (const value of ['bad', '', '-1', '10001', 'Infinity', null]) test('invalid or missing minimum defaults to 2000: ' + value, () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: value });
    e.advance(1999);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
});

test('custom minimum uses activation time and late readiness is never delayed again', () => {
    const e = environment({ body: false, minVisible: '500' });
    e.advance(1000);
    e.document.body = e.root;
    e.root.appendChild(e.overlay); e.root.appendChild(e.workspace); e.mutate();
    e.parsed(); e.domReady(); e.coreReady(); e.advance(499);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    const late = environment({ minVisible: '2000' });
    late.advance(2968);
    late.parsed(); late.domReady(); late.coreReady(); late.advance(32);
    assert.equal(late.window.AdasiAuthSplash.state, 'RELEASED');
});

test('readiness before late markup stays sticky until activation plus the minimum', () => {
    const e = environment({ mode: 'native-login', body: false, minVisible: '2000' });
    e.document.body = e.root;
    e.root.appendChild(e.workspace); e.parsed(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
    assert.equal(e.window.AdasiAuthSplash.state, 'ARMED');
    e.advance(968);
    e.root.appendChild(e.overlay); e.mutate();
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1999);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
});

test('pending GIF delays 2000ms minimum until load fires before fallback timeout', () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false, mediaFallbackMs: '500' });
    e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.renderReady, true);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(218); // t = 250ms
    e.mediaLoad(384);
    assert.equal(e.media.getAttribute('src'), GIF_SRC);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1999);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1); // t = 2250ms
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.advance(750);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.queue.size, 0);
});

test('slow GIF past mediaFallbackMs keeps GIF playing and anchors 2000ms minimum at timeout', () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false, mediaFallbackMs: '500' });
    e.advance(499);
    assert.equal(e.media.getAttribute('src'), GIF_SRC);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1); // t = 500ms
    assert.equal(e.media.getAttribute('src'), GIF_SRC);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(100);
    e.mediaLoad(384); // Late load completion must not restart the 2000ms minimum.
    e.advance(1899);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1); // t = 2500ms
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.advance(750);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.queue.size, 0);
});

test('GIF load error falls back to static PNG and double error hides image without deadlock', () => {
    const recovered = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false });
    recovered.advance(40);
    recovered.mediaError();
    assert.equal(recovered.media.getAttribute('src'), FALLBACK_SRC);
    assert.equal(recovered.media.hidden, false);
    recovered.advance(40); // t = 80ms
    recovered.mediaLoad(384);
    recovered.advance(1999);
    assert.equal(recovered.window.AdasiAuthSplash.state, 'WAITING');
    recovered.advance(1);
    assert.equal(recovered.window.AdasiAuthSplash.state, 'RELEASED');

    const broken = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false });
    broken.advance(40);
    broken.mediaError();
    assert.equal(broken.media.getAttribute('src'), FALLBACK_SRC);
    broken.advance(40); // t = 80ms
    broken.mediaError();
    assert.equal(broken.media.hidden, true);
    broken.advance(1999);
    assert.equal(broken.window.AdasiAuthSplash.state, 'WAITING');
    broken.advance(1);
    assert.equal(broken.window.AdasiAuthSplash.state, 'RELEASED');
    broken.advance(750);
    assert.equal(broken.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(broken.queue.size, 0);
});

test('pre-broken media and same-URL fallback hide cleanly with zero infinite error loop', () => {
    const e = environment({
        mode: 'native-login',
        parsed: true,
        minVisible: '2000',
        mediaComplete: true,
        mediaWidth: 0,
        mediaSrc: FALLBACK_SRC,
        fallbackSrc: FALLBACK_SRC,
    });
    assert.equal(e.media.hidden, true);
    e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1968);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
});

for (const action of ['Escape', 'skip', 'pagehide', 'pageswap', 'bfcache', 'css-timeout']) test('minimum never blocks immediate exit: ' + action, () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: '5000', mediaComplete: false });
    e.advance(action === 'skip' ? 2000 : 32);
    if (action === 'Escape') e.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
    else if (action === 'skip') e.skip.dispatchEvent({ type: 'click' });
    else if (action === 'bfcache') e.window.dispatchEvent({ type: 'pageshow', persisted: true });
    else if (action === 'css-timeout') e.overlay.dispatchEvent({ type: 'animationend', animationName: 'auth-splash-expire' });
    else e.window.dispatchEvent({ type: action });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.queue.size, 0);
    e.coreReady(); e.boot(); e.advance(10000);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('initial and live reduced motion bypass media wait and minimum without a fade', () => {
    const initial = environment({ mode: 'native-login', parsed: true, minVisible: '2000', reduced: true, mediaComplete: false });
    initial.advance(32);
    assert.equal(initial.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(initial.queue.size, 0);

    const live = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false });
    live.advance(100);
    assert.equal(live.window.AdasiAuthSplash.state, 'WAITING');
    live.motion.matches = true; live.motion.dispatchEvent({ type: 'change' });
    assert.equal(live.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(live.queue.size, 0);
});

test('an initialization error while holding the minimum restores interaction and cancels timers', () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: '2000', mediaComplete: false });
    e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.document.querySelector = () => { throw new Error('DOM integration failed'); };
    e.mutate();
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.events.find(event => event.type === 'adasi:auth-splash-hidden').detail.exit, 'error');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.queue.size, 0);
});

test('the hard deadline wins over a late activation minimum and pending media', () => {
    const e = environment({ mode: 'native-login', body: false, minVisible: '2000', mediaComplete: false });
    e.advance(9500);
    e.document.body = e.root;
    e.root.appendChild(e.overlay); e.root.appendChild(e.workspace); e.parsed();
    e.advance(499);
    assert.equal(e.window.AdasiAuthSplash.state, 'WAITING');
    e.advance(1);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.events.find(event => event.type === 'adasi:auth-splash-hidden').detail.exit, 'hard-timeout');
    assert.equal(e.workspace.inert, false);
    assert.equal(e.queue.size, 0);
});

test('returning from a background tab does not restart the minimum', () => {
    const e = environment({ mode: 'native-login', parsed: true, minVisible: '2000' });
    e.advance(32);
    e.document.hidden = true;
    e.document.dispatchEvent({ type: 'visibilitychange' });
    e.advance(1968);
    e.document.hidden = false;
    e.document.dispatchEvent({ type: 'visibilitychange' });
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
});

test('native login releases without app.js or DOMContentLoaded even while media is pending', () => {
    const e = environment({ mode: 'native-login', mediaComplete: false });
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

test('missing media element does not activate overlay', () => {
    const e = environment({ withMedia: false, parsed: true });
    assert.equal(e.overlay.hidden, true);
    assert.equal(e.workspace.inert, false);
    assert.equal(e.window.AdasiAuthSplash.state, 'ARMED');
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
    e.advance(9900);
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
    assert.equal(e.workspace.inert, false);
});

test('initial reduced motion has no fade at readiness', () => {
    const e = environment({ reduced: true });
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
    e.overlay.dispatchEvent({ type: 'transitionend', target: e.media, propertyName: 'opacity' });
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.document.dispatchEvent({ type: 'pointerdown', target: e.email });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('blocked CSS never activates or acquires interaction', () => {
    const e = environment({ css: false, minVisible: '2000' });
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

test('a page visually ready before component insertion skips without focus or activation', () => {
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
    const e = environment({ mode: 'native-login', holdFrames: true, minVisible: '2000' });
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

test('extended hardTimeout up to 60000ms is honored and syncs --splash-fade from fadeMs', () => {
    const target = () => ({
        listeners: new Map(),
        addEventListener(type, cb) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(cb); },
        removeEventListener(type, cb) { this.listeners.get(type)?.delete(cb); },
        dispatchEvent(ev) { ev.target ??= this; for (const cb of [...(this.listeners.get(ev.type) || [])]) cb(ev); return true; },
    });
    const doc = Object.assign(target(), { readyState: 'loading', hidden: false });
    const makeEl = (tag, attrs = {}) => {
        const cls = new Set((attrs.class || '').split(' ').filter(Boolean));
        const styleVars = {};
        const el = Object.assign(target(), {
            tagName: tag.toUpperCase(), attributes: { ...attrs }, children: [], dataset: {},
            style: { setProperty(k, v) { styleVars[k] = v; }, getPropertyValue(k) { return styleVars[k] ?? ''; } },
            hidden: false, disabled: false, isConnected: true,
            getAttribute(k) { return this.attributes[k] ?? null; },
            hasAttribute(k) { return k in this.attributes; },
            setAttribute(k, v) { this.attributes[k] = String(v); },
            removeAttribute(k) { delete this.attributes[k]; },
            appendChild(c) { c.parentElement = this; this.children.push(c); return c; },
            contains(c) { return this === c || this.children.some(n => n.contains(c)); },
            matches(s) { return s.split(',').some(p => { p = p.trim(); if (p.startsWith('.')) return cls.has(p.slice(1)); const a = [...p.matchAll(/\[([^=\]]+)(?:=["']?([^"'\]]+)["']?)?\]/g)]; return a.every(([, k, v]) => this.hasAttribute(k) && (v === undefined || this.getAttribute(k) === v)); }); },
            querySelectorAll(s) { return this.children.flatMap(c => [...(c.matches(s) ? [c] : []), ...c.querySelectorAll(s)]); },
            querySelector(s) { return this.querySelectorAll(s)[0] || null; },
            focus() {}, remove() { this.isConnected = false; }, getAnimations() { return []; },
            classList: { add: (...n) => n.forEach(x => cls.add(x)), remove: (...n) => n.forEach(x => cls.delete(x)), contains: x => cls.has(x) },
        });
        Object.defineProperty(el, 'inert', { get: () => el.hasAttribute('inert'), set: v => v ? el.setAttribute('inert', '') : el.removeAttribute('inert') });
        return el;
    };
    const root = makeEl('body');
    doc.body = root;
    doc.documentElement = makeEl('html');
    doc.querySelector = s => root.matches(s) ? root : root.querySelector(s);
    doc.querySelectorAll = s => root.querySelectorAll(s);
    doc.currentScript = { dataset: { hardTimeout: '60000', minVisibleMs: '600', mediaFallbackMs: '500', slowAfter: '2000', fadeMs: '500', cleanupMs: '600', layout: 'app', readiness: 'core' } };
    const overlay = root.appendChild(makeEl('div', { 'data-auth-splash': '', class: 'auth-splash' }));
    overlay.dataset = { splashReason: 'post-login' };
    overlay.appendChild(makeEl('div', { class: 'auth-splash__panel', tabindex: '-1' }));
    overlay.appendChild(makeEl('span', { 'data-auth-splash-status': '' }));
    const skip = overlay.appendChild(makeEl('button', { 'data-auth-splash-skip': '' })); skip.hidden = true;
    const media = overlay.appendChild(makeEl('img', { 'data-auth-splash-media': '', src: GIF_SRC }));
    media.complete = true; media.naturalWidth = 384; media.naturalHeight = 153; media.currentSrc = GIF_SRC;
    root.appendChild(makeEl('main', { id: 'main-content' }));
    const sentinel = root.appendChild(makeEl('template', { 'data-auth-splash-layout-ready': 'app' })); sentinel.dataset.readiness = 'core';

    let now = 0;
    let seq = 0;
    const queue = new Map();
    const win = Object.assign(target(), {
        document: doc, Element: Object, HTMLElement: Object,
        setTimeout: (cb, d = 0) => { const id = ++seq; queue.set(id, { cb, at: now + d }); return id; },
        clearTimeout: id => queue.delete(id),
        requestAnimationFrame: cb => win.setTimeout(cb, 16),
        cancelAnimationFrame: id => queue.delete(id),
        performance: { now: () => now, mark() {}, measure() {} },
        matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
        getComputedStyle: () => ({ transform: 'none', getPropertyValue: () => '1' }),
        CustomEvent: class { constructor(t, o = {}) { this.type = t; this.detail = o.detail; } },
        MutationObserver: class { observe() {} disconnect() {} },
    });
    const ctx = vm.createContext({ window: win, document: doc, console, performance: win.performance, MutationObserver: win.MutationObserver, CustomEvent: win.CustomEvent, HTMLElement: win.HTMLElement });
    vm.runInContext(source, ctx);

    assert.equal(overlay.style.getPropertyValue('--splash-fade'), '500ms');

    const advance = ms => {
        const end = now + ms;
        for (;;) {
            const next = [...queue].filter(([, v]) => v.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (!next) break;
            queue.delete(next[0]); now = next[1].at; next[1].cb();
        }
        now = end;
    };

    advance(15000);
    assert.equal(win.AdasiAuthSplash.state, 'WAITING', 'splash must survive 15s slow preparation when hardTimeout is 60s');
    doc.readyState = 'interactive';
    doc.dispatchEvent({ type: 'DOMContentLoaded' });
    win.AdasiAuthSplash.markCoreReady();
    advance(32);
    assert.equal(win.AdasiAuthSplash.state, 'RELEASED', 'splash releases cleanly when core readiness completes after slow load');
    assert.equal(doc.documentElement.getAttribute('data-auth-splash-wait'), null);
});

test('programmatic focusin during RELEASED does not cut fade early while pointerdown removes immediately', () => {
    const e = environment();
    e.parsed(); e.domReady(); e.coreReady(); e.advance(32);
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED');
    e.document.dispatchEvent({ type: 'focusin', target: e.email });
    assert.equal(e.window.AdasiAuthSplash.state, 'RELEASED', 'programmatic focusin must allow the ease-out fade to finish');
    e.document.dispatchEvent({ type: 'pointerdown', target: e.email });
    assert.equal(e.window.AdasiAuthSplash.state, 'REMOVED');
});

test('auth-splash.css exits with ease-out and disables transition under reduced motion', () => {
    const cssPath = new URL('../../resources/css/auth-splash.css', import.meta.url);
    const css = readFileSync(cssPath, 'utf8');
    const splashRule = css.match(/\.auth-splash\s*\{[\s\S]*?\}/);
    assert.ok(splashRule, '.auth-splash rule must exist');
    assert.equal(splashRule[0].includes('transition-timing-function: ease-out;'), true);
    assert.equal(css.includes('@media (prefers-reduced-motion: reduce)'), true);
    assert.equal(css.includes('.auth-splash { transition: none; }'), true);
});
