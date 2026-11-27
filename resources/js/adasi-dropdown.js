const bootedDocuments = new WeakSet();
const repositionCleanups = new WeakMap();

const MENU_SELECTOR = '[data-ui-dropdown-menu], .dropdown-menu';
const TRIGGER_SELECTOR = '[data-ui-dropdown-trigger]';
const ITEM_SELECTOR = 'a.dropdown-item[href], button.dropdown-item:not([disabled]):not([aria-disabled="true"]), [data-ui-dropdown-item]:not([disabled]):not([aria-disabled="true"])';
const VIEWPORT_PADDING = 8;
const ANCHOR_GAP = 4;

function getDoc(target) {
    if (target?.nodeType === 9) return target;
    if (target?.ownerDocument) return target.ownerDocument;
    return typeof document !== 'undefined' ? document : null;
}

function getWindow(node, doc) {
    return node?.ownerDocument?.defaultView || doc?.defaultView || (typeof window !== 'undefined' ? window : null);
}

function escapeId(id, win) {
    if (win?.CSS?.escape) return win.CSS.escape(String(id));
    if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(String(id));
    return String(id).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}

function isDropdownMenu(el) {
    return Boolean(el && typeof el === 'object' && (el.hasAttribute?.('data-ui-dropdown-menu') || el.classList?.contains?.('dropdown-menu')));
}

function isMenuOpen(menu) {
    if (!menu) return false;
    return Boolean(menu.matches?.(':popover-open') || menu.classList?.contains?.('show') || menu.hasAttribute?.('data-ui-dropdown-open'));
}

function resolveTriggerForMenu(menu, doc) {
    if (!menu || !doc) return null;
    if (menu.id) {
        const escaped = escapeId(menu.id, getWindow(menu, doc));
        const byPopoverTarget = doc.querySelector?.(`[popovertarget="${escaped}"]`);
        if (byPopoverTarget) return byPopoverTarget;
        const byAriaControls = doc.querySelector?.(`[data-ui-dropdown-trigger][aria-controls="${escaped}"]`);
        if (byAriaControls) return byAriaControls;
    }
    const wrapper = menu.closest?.('.dropdown, .dropup, .dropstart, .dropend, .btn-group') || menu.parentElement;
    return wrapper?.querySelector?.(TRIGGER_SELECTOR) || null;
}

function resolveMenuAndTrigger(target, explicitDoc = null) {
    const doc = explicitDoc || getDoc(target);
    if (!target || !doc) return { doc: null, trigger: null, menu: null };

    if (isDropdownMenu(target)) {
        return { doc, trigger: resolveTriggerForMenu(target, doc), menu: target };
    }

    const trigger = target.matches?.(TRIGGER_SELECTOR) || target.hasAttribute?.('popovertarget')
        ? target
        : target.closest?.(TRIGGER_SELECTOR) || target.closest?.('[popovertarget]');

    if (trigger) {
        const menuId = trigger.getAttribute?.('popovertarget') || trigger.getAttribute?.('aria-controls');
        let menu = menuId ? doc.getElementById?.(menuId) : null;
        if (!menu) {
            const wrapper = trigger.closest?.('.dropdown, .dropup, .dropstart, .dropend, .btn-group') || trigger.parentElement;
            menu = wrapper?.querySelector?.(MENU_SELECTOR) || null;
        }
        return { doc, trigger, menu };
    }

    const enclosingMenu = target.closest?.(MENU_SELECTOR);
    if (enclosingMenu) {
        return { doc, trigger: resolveTriggerForMenu(enclosingMenu, doc), menu: enclosingMenu };
    }

    return { doc, trigger: null, menu: null };
}

function dispatchDropdownEvent(node, name, detail, win) {
    if (!node || typeof node.dispatchEvent !== 'function') return;
    const CustomEvt = win?.CustomEvent || CustomEvent;
    node.dispatchEvent(new CustomEvt(name, { bubbles: true, detail }));
}

function safeFocus(el) {
    if (!el || el.isConnected === false || typeof el.focus !== 'function') return;
    try {
        el.focus({ preventScroll: true });
    } catch {
        el.focus();
    }
}

function supportsAnchorPositioning(win) {
    return Boolean(win?.CSS?.supports?.('position-area', 'block-end'));
}

