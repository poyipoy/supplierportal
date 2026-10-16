/* Standalone head bootstrap. Deliberately has no imports or application dependencies. */
(() => {
    'use strict';
    const win = window;
    const doc = win.document;
    if (win.AdasiAuthSplash || !doc.currentScript?.dataset) return;
    const config = doc.currentScript.dataset;
    const delay = (value, fallback, maximum) => {
        const number = Number(value);
        return Number.isFinite(number) && number > 0 ? Math.min(number, maximum) : fallback;
    };
    const later = win.setTimeout.bind(win);
    const cancel = win.clearTimeout.bind(win);
    const clock = () => win.performance.now();
    const deadline = clock() + delay(config.hardTimeout, 10000, 10000);
    const configuredMinimum = Number(config.minVisibleMs);
    const minimumVisible = typeof config.minVisibleMs === 'string' && config.minVisibleMs.trim() !== ''
        && Number.isFinite(configuredMinimum) && configuredMinimum >= 0 && configuredMinimum <= 10000
        ? configuredMinimum : 2000;
    const slowAfter = delay(config.slowAfter, 2000, 2000);
    const cleanupAfter = delay(config.cleanupMs, 750, 750);
    const layout = config.layout || 'app';
    const mode = config.readiness || 'core';
    const roots = new Map();
    const ownedWrites = new Map();
    const waveTimers = new Set();
    const removers = [];
    let state = 'ARMED';
    let ready = false;
    let coreReady = false;
    let nativeDomReady = false;
    let renderReady = false;
    let domReady = doc.readyState === 'complete';
    let overlay = null;
    let panel = null;
    let skip = null;
    let letters = [];
    let observer = null;
    let motion = null;
    let watchdog = null;
    let skipTimer = null;
    let fadeTimer = null;
    let minimumTimer = null;
    let visibleAt = null;
    let minimumBypassed = false;
    let readyFrame = null;
    let runId = 0;
    let returnFocus = null;
    let restoringFocus = false;
    let visible = false;

    const mark = name => {
        try { win.performance.mark('adasi-auth-splash-' + name); } catch { /* Diagnostics never gate availability. */ }
    };
    const emit = (type, exit) => {
        try {
            win.dispatchEvent(new win.CustomEvent(type, { detail: {
                layout, reason: config.reason || overlay?.dataset.splashReason || 'login-page', exit,
            } }));
        } catch { /* Consumers cannot own splash cleanup. */ }
    };
    const listen = (target, type, callback, options) => {
        target.addEventListener(type, callback, options);
        removers.push(() => target.removeEventListener(type, callback, options));
    };
    const freezeWave = freeze => {
        runId++;
        waveTimers.forEach(cancel);
        waveTimers.clear();
        letters.forEach(letter => {
            try {
                if (freeze) letter.style.transform = win.getComputedStyle(letter).transform;
                letter.getAnimations?.().forEach(animation => animation.cancel());
                letter.classList.remove('is-rising', 'is-sinking', 'is-up');
                letter.style.willChange = freeze ? 'auto' : '';
            } catch { /* Continue releasing other letters and the page. */ }
        });
    };
    const restoreRoots = () => {
        roots.forEach((saved, root) => {
            try {
                for (const name of ['inert', 'aria-busy']) {
                    if (saved[name] === null) root.removeAttribute(name);
                    else root.setAttribute(name, saved[name]);
                }
            } catch { /* A detached/custom node must not strand another root. */ }
        });
        roots.clear();
        ownedWrites.clear();
    };
    const removeVisual = exit => {
        if (state === 'REMOVED' || state === 'WAITING') return;
        state = 'REMOVED';
        freezeWave(false);
        restoreRoots();
        observer?.disconnect();
        [watchdog, skipTimer, fadeTimer, minimumTimer].forEach(cancel);
        minimumTimer = null;
        if (readyFrame !== null) win.cancelAnimationFrame?.(readyFrame);
        readyFrame = null;
        removers.splice(0).forEach(remove => { try { remove(); } catch { /* Continue cleanup. */ } });
        try {
            if (overlay) {
                overlay.hidden = true;
                overlay.remove();
            }
        } finally {
            mark('hidden');
            emit('adasi:auth-splash-hidden', exit);
        }
    };
    const release = (exit, immediate = false) => {
        if (state === 'REMOVED') return;
        if (state === 'RELEASED') {
            if (immediate) removeVisual(exit);
            return;
        }
        const ownedFocus = overlay?.contains(doc.activeElement);
        state = 'RELEASED';
        freezeWave(!immediate);
        observer?.disconnect();
        cancel(skipTimer);
        cancel(minimumTimer);
        minimumTimer = null;
        if (readyFrame !== null) win.cancelAnimationFrame?.(readyFrame);
        readyFrame = null;
        try {
            restoreRoots();
            if (overlay) {
                overlay.setAttribute('aria-hidden', 'true');
                overlay.setAttribute('data-state', 'released');
                overlay.style.pointerEvents = 'none';
                if (skip) { skip.hidden = true; skip.disabled = true; }
                if (!immediate && !motion?.matches && visible) overlay.classList.add('is-leaving');
            }
            if (ownedFocus) {
                const fallback = mode === 'native-login' ? doc.querySelector('#email') : doc.querySelector('#main-content');
                const target = returnFocus?.isConnected && returnFocus !== doc.body ? returnFocus : fallback;
                if (target?.isConnected && !target.inert) {
                    restoringFocus = true;
                    try { target.focus({ preventScroll: true }); } finally { restoringFocus = false; }
                }
            }
        } finally {
            mark('interaction-released');
            if (immediate || motion?.matches || !visible) removeVisual(exit);
            else fadeTimer = later(() => removeVisual(exit), cleanupAfter);
            emit('adasi:auth-splash-released', exit);
        }
    };
    const safe = fn => (...args) => {
        try { fn(...args); } catch { release('error', true); }
    };
    const scheduleWave = (ms, callback, run) => {
        const id = later(safe(() => {
            waveTimers.delete(id);
            if (state === 'WAITING' && run === runId) callback();
        }), ms);
        waveTimers.add(id);
    };
    const cycle = run => {
        if (state !== 'WAITING' || run !== runId || motion.matches) return;
        letters.forEach(letter => {
            letter.style.transform = '';
            letter.classList.remove('is-rising', 'is-up', 'is-sinking');
        });
        letters.forEach((letter, index) => {
            scheduleWave(50 + index * 90, () => {
                letter.classList.add('is-rising');
                scheduleWave(1600, () => {
                    letter.classList.remove('is-rising');
                    letter.classList.add('is-up');
                }, run);
            }, run);
            scheduleWave(2310 + index * 90, () => {
                letter.classList.remove('is-rising', 'is-up');
                letter.classList.add('is-sinking');
                scheduleWave(1600, () => letter.classList.remove('is-sinking'), run);
            }, run);
        });
        scheduleWave(4370, () => cycle(run), run);
    };
    const ownAttribute = (root, name) => {
        const value = name === 'inert' ? '' : 'true';
        if (root.getAttribute(name) === value) return;
        const writes = ownedWrites.get(root) || {};
        writes[name] = (writes[name] || 0) + 1;
        ownedWrites.set(root, writes);
        root.setAttribute(name, value);
    };
    const acquireRoots = () => {
        Array.from(doc.body?.children || []).forEach(root => {
            if (root === overlay || root.id === 'adasiLoader' || /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(root.tagName) || roots.has(root)) return;
            roots.set(root, { inert: root.getAttribute('inert'), 'aria-busy': root.getAttribute('aria-busy') });
            ownAttribute(root, 'inert');
            ownAttribute(root, 'aria-busy');
        });
    };
    const reconcileBindings = records => {
        // Alpine also binds sidebar inert. Preserve its current intent, while
        // keeping the temporary splash restriction until this owner releases.
        records.forEach(record => {
            if (record.type !== 'attributes' || !roots.has(record.target)) return;
            const name = record.attributeName;
            if (name !== 'inert' && name !== 'aria-busy') return;
            const writes = ownedWrites.get(record.target);
            if (writes?.[name]) writes[name]--;
            else roots.get(record.target)[name] = record.target.getAttribute(name);
        });
        if (state === 'WAITING') roots.forEach((saved, root) => {
            if (root.isConnected) {
                ownAttribute(root, 'inert');
                ownAttribute(root, 'aria-busy');
            }
        });
    };
    const nativeLoginReady = () => {
        const form = doc.querySelector('form[method="POST"]');
        return form?.querySelector('[name="email"]')
            && form.querySelector('[name="password"]')?.getAttribute('type') === 'password'
            && form.querySelector('[type="submit"]');
    };
    const factsReady = () => {
        const sentinel = doc.querySelector('[data-auth-splash-layout-ready="' + layout + '"]');
        if (mode === 'native-login' && sentinel && nativeLoginReady() && !nativeDomReady) {
            nativeDomReady = true;
            mark('native-dom-ready');
        }
        return sentinel && (mode === 'native-login' ? nativeDomReady : mode === 'native-document' || (domReady && coreReady));
    };
    const releaseWhenReady = () => {
        if (!renderReady || state === 'REMOVED' || state === 'RELEASED') return;
        if (clock() >= deadline) return release('hard-timeout', true);
        // Early readiness is a fact, not permission to discard late markup.
        if (state === 'ARMED') {
            if (minimumVisible === 0) release('ready');
            return;
        }
        const remaining = minimumBypassed ? 0 : visibleAt + minimumVisible - clock();
        if (remaining <= 0) return release('ready');
        if (minimumTimer === null) minimumTimer = later(safe(() => {
            minimumTimer = null;
            releaseWhenReady();
        }), Math.min(remaining, Math.max(0, deadline - clock())));
    };
    const queueReady = () => {
        if (readyFrame !== null || ready || state === 'REMOVED' || state === 'RELEASED') return;
        const complete = safe(() => {
            if (ready || state === 'REMOVED' || state === 'RELEASED') return;
            if (readyFrame !== null) win.cancelAnimationFrame?.(readyFrame);
            readyFrame = null;
            if (clock() >= deadline) return release('hard-timeout', true);
            renderReady = true;
            ready = true;
            mark('render-ready');
            mark('ready');
            emit('adasi:page-ready', 'ready');
            releaseWhenReady();
        });
        // Two frame boundaries allow an initial render opportunity. A stalled
        // renderer is released by the independent watchdog, never labelled ready.
        readyFrame = win.requestAnimationFrame(safe(() => {
            readyFrame = null;
            if (state === 'REMOVED' || state === 'RELEASED') return;
            if (clock() >= deadline) return release('hard-timeout', true);
            readyFrame = win.requestAnimationFrame(complete);
        }));
    };
    const scan = safe(() => {
        if (state === 'REMOVED' || state === 'RELEASED') return;
        if (clock() >= deadline) return release('hard-timeout', true);
        overlay ||= doc.querySelector('[data-auth-splash]');
        const prerequisitesReady = factsReady();
        if (!overlay) {
            if (renderReady) releaseWhenReady();
            else if (prerequisitesReady) queueReady();
            return;
        }
        if (state === 'WAITING') {
            acquireRoots();
            if (renderReady) releaseWhenReady();
            else if (prerequisitesReady) queueReady();
            return;
        }
        letters = Array.from(overlay.querySelectorAll('.auth-splash__letter'));
        panel = overlay.querySelector('.auth-splash__panel');
        skip = overlay.querySelector('[data-auth-splash-skip]');
        if (letters.length !== 5 || !panel || !skip || !overlay.querySelector('[data-auth-splash-status]')) return;
        if (!('inert' in overlay) || win.getComputedStyle(overlay).getPropertyValue('--auth-splash-css-ready').trim() !== '1') {
            return release('unsupported', true);
        }
        motion = win.matchMedia('(prefers-reduced-motion: reduce)');
        returnFocus = doc.activeElement;
        state = 'WAITING';
        acquireRoots();
        overlay.hidden = false;
        overlay.removeAttribute('aria-hidden');
        overlay.setAttribute('data-state', 'waiting');
        overlay.style.setProperty('--auth-splash-remaining', Math.max(0, deadline - clock()) + 'ms');
        visible = true;
        visibleAt = clock();
        minimumBypassed = motion.matches;
        mark('visible-start');
        panel.focus({ preventScroll: true });
        if (motion.matches) letters.forEach(letter => letter.classList.add('is-up'));
        else cycle(++runId);
        skipTimer = later(safe(() => {
            if (state === 'WAITING') { skip.hidden = false; skip.disabled = false; }
        }), slowAfter);
        listen(skip, 'click', () => release('skip', true));
        listen(overlay, 'transitionend', event => {
            if (event.target === overlay && event.propertyName === 'opacity' && state === 'RELEASED') removeVisual('ready');
        });
        listen(overlay, 'animationend', event => {
            if (event.target === overlay && event.animationName === 'auth-splash-expire') release('hard-timeout', true);
        });
        listen(motion, 'change', safe(() => {
            if (state !== 'WAITING') return;
            if (clock() >= deadline) return release('hard-timeout', true);
            freezeWave(false);
            letters.forEach(letter => { letter.style.transform = ''; });
            if (motion.matches) letters.forEach(letter => letter.classList.add('is-up'));
            else cycle(++runId);
            if (motion.matches) {
                minimumBypassed = true;
                cancel(minimumTimer);
                minimumTimer = null;
            }
            releaseWhenReady();
        }));
        if (renderReady) releaseWhenReady();
        else if (prerequisitesReady) queueReady();
    });

    win.AdasiAuthSplash = Object.freeze({
        get state() { return state; },
        get ready() { return ready; },
        get nativeDomReady() { return nativeDomReady; },
        get domReady() { return domReady; },
        get coreReady() { return coreReady; },
        get renderReady() { return renderReady; },
        markCoreReady() {
            if (!coreReady) {
                coreReady = true;
                mark('core-ready');
            }
            scan();
        },
        isWaiting: () => state === 'WAITING',
        hasVisual: () => visible && state !== 'REMOVED',
        removeVisual: exit => removeVisual(exit || 'loader-handoff'),
    });
    mark('bootstrap-start');
    watchdog = later(() => release('hard-timeout', true), Math.max(0, deadline - clock()));
    try {
        if (!win.MutationObserver || !win.requestAnimationFrame || !win.matchMedia) return release('unsupported', true);
        observer = new win.MutationObserver(safe(records => {
            reconcileBindings(records);
            scan();
        }));
        observer.observe(doc.documentElement, {
            childList: true, subtree: true, attributes: true,
            attributeFilter: ['inert', 'aria-busy'], attributeOldValue: true,
        });
        listen(doc, 'DOMContentLoaded', () => { domReady = true; scan(); });
        listen(doc, 'keydown', event => {
            if (state === 'RELEASED') return removeVisual('interaction');
            if (state !== 'WAITING') return;
            if (event.key === 'Escape') { event.preventDefault(); release('skip', true); }
            else if (event.key === 'Tab') {
                event.preventDefault();
                (skip.hidden ? panel : skip).focus({ preventScroll: true });
            }
        }, true);
        listen(doc, 'pointerdown', () => { if (state === 'RELEASED') removeVisual('interaction'); }, true);
        listen(doc, 'focusin', event => {
            if (restoringFocus) return;
            if (state === 'RELEASED') return removeVisual('interaction');
            if (state === 'WAITING' && !overlay.contains(event.target)) panel.focus({ preventScroll: true });
        }, true);
        listen(win, 'pageshow', event => {
            if (event.persisted) release('bfcache', true);
            else if (clock() >= deadline) release('hard-timeout', true);
        });
        listen(win, 'pagehide', () => release('pagehide', true));
        listen(win, 'pageswap', event => {
            if (visible && state !== 'REMOVED') {
                try { event.viewTransition?.skipTransition(); } finally { release('pageswap', true); }
            }
        });
        listen(doc, 'visibilitychange', () => {
            if (!doc.hidden) {
                if (clock() >= deadline) release('hard-timeout', true);
                else scan();
            }
        });
        scan();
    } catch { release('error', true); }
})();
