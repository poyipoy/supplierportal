import test from 'node:test';
import assert from 'node:assert/strict';

const ALERT_CONFIG = {
    swalCss: 'https://cdn.example.test/sweetalert2.min.css',
    swalCssIntegrity: 'sha384-test-css',
    alertCss: '/assets/css/adasi-alert.css?v=1',
    swalJs: 'https://cdn.example.test/sweetalert2.all.min.js',
    swalJsIntegrity: 'sha384-test-js',
    alertJs: '/assets/js/adasi-alert.js?v=1',
};

const TRANSLATIONS = {
    'js.toast.completed': 'Completed',
    'js.toast.failed': 'Failed',
    'js.toast.attention': 'Attention',
    'js.toast.update': 'Update',
    'js.actions.confirmation': 'Confirmation',
    'js.actions.input': 'Input',
    'js.actions.view': 'View',
};

function createFakeDom({ config = ALERT_CONFIG, onAppendAsset = null, flashNodes = [] } = {}) {
    const headChildren = [];
    const alerts = [];
    const confirms = [];
    const prompts = [];
    const toasts = [];

    const makeElement = (tagName) => {
        const listeners = new Map();
        const el = {
            tagName: tagName.toUpperCase(),
            dataset: {},
            rel: '',
            href: '',
            src: '',
            async: false,
            integrity: '',
            crossOrigin: '',
            isConnected: false,
            getAttribute(name) {
                if (name === 'href') return this.href || null;
                if (name === 'src') return this.src || null;
                if (name === 'rel') return this.rel || null;
                return null;
            },
            addEventListener(type, fn, opts) {
                if (!listeners.has(type)) listeners.set(type, new Set());
                listeners.get(type).add({ fn, once: Boolean(opts?.once) });
            },
            dispatchEvent(type) {
                const entries = [...(listeners.get(type) || [])];
                for (const entry of entries) {
                    if (entry.once) listeners.get(type)?.delete(entry);
                    entry.fn({ type, target: this });
                }
            },
            remove() {
                this.isConnected = false;
                const idx = headChildren.indexOf(this);
                if (idx >= 0) headChildren.splice(idx, 1);
            },
        };
        return el;
    };

    const configNode = config === null ? null : { textContent: JSON.stringify(config) };
    const flashElements = flashNodes.map((data) => ({
        dataset: { ...data },
    }));

    const doc = {
        head: {
            children: headChildren,
            appendChild(el) {
                el.isConnected = true;
                headChildren.push(el);
                if (typeof onAppendAsset === 'function') {
                    queueMicrotask(() => onAppendAsset(el));
                }
                return el;
            },
        },
        getElementById(id) {
            return id === 'adasi-auth-alert-assets' ? configNode : null;
        },
        createElement(tag) {
            return makeElement(tag);
        },
        querySelectorAll(selector) {
            if (selector === 'link[rel="stylesheet"]') {
                return headChildren.filter((el) => el.tagName === 'LINK' && el.rel === 'stylesheet');
            }
            if (selector === 'script[src]') {
                return headChildren.filter((el) => el.tagName === 'SCRIPT' && Boolean(el.src));
            }
            if (selector === '[data-adasi-flash]') {
                return flashElements;
            }
            return [];
        },
    };

    const win = {
        document: doc,
        AdasiI18n: {
            t: (key) => TRANSLATIONS[key] ?? key,
        },
        AdasiToast: {
            show(payload) {
                toasts.push(payload);
                return `toast-${toasts.length}`;
            },
        },
        alert(message) {
            alerts.push(message);
        },
        confirm(message) {
            confirms.push(message);
            return true;
        },
        prompt(message, initialValue) {
            prompts.push({ message, initialValue });
            return 'typed-value';
        },
    };

    return { win, doc, headChildren, alerts, confirms, prompts, toasts, flashElements };
}

