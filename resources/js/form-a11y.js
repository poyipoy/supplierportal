/**
 * ADASI Portal Supplier — Accessible Form Error Announcement & `:user-invalid` ARIA Bridge
 *
 * 1. Mounts a single visually hidden polite live region (`#adasi-form-announcer`) on load so
 *    dynamic validation errors are announced reliably (showing a newly inserted live region is
 *    unreliable across screen readers).
 * 2. Syncs `:user-invalid` with `aria-invalid` and optional `data-error` -> `aria-describedby`
 *    links only after user interaction (`blur`, `change`, `invalid`, `reset`), preserving any
 *    server-rendered or Alpine-managed `aria-invalid` attributes.
 */

const ANNOUNCER_ID = 'adasi-form-announcer';
const FIELD_SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]), select, textarea';

let announceTimer = null;
let announcedField = null;

function getOrCreateAnnouncer(doc = globalThis.document) {
    if (!doc?.body) return null;
    let announcer = doc.getElementById(ANNOUNCER_ID);
    if (!announcer) {
        announcer = doc.createElement('div');
        announcer.id = ANNOUNCER_ID;
        announcer.setAttribute('aria-live', 'polite');
        announcer.setAttribute('aria-atomic', 'true');
        announcer.className = 'tw:sr-only';
        doc.body.append(announcer);
    }
    return announcer;
}

/**
 * Announce a form validation message via the pre-mounted polite live region.
 *
 * @param {string} message
 * @param {{ delayMs?: number, doc?: Document, field?: Element | null }} [options]
 */
export function announceFormError(message, { delayMs = 0, doc = globalThis.document, field = null } = {}) {
    const text = String(message || '').replace(/\s+/g, ' ').trim();
    if (!text) return;

    const announcer = getOrCreateAnnouncer(doc);
    if (!announcer) return;

    if (announceTimer !== null) {
        clearTimeout(announceTimer);
        announceTimer = null;
    }

    announcer.textContent = '';
    announcedField = field;

    const write = () => {
        announceTimer = null;
        announcer.textContent = text;
    };

    if (delayMs > 0) {
        announceTimer = setTimeout(write, delayMs);
    } else {
        // Yield one microtask/frame so clearing then writing triggers SR live-region mutation.
        announceTimer = setTimeout(write, 30);
    }
}

export function clearFormAnnouncement(doc = globalThis.document, field = null) {
    if (field && announcedField && announcedField !== field) return;
    if (announceTimer !== null) {
        clearTimeout(announceTimer);
        announceTimer = null;
    }
    const announcer = doc?.getElementById?.(ANNOUNCER_ID);
    if (announcer) announcer.textContent = '';
    announcedField = null;
}

function spokenText(node) {
    if (!node) return '';
    const copy = node.cloneNode(true);
    copy.querySelectorAll?.('[aria-hidden="true"]').forEach((n) => n.remove());
    copy.querySelectorAll?.('[role="img"][aria-label]').forEach((n) => {
        n.replaceWith(`${n.getAttribute('aria-label')}: `);
    });
    return (copy.textContent || '').replace(/\s+/g, ' ').trim();
}

function linkDataError(el, shown) {
    const errorId = el?.dataset?.error;
    if (!errorId) return;
    const ids = (el.getAttribute('aria-describedby') || '')
        .split(/\s+/)
        .filter((id) => id && id !== errorId);
    if (shown) ids.push(errorId);
    if (ids.length) el.setAttribute('aria-describedby', ids.join(' '));
    else el.removeAttribute('aria-describedby');
}

function isManagedExternally(el) {
    return (
        el.hasAttribute('data-async-invalid')
        || el.hasAttribute(':aria-invalid')
        || el.hasAttribute('x-bind:aria-invalid')
        || el.hasAttribute('data-skip-user-invalid-sync')
    );
}

