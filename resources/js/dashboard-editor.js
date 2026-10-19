/**
 * Dashboard layout editor: the owner arranges their real dashboard in place.
 *
 * Two layers on purpose. The state functions are pure and unit-tested (tests/js/dashboard-editor.test.mjs);
 * bootDashboardEditor() is a thin DOM adapter around them. Card widths come from dashboard-layout-core.js,
 * the same smart-span rule the server renders with, so the preview never disagrees with the saved page.
 */
import { t } from './i18n.js';
import { SPAN_CLASSES, computeEffectiveSpans, dropPlacement, normalizeSpan, spanClass } from './dashboard-layout-core.js';

export { dropPlacement };

const sameOrder = (a, b) => a.length === b.length && a.every((value, index) => value === b[index]);

/* ------------------------------------------------------------------ state (pure) */

export function createEditorState({ widgets, defaultOrder = [] }) {
    const order = widgets.map((widget) => widget.key);
    const hidden = widgets.filter((widget) => !widget.visible).map((widget) => widget.key);

    return {
        widgets: Object.fromEntries(widgets.map((widget) => [widget.key, { ...widget }])),
        order,
        hidden,
        defaultOrder: [...defaultOrder.filter((key) => order.includes(key)), ...order.filter((key) => !defaultOrder.includes(key))],
        snapshot: { order: [...order], hidden: [...hidden] },
    };
}

/** Swaps a panel with its neighbour. Returns the same state when it is already at that end. */
export function moveWidget(state, key, direction) {
    const index = state.order.indexOf(key);
    const target = direction === 'up' ? index - 1 : index + 1;
    if (index < 0 || target < 0 || target >= state.order.length) return state;

    const order = [...state.order];
    [order[index], order[target]] = [order[target], order[index]];

    return { ...state, order };
}

/** Drops a panel before or after another one. Returns the same state when nothing changes. */
export function moveWidgetTo(state, key, targetKey, position) {
    if (key === targetKey || !state.order.includes(key) || !state.order.includes(targetKey)) return state;

    const order = state.order.filter((item) => item !== key);
    const targetIndex = order.indexOf(targetKey);
    order.splice(position === 'after' ? targetIndex + 1 : targetIndex, 0, key);

    return sameOrder(order, state.order) ? state : { ...state, order };
}

/** Required panels can never be hidden; the server refuses them too. */
export function setVisible(state, key, visible) {
    const widget = state.widgets[key];
    if (!widget || widget.required) return state;

    const isHidden = state.hidden.includes(key);
    if (isHidden === !visible) return state;

    return { ...state, hidden: visible ? state.hidden.filter((item) => item !== key) : [...state.hidden, key] };
}

export function resetToDefault(state) {
    return { ...state, order: [...state.defaultOrder], hidden: [] };
}

export function restoreSnapshot(state) {
    return { ...state, order: [...state.snapshot.order], hidden: [...state.snapshot.hidden] };
}

/** One change for a reorder (however many moves it took) plus one per panel shown or hidden. */
export function countChanges(state) {
    const before = new Set(state.snapshot.hidden);
    const now = new Set(state.hidden);
    let toggled = 0;
    for (const key of now) if (!before.has(key)) toggled++;
    for (const key of before) if (!now.has(key)) toggled++;

    return (sameOrder(state.order, state.snapshot.order) ? 0 : 1) + toggled;
}

export function buildPayload(state) {
    return { dashboard: { order: [...state.order], hidden: state.order.filter((key) => state.hidden.includes(key)) } };
}

/** Visible panels get the smart span; a hidden one keeps its natural width so its placeholder still reads as that panel. */
export function computeStateSpans(state) {
    const effective = computeEffectiveSpans(state.order.map((key) => ({
        key,
        span: state.widgets[key].span,
        visible: !state.hidden.includes(key),
    })));

    return Object.fromEntries(state.order.map((key) => [key, effective[key] ?? normalizeSpan(state.widgets[key].span)]));
}

/* ------------------------------------------------------------------ DOM adapter */

const WIDGET_SELECTOR = '[data-dashboard-widget], [data-dashboard-placeholder]';
const SPAN_CLASS_LIST = Object.values(SPAN_CLASSES);
const widgetKeyOf = (element) => element.dataset.dashboardWidget ?? element.dataset.dashboardPlaceholder;
const isWidget = (element) => element.hasAttribute('data-dashboard-widget') || element.hasAttribute('data-dashboard-placeholder');
const contentOf = (wrapper) => Array.from(wrapper.children)
    .filter((child) => !child.hasAttribute('data-editor-strip') && !child.hasAttribute('data-editor-ghost'));

