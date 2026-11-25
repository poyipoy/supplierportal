const bootedDocuments = new WeakSet();
const stateByDoc = new WeakMap();

const TOOLTIP_ID = 'adasiTooltipPopover';
const HIDE_DELAY_MS = 150;
const SHOW_DELAY_MS = 120;
const SIDEBAR_SHOW_DELAY_MS = 180;
const VIEWPORT_PADDING = 8;

function getDoc(target) {
    if (target?.nodeType === 9) return target;
    if (target?.ownerDocument) return target.ownerDocument;
    return typeof document !== 'undefined' ? document : null;
}

function getWindow(doc) {
    return doc?.defaultView || (typeof window !== 'undefined' ? window : null);
}

function getState(doc) {
    let state = stateByDoc.get(doc);
    if (!state) {
        state = {
            tooltipEl: null,
            activeTrigger: null,
            showTimer: null,
            hideTimer: null,
            pointerOverTrigger: false,
            pointerOverTooltip: false,
        };
        stateByDoc.set(doc, state);
    }
    return state;
}

function clearTimers(state) {
    if (state.showTimer !== null) {
        clearTimeout(state.showTimer);
        state.showTimer = null;
    }
    if (state.hideTimer !== null) {
        clearTimeout(state.hideTimer);
        state.hideTimer = null;
    }
}

function ensureTooltipElement(doc) {
    const state = getState(doc);
    if (state.tooltipEl && state.tooltipEl.isConnected !== false) {
        return state.tooltipEl;
    }

    const existing = doc.getElementById?.(TOOLTIP_ID);
    if (existing) {
        state.tooltipEl = existing;
        return existing;
    }

    if (!doc.body || typeof doc.createElement !== 'function') return null;
    const tip = doc.createElement('div');
    tip.id = TOOLTIP_ID;
    tip.className = 'ui-tooltip';
    tip.setAttribute('role', 'tooltip');
    tip.setAttribute('popover', 'manual');
    tip.hidden = true;
    doc.body.appendChild(tip);
    state.tooltipEl = tip;
    return tip;
}

function addDescribedByToken(trigger, token = TOOLTIP_ID) {
    const current = (trigger?.getAttribute?.('aria-describedby') || '').trim();
    const tokens = new Set(current ? current.split(/\s+/) : []);
    tokens.add(token);
    trigger?.setAttribute?.('aria-describedby', [...tokens].join(' '));
}

function removeDescribedByToken(trigger, token = TOOLTIP_ID) {
    const current = (trigger?.getAttribute?.('aria-describedby') || '').trim();
    if (!current) return;
    const tokens = current.split(/\s+/).filter((item) => item && item !== token);
    if (tokens.length) {
        trigger.setAttribute('aria-describedby', tokens.join(' '));
    } else {
        trigger.removeAttribute('aria-describedby');
    }
}

function stashTitleAttribute(trigger) {
    const nativeTitle = trigger?.getAttribute?.('title');
    if (nativeTitle) {
        trigger.setAttribute('data-ui-stashed-title', nativeTitle);
        trigger.removeAttribute('title');
    }
}

function restoreTitleAttribute(trigger) {
    const stashed = trigger?.getAttribute?.('data-ui-stashed-title');
    if (stashed !== null && stashed !== undefined) {
        if (!trigger.hasAttribute('title')) {
            trigger.setAttribute('title', stashed);
        }
        trigger.removeAttribute('data-ui-stashed-title');
    }
}

function isEligibleTrigger(trigger, doc) {
    if (!trigger || trigger.isConnected === false) return false;
    const text = (trigger.getAttribute?.('data-ui-tooltip') || '').trim();
    if (!text) return false;
    if (trigger.hasAttribute?.('data-sidebar-tooltip')) {
        return doc?.documentElement?.dataset?.sidebarCollapsed === 'true';
    }
    return true;
}

