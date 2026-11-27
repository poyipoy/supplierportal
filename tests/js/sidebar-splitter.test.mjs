import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    bindSidebarSplitter,
    clampSidebarWidth,
    COLLAPSED_SIDEBAR_WIDTH,
    COLLAPSE_SNAP_THRESHOLD,
    DEFAULT_SIDEBAR_WIDTH,
    MIN_EXPANDED_SIDEBAR_WIDTH,
    resolveSidebarResizeTarget,
    syncSplitterGripY,
} from '../../resources/js/splitter.js';

function createMockElement(rect = { top: 0, height: 600, width: 256 }) {
    const listeners = new Map();
    const attrs = new Map();
    const styles = new Map();
    const classes = new Set();
    const dataset = {};
    let pointerCaptured = null;

    return {
        dataset,
        textContent: '',
        classList: {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            contains: (name) => classes.has(name),
        },
        style: {
            setProperty: (name, value) => styles.set(name, value),
            removeProperty: (name) => styles.delete(name),
            getPropertyValue: (name) => styles.get(name) ?? '',
        },
        setAttribute: (name, value) => attrs.set(name, String(value)),
        getAttribute: (name) => attrs.get(name) ?? null,
        removeAttribute: (name) => attrs.delete(name),
        getBoundingClientRect: () => rect,
        setPointerCapture: (id) => { pointerCaptured = id; },
        hasPointerCapture: (id) => pointerCaptured === id,
        releasePointerCapture: (id) => { if (pointerCaptured === id) pointerCaptured = null; },
        addEventListener: (type, handler) => {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(handler);
        },
        removeEventListener: (type, handler) => {
            const list = listeners.get(type) || [];
            listeners.set(type, list.filter((fn) => fn !== handler));
        },
        dispatch: (type, event = {}) => {
            const payload = {
                preventDefault() {},
                ...event,
            };
            for (const fn of listeners.get(type) || []) {
                fn(payload);
            }
        },
    };
}

test('clampSidebarWidth and resolveSidebarResizeTarget span 64px collapsed rail up to 100% viewport width', () => {
    assert.equal(clampSidebarWidth(50, 1440), MIN_EXPANDED_SIDEBAR_WIDTH);
    assert.equal(clampSidebarWidth(1440, 1440), 1440);
    assert.equal(clampSidebarWidth(1920, 1366), 1366);
    assert.equal(clampSidebarWidth(Number.NaN, 1440), DEFAULT_SIDEBAR_WIDTH);

    assert.deepEqual(resolveSidebarResizeTarget(0, 1440), { collapsed: true, width: COLLAPSED_SIDEBAR_WIDTH });
    assert.deepEqual(resolveSidebarResizeTarget(COLLAPSE_SNAP_THRESHOLD, 1440), { collapsed: true, width: COLLAPSED_SIDEBAR_WIDTH });
    assert.deepEqual(resolveSidebarResizeTarget(81, 1440), { collapsed: false, width: 81 });
    assert.deepEqual(resolveSidebarResizeTarget(1600, 1440), { collapsed: false, width: 1440 });
});

test('syncSplitterGripY clamps vertical pill position inside splitter bounds', () => {
    const splitter = createMockElement({ top: 100, height: 400 });
    syncSplitterGripY(splitter, 105);
    assert.equal(splitter.style.getPropertyValue('--splitter-grip-y'), '22px');

    syncSplitterGripY(splitter, 495);
    assert.equal(splitter.style.getPropertyValue('--splitter-grip-y'), '378px');

    syncSplitterGripY(splitter, 250);
    assert.equal(splitter.style.getPropertyValue('--splitter-grip-y'), '150px');
});

