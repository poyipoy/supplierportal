/**
 * ADASI Portal Supplier — Shared Overlay Modality Manager
 *
 * Provides ref-counted, multi-level ancestor-walk background `inert` management
 * (supporting both body-mounted overlays like lightbox/smart-panel AND content-mounted
 * `<x-ui.dialog>` / `<x-ui.drawer>` inside `#mainWrapper > #main-content`), preserves
 * preexisting `inert` flags (e.g. collapsed mobile sidebar), tracks Bootstrap `.modal`
 * lifecycle events without conflicting with Bootstrap focus traps, and restores focus safely.
 */

const stateByDoc = new WeakMap();
const returnFocusMap = new WeakMap();
const boundBootstrapDocs = new WeakSet();

function getDocState(doc) {
    let state = stateByDoc.get(doc);
    if (!state) {
        state = {
            activeStack: [],
            ownedNodes: new Set(),
            ownedBaseline: new WeakMap(),
            bootstrapModals: new Set(),
            observer: null,
        };
        stateByDoc.set(doc, state);
    }
    return state;
}

function isExemptNode(node) {
    if (!node) return true;
    if (
        node.id === 'adasi-form-announcer'
        || node.id === 'adasi-toast-announcer'
        || node.id === 'adasi-toast-announcer-assertive'
        || node.id === 'adasiLoader'
        || node.classList?.contains?.('adasi-toast-region')
        || node.classList?.contains?.('adasi-loader-overlay')
    ) {
        return true;
    }
    const tag = node.tagName;
    return tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEMPLATE';
}

function getAncestorChain(overlayEl, doc) {
    const ancestors = new Set();
    let cursor = overlayEl;
    while (cursor && cursor !== doc.body) {
        ancestors.add(cursor);
        cursor = cursor.parentElement;
    }
    return ancestors;
}

function collectBackgroundSiblings(overlayEl, doc) {
    if (!overlayEl || !doc?.body) return [];

    const targets = [];
    let cursor = overlayEl;
    while (cursor && cursor !== doc.body && cursor.parentElement) {
        const parent = cursor.parentElement;
        const children = Array.from(parent.children || []);
        for (const sibling of children) {
            if (sibling === cursor || sibling === overlayEl) continue;
            if (isExemptNode(sibling)) continue;
            targets.push(sibling);
        }
        if (parent === doc.body) break;
        cursor = parent;
    }

    return targets;
}

function emitModalityChange(doc, state) {
    const activeCount = state.activeStack.length + state.bootstrapModals.size;
    doc.defaultView?.dispatchEvent?.(new CustomEvent('adasi:overlay-modality-change', {
        detail: {
            activeCount,
            overlayCount: state.activeStack.length,
            bootstrapModalCount: state.bootstrapModals.size,
        },
    }));
}

function syncOverlayInertState(doc, state) {
    if (state.activeStack.length === 0) {
        for (const node of state.ownedNodes) {
            node.inert = Boolean(state.ownedBaseline.get(node));
            state.ownedBaseline.delete(node);
        }
        state.ownedNodes.clear();
        doc.body.classList?.remove('ui-dialog-open');
        return;
    }

    const activeTop = state.activeStack[state.activeStack.length - 1];
    const topTargets = new Set(collectBackgroundSiblings(activeTop, doc));
    const topAncestors = getAncestorChain(activeTop, doc);

    for (const node of [...topTargets, ...topAncestors]) {
        if (!state.ownedNodes.has(node)) {
            state.ownedBaseline.set(node, Boolean(node.inert));
            state.ownedNodes.add(node);
        }
    }

    for (const node of state.ownedNodes) {
        if (topAncestors.has(node)) {
            node.inert = false;
        } else if (topTargets.has(node)) {
            node.inert = true;
        } else {
            node.inert = Boolean(state.ownedBaseline.get(node));
        }
    }

    doc.body.classList?.add('ui-dialog-open');
}

function syncObserver(doc, state) {
    if (typeof MutationObserver !== 'function' || !doc?.body || typeof doc.body.nodeType !== 'number') {
        return;
    }

    const shouldObserve = state.activeStack.length > 0 || state.bootstrapModals.size > 0;
    if (shouldObserve && !state.observer) {
        state.observer = new MutationObserver(() => {
            pruneDisconnected(doc, state);
        });
        state.observer.observe(doc.body, { childList: true, subtree: true });
    } else if (!shouldObserve && state.observer) {
        state.observer.disconnect();
        state.observer = null;
    }
}