function positionTooltipFallback(trigger, tip, placement, win) {
    if (!trigger || !tip || !win || typeof trigger.getBoundingClientRect !== 'function' || typeof tip.getBoundingClientRect !== 'function') {
        return;
    }

    tip.style.position = 'fixed';
    tip.style.margin = '0';
    tip.style.inset = 'auto';

    const tRect = trigger.getBoundingClientRect();
    const tipRect = tip.getBoundingClientRect();
    const vw = win.innerWidth || 1024;
    const vh = win.innerHeight || 768;
    const tipW = tipRect.width || tip.offsetWidth || 120;
    const tipH = tipRect.height || tip.offsetHeight || 28;

    let left;
    let top;

    if (placement === 'right') {
        left = Math.min(vw - tipW - VIEWPORT_PADDING, tRect.right + 8);
        top = Math.max(VIEWPORT_PADDING, Math.min(vh - tipH - VIEWPORT_PADDING, tRect.top + (tRect.height - tipH) / 2));
    } else {
        left = tRect.left + (tRect.width - tipW) / 2;
        left = Math.max(VIEWPORT_PADDING, Math.min(vw - tipW - VIEWPORT_PADDING, left));
        top = tRect.top - tipH - 6;
        if (top < VIEWPORT_PADDING && tRect.bottom + 6 + tipH <= vh - VIEWPORT_PADDING) {
            top = tRect.bottom + 6;
        }
    }

    tip.style.left = `${Math.round(left)}px`;
    tip.style.top = `${Math.round(top)}px`;
}

function hideTooltipNow(doc) {
    const state = stateByDoc.get(doc);
    if (!state) return;
    clearTimers(state);

    const prevTrigger = state.activeTrigger;
    state.activeTrigger = null;
    state.pointerOverTrigger = false;
    state.pointerOverTooltip = false;

    if (prevTrigger) {
        prevTrigger.removeAttribute?.('data-ui-tooltip-open');
        removeDescribedByToken(prevTrigger, TOOLTIP_ID);
        restoreTitleAttribute(prevTrigger);
    }

    const tip = state.tooltipEl;
    if (!tip) return;

    tip.classList?.remove?.('sidebar-nav-tooltip');
    if (tip.style) {
        tip.style.position = '';
        tip.style.margin = '';
        tip.style.inset = '';
        tip.style.left = '';
        tip.style.top = '';
    }
    if (typeof tip.hidePopover === 'function' && tip.matches?.(':popover-open')) {
        try {
            tip.hidePopover();
        } catch {}
    }
    tip.hidden = true;
}

function showTooltipNow(trigger, doc) {
    if (!isEligibleTrigger(trigger, doc)) {
        hideTooltipNow(doc);
        return;
    }

    const state = getState(doc);
    const tip = ensureTooltipElement(doc);
    if (!tip) return;

    if (state.activeTrigger && state.activeTrigger !== trigger) {
        state.activeTrigger.removeAttribute?.('data-ui-tooltip-open');
        removeDescribedByToken(state.activeTrigger, TOOLTIP_ID);
        restoreTitleAttribute(state.activeTrigger);
    }

    const isSidebar = Boolean(trigger.hasAttribute?.('data-sidebar-tooltip'));
    const placement = isSidebar ? 'right' : (trigger.getAttribute?.('data-ui-tooltip-placement') || 'top');
    const text = (trigger.getAttribute?.('data-ui-tooltip') || '').trim();

    state.activeTrigger = trigger;
    trigger.setAttribute('data-ui-tooltip-open', 'true');
    stashTitleAttribute(trigger);
    addDescribedByToken(trigger, TOOLTIP_ID);

    tip.textContent = text;
    tip.setAttribute('data-ui-placement', placement);
    tip.classList?.toggle?.('sidebar-nav-tooltip', isSidebar);
    tip.hidden = false;

    if (typeof tip.showPopover === 'function') {
        try {
            if (tip.matches?.(':popover-open')) {
                tip.hidePopover();
            }
            tip.showPopover();
        } catch {}
    }

    const win = getWindow(doc);
    if (!win?.CSS?.supports?.('position-area', 'block-start')) {
        positionTooltipFallback(trigger, tip, placement, win);
    }
}

function scheduleHide(doc) {
    const state = getState(doc);
    if (state.showTimer !== null) {
        clearTimeout(state.showTimer);
        state.showTimer = null;
    }
    if (state.hideTimer !== null) {
        clearTimeout(state.hideTimer);
    }
    state.hideTimer = setTimeout(() => {
        state.hideTimer = null;
        if (state.pointerOverTrigger || state.pointerOverTooltip) return;
        const active = doc?.activeElement;
        if (state.activeTrigger && active && (active === state.activeTrigger || state.activeTrigger.contains?.(active))) {
            return;
        }
        hideTooltipNow(doc);
    }, HIDE_DELAY_MS);
}