test('bindSidebarSplitter supports full-screen expansion, 64px collapse snap, re-expansion, and width preservation', () => {
    const sidebarRect = { top: 0, height: 800, width: DEFAULT_SIDEBAR_WIDTH };
    const splitterEl = createMockElement({ top: 0, height: 800, width: 16 });
    const sidebarEl = createMockElement(sidebarRect);
    const rootEl = createMockElement();
    const liveRegionEl = createMockElement();
    const windowListeners = new Map();
    const windowRef = {
        innerWidth: 1440,
        requestAnimationFrame: (cb) => cb(),
        addEventListener: (type, fn) => windowListeners.set(type, fn),
        removeEventListener: (type) => windowListeners.delete(type),
    };

    let storedWidth = null;
    let collapsed = false;
    let isDesktop = true;
    let dragStartCalls = 0;

    const controller = bindSidebarSplitter({
        splitterEl,
        sidebarEl,
        rootEl,
        windowRef,
        getCollapsed: () => collapsed,
        setCollapsed: (next) => { collapsed = Boolean(next); },
        getIsDesktop: () => isDesktop,
        onDragStart: () => { dragStartCalls++; },
        readStoredWidth: () => storedWidth,
        writeStoredWidth: (next) => { storedWidth = next; },
        formatValueText: (width) => `${width} px`,
        formatAnnouncement: (width) => `Width ${width} px`,
        resetAnnouncement: 'Reset 256 px',
        collapsedAnnouncement: 'Collapsed 64 px',
        liveRegionEl,
    });

    assert.equal(splitterEl.getAttribute('aria-valuemin'), '64');
    assert.equal(splitterEl.getAttribute('aria-valuemax'), '1440');
    assert.equal(splitterEl.getAttribute('aria-valuenow'), '256');

    // Drag all the way right to cover 100% of viewport (1440px)
    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 7, clientX: 256, clientY: 300 });
    assert.equal(dragStartCalls, 1);
    assert.equal(rootEl.dataset.sidebarResizing, 'true');

    splitterEl.dispatch('pointermove', { pointerId: 7, clientX: 1600, clientY: 340 });
    assert.equal(rootEl.style.getPropertyValue('--sidebar-width'), '1440px');
    assert.equal(splitterEl.dataset.sidebarEdge, 'right');
    assert.equal(splitterEl.getAttribute('aria-valuenow'), '1440');

    splitterEl.dispatch('pointerup', { pointerId: 7, clientX: 1600, clientY: 340 });
    assert.equal(rootEl.dataset.sidebarResizing, undefined);
    assert.equal(storedWidth, 1440);
    assert.equal(collapsed, false);

    // Narrow drag (< 180px) marks data-sidebar-narrow
    sidebarRect.width = 400;
    storedWidth = 400;
    controller.sync();
    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 8, clientX: 400, clientY: 300 });
    splitterEl.dispatch('pointermove', { pointerId: 8, clientX: 140, clientY: 300 });
    assert.equal(rootEl.dataset.sidebarNarrow, 'true');
    assert.equal(splitterEl.dataset.sidebarEdge, undefined);

    // Dragging further left (<= 80px) snaps to 64px collapsed while preserving prior expanded width (400px)
    splitterEl.dispatch('pointermove', { pointerId: 8, clientX: 70, clientY: 300 });
    assert.equal(collapsed, true);
    assert.equal(splitterEl.getAttribute('aria-valuenow'), '64');
    assert.equal(rootEl.dataset.sidebarNarrow, undefined);
    splitterEl.dispatch('pointerup', { pointerId: 8, clientX: 70, clientY: 300 });
    assert.equal(collapsed, true);
    assert.equal(storedWidth, 400);
    assert.equal(rootEl.style.getPropertyValue('--sidebar-width'), '400px');

    // Dragging right from collapsed 64px rail re-expands smoothly
    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 9, clientX: 64, clientY: 200 });
    splitterEl.dispatch('pointermove', { pointerId: 9, clientX: 300, clientY: 200 });
    assert.equal(collapsed, false);
    assert.equal(rootEl.style.getPropertyValue('--sidebar-width'), '300px');
    splitterEl.dispatch('pointerup', { pointerId: 9, clientX: 300, clientY: 200 });
    assert.equal(storedWidth, 300);

    // Keyboard Home collapses to 64px without erasing storedWidth, ArrowRight jumps out of snap threshold
    splitterEl.dispatch('keydown', { key: 'Home' });
    assert.equal(collapsed, true);
    assert.equal(storedWidth, 300);
    assert.equal(liveRegionEl.textContent, 'Collapsed 64 px');

    splitterEl.dispatch('keydown', { key: 'ArrowRight', shiftKey: false });
    assert.equal(collapsed, false);
    assert.equal(storedWidth, 96);
    assert.equal(liveRegionEl.textContent, 'Width 96 px');

    // Keyboard End expands to 100% viewport width
    splitterEl.dispatch('keydown', { key: 'End' });
    assert.equal(storedWidth, 1440);
    assert.equal(splitterEl.dataset.sidebarEdge, 'right');

    // Double-click and Enter reset to 256px
    splitterEl.dispatch('dblclick');
    assert.equal(storedWidth, null);
    assert.equal(rootEl.style.getPropertyValue('--sidebar-width'), '');
    assert.equal(splitterEl.getAttribute('aria-valuenow'), '256');
    assert.equal(splitterEl.dataset.sidebarEdge, undefined);
    assert.equal(liveRegionEl.textContent, 'Reset 256 px');

    splitterEl.dispatch('keydown', { key: 'ArrowRight', shiftKey: true });
    assert.equal(storedWidth, 288);
    splitterEl.dispatch('keydown', { key: 'Enter' });
    assert.equal(storedWidth, null);

    // Mobile disables splitter interactions
    isDesktop = false;
    controller.sync();
    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 10, clientX: 256, clientY: 200 });
    assert.equal(rootEl.dataset.sidebarResizing, undefined);

    // Embed guard never mutates --sidebar-width on rootEl
    isDesktop = true;
    rootEl.dataset.workspaceEmbed = 'true';
    storedWidth = 340;
    controller.sync();
    assert.equal(rootEl.style.getPropertyValue('--sidebar-width'), '');

    controller.destroy();
    assert.equal(windowListeners.has('resize'), false);
    assert.equal(windowListeners.has('pointerup'), false);
    assert.equal(windowListeners.has('pointercancel'), false);
    assert.equal(windowListeners.has('blur'), false);
});

