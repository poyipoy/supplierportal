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
    const deadline = clock() + delay(config.hardTimeout, 10000, 60000);
    const configuredMinimum = Number(config.minVisibleMs);
    const minimumVisible = typeof config.minVisibleMs === 'string' && config.minVisibleMs.trim() !== ''
        && Number.isFinite(configuredMinimum) && configuredMinimum >= 0 && configuredMinimum <= 10000
        ? configuredMinimum : 2000;
    const mediaFallbackMs = delay(config.mediaFallbackMs, 500, 5000);
    const slowAfter = delay(config.slowAfter, 2000, 2000);
    const fadeMs = delay(config.fadeMs, 500, 10000);
    const cleanupAfter = delay(config.cleanupMs, 750, 10000);
    const layout = config.layout || 'app';
    const mode = config.readiness || 'core';
    const roots = new Map();
    const ownedWrites = new Map();
    const removers = [];
    let state = 'ARMED';
    let ready = false;
    let coreReady = false;
    let nativeDomReady = false;
    let renderReady = false;
    let domReady = doc.readyState === 'complete';
    let overlay = null;
    let panel = null;
    let media = null;
    let skip = null;
    let observer = null;
    let motion = null;
    let watchdog = null;
    let mediaTimer = null;
    let skipTimer = null;
    let fadeTimer = null;
    let minimumTimer = null;
    let visibleAt = null;
    let mediaVisibleAt = null;
    let mediaSettled = false;
    let mediaFallbackApplied = false;
    let minimumBypassed = false;
    let readyFrame = null;
    let focusFrame = null;
    let restoreFocusFrame = null;
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
        restoreRoots();
        observer?.disconnect();
        [watchdog, mediaTimer, skipTimer, fadeTimer, minimumTimer].forEach(cancel);
        mediaTimer = null;
        minimumTimer = null;
        if (readyFrame !== null) win.cancelAnimationFrame?.(readyFrame);
        readyFrame = null;
        if (focusFrame !== null) win.cancelAnimationFrame?.(focusFrame);
        focusFrame = null;
        if (exit === 'error' || exit === 'pagehide' || exit === 'pageswap') {
            if (restoreFocusFrame !== null) win.cancelAnimationFrame?.(restoreFocusFrame);
            restoreFocusFrame = null;
        }
        removers.splice(0).forEach(remove => { try { remove(); } catch { /* Continue cleanup. */ } });
        doc.documentElement?.removeAttribute?.('data-auth-splash-wait');
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
        if (state === 'REMOVED') {
            if (exit === 'error' && restoreFocusFrame !== null) {
                win.cancelAnimationFrame?.(restoreFocusFrame);
                restoreFocusFrame = null;
            }
            return;
        }
        if (state === 'RELEASED') {
            if (immediate) removeVisual(exit);
            return;
        }
        const ownedFocus = overlay?.contains(doc.activeElement);
        state = 'RELEASED';
        observer?.disconnect();
        cancel(mediaTimer);
        mediaTimer = null;
        cancel(skipTimer);
        cancel(minimumTimer);
        minimumTimer = null;
        if (readyFrame !== null) win.cancelAnimationFrame?.(readyFrame);
        readyFrame = null;
        if (focusFrame !== null) win.cancelAnimationFrame?.(focusFrame);
        focusFrame = null;
        if (restoreFocusFrame !== null) win.cancelAnimationFrame?.(restoreFocusFrame);
        restoreFocusFrame = null;
        try {
            restoreRoots();
            if (overlay) {
                overlay.setAttribute('aria-hidden', 'true');
                overlay.setAttribute('data-state', 'released');
                overlay.style.pointerEvents = 'none';
                if (skip) { skip.hidden = true; skip.disabled = true; }
                if (!immediate && !motion?.matches && visible) overlay.classList.add('is-leaving');
            }
            if (ownedFocus && exit !== 'error' && exit !== 'pagehide' && exit !== 'pageswap') {
                const fallback = mode === 'native-login' ? doc.querySelector('#email') : doc.querySelector('#main-content');
                const target = returnFocus?.isConnected && returnFocus !== doc.body ? returnFocus : fallback;
                if (target?.isConnected && !target.inert && typeof win.requestAnimationFrame === 'function') {
                    restoreFocusFrame = win.requestAnimationFrame(safe(() => {
                        restoreFocusFrame = null;
                        if (!target.isConnected || target.inert) return;
                        if (doc.activeElement && doc.activeElement !== doc.body && !overlay?.contains(doc.activeElement)) return;
                        restoringFocus = true;
                        try { target.focus({ preventScroll: true }); } finally { restoringFocus = false; }
                    }));
                }
            }
        } finally {
            doc.documentElement?.removeAttribute?.('data-auth-splash-wait');
            mark('interaction-released');
            if (immediate || motion?.matches || !visible) removeVisual(exit);
            else fadeTimer = later(() => removeVisual(exit), cleanupAfter);
            emit('adasi:auth-splash-released', exit);
        }
    };
    const safe = fn => (...args) => {
        try { fn(...args); } catch { release('error', true); }
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
        const effectiveMinimum = minimumBypassed ? 0 : minimumVisible;
        // Early readiness is a fact, not permission to discard late markup.
        if (state === 'ARMED') {
            if (effectiveMinimum === 0) release('ready');
            return;
        }
        if (effectiveMinimum > 0 && !mediaSettled) return;
        const anchor = mediaVisibleAt ?? visibleAt;
        const remaining = effectiveMinimum === 0 ? 0 : anchor + effectiveMinimum - clock();
        if (remaining <= 0) return release('ready');
        if (minimumTimer === null) minimumTimer = later(safe(() => {
            minimumTimer = null;
            releaseWhenReady();
        }), Math.min(remaining, Math.max(0, deadline - clock())));
    };
    const settleMedia = (markName, at = clock()) => {
        cancel(mediaTimer);
        mediaTimer = null;
        if (!mediaSettled) {
            mediaSettled = true;
            mediaVisibleAt = at;
            if (markName) mark(markName);
        }
        releaseWhenReady();
    };
    const applyMediaFallback = reason => {
        cancel(mediaTimer);
        mediaTimer = null;
        if (!media) return settleMedia(reason);
        const fallbackSrc = media.getAttribute('data-fallback-src') || '';
        const currentSrc = media.currentSrc || media.getAttribute('src') || '';
        // Guard against looping if the active <picture> source already matches fallback.
        const canSwap = !mediaFallbackApplied && fallbackSrc !== '' && currentSrc !== fallbackSrc;
        if (canSwap) {
            mediaFallbackApplied = true;
            media.parentElement?.querySelectorAll?.('source[type="image/webp"]')?.forEach(source => source.remove());
            media.src = fallbackSrc;
            if (reason === 'media-error' && !mediaSettled && state === 'WAITING' && !motion?.matches && minimumVisible > 0) {
                const remainingBudget = Math.max(0, Math.min(mediaFallbackMs, deadline - clock(), visibleAt + mediaFallbackMs - clock()));
                if (remainingBudget > 0) {
                    mediaTimer = later(safe(() => {
                        mediaTimer = null;
                        if (!mediaSettled && state === 'WAITING') settleMedia('media-fallback-timeout');
                    }), remainingBudget);
                    return;
                }
            }
            settleMedia(reason);
            return;
        }
        mediaFallbackApplied = true;
        media.hidden = true;
        settleMedia(reason);
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
    const isCssReady = overlayEl => {
        try {
            const styleEl = doc.querySelector?.('style[data-auth-splash-styles]');
            if (styleEl?.sheet?.cssRules?.length > 0) return true;
        } catch { /* Fall back to computed style check if CSSOM inspection is unavailable. */ }
        return win.getComputedStyle(overlayEl).getPropertyValue('--auth-splash-css-ready').trim() === '1';
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
        media = overlay.querySelector('[data-auth-splash-media]');
        panel = overlay.querySelector('.auth-splash__panel');
        skip = overlay.querySelector('[data-auth-splash-skip]');
        if (!media || !panel || !skip || !overlay.querySelector('[data-auth-splash-status]')) return;
        if (!('inert' in overlay) || !isCssReady(overlay)) {
            return release('unsupported', true);
        }
        motion = win.matchMedia('(prefers-reduced-motion: reduce)');
        returnFocus = doc.activeElement;
        state = 'WAITING';
        doc.documentElement?.setAttribute?.('data-auth-splash-wait', '1');
        acquireRoots();
        overlay.hidden = false;
        overlay.removeAttribute('aria-hidden');
        overlay.setAttribute('data-state', 'waiting');
        overlay.style.setProperty('--splash-fade', fadeMs + 'ms');
        overlay.style.setProperty('--auth-splash-remaining', Math.max(0, deadline - clock()) + 'ms');
        visible = true;
        visibleAt = clock();
        minimumBypassed = motion.matches;
        mark('visible-start');
        focusFrame = win.requestAnimationFrame(safe(() => {
            focusFrame = null;
            if (state === 'WAITING' && panel && !overlay.contains(doc.activeElement)) {
                panel.focus({ preventScroll: true });
            }
        }));
        listen(media, 'load', safe(() => {
            if (state !== 'WAITING' || mediaSettled) return;
            if (media.naturalWidth > 0) settleMedia('media-ready');
        }));
        listen(media, 'error', safe(() => {
            if (state === 'RELEASED') { media.hidden = true; return; }
            if (state === 'WAITING') applyMediaFallback('media-error');
        }));
        if (motion.matches || minimumVisible === 0) {
            mediaSettled = true;
            mediaVisibleAt = visibleAt;
            if (media.complete && media.naturalWidth === 0) applyMediaFallback('media-error');
        } else if (media.complete) {
            if (media.naturalWidth > 0) {
                mediaSettled = true;
                mediaVisibleAt = visibleAt;
                mark('media-ready');
            } else {
                applyMediaFallback('media-error');
            }
        } else {
            mediaTimer = later(safe(() => {
                mediaTimer = null;
                if (!mediaSettled && state === 'WAITING') settleMedia('media-timeout');
            }), Math.min(mediaFallbackMs, Math.max(0, deadline - clock())));
        }
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
            if (motion.matches) {
                minimumBypassed = true;
                cancel(mediaTimer);
                mediaTimer = null;
                cancel(minimumTimer);
                minimumTimer = null;
                if (!mediaSettled) {
                    mediaSettled = true;
                    mediaVisibleAt = clock();
                }
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
