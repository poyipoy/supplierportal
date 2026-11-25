const bootedDocuments = new WeakSet();

const TAB_SELECTOR = '[role="tab"][data-ui-tab]';

function getDoc(target) {
    if (target?.nodeType === 9) return target;
    if (target?.ownerDocument) return target.ownerDocument;
    return typeof document !== 'undefined' ? document : null;
}

function resolvePaneSelector(tabEl) {
    const explicit = tabEl?.getAttribute?.('data-ui-tab-target');
    if (explicit) return explicit;
    const controls = tabEl?.getAttribute?.('aria-controls');
    return controls ? `#${controls}` : null;
}

function resolvePane(tabEl, doc) {
    const selector = resolvePaneSelector(tabEl);
    if (!selector || !doc) return { selector: null, pane: null };
    if (selector.startsWith('#') && !selector.slice(1).includes(' ')) {
        return { selector, pane: doc.getElementById?.(selector.slice(1)) || doc.querySelector?.(selector) || null };
    }
    return { selector, pane: doc.querySelector?.(selector) || null };
}

function isEnabledTab(tabEl) {
    return Boolean(tabEl && !tabEl.disabled && tabEl.getAttribute?.('aria-disabled') !== 'true');
}

function safeFocus(el) {
    if (!el || el.isConnected === false || typeof el.focus !== 'function') return;
    try {
        el.focus({ preventScroll: true });
    } catch {
        el.focus();
    }
}

function getSiblingTabs(tabEl) {
    const tablist = tabEl.closest?.('[role="tablist"]');
    if (!tablist?.querySelectorAll) return [tabEl];
    const tabs = [...tablist.querySelectorAll(TAB_SELECTOR)];
    return tabs.length ? tabs : [tabEl];
}

function hideInactivePanes(selectedPane, siblingTabs, doc) {
    if (selectedPane?.parentElement?.children) {
        for (const child of [...selectedPane.parentElement.children]) {
            if (child === selectedPane) continue;
            if (child.getAttribute?.('role') === 'tabpanel' || child.classList?.contains?.('tab-pane')) {
                child.hidden = true;
                child.classList?.remove?.('active', 'show');
            }
        }
        return;
    }

    siblingTabs.forEach((tab) => {
        const { pane } = resolvePane(tab, doc);
        if (pane && pane !== selectedPane) {
            pane.hidden = true;
            pane.classList?.remove?.('active', 'show');
        }
    });
}

function adjustNestedDataTables(pane, win) {
    if (!pane?.querySelectorAll || !win?.DataTable?.isDataTable) return;
    pane.querySelectorAll('table.dataTable').forEach((tableEl) => {
        if (!win.DataTable.isDataTable(tableEl)) return;
        try {
            new win.DataTable(tableEl, { retrieve: true }).columns?.adjust?.();
        } catch {}
    });
}

function activateTab(tabEl) {
    if (!isEnabledTab(tabEl)) return null;
    const doc = getDoc(tabEl);
    if (!doc) return null;
    const win = doc.defaultView || (typeof window !== 'undefined' ? window : null);

    const siblingTabs = getSiblingTabs(tabEl);
    const previousTab = siblingTabs.find((tab) => tab.getAttribute?.('aria-selected') === 'true' || tab.classList?.contains?.('active')) || null;

    siblingTabs.forEach((tab) => {
        const selected = tab === tabEl;
        tab.setAttribute?.('aria-selected', selected ? 'true' : 'false');
        tab.classList?.toggle?.('active', selected);
        if (selected) {
            tab.removeAttribute?.('tabindex');
        } else {
            tab.setAttribute?.('tabindex', '-1');
        }
    });

    const { selector, pane } = resolvePane(tabEl, doc);
    if (pane) {
        hideInactivePanes(pane, siblingTabs, doc);
        pane.hidden = false;
        pane.classList?.add?.('active', 'show');
        adjustNestedDataTables(pane, win);
    }

    const CustomEvt = win?.CustomEvent || CustomEvent;
    tabEl.dispatchEvent?.(new CustomEvt('adasi:tab-shown', {
        bubbles: true,
        detail: {
            tab: tabEl,
            target: selector,
            pane,
            previousTab,
        },
    }));

    return pane;
}

export const AdasiTabs = Object.freeze({
    show(tabEl) {
        return activateTab(tabEl);
    },
    getOrCreateInstance(tabEl) {
        if (!tabEl) return null;
        return {
            show: () => activateTab(tabEl),
        };
    },
});

/**
 * Delegated accessible tabs controller with roving tabindex and DataTables column alignment.
 */
export function bootAdasiTabs(root = typeof document !== 'undefined' ? document : null) {
    const doc = getDoc(root);
    if (!doc || typeof doc.addEventListener !== 'function') return;
    if (bootedDocuments.has(doc)) return;
    bootedDocuments.add(doc);

    doc.addEventListener('click', (event) => {
        const tabEl = event.target?.closest?.(TAB_SELECTOR);
        if (!tabEl || !isEnabledTab(tabEl)) return;
        if (tabEl.tagName === 'A') event.preventDefault?.();
        activateTab(tabEl);
    });

    doc.addEventListener('keydown', (event) => {
        if (event.isComposing || event.keyCode === 229) return;
        const tabEl = event.target?.closest?.(TAB_SELECTOR);
        if (!tabEl || event.target?.closest?.('input, textarea, select')) return;

        const enabledTabs = getSiblingTabs(tabEl).filter(isEnabledTab);
        if (!enabledTabs.length) return;

        const idx = enabledTabs.indexOf(tabEl);
        let nextTab = null;

        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            nextTab = enabledTabs[(idx + 1) % enabledTabs.length];
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            nextTab = enabledTabs[(idx - 1 + enabledTabs.length) % enabledTabs.length];
        } else if (event.key === 'Home') {
            nextTab = enabledTabs[0];
        } else if (event.key === 'End') {
            nextTab = enabledTabs[enabledTabs.length - 1];
        } else if (event.key === 'Enter' || event.key === ' ') {
            if (isEnabledTab(tabEl)) {
                event.preventDefault?.();
                activateTab(tabEl);
            }
            return;
        }

        if (!nextTab) return;
        event.preventDefault?.();
        safeFocus(nextTab);
        activateTab(nextTab);
    });
}