function cleanupStrandedBootstrapState(doc) {
    if (!doc?.body) return false;
    const remainingOpen = Array.from(doc.querySelectorAll?.('.modal.show') || [])
        .filter((el) => el && el.isConnected !== false);
    if (remainingOpen.length > 0) return false;

    let cleaned = false;
    for (const backdrop of Array.from(doc.querySelectorAll?.('.modal-backdrop') || [])) {
        backdrop?.remove?.();
        cleaned = true;
    }
    if (doc.body.classList?.contains?.('modal-open')) {
        doc.body.classList.remove('modal-open');
        cleaned = true;
    }
    if (doc.body.style) {
        if (doc.body.style.overflow) {
            doc.body.style.overflow = '';
            cleaned = true;
        }
        if (doc.body.style.paddingRight) {
            doc.body.style.paddingRight = '';
            cleaned = true;
        }
    }
    return cleaned;
}

function pruneDisconnected(doc, state) {
    let changed = false;

    for (let i = state.activeStack.length - 1; i >= 0; i -= 1) {
        const el = state.activeStack[i];
        if (!el || el.isConnected === false) {
            state.activeStack.splice(i, 1);
            if (el) returnFocusMap.delete(el);
            changed = true;
        }
    }

    let bootstrapRemoved = false;
    for (const modal of Array.from(state.bootstrapModals)) {
        if (!modal || modal.isConnected === false) {
            state.bootstrapModals.delete(modal);
            bootstrapRemoved = true;
            changed = true;
        }
    }

    if (bootstrapRemoved) {
        cleanupStrandedBootstrapState(doc);
    }

    if (changed) {
        syncOverlayInertState(doc, state);
        syncObserver(doc, state);
        emitModalityChange(doc, state);
    }

    return changed;
}

export function bindBootstrapModalModality(doc = globalThis.document) {
    if (!doc || typeof doc.addEventListener !== 'function' || boundBootstrapDocs.has(doc)) return;
    boundBootstrapDocs.add(doc);

    doc.addEventListener('show.bs.modal', (event) => {
        const modal = event.target;
        if (!modal) return;
        const state = getDocState(doc);
        if (!state.bootstrapModals.has(modal)) {
            state.bootstrapModals.add(modal);
            syncObserver(doc, state);
            emitModalityChange(doc, state);
        }
    });

    doc.addEventListener('hidden.bs.modal', (event) => {
        const modal = event.target;
        if (!modal) return;
        const state = getDocState(doc);
        if (state.bootstrapModals.delete(modal)) {
            syncObserver(doc, state);
            emitModalityChange(doc, state);
        }
    });

    doc.defaultView?.addEventListener?.('pageshow', (event) => {
        if (!event?.persisted) return;
        const state = stateByDoc.get(doc);
        if (state) {
            pruneDisconnected(doc, state);
        }
        cleanupStrandedBootstrapState(doc);
    });
}

function isSessionExpiredOverlay(node) {
    return Boolean(
        node && (
            node.hasAttribute?.('data-session-expired-modal')
            || node.dataset?.sessionExpiredModal !== undefined
        ),
    );
}

function isDistinctOverlayRoot(node) {
    return Boolean(
        node && (
            node.matches?.('[data-ui-dialog], [data-ui-drawer], [data-session-expired-modal], #adasi-smart-panel, #adasiImageLightbox')
            || node.dataset?.uiDialog !== undefined
            || node.dataset?.uiDrawer !== undefined
            || node.hasAttribute?.('data-session-expired-modal')
            || node.id === 'adasi-smart-panel'
            || node.id === 'adasiImageLightbox'
        ),
    );
}

export function acquireOverlayModality(overlayEl, { triggerEl = null, doc = globalThis.document } = {}) {
    if (!overlayEl || !doc?.body || overlayEl.isConnected === false) return;
    const state = getDocState(doc);
    pruneDisconnected(doc, state);

    const existingIndex = state.activeStack.indexOf(overlayEl);
    if (existingIndex === -1) {
        const invoker = triggerEl ?? doc.activeElement ?? null;
        if (invoker) {
            returnFocusMap.set(overlayEl, invoker);
        }
        state.activeStack.push(overlayEl);
    } else if (existingIndex !== state.activeStack.length - 1) {
        state.activeStack.splice(existingIndex, 1);
        state.activeStack.push(overlayEl);
    }

    // Keep blocking session-expired modals above lower-layer dialogs/drawers so
    // async background overlays can never inert an active session-expired prompt.
    if (!isSessionExpiredOverlay(overlayEl)) {
        const sessionOverlays = state.activeStack.filter(isSessionExpiredOverlay);
        if (sessionOverlays.length > 0) {
            state.activeStack = [
                ...state.activeStack.filter((el) => !isSessionExpiredOverlay(el)),
                ...sessionOverlays,
            ];
        }
    }

    syncOverlayInertState(doc, state);
    syncObserver(doc, state);
    emitModalityChange(doc, state);
}

