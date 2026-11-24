export const DEFAULT_SIDEBAR_WIDTH = 256;
export const COLLAPSED_SIDEBAR_WIDTH = 64;
export const COLLAPSE_SNAP_THRESHOLD = 80;
export const MIN_EXPANDED_SIDEBAR_WIDTH = 81;
export const MAX_STORED_SIDEBAR_WIDTH = 4096;
export const NARROW_SIDEBAR_THRESHOLD = 180;
export const SIDEBAR_KEYBOARD_STEP = 16;
export const SIDEBAR_KEYBOARD_LARGE_STEP = 32;

/**
 * Resolve effective maximum expanded sidebar width for the given viewport (up to 100% of viewport width).
 */
export const resolveMaxSidebarWidth = (viewportWidth = 1280) => {
    const numeric = Number(viewportWidth);
    const safeViewport = Number.isFinite(numeric) && numeric > 0
        ? Math.floor(numeric)
        : 1280;

    return Math.min(MAX_STORED_SIDEBAR_WIDTH, Math.max(MIN_EXPANDED_SIDEBAR_WIDTH, safeViewport));
};

/**
 * Clamp an expanded sidebar width in pixels to [MIN_EXPANDED_SIDEBAR_WIDTH, viewportWidth].
 */
export const clampSidebarWidth = (
    rawWidth,
    viewportWidth = 1280,
    { minWidth = MIN_EXPANDED_SIDEBAR_WIDTH, maxWidth = null } = {},
) => {
    const numeric = Number(rawWidth);
    const base = Number.isFinite(numeric) ? Math.round(numeric) : DEFAULT_SIDEBAR_WIDTH;
    const viewportMax = resolveMaxSidebarWidth(viewportWidth);
    const effectiveMax = maxWidth === null
        ? viewportMax
        : Math.max(minWidth, Math.min(Number(maxWidth) || viewportMax, viewportMax));

    return Math.min(effectiveMax, Math.max(minWidth, base));
};

/**
 * Resolve a raw drag/keyboard width into either collapsed (64px) or clamped expanded width (81..viewportWidth).
 */
export const resolveSidebarResizeTarget = (rawWidth, viewportWidth = 1280) => {
    const numeric = Number(rawWidth);
    if (!Number.isFinite(numeric)) {
        return { collapsed: false, width: clampSidebarWidth(DEFAULT_SIDEBAR_WIDTH, viewportWidth) };
    }
    const rounded = Math.round(numeric);
    if (rounded <= COLLAPSE_SNAP_THRESHOLD) {
        return { collapsed: true, width: COLLAPSED_SIDEBAR_WIDTH };
    }
    return {
        collapsed: false,
        width: clampSidebarWidth(rounded, viewportWidth),
    };
};

/**
 * Keep the vertical splitter pill centered on the pointer along the separator track.
 */
export const syncSplitterGripY = (splitterEl, clientY) => {
    if (!splitterEl || !Number.isFinite(clientY)) return;
    const rect = splitterEl.getBoundingClientRect?.();
    if (!rect || !rect.height) return;
    const gripHalf = 22;
    const clampedY = rect.height > gripHalf * 2
        ? Math.min(rect.height - gripHalf, Math.max(gripHalf, clientY - rect.top))
        : rect.height / 2;
    splitterEl.style.setProperty('--splitter-grip-y', `${Math.round(clampedY)}px`);
};

/**
 * Wire pointer drag, keyboard adjustments, double-click reset, and full-range 64px..100% viewport resizing.
 */