export const AdasiTooltip = Object.freeze({
    refresh(root = typeof document !== 'undefined' ? document : null) {
        const doc = getDoc(root);
        if (!doc) return;
        const state = stateByDoc.get(doc);
        if (!state?.activeTrigger) return;
        if (!isEligibleTrigger(state.activeTrigger, doc)) {
            hideTooltipNow(doc);
            return;
        }
        if (root && root !== doc && typeof root.contains === 'function' && !root.contains(state.activeTrigger)) {
            return;
        }
        if (state.tooltipEl && !state.tooltipEl.hidden) {
            state.tooltipEl.textContent = (state.activeTrigger.getAttribute('data-ui-tooltip') || '').trim();
        }
    },
    hide(doc = typeof document !== 'undefined' ? document : null) {
        if (doc) hideTooltipNow(getDoc(doc));
    },
    syncSidebarState(collapsed, doc = typeof document !== 'undefined' ? document : null) {
        const resolvedDoc = getDoc(doc);
        const state = resolvedDoc ? stateByDoc.get(resolvedDoc) : null;
        if (!collapsed && state?.activeTrigger?.hasAttribute?.('data-sidebar-tooltip')) {
            hideTooltipNow(resolvedDoc);
        }
    },
    getElement(doc = typeof document !== 'undefined' ? document : null) {
        const resolvedDoc = getDoc(doc);
        return resolvedDoc ? stateByDoc.get(resolvedDoc)?.tooltipEl || null : null;
    },
});

/**
 * WCAG 1.4.13 compliant delegated tooltip controller (dismissible via Esc, hoverable, persistent).
 */
export function bootAdasiTooltips(root = typeof document !== 'undefined' ? document : null) {
    const doc = getDoc(root);
    if (!doc || typeof doc.addEventListener !== 'function') return;
    if (bootedDocuments.has(doc)) return;
    bootedDocuments.add(doc);

    doc.addEventListener('pointerover', (event) => {
        const target = event.target;
        if (!target?.closest) return;
        const state = getState(doc);

        if (target.closest(`#${TOOLTIP_ID}`)) {
            state.pointerOverTooltip = true;
            if (state.hideTimer !== null) {
                clearTimeout(state.hideTimer);
                state.hideTimer = null;
            }
            return;
        }

        const trigger = target.closest('[data-ui-tooltip]');
        if (!trigger || !isEligibleTrigger(trigger, doc)) return;
        if (trigger === state.activeTrigger && !state.tooltipEl?.hidden) {
            state.pointerOverTrigger = true;
            if (state.hideTimer !== null) {
                clearTimeout(state.hideTimer);
                state.hideTimer = null;
            }
            return;
        }

        clearTimers(state);
        state.pointerOverTrigger = true;
        const delay = trigger.hasAttribute('data-sidebar-tooltip') ? SIDEBAR_SHOW_DELAY_MS : SHOW_DELAY_MS;
        state.showTimer = setTimeout(() => {
            state.showTimer = null;
            if (state.pointerOverTrigger) {
                showTooltipNow(trigger, doc);
            }
        }, delay);
    });

    doc.addEventListener('pointerout', (event) => {
        const target = event.target;
        if (!target?.closest) return;
        const state = getState(doc);
        const related = event.relatedTarget;

        if (target.closest(`#${TOOLTIP_ID}`)) {
            if (!related?.closest?.(`#${TOOLTIP_ID}`)) {
                state.pointerOverTooltip = false;
                scheduleHide(doc);
            }
            return;
        }

        const trigger = target.closest('[data-ui-tooltip]');
        if (!trigger) return;
        if (related && trigger.contains?.(related)) return;
        if (trigger === state.activeTrigger || state.showTimer !== null) {
            state.pointerOverTrigger = false;
            scheduleHide(doc);
        }
    });

    doc.addEventListener('focusin', (event) => {
        const trigger = event.target?.closest?.('[data-ui-tooltip]');
        if (!trigger || !isEligibleTrigger(trigger, doc)) return;
        clearTimers(getState(doc));
        showTooltipNow(trigger, doc);
    });

    doc.addEventListener('focusout', (event) => {
        const trigger = event.target?.closest?.('[data-ui-tooltip]');
        if (!trigger) return;
        const related = event.relatedTarget;
        if (related && trigger.contains?.(related)) return;
        if (trigger === getState(doc).activeTrigger) {
            scheduleHide(doc);
        }
    });

    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const state = stateByDoc.get(doc);
        if (!state?.tooltipEl || state.tooltipEl.hidden) return;
        event.stopPropagation?.();
        hideTooltipNow(doc);
    }, true);
}