export function releaseOverlayModality(overlayEl, { restoreFocus = true, doc = globalThis.document } = {}) {
    if (!overlayEl || !doc?.body) return;
    const state = getDocState(doc);
    pruneDisconnected(doc, state);

    let index = state.activeStack.lastIndexOf(overlayEl);
    let targetOverlay = overlayEl;
    if (index === -1 && !isDistinctOverlayRoot(overlayEl)) {
        for (let i = state.activeStack.length - 1; i >= 0; i -= 1) {
            const candidate = state.activeStack[i];
            if (typeof candidate?.contains === 'function' && candidate.contains(overlayEl)) {
                index = i;
                targetOverlay = candidate;
                break;
            }
        }
    }

    if (index !== -1) {
        state.activeStack.splice(index, 1);
    }

    syncOverlayInertState(doc, state);
    syncObserver(doc, state);

    const invoker = returnFocusMap.get(targetOverlay) ?? returnFocusMap.get(overlayEl);
    returnFocusMap.delete(targetOverlay);
    if (targetOverlay !== overlayEl) {
        returnFocusMap.delete(overlayEl);
    }
    if (restoreFocus && invoker && invoker.isConnected !== false && typeof invoker.focus === 'function') {
        invoker.focus({ preventScroll: true });
    }

    emitModalityChange(doc, state);
}

export function releaseOverlaysWithin(container, { doc = globalThis.document } = {}) {
    if (!container || !doc?.body) return;
    const state = getDocState(doc);
    pruneDisconnected(doc, state);

    const isInside = (node) => Boolean(
        node && (node === container || (typeof container.contains === 'function' && container.contains(node))),
    );

    const matchingOverlays = state.activeStack.filter(isInside);
    for (let i = matchingOverlays.length - 1; i >= 0; i -= 1) {
        releaseOverlayModality(matchingOverlays[i], { restoreFocus: false, doc });
    }

    const bsModals = new Set(Array.from(container.querySelectorAll?.('.modal') || []));
    for (const modal of Array.from(state.bootstrapModals)) {
        if (isInside(modal)) {
            bsModals.add(modal);
        }
    }

    let touchedBootstrap = false;
    for (const modal of bsModals) {
        touchedBootstrap = true;
        try {
            doc.defaultView?.bootstrap?.Modal?.getInstance?.(modal)?.hide?.();
        } catch {
            // Best-effort hide before DOM swap removes the modal subtree.
        }
        modal.classList?.remove?.('show');
        state.bootstrapModals.delete(modal);
    }

    if (touchedBootstrap || doc.body.classList?.contains?.('modal-open')) {
        cleanupStrandedBootstrapState(doc);
        syncObserver(doc, state);
        emitModalityChange(doc, state);
    }
}

export function getActiveOverlayCount(doc = globalThis.document) {
    if (!doc) return 0;
    const state = stateByDoc.get(doc);
    if (!state) return 0;
    pruneDisconnected(doc, state);
    return state.activeStack.length + state.bootstrapModals.size;
}

export function resetOverlayModality(doc = globalThis.document) {
    if (!doc) return;
    const state = stateByDoc.get(doc);
    if (!state) return;
    state.bootstrapModals.clear();
    while (state.activeStack.length > 0) {
        const top = state.activeStack[state.activeStack.length - 1];
        releaseOverlayModality(top, { restoreFocus: false, doc });
    }
    syncOverlayInertState(doc, state);
    syncObserver(doc, state);
}

if (typeof document !== 'undefined') {
    bindBootstrapModalModality(document);
}

if (typeof window !== 'undefined') {
    window.AdasiOverlayModality = Object.freeze({
        acquire: acquireOverlayModality,
        release: releaseOverlayModality,
        releaseWithin: releaseOverlaysWithin,
        activeCount: getActiveOverlayCount,
        reset: resetOverlayModality,
    });
}