export const bindSidebarSplitter = ({
    splitterEl,
    sidebarEl,
    rootEl,
    windowRef = typeof window !== 'undefined' ? window : null,
    getCollapsed = () => false,
    setCollapsed = () => {},
    getIsDesktop = () => true,
    onDragStart = () => {},
    readStoredWidth = () => null,
    writeStoredWidth = () => {},
    formatValueText = (width) => `${width}px`,
    formatAnnouncement = (width) => `Sidebar width ${width}px`,
    resetAnnouncement = 'Sidebar width reset to default (256px)',
    collapsedAnnouncement = 'Sidebar collapsed (64px)',
    liveRegionEl = null,
} = {}) => {
    if (!splitterEl || !sidebarEl || !rootEl || !windowRef) {
        return { sync: () => {}, destroy: () => {} };
    }

    let dragState = null;
    let currentWidth = null;

    const effectiveBounds = () => ({
        min: COLLAPSED_SIDEBAR_WIDTH,
        max: resolveMaxSidebarWidth(windowRef.innerWidth || 1280),
    });

    const resolveActiveExpandedWidth = () => {
        const stored = readStoredWidth();
        const raw = typeof stored === 'number' && Number.isFinite(stored) ? stored : DEFAULT_SIDEBAR_WIDTH;
        return clampSidebarWidth(raw, windowRef.innerWidth || 1280);
    };

    const syncEdgeAndNarrowFlags = (width, collapsed) => {
        const viewportWidth = windowRef.innerWidth || 1280;
        if (!collapsed && width >= viewportWidth - 8) {
            splitterEl.dataset.sidebarEdge = 'right';
        } else {
            delete splitterEl.dataset.sidebarEdge;
        }

        if (rootEl.dataset.workspaceEmbed !== 'true' && !collapsed && width < NARROW_SIDEBAR_THRESHOLD) {
            rootEl.dataset.sidebarNarrow = 'true';
        } else {
            delete rootEl.dataset.sidebarNarrow;
        }
    };

    const syncAria = (width) => {
        const { min, max } = effectiveBounds();
        splitterEl.setAttribute('aria-valuemin', String(min));
        splitterEl.setAttribute('aria-valuemax', String(max));
        splitterEl.setAttribute('aria-valuenow', String(width));
        splitterEl.setAttribute('aria-valuetext', formatValueText(width));
    };

    const applyResizeState = (rawWidth, { persist = false, isReset = false } = {}) => {
        const viewportWidth = windowRef.innerWidth || 1280;
        const target = isReset
            ? { collapsed: false, width: clampSidebarWidth(DEFAULT_SIDEBAR_WIDTH, viewportWidth) }
            : resolveSidebarResizeTarget(rawWidth, viewportWidth);

        currentWidth = target.width;

        if (target.collapsed) {
            setCollapsed(true, { persist });
            if (rootEl.dataset.workspaceEmbed !== 'true') {
                // Keep previously saved expanded width untouched so toggling open restores it.
                const storedExpanded = resolveActiveExpandedWidth();
                if (storedExpanded === DEFAULT_SIDEBAR_WIDTH) {
                    rootEl.style?.removeProperty?.('--sidebar-width');
                } else {
                    rootEl.style?.setProperty?.('--sidebar-width', `${storedExpanded}px`);
                }
            }
            syncEdgeAndNarrowFlags(COLLAPSED_SIDEBAR_WIDTH, true);
            syncAria(COLLAPSED_SIDEBAR_WIDTH);
            return target;
        }

        setCollapsed(false, { persist });

        if (rootEl.dataset.workspaceEmbed !== 'true') {
            if (target.width === DEFAULT_SIDEBAR_WIDTH) {
                rootEl.style?.removeProperty?.('--sidebar-width');
            } else {
                rootEl.style?.setProperty?.('--sidebar-width', `${target.width}px`);
            }
        }

        syncEdgeAndNarrowFlags(target.width, false);
        syncAria(target.width);

        if (persist) {
            writeStoredWidth(isReset || target.width === DEFAULT_SIDEBAR_WIDTH ? null : target.width);
        }

        return target;
    };

    const announce = (message) => {
        if (!liveRegionEl || !message) return;
        liveRegionEl.textContent = '';
        const commit = () => {
            liveRegionEl.textContent = message;
        };
        if (typeof windowRef.requestAnimationFrame === 'function') {
            windowRef.requestAnimationFrame(commit);
        } else {
            commit();
        }
    };

    const canResize = () => (
        rootEl.dataset.workspaceEmbed !== 'true'
        && getIsDesktop()
        && splitterEl.getAttribute('aria-disabled') !== 'true'
    );

    const endDrag = (event = null) => {
        if (!dragState) return;
        const activePointerId = dragState.pointerId;
        const finalRawWidth = dragState.latestRawWidth;
        dragState = null;
        windowRef.removeEventListener?.('pointerup', onPointerUp);
        windowRef.removeEventListener?.('pointercancel', onPointerCancel);
        windowRef.removeEventListener?.('blur', onWindowBlur);
        splitterEl.classList.remove('is-dragging');
        delete rootEl.dataset.sidebarResizing;

        const releaseId = event?.pointerId ?? activePointerId;
        if (releaseId !== undefined && releaseId !== null) {
            try {
                if (splitterEl.hasPointerCapture?.(releaseId)) {
                    splitterEl.releasePointerCapture?.(releaseId);
                }
            } catch {}
        }

        if (typeof finalRawWidth === 'number') {
            applyResizeState(finalRawWidth, { persist: true });
        }
    };

    const onPointerEnter = (event) => {
        syncSplitterGripY(splitterEl, event.clientY);
    };

    const onWindowBlur = () => {
        endDrag(null);
    };

    const onPointerDown = (event) => {
        if (event.button !== 0 || !canResize()) return;

        event.preventDefault();
        onDragStart();
        syncSplitterGripY(splitterEl, event.clientY);

        const isCurrentlyCollapsed = Boolean(getCollapsed());
        const measuredWidth = sidebarEl.getBoundingClientRect?.().width;
        const startWidth = isCurrentlyCollapsed
            ? COLLAPSED_SIDEBAR_WIDTH
            : (Number.isFinite(measuredWidth) && measuredWidth > 0 ? Math.round(measuredWidth) : resolveActiveExpandedWidth());

        dragState = {
            pointerId: event.pointerId,
            startClientX: event.clientX,
            startWidth,
            latestRawWidth: startWidth,
        };

        splitterEl.classList.add('is-dragging');
        rootEl.dataset.sidebarResizing = 'true';
        windowRef.addEventListener?.('pointerup', onPointerUp);
        windowRef.addEventListener?.('pointercancel', onPointerCancel);
        windowRef.addEventListener?.('blur', onWindowBlur);
        try {
            splitterEl.setPointerCapture?.(event.pointerId);
        } catch {}
    };

    const onPointerMove = (event) => {
        syncSplitterGripY(splitterEl, event.clientY);
        if (!dragState) return;

        const delta = event.clientX - dragState.startClientX;
        const rawTarget = dragState.startWidth + delta;
        const result = applyResizeState(rawTarget, { persist: false });
        dragState.latestRawWidth = result.collapsed ? COLLAPSED_SIDEBAR_WIDTH : result.width;
    };

    const onPointerUp = (event) => {
        endDrag(event);
    };

    const onPointerCancel = (event) => {
        endDrag(event);
    };

    const onLostPointerCapture = () => {
        endDrag(null);
    };

    const onDblClick = () => {
        if (!canResize()) return;
        onDragStart();
        applyResizeState(DEFAULT_SIDEBAR_WIDTH, { persist: true, isReset: true });
        announce(resetAnnouncement);
    };

    const onKeyDown = (event) => {
        if (!canResize()) return;

        const step = event.shiftKey ? SIDEBAR_KEYBOARD_LARGE_STEP : SIDEBAR_KEYBOARD_STEP;
        const { max } = effectiveBounds();
        const isCurrentlyCollapsed = Boolean(getCollapsed());
        const activeWidth = isCurrentlyCollapsed
            ? COLLAPSED_SIDEBAR_WIDTH
            : (currentWidth ?? resolveActiveExpandedWidth());
        let nextWidth = null;
        let isReset = false;

        if (event.key === 'ArrowLeft') {
            if (isCurrentlyCollapsed) return;
            nextWidth = activeWidth - step;
        } else if (event.key === 'ArrowRight') {
            nextWidth = isCurrentlyCollapsed
                ? Math.max(MIN_EXPANDED_SIDEBAR_WIDTH, COLLAPSE_SNAP_THRESHOLD + step)
                : activeWidth + step;
        } else if (event.key === 'Home') {
            if (isCurrentlyCollapsed) return;
            nextWidth = COLLAPSED_SIDEBAR_WIDTH;
        } else if (event.key === 'End') {
            nextWidth = max;
        } else if (event.key === 'Enter') {
            nextWidth = DEFAULT_SIDEBAR_WIDTH;
            isReset = true;
        }

        if (nextWidth === null) return;

        event.preventDefault();
        onDragStart();
        const result = applyResizeState(nextWidth, { persist: true, isReset });
        if (isReset) {
            announce(resetAnnouncement);
        } else if (result.collapsed) {
            announce(collapsedAnnouncement);
        } else {
            announce(formatAnnouncement(result.width));
        }
    };

    const sync = () => {
        if (!canResize()) {
            if (dragState) endDrag(null);
            delete splitterEl.dataset.sidebarEdge;
            delete rootEl.dataset.sidebarNarrow;
            syncAria(getCollapsed() ? COLLAPSED_SIDEBAR_WIDTH : resolveActiveExpandedWidth());
            return;
        }

        if (dragState) return;

        if (getCollapsed()) {
            currentWidth = COLLAPSED_SIDEBAR_WIDTH;
            const storedExpanded = resolveActiveExpandedWidth();
            if (rootEl.dataset.workspaceEmbed !== 'true') {
                if (storedExpanded === DEFAULT_SIDEBAR_WIDTH) {
                    rootEl.style?.removeProperty?.('--sidebar-width');
                } else {
                    rootEl.style?.setProperty?.('--sidebar-width', `${storedExpanded}px`);
                }
            }
            syncEdgeAndNarrowFlags(COLLAPSED_SIDEBAR_WIDTH, true);
            syncAria(COLLAPSED_SIDEBAR_WIDTH);
            return;
        }

        const activeWidth = resolveActiveExpandedWidth();
        currentWidth = activeWidth;
        if (rootEl.dataset.workspaceEmbed !== 'true') {
            if (activeWidth === DEFAULT_SIDEBAR_WIDTH) {
                rootEl.style?.removeProperty?.('--sidebar-width');
            } else {
                rootEl.style?.setProperty?.('--sidebar-width', `${activeWidth}px`);
            }
        }
        syncEdgeAndNarrowFlags(activeWidth, false);
        syncAria(activeWidth);
    };

    const onResize = () => {
        sync();
    };

    splitterEl.addEventListener('pointerenter', onPointerEnter);
    splitterEl.addEventListener('pointerdown', onPointerDown);
    splitterEl.addEventListener('pointermove', onPointerMove);
    splitterEl.addEventListener('pointerup', onPointerUp);
    splitterEl.addEventListener('pointercancel', onPointerCancel);
    splitterEl.addEventListener('lostpointercapture', onLostPointerCapture);
    splitterEl.addEventListener('dblclick', onDblClick);
    splitterEl.addEventListener('keydown', onKeyDown);
    windowRef.addEventListener('resize', onResize, { passive: true });

    sync();

    return {
        sync,
        destroy: () => {
            endDrag(null);
            splitterEl.removeEventListener('pointerenter', onPointerEnter);
            splitterEl.removeEventListener('pointerdown', onPointerDown);
            splitterEl.removeEventListener('pointermove', onPointerMove);
            splitterEl.removeEventListener('pointerup', onPointerUp);
            splitterEl.removeEventListener('pointercancel', onPointerCancel);
            splitterEl.removeEventListener('lostpointercapture', onLostPointerCapture);
            splitterEl.removeEventListener('dblclick', onDblClick);
            splitterEl.removeEventListener('keydown', onKeyDown);
            windowRef.removeEventListener('resize', onResize);
        },
    };
};