function positionMenuFallback(trigger, menu, win) {
    if (!trigger || !menu || !win || typeof trigger.getBoundingClientRect !== 'function' || typeof menu.getBoundingClientRect !== 'function') {
        return;
    }

    menu.style.position = 'fixed';
    menu.style.margin = '0';
    menu.style.inset = 'auto';

    const triggerRect = trigger.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const vw = win.innerWidth || 1024;
    const vh = win.innerHeight || 768;
    const alignEnd = Boolean(menu.classList?.contains?.('dropdown-menu-end') || menu.dataset?.uiAlign === 'end');

    const menuWidth = menuRect.width || menu.offsetWidth || 220;
    const menuHeight = menuRect.height || menu.offsetHeight || 160;

    let left = alignEnd ? triggerRect.right - menuWidth : triggerRect.left;
    if (left + menuWidth > vw - VIEWPORT_PADDING) {
        left = vw - menuWidth - VIEWPORT_PADDING;
    }
    if (left < VIEWPORT_PADDING) {
        left = VIEWPORT_PADDING;
    }

    const spaceBelow = vh - triggerRect.bottom - ANCHOR_GAP;
    const spaceAbove = triggerRect.top - ANCHOR_GAP;
    let top = triggerRect.bottom + ANCHOR_GAP;
    if (menuHeight > spaceBelow && spaceAbove > spaceBelow) {
        top = Math.max(VIEWPORT_PADDING, triggerRect.top - ANCHOR_GAP - menuHeight);
    } else if (top + menuHeight > vh - VIEWPORT_PADDING) {
        top = Math.max(VIEWPORT_PADDING, vh - menuHeight - VIEWPORT_PADDING);
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
}

function bindFallbackReposition(trigger, menu, win) {
    cleanupFallbackPosition(menu);
    if (!trigger || !menu || !win?.addEventListener) return;

    const reposition = () => {
        if (!isMenuOpen(menu) || trigger.isConnected === false || menu.isConnected === false) {
            cleanupFallbackPosition(menu);
            return;
        }
        positionMenuFallback(trigger, menu, win);
    };

    reposition();
    win.addEventListener('scroll', reposition, { capture: true, passive: true });
    win.addEventListener('resize', reposition, { passive: true });

    repositionCleanups.set(menu, () => {
        win.removeEventListener('scroll', reposition, { capture: true });
        win.removeEventListener('resize', reposition);
    });
}

function cleanupFallbackPosition(menu) {
    const cleanup = repositionCleanups.get(menu);
    if (cleanup) {
        cleanup();
        repositionCleanups.delete(menu);
    }
    if (menu?.style) {
        menu.style.position = '';
        menu.style.margin = '';
        menu.style.inset = '';
        menu.style.left = '';
        menu.style.top = '';
    }
}

function clearActiveAnchors(doc, exceptMenu = null) {
    if (!doc?.querySelectorAll) return;
    doc.querySelectorAll('[data-ui-dropdown-open="true"]').forEach((node) => {
        if (node === exceptMenu) return;
        const { menu } = resolveMenuAndTrigger(node, doc);
        if (exceptMenu && menu === exceptMenu) return;
        node.removeAttribute?.('data-ui-dropdown-open');
    });
}

function ensureTriggerHasPopup(trigger) {
    if (trigger && !trigger.hasAttribute?.('aria-haspopup')) {
        trigger.setAttribute?.('aria-haspopup', 'menu');
    }
}

function markOpenVisualState(trigger, menu, doc, win) {
    clearActiveAnchors(doc, menu);
    ensureTriggerHasPopup(trigger);
    trigger?.setAttribute?.('data-ui-dropdown-open', 'true');
    menu?.setAttribute?.('data-ui-dropdown-open', 'true');
    dispatchDropdownEvent(trigger || menu, 'adasi:dropdown-show', { menu, trigger }, win);
}

function completeOpenState(trigger, menu, win) {
    trigger?.setAttribute?.('aria-expanded', 'true');
    menu?.setAttribute?.('data-ui-dropdown-open', 'true');
    if (!supportsAnchorPositioning(win)) {
        bindFallbackReposition(trigger, menu, win);
    }
}

function completeClosedState(trigger, menu, win) {
    cleanupFallbackPosition(menu);
    trigger?.removeAttribute?.('data-ui-dropdown-open');
    menu?.removeAttribute?.('data-ui-dropdown-open');
    menu?.classList?.remove?.('show');
    trigger?.classList?.remove?.('show');
    trigger?.setAttribute?.('aria-expanded', 'false');
    dispatchDropdownEvent(trigger || menu, 'adasi:dropdown-hidden', { menu, trigger }, win);
}

function focusItem(menu, indexSelector) {
    if (!menu?.querySelectorAll) return;
    const items = [...menu.querySelectorAll(ITEM_SELECTOR)].filter((el) => !el.hidden && el.getAttribute?.('aria-hidden') !== 'true');
    if (!items.length) return;
    const target = indexSelector === 'last' ? items[items.length - 1] : items[0];
    safeFocus(target);
}

function hideMenu(menu, trigger = null, doc = getDoc(menu)) {
    if (!menu) return;
    const resolvedTrigger = trigger || resolveTriggerForMenu(menu, doc);
    const win = getWindow(menu, doc);

    if (typeof menu.hidePopover === 'function') {
        if (menu.matches?.(':popover-open')) {
            try {
                menu.hidePopover();
            } catch {
                completeClosedState(resolvedTrigger, menu, win);
            }
        }
        return;
    }

    if (isMenuOpen(menu)) {
        completeClosedState(resolvedTrigger, menu, win);
    }
}

function showMenu(menu, trigger = null, doc = getDoc(menu)) {
    if (!menu) return;
    const resolvedTrigger = trigger || resolveTriggerForMenu(menu, doc);
    const win = getWindow(menu, doc);

    if (typeof menu.showPopover === 'function') {
        if (!menu.matches?.(':popover-open')) {
            try {
                menu.showPopover();
            } catch {
                markOpenVisualState(resolvedTrigger, menu, doc, win);
                completeOpenState(resolvedTrigger, menu, win);
            }
        }
        return;
    }

    if (!isMenuOpen(menu)) {
        dismissAllMenus(doc, resolvedTrigger);
        markOpenVisualState(resolvedTrigger, menu, doc, win);
        menu.classList?.add?.('show');
        resolvedTrigger?.classList?.add?.('show');
        completeOpenState(resolvedTrigger, menu, win);
    }
}

function dismissAllMenus(doc = typeof document !== 'undefined' ? document : null, exceptToggle = null) {
    if (!doc?.querySelectorAll) return;
    const openMenus = new Set();
    doc.querySelectorAll('[data-ui-dropdown-menu]:popover-open, .dropdown-menu:popover-open, [data-ui-dropdown-menu].show, .dropdown-menu.show, [data-ui-dropdown-menu][data-ui-dropdown-open="true"]').forEach((menu) => {
        openMenus.add(menu);
    });

    openMenus.forEach((menu) => {
        const trigger = resolveTriggerForMenu(menu, doc);
        if (exceptToggle && trigger === exceptToggle) return;
        hideMenu(menu, trigger, doc);
    });
}

export const AdasiDropdown = Object.freeze({
    show(target, doc = null) {
        const { doc: resolvedDoc, trigger, menu } = resolveMenuAndTrigger(target, doc);
        showMenu(menu, trigger, resolvedDoc);
    },
    hide(target, doc = null) {
        const { doc: resolvedDoc, trigger, menu } = resolveMenuAndTrigger(target, doc);
        hideMenu(menu, trigger, resolvedDoc);
    },
    toggle(target, doc = null) {
        const { doc: resolvedDoc, trigger, menu } = resolveMenuAndTrigger(target, doc);
        if (!menu) return;
        if (isMenuOpen(menu)) {
            hideMenu(menu, trigger, resolvedDoc);
        } else {
            showMenu(menu, trigger, resolvedDoc);
        }
    },
    dismissAll(exceptToggle = null, doc = typeof document !== 'undefined' ? document : null) {
        dismissAllMenus(doc, exceptToggle);
    },
});

/**
 * Delegated Native Popover API dropdown controller with CSS Anchor Positioning + viewport fallback.
 */
export function bootAdasiDropdowns(root = typeof document !== 'undefined' ? document : null) {
    const doc = getDoc(root);
    if (!doc || typeof doc.addEventListener !== 'function') return;
    if (bootedDocuments.has(doc)) return;
    bootedDocuments.add(doc);

    doc.querySelectorAll?.(TRIGGER_SELECTOR)?.forEach(ensureTriggerHasPopup);

    doc.addEventListener('beforetoggle', (event) => {
        const menu = event.target;
        if (!isDropdownMenu(menu)) return;
        const win = getWindow(menu, doc);
        const trigger = resolveTriggerForMenu(menu, doc);
        if (event.newState === 'open') {
            markOpenVisualState(trigger, menu, doc, win);
        }
    }, true);

    doc.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (!isDropdownMenu(menu)) return;
        const win = getWindow(menu, doc);
        const trigger = resolveTriggerForMenu(menu, doc);
        if (event.newState === 'open') {
            completeOpenState(trigger, menu, win);
        } else if (event.newState === 'closed') {
            completeClosedState(trigger, menu, win);
        }
    }, true);

    doc.addEventListener('click', (event) => {
        const target = event.target;
        if (!target?.closest) return;

        const trigger = target.closest(TRIGGER_SELECTOR);
        if (trigger) {
            const { menu } = resolveMenuAndTrigger(trigger, doc);
            if (menu && typeof menu.showPopover !== 'function') {
                event.preventDefault?.();
                AdasiDropdown.toggle(trigger, doc);
            }
            return;
        }

        const menu = target.closest(MENU_SELECTOR);
        if (menu) {
            const actionItem = target.closest('.dropdown-item, [data-ui-dropdown-item]');
            if (!actionItem) return;
            if (actionItem.disabled || actionItem.getAttribute?.('aria-disabled') === 'true') return;
            if (actionItem.getAttribute?.('role') === 'tab' || target.closest?.('input, select, textarea, label')) return;
            queueMicrotask(() => hideMenu(menu, resolveTriggerForMenu(menu, doc), doc));
            return;
        }

        // Light-dismiss fallback when running without native Popover API support.
        doc.querySelectorAll?.('[data-ui-dropdown-menu].show, .dropdown-menu.show')?.forEach((openMenu) => {
            if (typeof openMenu.showPopover === 'function') return;
            const triggerForMenu = resolveTriggerForMenu(openMenu, doc);
            if (triggerForMenu?.contains?.(target) || openMenu.contains?.(target)) return;
            hideMenu(openMenu, triggerForMenu, doc);
        });
    });

    doc.addEventListener('keydown', (event) => {
        if (event.isComposing || event.keyCode === 229) return;
        const target = event.target;
        if (!target?.closest) return;

        const trigger = target.closest(TRIGGER_SELECTOR);
        if (trigger && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            const { menu } = resolveMenuAndTrigger(trigger, doc);
            if (!menu) return;
            event.preventDefault?.();
            showMenu(menu, trigger, doc);
            const focusTarget = event.key === 'ArrowUp' ? 'last' : 'first';
            const raf = getWindow(menu, doc)?.requestAnimationFrame;
            if (typeof raf === 'function') {
                raf(() => focusItem(menu, focusTarget));
            } else {
                focusItem(menu, focusTarget);
            }
            return;
        }

        const menu = target.closest(MENU_SELECTOR);
        if (!menu || !isMenuOpen(menu)) return;

        const win = getWindow(menu, doc);
        const menuTrigger = resolveTriggerForMenu(menu, doc);

        if (event.key === 'Escape') {
            if (typeof menu.hidePopover !== 'function') {
                event.preventDefault?.();
                hideMenu(menu, menuTrigger, doc);
            }
            safeFocus(menuTrigger);
            return;
        }

        if (target.closest?.('.notification-dropdown, [role="tablist"], input, textarea, select')) return;

        const items = [...(menu.querySelectorAll?.(ITEM_SELECTOR) || [])].filter((el) => !el.hidden && el.getAttribute?.('aria-hidden') !== 'true');
        if (!items.length) return;

        const currentIdx = items.indexOf(target.closest(ITEM_SELECTOR));
        if (event.key === 'ArrowDown') {
            event.preventDefault?.();
            safeFocus(items[(currentIdx + 1) % items.length]);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault?.();
            safeFocus(items[(currentIdx - 1 + items.length) % items.length]);
        } else if (event.key === 'Home') {
            event.preventDefault?.();
            safeFocus(items[0]);
        } else if (event.key === 'End') {
            event.preventDefault?.();
            safeFocus(items[items.length - 1]);
        } else if (event.key === 'Tab') {
            if (event.shiftKey && currentIdx <= 0) {
                hideMenu(menu, menuTrigger, doc);
            } else if (!event.shiftKey && currentIdx === items.length - 1) {
                hideMenu(menu, menuTrigger, doc);
            }
        }
    });
}