export function bootDashboardEditor(documentRef = document, windowRef = window) {
    const grid = documentRef.querySelector('[data-dashboard-audience]');
    const configNode = grid?.querySelector('[data-editor-config]');
    if (!grid || !configNode) return null;

    let config;
    try {
        config = JSON.parse(configNode.textContent);
    } catch {
        return null;
    }

    const wrappers = new Map();
    grid.querySelectorAll(WIDGET_SELECTOR).forEach((element) => wrappers.set(widgetKeyOf(element), element));
    const widgets = (config.widgets ?? []).filter((widget) => wrappers.has(widget.key));

    const enterButton = grid.querySelector('[data-editor-enter]');
    const actionBar = grid.querySelector('[data-editor-actionbar]');
    const saveButton = grid.querySelector('[data-editor-save]');
    const cancelButton = grid.querySelector('[data-editor-cancel]');
    const resetButton = grid.querySelector('[data-editor-reset]');
    if (!widgets.length || !enterButton || !actionBar || !saveButton || !cancelButton || !resetButton) return null;

    const statusNode = grid.querySelector('[data-editor-status]');
    const announceNode = grid.querySelector('[data-editor-announce]');
    const spinner = grid.querySelector('[data-editor-saving]');
    const copy = { unsaved: ':count', noChanges: '', ...(config.copy ?? {}) };

    let state = createEditorState({ widgets, defaultOrder: config.defaultOrder ?? [] });
    let editing = false;
    let saving = false;
    let guarded = false;
    let dragged = null;
    let dropTarget = null;
    const cleanups = [];

    const announce = (message) => {
        if (announceNode) announceNode.textContent = message;
    };

    const positionMessage = (key) => t('js.dashboard.position', {
        label: state.widgets[key].label,
        position: state.order.indexOf(key) + 1,
        total: state.order.length,
    });

    const statusFor = (key, hasContent) => {
        if (state.hidden.includes(key)) return t('js.dashboard.editor.hidden');

        return hasContent ? '' : t('js.dashboard.editor.shown_after_save');
    };

    /** Keeps the DOM order equal to state.order, moving only the nodes that are out of place. */
    const nextWidget = (element) => {
        let next = element.nextElementSibling;
        while (next && !isWidget(next)) next = next.nextElementSibling;

        return next;
    };
    const syncOrder = () => {
        let cursor = Array.from(grid.children).find(isWidget) ?? null;
        for (const key of state.order) {
            const node = wrappers.get(key);
            if (node === cursor) cursor = nextWidget(cursor);
            else grid.insertBefore(node, cursor);
        }
    };

    const syncGuard = (dirty) => {
        if (dirty === guarded) return;
        guarded = dirty;
        if (dirty) windowRef.AdasiUnsaved?.markDirty();
        else windowRef.AdasiUnsaved?.markClean();
    };

    const refreshChrome = () => {
        const count = countChanges(state);
        enterButton.hidden = editing;
        actionBar.hidden = !editing;
        if (editing) grid.setAttribute('data-dashboard-editing', '');
        else grid.removeAttribute('data-dashboard-editing');
        if (statusNode) statusNode.textContent = editing ? (count > 0 ? copy.unsaved.replace(':count', String(count)) : copy.noChanges) : '';
        saveButton.disabled = saving || count === 0;
        cancelButton.disabled = saving;
        resetButton.disabled = saving;
        saveButton.setAttribute('aria-busy', saving ? 'true' : 'false');
        if (spinner) spinner.hidden = !saving;
        syncGuard(editing && count > 0);
    };

    const render = () => {
        const spans = computeStateSpans(state);
        syncOrder();

        for (const key of state.order) {
            const wrapper = wrappers.get(key);
            const isHidden = state.hidden.includes(key);
            const hasContent = wrapper.dataset.widgetHasContent === 'true';
            const status = statusFor(key, hasContent);

            SPAN_CLASS_LIST.forEach((name) => wrapper.classList.remove(name));
            wrapper.classList.add(spanClass(spans[key]));
            // A placeholder carries no data, so it exists only while editing.
            wrapper.hidden = !editing && !hasContent;

            for (const child of contentOf(wrapper)) {
                child.inert = editing;
                if (editing && isHidden) child.setAttribute('data-editor-concealed', '');
                else child.removeAttribute('data-editor-concealed');
            }

            const strip = wrapper.querySelector('[data-editor-strip]');
            if (strip) strip.hidden = !editing;
            const stripStatus = wrapper.querySelector('[data-editor-strip-status]');
            if (stripStatus) {
                stripStatus.textContent = status;
                stripStatus.hidden = status === '';
            }
            const ghost = wrapper.querySelector('[data-editor-ghost]');
            if (ghost) ghost.hidden = !(editing && (isHidden || !hasContent));
            const ghostStatus = wrapper.querySelector('[data-editor-ghost-status]');
            if (ghostStatus) ghostStatus.textContent = status;
            const toggle = wrapper.querySelector('[data-editor-visibility]');
            if (toggle) toggle.checked = !isHidden;
        }

        refreshChrome();
    };

    const enter = () => {
        if (editing || saving) return;
        editing = true;
        render();
        announce(t('js.dashboard.editor.entered'));
        wrappers.get(state.order[0])?.querySelector('[data-editor-move]')?.focus?.();
    };

    const cancel = () => {
        if (!editing || saving) return;
        state = restoreSnapshot(state);
        editing = false;
        render();
        announce(t('js.dashboard.editor.cancelled'));
        enterButton.focus?.();
    };

    const reset = () => {
        if (!editing || saving) return;
        state = resetToDefault(state);
        render();
        announce(t('js.dashboard.editor.reset_applied'));
    };

    const fail = () => {
        const message = t('js.dashboard.editor.save_failed');
        if (windowRef.AdasiToast) windowRef.AdasiToast.error(message);
        announce(message);
    };

    const save = async () => {
        if (!editing || saving || countChanges(state) === 0) return;
        saving = true;
        refreshChrome();

        try {
            const response = await windowRef.fetch(config.saveUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': documentRef.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(buildPayload(state)),
            });

            if (response.ok) {
                // Reload so a panel that was hidden is rendered with its real data. The page stays busy until then.
                guarded = false;
                windowRef.AdasiUnsaved?.setSubmitting(true);
                windowRef.location.reload();

                return;
            }
        } catch {
            // Network failure is reported the same way as a rejected save.
        }

        saving = false;
        refreshChrome();
        fail();
    };

    const move = (wrapper, direction, control) => {
        const key = widgetKeyOf(wrapper);
        const next = moveWidget(state, key, direction);
        if (next !== state) {
            state = next;
            render();
        }
        announce(positionMessage(key));
        // Moving the node drops focus, so hand it back to the control that was used.
        control.focus?.();
    };

    const toggleVisibility = (wrapper, input) => {
        const key = widgetKeyOf(wrapper);
        state = setVisible(state, key, input.checked);
        render();
        const status = statusFor(key, wrapper.dataset.widgetHasContent === 'true');
        if (status !== '') announce(`${state.widgets[key].label}: ${status}`);
    };

    const clearDropIndicator = () => {
        wrappers.forEach((wrapper) => wrapper.removeAttribute('data-drop-edge'));
    };

    const endDrag = () => {
        dragged?.removeAttribute('data-dragging');
        clearDropIndicator();
        dragged = null;
        dropTarget = null;
    };

    const on = (target, name, handler) => {
        target.addEventListener(name, handler);
        cleanups.push(() => target.removeEventListener?.(name, handler));
    };

    on(enterButton, 'click', enter);
    on(cancelButton, 'click', cancel);
    on(resetButton, 'click', reset);
    on(saveButton, 'click', save);

    on(grid, 'click', (event) => {
        const control = event.target.closest?.('[data-editor-move]');
        if (!control || !editing || saving) return;
        const wrapper = control.closest(WIDGET_SELECTOR);
        if (wrapper) move(wrapper, control.dataset.editorMove, control);
    });

    on(grid, 'change', (event) => {
        const input = event.target.closest?.('[data-editor-visibility]');
        if (!input || !editing || saving) return;
        const wrapper = input.closest(WIDGET_SELECTOR);
        if (wrapper) toggleVisibility(wrapper, input);
    });

    on(grid, 'dragstart', (event) => {
        if (!editing || saving) return;
        const wrapper = event.target.closest?.('[data-editor-strip]')?.closest(WIDGET_SELECTOR);
        if (!wrapper) return;

        dragged = wrapper;
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', widgetKeyOf(wrapper));
            event.dataTransfer.setDragImage?.(wrapper, 24, 24);
        }
        wrapper.setAttribute('data-dragging', '');
    });

    on(grid, 'dragover', (event) => {
        if (!dragged) return;
        const target = event.target.closest?.(WIDGET_SELECTOR);
        clearDropIndicator();
        dropTarget = null;
        if (!target || target === dragged) return;

        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        const placement = dropPlacement(target.getBoundingClientRect(), { x: event.clientX, y: event.clientY }, grid.getBoundingClientRect().width);
        target.setAttribute('data-drop-edge', placement.edge);
        dropTarget = { element: target, position: placement.position };
    });

    on(grid, 'drop', (event) => {
        if (!dragged) return;
        event.preventDefault();
        const key = widgetKeyOf(dragged);
        const landing = dropTarget;
        endDrag();
        if (!landing) return;

        const next = moveWidgetTo(state, key, widgetKeyOf(landing.element), landing.position);
        if (next !== state) {
            state = next;
            render();
        }
        announce(positionMessage(key));
    });

    on(grid, 'dragend', endDrag);

    on(windowRef, 'beforeunload', (event) => {
        if (guarded && !saving) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    render();

    // Deep link from the Customization page: ?customize=1 opens edit mode, then the parameter is dropped.
    try {
        const url = new URL(windowRef.location.href);
        if (url.searchParams.get('customize') === '1') {
            enter();
            url.searchParams.delete('customize');
            windowRef.history?.replaceState?.(windowRef.history.state, '', `${url.pathname}${url.search}${url.hash}`);
        }
    } catch {
        // An unusable location never blocks the editor.
    }

    return {
        enter,
        cancel,
        reset,
        save,
        isEditing: () => editing,
        getState: () => state,
        destroy: () => cleanups.splice(0).forEach((cleanup) => cleanup()),
    };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    const start = () => bootDashboardEditor(document, window);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