test('bindSidebarSplitter releases drag cleanly on window blur/pointerup and CSS shields iframes without disabling iframe pointer-events', () => {
    const splitterEl = createMockElement({ top: 0, height: 800, width: 16 });
    const sidebarEl = createMockElement({ top: 0, height: 800, width: DEFAULT_SIDEBAR_WIDTH });
    const rootEl = createMockElement();
    const windowListeners = new Map();
    const windowRef = {
        innerWidth: 1440,
        requestAnimationFrame: (cb) => cb(),
        addEventListener: (type, fn) => windowListeners.set(type, fn),
        removeEventListener: (type) => windowListeners.delete(type),
    };
    let storedWidth = null;
    const controller = bindSidebarSplitter({
        splitterEl,
        sidebarEl,
        rootEl,
        windowRef,
        readStoredWidth: () => storedWidth,
        writeStoredWidth: (next) => { storedWidth = next; },
    });

    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 12, clientX: 256, clientY: 300 });
    assert.equal(rootEl.dataset.sidebarResizing, 'true');
    assert.equal(windowListeners.has('blur'), true);
    assert.equal(windowListeners.has('pointerup'), true);
    splitterEl.dispatch('pointermove', { pointerId: 12, clientX: 340, clientY: 300 });

    // Window blur ends drag even if splitterEl misses pointerup
    windowListeners.get('blur')();
    assert.equal(rootEl.dataset.sidebarResizing, undefined);
    assert.equal(splitterEl.classList.contains('is-dragging'), false);
    assert.equal(windowListeners.has('blur'), false);
    assert.equal(storedWidth, 340);

    // Window pointerup fallback also terminates drag cleanly
    splitterEl.dispatch('pointerdown', { button: 0, pointerId: 13, clientX: 340, clientY: 300 });
    assert.equal(rootEl.dataset.sidebarResizing, 'true');
    windowListeners.get('pointerup')({ pointerId: 13 });
    assert.equal(rootEl.dataset.sidebarResizing, undefined);
    assert.equal(windowListeners.has('pointerup'), false);

    controller.destroy();

    const appCss = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(appCss, /html\[data-sidebar-resizing="true"\]\s+\.workspace-stage__frames::after\s*\{/);
    assert.equal(
        /html\[data-sidebar-resizing="true"\]\s+iframe\s*\{[^}]*pointer-events\s*:\s*none/i.test(appCss),
        false,
        'Never toggle pointer-events: none directly on iframe during sidebar resize (breaks Chromium wheel scrolling until clicked)',
    );

    const wsCss = readFileSync(new URL('../../resources/css/workspace-shell.css', import.meta.url), 'utf8');
    const numberJs = readFileSync(new URL('../../resources/js/number-input-helper.js', import.meta.url), 'utf8');
    assert.match(appCss, /@container topbar \(max-width: 36rem\)/);
    assert.match(appCss, /@container filter-bar \(min-width: 36rem\)/);
    assert.match(appCss, /@container export-designer \(min-width: 46rem\)/);
    assert.match(appCss, /\.ui-export-designer-modal\s+\[data-advanced-export-form\]\s*\{[^}]*container-name:\s*export-designer;\s*container-type:\s*inline-size;/);
    assert.doesNotMatch(appCss, /\.ui-export-designer-modal\s+\.modal-body\s*\{[^}]*container-name:\s*export-designer/);
    assert.match(appCss, /@container notif-panel \(max-width: 30rem\)/);
    assert.match(appCss, /\[data-ui-dropdown-menu\]\[popover\]\s*\{[^}]*min-width:\s*min\(11rem,\s*calc\(100vw\s*-\s*1rem\)\)/s);
    assert.match(wsCss, /@container sessionbar \(max-width: 24rem\)/);
    assert.match(wsCss, /\.workspace-session-menu\s*\{[^}]*min-width:\s*min\(13rem,\s*calc\(100vw\s*-\s*1rem\)\)/s);
    assert.match(numberJs, /window\.scrollX\s*\+\s*window\.innerWidth\s*-\s*badgeWidth\s*-\s*12/);
});