export function bootFormA11y(doc = globalThis.document) {
    if (!doc?.body) return null;
    getOrCreateAnnouncer(doc);

    const supportsUserInvalid = typeof globalThis.CSS?.supports === 'function'
        && globalThis.CSS.supports('selector(:user-invalid)');

    const flagged = new WeakSet();
    const announced = new WeakSet();
    const dirtyState = new WeakMap();

    const isFieldInvalidAfterInteraction = (el) => {
        if (supportsUserInvalid) {
            try {
                return el.matches(':user-invalid');
            } catch {
                // Fall through to WeakMap state below.
            }
        }
        const state = dirtyState.get(el);
        return Boolean(state?.hasInteracted && state?.hasBlurred && el.validity && !el.validity.valid);
    };

    const syncAria = (el, announce = false) => {
        if (!(el instanceof Element) || !el.matches(FIELD_SELECTOR)) return;
        if (isManagedExternally(el)) return;

        const invalid = isFieldInvalidAfterInteraction(el);
        if (!supportsUserInvalid && dirtyState.has(el)) {
            el.classList.toggle('user-invalid-fallback', invalid);
            el.classList.toggle('user-valid-fallback', !invalid && Boolean(el.validity?.valid));
        }

        if (invalid) {
            if (announce && !announced.has(el)) {
                const errorNode = el.dataset.error ? doc.getElementById(el.dataset.error) : null;
                if (errorNode) {
                    const label = el.labels?.[0] ? `${spokenText(el.labels[0])}: ` : '';
                    announceFormError(label + spokenText(errorNode), { delayMs: 500, doc, field: el });
                }
                announced.add(el);
            }
            el.setAttribute('aria-invalid', 'true');
            linkDataError(el, true);
            flagged.add(el);
        } else if (flagged.has(el) || el.getAttribute('aria-invalid') !== 'true') {
            el.setAttribute('aria-invalid', 'false');
            linkDataError(el, false);
            flagged.delete(el);
            announced.delete(el);
            clearFormAnnouncement(doc, el);
        }
    };

    const syncContainer = (root = doc) => {
        if (!root || typeof root.querySelectorAll !== 'function') return;
        // Baseline: set aria-invalid="false" on unconstrained/unflagged fields so Firefox/Safari
        // do not expose empty required fields as invalid before interaction.
        root.querySelectorAll(FIELD_SELECTOR).forEach((el) => {
            if (!isManagedExternally(el) && !el.hasAttribute('aria-invalid')) {
                el.setAttribute('aria-invalid', 'false');
            }
        });
    };

    syncContainer(doc);

    doc.addEventListener('blur', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches(FIELD_SELECTOR)) return;
        if (!supportsUserInvalid) {
            const state = dirtyState.get(target) || { hasInteracted: false, hasBlurred: false };
            state.hasBlurred = true;
            dirtyState.set(target, state);
        }
        syncAria(target, true);
    }, true);

    doc.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches(FIELD_SELECTOR)) return;
        if (!supportsUserInvalid) {
            const state = dirtyState.get(target) || { hasInteracted: false, hasBlurred: false };
            state.hasInteracted = true;
            dirtyState.set(target, state);
        }
        if (target.hasAttribute('aria-invalid')) syncAria(target, false);
    });

    doc.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches(FIELD_SELECTOR)) return;
        if (!supportsUserInvalid) {
            const state = dirtyState.get(target) || { hasInteracted: false, hasBlurred: false };
            state.hasInteracted = true;
            state.hasBlurred = true;
            dirtyState.set(target, state);
        }
        syncAria(target, true);
    }, true);

    doc.addEventListener('invalid', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !target.matches(FIELD_SELECTOR)) return;
        if (!supportsUserInvalid) {
            dirtyState.set(target, { hasInteracted: true, hasBlurred: true });
        }
        announced.add(target);
        syncAria(target, false);
    }, true);

    doc.addEventListener('reset', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        setTimeout(() => {
            for (const el of Array.from(form.elements)) {
                dirtyState.delete(el);
                el.classList?.remove('user-invalid-fallback', 'user-valid-fallback');
                syncAria(el, false);
            }
        });
    }, true);

    return {
        announce: (message, options) => announceFormError(message, { doc, ...options }),
        clear: () => clearFormAnnouncement(doc),
        syncField: syncAria,
        syncContainer,
    };
}

if (typeof document !== 'undefined') {
    const init = () => {
        const api = bootFormA11y(document);
        if (api && typeof window !== 'undefined') {
            window.AdasiFormA11y = Object.freeze(api);
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}