let importCounter = 0;
async function loadFreshBridge(env) {
    const prevWindow = globalThis.window;
    const prevDocument = globalThis.document;
    globalThis.window = env.win;
    globalThis.document = env.doc;
    const mod = await import(`../../resources/js/auth-alert-bridge.js?case=${++importCounter}`);
    return {
        mod,
        restore() {
            if (prevWindow === undefined) delete globalThis.window;
            else globalThis.window = prevWindow;
            if (prevDocument === undefined) delete globalThis.document;
            else globalThis.document = prevDocument;
        },
    };
}

test('MAJ-06: retry succeeds after transient script load error and removes failed script tag', async () => {
    let swalScriptAttempts = 0;
    const realConfirmCalls = [];

    const env = createFakeDom({
        onAppendAsset(el) {
            if (el.tagName === 'LINK') {
                el.dispatchEvent('load');
                return;
            }
            if (el.src === ALERT_CONFIG.swalJs) {
                swalScriptAttempts += 1;
                assert.equal(el.integrity, ALERT_CONFIG.swalJsIntegrity);
                assert.equal(el.crossOrigin, 'anonymous');
                if (swalScriptAttempts === 1) {
                    el.dispatchEvent('error');
                } else {
                    env.win.Swal = { fire: async () => ({ isConfirmed: true }) };
                    el.dispatchEvent('load');
                }
                return;
            }
            if (el.src === ALERT_CONFIG.alertJs) {
                env.win.AdasiAlert = Object.freeze({
                    confirm: async (opts) => {
                        realConfirmCalls.push(opts);
                        return { isConfirmed: true, isDenied: false, isDismissed: false, value: 'swal-ok' };
                    },
                });
                el.dispatchEvent('load');
            }
        },
    });

    const { mod, restore } = await loadFreshBridge(env);
    try {
        mod.installAuthAlertBridge();

        // First attempt: SweetAlert2 script fails -> failed <script> is removed & native fallback runs.
        const firstResult = await env.win.AdasiAlert.confirm({ title: 'Delete?', text: 'Proceed?' });
        assert.equal(firstResult.isConfirmed, true);
        assert.equal(env.confirms.length, 1);
        assert.equal(realConfirmCalls.length, 0);
        assert.equal(
            env.headChildren.some((el) => el.tagName === 'SCRIPT' && el.src === ALERT_CONFIG.swalJs),
            false,
            'failed script element must be removed from DOM before retry',
        );

        // Second attempt: retry re-injects script, succeeds, and runs the real SweetAlert2 runtime.
        const secondResult = await env.win.AdasiAlert.confirm({ title: 'Delete?', text: 'Retry now' });
        assert.equal(swalScriptAttempts, 2);
        assert.equal(realConfirmCalls.length, 1);
        assert.equal(secondResult.value, 'swal-ok');
        assert.equal(
            env.headChildren.filter((el) => el.tagName === 'LINK' && el.href === ALERT_CONFIG.swalCss).length,
            1,
            'already-loaded stylesheet must not be duplicated on retry',
        );
    } finally {
        restore();
    }
});

test('MAJ-06: retry succeeds after transient stylesheet load error without prematurely locking in unstyled alert runtime', async () => {
    let swalCssAttempts = 0;
    let alertJsInjections = 0;
    const realInfoCalls = [];

    const env = createFakeDom({
        onAppendAsset(el) {
            if (el.tagName === 'LINK' && el.href === ALERT_CONFIG.swalCss) {
                swalCssAttempts += 1;
                assert.equal(el.integrity, ALERT_CONFIG.swalCssIntegrity);
                assert.equal(el.crossOrigin, 'anonymous');
                if (swalCssAttempts === 1) {
                    el.dispatchEvent('error');
                } else {
                    el.dispatchEvent('load');
                }
                return;
            }
            if (el.tagName === 'LINK') {
                el.dispatchEvent('load');
                return;
            }
            if (el.src === ALERT_CONFIG.swalJs) {
                env.win.Swal = { fire: async () => ({ isConfirmed: true }) };
                el.dispatchEvent('load');
                return;
            }
            if (el.src === ALERT_CONFIG.alertJs) {
                alertJsInjections += 1;
                env.win.AdasiAlert = Object.freeze({
                    confirm: async () => ({ isConfirmed: true }),
                    info: async (opts) => {
                        realInfoCalls.push(opts);
                        return { isConfirmed: true, value: 'styled-info' };
                    },
                });
                el.dispatchEvent('load');
            }
        },
    });

    const { mod, restore } = await loadFreshBridge(env);
    try {
        mod.installAuthAlertBridge();

        // Attempt 1: swalCss fails -> removed from head, alertJs not loaded yet, bridge stays retryable.
        await env.win.AdasiAlert.info({ text: 'Check status' });
        assert.equal(swalCssAttempts, 1);
        assert.equal(alertJsInjections, 0, 'adasi-alert.js must not take over while required stylesheet failed');
        assert.equal(env.win.AdasiAlert.__authLazyBridge, true);
        assert.equal(
            env.headChildren.some((el) => el.tagName === 'LINK' && el.href === ALERT_CONFIG.swalCss),
            false,
            'failed stylesheet link must be removed from DOM',
        );
        assert.equal(env.alerts.length, 1);

        // Attempt 2: swalCss succeeds on retry, cached swalJs/alertCss are reused, and alertJs loads cleanly.
        const secondResult = await env.win.AdasiAlert.info({ text: 'Check status again' });
        assert.equal(swalCssAttempts, 2);
        assert.equal(alertJsInjections, 1);
        assert.equal(realInfoCalls.length, 1);
        assert.equal(secondResult.value, 'styled-info');
        assert.equal(
            env.headChildren.filter((el) => el.tagName === 'SCRIPT' && el.src === ALERT_CONFIG.swalJs).length,
            1,
            'already-loaded swalJs must not be re-injected on stylesheet retry',
        );
    } finally {
        restore();
    }
});

test('MIN-04: nativeFallback uses per-type localized titles matching adasi-alert.js', async () => {
    const env = createFakeDom({ config: null });
    const { mod, restore } = await loadFreshBridge(env);
    try {
        mod.installAuthAlertBridge();

        await env.win.AdasiAlert.success({ text: 'Saved' });
        await env.win.AdasiAlert.error({ text: 'Broken' });
        await env.win.AdasiAlert.warning({ text: 'Careful' });
        await env.win.AdasiAlert.info({ text: 'FYI' });
        await env.win.AdasiAlert.error({ title: 'Custom Error', text: 'Explicit title wins' });

        assert.deepEqual(env.alerts, [
            'Completed\n\nSaved',
            'Failed\n\nBroken',
            'Attention\n\nCareful',
            'Update\n\nFYI',
            'Custom Error\n\nExplicit title wins',
        ]);
    } finally {
        restore();
    }
});

test('flash toasts flush idempotently without loading SweetAlert2 assets', async () => {
    const env = createFakeDom({
        flashNodes: [
            { type: 'success', title: 'Logged out', message: 'See you soon', duration: '4000' },
            { type: 'warning', title: '', message: 'Session expired', duration: '' },
        ],
    });
    const { mod, restore } = await loadFreshBridge(env);
    try {
        mod.installAuthAlertBridge();
        mod.flushAuthFlashToasts(); // Second call must not duplicate consumed flash nodes.

        assert.equal(env.headChildren.length, 0, 'flash toast flush must never inject SweetAlert2 scripts or stylesheets');
        assert.equal(env.toasts.length, 2);
        assert.deepEqual(env.toasts[0], {
            type: 'success',
            title: 'Logged out',
            message: 'See you soon',
            timestamp: undefined,
            autoClose: 4000,
            actions: undefined,
        });
        assert.deepEqual(env.toasts[1], {
            type: 'warning',
            title: '',
            message: 'Session expired',
            timestamp: undefined,
            autoClose: undefined,
            actions: undefined,
        });
        assert.equal(env.flashElements.every((el) => el.dataset.adasiFlashConsumed === '1'), true);
    } finally {
        restore();
    }
});

