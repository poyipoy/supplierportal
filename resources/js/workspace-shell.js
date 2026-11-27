import {
    appendEmbedParam,
    canonicalizeShortcutModulePath,
    canonicalizeUrl,
    clampOpenPaneWeights,
    classifyInternalNavigation,
    computeOpenPaneWidthPx,
    computeWaterFillTabCap,
    DEFAULT_MAX_PINNED_SESSIONS,
    DEFAULT_MAX_RECENTLY_CLOSED,
    DEFAULT_SHORTCUTS_WEIGHTS,
    formatSessionContextHint,
    formatSessionDuplicateBadge,
    isSameOriginUrl,
    isTopLevelOnlyUrl,
    mergeWorkspaceShortcutsState,
    MIN_SHORTCUTS_PANE_WIDTH_PX,
    normalizeShortcutsLayout,
    resizeShortcutsPairByDelta,
    resolveActiveSidebarLinks,
    resolveDragTargetZoneIndex,
    resolveGroupSessionMenuItems,
    resolveModuleKey,
    resolveShortcutsSplitterPair,
    resolveTransitionSettleMs,
    resolveVisibleShortcutsSplitters,
    safeWebUrl,
    sanitizePersistedPinnedList,
    sanitizeRecentlyClosedList,
    SHORTCUTS_KEY,
    SHORTCUTS_PANE_KEYS,
    stripEmbedParam,
    WorkspaceNavQueue,
    WorkspaceSessionStore,
} from './workspace-shell-core.js';
import {
    buildHistoryProjection,
    buildShortcutsHistoryProjection,
    resolveContextUrl,
    resolvePopstateNavigation,
} from './portal-context-url.js';
import { syncSplitterGripY } from './splitter.js';

function cleanPageTitle(rawTitle, fallback = 'ADASI Portal') {
    if (!rawTitle || typeof rawTitle !== 'string') return fallback;
    const stripped = rawTitle
        .replace(/\s+-\s+(?:ADASI(?:\s+Supplier)?\s+Portal|Portal\s+(?:Supplier\s+)?ADASI)$/i, '')
        .trim();
    return stripped || fallback;
}

function normalizedPath(rawUrl, origin = 'http://localhost') {
    try {
        return new URL(stripEmbedParam(rawUrl, origin), origin).pathname.replace(/\/+$/, '') || '/';
    } catch {
        return '/';
    }
}

export function bootWorkspaceShell() {
    if (typeof window === 'undefined' || typeof document === 'undefined') return null;
    if (window.AdasiWorkspaceShell) return window.AdasiWorkspaceShell;

    const config = window.AdasiWorkspaceShellConfig;
    const stageEl = document.querySelector('[data-workspace-stage]');
    const framesEl = document.querySelector('[data-workspace-frames]');
    const tablistEl = document.querySelector('[data-workspace-sessionlist]');
    const tablistOwnerEl = document.querySelector('[data-workspace-tablist-owner]');
    if (!config?.enabled || !stageEl || !framesEl || !tablistEl) return null;

    const origin = window.location.origin;
    const pathPrefix = config.pathPrefix || '';
    const uuidMap = config.uuidMap || {};
    const pathMap = config.pathMap || {};
    const indexPaths = Array.isArray(config.indexPaths) ? config.indexPaths : [];
    const quickAccessPaths = new Set((config.quickAccessPaths || []).map((p) => String(p).replace(/\/+$/, '') || '/'));
    const i18n = config.i18n || {};
    let lastProjectedContextUrl = null;

    const breakoutToTop = (url) => {
        const safeHref = safeWebUrl(url, origin);
        if (!safeHref || !isSameOriginUrl(safeHref, origin)) return false;
        window.location.assign(stripEmbedParam(safeHref, origin));
        return true;
    };

    const openSafeExternal = (url) => {
        const safeExternal = safeWebUrl(url, origin);
        if (safeExternal) {
            window.open(safeExternal, '_blank', 'noopener,noreferrer');
        }
    };

    const tabbarEl = document.querySelector('[data-workspace-sessionbar]');
    const viewportEl = document.querySelector('[data-workspace-session-viewport]');
    const scrollPrevBtn = document.querySelector('[data-workspace-scroll="prev"]');
    const scrollNextBtn = document.querySelector('[data-workspace-scroll="next"]');
    const switcherBtn = document.querySelector('[data-workspace-switcher]');
    const switcherCountEl = document.querySelector('[data-workspace-switcher-count]');
    const refreshBtn = document.querySelector('[data-workspace-refresh]');
    const staleBtn = document.querySelector('[data-workspace-stale-action]');
    const progressEl = document.querySelector('[data-workspace-progress]');
    const tabMenuEl = document.querySelector('[data-workspace-session-menu]');
    const liveRegionEl = document.querySelector('[data-workspace-live-region]');
    const topbarTitleEl = document.querySelector('.top-navbar .topbar-page-title');

    const WORKSPACE_MOTION_FALLBACK_MS = 420;
    const motionCleanupMap = new WeakMap();

    let hasCompletedInitialSync = false;
    let localeSyncSeq = 0;
    const syncedLocaleFrames = new Set();
    const pendingLocaleSyncFrames = new Map();
    let latestLocalePayload = null;

    const prefersReducedMotion = () => Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);

    const getTabGapPx = () => {
        try {
            const cs = window.getComputedStyle(tablistEl);
            const raw = parseFloat(cs.columnGap || cs.gap || '');
            return Number.isFinite(raw) ? raw : 5;
        } catch {
            return 5;
        }
    };

    const cancelElementMotion = (el) => {
        const record = motionCleanupMap.get(el);
        if (!record) return;
        motionCleanupMap.delete(el);
        record.cleanup?.();
    };

    const resolveMotionSettleMs = (el) => {
        try {
            const cs = window.getComputedStyle(el);
            return resolveTransitionSettleMs(cs.transitionDuration, cs.transitionDelay, {
                floorMs: WORKSPACE_MOTION_FALLBACK_MS,
            });
        } catch {
            return WORKSPACE_MOTION_FALLBACK_MS;
        }
    };

    const finalizeElementMotionWhenSettled = (el, expectedProp, onFinish) => {
        cancelElementMotion(el);
        let done = false;
        let timer = 0;
        const finish = () => {
            if (done) return;
            done = true;
            el.removeEventListener('transitionend', onTransitionEnd);
            if (timer) window.clearTimeout(timer);
            motionCleanupMap.delete(el);
            onFinish?.();
        };
        const onTransitionEnd = (event) => {
            if (event.target === el && event.propertyName === expectedProp) {
                finish();
            }
        };
        el.addEventListener('transitionend', onTransitionEnd);
        timer = window.setTimeout(finish, resolveMotionSettleMs(el));
        motionCleanupMap.set(el, {
            cleanup: () => {
                if (done) return;
                done = true;
                el.removeEventListener('transitionend', onTransitionEnd);
                if (timer) window.clearTimeout(timer);
            },
        });
    };

    const sanitizeGhostElement = (root) => {
        root.setAttribute('aria-hidden', 'true');
        root.removeAttribute('id');
        root.removeAttribute('role');
        for (const attr of Array.from(root.attributes)) {
            if (attr.name.startsWith('data-workspace-')) {
                root.removeAttribute(attr.name);
            }
        }
        root.querySelectorAll('*').forEach((child) => {
            child.removeAttribute('id');
            child.removeAttribute('role');
            child.setAttribute('tabindex', '-1');
            if (child instanceof HTMLButtonElement) {
                child.disabled = true;
            }
            for (const attr of Array.from(child.attributes)) {
                if (attr.name.startsWith('data-workspace-')) {
                    child.removeAttribute(attr.name);
                }
            }
        });
    };

    const roundPx = (value) => Math.round((Number(value) || 0) * 100) / 100;

    const getHorizontalBorderPx = (el) => {
        if (!el) return 0;
        try {
            const cs = window.getComputedStyle(el);
            const left = parseFloat(cs.borderLeftWidth || '0') || 0;
            const right = parseFloat(cs.borderRightWidth || '0') || 0;
            return roundPx(left + right);
        } catch {
            return 0;
        }
    };

    const createCollapseTail = (removedPills) => {
        if (!removedPills || removedPills.length === 0) return null;
        const tail = document.createElement('div');
        tail.className = 'workspace-session-group__collapse-tail';
        tail.setAttribute('aria-hidden', 'true');
        let totalWidth = 0;
        for (const pill of removedPills) {
            const pillW = roundPx(pill.getBoundingClientRect?.().width || 0);
            const clone = pill.cloneNode(true);
            clone.querySelectorAll('.workspace-session__morph-overlay').forEach((n) => n.remove());
            sanitizeGhostElement(clone);
            if (pillW > 0) {
                totalWidth = roundPx(totalWidth + pillW);
                clone.style.width = `${pillW}px`;
                clone.style.minWidth = `${pillW}px`;
                clone.style.maxWidth = `${pillW}px`;
                clone.style.flex = '0 0 auto';
                const trigger = clone.querySelector(':scope > .workspace-session__trigger');
                if (trigger) {
                    trigger.style.width = `${pillW}px`;
                    trigger.style.minWidth = `${pillW}px`;
                }
            }
            tail.appendChild(clone);
        }
        tail._startWidth = totalWidth;
        return tail;
    };

    const createPillMorphOverlay = (prevPill) => {
        if (!prevPill) return null;
        const clone = prevPill.cloneNode(true);
        clone.querySelectorAll('.workspace-session__morph-overlay').forEach((n) => n.remove());
        sanitizeGhostElement(clone);
        const overlay = document.createElement('span');
        overlay.className = 'workspace-session__morph-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        while (clone.firstChild) {
            const child = clone.firstChild;
            if (child instanceof HTMLElement) {
                child.style.width = '';
                child.style.minWidth = '';
                child.style.opacity = '';
                child.style.transition = '';
            }
            overlay.appendChild(child);
        }
        return overlay;
    };

    const measureTargetGroupLayout = (groupEl, transientHide = []) => {
        const prevDisplays = transientHide.map((node) => node.style.display);
        transientHide.forEach((node) => {
            node.style.display = 'none';
        });

        const prevTransition = groupEl.style.transition;
        const prevWidth = groupEl.style.width;
        const prevMinWidth = groupEl.style.minWidth;
        const prevMaxWidth = groupEl.style.maxWidth;
        const prevMarginRight = groupEl.style.marginRight;
        const hadEntering = groupEl.classList.contains('is-entering');
        const hadMorphing = groupEl.classList.contains('is-morphing');
        const hadExpanding = groupEl.classList.contains('is-expanding-group');
        const hadCollapsing = groupEl.classList.contains('is-collapsing-group');

        groupEl.style.transition = 'none';
        groupEl.style.width = '';
        groupEl.style.minWidth = '';
        groupEl.style.maxWidth = '';
        groupEl.style.marginRight = '';
        groupEl.classList.remove('is-entering', 'is-morphing', 'is-expanding-group', 'is-collapsing-group');

        const livePills = Array.from(groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)'));
        const pillSnapshots = livePills.map((pill) => {
            const snap = {
                pill,
                transition: pill.style.transition,
                width: pill.style.width,
                minWidth: pill.style.minWidth,
                maxWidth: pill.style.maxWidth,
            };
            pill.style.transition = 'none';
            pill.style.width = '';
            pill.style.minWidth = '';
            pill.style.maxWidth = '';
            return snap;
        });

        const targetWidth = roundPx(groupEl.getBoundingClientRect?.().width || 0);
        const pillWidths = new Map();
        livePills.forEach((pill) => {
            pillWidths.set(pill, roundPx(pill.getBoundingClientRect?.().width || 0));
        });

        pillSnapshots.forEach((snap) => {
            snap.pill.style.width = snap.width;
            snap.pill.style.minWidth = snap.minWidth;
            snap.pill.style.maxWidth = snap.maxWidth;
        });

        groupEl.style.width = prevWidth;
        groupEl.style.minWidth = prevMinWidth;
        groupEl.style.maxWidth = prevMaxWidth;
        groupEl.style.marginRight = prevMarginRight;
        if (hadEntering) groupEl.classList.add('is-entering');
        if (hadMorphing) groupEl.classList.add('is-morphing');
        if (hadExpanding) groupEl.classList.add('is-expanding-group');
        if (hadCollapsing) groupEl.classList.add('is-collapsing-group');

        transientHide.forEach((node, idx) => {
            node.style.display = prevDisplays[idx];
        });

        void groupEl.offsetWidth;
        pillSnapshots.forEach((snap) => {
            snap.pill.style.transition = snap.transition;
        });
        groupEl.style.transition = prevTransition;

        return { targetWidth, pillWidths };
    };

    const scrollGroupTargetIntoView = (groupEl, targetWidth) => {
        if (!groupEl?.isConnected) return;
        const isGroupActive = groupEl.classList.contains('is-active')
            || Boolean(groupEl.querySelector('[role="tab"][aria-selected="true"]'));
        if (!isGroupActive) return;

        const maxScroll = Math.max(0, tablistEl.scrollWidth - tablistEl.clientWidth);
        if (maxScroll <= 0) return;
        const rightEdgeNeeded = groupEl.offsetLeft + targetWidth + 32;
        const visibleRight = tablistEl.scrollLeft + tablistEl.clientWidth;
        const reduced = prefersReducedMotion();
        if (rightEdgeNeeded > visibleRight) {
            tablistEl.scrollTo({
                left: Math.min(maxScroll, rightEdgeNeeded - tablistEl.clientWidth),
                behavior: reduced ? 'auto' : 'smooth',
            });
        } else if (groupEl.offsetLeft < tablistEl.scrollLeft) {
            tablistEl.scrollTo({
                left: Math.max(0, groupEl.offsetLeft - 4),
                behavior: reduced ? 'auto' : 'smooth',
            });
        }
    };

    const clearGroupMotionStyles = (groupEl) => {
        if (!groupEl) return;
        groupEl.classList.remove(
            'is-entering',
            'is-morphing',
            'is-expanding-group',
            'is-collapsing-group',
        );
        groupEl.style.transition = '';
        groupEl.style.width = '';
        groupEl.style.minWidth = '';
        groupEl.style.maxWidth = '';
        groupEl.style.marginRight = '';
        groupEl.style.opacity = '';
        groupEl.style.borderLeftWidth = '';
        groupEl.style.borderRightWidth = '';
        groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)').forEach((pill) => {
            pill.classList.remove('is-morph-pill', 'is-morph-fade-in', 'is-revealing', 'is-revealed');
            pill.style.transition = '';
            pill.style.width = '';
            pill.style.minWidth = '';
            pill.style.maxWidth = '';
            pill.style.flexShrink = '';
            pill.style.opacity = '';
            const trigger = pill.querySelector(':scope > .workspace-session__trigger');
            if (trigger) {
                trigger.style.transition = '';
                trigger.style.width = '';
                trigger.style.minWidth = '';
                trigger.style.opacity = '';
            }
            const closeBtn = pill.querySelector(':scope > .workspace-session__close');
            if (closeBtn) {
                closeBtn.style.transition = '';
                closeBtn.style.opacity = '';
            }
            pill.querySelectorAll(':scope > .workspace-session__morph-overlay').forEach((n) => n.remove());
        });
    };

    const insertGroupAfterAnchor = (anchor, groupEl) => {
        let nextLive = anchor ? anchor.nextElementSibling : tablistEl.firstElementChild;
        while (nextLive?.classList?.contains('workspace-session-group--ghost')) {
            nextLive = nextLive.nextElementSibling;
        }
        if (nextLive === groupEl) return;
        if (nextLive) {
            nextLive.before(groupEl);
        } else {
            tablistEl.appendChild(groupEl);
        }
    };

    const animateGroupTransition = (groupEl, {
        startWidth,
        targetWidth,
        pillWidths = new Map(),
        isNew = false,
        tailEl = null,
        childGhosts = [],
        revealingPills = [],
        isStackEntering = false,
        wasCollapsedStack = false,
        prevPill0Width = 0,
        prevPill0Overlay = null,
        morphType = 'same',
    }) => {
        const cleanTransients = () => {
            tailEl?.remove();
            childGhosts.forEach((ghost) => ghost.remove());
            prevPill0Overlay?.remove();
        };

        if (!groupEl || !hasCompletedInitialSync || prefersReducedMotion() || targetWidth <= 0) {
            cancelElementMotion(groupEl);
            clearGroupMotionStyles(groupEl);
            cleanTransients();
            return;
        }

        const livePills = Array.from(groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)'));
        const pill0 = livePills[0] || null;
        const targetPill0Width = pill0 ? (pillWidths.get(pill0) || 0) : 0;
        const groupBorderH = getHorizontalBorderPx(groupEl);
        const startPill0InnerW = Math.max(
            0,
            roundPx(prevPill0Width > 0 && morphType !== 'single-to-expanded' ? prevPill0Width : startWidth - groupBorderH),
        );
        const hasPill0Delta = morphType !== 'same' || Math.abs(targetPill0Width - startPill0InnerW) >= 1;
        const hasDelta = Math.abs(targetWidth - startWidth) >= 1;
        if (
            !isNew
            && !hasDelta
            && !hasPill0Delta
            && !tailEl
            && childGhosts.length === 0
            && revealingPills.length === 0
        ) {
            cancelElementMotion(groupEl);
            clearGroupMotionStyles(groupEl);
            cleanTransients();
            return;
        }

        cancelElementMotion(groupEl);

        const gapPx = getTabGapPx();
        const isExpandingMotion = isNew
            || morphType === 'stack-to-expanded'
            || morphType === 'single-to-expanded'
            || revealingPills.length > 0;
        const isCollapsingMotion = !isExpandingMotion && (
            morphType === 'expanded-to-stack'
            || Boolean(tailEl)
            || childGhosts.length > 0
            || targetWidth < startWidth
        );
        const motionClass = isNew ? 'is-entering' : 'is-morphing';

        groupEl.classList.remove(
            'is-entering',
            'is-morphing',
            'is-expanding-group',
            'is-collapsing-group',
        );
        groupEl.style.transition = 'none';
        groupEl.classList.add(motionClass);
        if (isExpandingMotion && !isNew) {
            groupEl.classList.add('is-expanding-group');
        } else if (isCollapsingMotion) {
            groupEl.classList.add('is-collapsing-group');
        }

        groupEl.style.width = `${startWidth}px`;
        groupEl.style.minWidth = `${startWidth}px`;
        groupEl.style.maxWidth = `${Math.max(startWidth, targetWidth)}px`;
        if (isNew) {
            groupEl.style.marginRight = `${-gapPx}px`;
            groupEl.style.opacity = '0';
            groupEl.style.borderLeftWidth = '0px';
            groupEl.style.borderRightWidth = '0px';
        }

        const pill0MorphActive = Boolean(pill0 && !isNew && targetPill0Width > 0 && hasPill0Delta);
        const revealingSet = new Set(revealingPills);

        livePills.forEach((pill, idx) => {
            const targetW = pillWidths.get(pill) || roundPx(pill.getBoundingClientRect?.().width || 0);
            if (idx === 0 && pill0MorphActive) {
                pill.classList.add('is-morph-pill');
                if (prevPill0Overlay && (morphType === 'stack-to-expanded' || morphType === 'expanded-to-stack')) {
                    pill.classList.add('is-morph-fade-in');
                    pill.appendChild(prevPill0Overlay);
                }
                pill.style.transition = 'none';
                pill.style.width = `${startPill0InnerW}px`;
                pill.style.minWidth = `${startPill0InnerW}px`;
                pill.style.maxWidth = `${Math.max(startPill0InnerW, targetW)}px`;
                pill.style.flexShrink = '0';
                return;
            }
            if (revealingSet.has(pill)) {
                const trigger = pill.querySelector(':scope > .workspace-session__trigger');
                if (trigger && targetW > 0) {
                    trigger.style.transition = 'none';
                    trigger.style.width = `${targetW}px`;
                    trigger.style.minWidth = `${targetW}px`;
                }
                pill.classList.remove('is-revealed');
                pill.classList.add('is-revealing');
                pill.style.transition = 'none';
                pill.style.width = '0px';
                pill.style.minWidth = '0px';
                pill.style.maxWidth = `${targetW}px`;
                pill.style.flexShrink = '0';
                return;
            }
            if (!isNew && targetW > 0) {
                pill.style.width = `${targetW}px`;
                pill.style.minWidth = `${targetW}px`;
                pill.style.maxWidth = `${targetW}px`;
                pill.style.flexShrink = '0';
            }
        });

        if (tailEl) {
            const tailW = roundPx(tailEl._startWidth || 0);
            tailEl.style.transition = 'none';
            tailEl.style.width = `${tailW}px`;
            tailEl.style.minWidth = `${tailW}px`;
            tailEl.style.maxWidth = `${tailW}px`;
            tailEl.style.opacity = '1';
        }

        childGhosts.forEach((ghost) => {
            const gw = roundPx(ghost._startWidth || 0);
            const trigger = ghost.querySelector(':scope > .workspace-session__trigger');
            if (trigger && gw > 0) {
                trigger.style.width = `${gw}px`;
                trigger.style.minWidth = `${gw}px`;
            }
            ghost.style.transition = 'none';
            ghost.style.width = `${gw}px`;
            ghost.style.minWidth = `${gw}px`;
            ghost.style.maxWidth = `${gw}px`;
            ghost.style.opacity = '1';
        });

        // Commit before-change layout geometry before re-enabling transitions.
        void groupEl.offsetWidth;
        groupEl.style.transition = '';
        if (pill0MorphActive && pill0) {
            pill0.style.transition = '';
        }
        revealingPills.forEach((pill) => {
            pill.style.transition = '';
            const trigger = pill.querySelector(':scope > .workspace-session__trigger');
            if (trigger) trigger.style.transition = '';
        });
        if (tailEl) tailEl.style.transition = '';
        childGhosts.forEach((ghost) => {
            ghost.style.transition = '';
        });
        void groupEl.offsetWidth;

        groupEl.style.width = `${targetWidth}px`;
        groupEl.style.minWidth = `${targetWidth}px`;
        groupEl.style.maxWidth = `${Math.max(startWidth, targetWidth)}px`;
        if (isNew) {
            groupEl.style.marginRight = '0px';
            groupEl.style.opacity = '1';
            groupEl.style.borderLeftWidth = '';
            groupEl.style.borderRightWidth = '';
        }

        if (pill0MorphActive && pill0) {
            pill0.classList.remove('is-morph-fade-in');
            pill0.style.width = `${targetPill0Width}px`;
            pill0.style.minWidth = `${targetPill0Width}px`;
            pill0.style.maxWidth = `${Math.max(startPill0InnerW, targetPill0Width)}px`;
            if (prevPill0Overlay) {
                prevPill0Overlay.classList.add('is-fading-out');
            }
        }

        revealingPills.forEach((pill) => {
            const targetW = pillWidths.get(pill) || 0;
            pill.style.width = `${targetW}px`;
            pill.style.minWidth = `${targetW}px`;
            pill.style.maxWidth = `${targetW}px`;
            pill.classList.add('is-revealed');
        });

        if (tailEl) {
            tailEl.classList.add('is-collapsing');
            tailEl.style.width = '0px';
            tailEl.style.minWidth = '0px';
            tailEl.style.maxWidth = '0px';
            tailEl.style.opacity = '0';
        }
        childGhosts.forEach((ghost) => {
            ghost.classList.add('is-collapsing');
            ghost.style.width = '0px';
            ghost.style.minWidth = '0px';
            ghost.style.maxWidth = '0px';
            ghost.style.opacity = '0';
        });

        scrollGroupTargetIntoView(groupEl, targetWidth);

        finalizeElementMotionWhenSettled(groupEl, 'width', () => {
            clearGroupMotionStyles(groupEl);
            cleanTransients();
            scheduleCapSettleCheck?.();
            updateTabOverflowState();
            scrollGroupTargetIntoView(groupEl, roundPx(groupEl.getBoundingClientRect?.().width || targetWidth));
        });
    };

    const animateRemovedGroupNode = (groupEl) => {
        if (!groupEl || !hasCompletedInitialSync || prefersReducedMotion()) return;
        if (tablistEl.querySelectorAll('.workspace-session-group--ghost').length >= 12) return;

        const rect = groupEl.getBoundingClientRect?.();
        const startWidth = rect ? Math.round(rect.width * 100) / 100 : 0;
        if (startWidth <= 0) return;

        const connectedPillWidths = Array.from(groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)'))
            .map((pill) => roundPx(pill.getBoundingClientRect?.().width || 0));

        cancelElementMotion(groupEl);

        const ghost = groupEl.cloneNode(true);
        sanitizeGhostElement(ghost);
        ghost.classList.remove(
            'is-entering',
            'is-morphing',
            'is-expanding-group',
            'is-collapsing-group',
            'is-active',
        );
        ghost.classList.add('workspace-session-group--ghost', 'is-closing');
        ghost.querySelectorAll(
            '.workspace-session-group__collapse-tail, .workspace-session--ghost, .workspace-session__morph-overlay',
        ).forEach((n) => n.remove());
        Array.from(ghost.querySelectorAll(':scope > .workspace-session')).forEach((pillClone, idx) => {
            pillClone.classList.remove('is-morph-pill', 'is-morph-fade-in', 'is-revealing', 'is-revealed');
            pillClone.style.transition = 'none';
            const pw = connectedPillWidths[idx] || 0;
            if (pw > 0) {
                pillClone.style.width = `${pw}px`;
                pillClone.style.minWidth = `${pw}px`;
                pillClone.style.maxWidth = `${pw}px`;
                pillClone.style.flexShrink = '0';
                const trigger = pillClone.querySelector(':scope > .workspace-session__trigger');
                if (trigger) {
                    trigger.style.width = `${pw}px`;
                    trigger.style.minWidth = `${pw}px`;
                }
            }
        });

        const gapPx = getTabGapPx();
        ghost.style.transition = 'none';
        ghost.style.width = `${startWidth}px`;
        ghost.style.minWidth = `${startWidth}px`;
        ghost.style.maxWidth = `${startWidth}px`;
        ghost.style.marginRight = '0px';
        ghost.style.opacity = '1';

        groupEl.before(ghost);

        void ghost.offsetWidth;
        ghost.style.transition = '';
        void ghost.offsetWidth;

        ghost.classList.add('is-collapsing');
        ghost.style.width = '0px';
        ghost.style.minWidth = '0px';
        ghost.style.maxWidth = '0px';
        ghost.style.marginRight = `${-gapPx}px`;
        ghost.style.borderLeftWidth = '0px';
        ghost.style.borderRightWidth = '0px';
        ghost.style.opacity = '0';

        finalizeElementMotionWhenSettled(ghost, 'width', () => {
            ghost.remove();
            updateTabOverflowState();
        });
    };

    const resolveCssLengthPx = (rawValue, fallbackPx) => {
        const text = String(rawValue || '').trim();
        if (!text) return fallbackPx;
        const num = parseFloat(text);
        if (!Number.isFinite(num) || num <= 0) return fallbackPx;
        if (text.endsWith('rem')) {
            const rootFont = parseFloat(window.getComputedStyle(document.documentElement).fontSize || '16') || 16;
            return roundPx(num * rootFont);
        }
        return roundPx(num);
    };

    const getScrollButtonsOverheadPx = () => {
        const barCs = tabbarEl ? window.getComputedStyle(tabbarEl) : null;
        const barGap = parseFloat(barCs?.columnGap || barCs?.gap || '8') || 8;
        let overhead = 0;
        if (scrollPrevBtn && !scrollPrevBtn.hidden && window.getComputedStyle(scrollPrevBtn).display !== 'none') {
            overhead += scrollPrevBtn.offsetWidth + barGap;
        }
        if (scrollNextBtn && !scrollNextBtn.hidden && window.getComputedStyle(scrollNextBtn).display !== 'none') {
            overhead += scrollNextBtn.offsetWidth + barGap;
        }
        return roundPx(overhead);
    };

    let lastResponsiveTier = null;

    const recomputeDynamicTabCap = ({ invalidateNatural = false } = {}) => {
        if (!tablistEl) return;

        const barCs = window.getComputedStyle(tabbarEl || tablistEl);
        const listCs = window.getComputedStyle(tablistEl);
        const minCapPx = resolveCssLengthPx(barCs.getPropertyValue('--workspace-session-min-width'), 116);
        const stackedMinCapPx = resolveCssLengthPx(barCs.getPropertyValue('--workspace-session-stacked-min-width'), 140);
        const maxCapPx = resolveCssLengthPx(barCs.getPropertyValue('--workspace-session-max-width'), 248);
        const tier = `${minCapPx}:${stackedMinCapPx}:${maxCapPx}`;
        const shouldInvalidate = invalidateNatural || (lastResponsiveTier !== null && lastResponsiveTier !== tier);
        lastResponsiveTier = tier;

        const liveGroups = Array.from(tablistEl.querySelectorAll(':scope > [data-workspace-group]:not(.workspace-session-group--ghost)'));
        if (liveGroups.length === 0) {
            tablistEl.dataset.capRequiresScroll = 'false';
            tablistEl.style.setProperty('--workspace-session-cap', 'var(--workspace-session-max-width)');
            return;
        }

        const uncachedPills = [];
        liveGroups.forEach((groupEl) => {
            groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)').forEach((pill) => {
                if (shouldInvalidate) {
                    delete pill.dataset.naturalWidth;
                    pill.style.removeProperty('--workspace-session-natural-width');
                }
                if (!pill.dataset.naturalWidth) {
                    uncachedPills.push(pill);
                }
            });
        });

        const groupSnapshots = [];
        let pillSnapshots = [];
        if (uncachedPills.length > 0) {
            const isFinePointer = Boolean(window.matchMedia?.('(hover: hover) and (pointer: fine)')?.matches);
            const touchedGroups = new Set();
            uncachedPills.forEach((pill) => {
                const grp = pill.parentElement;
                if (grp && !touchedGroups.has(grp) && !grp.classList.contains('is-entering') && !grp.classList.contains('is-morphing')) {
                    touchedGroups.add(grp);
                    groupSnapshots.push({
                        grp,
                        transition: grp.style.transition,
                        width: grp.style.width,
                        minWidth: grp.style.minWidth,
                        maxWidth: grp.style.maxWidth,
                    });
                    grp.style.transition = 'none';
                    grp.style.width = '';
                    grp.style.minWidth = '';
                    grp.style.maxWidth = '';
                }
            });
            pillSnapshots = uncachedPills.map((pill) => {
                const closeBtn = pill.querySelector(':scope > .workspace-session__close');
                const snap = {
                    pill,
                    transition: pill.style.transition,
                    width: pill.style.width,
                    minWidth: pill.style.minWidth,
                    maxWidth: pill.style.maxWidth,
                    fontWeight: pill.style.fontWeight,
                    closeBtn: isFinePointer ? closeBtn : null,
                    closeTransition: closeBtn ? closeBtn.style.transition : '',
                    closeWidth: closeBtn ? closeBtn.style.width : '',
                    closeMinWidth: closeBtn ? closeBtn.style.minWidth : '',
                    closeMarginRight: closeBtn ? closeBtn.style.marginRight : '',
                };
                pill.style.transition = 'none';
                pill.style.width = 'auto';
                pill.style.minWidth = '0px';
                pill.style.maxWidth = 'var(--workspace-session-max-width)';
                pill.style.fontWeight = '600';
                if (isFinePointer && closeBtn) {
                    closeBtn.style.transition = 'none';
                    closeBtn.style.width = '0px';
                    closeBtn.style.minWidth = '0px';
                    closeBtn.style.marginRight = '0px';
                }
                return snap;
            });

            uncachedPills.forEach((pill) => {
                const rawW = Math.ceil(pill.getBoundingClientRect?.().width || 0);
                if (rawW > 0) {
                    const floorW = pill.classList.contains('workspace-session--stacked') ? stackedMinCapPx : 0;
                    const naturalW = Math.max(rawW, floorW);
                    pill.dataset.naturalWidth = String(naturalW);
                    pill.style.setProperty('--workspace-session-natural-width', `${naturalW}px`);
                }
            });

            pillSnapshots.forEach((snap) => {
                snap.pill.style.maxWidth = snap.maxWidth;
                snap.pill.style.minWidth = snap.minWidth;
                snap.pill.style.width = snap.width;
                snap.pill.style.fontWeight = snap.fontWeight;
                if (snap.closeBtn) {
                    snap.closeBtn.style.width = snap.closeWidth;
                    snap.closeBtn.style.minWidth = snap.closeMinWidth;
                    snap.closeBtn.style.marginRight = snap.closeMarginRight;
                }
            });
            groupSnapshots.forEach((snap) => {
                snap.grp.style.maxWidth = snap.maxWidth;
                snap.grp.style.minWidth = snap.minWidth;
                snap.grp.style.width = snap.width;
            });
        }

        const pillItems = [];
        let borderOverhead = 0;
        liveGroups.forEach((groupEl) => {
            borderOverhead += getHorizontalBorderPx(groupEl);
            groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)').forEach((pill) => {
                const nw = parseFloat(pill.dataset.naturalWidth || '');
                const w = Number.isFinite(nw) && nw > 0 ? nw : roundPx(pill.getBoundingClientRect?.().width || 0);
                if (w > 0) {
                    const pillMin = pill.classList.contains('workspace-session--stacked') ? stackedMinCapPx : minCapPx;
                    pillItems.push({ width: w, minWidth: pillMin });
                }
            });
        });

        const gapPx = getTabGapPx();
        const gapsOverhead = Math.max(0, liveGroups.length - 1) * gapPx;
        const paddingLeft = parseFloat(listCs.paddingLeft || '0') || 0;
        const paddingRight = parseFloat(listCs.paddingRight || '0') || 0;
        const overheadPx = roundPx(gapsOverhead + borderOverhead);
        const listInnerWidth = Math.max(0, Math.floor(tablistEl.clientWidth - paddingLeft - paddingRight) - 2);
        const unscrolledBudgetPx = roundPx(listInnerWidth + getScrollButtonsOverheadPx());

        const solvedCap = computeWaterFillTabCap({
            pillWidths: pillItems,
            overheadPx,
            budgetPx: unscrolledBudgetPx,
            minCapPx,
            maxCapPx,
        });

        const estimatedTotal = pillItems.reduce(
            (sum, item) => sum + Math.min(item.width, Math.max(Math.min(item.width, item.minWidth), solvedCap)),
            0,
        ) + overheadPx;
        tablistEl.dataset.capRequiresScroll = estimatedTotal > unscrolledBudgetPx ? 'true' : 'false';

        const prevCapCss = tablistEl.style.getPropertyValue('--workspace-session-cap');
        const nextCapCss = solvedCap >= maxCapPx ? 'var(--workspace-session-max-width)' : `${solvedCap}px`;
        tablistEl.style.setProperty('--workspace-session-cap', nextCapCss);
        if (uncachedPills.length > 0) {
            void tablistEl.offsetWidth;
            pillSnapshots.forEach((snap) => {
                snap.pill.style.transition = snap.transition;
                if (snap.closeBtn) {
                    snap.closeBtn.style.transition = snap.closeTransition;
                }
            });
            groupSnapshots.forEach((snap) => {
                snap.grp.style.transition = snap.transition;
            });
        }
        if (hasCompletedInitialSync && prevCapCss !== nextCapCss) {
            scheduleCapSettleCheck();
        }
    };

    let resizeSettleTimer = 0;
    let isCapTransitionSettling = false;

    const scheduleCapSettleCheck = () => {
        if (prefersReducedMotion()) {
            isCapTransitionSettling = false;
            return;
        }
        isCapTransitionSettling = true;
        if (resizeSettleTimer) window.clearTimeout(resizeSettleTimer);
        resizeSettleTimer = window.setTimeout(() => {
            resizeSettleTimer = 0;
            updateTabOverflowState({ fromSettle: true });
        }, WORKSPACE_MOTION_FALLBACK_MS);
    };

    const updateTabOverflowState = ({ fromSettle = false } = {}) => {
        if (fromSettle) {
            isCapTransitionSettling = false;
        }
        const arrowsOverhead = getScrollButtonsOverheadPx();
        const hasClosingGhost = Boolean(tablistEl.querySelector('.workspace-session-group--ghost, .workspace-session--ghost'));
        const capRequiresScroll = tablistEl.dataset.capRequiresScroll === 'true';
        const rawMaxScroll = Math.max(0, tablistEl.scrollWidth - tablistEl.clientWidth);
        const fitsWithoutArrows = !capRequiresScroll
            && !hasClosingGhost
            && ((arrowsOverhead > 0 && tablistEl.scrollWidth <= tablistEl.clientWidth + arrowsOverhead + 1)
                || (arrowsOverhead === 0 && isCapTransitionSettling && !prefersReducedMotion()));
        const hasOverflow = fitsWithoutArrows ? false : rawMaxScroll > 2;
        const maxScroll = hasOverflow ? rawMaxScroll : 0;
        const atStart = tablistEl.scrollLeft <= 2;
        const atEnd = tablistEl.scrollLeft >= maxScroll - 2;

        viewportEl?.classList.toggle('is-overflow-left', hasOverflow && !atStart);
        viewportEl?.classList.toggle('is-overflow-right', hasOverflow && !atEnd);

        if (scrollPrevBtn) {
            scrollPrevBtn.hidden = !hasOverflow;
            scrollPrevBtn.disabled = atStart;
        }
        if (scrollNextBtn) {
            scrollNextBtn.hidden = !hasOverflow;
            scrollNextBtn.disabled = atEnd;
        }
    };

    const scrollTabStrip = (direction) => {
        const delta = Math.max(180, Math.round(tablistEl.clientWidth * 0.55));
        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        tablistEl.scrollBy({
            left: direction * delta,
            behavior: reducedMotion ? 'auto' : 'smooth',
        });
    };

    const shortcutsScope = tabbarEl || document;
    const shortcutsPanel = document.querySelector('[data-workspace-panel="shortcuts"]');
    const shortcutsTabEl = shortcutsScope.querySelector('[data-workspace-session="shortcuts"]');
    const shortcutsSelectBtn = shortcutsScope.querySelector('[data-workspace-shortcuts-select]');
    const shortcutsBoardEl = document.querySelector('[data-shortcuts-board]');
    const shortcutsSplitterKeys = ['favorites-bookmarks', 'bookmarks-frequent'];
    const shortcutsSplitterEls = Object.fromEntries(
        shortcutsSplitterKeys.map((key) => [key, document.querySelector(`[data-shortcuts-splitter="${key}"]`)]),
    );
    const shortcutsFavoritesEl = document.querySelector('[data-shortcuts-favorites]');
    const shortcutsBookmarksEl = document.querySelector('[data-shortcuts-bookmarks]');
    const shortcutsFrequentEl = document.querySelector('[data-shortcuts-frequent]');
    const shortcutsPaneEls = Object.fromEntries(
        SHORTCUTS_PANE_KEYS.map((paneKey) => [paneKey, document.querySelector(`[data-shortcuts-pane="${paneKey}"]`)]),
    );
    const shortcutsPaneToggleEls = Object.fromEntries(
        SHORTCUTS_PANE_KEYS.map((paneKey) => [paneKey, document.querySelector(`[data-shortcuts-pane-toggle="${paneKey}"]`)]),
    );
    const shortcutsDockEl = document.querySelector('[data-shortcuts-dock]');
    const shortcutsRestoreEls = Object.fromEntries(
        SHORTCUTS_PANE_KEYS.map((paneKey) => [paneKey, document.querySelector(`[data-shortcuts-pane-restore="${paneKey}"]`)]),
    );

    const cloneTemplateIcon = (name) => {
        const tpl = document.querySelector(`template[data-workspace-icon="${name}"]`);
        return tpl?.content?.firstElementChild ? tpl.content.firstElementChild.cloneNode(true) : null;
    };

    const matchPathKey = (path) => {
        if (pathMap[path]?.key) return pathMap[path].key;
        let bestKey = null;
        let bestLen = -1;
        for (const [basePath, entry] of Object.entries(pathMap)) {
            if (path.startsWith(`${basePath}/`) && basePath.length > bestLen) {
                bestLen = basePath.length;
                bestKey = entry.key;
            }
        }
        return bestKey;
    };

    const resolveLinkTarget = (rawHref) => {
        if (!rawHref) return { url: '', key: null };
        try {
            const parsed = new URL(rawHref, origin);
            if (config.contextLinks?.enabled) {
                const contextMatch = resolveContextUrl(parsed.toString(), origin, config.contextLinks);
                if (contextMatch?.internalUrl) {
                    const contextPath = normalizedPath(contextMatch.internalUrl, origin);
                    return {
                        url: contextMatch.internalUrl,
                        key: matchPathKey(contextPath) || resolveModuleKey(contextMatch.internalUrl, indexPaths, origin),
                    };
                }
            }
            const path = parsed.pathname.replace(/\/+$/, '') || '/';
            const mapped = uuidMap[path];
            if (mapped) {
                const resolved = new URL(mapped.url, origin);
                parsed.searchParams.forEach((value, name) => {
                    if (!resolved.searchParams.has(name)) resolved.searchParams.set(name, value);
                });
                return { url: resolved.toString(), key: mapped.key };
            }
            return {
                url: stripEmbedParam(parsed.toString(), origin),
                key: matchPathKey(path),
            };
        } catch {
            return { url: '', key: null };
        }
    };

    // Build authorized, role/scope-filtered navigation catalog directly from #sidebar DOM.
    const buildCatalog = () => {
        const entries = [];
        const seenPaths = new Set();
        let currentCategory = i18n.allModules || 'Modules';
        const nodes = document.querySelectorAll('#sidebar .sidebar-heading, #sidebar a.sidebar-link[href]');

        for (const node of nodes) {
            if (node.classList.contains('sidebar-heading')) {
                const headingText = node.textContent?.trim();
                if (headingText) currentCategory = headingText;
                continue;
            }

            const target = resolveLinkTarget(node.getAttribute('href'));
            if (!target.url || !isSameOriginUrl(target.url, origin)) continue;
            if (isTopLevelOnlyUrl(target.url, origin, pathPrefix)) continue;

            const path = normalizedPath(target.url, origin);
            if (seenPaths.has(path)) continue;
            seenPaths.add(path);

            const label = node.querySelector('.sidebar-link-label')?.textContent?.trim()
                || node.dataset.sidebarTooltip
                || node.getAttribute('aria-label')
                || node.textContent.trim();
            const key = target.key || resolveModuleKey(target.url, [], origin);
            const iconSvg = node.querySelector('svg');

            entries.push({
                key,
                label,
                category: currentCategory,
                href: canonicalizeUrl(target.url, origin),
                normalizedPath: path,
                iconSvg: iconSvg ? iconSvg.cloneNode(true) : null,
                isQuickAccessDefault: quickAccessPaths.has(path),
            });
        }

        return entries;
    };

    const catalog = [];
    const menuPathTitles = new Map();
    const indexPathSet = new Set();

    const syncNavigationCatalogMetadata = (nextConfig = config) => {
        const nextQuickAccessPaths = Array.isArray(nextConfig?.quickAccessPaths)
            ? nextConfig.quickAccessPaths
            : config.quickAccessPaths;
        if (Array.isArray(nextQuickAccessPaths)) {
            quickAccessPaths.clear();
            nextQuickAccessPaths.forEach((p) => quickAccessPaths.add(String(p).replace(/\/+$/, '') || '/'));
        }

        const nextCatalog = buildCatalog();
        catalog.splice(0, catalog.length, ...nextCatalog);

        menuPathTitles.clear();
        Object.entries(nextConfig?.menuPathTitles || {}).forEach(([pathKey, label]) => {
            menuPathTitles.set(normalizedPath(pathKey, origin), label);
        });
        for (const entry of catalog) {
            if (entry.normalizedPath && entry.label) {
                menuPathTitles.set(entry.normalizedPath, entry.label);
            }
        }

        const nextIndexPaths = Array.isArray(nextConfig?.indexPaths) ? nextConfig.indexPaths : indexPaths;
        indexPathSet.clear();
        nextIndexPaths.forEach((path) => indexPathSet.add(normalizedPath(path, origin)));
    };

    syncNavigationCatalogMetadata(config);

    const resolveCanonicalFrameTitle = (ctx, cleanUrl, frameDocTitle) => {
        const fallback = ctx?.frame?.title || 'ADASI Portal';
        const parsedTitle = cleanPageTitle(frameDocTitle, fallback);
        if (!ctx) return parsedTitle;

        if (ctx.frame.role === 'parent') {
            const np = normalizedPath(cleanUrl, origin);
            const pathTitle = menuPathTitles.get(np);
            if (pathTitle) return pathTitle;

            const catalogByKey = catalog.find((item) => item.key === ctx.group.moduleKey);
            if (catalogByKey?.label && indexPathSet.has(np)) {
                return catalogByKey.label;
            }
        }

        return parsedTitle;
    };

    const formatTopbarTitle = (frame, group) => {
        if (!frame) return '';
        if (frame.role === 'child' && group?.baseLabel && group.baseLabel !== frame.title) {
            return `${group.baseLabel} — ${frame.title}`;
        }
        return frame.title;
    };

    const scopedStorageSuffix = `${config.userId || 'guest'}:${config.portalScope || 'import'}`;
    const usageStorageKey = `adasi:workspace:usage:${scopedStorageSuffix}`;
    const favoritesStorageKey = `adasi:workspace:favorites:${scopedStorageSuffix}`;
    const bookmarksStorageKey = `adasi:workspace:bookmarks:${scopedStorageSuffix}`;
    const shortcutsLayoutStorageKey = `adasi:workspace:shortcuts-layout:${scopedStorageSuffix}`;
    const shortcutsMetaStorageKey = `adasi:workspace:shortcuts-meta:${scopedStorageSuffix}`;
    const pinnedStorageKey = `adasi:workspace:pinned:${scopedStorageSuffix}`;
    const recentlyClosedStorageKey = `adasi:workspace:recently-closed:${scopedStorageSuffix}`;
    const SHORTCUTS_KEYBOARD_STEP_PX = 24;
    const SHORTCUTS_SYNC_DEBOUNCE_MS = 1200;
    const shortcutsSyncUrl = typeof config.shortcutsSyncUrl === 'string' && config.shortcutsSyncUrl.trim() !== ''
        ? config.shortcutsSyncUrl.trim()
        : null;
    let shortcutsSyncTimer = 0;
    let shortcutsSyncDirty = false;
    let shortcutsLayout = normalizeShortcutsLayout(null);

    const hasStorageKey = (key) => {
        try {
            return window.localStorage?.getItem(key) !== null;
        } catch {
            return false;
        }
    };

    const readJsonStorage = (key, fallbackValue) => {
        try {
            const raw = window.localStorage?.getItem(key);
            if (!raw) return fallbackValue;
            const parsed = JSON.parse(raw);
            return parsed ?? fallbackValue;
        } catch {
            return fallbackValue;
        }
    };

    const writeJsonStorage = (key, value) => {
        try {
            window.localStorage?.setItem(key, JSON.stringify(value));
        } catch {
            // Storage blocked or quota exceeded is non-fatal.
        }
    };

    const canonicalShortcutPath = (rawPathOrUrl) => canonicalizeShortcutModulePath(rawPathOrUrl, { origin, pathPrefix });

    const readShortcutsUpdatedAt = () => {
        const meta = readJsonStorage(shortcutsMetaStorageKey, null);
        return Math.max(0, Math.trunc(Number(meta?.updatedAt) || 0));
    };

    const touchShortcutsUpdatedAt = (explicitTs = Date.now()) => {
        const ts = Math.max(0, Math.trunc(Number(explicitTs) || Date.now()));
        writeJsonStorage(shortcutsMetaStorageKey, { updatedAt: ts });
        return ts;
    };

    const readUsageMap = () => {
        const parsed = readJsonStorage(usageStorageKey, {});
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
        const normalized = {};
        for (const [rawPath, entry] of Object.entries(parsed)) {
            if (!entry || typeof entry !== 'object') continue;
            const cleanPath = canonicalShortcutPath(rawPath) || normalizedPath(rawPath, origin);
            if (!cleanPath) continue;
            const count = Math.trunc(Number(entry.count) || 0);
            const lastUsedAt = Math.max(0, Math.trunc(Number(entry.lastUsedAt) || 0));
            if (count < 1) continue;
            const prev = normalized[cleanPath];
            normalized[cleanPath] = prev
                ? { count: Math.max(prev.count, count), lastUsedAt: Math.max(prev.lastUsedAt, lastUsedAt) }
                : { count, lastUsedAt };
        }
        return normalized;
    };

    const buildShortcutsSyncPayload = () => ({
        scope: config.portalScope || 'import',
        updated_at: readShortcutsUpdatedAt() || Date.now(),
        usage: readUsageMap(),
        favorites: readFavoritesList(),
        favorites_initialized: hasStorageKey(favoritesStorageKey),
        bookmarks: readBookmarksList(),
        layout: shortcutsLayout,
    });

    const flushShortcutsSync = ({ keepalive = false } = {}) => {
        if (shortcutsSyncTimer) {
            window.clearTimeout(shortcutsSyncTimer);
            shortcutsSyncTimer = 0;
        }
        if (!shortcutsSyncDirty || !shortcutsSyncUrl || typeof window.fetch !== 'function') {
            return;
        }
        shortcutsSyncDirty = false;
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            if (csrf) headers['X-CSRF-TOKEN'] = csrf;

            void window.fetch(shortcutsSyncUrl, {
                method: 'PATCH',
                headers,
                credentials: 'same-origin',
                cache: 'no-store',
                priority: 'low',
                keepalive: Boolean(keepalive),
                body: JSON.stringify(buildShortcutsSyncPayload()),
            }).catch(() => {});
        } catch {
            // Network/CSP/unload restrictions are non-fatal; localStorage still retains state.
        }
    };

    const scheduleShortcutsSync = () => {
        touchShortcutsUpdatedAt();
        if (!shortcutsSyncUrl || typeof window.fetch !== 'function') return;
        shortcutsSyncDirty = true;
        if (shortcutsSyncTimer) {
            window.clearTimeout(shortcutsSyncTimer);
        }
        shortcutsSyncTimer = window.setTimeout(() => {
            shortcutsSyncTimer = 0;
            flushShortcutsSync({ keepalive: false });
        }, SHORTCUTS_SYNC_DEBOUNCE_MS);
    };

    const recordModuleUse = (rawUrl) => {
        const path = canonicalShortcutPath(rawUrl) || normalizedPath(rawUrl, origin);
        if (!path || path === '/') return;
        const current = readUsageMap();
        const prev = current[path] && typeof current[path] === 'object' ? current[path] : { count: 0, lastUsedAt: 0 };
        current[path] = {
            count: Number(prev.count || 0) + 1,
            lastUsedAt: Date.now(),
        };
        writeJsonStorage(usageStorageKey, current);
        scheduleShortcutsSync();
    };

    const removeUsageEntry = (rawUrl) => {
        const path = canonicalShortcutPath(rawUrl) || normalizedPath(rawUrl, origin);
        if (!path) return;
        const current = readUsageMap();
        if (Object.prototype.hasOwnProperty.call(current, path)) {
            delete current[path];
            writeJsonStorage(usageStorageKey, current);
            scheduleShortcutsSync();
        }
    };

    const readFavoritesList = () => {
        const hasKey = hasStorageKey(favoritesStorageKey);
        if (!hasKey) {
            const seeded = catalog
                .filter((item) => item.isQuickAccessDefault && item.normalizedPath)
                .map((item) => item.normalizedPath);
            const uniqueSeed = Array.from(new Set(seeded)).slice(0, 50);
            writeJsonStorage(favoritesStorageKey, uniqueSeed);
            return uniqueSeed;
        }
        const parsed = readJsonStorage(favoritesStorageKey, []);
        if (!Array.isArray(parsed)) return [];
        return Array.from(
            new Set(
                parsed
                    .map((p) => canonicalShortcutPath(String(p || '')) || String(p || '').trim())
                    .filter(Boolean),
            ),
        ).slice(0, 50);
    };

    const isFavoritePath = (path) => {
        const cleanPath = canonicalShortcutPath(String(path || '')) || String(path || '').trim();
        return cleanPath !== '' && readFavoritesList().includes(cleanPath);
    };

    const toggleFavoritePath = (path) => {
        const cleanPath = canonicalShortcutPath(String(path || '')) || String(path || '').trim();
        if (!cleanPath) return false;
        const current = readFavoritesList();
        const exists = current.includes(cleanPath);
        const next = exists
            ? current.filter((p) => p !== cleanPath)
            : [cleanPath, ...current].slice(0, 50);
        writeJsonStorage(favoritesStorageKey, next);
        scheduleShortcutsSync();
        renderShortcuts();
        return !exists;
    };

    const canonicalizeBookmarkUrl = (rawUrl) => {
        const clean = canonicalizeUrl(stripEmbedParam(String(rawUrl || ''), origin), origin);
        if (!clean || !isSameOriginUrl(clean, origin) || isTopLevelOnlyUrl(clean, origin, pathPrefix)) {
            return '';
        }
        try {
            const parsed = new URL(clean, origin);
            const canonicalPath = canonicalShortcutPath(parsed.pathname);
            if (canonicalPath) parsed.pathname = canonicalPath;
            return parsed.toString();
        } catch {
            return clean;
        }
    };

    const readBookmarksList = () => {
        const parsed = readJsonStorage(bookmarksStorageKey, []);
        if (!Array.isArray(parsed)) return [];
        const seen = new Set();
        const valid = [];
        for (const item of parsed) {
            if (!item || typeof item !== 'object') continue;
            const clean = canonicalizeBookmarkUrl(item.url);
            if (!clean || seen.has(clean)) continue;
            seen.add(clean);
            valid.push({
                url: clean,
                normalizedPath: canonicalShortcutPath(clean) || normalizedPath(clean, origin),
                label: String(item.label || clean).trim() || clean,
                moduleKey: String(item.moduleKey || '').trim() || null,
                createdAt: Number(item.createdAt || 0),
            });
            if (valid.length >= 50) break;
        }
        return valid;
    };

    const isBookmarkedUrl = (rawUrl) => {
        const clean = canonicalizeBookmarkUrl(rawUrl);
        return clean !== '' && readBookmarksList().some((item) => item.url === clean);
    };

    const toggleBookmarkEntry = ({ url, label = '', moduleKey = null }) => {
        const clean = canonicalizeBookmarkUrl(url);
        if (!clean) return false;
        const current = readBookmarksList();
        const exists = current.some((item) => item.url === clean);
        const next = exists
            ? current.filter((item) => item.url !== clean)
            : [
                {
                    url: clean,
                    normalizedPath: canonicalShortcutPath(clean) || normalizedPath(clean, origin),
                    label: String(label || '').trim() || clean,
                    moduleKey: moduleKey || resolveModuleForUrl(clean) || null,
                    createdAt: Date.now(),
                },
                ...current,
            ].slice(0, 50);
        writeJsonStorage(bookmarksStorageKey, next);
        scheduleShortcutsSync();
        renderShortcuts();
        return !exists;
    };

    const removeBookmarkUrl = (rawUrl) => {
        const clean = canonicalizeBookmarkUrl(rawUrl);
        if (!clean) return;
        const next = readBookmarksList().filter((item) => item.url !== clean);
        writeJsonStorage(bookmarksStorageKey, next);
        scheduleShortcutsSync();
        renderShortcuts();
    };

    const readShortcutsLayout = () => normalizeShortcutsLayout(readJsonStorage(shortcutsLayoutStorageKey, null));

    // Hydrate and merge localStorage with server-persisted user_preferences shortcutsState on boot
    try {
        const hasUsageKey = hasStorageKey(usageStorageKey);
        const hasFavoritesKey = hasStorageKey(favoritesStorageKey);
        const hasBookmarksKey = hasStorageKey(bookmarksStorageKey);
        const hasLayoutKey = hasStorageKey(shortcutsLayoutStorageKey);

        const mergedShortcuts = mergeWorkspaceShortcutsState({
            localState: {
                updatedAt: readShortcutsUpdatedAt(),
                hasUsageKey,
                hasFavoritesKey,
                hasBookmarksKey,
                hasLayoutKey,
                usage: hasUsageKey ? readJsonStorage(usageStorageKey, {}) : {},
                favorites: hasFavoritesKey ? readJsonStorage(favoritesStorageKey, []) : [],
                bookmarks: hasBookmarksKey ? readJsonStorage(bookmarksStorageKey, []) : [],
                layout: hasLayoutKey ? readJsonStorage(shortcutsLayoutStorageKey, null) : null,
            },
            serverState: config.shortcutsState && typeof config.shortcutsState === 'object' ? config.shortcutsState : {},
            origin,
            pathPrefix,
        });

        if (Object.keys(mergedShortcuts.usage).length > 0 || hasUsageKey) {
            writeJsonStorage(usageStorageKey, mergedShortcuts.usage);
        }
        if (mergedShortcuts.favoritesInitialized) {
            writeJsonStorage(favoritesStorageKey, mergedShortcuts.favorites);
        }
        if (mergedShortcuts.bookmarks.length > 0 || hasBookmarksKey) {
            writeJsonStorage(bookmarksStorageKey, mergedShortcuts.bookmarks);
        }
        if (mergedShortcuts.hasLayout) {
            writeJsonStorage(shortcutsLayoutStorageKey, mergedShortcuts.layout);
        }
        if (mergedShortcuts.updatedAt > 0) {
            touchShortcutsUpdatedAt(mergedShortcuts.updatedAt);
        }
        if (mergedShortcuts.needsServerSync) {
            scheduleShortcutsSync();
        }
    } catch {}

    shortcutsLayout = readShortcutsLayout();

    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && event.target?.matches?.('[data-logout-form]')) {
            flushShortcutsSync({ keepalive: true });
        }
    }, true);

    window.addEventListener('pagehide', () => {
        flushShortcutsSync({ keepalive: true });
    });

    const getBoardWidthPx = () => {
        const width = shortcutsBoardEl?.getBoundingClientRect?.().width || 0;
        return width > 0 ? width : 0;
    };

    const applyShortcutsLayout = (layout = shortcutsLayout, { persistIfClamped = false } = {}) => {
        shortcutsLayout = normalizeShortcutsLayout(layout);
        const boardWidthPx = getBoardWidthPx();
        if (boardWidthPx > 0) {
            const clamped = clampOpenPaneWeights({
                weights: shortcutsLayout.weights,
                minimized: shortcutsLayout.minimized,
                boardWidthPx,
            });
            const shifted = SHORTCUTS_PANE_KEYS.some(
                (k) => Math.abs((clamped[k] || 0) - (shortcutsLayout.weights[k] || 0)) > 0.05,
            );
            shortcutsLayout.weights = clamped;
            if (shifted && persistIfClamped) {
                writeJsonStorage(shortcutsLayoutStorageKey, shortcutsLayout);
                scheduleShortcutsSync();
            }
        }

        const fallbackPaneTitles = {
            favorites: i18n.favorites || 'Favorites',
            bookmarks: i18n.bookmarks || 'Bookmarks',
            frequent: i18n.frequentlyUsed || 'Frequently Used',
        };
        const resolvedTitles = {};
        const snapshot = computeOpenPaneWidthPx(
            shortcutsLayout.weights,
            shortcutsLayout.minimized,
            boardWidthPx > 0 ? boardWidthPx : 1000,
        );
        const minimizedOrder = Array.isArray(shortcutsLayout.minimizedOrder)
            ? shortcutsLayout.minimizedOrder.filter((paneKey) => Boolean(shortcutsLayout.minimized[paneKey]))
            : SHORTCUTS_PANE_KEYS.filter((paneKey) => Boolean(shortcutsLayout.minimized[paneKey]));

        if (shortcutsDockEl) {
            shortcutsDockEl.hidden = minimizedOrder.length === 0;
            shortcutsDockEl.setAttribute('aria-label', i18n.minimizedPanels || 'Minimized shortcuts panels');
        }

        for (const paneKey of SHORTCUTS_PANE_KEYS) {
            const isMin = Boolean(shortcutsLayout.minimized[paneKey]);
            const widthPx = snapshot.widthsPx[paneKey] || 0;
            const isSliverCollapsed = !isMin && widthPx <= 0.6;
            const isSliver = !isMin && !isSliverCollapsed && widthPx < 24;
            const paneEl = shortcutsPaneEls[paneKey];
            const toggleBtn = shortcutsPaneToggleEls[paneKey];
            const restoreBtn = shortcutsRestoreEls[paneKey];
            const titleText = paneEl?.querySelector('.workspace-shortcuts-pane__title')?.textContent?.trim()
                || fallbackPaneTitles[paneKey];
            resolvedTitles[paneKey] = titleText;

            if (paneEl) {
                paneEl.classList.toggle('is-minimized', isMin);
                paneEl.classList.toggle('is-sliver-collapsed', isSliverCollapsed);
                paneEl.classList.toggle('is-sliver', isSliver);
                paneEl.style.setProperty('--shortcuts-pane-weight', String(shortcutsLayout.weights[paneKey]));
            }
            if (toggleBtn) {
                const template = isMin
                    ? (i18n.expandPane || 'Expand :pane')
                    : (i18n.minimizePane || 'Minimize :pane');
                const actionLabel = String(template).replace(':pane', titleText);
                toggleBtn.setAttribute('aria-expanded', isMin ? 'false' : 'true');
                toggleBtn.setAttribute('aria-label', actionLabel);
                toggleBtn.title = actionLabel;
            }
            if (restoreBtn) {
                const orderIdx = minimizedOrder.indexOf(paneKey);
                restoreBtn.hidden = !isMin;
                restoreBtn.style.order = String(orderIdx >= 0 ? orderIdx : 99);
                const restoreLabelEl = restoreBtn.querySelector('.workspace-shortcuts-dock__label');
                if (restoreLabelEl) {
                    restoreLabelEl.textContent = titleText;
                }
                const expandLabel = String(i18n.expandPane || 'Expand :pane').replace(':pane', titleText);
                restoreBtn.setAttribute('aria-label', expandLabel);
                restoreBtn.title = expandLabel;
            }
        }

        const visibleSplitters = resolveVisibleShortcutsSplitters(shortcutsLayout.minimized);

        for (const splitterKey of shortcutsSplitterKeys) {
            const splitterEl = shortcutsSplitterEls[splitterKey];
            if (!splitterEl) continue;
            const isVisible = visibleSplitters.includes(splitterKey);
            const pair = isVisible ? resolveShortcutsSplitterPair(splitterKey, shortcutsLayout.minimized) : null;
            const disabled = !pair;
            splitterEl.hidden = !isVisible;
            splitterEl.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            splitterEl.tabIndex = disabled ? -1 : 0;

            if (pair) {
                const [leftKey, rightKey] = pair;
                const leftW = snapshot.widthsPx[leftKey] || 0;
                const rightW = snapshot.widthsPx[rightKey] || 0;
                const pairTotal = leftW + rightW;
                const minPx = Math.min(MIN_SHORTCUTS_PANE_WIDTH_PX, pairTotal / 2);
                const minPct = pairTotal > 0 ? Math.max(0, Math.round((minPx / pairTotal) * 100)) : 0;
                const maxPct = Math.max(minPct, 100 - minPct);
                const nowPct = pairTotal > 0 ? Math.round((leftW / pairTotal) * 100) : 50;

                splitterEl.setAttribute('aria-valuemin', String(minPct));
                splitterEl.setAttribute('aria-valuemax', String(maxPct));
                splitterEl.setAttribute('aria-valuenow', String(nowPct));
                splitterEl.setAttribute(
                    'aria-controls',
                    `workspace-shortcuts-pane-${leftKey} workspace-shortcuts-pane-${rightKey}`,
                );
                const labelTemplate = i18n.resizePanePair || 'Resize :left and :right panels (Double-click to reset)';
                const pairLabel = String(labelTemplate)
                    .replace(':left', resolvedTitles[leftKey])
                    .replace(':right', resolvedTitles[rightKey]);
                splitterEl.setAttribute('aria-label', pairLabel);
                splitterEl.title = pairLabel;
            } else {
                const fallbackLabel = i18n.resizeShortcutsPanes || 'Resize shortcuts panels (Double-click to reset)';
                splitterEl.setAttribute('aria-label', fallbackLabel);
                splitterEl.title = fallbackLabel;
            }
        }
    };

    const store = new WorkspaceSessionStore({
        maxSessions: config.maxSessions ?? config.maxTabs,
        maxLiveFrames: config.maxLiveFrames,
        maxPinnedSessions: config.maxPinnedSessions ?? DEFAULT_MAX_PINNED_SESSIONS,
        staleAfterMs: config.staleAfterMs,
        catalog,
        indexPaths,
    });

    const frames = new Map();
    const initialTab = config.initialSession || config.initialTab;
    const slowLoaderThresholdMs = Number.isFinite(Number(config.slowLoaderThresholdMs)) && Number(config.slowLoaderThresholdMs) >= 0
        ? Number(config.slowLoaderThresholdMs)
        : 2500;
    if (Number.isFinite(Number(config.pageFadeMs)) && Number(config.pageFadeMs) >= 0) {
        document.documentElement?.style?.setProperty?.('--ui-page-fade-duration', `${Number(config.pageFadeMs)}ms`);
    }
    let initialFrameId = null;
    let initialReadyResolve = null;
    let initialReadyDone = false;
    let lastMenuOpener = null;
    let tabMenuCloseTimer = 0;
    let activeLoaderToken = null;
    const trackedTriggerEls = new Set();
    const attachedReadinessPromises = new WeakSet();
    const leavingPanelCleanups = new WeakMap();

    const attachedShortcutDocs = new WeakSet();
    const handleGlobalWorkspaceShortcut = (event) => {
        if (!event.altKey || !event.shiftKey || event.ctrlKey || event.metaKey) return;
        if (event.code !== 'KeyT' && String(event.key || '').toLowerCase() !== 't') return;
        if (readRecentlyClosedList().length === 0) return;
        event.preventDefault();
        reopenRecentlyClosed();
    };
    const attachGlobalShortcutListener = (targetDoc) => {
        if (!targetDoc || attachedShortcutDocs.has(targetDoc)) return;
        attachedShortcutDocs.add(targetDoc);
        try {
            targetDoc.addEventListener('keydown', handleGlobalWorkspaceShortcut);
        } catch {}
    };
    attachGlobalShortcutListener(document);

    const announceLiveStatus = (text) => {
        if (!liveRegionEl || !text) return;
        liveRegionEl.textContent = '';
        window.requestAnimationFrame?.(() => {
            if (liveRegionEl) liveRegionEl.textContent = text;
        });
    };

    const fillI18n = (template, values) => Object.entries(values).reduce(
        (text, [key, value]) => text.split(`:${key}`).join(String(value)),
        String(template || ''),
    );

    const storedSessionOptions = { origin, pathPrefix };

    const readPinnedList = () => sanitizePersistedPinnedList(readJsonStorage(pinnedStorageKey, []), {
        ...storedSessionOptions,
        maxPinned: store.maxPinnedSessions,
    });

    const persistPinnedGroups = () => {
        const entries = store.groups()
            .filter((group) => group.pinned)
            .map((group) => {
                const parent = group.frames.find((frame) => frame.role === 'parent') || group.frames[0];
                return {
                    url: parent?.url || '',
                    label: group.baseLabel || parent?.title || '',
                    moduleKey: group.moduleKey,
                    icon: group.icon || '',
                };
            });
        writeJsonStorage(pinnedStorageKey, sanitizePersistedPinnedList(entries, {
            ...storedSessionOptions,
            maxPinned: store.maxPinnedSessions,
        }));
    };

    const readRecentlyClosedFromStorage = () => sanitizeRecentlyClosedList(readJsonStorage(recentlyClosedStorageKey, []), {
        ...storedSessionOptions,
        maxEntries: DEFAULT_MAX_RECENTLY_CLOSED,
    });

    let recentlyClosedCache = readRecentlyClosedFromStorage();
    const readRecentlyClosedList = () => recentlyClosedCache.slice();

    const writeRecentlyClosedList = (list) => {
        recentlyClosedCache = sanitizeRecentlyClosedList(list, {
            ...storedSessionOptions,
            maxEntries: DEFAULT_MAX_RECENTLY_CLOSED,
        });
        writeJsonStorage(recentlyClosedStorageKey, recentlyClosedCache);
    };

    window.addEventListener('storage', (event) => {
        if (event.key === recentlyClosedStorageKey) {
            recentlyClosedCache = readRecentlyClosedFromStorage();
            syncTabDom();
        }
    });

    const recordRecentlyClosed = (snapshots = []) => {
        const incoming = (Array.isArray(snapshots) ? snapshots : [])
            .filter((snapshot) => snapshot && snapshot.role !== 'child' && snapshot.url);
        if (incoming.length === 0) return;
        writeRecentlyClosedList([...incoming, ...recentlyClosedCache]);
    };

    const clearRecentlyClosedList = () => {
        writeRecentlyClosedList([]);
        syncTabDom();
    };

    const reopenRecentlyClosed = (targetUrl = null) => {
        const list = readRecentlyClosedList();
        const idx = targetUrl ? list.findIndex((entry) => entry.url === targetUrl) : 0;
        const entry = idx >= 0 ? list[idx] : null;
        if (!entry) return false;

        const opened = openNewWorkspace({ moduleKey: entry.moduleKey, label: entry.label, url: entry.url });
        if (!opened) return false;

        list.splice(idx, 1);
        writeRecentlyClosedList(list);
        announceLiveStatus(fillI18n(i18n.reopenedAnnouncement || 'Reopened :label', { label: entry.label }));
        syncTabDom();
        return true;
    };

    const groupDisplayInfo = (groupId) => store.displayGroups().find((group) => group.groupId === groupId) || null;

    const focusGroupTrigger = (groupId) => {
        const groupEl = tablistEl.querySelector(`[data-workspace-group="${CSS.escape(groupId)}"]`);
        const trigger = groupEl?.querySelector('[role="tab"][aria-selected="true"]') || groupEl?.querySelector('[role="tab"]');
        trigger?.focus?.();
        trigger?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
    };

    const requestTogglePinGroup = (groupId, { refocus = false } = {}) => {
        const label = groupDisplayInfo(groupId)?.displayLabel || store.getGroup(groupId)?.baseLabel || '';
        const res = store.togglePinGroup(groupId);
        if (res.blocked) {
            const message = fillI18n(
                i18n.maxPinnedReached || 'Maximum of :max pinned sessions reached. Unpin a session first.',
                { max: store.maxPinnedSessions },
            );
            window.AdasiToast?.warning?.(message);
            announceLiveStatus(message);
            return;
        }
        if (!res.changed) return;

        persistPinnedGroups();
        syncTabDom();
        if (refocus) focusGroupTrigger(groupId);
        announceLiveStatus(fillI18n(
            res.pinned ? (i18n.pinnedAnnouncement || 'Pinned :label') : (i18n.unpinnedAnnouncement || 'Unpinned :label'),
            { label },
        ));
    };

    const announceGroupPosition = (groupId) => {
        const info = groupDisplayInfo(groupId);
        if (!info) return;
        announceLiveStatus(fillI18n(i18n.movedAnnouncement || 'Moved :label to position :position of :total', {
            label: info.displayLabel,
            position: info.zoneIndex + 1,
            total: info.zoneTotal,
        }));
    };

    const requestMoveGroupByOffset = (groupId, delta, { refocus = true } = {}) => {
        const res = store.moveGroupByOffset(groupId, delta);
        if (!res.moved) return false;

        if (res.pinned) persistPinnedGroups();
        syncTabDom();
        if (refocus) focusGroupTrigger(groupId);
        announceGroupPosition(groupId);
        return true;
    };

    const syncTriggerPendingAttributes = () => {
        for (const triggerEl of Array.from(trackedTriggerEls)) {
            const isPending = navQueue.isTriggerPending(triggerEl);
            try {
                if (isPending) {
                    triggerEl.setAttribute('data-workspace-pending', 'true');
                    triggerEl.setAttribute('aria-busy', 'true');
                } else {
                    triggerEl.removeAttribute('data-workspace-pending');
                    if (triggerEl.getAttribute('aria-busy') === 'true') {
                        triggerEl.removeAttribute('aria-busy');
                    }
                    trackedTriggerEls.delete(triggerEl);
                }
            } catch {
                trackedTriggerEls.delete(triggerEl);
            }
        }
    };

    const unmountFramePanel = (frameId) => {
        if (!frameId) return;
        pendingLocaleSyncFrames.delete(frameId);
        syncedLocaleFrames.delete(frameId);
        const iframe = frames.get(frameId);
        if (iframe) {
            delete iframe.dataset.workspaceNavigated;
            try { iframe.src = 'about:blank'; } catch {}
            frames.delete(frameId);
        }
        const panel = framesEl.querySelector(`[data-workspace-panel="${CSS.escape(frameId)}"]`);
        if (panel) {
            leavingPanelCleanups.get(panel)?.();
            panel.remove();
        }
    };

    const probingFrames = new Set();
    const recentWorkspaceLaunches = new Map();

    const cleanupAbortedFrame = (frameId) => {
        if (!frameId) return;
        probingFrames.delete(frameId);
        const discarded = store.discardStagedFrame(frameId);
        if (!discarded.discarded) {
            const ctx = store.getFrameContext(frameId);
            if (ctx && ctx.frame.loading) {
                store.closeFrame(frameId);
            }
        }
        unmountFramePanel(frameId);
        syncTriggerPendingAttributes();
        syncTabDom();
    };

    const retryFailedNavigation = (entry) => {
        if (!entry) return;
        if (entry.kind === 'activate' && entry.frameId && store.getFrame(entry.frameId)) {
            activateFrame(entry.frameId, { historyMode: entry.historyMode || 'push' });
            return;
        }
        if (entry.kind === 'child' && entry.groupId && store.getGroup(entry.groupId) && entry.url) {
            const group = store.getGroup(entry.groupId);
            const sourceId = group.activeFrameId || group.frames[0]?.frameId || null;
            openChildInGroup(entry.groupId, sourceId, entry.url, entry.label || '');
            return;
        }
        if (entry.url) {
            openNewWorkspace({
                label: entry.label || '',
                url: entry.url,
                historyMode: entry.historyMode || 'push',
            });
        }
    };

    const reportNavigationFailure = (entry, reason = 'timeout') => {
        const message = reason === 'network'
            ? (i18n.loadingNetworkError || i18n.loadingModuleError || 'Unable to reach the server. Check your connection and try again.')
            : (reason === 'timeout' || reason === 'queue-timeout')
                ? (i18n.loadingTimeoutError || i18n.loadingModuleError || 'The page took too long to respond. Please try again.')
                : (i18n.loadingModuleError || i18n.loadingFailed || 'Unable to load the selected page. Please try again.');
        const nowMs = typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();
        const elapsedMs = Number.isFinite(entry?.startedAt) ? Math.round(nowMs - entry.startedAt) : null;
        try {
            window.AdasiDebug?.warn('[AdasiWorkspaceShell] navigation failed', {
                reason,
                path: entry?.url ? normalizedPath(entry.url, origin) : '',
                elapsedMs,
            });
        } catch {}
        announceLiveStatus(message);
        const retryLabel = i18n.retryLoad || 'Try again';
        const canRetry = Boolean(entry && (entry.url || (entry.kind === 'activate' && entry.frameId)));
        window.AdasiToast?.error?.(
            message,
            canRetry
                ? { actions: [{ label: retryLabel, variant: 'primary', onClick: () => retryFailedNavigation(entry) }] }
                : undefined,
        );
    };

    const configuredSlotTimeoutMs = Number.isFinite(Number(config.slotTimeoutMs)) && Number(config.slotTimeoutMs) >= 5000
        ? Number(config.slotTimeoutMs)
        : 30000;
    const configuredDataReadyTimeoutMs = Number.isFinite(Number(config.dataReadyTimeoutMs)) && Number(config.dataReadyTimeoutMs) >= 3000
        ? Number(config.dataReadyTimeoutMs)
        : 12000;
    const configuredQueueWaitTimeoutMs = Number.isFinite(Number(config.queueWaitTimeoutMs)) && Number(config.queueWaitTimeoutMs) >= 10000
        ? Number(config.queueWaitTimeoutMs)
        : 60000;

    const navQueue = new WorkspaceNavQueue({
        maxConcurrent: 2,
        slowThresholdMs: slowLoaderThresholdMs,
        stepIntervalMs: 220,
        slotTimeoutMs: configuredSlotTimeoutMs,
        dataReadyTimeoutMs: configuredDataReadyTimeoutMs,
        queueWaitTimeoutMs: configuredQueueWaitTimeoutMs,
        isDocumentHidden: () => document.hidden === true,
        onStartSlot: (entry) => {
            const frame = store.getFrame(entry.frameId);
            if (!frame) return;
            frame.queued = false;
            frame.loading = true;
            if (frames.has(frame.frameId)) {
                unmountFramePanel(frame.frameId);
            }
            ensureFrameMounted(frame);
            syncTriggerPendingAttributes();
            syncTabDom();
        },
        onCommitReady: (entry, outcome) => {
            const committed = store.commitStagedFrame(entry.frameId, { activate: outcome.activate });
            syncTriggerPendingAttributes();
            if (!committed.ok) return;
            const ctx = store.getFrameContext(entry.frameId);
            const label = ctx?.frame?.title || entry.label || '';
            if (outcome.activate) {
                notifyActiveSurface(entry.historyMode || 'push');
            } else {
                syncTabDom();
            }
            const readyMsg = String(i18n.readyAnnouncement || ':label is ready').replace(':label', label);
            announceLiveStatus(readyMsg);
        },
        onSlowThreshold: () => {
            if (activeLoaderToken === null) {
                activeLoaderToken = window.AdasiLoader?.showImmediate?.() ?? 'shown';
                syncStageControls();
            }
        },
        onHideLoader: () => {
            if (activeLoaderToken !== null) {
                window.AdasiLoader?.hide?.(activeLoaderToken, true);
                activeLoaderToken = null;
                syncStageControls();
            }
        },
        onSlotTimeout: (entry, reason) => {
            cleanupAbortedFrame(entry.frameId);
            reportNavigationFailure(entry, reason || 'timeout');
        },
        onDataTimeout: (entry) => {
            const iframe = frames.get(entry.frameId);
            let frameWin = null;
            try {
                frameWin = iframe?.contentWindow || null;
            } catch {}
            finalizeFrameReady(entry.frameId, frameWin);
        },
        onQueueChange: () => {
            syncTriggerPendingAttributes();
            syncStageControls();
        },
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden !== true) {
            navQueue.notifyVisibilityRestored();
        }
    });

    const initialReadyPromise = new Promise((resolve) => {
        initialReadyResolve = () => {
            if (initialReadyDone) return;
            initialReadyDone = true;
            resolve();
            syncStageControls();
        };
    });

    // Keep safety ceiling generous enough for slow initial DataTables draws while letting emergency watchdog recover hung pages.
    window.setTimeout(() => initialReadyResolve?.(), 60000);

    const resolveModuleForUrl = (url, preferredKey = null) => {
        const resolved = resolveLinkTarget(url);
        if (resolved.key) return resolved.key;
        const matched = resolveModuleKey(resolved.url || url, catalog, origin);
        if (preferredKey && (matched === 'drp-supplier' || matched === 'drp-ga') && (preferredKey === 'drp-supplier' || preferredKey === 'drp-ga')) {
            return preferredKey;
        }
        return matched;
    };

    const syncTopbarTitle = (label) => {
        const liveTitleEl = topbarTitleEl?.isConnected ? topbarTitleEl : document.querySelector('.top-navbar .topbar-page-title');
        if (liveTitleEl && label) liveTitleEl.textContent = label;
        document.title = 'ADASI Supplier Portal';
    };

    const syncSidebarHighlight = (activeUrl) => {
        const links = Array.from(document.querySelectorAll('#sidebar a.sidebar-link'));
        if (!activeUrl) {
            links.forEach((link) => {
                link.classList.remove('active');
                link.removeAttribute('aria-current');
            });
            return;
        }
        const resolvedActive = resolveLinkTarget(activeUrl);
        const activeInternalUrl = resolvedActive.url || stripEmbedParam(activeUrl, origin);
        const matchedKey = resolvedActive.key || resolveModuleForUrl(activeInternalUrl);
        const resolvedLinks = links.map((link) => {
            const target = resolveLinkTarget(link.getAttribute('href'));
            return {
                url: target.url,
                key: target.key || resolveModuleKey(target.url, catalog, origin),
            };
        });
        const activeFlags = resolveActiveSidebarLinks(resolvedLinks, {
            activeUrl: activeInternalUrl,
            activeKey: matchedKey,
            catalog,
            origin,
        });
        links.forEach((link, index) => {
            const active = Boolean(activeFlags[index]);
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    };

    const syncStageControls = () => {
        const active = store.activeFrame();
        const isShortcuts = store.isShortcutsActive();
        const hasPendingNav = navQueue.size() > 0 || navQueue.pendingCommitsCount() > 0;
        const loading = Boolean(activeLoaderToken)
            || hasPendingNav
            || !initialReadyDone
            || Boolean(active?.loading);
        stageEl.classList.toggle('is-loading', loading);
        if (progressEl) progressEl.setAttribute('aria-hidden', loading ? 'false' : 'true');
        if (refreshBtn) {
            refreshBtn.classList.toggle('is-spinning', Boolean(active?.loading));
            refreshBtn.disabled = isShortcuts;
        }
        if (staleBtn) {
            const showStaleHint = Boolean(active && active.stale && active.isDirty);
            staleBtn.hidden = !showStaleHint;
        }
    };

    const formatCountText = (template, count) => String(template || ':count').replace(':count', String(count));

    const openCountsByPath = () => {
        const counts = new Map();
        for (const group of store.groups()) {
            const parentUrl = group.frames[0]?.url;
            if (!parentUrl) continue;
            const path = normalizedPath(parentUrl, origin);
            counts.set(path, (counts.get(path) || 0) + 1);
        }
        return counts;
    };

    const openCountsByUrl = () => {
        const counts = new Map();
        for (const group of store.groups()) {
            for (const f of group.frames) {
                const clean = canonicalizeBookmarkUrl(f.url);
                if (!clean) continue;
                counts.set(clean, (counts.get(clean) || 0) + 1);
            }
        }
        return counts;
    };

    const findCatalogEntryForGroup = (group) => {
        if (!group) return null;
        const parentPath = normalizedPath(group.frames[0]?.url || '', origin);
        return catalog.find((item) => item.key === group.moduleKey)
            || catalog.find((item) => item.normalizedPath === parentPath)
            || null;
    };

    const findCatalogEntryForBookmark = (bookmark) => {
        if (!bookmark) return null;
        if (bookmark.moduleKey) {
            const byKey = catalog.find((item) => item.key === bookmark.moduleKey);
            if (byKey) return byKey;
        }
        let best = null;
        let bestLen = -1;
        for (const item of catalog) {
            if (!item.normalizedPath) continue;
            if (bookmark.normalizedPath === item.normalizedPath || bookmark.normalizedPath.startsWith(`${item.normalizedPath}/`)) {
                if (item.normalizedPath.length > bestLen) {
                    bestLen = item.normalizedPath.length;
                    best = item;
                }
            }
        }
        return best;
    };

    const createShortcutRow = (item, openCount, { showCategory = false, fallbackIcon = 'compass', actions = [] } = {}) => {
        const li = document.createElement('li');
        li.className = 'workspace-shortcuts__row';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'workspace-shortcuts__item ui-focus-ring';
        btn.dataset.shortcutHref = item.href;
        if (item.key) btn.dataset.shortcutModule = item.key;

        const main = document.createElement('span');
        main.className = 'workspace-shortcuts__item-main';

        const iconWrap = document.createElement('span');
        iconWrap.className = 'workspace-shortcuts__item-icon';
        iconWrap.setAttribute('aria-hidden', 'true');
        const iconEl = item.iconSvg ? item.iconSvg.cloneNode(true) : cloneTemplateIcon(fallbackIcon);
        if (iconEl) iconWrap.appendChild(iconEl);

        const textWrap = document.createElement('span');
        textWrap.className = 'workspace-shortcuts__item-text';

        const labelEl = document.createElement('span');
        labelEl.className = 'workspace-shortcuts__item-label';
        labelEl.textContent = item.label;
        textWrap.appendChild(labelEl);

        if (showCategory && item.category) {
            const metaEl = document.createElement('span');
            metaEl.className = 'workspace-shortcuts__item-meta';
            metaEl.textContent = item.category;
            textWrap.appendChild(metaEl);
        }

        main.append(iconWrap, textWrap);
        btn.appendChild(main);

        if (openCount > 0) {
            const badge = document.createElement('span');
            badge.className = 'workspace-shortcuts__open-badge';
            badge.textContent = formatCountText(i18n.openInstancesCount || ':count open', openCount);
            btn.appendChild(badge);
        }

        btn.addEventListener('click', () => {
            openNewWorkspace({
                moduleKey: item.key || null,
                label: item.label,
                url: item.href,
                triggerEl: btn,
            });
        });

        li.appendChild(btn);

        if (actions.length > 0) {
            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'workspace-shortcuts__item-actions';

            for (const action of actions) {
                const actBtn = document.createElement('button');
                actBtn.type = 'button';
                actBtn.className = 'workspace-shortcuts__action ui-focus-ring';
                if (action.active) actBtn.classList.add('is-active');
                if (typeof action.pressed === 'boolean') {
                    actBtn.setAttribute('aria-pressed', action.pressed ? 'true' : 'false');
                }
                actBtn.setAttribute('aria-label', action.label);
                actBtn.title = action.label;
                const actIcon = cloneTemplateIcon(action.iconName);
                if (actIcon) actBtn.appendChild(actIcon);
                else actBtn.textContent = action.iconName === 'x' ? '×' : '★';
                actBtn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    action.onClick();
                });
                actionsWrap.appendChild(actBtn);
            }

            li.appendChild(actionsWrap);
        }

        return li;
    };

    const renderShortcuts = () => {
        if (!shortcutsPanel) return;

        const catalogByPath = new Map(catalog.map((item) => [item.normalizedPath, item]));
        const openCounts = openCountsByPath();
        const openUrlCounts = openCountsByUrl();

        // 1. Favorites (Module / Menu level)
        const favoritePaths = readFavoritesList();
        const favoriteSet = new Set(favoritePaths);
        const favoriteItems = favoritePaths
            .map((path) => catalogByPath.get(path))
            .filter(Boolean);

        if (shortcutsFavoritesEl) {
            shortcutsFavoritesEl.replaceChildren();
            for (const item of favoriteItems) {
                shortcutsFavoritesEl.appendChild(createShortcutRow(item, openCounts.get(item.normalizedPath) || 0, {
                    showCategory: true,
                    actions: [
                        {
                            label: `${i18n.removeFavorite || 'Remove from Favorites'}: ${item.label}`,
                            iconName: 'x',
                            onClick: () => toggleFavoritePath(item.normalizedPath),
                        },
                    ],
                }));
            }
        }

        // 2. Bookmarks (Contextual / Document / Filtered URL level)
        const bookmarkEntries = readBookmarksList();
        const visibleBookmarks = [];
        for (const bm of bookmarkEntries) {
            const matchedCatalog = findCatalogEntryForBookmark(bm);
            if (!matchedCatalog && catalog.length > 0) continue;
            visibleBookmarks.push({
                key: bm.moduleKey || matchedCatalog?.key || null,
                label: bm.label,
                category: matchedCatalog?.label || matchedCatalog?.category || '',
                href: bm.url,
                iconSvg: matchedCatalog?.iconSvg || null,
            });
        }

        if (shortcutsBookmarksEl) {
            shortcutsBookmarksEl.replaceChildren();
            for (const bmItem of visibleBookmarks) {
                shortcutsBookmarksEl.appendChild(createShortcutRow(bmItem, openUrlCounts.get(bmItem.href) || 0, {
                    showCategory: true,
                    fallbackIcon: 'bookmark',
                    actions: [
                        {
                            label: `${i18n.removeBookmark || 'Remove bookmark'}: ${bmItem.label}`,
                            iconName: 'x',
                            onClick: () => removeBookmarkUrl(bmItem.href),
                        },
                    ],
                }));
            }
        }

        // 3. Frequently Used (Usage frequency + recency)
        const usage = readUsageMap();
        const frequentItems = catalog
            .filter((item) => Number(usage[item.normalizedPath]?.count || 0) > 0)
            .toSorted((a, b) => {
                const countA = Number(usage[a.normalizedPath]?.count || 0);
                const countB = Number(usage[b.normalizedPath]?.count || 0);
                if (countA !== countB) return countB - countA;
                const timeA = Number(usage[a.normalizedPath]?.lastUsedAt || 0);
                const timeB = Number(usage[b.normalizedPath]?.lastUsedAt || 0);
                if (timeA !== timeB) return timeB - timeA;
                return a.label.localeCompare(b.label);
            })
            .slice(0, 15);

        if (shortcutsFrequentEl) {
            shortcutsFrequentEl.replaceChildren();
            for (const item of frequentItems) {
                const isFav = favoriteSet.has(item.normalizedPath);
                shortcutsFrequentEl.appendChild(createShortcutRow(item, openCounts.get(item.normalizedPath) || 0, {
                    showCategory: true,
                    actions: [
                        {
                            label: `${isFav ? (i18n.removeFavorite || 'Remove from Favorites') : (i18n.addFavorite || 'Add to Favorites')}: ${item.label}`,
                            iconName: 'star',
                            active: isFav,
                            pressed: isFav,
                            onClick: () => toggleFavoritePath(item.normalizedPath),
                        },
                        {
                            label: `${i18n.removeFrequent || 'Remove from history'}: ${item.label}`,
                            iconName: 'x',
                            onClick: () => {
                                removeUsageEntry(item.normalizedPath);
                                renderShortcuts();
                            },
                        },
                    ],
                }));
            }
        }
    };

    const createCloseButton = (label, frameId) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = `workspace-session-close-${frameId}`;
        btn.className = 'workspace-session__close ui-focus-ring';
        const closeLabel = i18n.closeSession || i18n.closeTab || 'Close session';
        btn.setAttribute('aria-label', `${closeLabel}: ${label}`);
        btn.title = closeLabel;
        btn.dataset.workspaceSessionClose = frameId;
        const icon = cloneTemplateIcon('x');
        if (icon) btn.appendChild(icon);
        else btn.textContent = '×';
        return btn;
    };

    const createGroupMenuButton = (label, groupId) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = `workspace-group-menu-${groupId}`;
        btn.className = 'workspace-session__menu-btn ui-focus-ring';
        const actionsLabel = i18n.sessionActions || i18n.tabActions || 'Session actions';
        btn.setAttribute('aria-label', `${actionsLabel}: ${label}`);
        btn.setAttribute('aria-haspopup', 'menu');
        btn.setAttribute('aria-expanded', 'false');
        btn.title = actionsLabel;
        btn.dataset.workspaceGroupMenu = groupId;
        const icon = cloneTemplateIcon('square-stack');
        if (icon) btn.appendChild(icon);
        else btn.textContent = '▾';
        return btn;
    };

    const syncTabPillState = (wrapper, trigger, { frame, group, isActive, isStacked, displayText }) => {
        const isDirty = isStacked ? group.dirty : frame.isDirty;
        const isStale = isStacked ? group.stale : frame.stale;
        const isHibernated = isStacked ? group.hibernated : frame.hibernated;
        const isQueued = isStacked
            ? Boolean(group.queued)
            : Boolean(frame.queued);
        const isLoading = isStacked
            ? group.frames.some((f) => Boolean(f.loading) && !f.queued)
            : (Boolean(frame.loading) && !Boolean(frame.queued));

        const isPinnedParent = Boolean(group.pinned && (isStacked || frame.role === 'parent'));

        wrapper.classList.toggle('is-active', Boolean(isActive));
        wrapper.dataset.pinned = isPinnedParent ? 'true' : 'false';
        wrapper.dataset.dirty = isDirty ? 'true' : 'false';
        wrapper.dataset.stale = isStale ? 'true' : 'false';
        wrapper.dataset.hibernated = isHibernated ? 'true' : 'false';
        wrapper.dataset.queued = isQueued ? 'true' : 'false';
        wrapper.dataset.loading = isLoading ? 'true' : 'false';

        trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
        trigger.setAttribute('aria-controls', `workspace-panel-${frame.frameId}`);
        trigger.setAttribute('aria-busy', (isLoading || isQueued) ? 'true' : 'false');
        trigger.tabIndex = isActive ? 0 : -1;

        const hasStatusIcon = Boolean(isDirty || isStale || isHibernated || isQueued || isLoading);
        wrapper.dataset.hasStatusIcon = hasStatusIcon ? 'true' : 'false';
        const statusNotes = [];
        const accessibleStatusNotes = [];
        if (isPinnedParent) statusNotes.push(i18n.pinnedBadge || 'Pinned');
        if (isDirty) {
            const msg = i18n.statusUnsaved || i18n.unsavedBadge || 'Unsaved changes';
            statusNotes.push(msg);
            accessibleStatusNotes.push(msg);
        } else if (isLoading) {
            const msg = i18n.loadingModule || 'Loading module...';
            statusNotes.push(msg);
            accessibleStatusNotes.push(msg);
        } else if (isStale) {
            const msg = i18n.statusStale || i18n.staleBadge || 'Refresh needed';
            statusNotes.push(msg);
            accessibleStatusNotes.push(msg);
        } else if (isQueued) {
            const msg = i18n.statusQueued || 'Queued to load';
            statusNotes.push(msg);
            accessibleStatusNotes.push(msg);
        } else if (isHibernated) {
            const msg = i18n.statusHibernated || i18n.hibernatedBadge || 'Hibernated';
            statusNotes.push(msg);
            accessibleStatusNotes.push(msg);
        } else if (isActive) {
            statusNotes.push(i18n.statusActive || 'Active session');
        }

        if (accessibleStatusNotes.length > 0) {
            trigger.setAttribute('aria-description', accessibleStatusNotes.join(' · '));
        } else {
            trigger.removeAttribute('aria-description');
        }

        const labelEl = trigger.querySelector('.workspace-session__label');
        if (labelEl && labelEl.textContent !== displayText) {
            labelEl.textContent = displayText;
            delete wrapper.dataset.naturalWidth;
            wrapper.style.removeProperty('--workspace-session-natural-width');
            const closeBtn = wrapper.querySelector(':scope > .workspace-session__close');
            if (closeBtn) {
                const closeLabel = i18n.closeSession || i18n.closeTab || 'Close session';
                closeBtn.setAttribute('aria-label', `${closeLabel}: ${displayText}`);
            }
            const menuBtn = wrapper.querySelector(':scope > .workspace-session__menu-btn');
            if (menuBtn) {
                const actionsLabel = i18n.sessionActions || i18n.tabActions || 'Session actions';
                menuBtn.setAttribute('aria-label', `${actionsLabel}: ${displayText}`);
            }
        }

        const pinnedSuffix = isPinnedParent ? `, ${i18n.pinnedBadge || 'Pinned'}` : '';
        const dupBadge = (isStacked || group.frames.length === 1 || frame.role === 'parent')
            ? formatSessionDuplicateBadge(group.duplicateIndex, group.duplicateTotal)
            : '';
        const dupSuffix = dupBadge ? ` ${dupBadge}` : '';
        const activeChild = isStacked ? group.frames[group.frames.length - 1] : null;
        const contextHint = formatSessionContextHint(
            isStacked ? (activeChild?.url || frame.url) : frame.url,
            { childTitle: activeChild?.title || '', parentTitle: displayText },
        );

        if (isStacked) {
            trigger.dataset.workspaceGroupSelect = group.groupId;
            const stackedText = formatCountText(i18n.stackedPagesCount || ':count pages in session', group.frames.length);
            const titleBase = `${displayText}${dupSuffix} — ${stackedText}${contextHint ? ` (${contextHint})` : ''}`;
            trigger.setAttribute('aria-label', `${displayText}${dupSuffix} (${stackedText})${pinnedSuffix}`);
            trigger.title = statusNotes.length ? `${titleBase} (${statusNotes.join(' · ')})` : titleBase;
        } else {
            trigger.dataset.workspaceSessionSelect = frame.frameId;
            if (dupSuffix || pinnedSuffix) {
                trigger.setAttribute('aria-label', `${displayText}${dupSuffix}${pinnedSuffix}`);
            } else {
                trigger.removeAttribute('aria-label');
            }
            const titleBase = contextHint ? `${displayText}${dupSuffix} · ${contextHint}` : `${displayText}${dupSuffix}`;
            trigger.title = statusNotes.length ? `${titleBase} (${statusNotes.join(' · ')})` : titleBase;
        }
    };

    const buildTabPillNode = ({ frame, group, showClose, isActive, isStacked, displayText }) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'workspace-session';
        if (isStacked) wrapper.classList.add('workspace-session--stacked');
        else if (group.frames.length > 1) wrapper.classList.add(frame.role === 'parent' ? 'workspace-session--parent' : 'workspace-session--child');

        const isPinnedParent = Boolean(group.pinned && (isStacked || frame.role === 'parent'));

        wrapper.setAttribute('role', 'presentation');
        wrapper.dataset.workspaceSession = isStacked ? group.groupId : frame.frameId;
        wrapper.dataset.workspaceGroupTarget = group.groupId;
        wrapper.dataset.pinned = isPinnedParent ? 'true' : 'false';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.setAttribute('role', 'tab');
        trigger.id = `workspace-session-${isStacked ? group.groupId : frame.frameId}`;
        trigger.className = 'workspace-session__trigger ui-focus-ring';

        syncTabPillState(wrapper, trigger, { frame, group, isActive, isStacked, displayText });

        if (isPinnedParent) {
            const pinEl = document.createElement('span');
            pinEl.className = 'workspace-session__pin-icon';
            pinEl.setAttribute('aria-hidden', 'true');
            const pinIcon = cloneTemplateIcon('pin');
            if (pinIcon) pinEl.appendChild(pinIcon);
            trigger.appendChild(pinEl);
        }

        const statusIcon = document.createElement('span');
        statusIcon.className = 'workspace-session__status-icon';
        statusIcon.setAttribute('aria-hidden', 'true');
        trigger.appendChild(statusIcon);

        const labelEl = document.createElement('span');
        labelEl.className = 'workspace-session__label';
        labelEl.textContent = displayText;
        trigger.appendChild(labelEl);

        const dupBadge = (isStacked || group.frames.length === 1 || frame.role === 'parent')
            ? formatSessionDuplicateBadge(group.duplicateIndex, group.duplicateTotal)
            : '';
        if (dupBadge) {
            const dupEl = document.createElement('span');
            dupEl.className = 'workspace-session__dup-index';
            dupEl.setAttribute('aria-hidden', 'true');
            dupEl.textContent = dupBadge;
            trigger.appendChild(dupEl);
        }

        wrapper.appendChild(trigger);

        if (isStacked) {
            wrapper.appendChild(createGroupMenuButton(displayText, group.groupId));
        } else if (showClose) {
            wrapper.appendChild(createCloseButton(displayText, frame.frameId));
        }

        return wrapper;
    };

    const restorePanelInteractiveState = (panel) => {
        if (!panel) return;
        panel.classList.remove('is-leaving');
        panel.style.removeProperty('--workspace-leave-opacity');
        panel.inert = false;
        panel.removeAttribute('aria-hidden');
    };

    const activatePanelSurface = (panel) => {
        if (!panel) return;
        leavingPanelCleanups.get(panel)?.(false);
        restorePanelInteractiveState(panel);
        panel.classList.remove('is-staging');
        panel.classList.add('is-active');
        panel.hidden = false;
    };

    const markPanelLeaving = (panel) => {
        if (!panel) return;
        const existingCleanup = leavingPanelCleanups.get(panel);
        if (existingCleanup) existingCleanup(false);

        const startOpacity = Number.parseFloat(window.getComputedStyle?.(panel)?.opacity);
        if (Number.isFinite(startOpacity) && startOpacity > 0 && startOpacity < 1) {
            panel.style.setProperty('--workspace-leave-opacity', String(startOpacity));
        } else {
            panel.style.removeProperty('--workspace-leave-opacity');
        }

        let done = false;
        let timerId = 0;
        const finish = (shouldHide = true) => {
            if (done) return;
            done = true;
            panel.removeEventListener('animationend', onAnimationEnd);
            panel.removeEventListener('transitionend', onTransitionEnd);
            if (timerId) window.clearTimeout(timerId);
            leavingPanelCleanups.delete(panel);
            restorePanelInteractiveState(panel);
            if (shouldHide && !panel.classList.contains('is-active') && !panel.classList.contains('is-staging')) {
                panel.hidden = true;
            }
        };
        const onAnimationEnd = (event) => {
            if (event.target === panel && event.animationName === 'workspace-panel-leave') {
                finish(true);
            }
        };
        const onTransitionEnd = (event) => {
            if (event.target === panel && event.propertyName === 'opacity') {
                finish(true);
            }
        };

        leavingPanelCleanups.set(panel, finish);
        panel.classList.remove('is-active');
        panel.classList.add('is-leaving');
        panel.inert = true;
        panel.setAttribute('aria-hidden', 'true');
        panel.hidden = false;
        panel.addEventListener('animationend', onAnimationEnd);
        panel.addEventListener('transitionend', onTransitionEnd);
        const pageFadeMs = Number.isFinite(Number(config.pageFadeMs)) && Number(config.pageFadeMs) > 0
            ? Number(config.pageFadeMs)
            : 500;
        timerId = window.setTimeout(() => finish(true), pageFadeMs + 80);
    };

    const syncTabDom = () => {
        const displayList = store.displayGroups();
        const activeTarget = store.activeTarget();
        const isShortcuts = activeTarget.type === SHORTCUTS_KEY;
        const activeFrameId = activeTarget.type === 'frame' ? activeTarget.frameId : null;

        // Reconcile mounted/unmounted iframe panels cleanly (keep staged frames mounted behind active panel)
        const liveOrKeptIds = new Set();
        for (const group of store.groups()) {
            for (const frame of group.frames) {
                if (!frame.hibernated) liveOrKeptIds.add(frame.frameId);
            }
        }

        framesEl.querySelectorAll('[data-workspace-panel]').forEach((node) => {
            const panelId = node.dataset.workspacePanel;
            if (panelId === SHORTCUTS_KEY) return;
            if (!liveOrKeptIds.has(panelId)) {
                leavingPanelCleanups.get(node)?.(false);
                frames.delete(panelId);
                node.remove();
            }
        });

        const prevActivePanel = Array.from(framesEl.querySelectorAll('.workspace-panel.is-active'))
            .find((el) => el.isConnected) || null;
        const nextActivePanelId = isShortcuts ? SHORTCUTS_KEY : activeFrameId;
        const nextActivePanel = nextActivePanelId
            ? framesEl.querySelector(`[data-workspace-panel="${CSS.escape(nextActivePanelId)}"]`)
            : null;

        if (
            hasCompletedInitialSync
            && prevActivePanel
            && nextActivePanel
            && prevActivePanel !== nextActivePanel
            && !prefersReducedMotion()
        ) {
            framesEl.querySelectorAll('.workspace-panel.is-leaving').forEach((leavingEl) => {
                if (leavingEl !== prevActivePanel) {
                    leavingPanelCleanups.get(leavingEl)?.(true);
                }
            });
            markPanelLeaving(prevActivePanel);
        }

        // Toggle Shortcuts pinned tab & native panel
        if (shortcutsTabEl) shortcutsTabEl.classList.toggle('is-active', isShortcuts);
        if (shortcutsSelectBtn) {
            shortcutsSelectBtn.setAttribute('aria-selected', isShortcuts ? 'true' : 'false');
            shortcutsSelectBtn.tabIndex = isShortcuts ? 0 : -1;
        }
        if (shortcutsPanel) {
            if (isShortcuts) {
                activatePanelSurface(shortcutsPanel);
            } else {
                const isLeaving = leavingPanelCleanups.has(shortcutsPanel);
                shortcutsPanel.classList.remove('is-active');
                shortcutsPanel.hidden = !isLeaving;
            }
        }

        // Preserve focus if the focused trigger stays present across state updates
        const previouslyFocusedId = document.activeElement?.closest?.('[data-workspace-sessionbar], [data-workspace-sessionlist]')
            ? document.activeElement.id
            : null;

        // Remove stale group elements no longer in store (with non-bouncy horizontal shorten ghost)
        const activeGroupIds = new Set(displayList.map((g) => g.groupId));
        tablistEl.querySelectorAll('[data-workspace-group]').forEach((groupEl) => {
            const gid = groupEl.dataset.workspaceGroup;
            if (gid === SHORTCUTS_KEY) return;
            if (!activeGroupIds.has(gid)) {
                animateRemovedGroupNode(groupEl);
                groupEl.remove();
            }
        });

        // Reconcile group DOM nodes cleanly before transitions measure target geometry
        let anchorNode = null;
        const pendingMorphs = [];
        const lastPinnedIdx = displayList.reduce((last, entry, idx) => (entry.pinned ? idx : last), -1);
        const lastPinnedGroupId = lastPinnedIdx !== -1 && lastPinnedIdx < displayList.length - 1
            ? displayList[lastPinnedIdx].groupId
            : null;

        for (const group of displayList) {
            let groupEl = tablistEl.querySelector(`[data-workspace-group="${CSS.escape(group.groupId)}"]`);
            const isNewGroupEl = !groupEl;
            if (!groupEl) {
                groupEl = document.createElement('div');
                groupEl.setAttribute('role', 'presentation');
                groupEl.dataset.workspaceGroup = group.groupId;
                groupEl.dataset.pinned = 'false';
                groupEl.className = 'workspace-session-group';
            }
            groupEl.classList.add('workspace-session-group');
            groupEl.dataset.workspaceModule = group.moduleKey;
            groupEl.dataset.pinned = group.pinned ? 'true' : 'false';
            groupEl.classList.toggle('is-last-pinned', group.groupId === lastPinnedGroupId);

            const pinSig = group.pinned ? 'p1' : 'p0';
            const dupSig = group.duplicateTotal > 1 && group.duplicateIndex > 1 ? group.duplicateIndex : 0;
            const expectedLayoutSig = group.isCollapsedStack
                ? `stack:${group.groupId}:${group.frames.length}:${dupSig}:${pinSig}`
                : `open:${group.frames.map((f) => `${f.frameId}:${f.role}`).join('|')}:${dupSig}:${pinSig}`;

            const expectedRenderSig = group.isCollapsedStack
                ? `${expectedLayoutSig}:${group.displayLabel}:${group.frames[group.frames.length - 1]?.url || ''}:${group.dirty}:${group.stale}:${group.hibernated}:${Boolean(group.queued)}:${group.frames.some((f) => Boolean(f.loading) && !f.queued)}`
                : `${expectedLayoutSig}:${group.frames.map((f) => `${f.title}:${f.url}:${f.isDirty}:${f.stale}:${f.hibernated}:${Boolean(f.queued)}:${Boolean(f.loading)}:${f.frameId === activeFrameId}`).join('|')}`;

            const prevLayoutSig = groupEl.dataset.layoutSig || '';
            const layoutChanged = isNewGroupEl || prevLayoutSig !== expectedLayoutSig;

            if (layoutChanged) {
                const livePrevWidth = isNewGroupEl
                    ? 0
                    : roundPx(groupEl.getBoundingClientRect?.().width || 0);
                const previousPills = Array.from(groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)'));
                const previousPillIds = new Set(previousPills.map((p) => p.dataset.workspaceSession));
                const nextFrameIds = new Set(group.frames.map((f) => f.frameId));
                const wasCollapsedStack = prevLayoutSig.startsWith('stack:');
                const prevPill0Width = previousPills[0] ? roundPx(previousPills[0].getBoundingClientRect?.().width || 0) : 0;
                const morphType = isNewGroupEl
                    ? 'new'
                    : (wasCollapsedStack && !group.isCollapsedStack)
                        ? 'stack-to-expanded'
                        : (prevLayoutSig.startsWith('open:') && group.isCollapsedStack)
                            ? 'expanded-to-stack'
                            : (prevLayoutSig.startsWith('open:') && !group.isCollapsedStack && previousPills.length === 1 && group.frames.length > 1)
                                ? 'single-to-expanded'
                                : 'same';
                const prevPill0Overlay = (morphType === 'stack-to-expanded' || morphType === 'expanded-to-stack')
                    ? createPillMorphOverlay(previousPills[0])
                    : null;

                cancelElementMotion(groupEl);
                groupEl.dataset.layoutSig = expectedLayoutSig;
                groupEl.dataset.renderSig = expectedRenderSig;

                let tailEl = null;
                const childGhosts = [];
                const revealingPills = [];
                const isStackEntering = !isNewGroupEl && group.isCollapsedStack && prevLayoutSig.startsWith('open:');

                if (prevLayoutSig.startsWith('open:')) {
                    if (group.isCollapsedStack) {
                        tailEl = createCollapseTail(previousPills.slice(1));
                    } else {
                        previousPills.forEach((oldPill, prevIdx) => {
                            const oldFrameId = oldPill.dataset.workspaceSession;
                            if (oldFrameId && !nextFrameIds.has(oldFrameId)) {
                                const gw = roundPx(oldPill.getBoundingClientRect?.().width || 0);
                                if (gw > 0) {
                                    const gClone = oldPill.cloneNode(true);
                                    gClone.querySelectorAll('.workspace-session__morph-overlay').forEach((n) => n.remove());
                                    sanitizeGhostElement(gClone);
                                    gClone.className = 'workspace-session workspace-session--ghost is-closing';
                                    gClone._startWidth = gw;
                                    gClone._targetIndex = prevIdx;
                                    childGhosts.push(gClone);
                                }
                            }
                        });
                    }
                }

                groupEl.classList.toggle('is-active', Boolean(group.isActive));
                groupEl.classList.toggle('is-expanded', Boolean(group.isExpanded));
                groupEl.classList.toggle('is-collapsed-stack', Boolean(group.isCollapsedStack));

                groupEl.replaceChildren();

                if (group.isCollapsedStack) {
                    const parent = group.frames[0];
                    const pill = buildTabPillNode({
                        frame: parent,
                        group,
                        showClose: false,
                        isActive: false,
                        isStacked: true,
                        displayText: group.displayLabel,
                    });
                    groupEl.appendChild(pill);
                    if (tailEl) {
                        groupEl.appendChild(tailEl);
                    }
                } else {
                    const expandingFromStack = prevLayoutSig.startsWith('stack:');
                    for (const frame of group.frames) {
                        const isFrameActive = frame.frameId === activeFrameId;
                        const isMultiParent = group.frames.length > 1 && frame.role === 'parent';
                        const isPinnedParent = Boolean(group.pinned && frame.role === 'parent');
                        const pill = buildTabPillNode({
                            frame,
                            group,
                            showClose: !isMultiParent && !isPinnedParent,
                            isActive: isFrameActive,
                            isStacked: false,
                            displayText: frame.title,
                        });
                        groupEl.appendChild(pill);
                        if (
                            !isNewGroupEl
                            && ((expandingFromStack && frame.role === 'child')
                                || (!expandingFromStack && prevLayoutSig && !previousPillIds.has(frame.frameId)))
                        ) {
                            revealingPills.push(pill);
                        }
                    }

                    childGhosts.forEach((ghostNode) => {
                        const idx = Math.min(ghostNode._targetIndex, groupEl.children.length);
                        const refNode = groupEl.children[idx] || null;
                        groupEl.insertBefore(ghostNode, refNode);
                    });
                }

                insertGroupAfterAnchor(anchorNode, groupEl);

                pendingMorphs.push({
                    groupEl,
                    livePrevWidth,
                    isNewGroupEl,
                    tailEl,
                    childGhosts,
                    revealingPills,
                    isStackEntering,
                    wasCollapsedStack,
                    prevPill0Width,
                    prevPill0Overlay,
                    morphType,
                    transientHide: [
                        ...(tailEl ? [tailEl] : []),
                        ...childGhosts,
                    ],
                });
            } else {
                groupEl.classList.toggle('is-active', Boolean(group.isActive));
                groupEl.classList.toggle('is-expanded', Boolean(group.isExpanded));
                groupEl.classList.toggle('is-collapsed-stack', Boolean(group.isCollapsedStack));
                insertGroupAfterAnchor(anchorNode, groupEl);

                if (groupEl.dataset.renderSig !== expectedRenderSig) {
                    groupEl.dataset.renderSig = expectedRenderSig;
                    const livePills = Array.from(groupEl.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)'));
                    if (group.isCollapsedStack && livePills[0]) {
                        const parent = group.frames[0];
                        const trigger = livePills[0].querySelector('[role="tab"]');
                        if (parent && trigger) {
                            syncTabPillState(livePills[0], trigger, {
                                frame: parent,
                                group,
                                isActive: false,
                                isStacked: true,
                                displayText: group.displayLabel,
                            });
                        }
                    } else {
                        group.frames.forEach((frame, idx) => {
                            const pill = livePills[idx];
                            const trigger = pill?.querySelector('[role="tab"]');
                            if (!pill || !trigger) return;
                            syncTabPillState(pill, trigger, {
                                frame,
                                group,
                                isActive: frame.frameId === activeFrameId,
                                isStacked: false,
                                displayText: frame.title,
                            });
                        });
                    }
                }
            }

            anchorNode = groupEl;

            for (const frame of group.frames) {
                if (frame.hibernated) continue;
                const panel = framesEl.querySelector(`[data-workspace-panel="${CSS.escape(frame.frameId)}"]`);
                if (panel) {
                    const isPanelActive = frame.frameId === activeFrameId;
                    const isPanelStaging = Boolean(frame.staged) && !isPanelActive;
                    if (isPanelActive) {
                        activatePanelSurface(panel);
                    } else {
                        const isLeaving = leavingPanelCleanups.has(panel);
                        panel.classList.remove('is-active');
                        panel.classList.toggle('is-staging', isPanelStaging);
                        panel.hidden = !isPanelStaging && !isLeaving;
                    }
                    panel.setAttribute('aria-busy', frame.loading ? 'true' : 'false');
                }
            }
        }

        // Also update panel visibility for stagedNew groups omitted from displayGroups()
        for (const group of store.groups()) {
            for (const frame of group.frames) {
                if (!frame.stagedNew || frame.hibernated) continue;
                const panel = framesEl.querySelector(`[data-workspace-panel="${CSS.escape(frame.frameId)}"]`);
                if (panel) {
                    const isPanelActive = frame.frameId === activeFrameId;
                    if (isPanelActive) {
                        activatePanelSurface(panel);
                    } else {
                        panel.classList.remove('is-active');
                        panel.classList.add('is-staging');
                        panel.hidden = false;
                    }
                    panel.setAttribute('aria-busy', frame.loading ? 'true' : 'false');
                }
            }
        }

        recomputeDynamicTabCap();

        const measuredMorphs = pendingMorphs.map((morph) => ({
            morph,
            ...measureTargetGroupLayout(morph.groupEl, morph.transientHide),
        }));

        for (const { morph, targetWidth, pillWidths } of measuredMorphs) {
            animateGroupTransition(morph.groupEl, {
                startWidth: morph.livePrevWidth,
                targetWidth,
                pillWidths,
                isNew: morph.isNewGroupEl,
                tailEl: morph.tailEl,
                childGhosts: morph.childGhosts,
                revealingPills: morph.revealingPills,
                isStackEntering: morph.isStackEntering,
                wasCollapsedStack: morph.wasCollapsedStack,
                prevPill0Width: morph.prevPill0Width,
                prevPill0Overlay: morph.prevPill0Overlay,
                morphType: morph.morphType,
            });
        }

        if (switcherBtn) {
            switcherBtn.hidden = displayList.length < 2 && readRecentlyClosedList().length === 0;
            if (switcherCountEl) switcherCountEl.textContent = String(displayList.length);
        }

        if (activeMenuContext?.kind === 'group-pages' && tabMenuEl && !tabMenuEl.hidden) {
            const liveMenuAnchor = tablistEl.querySelector(
                `[data-workspace-group-menu="${CSS.escape(activeMenuContext.groupId)}"]`,
            );
            if (liveMenuAnchor?.isConnected) {
                lastMenuOpener = liveMenuAnchor;
                liveMenuAnchor.setAttribute('aria-expanded', 'true');
            } else if (!activeMenuContext.closingInFlight) {
                closeTabMenu({ restoreFocus: false, immediate: true });
            }
        }

        if (previouslyFocusedId) {
            const restoredFocus = document.getElementById(previouslyFocusedId);
            if (restoredFocus && document.activeElement !== restoredFocus) {
                restoredFocus.focus({ preventScroll: true });
            }
        }

        const activeBtn = tablistEl.querySelector('[role="tab"][aria-selected="true"]');
        const scrollTarget = activeBtn?.closest('.workspace-session-group.is-expanded')
            || activeBtn?.closest('.workspace-session')
            || activeBtn;
        const activeGroupEl = scrollTarget?.closest?.('.workspace-session-group');
        if (!activeGroupEl?.classList?.contains('is-entering') && !activeGroupEl?.classList?.contains('is-morphing')) {
            scrollTarget?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        }

        if (tablistOwnerEl) {
            const ownedTabIds = [];
            if (shortcutsSelectBtn?.id) ownedTabIds.push(shortcutsSelectBtn.id);
            tablistEl.querySelectorAll(':scope > [data-workspace-group]:not(.workspace-session-group--ghost) [role="tab"][id]').forEach((tabBtn) => {
                if (tabBtn.id) ownedTabIds.push(tabBtn.id);
            });
            if (ownedTabIds.length > 0) {
                tablistOwnerEl.setAttribute('aria-owns', ownedTabIds.join(' '));
            } else {
                tablistOwnerEl.removeAttribute('aria-owns');
            }
        }

        syncStageControls();
        renderShortcuts();
        updateTabOverflowState();
        window.requestAnimationFrame?.(updateTabOverflowState);
        hasCompletedInitialSync = true;
    };

    const syncHistory = (mode, url, frameId, groupId, label) => {
        if (!url || mode === 'skip') return;
        const cleanInternalUrl = stripEmbedParam(url, origin);
        if (!cleanInternalUrl || !isSameOriginUrl(cleanInternalUrl, origin)) return;

        const projection = buildHistoryProjection({
            internalUrl: cleanInternalUrl,
            origin,
            contextLinks: config.contextLinks,
            frameId,
            groupId,
            title: label,
        });
        const displayUrl = projection.displayUrl;
        if (!displayUrl || !isSameOriginUrl(displayUrl, origin)) return;
        if (config.contextLinks?.enabled && displayUrl.includes('favoriteContext=')) {
            lastProjectedContextUrl = displayUrl;
        }
        const currentHref = window.location.href;

        if (mode === 'replace' || currentHref === displayUrl) {
            window.history.replaceState(projection.state, '', displayUrl);
        } else {
            window.history.pushState(projection.state, '', displayUrl);
        }
    };

    const syncShortcutsHistory = (mode = 'push') => {
        if (mode === 'skip') return;
        const dashBase = canonicalizeUrl(config.dashboardUrl || window.location.href, origin);
        const projection = buildShortcutsHistoryProjection({
            lastContextUrl: lastProjectedContextUrl,
            origin,
            contextLinks: config.contextLinks,
            dashboardUrl: dashBase,
            shortcutsUrl: config.shortcutsUrl || config.contextLinks?.shortcutsUrl || '',
            title: i18n.shortcuts || 'Shortcuts',
        });
        const target = projection.target;
        if (!target || !isSameOriginUrl(target, origin)) return;

        const alreadyShortcutsState = Boolean(window.history.state?.shortcuts);
        if (
            mode === 'replace'
            || (window.location.href === target && (!config.contextLinks?.enabled || alreadyShortcutsState))
        ) {
            window.history.replaceState(projection.state, '', target);
        } else {
            window.history.pushState(projection.state, '', target);
        }
    };

    const attachReadinessListener = (frameId, frameWin, readiness) => {
        if (!readiness || typeof readiness.whenReady !== 'function' || attachedReadinessPromises.has(readiness)) {
            return;
        }
        attachedReadinessPromises.add(readiness);
        readiness.whenReady().then(() => {
            finalizeFrameReady(frameId, frameWin);
        });
    };

    const applySyncedFrameTitle = (frameId, iframe, res) => {
        if (!res?.ok) return false;
        const updatedCtx = store.getFrameContext(frameId);
        if (!updatedCtx) return false;
        const updatedTitle = resolveCanonicalFrameTitle(updatedCtx, updatedCtx.frame.url, res.title || updatedCtx.frame.title);
        updatedCtx.frame.title = updatedTitle;
        if (updatedCtx.frame.role === 'parent') updatedCtx.group.baseLabel = updatedTitle;
        if (iframe) iframe.title = updatedTitle;
        return true;
    };

    const syncFrameLocale = async (frameId, iframe, payload = null, { seq = null } = {}) => {
        if (!frameId || !iframe || frames.get(frameId) !== iframe || syncedLocaleFrames.has(frameId)) {
            return false;
        }

        let childWin = null;
        try {
            childWin = iframe.contentWindow;
            if (!childWin || !isSameOriginUrl(childWin.location?.href, origin)) return false;
        } catch {
            return false;
        }

        pendingLocaleSyncFrames.delete(frameId);

        if (typeof childWin.AdasiLocaleSwitcher?.syncLocaleInPlace !== 'function') {
            const ctx = store.getFrameContext(frameId);
            if (ctx) {
                ctx.frame.stale = true;
                if (ctx.frame.loading) {
                    try {
                        childWin.location.replace(appendEmbedParam(ctx.frame.url, origin));
                    } catch {}
                }
            }
            return false;
        }

        const resolvedPayload = {
            locale: payload?.locale || latestLocalePayload?.locale || document.documentElement?.lang || 'en',
            i18n: payload?.i18n ?? latestLocalePayload?.i18n ?? null,
            datatables: payload?.datatables ?? latestLocalePayload?.datatables ?? null,
            session_copy: payload?.session_copy ?? latestLocalePayload?.session_copy ?? null,
            pagination_label: payload?.pagination_label ?? latestLocalePayload?.pagination_label ?? null,
        };

        syncedLocaleFrames.add(frameId);
        try {
            const res = await childWin.AdasiLocaleSwitcher.syncLocaleInPlace(resolvedPayload);
            if (seq !== null && seq !== localeSyncSeq) return false;
            if (!applySyncedFrameTitle(frameId, iframe, res)) return false;
            forceRebuildAllTabs();
            return true;
        } catch {
            return false;
        } finally {
            syncedLocaleFrames.delete(frameId);
        }
    };

    const syncLocaleIfNeeded = (frameId, iframe, frameWin, frameDoc) => {
        const topLocale = document.documentElement?.lang;
        const embedLocale = frameDoc?.documentElement?.lang;
        const isPending = pendingLocaleSyncFrames.has(frameId);
        if (!topLocale || (!isPending && (!embedLocale || embedLocale === topLocale)) || syncedLocaleFrames.has(frameId)) {
            return;
        }
        const queuedPayload = pendingLocaleSyncFrames.get(frameId) || latestLocalePayload || { locale: topLocale };
        void syncFrameLocale(frameId, iframe, queuedPayload);
    };

    const finalizeFrameReady = (frameId, frameWin = null) => {
        const ctx = store.getFrameContext(frameId);
        if (!ctx) {
            if (frameId === initialFrameId) initialReadyResolve?.();
            return false;
        }

        const iframe = frames.get(frameId);
        let liveWin = frameWin;
        let liveDoc = null;
        if (!liveWin && iframe) {
            try { liveWin = iframe.contentWindow; } catch {}
        }
        if (liveWin) {
            try { liveDoc = liveWin.document; } catch {}
        }

        let liveHref = ctx.frame.url;
        try {
            if (liveWin?.location?.href && liveWin.location.href !== 'about:blank' && liveWin.location.href !== 'about:srcdoc') {
                liveHref = liveWin.location.href;
            }
        } catch {}

        const cleanUrl = stripEmbedParam(liveHref, origin) || ctx.frame.url;
        const title = resolveCanonicalFrameTitle(ctx, cleanUrl, liveDoc?.title || ctx.frame.title);
        store.markLoaded(frameId, { url: cleanUrl, label: title });
        if (iframe && title) iframe.title = title;
        if (ctx.group.pinned && ctx.frame.role === 'parent') {
            persistPinnedGroups();
        }

        if (liveWin && liveDoc) {
            syncLocaleIfNeeded(frameId, iframe, liveWin, liveDoc);
        }

        if (navQueue.has(frameId)) {
            navQueue.complete(frameId);
            syncTriggerPendingAttributes();
        } else {
            store.commitStagedFrame(frameId, { activate: false });
            const activeFrame = store.activeFrame();
            if (activeFrame && activeFrame.frameId === frameId) {
                const topbarTitle = formatTopbarTitle(activeFrame, ctx.group);
                syncTopbarTitle(topbarTitle);
                syncSidebarHighlight(cleanUrl);
                syncHistory('replace', cleanUrl, frameId, ctx.group.groupId, topbarTitle);
            }
            syncTabDom();
        }

        if (frameId === initialFrameId) {
            initialReadyResolve?.();
        }
        return true;
    };

    const handleInaccessibleFrameLoad = async (frameId, iframe, triggerReason = 'blocked') => {
        if (frames.get(frameId) !== iframe || probingFrames.has(frameId)) return;

        const ctx = store.getFrameContext(frameId);
        const queueEntry = navQueue.get(frameId);
        if (!ctx) {
            navQueue.cancel(frameId, triggerReason);
            unmountFramePanel(frameId);
            return;
        }

        const entrySnapshot = {
            frameId,
            groupId: ctx.group.groupId,
            kind: queueEntry?.kind || 'workspace',
            url: ctx.frame.url || queueEntry?.url || '',
            label: ctx.frame.title || queueEntry?.label || '',
            historyMode: queueEntry?.historyMode || 'push',
            startedAt: queueEntry?.startedAt ?? null,
        };

        probingFrames.add(frameId);
        try {
            if (window.AdasiSessionSync?.isSessionExpired?.()) {
                navQueue.cancel(frameId, 'session-expired');
                cleanupAbortedFrame(frameId);
                window.AdasiSessionSync.requestReconnect?.(false, { reason: 'session_ended' });
                return;
            }

            if (typeof window.AdasiSessionSync?.probeSessionStatus === 'function') {
                const status = await window.AdasiSessionSync.probeSessionStatus();
                if (frames.get(frameId) !== iframe && !navQueue.has(frameId)) return;

                if (status?.authenticated === false) {
                    navQueue.cancel(frameId, 'session-expired');
                    cleanupAbortedFrame(frameId);
                    window.AdasiSessionSync.requestReconnect?.(false, {
                        reason: status.reason || 'session_ended',
                        reconnectable: status.reconnectable,
                    });
                    return;
                }

                if (status?.networkError === true) {
                    navQueue.cancel(frameId, 'network');
                    cleanupAbortedFrame(frameId);
                    reportNavigationFailure(entrySnapshot, 'network');
                    return;
                }
            }

            const probeUrl = ctx.frame.embedUrl || appendEmbedParam(ctx.frame.url, origin);
            if (!probeUrl || !isSameOriginUrl(probeUrl, origin) || typeof window.fetch !== 'function') {
                navQueue.cancel(frameId, triggerReason);
                cleanupAbortedFrame(frameId);
                reportNavigationFailure(entrySnapshot, triggerReason === 'iframe-error' ? 'network' : 'load-error');
                return;
            }

            const response = await window.fetch(probeUrl, {
                method: 'HEAD',
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
                priority: 'low',
            });
            if (frames.get(frameId) !== iframe && !navQueue.has(frameId)) return;

            if (response.type === 'opaqueredirect') {
                navQueue.cancel(frameId, 'breakout');
                cleanupAbortedFrame(frameId);
                breakoutToTop(ctx.frame.url);
                return;
            }

            const xfo = String(response.headers?.get?.('x-frame-options') || '').toUpperCase();
            const contentType = String(response.headers?.get?.('content-type') || '').toLowerCase();
            const disposition = String(response.headers?.get?.('content-disposition') || '').toLowerCase();

            if (response.ok && (disposition.includes('attachment') || (contentType !== '' && !contentType.includes('text/html')))) {
                navQueue.cancel(frameId, 'download');
                cleanupAbortedFrame(frameId);
                const safeDownload = safeWebUrl(ctx.frame.url, origin);
                if (safeDownload && isSameOriginUrl(safeDownload, origin)) {
                    window.location.assign(safeDownload);
                }
                return;
            }

            if ((response.ok && xfo === 'DENY') || (response.status === 403 && isTopLevelOnlyUrl(ctx.frame.url, origin, pathPrefix))) {
                navQueue.cancel(frameId, 'breakout');
                cleanupAbortedFrame(frameId);
                breakoutToTop(ctx.frame.url);
                return;
            }

            navQueue.cancel(frameId, 'load-error');
            cleanupAbortedFrame(frameId);
            reportNavigationFailure(entrySnapshot, 'load-error');
        } catch {
            if (frames.get(frameId) !== iframe && !navQueue.has(frameId)) return;
            navQueue.cancel(frameId, 'network');
            cleanupAbortedFrame(frameId);
            reportNavigationFailure(entrySnapshot, 'network');
        } finally {
            probingFrames.delete(frameId);
        }
    };

    const onFrameLoad = (frameId, iframe) => {
        if (frames.get(frameId) !== iframe) return;

        let frameWin;
        let frameDoc;
        try {
            frameWin = iframe.contentWindow;
            frameDoc = iframe.contentDocument;
        } catch {
            void handleInaccessibleFrameLoad(frameId, iframe, 'blocked');
            return;
        }

        if (!frameWin || !frameDoc) {
            if (iframe.dataset.workspaceNavigated === 'true') {
                void handleInaccessibleFrameLoad(frameId, iframe, 'blocked');
            }
            return;
        }

        let frameHref = '';
        try {
            frameHref = frameWin.location.href;
        } catch {
            void handleInaccessibleFrameLoad(frameId, iframe, 'blocked');
            return;
        }

        if (!frameHref || frameHref === 'about:blank' || frameHref === 'about:srcdoc') {
            return;
        }

        if (!isSameOriginUrl(frameHref, origin)) {
            void handleInaccessibleFrameLoad(frameId, iframe, 'cross-origin');
            return;
        }

        // Handle an auth-login document inside an iframe BEFORE isTopLevelOnlyUrl so
        // expired sessions open the ERP Session Ended modal instead of abruptly
        // destroying unsaved tabs with breakoutToTop.
        if (frameDoc.querySelector('[data-auth-login-page]')) {
            navQueue.cancel(frameId, 'breakout');
            cleanupAbortedFrame(frameId);
            if (typeof window.AdasiSessionSync?.requestReconnect === 'function') {
                window.AdasiSessionSync.requestReconnect(false, { reason: 'session_ended' });
            } else {
                breakoutToTop(frameHref);
            }
            return;
        }

        if (isTopLevelOnlyUrl(frameHref, origin, pathPrefix)) {
            navQueue.cancel(frameId, 'breakout');
            cleanupAbortedFrame(frameId);
            breakoutToTop(frameHref);
            return;
        }

        if (frameDoc.querySelector('[data-workspace-shell]')) {
            if (frameHref.includes(`${config.embedParam || '_embed'}=`)) {
                navQueue.cancel(frameId, 'breakout');
                cleanupAbortedFrame(frameId);
                breakoutToTop(frameHref);
            } else {
                navQueue.resetSlotTimer(frameId);
                frameWin.location.replace(appendEmbedParam(frameHref, origin));
            }
            return;
        }

        const ctx = store.getFrameContext(frameId);
        if (!ctx) return;

        const cleanUrl = stripEmbedParam(frameHref, origin);
        const title = resolveCanonicalFrameTitle(ctx, cleanUrl, frameDoc.title);
        ctx.frame.url = canonicalizeUrl(cleanUrl, origin);
        ctx.frame.embedUrl = appendEmbedParam(ctx.frame.url, origin);
        ctx.frame.title = title;
        if (ctx.frame.role === 'parent') ctx.group.baseLabel = title;
        iframe.title = title;

        syncLocaleIfNeeded(frameId, iframe, frameWin, frameDoc);
        attachGlobalShortcutListener(frameDoc);

        const readiness = frameWin.AdasiEmbedReadiness;
        if (readiness && !readiness.isReady) {
            ctx.frame.queued = false;
            ctx.frame.loading = true;
            if (navQueue.has(frameId)) {
                navQueue.markDocumentLoaded(frameId, { awaitingData: true });
            }
            attachReadinessListener(frameId, frameWin, readiness);
            syncTabDom();
            return;
        }

        finalizeFrameReady(frameId, frameWin);
    };

    const createPanelAndFrame = (frame) => {
        let panel = framesEl.querySelector(`[data-workspace-panel="${CSS.escape(frame.frameId)}"]`);
        if (panel) {
            const existingFrame = frames.get(frame.frameId);
            if (existingFrame) return existingFrame;
        }

        panel = document.createElement('div');
        panel.className = frame.staged ? 'workspace-panel is-staging' : 'workspace-panel';
        panel.id = `workspace-panel-${frame.frameId}`;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', `workspace-session-${frame.frameId}`);
        panel.setAttribute('aria-busy', 'true');
        panel.dataset.workspacePanel = frame.frameId;

        const iframe = document.createElement('iframe');
        iframe.className = 'workspace-frame';
        iframe.name = `workspace-frame-${frame.frameId}`;
        iframe.title = frame.title;
        iframe.loading = 'eager';
        iframe.setAttribute('referrerpolicy', 'same-origin');
        iframe.dataset.workspaceFrame = frame.frameId;
        iframe.dataset.workspaceNavigated = 'true';
        iframe.src = frame.embedUrl;
        iframe.addEventListener('load', () => onFrameLoad(frame.frameId, iframe));
        iframe.addEventListener('error', () => {
            void handleInaccessibleFrameLoad(frame.frameId, iframe, 'iframe-error');
        });

        panel.appendChild(iframe);
        framesEl.appendChild(panel);
        frames.set(frame.frameId, iframe);
        return iframe;
    };

    const ensureFrameMounted = (frame) => {
        if (!frame || frame.hibernated) return null;
        if (frames.has(frame.frameId)) return frames.get(frame.frameId);
        return createPanelAndFrame(frame);
    };

    const refreshFrameSilently = (frame) => {
        const iframe = frames.get(frame.frameId);
        if (!iframe || !frame) {
            if (frame && !frame.hibernated) ensureFrameMounted(frame);
            return;
        }

        try {
            const win = iframe.contentWindow;
            if (win?.DataTable) {
                const api = win.DataTable;
                const tables = api.tables ? api.tables({ api: true }) : null;
                if (tables && typeof tables.count === 'function' && tables.count() > 0 && typeof tables.ajax?.url === 'function' && tables.ajax.url()) {
                    frame.loading = true;
                    syncTabDom();
                    tables.ajax.reload(() => {
                        const current = store.getFrame(frame.frameId);
                        if (current) {
                            current.loading = false;
                            store.markFresh(frame.frameId);
                            syncTabDom();
                        }
                    }, false);
                    return;
                }
            }
        } catch {
            // Fall through cleanly to full iframe reload.
        }

        frame.loading = true;
        syncTabDom();
        try {
            iframe.contentWindow.location.replace(appendEmbedParam(frame.url, origin));
        } catch {
            iframe.src = appendEmbedParam(frame.url, origin);
        }
    };

    const notifyActiveSurface = (historyMode = 'push') => {
        if (store.isShortcutsActive()) {
            syncTabDom();
            syncTopbarTitle(i18n.shortcuts || 'Shortcuts');
            syncSidebarHighlight(null);
            syncShortcutsHistory(historyMode);
            return;
        }

        const activeFrame = store.activeFrame();
        const activeGroup = store.activeGroup();
        if (!activeFrame || !activeGroup) return;

        syncTabDom();
        const topbarTitle = formatTopbarTitle(activeFrame, activeGroup);
        syncTopbarTitle(topbarTitle);
        syncSidebarHighlight(activeFrame.url);
        syncHistory(historyMode, activeFrame.url, activeFrame.frameId, activeGroup.groupId, topbarTitle);
    };

    const openNewWorkspace = ({ moduleKey = null, label = '', url, historyMode = 'push', triggerEl = null }) => {
        const target = resolveLinkTarget(url);
        const resolvedUrl = target.url || stripEmbedParam(url, origin);
        if (!resolvedUrl || !isSameOriginUrl(resolvedUrl, origin)) {
            openSafeExternal(url);
            return false;
        }
        if (isTopLevelOnlyUrl(resolvedUrl, origin, pathPrefix)) {
            breakoutToTop(resolvedUrl);
            return false;
        }

        const canonicalKey = `workspace:${canonicalizeUrl(resolvedUrl, origin)}`;
        const nowMs = Date.now();
        const prevLaunch = recentWorkspaceLaunches.get(canonicalKey);
        if (
            triggerEl
            && prevLaunch
            && prevLaunch.triggerEl === triggerEl
            && (nowMs - prevLaunch.at) < 400
            && navQueue.has(prevLaunch.frameId)
        ) {
            navQueue.prioritize(prevLaunch.frameId);
            return true;
        }

        const resolvedKey = moduleKey || target.key || resolveModuleForUrl(resolvedUrl);
        const pathTitle = menuPathTitles.get(normalizedPath(resolvedUrl, origin));
        const catalogEntry = catalog.find((item) => item.key === resolvedKey);
        const tabLabel = label || pathTitle || catalogEntry?.label || resolvedKey;

        const result = store.openWorkspace({
            moduleKey: resolvedKey,
            label: tabLabel,
            url: resolvedUrl,
            stageOnly: true,
            showSessionWhileStaged: false,
        });

        if (result.blocked) {
            window.AdasiToast?.warning?.(i18n.evictionBlocked || 'All open sessions have unsaved changes.');
            return false;
        }

        if (result.closedFrameIds?.length) {
            cancelQueuedFrames(result.closedFrameIds);
        }

        recordModuleUse(resolvedUrl);
        if (result.staged && result.frame) {
            if (triggerEl) {
                trackedTriggerEls.add(triggerEl);
                recentWorkspaceLaunches.set(canonicalKey, { triggerEl, frameId: result.frame.frameId, at: nowMs });
            }
            const entry = navQueue.enqueue({
                frameId: result.frame.frameId,
                groupId: result.group.groupId,
                kind: 'workspace',
                canonicalKey,
                url: resolvedUrl,
                label: tabLabel,
                historyMode,
                triggerEl,
                autoFocus: true,
            });
            if (entry && entry.status === 'queued') {
                result.frame.queued = true;
                result.frame.loading = false;
            }
            syncTriggerPendingAttributes();
            syncTabDom();
            const queuedMsg = String(i18n.queuedAnnouncement || 'Queued :label (:count loading)')
                .replace(':label', tabLabel)
                .replace(':count', String(navQueue.size()));
            announceLiveStatus(queuedMsg);
            return true;
        }
        ensureFrameMounted(result.frame);
        notifyActiveSurface(historyMode);
        return true;
    };

    const openChildInGroup = (groupId, sourceFrameId, url, label = '', triggerEl = null) => {
        const target = resolveLinkTarget(url);
        const resolvedUrl = target.url || stripEmbedParam(url, origin);
        if (!resolvedUrl || !isSameOriginUrl(resolvedUrl, origin)) return;
        if (isTopLevelOnlyUrl(resolvedUrl, origin, pathPrefix)) {
            breakoutToTop(resolvedUrl);
            return;
        }

        const canonicalKey = `child:${groupId}:${canonicalizeUrl(resolvedUrl, origin)}`;
        const resolvedLabel = label || store.getGroup(groupId)?.baseLabel || i18n.loadingModule;

        const result = store.openChildFrame({
            groupId,
            sourceFrameId,
            label: resolvedLabel,
            url: resolvedUrl,
            stageOnly: true,
            showSessionWhileStaged: false,
        });

        if (result.blocked) {
            window.AdasiToast?.warning?.(i18n.evictionBlocked || 'All open sessions have unsaved changes.');
            return;
        }

        if (result.closedFrameIds?.length) {
            cancelQueuedFrames(result.closedFrameIds);
        }

        if (result.staged && result.frame) {
            if (navQueue.has(result.frame.frameId)) {
                navQueue.prioritize(result.frame.frameId);
            } else {
                if (triggerEl) trackedTriggerEls.add(triggerEl);
                const entry = navQueue.enqueue({
                    frameId: result.frame.frameId,
                    groupId: result.group.groupId,
                    kind: 'child',
                    canonicalKey,
                    url: resolvedUrl,
                    label: result.frame.title,
                    historyMode: 'push',
                    triggerEl,
                    wasHibernated: Boolean(result.wasHibernated),
                    autoFocus: true,
                });
                if (entry && entry.status === 'queued') {
                    result.frame.queued = true;
                    result.frame.loading = false;
                }
            }
            syncTriggerPendingAttributes();
            syncTabDom();
            return;
        }

        navQueue.clearAutoFocus();
        if (result.created || result.wasHibernated) {
            ensureFrameMounted(result.frame);
        } else if (result.shouldRefresh) {
            refreshFrameSilently(result.frame);
        }

        notifyActiveSurface('push');
    };

    const activateShortcuts = ({ historyMode = 'push', focusTrigger = false } = {}) => {
        navQueue.clearAutoFocus();
        store.activateShortcuts();
        notifyActiveSurface(historyMode);
        if (focusTrigger) {
            shortcutsSelectBtn?.focus?.();
        }
    };

    const activateGroup = (groupId, { historyMode = 'push', focusTrigger = false, triggerEl = null } = {}) => {
        const res = store.activateGroup(groupId, { stageIfHibernated: true, stageIfLoading: true });
        if (!res.frame) return;

        if (res.staged) {
            if (navQueue.has(res.frame.frameId)) {
                navQueue.prioritize(res.frame.frameId);
            } else {
                if (triggerEl) trackedTriggerEls.add(triggerEl);
                const entry = navQueue.enqueue({
                    frameId: res.frame.frameId,
                    groupId: res.group.groupId,
                    kind: 'activate',
                    canonicalKey: `frame:${res.frame.frameId}`,
                    url: res.frame.url,
                    label: res.frame.title,
                    historyMode,
                    triggerEl,
                    wasHibernated: Boolean(res.wasHibernated),
                    autoFocus: true,
                });
                if (entry && entry.status === 'queued') {
                    res.frame.queued = true;
                    res.frame.loading = false;
                }
            }
            syncTriggerPendingAttributes();
            syncTabDom();
            return;
        }

        navQueue.clearAutoFocus();
        if (res.wasHibernated) {
            ensureFrameMounted(res.frame);
        } else if (res.shouldRefresh) {
            refreshFrameSilently(res.frame);
        } else {
            const activeIframe = frames.get(res.frame.frameId);
            if (activeIframe) {
                try {
                    const activeWin = activeIframe.contentWindow;
                    const activeDoc = activeWin?.document;
                    if (activeWin && activeDoc) {
                        syncLocaleIfNeeded(res.frame.frameId, activeIframe, activeWin, activeDoc);
                    }
                } catch {}
            }
        }

        notifyActiveSurface(historyMode);

        if (focusTrigger) {
            tablistEl.querySelector(`#workspace-session-${CSS.escape(res.frame.frameId)}`)?.focus?.();
        }
    };

    const activateFrame = (frameId, { historyMode = 'push', focusTrigger = false, triggerEl = null } = {}) => {
        const res = store.activateFrame(frameId, { stageIfHibernated: true, stageIfLoading: true });
        if (!res.frame) return;

        if (res.staged) {
            if (navQueue.has(res.frame.frameId)) {
                navQueue.prioritize(res.frame.frameId);
            } else {
                if (triggerEl) trackedTriggerEls.add(triggerEl);
                const entry = navQueue.enqueue({
                    frameId: res.frame.frameId,
                    groupId: res.group.groupId,
                    kind: 'activate',
                    canonicalKey: `frame:${res.frame.frameId}`,
                    url: res.frame.url,
                    label: res.frame.title,
                    historyMode,
                    triggerEl,
                    wasHibernated: Boolean(res.wasHibernated),
                    autoFocus: true,
                });
                if (entry && entry.status === 'queued') {
                    res.frame.queued = true;
                    res.frame.loading = false;
                }
            }
            syncTriggerPendingAttributes();
            syncTabDom();
            return;
        }

        navQueue.clearAutoFocus();
        if (res.wasHibernated) {
            ensureFrameMounted(res.frame);
        } else if (res.shouldRefresh) {
            refreshFrameSilently(res.frame);
        } else {
            const activeIframe = frames.get(res.frame.frameId);
            if (activeIframe) {
                try {
                    const activeWin = activeIframe.contentWindow;
                    const activeDoc = activeWin?.document;
                    if (activeWin && activeDoc) {
                        syncLocaleIfNeeded(res.frame.frameId, activeIframe, activeWin, activeDoc);
                    }
                } catch {}
            }
        }

        notifyActiveSurface(historyMode);

        if (focusTrigger) {
            tablistEl.querySelector(`#workspace-session-${CSS.escape(res.frame.frameId)}`)?.focus?.();
        }
    };

    const confirmDirtyClose = async (dirtyFrames) => {
        if (!dirtyFrames || dirtyFrames.length === 0) return true;
        const message = dirtyFrames.length === 1
            ? (i18n.closeDirtyConfirm || 'This session has unsaved changes. Close it anyway?')
            : (i18n.confirmCloseDirtyBulk || 'Some closing sessions have unsaved changes. Close them anyway?');

        if (window.AdasiAlert?.confirm) {
            const res = await window.AdasiAlert.confirm({
                title: i18n.unsavedBadge || 'Unsaved changes',
                text: message,
                confirmVariant: 'danger',
            });
            return Boolean(res?.isConfirmed);
        }
        return window.confirm(message);
    };

    const cancelQueuedFrames = (frameIds = []) => {
        if (!Array.isArray(frameIds) || frameIds.length === 0) return;
        for (const fid of frameIds) {
            navQueue.cancel(fid, 'closed');
            unmountFramePanel(fid);
        }
        syncTriggerPendingAttributes();
    };

    const notifyPinnedCloseProtected = () => {
        const message = i18n.pinnedCloseProtected || 'Pinned session is protected. Unpin it first to close.';
        window.AdasiToast?.info?.(message);
        announceLiveStatus(message);
    };

    const handlePostCloseResult = (closeResult) => {
        if (closeResult?.blockedByPin) {
            notifyPinnedCloseProtected();
            return;
        }
        cancelQueuedFrames(closeResult?.closedFrameIds || []);
        recordRecentlyClosed(closeResult?.closedSnapshots || []);
        const { nextTarget, wasHibernated, shouldRefresh } = closeResult;
        if (!nextTarget || nextTarget.type === SHORTCUTS_KEY) {
            notifyActiveSurface('push');
            return;
        }
        if (navQueue.has(nextTarget.frameId)) {
            navQueue.prioritize(nextTarget.frameId);
            syncTabDom();
            return;
        }
        const frame = store.getFrame(nextTarget.frameId);
        if (frame) {
            if (wasHibernated) {
                ensureFrameMounted(frame);
            } else if (shouldRefresh) {
                refreshFrameSilently(frame);
            }
        }
        notifyActiveSurface('push');
    };

    const requestCloseFrame = async (frameId, { onBeforeConfirmDirty = null } = {}) => {
        const ctx = store.getFrameContext(frameId);
        if (!ctx) return { closed: false, blockedByPin: false, closedFrameIds: [] };

        const dirtyFrames = ctx.frame.role === 'parent'
            ? store.dirtyFramesForClose('group', ctx.group.groupId)
            : store.dirtyFramesForClose('frame', frameId);

        if (dirtyFrames.length > 0) {
            onBeforeConfirmDirty?.();
            if (!(await confirmDirtyClose(dirtyFrames))) {
                return { closed: false, blockedByPin: false, closedFrameIds: [] };
            }
        }
        const res = store.closeFrame(frameId);
        if (res.closed || res.blockedByPin) handlePostCloseResult(res);
        return {
            closed: Boolean(res.closed),
            blockedByPin: Boolean(res.blockedByPin),
            closedFrameIds: res.closedFrameIds || [],
        };
    };

    const requestCloseGroup = async (groupId) => {
        const group = store.getGroup(groupId);
        if (!group) return;
        const dirtyFrames = store.dirtyFramesForClose('group', groupId);
        if (dirtyFrames.length > 0 && !(await confirmDirtyClose(dirtyFrames))) return;
        const res = store.closeGroup(groupId);
        if (res.closed || res.blockedByPin) handlePostCloseResult(res);
    };

    const requestBulkClose = async (scope, anchorGroupId = null) => {
        const dirtyFrames = store.dirtyFramesForClose(scope, anchorGroupId);
        if (dirtyFrames.length > 0 && !(await confirmDirtyClose(dirtyFrames))) return;
        let res;
        if (scope === 'others') res = store.closeOtherGroups(anchorGroupId);
        else if (scope === 'right') res = store.closeGroupsToRight(anchorGroupId);
        else res = store.closeAllGroups();
        handlePostCloseResult(res);
    };

    const requestDuplicateGroup = (groupId) => {
        navQueue.clearAutoFocus();
        const res = store.duplicateGroup(groupId);
        if (res.blocked) {
            window.AdasiToast?.warning?.(i18n.evictionBlocked || 'All open sessions have unsaved changes.');
            return;
        }
        if (res.frame) {
            recordModuleUse(res.frame.url);
            ensureFrameMounted(res.frame);
            notifyActiveSurface('push');
        }
    };

    const requestRefreshFrame = async (frameId) => {
        const frame = store.getFrame(frameId);
        if (!frame) return;

        if (frame.isDirty && window.AdasiAlert?.confirm) {
            const res = await window.AdasiAlert.confirm({
                title: i18n.unsavedBadge || 'Unsaved changes',
                text: i18n.closeDirtyConfirm || 'This session has unsaved changes. Refresh anyway?',
                confirmVariant: 'danger',
            });
            if (!res?.isConfirmed) return;
        }

        if (frame.hibernated) {
            activateFrame(frameId, { historyMode: 'skip' });
            return;
        }

        const iframe = frames.get(frameId);
        if (!iframe) return;
        frame.loading = true;
        frame.stale = false;
        syncTabDom();
        try {
            iframe.contentWindow.location.replace(appendEmbedParam(frame.url, origin));
        } catch {
            iframe.src = appendEmbedParam(frame.url, origin);
        }
    };

    // Context & Stack Action Popover Menu + Cross-Boundary Overlay Dismissal
    const dismissEmbedOverlays = (excludeEmbedWindow = null) => {
        frames.forEach((iframe) => {
            try {
                const childWin = iframe.contentWindow;
                if (!childWin || (excludeEmbedWindow && childWin === excludeEmbedWindow)) return;
                childWin.dispatchEvent(new childWin.CustomEvent('adasi:dismiss-overlays'));
            } catch {}
        });
    };

    let hoverMenuCloseTimer = 0;
    let tabMenuOpenedByHover = false;
    let activeMenuContext = null;
    const MENU_ITEM_SELECTOR = '[role="menuitem"], [role="menuitemradio"], [role="menuitemcheckbox"]';

    const clearHoverMenuCloseTimer = () => {
        if (hoverMenuCloseTimer) {
            window.clearTimeout(hoverMenuCloseTimer);
            hoverMenuCloseTimer = 0;
        }
    };

    const scheduleHoverMenuClose = () => {
        clearHoverMenuCloseTimer();
        if (!tabMenuOpenedByHover || !tabMenuEl || tabMenuEl.hidden) return;
        hoverMenuCloseTimer = window.setTimeout(() => {
            hoverMenuCloseTimer = 0;
            if (tabMenuOpenedByHover) {
                closeTabMenu({ restoreFocus: false });
            }
        }, 160);
    };

    const finalizeTabMenuClose = () => {
        if (!tabMenuEl) return;
        if (tabMenuCloseTimer) {
            window.clearTimeout(tabMenuCloseTimer);
            tabMenuCloseTimer = 0;
        }
        activeMenuContext = null;
        tabMenuEl.classList.remove('is-closing', 'workspace-session-menu--group-pages');
        tabMenuEl.hidden = true;
        tabMenuEl.replaceChildren();
    };

    const closeTabMenu = ({ restoreFocus = false, immediate = false } = {}) => {
        clearHoverMenuCloseTimer();
        tabMenuOpenedByHover = false;
        activeMenuContext = null;
        if (!tabMenuEl || tabMenuEl.hidden) return;
        if (tabMenuCloseTimer) {
            window.clearTimeout(tabMenuCloseTimer);
            tabMenuCloseTimer = 0;
        }
        tablistEl.querySelectorAll('[aria-expanded="true"]').forEach((btn) => btn.setAttribute('aria-expanded', 'false'));
        if (switcherBtn) switcherBtn.setAttribute('aria-expanded', 'false');
        const opener = lastMenuOpener;
        lastMenuOpener = null;
        if (restoreFocus && opener?.isConnected) {
            opener.focus();
        }
        if (immediate || prefersReducedMotion()) {
            finalizeTabMenuClose();
            return;
        }
        tabMenuEl.classList.add('is-closing');
        const onEnd = (event) => {
            if (event.target === tabMenuEl && event.propertyName === 'opacity') {
                tabMenuEl.removeEventListener('transitionend', onEnd);
                finalizeTabMenuClose();
            }
        };
        tabMenuEl.addEventListener('transitionend', onEnd);
        tabMenuCloseTimer = window.setTimeout(() => {
            tabMenuEl.removeEventListener('transitionend', onEnd);
            finalizeTabMenuClose();
        }, 140);
    };

    const dismissTopOverlaysForMenu = (exceptToggle = null) => {
        window.AdasiDismissDropdowns?.(exceptToggle);
        window.AdasiCalendar?.closeActive?.();
        window.dispatchEvent(new CustomEvent('adasi:dismiss-overlays'));
    };

    const dismissFloatingOverlays = ({ restoreFocus = false, excludeEmbedWindow = null, keepTopDropdownToggle = null, immediate = false } = {}) => {
        closeTabMenu({ restoreFocus, immediate });
        dismissTopOverlaysForMenu(keepTopDropdownToggle);
        dismissEmbedOverlays(excludeEmbedWindow);
    };

    const clampOpenTabMenuPosition = (anchorEl, clientX = null, clientY = null) => {
        if (!tabMenuEl || tabMenuEl.hidden) return;
        const rect = anchorEl?.getBoundingClientRect?.();
        const rawX = clientX ?? (rect ? rect.left : 16);
        const rawY = clientY ?? (rect ? rect.bottom + 4 : 88);
        tabMenuEl.style.left = '8px';
        tabMenuEl.style.top = '8px';
        const menuRect = tabMenuEl.getBoundingClientRect();
        const clampedX = Math.max(8, Math.min(rawX, window.innerWidth - menuRect.width - 8));
        const clampedY = Math.max(8, Math.min(rawY, window.innerHeight - menuRect.height - 8));
        tabMenuEl.style.left = `${clampedX}px`;
        tabMenuEl.style.top = `${clampedY}px`;
    };

    const positionAndShowTabMenu = (anchorEl, clientX = null, clientY = null, { focusFirst = true } = {}) => {
        if (!tabMenuEl) return;
        if (tabMenuCloseTimer) {
            window.clearTimeout(tabMenuCloseTimer);
            tabMenuCloseTimer = 0;
        }
        tabMenuEl.classList.remove('is-closing');
        tabMenuEl.hidden = false;
        clampOpenTabMenuPosition(anchorEl, clientX, clientY);
        if (focusFirst) {
            tabMenuEl.querySelector(MENU_ITEM_SELECTOR)?.focus();
        }
    };

    const focusSurvivingWorkspaceTab = (preferredGroupId = null) => {
        if (preferredGroupId) {
            const preferredGroupEl = tablistEl.querySelector(`[data-workspace-group="${CSS.escape(preferredGroupId)}"]`);
            const preferredTrigger = preferredGroupEl?.querySelector('[role="tab"][aria-selected="true"]')
                || preferredGroupEl?.querySelector('[role="tab"]');
            if (preferredTrigger) {
                preferredTrigger.focus();
                preferredTrigger.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
                return;
            }
        }
        const activeTrigger = tabStripEventsEl.querySelector('[role="tab"][aria-selected="true"]')
            || shortcutsSelectBtn;
        activeTrigger?.focus?.();
    };

    const handleGroupPageClose = async (groupId, item, { rowIndex = 0, fromKeyboard = false } = {}) => {
        clearHoverMenuCloseTimer();
        tabMenuOpenedByHover = false;
        const menuCtx = activeMenuContext?.kind === 'group-pages' && activeMenuContext.groupId === groupId
            ? activeMenuContext
            : null;
        if (menuCtx) menuCtx.closingInFlight = true;

        const res = await requestCloseFrame(item.frameId, {
            onBeforeConfirmDirty: () => closeTabMenu({ restoreFocus: false, immediate: true }),
        });

        if (menuCtx) menuCtx.closingInFlight = false;
        if (!res?.closed) return;

        announceLiveStatus(fillI18n(i18n.closedAnnouncement || 'Closed :label', { label: item.title }));

        const updatedGroup = store.getGroup(groupId);
        const remainingItems = updatedGroup ? resolveGroupSessionMenuItems(updatedGroup) : [];
        const liveAnchor = tablistEl.querySelector(`[data-workspace-group-menu="${CSS.escape(groupId)}"]`);

        if (!updatedGroup || remainingItems.length < 2 || !liveAnchor?.isConnected) {
            closeTabMenu({ restoreFocus: false, immediate: true });
            if (fromKeyboard) {
                focusSurvivingWorkspaceTab(updatedGroup ? groupId : null);
            }
            return;
        }

        if (
            tabMenuEl
            && !tabMenuEl.hidden
            && activeMenuContext?.kind === 'group-pages'
            && activeMenuContext.groupId === groupId
        ) {
            lastMenuOpener = liveAnchor;
            liveAnchor.setAttribute('aria-expanded', 'true');
            renderGroupPagesMenuContent(updatedGroup, {
                focusRowIndex: fromKeyboard ? Math.min(rowIndex, remainingItems.length - 1) : null,
            });
            clampOpenTabMenuPosition(liveAnchor);
        }
    };

    const createGroupPageRow = (groupId, item, rowIndex) => {
        const row = document.createElement('div');
        row.className = 'workspace-session-menu__row';
        if (item.isCurrentInGroup) row.classList.add('is-current');
        row.setAttribute('role', 'none');
        row.dataset.workspaceGroupPageRow = item.frameId;

        const selectBtn = document.createElement('button');
        selectBtn.type = 'button';
        selectBtn.className = 'workspace-session-menu__item workspace-session-menu__item--group-page ui-focus-ring';
        if (item.isCurrentInGroup) selectBtn.classList.add('is-current');
        selectBtn.setAttribute('role', 'menuitemradio');
        selectBtn.setAttribute('aria-checked', item.isCurrentInGroup ? 'true' : 'false');
        selectBtn.dataset.workspaceGroupPageSelect = item.frameId;

        const statusNotes = [];
        if (item.isDirty) statusNotes.push(i18n.unsavedBadge || 'Unsaved changes');
        else if (item.isHibernated) statusNotes.push(i18n.hibernatedBadge || 'Hibernated');
        const statusSummary = statusNotes.join(' · ');
        selectBtn.title = statusSummary ? `${item.title} (${statusSummary})` : item.title;
        if (item.isPinnedProtected) {
            selectBtn.setAttribute('aria-description', i18n.pinnedBadge || 'Pinned');
        }

        const checkSlot = document.createElement('span');
        checkSlot.className = 'workspace-session-menu__check-slot';
        checkSlot.setAttribute('aria-hidden', 'true');
        if (item.isCurrentInGroup) {
            const checkIcon = cloneTemplateIcon('check');
            if (checkIcon) checkSlot.appendChild(checkIcon);
            else checkSlot.textContent = '✓';
        }

        const textWrap = document.createElement('span');
        textWrap.className = 'workspace-session-menu__item-text';
        const labelSpan = document.createElement('span');
        labelSpan.className = 'workspace-session-menu__item-label';
        labelSpan.textContent = item.title;
        textWrap.appendChild(labelSpan);

        if (statusSummary) {
            const subSpan = document.createElement('span');
            subSpan.className = 'workspace-session-menu__item-sub';
            subSpan.textContent = statusSummary;
            textWrap.appendChild(subSpan);
        }

        selectBtn.append(checkSlot, textWrap);
        selectBtn.addEventListener('click', () => {
            closeTabMenu();
            activateFrame(item.frameId, { focusTrigger: true });
        });
        row.appendChild(selectBtn);

        if (item.canClose) {
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'workspace-session-menu__close ui-focus-ring';
            closeBtn.setAttribute('role', 'menuitem');
            const closeLabel = item.closesWholeGroup
                ? (i18n.closeGroup || 'Close session group')
                : (i18n.closeSession || i18n.closeTab || 'Close session');
            closeBtn.setAttribute('aria-label', `${closeLabel}: ${item.title}`);
            closeBtn.title = closeLabel;
            closeBtn.dataset.workspaceGroupPageClose = item.frameId;
            const closeIcon = cloneTemplateIcon('x');
            if (closeIcon) closeBtn.appendChild(closeIcon);
            else closeBtn.textContent = '×';
            closeBtn.addEventListener('click', (event) => {
                event.stopPropagation();
                void handleGroupPageClose(groupId, item, {
                    rowIndex,
                    fromKeyboard: event.detail === 0,
                });
            });
            row.appendChild(closeBtn);
        } else if (item.isPinnedProtected) {
            const pinLock = document.createElement('span');
            pinLock.className = 'workspace-session-menu__pin-lock';
            pinLock.setAttribute('aria-hidden', 'true');
            pinLock.title = i18n.pinnedBadge || 'Pinned';
            const pinIcon = cloneTemplateIcon('pin');
            if (pinIcon) pinLock.appendChild(pinIcon);
            row.appendChild(pinLock);
        }

        return row;
    };

    const renderGroupPagesMenuContent = (group, { focusRowIndex = null } = {}) => {
        if (!tabMenuEl || !group) return false;
        const items = resolveGroupSessionMenuItems(group);
        if (items.length === 0) return false;

        tabMenuEl.replaceChildren();
        const groupContainer = document.createElement('div');
        groupContainer.className = 'workspace-session-menu__group';
        groupContainer.setAttribute('role', 'group');
        groupContainer.setAttribute('aria-label', group.baseLabel || i18n.sessionActions || 'Session actions');

        items.forEach((item, index) => {
            groupContainer.appendChild(createGroupPageRow(group.groupId, item, index));
        });

        tabMenuEl.appendChild(groupContainer);

        if (focusRowIndex !== null && focusRowIndex >= 0) {
            const rows = groupContainer.querySelectorAll('[data-workspace-group-page-row]');
            const targetRow = rows[Math.min(focusRowIndex, rows.length - 1)];
            targetRow?.querySelector('[data-workspace-group-page-select]')?.focus();
        }
        return true;
    };

    const openGroupPagesMenu = ({ groupId = null, anchorEl = null, focusMenu = false, openedByHover = false }) => {
        if (!tabMenuEl) return;
        const group = store.getGroup(groupId);
        const items = group ? resolveGroupSessionMenuItems(group) : [];
        if (!group || items.length === 0) return;

        dismissFloatingOverlays({ restoreFocus: false, immediate: true });
        tabMenuOpenedByHover = openedByHover;
        activeMenuContext = { kind: 'group-pages', groupId: group.groupId, closingInFlight: false };
        lastMenuOpener = anchorEl || document.activeElement;
        if (anchorEl?.dataset?.workspaceGroupMenu) {
            anchorEl.setAttribute('aria-expanded', 'true');
        }

        tabMenuEl.classList.add('workspace-session-menu--group-pages');
        renderGroupPagesMenuContent(group);
        positionAndShowTabMenu(anchorEl, null, null, { focusFirst: focusMenu });
    };

    const openWorkspaceSwitcherMenu = (anchorEl) => {
        if (!tabMenuEl) return;
        const displayList = store.displayGroups();
        const recentlyClosed = readRecentlyClosedList();
        if (displayList.length === 0 && recentlyClosed.length === 0) return;

        dismissFloatingOverlays({ restoreFocus: false, immediate: true });
        lastMenuOpener = anchorEl || document.activeElement;
        if (anchorEl) anchorEl.setAttribute('aria-expanded', 'true');

        if (displayList.length > 0) {
            const heading = document.createElement('div');
            heading.className = 'workspace-session-menu__heading';
            heading.textContent = i18n.openSessions || i18n.openWorkspaces || 'Open sessions';
            tabMenuEl.appendChild(heading);

            for (const group of displayList) {
                const dupBadge = formatSessionDuplicateBadge(group.duplicateIndex, group.duplicateTotal);
                const label = dupBadge ? `${group.displayLabel} ${dupBadge}` : group.displayLabel;
                const activeGroupFrame = group.frames.find((f) => f.frameId === group.activeFrameId) || group.frames[group.frames.length - 1];
                const contextHint = formatSessionContextHint(activeGroupFrame?.url, {
                    childTitle: group.frames.length > 1 ? activeGroupFrame?.title : '',
                    parentTitle: group.displayLabel,
                });
                const badgeParts = [];
                if (group.frames.length > 1) badgeParts.push(String(group.frames.length));
                if (group.dirty) badgeParts.push(i18n.unsavedBadge || 'Unsaved changes');
                else if (group.hibernated) badgeParts.push(i18n.hibernatedBadge || 'Hibernated');

                tabMenuEl.appendChild(createMenuButtonItem({
                    label,
                    subtext: contextHint,
                    badgeText: badgeParts.join(' '),
                    iconName: group.pinned ? 'pin' : (group.frames.length > 1 ? 'layers' : 'chevron-right'),
                    current: group.isActive,
                    onSelect: () => activateGroup(group.groupId, { focusTrigger: true }),
                }));
            }
        }

        if (recentlyClosed.length > 0) {
            if (displayList.length > 0) {
                const recentDivider = document.createElement('div');
                recentDivider.className = 'workspace-session-menu__divider';
                recentDivider.setAttribute('role', 'separator');
                tabMenuEl.appendChild(recentDivider);
            }

            const recentHeading = document.createElement('div');
            recentHeading.className = 'workspace-session-menu__heading';
            recentHeading.textContent = i18n.recentlyClosed || 'Recently closed';
            tabMenuEl.appendChild(recentHeading);

            for (const entry of recentlyClosed.slice(0, 8)) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: entry.label,
                    iconName: 'rotate-ccw',
                    onSelect: () => reopenRecentlyClosed(entry.url),
                }));
            }

            tabMenuEl.appendChild(createMenuButtonItem({
                label: i18n.clearRecentlyClosed || 'Clear recently closed',
                iconName: 'trash-2',
                onSelect: () => clearRecentlyClosedList(),
            }));
        }

        if (displayList.some((group) => !group.pinned)) {
            const divider = document.createElement('div');
            divider.className = 'workspace-session-menu__divider';
            divider.setAttribute('role', 'separator');
            tabMenuEl.appendChild(divider);

            tabMenuEl.appendChild(createMenuButtonItem({
                label: i18n.closeAll || 'Close all sessions',
                iconName: 'x',
                danger: true,
                onSelect: () => requestBulkClose('all'),
            }));
        }

        positionAndShowTabMenu(anchorEl);
    };

    const createMenuButtonItem = ({ label, subtext = '', badgeText = '', iconName = null, danger = false, current = false, onSelect }) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'workspace-session-menu__item ui-focus-ring';
        if (danger) btn.classList.add('is-danger');
        if (current) btn.classList.add('is-current');
        btn.setAttribute('role', 'menuitem');
        if (subtext) {
            btn.title = `${label} — ${subtext}`;
        }

        const main = document.createElement('span');
        main.className = 'workspace-session-menu__item-main';
        if (iconName) {
            const icon = cloneTemplateIcon(iconName);
            if (icon) main.appendChild(icon);
        }
        const textWrap = document.createElement('span');
        textWrap.className = 'workspace-session-menu__item-text';
        const textSpan = document.createElement('span');
        textSpan.className = 'workspace-session-menu__item-label';
        textSpan.textContent = label;
        textWrap.appendChild(textSpan);
        if (subtext) {
            const subSpan = document.createElement('span');
            subSpan.className = 'workspace-session-menu__item-sub';
            subSpan.textContent = subtext;
            textWrap.appendChild(subSpan);
        }
        main.appendChild(textWrap);
        btn.appendChild(main);

        if (badgeText) {
            const badge = document.createElement('span');
            badge.className = 'workspace-session-menu__item-badge';
            badge.textContent = badgeText;
            btn.appendChild(badge);
        }

        btn.addEventListener('click', () => {
            closeTabMenu();
            onSelect();
        });
        return btn;
    };

    const openTabMenu = ({ groupId = null, frameId = null, anchorEl = null, clientX = null, clientY = null }) => {
        if (!tabMenuEl) return;

        const isShortcutsTarget = groupId === SHORTCUTS_KEY;
        const group = isShortcutsTarget ? null : store.getGroup(groupId);
        const groups = store.groups();
        if (!isShortcutsTarget && !group) return;

        dismissFloatingOverlays({ restoreFocus: false, immediate: true });
        lastMenuOpener = anchorEl || document.activeElement;
        if (anchorEl?.dataset?.workspaceGroupMenu) {
            anchorEl.setAttribute('aria-expanded', 'true');
        }

        const recentlyClosed = readRecentlyClosedList();
        const hasUnpinnedGroups = groups.some((g) => !g.pinned);

        if (isShortcutsTarget) {
            let added = false;
            if (recentlyClosed.length > 0) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.reopenClosedSession || 'Reopen closed session',
                    iconName: 'rotate-ccw',
                    onSelect: () => reopenRecentlyClosed(),
                }));
                added = true;
            }
            if (hasUnpinnedGroups) {
                if (added) {
                    const div = document.createElement('div');
                    div.className = 'workspace-session-menu__divider';
                    div.setAttribute('role', 'separator');
                    tabMenuEl.appendChild(div);
                }
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.closeAll || 'Close all sessions',
                    iconName: 'x',
                    danger: true,
                    onSelect: () => requestBulkClose('all'),
                }));
                added = true;
            }
            if (!added) return;
        } else {
            const targetFrame = (frameId ? store.getFrame(frameId) : null)
                || store.getFrame(group.activeFrameId)
                || group.frames[0];
            const moveBounds = store.canMoveGroup(group.groupId);

            if (targetFrame) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.refreshSession || i18n.refreshTab || 'Refresh session',
                    iconName: 'refresh-cw',
                    onSelect: () => requestRefreshFrame(targetFrame.frameId),
                }));
            }

            tabMenuEl.appendChild(createMenuButtonItem({
                label: group.pinned ? (i18n.unpinSession || 'Unpin session') : (i18n.pinSession || 'Pin session'),
                iconName: group.pinned ? 'pin-off' : 'pin',
                current: Boolean(group.pinned),
                onSelect: () => requestTogglePinGroup(group.groupId, { refocus: true }),
            }));

            if (moveBounds.canMoveLeft) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.moveSessionLeft || 'Move session left',
                    iconName: 'arrow-left',
                    onSelect: () => requestMoveGroupByOffset(group.groupId, -1, { refocus: true }),
                }));
            }
            if (moveBounds.canMoveRight) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.moveSessionRight || 'Move session right',
                    iconName: 'arrow-right',
                    onSelect: () => requestMoveGroupByOffset(group.groupId, 1, { refocus: true }),
                }));
            }

            tabMenuEl.appendChild(createMenuButtonItem({
                label: i18n.duplicateSession || i18n.duplicateTab || 'Duplicate session',
                iconName: 'copy',
                onSelect: () => requestDuplicateGroup(group.groupId),
            }));

            if (recentlyClosed.length > 0) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.reopenClosedSession || 'Reopen closed session',
                    iconName: 'rotate-ccw',
                    onSelect: () => reopenRecentlyClosed(),
                }));
            }

            const targetBookmarkUrl = targetFrame ? canonicalizeBookmarkUrl(targetFrame.url) : '';
            if (targetBookmarkUrl) {
                const isBookmarked = isBookmarkedUrl(targetBookmarkUrl);
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: isBookmarked ? (i18n.removeBookmark || 'Remove bookmark') : (i18n.addBookmark || 'Bookmark session'),
                    iconName: 'bookmark',
                    current: isBookmarked,
                    onSelect: () => toggleBookmarkEntry({
                        url: targetBookmarkUrl,
                        label: formatTopbarTitle(targetFrame, group) || targetFrame.title,
                        moduleKey: group.moduleKey,
                    }),
                }));
            }

            const favCatalogEntry = findCatalogEntryForGroup(group);
            if (favCatalogEntry?.normalizedPath) {
                const isFav = isFavoritePath(favCatalogEntry.normalizedPath);
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: isFav ? (i18n.removeFavorite || 'Remove from Favorites') : (i18n.addFavorite || 'Add to Favorites'),
                    iconName: 'star',
                    current: isFav,
                    onSelect: () => toggleFavoritePath(favCatalogEntry.normalizedPath),
                }));
            }

            const groupIndex = groups.findIndex((g) => g.groupId === group.groupId);
            const hasClosableChild = Boolean(targetFrame && targetFrame.role === 'child');
            const hasClosableSelf = !group.pinned;
            const hasClosableOthers = groups.some((g) => !g.pinned && g.groupId !== group.groupId);
            const hasClosableRight = groupIndex !== -1 && groups.slice(groupIndex + 1).some((g) => !g.pinned);

            if (hasClosableChild || hasClosableSelf || hasClosableOthers || hasClosableRight || hasUnpinnedGroups) {
                const div2 = document.createElement('div');
                div2.className = 'workspace-session-menu__divider';
                div2.setAttribute('role', 'separator');
                tabMenuEl.appendChild(div2);
            }

            if (hasClosableChild) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.closeSession || i18n.closeTab || 'Close session',
                    iconName: 'x',
                    onSelect: () => requestCloseFrame(targetFrame.frameId),
                }));
            }

            if (hasClosableSelf) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: group.frames.length > 1 ? (i18n.closeGroup || 'Close session group') : (i18n.closeSession || i18n.closeTab || 'Close session'),
                    iconName: 'x',
                    danger: group.frames.length > 1,
                    onSelect: () => requestCloseGroup(group.groupId),
                }));
            }

            if (hasClosableOthers) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.closeOthers || 'Close other sessions',
                    onSelect: () => requestBulkClose('others', group.groupId),
                }));
            }
            if (hasClosableRight) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.closeRight || 'Close sessions to the right',
                    onSelect: () => requestBulkClose('right', group.groupId),
                }));
            }
            if (hasUnpinnedGroups) {
                tabMenuEl.appendChild(createMenuButtonItem({
                    label: i18n.closeAll || 'Close all sessions',
                    danger: true,
                    onSelect: () => requestBulkClose('all'),
                }));
            }
        }

        positionAndShowTabMenu(anchorEl, clientX, clientY);
    };

    // Hydrate persisted pinned sessions (hibernated) + SSR initial group & iframe seamlessly without double reload
    const ssrGroupEl = tablistEl.querySelector('[data-workspace-group="initial"]');
    const ssrTabEl = tablistEl.querySelector('[data-workspace-session="initial"]');
    const ssrTriggerEl = tablistEl.querySelector('[data-workspace-session-select="initial"]');
    const ssrCloseEl = tablistEl.querySelector('[data-workspace-session-close="initial"]');
    const ssrPanelEl = framesEl.querySelector('[data-workspace-panel="initial"]');
    const ssrFrameEl = framesEl.querySelector('[data-workspace-frame="initial"]');

    const persistedPinnedEntries = readPinnedList();
    const seedHibernatedEntry = (entry) => {
        const entryLabel = menuPathTitles.get(normalizedPath(entry.url, origin)) || entry.label;
        store.seedHibernatedPinned({
            moduleKey: entry.moduleKey,
            label: entryLabel,
            url: entry.url,
            icon: entry.icon || '',
        });
    };

    if (
        config.initialMode === 'shortcuts'
        || !initialTab?.url
        || window.location.hash === `#${SHORTCUTS_KEY}`
    ) {
        ssrGroupEl?.remove();
        ssrPanelEl?.remove();
        for (const entry of persistedPinnedEntries) {
            seedHibernatedEntry(entry);
        }
        store.activateShortcuts();
        initialReadyResolve?.();
        notifyActiveSurface('replace');
    } else {
        const canonicalInitialUrl = canonicalizeUrl(initialTab.url, origin);
        const canonicalInitialLabel = menuPathTitles.get(normalizedPath(initialTab.url, origin)) || initialTab.label;
        const matchedPinIdx = persistedPinnedEntries.findIndex((entry) => entry.url === canonicalInitialUrl);

        if (matchedPinIdx !== -1) {
            for (let i = 0; i < matchedPinIdx; i += 1) {
                seedHibernatedEntry(persistedPinnedEntries[i]);
            }
        } else {
            for (const entry of persistedPinnedEntries) {
                seedHibernatedEntry(entry);
            }
        }

        const seeded = store.seedInitial({
            moduleKey: initialTab.key,
            label: canonicalInitialLabel,
            url: initialTab.url,
            pinned: matchedPinIdx !== -1,
        });

        if (matchedPinIdx !== -1) {
            for (let i = matchedPinIdx + 1; i < persistedPinnedEntries.length; i += 1) {
                seedHibernatedEntry(persistedPinnedEntries[i]);
            }
        }

        if (seeded.group && seeded.frame) {
            initialFrameId = seeded.frame.frameId;
            recordModuleUse(initialTab.url);
            if (ssrGroupEl) {
                ssrGroupEl.dataset.workspaceGroup = seeded.group.groupId;
                ssrGroupEl.dataset.pinned = seeded.group.pinned ? 'true' : 'false';
            }
            if (ssrTabEl) {
                ssrTabEl.dataset.workspaceSession = seeded.frame.frameId;
                ssrTabEl.dataset.workspaceGroupTarget = seeded.group.groupId;
                ssrTabEl.dataset.pinned = seeded.group.pinned ? 'true' : 'false';
            }
            if (ssrTriggerEl) {
                ssrTriggerEl.id = `workspace-session-${seeded.frame.frameId}`;
                ssrTriggerEl.dataset.workspaceSessionSelect = seeded.frame.frameId;
                ssrTriggerEl.setAttribute('aria-controls', `workspace-panel-${seeded.frame.frameId}`);
            }
            if (ssrCloseEl) ssrCloseEl.dataset.workspaceSessionClose = seeded.frame.frameId;
            if (ssrPanelEl) {
                ssrPanelEl.id = `workspace-panel-${seeded.frame.frameId}`;
                ssrPanelEl.dataset.workspacePanel = seeded.frame.frameId;
                ssrPanelEl.setAttribute('aria-labelledby', `workspace-session-${seeded.frame.frameId}`);
            }
            if (ssrFrameEl) {
                ssrFrameEl.name = `workspace-frame-${seeded.frame.frameId}`;
                ssrFrameEl.dataset.workspaceFrame = seeded.frame.frameId;
                ssrFrameEl.dataset.workspaceNavigated = 'true';
                frames.set(seeded.frame.frameId, ssrFrameEl);
                ssrFrameEl.addEventListener('load', () => onFrameLoad(seeded.frame.frameId, ssrFrameEl));
                ssrFrameEl.addEventListener('error', () => {
                    void handleInaccessibleFrameLoad(seeded.frame.frameId, ssrFrameEl, 'iframe-error');
                });
                try {
                    const frameHref = ssrFrameEl.contentWindow?.location?.href;
                    if (ssrFrameEl.contentDocument?.readyState === 'complete' && frameHref && frameHref !== 'about:blank' && frameHref !== 'about:srcdoc') {
                        onFrameLoad(seeded.frame.frameId, ssrFrameEl);
                    }
                } catch {}
            }
        }
        notifyActiveSurface('replace');
    }

    // Session Strip clicks, drag-and-drop reorder, middle-click close, context menu, and roving keyboard navigation
    const tabStripEventsEl = tabbarEl || tablistEl;
    let suppressTabClickUntil = 0;

    // Pointer Events drag-and-drop reorder (strictly partitioned by pinned vs unpinned zone)
    let tabDragState = null;
    const flipAnimationTimers = new WeakMap();

    const stopTabDragAutoScroll = () => {
        if (tabDragState?.autoScrollRaf) {
            window.cancelAnimationFrame?.(tabDragState.autoScrollRaf);
            tabDragState.autoScrollRaf = 0;
        }
    };

    const animateFlipShift = (el, deltaX) => {
        if (!el || !deltaX || prefersReducedMotion()) return;
        const prev = flipAnimationTimers.get(el);
        if (prev) {
            window.clearTimeout?.(prev.timerId);
            el.removeEventListener('transitionend', prev.clear);
        }
        el.classList.remove('is-reorder-shifting');
        el.style.transform = `translate3d(${roundPx(deltaX)}px, 0, 0)`;
        void el.offsetWidth;
        el.classList.add('is-reorder-shifting');
        el.style.transform = '';
        const clear = () => {
            el.classList.remove('is-reorder-shifting');
            el.removeEventListener('transitionend', clear);
            if (flipAnimationTimers.get(el)?.clear === clear) {
                flipAnimationTimers.delete(el);
            }
        };
        el.addEventListener('transitionend', clear);
        const timerId = window.setTimeout?.(clear, 220) || 0;
        flipAnimationTimers.set(el, { timerId, clear });
    };

    const readActiveTransformX = (el) => {
        if (!el) return 0;
        if (tabDragState?.active && el === tabDragState.groupEl) {
            return tabDragState.currentTranslateX || 0;
        }
        const raw = window.getComputedStyle?.(el)?.transform;
        if (!raw || raw === 'none') return 0;
        if (typeof DOMMatrix === 'function') {
            try {
                const tx = new DOMMatrix(raw).m41;
                return Number.isFinite(tx) ? tx : 0;
            } catch {}
        }
        const match = raw.match(/matrix\([^,]+,[^,]+,[^,]+,[^,]+,\s*([^,]+),/);
        if (match) {
            const tx = Number(match[1]);
            return Number.isFinite(tx) ? tx : 0;
        }
        const match3d = raw.match(/matrix3d\((?:[^,]+,){12}\s*([^,]+),/);
        if (match3d) {
            const tx = Number(match3d[1]);
            return Number.isFinite(tx) ? tx : 0;
        }
        return 0;
    };

    const queryZoneGroupEls = (pinned) => Array.from(
        tablistEl.querySelectorAll(`:scope > [data-workspace-group][data-pinned="${pinned ? 'true' : 'false'}"]:not(.workspace-session-group--ghost)`),
    );

    const readUntransformedZoneBoxes = (zoneEls) => zoneEls.map((el) => {
        const rect = el.getBoundingClientRect();
        const activeTx = readActiveTransformX(el);
        return {
            el,
            visualLeft: rect.left,
            left: rect.left - activeTx,
            width: rect.width,
        };
    });

    const applyDraggedTransform = (precomputedBoxes = null) => {
        if (!tabDragState?.active) return;
        const { groupEl, pinned } = tabDragState;
        let translateX = tabDragState.lastClientX - tabDragState.anchorClientX;
        const boxes = precomputedBoxes || readUntransformedZoneBoxes(queryZoneGroupEls(pinned));
        const draggedIdx = boxes.findIndex((box) => box.el === groupEl);
        if (draggedIdx !== -1 && boxes.length > 0) {
            const firstBox = boxes[0];
            const lastBox = boxes[boxes.length - 1];
            const draggedBox = boxes[draggedIdx];
            const minTx = firstBox.left - draggedBox.left;
            const maxTx = (lastBox.left + lastBox.width) - (draggedBox.left + draggedBox.width);
            translateX = Math.max(minTx, Math.min(maxTx, translateX));
        }
        tabDragState.currentTranslateX = translateX;
        groupEl.style.transform = `translate3d(${roundPx(translateX)}px, 0, 0)`;
    };

    const evaluateTabDragSwaps = () => {
        if (!tabDragState?.active) return;
        const { groupEl, groupId, pinned } = tabDragState;
        const zoneEls = queryZoneGroupEls(pinned);
        const currentIdx = zoneEls.indexOf(groupEl);
        if (currentIdx === -1 || zoneEls.length <= 1) return;

        const beforeBoxes = readUntransformedZoneBoxes(zoneEls);
        const draggedBox = beforeBoxes[currentIdx];
        const virtualLeft = tabDragState.lastClientX - tabDragState.grabOffsetX;

        const targetIdx = resolveDragTargetZoneIndex({
            currentIndex: currentIdx,
            virtualLeft,
            virtualWidth: draggedBox.width,
            slotBoxes: beforeBoxes,
            hysteresisPx: 2,
        });

        if (targetIdx === currentIdx) return;

        const res = store.moveGroupByOffset(groupId, targetIdx - currentIdx);
        if (!res.moved) return;

        const beforeBoxMap = new Map(beforeBoxes.map((box) => [box.el, box]));
        const hadLastPinnedDivider = pinned && zoneEls.some((el) => el.classList.contains('is-last-pinned'));
        const activeEl = document.activeElement;
        const focusedInsideDragged = activeEl && groupEl.contains(activeEl) ? activeEl : null;

        const targetEl = zoneEls[targetIdx];
        if (targetIdx > currentIdx) {
            targetEl.after(groupEl);
        } else {
            targetEl.before(groupEl);
        }

        if (focusedInsideDragged && document.activeElement !== focusedInsideDragged) {
            focusedInsideDragged.focus?.({ preventScroll: true });
        }

        const afterZoneEls = queryZoneGroupEls(pinned);
        if (hadLastPinnedDivider) {
            afterZoneEls.forEach((el, idx) => {
                el.classList.toggle('is-last-pinned', idx === afterZoneEls.length - 1);
            });
        }

        const afterBoxes = readUntransformedZoneBoxes(afterZoneEls);
        const afterDraggedBox = afterBoxes.find((box) => box.el === groupEl);
        if (afterDraggedBox) {
            tabDragState.anchorClientX += afterDraggedBox.left - draggedBox.left;
        }
        tabDragState.moved = true;

        afterBoxes.forEach((afterBox) => {
            if (afterBox.el === groupEl) return;
            const prevBox = beforeBoxMap.get(afterBox.el);
            if (!prevBox) return;
            if (Math.abs(afterBox.left - prevBox.left) >= 0.5) {
                animateFlipShift(afterBox.el, prevBox.visualLeft - afterBox.left);
            }
        });

        applyDraggedTransform(afterBoxes);
    };

    const runTabDragAutoScroll = () => {
        if (!tabDragState?.active || !tablistEl) return;
        const maxScroll = tablistEl.scrollWidth - tablistEl.clientWidth;
        if (maxScroll <= 0) {
            tabDragState.autoScrollRaf = 0;
            return;
        }
        const listRect = tablistEl.getBoundingClientRect();
        const edgeZone = 36;
        let step = 0;
        if (tabDragState.lastClientX < listRect.left + edgeZone) {
            step = -Math.min(18, Math.ceil(((listRect.left + edgeZone - tabDragState.lastClientX) / edgeZone) * 10));
        } else if (tabDragState.lastClientX > listRect.right - edgeZone) {
            step = Math.min(18, Math.ceil(((tabDragState.lastClientX - (listRect.right - edgeZone)) / edgeZone) * 10));
        }
        if (step !== 0) {
            const prevScroll = tablistEl.scrollLeft;
            tablistEl.scrollLeft = Math.max(0, Math.min(maxScroll, prevScroll + step));
            const appliedDelta = tablistEl.scrollLeft - prevScroll;
            if (appliedDelta !== 0) {
                tabDragState.anchorClientX -= appliedDelta;
                applyDraggedTransform();
                evaluateTabDragSwaps();
                tabDragState.autoScrollRaf = window.requestAnimationFrame?.(runTabDragAutoScroll) || 0;
                return;
            }
        }
        tabDragState.autoScrollRaf = 0;
    };

    const activateTabDrag = () => {
        if (!tabDragState || tabDragState.active) return;
        const groupRect = tabDragState.groupEl.getBoundingClientRect();
        tabDragState.active = true;
        tabDragState.anchorClientX = tabDragState.lastClientX;
        tabDragState.grabOffsetX = tabDragState.lastClientX - groupRect.left;
        tabDragState.currentTranslateX = 0;
        closeTabMenu({ immediate: true });
        tabDragState.groupEl.classList.add('is-dragging');
        document.body?.classList?.add('is-dragging-workspace-session');
        tablistEl.dataset.draggingZone = tabDragState.pinned ? 'pinned' : 'unpinned';
        try {
            tablistEl.setPointerCapture?.(tabDragState.pointerId);
        } catch {}
    };

    const finishTabDrag = (event = null) => {
        if (!tabDragState) return;
        const state = tabDragState;
        tabDragState = null;
        if (state.touchTimer) window.clearTimeout?.(state.touchTimer);
        if (state.autoScrollRaf) window.cancelAnimationFrame?.(state.autoScrollRaf);

        if (state.active) {
            suppressTabClickUntil = (typeof performance !== 'undefined' ? performance.now() : Date.now()) + 180;
            state.groupEl.classList.remove('is-dragging');
            state.groupEl.style.transform = '';
            document.body?.classList?.remove('is-dragging-workspace-session');
            delete tablistEl.dataset.draggingZone;
            const captureId = event?.pointerId ?? state.pointerId;
            if (captureId !== undefined) {
                try {
                    if (tablistEl.hasPointerCapture?.(captureId) ?? true) {
                        tablistEl.releasePointerCapture?.(captureId);
                    }
                } catch {}
            }
            if (state.moved) {
                if (state.pinned) persistPinnedGroups();
                syncTabDom();
                announceGroupPosition(state.groupId);
            }
        }
    };

    tablistEl.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch' && event.button !== 0) return;
        if (event.target.closest('[data-workspace-session-close], [data-workspace-group-menu]')) return;
        const groupEl = event.target.closest('[data-workspace-group]');
        if (!groupEl || groupEl.classList.contains('workspace-session-group--ghost')) return;
        const groupId = groupEl.dataset.workspaceGroup;
        if (!groupId || groupId === SHORTCUTS_KEY) return;
        const bounds = store.canMoveGroup(groupId);
        if (bounds.zoneTotal <= 1) return;

        tabDragState = {
            groupId,
            groupEl,
            pinned: bounds.pinned,
            pointerId: event.pointerId,
            pointerType: event.pointerType,
            startClientX: event.clientX,
            startClientY: event.clientY,
            anchorClientX: event.clientX,
            lastClientX: event.clientX,
            grabOffsetX: 0,
            currentTranslateX: 0,
            active: false,
            moved: false,
            touchTimer: 0,
            autoScrollRaf: 0,
        };

        if (event.pointerType === 'touch') {
            tabDragState.touchTimer = window.setTimeout?.(() => {
                activateTabDrag();
            }, 180) || 0;
        }
    });

    tablistEl.addEventListener('pointermove', (event) => {
        if (!tabDragState || event.pointerId !== tabDragState.pointerId) return;
        tabDragState.lastClientX = event.clientX;
        const dx = event.clientX - tabDragState.startClientX;
        const dy = event.clientY - tabDragState.startClientY;

        if (!tabDragState.active) {
            if (tabDragState.pointerType === 'touch') {
                if (Math.hypot(dx, dy) > 8) {
                    finishTabDrag();
                }
                return;
            }
            if (Math.abs(dy) > Math.abs(dx) * 1.5 && Math.abs(dy) > 8) {
                finishTabDrag();
                return;
            }
            if (Math.abs(dx) < 6) return;
            activateTabDrag();
        }

        event.preventDefault();
        applyDraggedTransform();
        evaluateTabDragSwaps();

        if (!tabDragState.autoScrollRaf && tablistEl && tablistEl.scrollWidth > tablistEl.clientWidth) {
            const listRect = tablistEl.getBoundingClientRect();
            if (event.clientX < listRect.left + 36 || event.clientX > listRect.right - 36) {
                tabDragState.autoScrollRaf = window.requestAnimationFrame?.(runTabDragAutoScroll) || 0;
            }
        }
    });

    tablistEl.addEventListener('touchmove', (event) => {
        if (tabDragState?.active) event.preventDefault();
    }, { passive: false });

    tablistEl.addEventListener('pointerup', (event) => {
        if (tabDragState && event.pointerId === tabDragState.pointerId) {
            finishTabDrag(event);
        }
    });

    tablistEl.addEventListener('pointercancel', (event) => {
        if (tabDragState && event.pointerId === tabDragState.pointerId) {
            finishTabDrag(event);
        }
    });

    tablistEl.addEventListener('lostpointercapture', (event) => {
        if (tabDragState?.active && (!event?.target || event.target === tablistEl)) {
            finishTabDrag();
        }
    });

    tabStripEventsEl.addEventListener('click', (event) => {
        const nowMs = typeof performance !== 'undefined' ? performance.now() : Date.now();
        if (nowMs < suppressTabClickUntil) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        const menuBtn = event.target.closest('[data-workspace-group-menu]');
        if (menuBtn) {
            event.preventDefault();
            const gid = menuBtn.dataset.workspaceGroupMenu;
            if (tabMenuEl && !tabMenuEl.hidden && lastMenuOpener === menuBtn && !tabMenuOpenedByHover) {
                closeTabMenu({ restoreFocus: true });
            } else {
                openGroupPagesMenu({
                    groupId: gid,
                    anchorEl: menuBtn,
                    focusMenu: event.detail === 0,
                    openedByHover: false,
                });
            }
            return;
        }

        const closeBtn = event.target.closest('[data-workspace-session-close]');
        if (closeBtn) {
            event.preventDefault();
            const fid = closeBtn.dataset.workspaceSessionClose;
            if (fid) requestCloseFrame(fid);
            return;
        }

        if (event.target.closest('[data-workspace-shortcuts-select]')) {
            activateShortcuts();
            return;
        }

        const groupBtn = event.target.closest('[data-workspace-group-select]');
        if (groupBtn) {
            activateGroup(groupBtn.dataset.workspaceGroupSelect, { triggerEl: groupBtn });
            return;
        }

        const selectBtn = event.target.closest('[data-workspace-session-select]');
        if (selectBtn) {
            activateFrame(selectBtn.dataset.workspaceSessionSelect, { triggerEl: selectBtn });
        }
    });

    tabStripEventsEl.addEventListener('mouseover', (event) => {
        const menuBtn = event.target.closest('[data-workspace-group-menu]');
        if (!menuBtn || menuBtn.contains(event.relatedTarget)) return;
        clearHoverMenuCloseTimer();
        const gid = menuBtn.dataset.workspaceGroupMenu;
        if (tabMenuEl && !tabMenuEl.hidden && lastMenuOpener === menuBtn) {
            return;
        }
        openGroupPagesMenu({ groupId: gid, anchorEl: menuBtn, focusMenu: false, openedByHover: true });
    });

    tabStripEventsEl.addEventListener('mouseout', (event) => {
        const menuBtn = event.target.closest('[data-workspace-group-menu]');
        if (!menuBtn || menuBtn.contains(event.relatedTarget)) return;
        if (tabMenuEl && (event.relatedTarget === tabMenuEl || tabMenuEl.contains(event.relatedTarget))) {
            clearHoverMenuCloseTimer();
            return;
        }
        scheduleHoverMenuClose();
    });

    tabMenuEl?.addEventListener('mouseenter', () => {
        clearHoverMenuCloseTimer();
    });

    tabMenuEl?.addEventListener('mouseleave', (event) => {
        if (lastMenuOpener && (event.relatedTarget === lastMenuOpener || lastMenuOpener.contains?.(event.relatedTarget))) {
            clearHoverMenuCloseTimer();
            return;
        }
        scheduleHoverMenuClose();
    });

    tabStripEventsEl.addEventListener('auxclick', (event) => {
        if (event.button !== 1) return;
        const tabEl = event.target.closest('[data-workspace-session]');
        if (!tabEl || tabEl.dataset.workspaceSession === SHORTCUTS_KEY) return;
        event.preventDefault();
        const gid = tabEl.dataset.workspaceGroupTarget;
        if (tabEl.classList.contains('workspace-session--stacked') && gid) {
            requestCloseGroup(gid);
        } else {
            requestCloseFrame(tabEl.dataset.workspaceSession);
        }
    });

    tabStripEventsEl.addEventListener('contextmenu', (event) => {
        const tabEl = event.target.closest('[data-workspace-session]');
        if (!tabEl) return;
        event.preventDefault();
        const tabId = tabEl.dataset.workspaceSession;
        if (tabId === SHORTCUTS_KEY) {
            openTabMenu({ groupId: SHORTCUTS_KEY, anchorEl: tabEl, clientX: event.clientX, clientY: event.clientY });
            return;
        }
        const gid = tabEl.dataset.workspaceGroupTarget;
        const fid = tabEl.classList.contains('workspace-session--stacked') ? null : tabId;
        openTabMenu({ groupId: gid, frameId: fid, anchorEl: tabEl, clientX: event.clientX, clientY: event.clientY });
    });

    tabStripEventsEl.addEventListener('keydown', (event) => {
        const triggerBtn = event.target.closest('[role="tab"], [data-workspace-group-menu]');
        if (!triggerBtn) return;

        if ((event.shiftKey && event.key === 'F10') || event.key === 'ContextMenu') {
            event.preventDefault();
            const tabEl = triggerBtn.closest('[data-workspace-session]');
            if (!tabEl) return;
            const tabId = tabEl.dataset.workspaceSession;
            if (tabId === SHORTCUTS_KEY) {
                openTabMenu({ groupId: SHORTCUTS_KEY, anchorEl: triggerBtn });
                return;
            }
            const gid = tabEl.dataset.workspaceGroupTarget;
            const fid = tabEl.classList.contains('workspace-session--stacked') ? null : tabId;
            openTabMenu({ groupId: gid, frameId: fid, anchorEl: triggerBtn });
            return;
        }

        if (triggerBtn.matches('[data-workspace-group-menu]')) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const gid = triggerBtn.dataset.workspaceGroupMenu;
                if (gid) {
                    openGroupPagesMenu({ groupId: gid, anchorEl: triggerBtn, focusMenu: true, openedByHover: false });
                }
                return;
            }
            if (event.key === 'Escape' && tabMenuEl && !tabMenuEl.hidden) {
                event.preventDefault();
                closeTabMenu({ restoreFocus: true });
                return;
            }
        }

        if (!triggerBtn.matches('[role="tab"]')) return;

        if (event.altKey && event.shiftKey && !event.ctrlKey && !event.metaKey) {
            const tabEl = triggerBtn.closest('[data-workspace-session]');
            const gid = tabEl?.dataset?.workspaceGroupTarget;
            if (tabEl && tabEl.dataset.workspaceSession !== SHORTCUTS_KEY && gid) {
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    requestMoveGroupByOffset(gid, -1, { refocus: true });
                    return;
                }
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    requestMoveGroupByOffset(gid, 1, { refocus: true });
                    return;
                }
                if (event.key === 'Home') {
                    event.preventDefault();
                    const bounds = store.canMoveGroup(gid);
                    if (bounds.zoneTotal > 1) {
                        requestMoveGroupByOffset(gid, -bounds.zoneTotal, { refocus: true });
                    }
                    return;
                }
                if (event.key === 'End') {
                    event.preventDefault();
                    const bounds = store.canMoveGroup(gid);
                    if (bounds.zoneTotal > 1) {
                        requestMoveGroupByOffset(gid, bounds.zoneTotal, { refocus: true });
                    }
                    return;
                }
                if (event.code === 'KeyP' || event.key.toLowerCase() === 'p') {
                    event.preventDefault();
                    requestTogglePinGroup(gid, { refocus: true });
                    return;
                }
            }
        }

        const tabs = Array.from(tabStripEventsEl.querySelectorAll('[role="tab"]'));
        const idx = tabs.indexOf(triggerBtn);
        if (idx === -1) return;

        let targetIdx = null;
        if (event.key === 'ArrowRight') targetIdx = (idx + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') targetIdx = (idx - 1 + tabs.length) % tabs.length;
        else if (event.key === 'Home') targetIdx = 0;
        else if (event.key === 'End') targetIdx = tabs.length - 1;
        else if (event.key === 'Delete' || event.key === 'Backspace') {
            const tabEl = triggerBtn.closest('[data-workspace-session]');
            if (tabEl && tabEl.dataset.workspaceSession !== SHORTCUTS_KEY) {
                event.preventDefault();
                if (tabEl.classList.contains('workspace-session--stacked')) {
                    requestCloseGroup(tabEl.dataset.workspaceGroupTarget);
                } else {
                    requestCloseFrame(tabEl.dataset.workspaceSession);
                }
            }
            return;
        }

        if (targetIdx !== null) {
            event.preventDefault();
            const nextBtn = tabs[targetIdx];
            if (nextBtn.hasAttribute('data-workspace-shortcuts-select')) {
                activateShortcuts({ focusTrigger: true });
            } else if (nextBtn.hasAttribute('data-workspace-group-select')) {
                activateGroup(nextBtn.dataset.workspaceGroupSelect, { focusTrigger: true });
            } else if (nextBtn.hasAttribute('data-workspace-session-select')) {
                activateFrame(nextBtn.dataset.workspaceSessionSelect, { focusTrigger: true });
            }
        }
    });

    tabMenuEl?.addEventListener('keydown', (event) => {
        if (activeMenuContext?.kind === 'group-pages') {
            const rows = Array.from(tabMenuEl.querySelectorAll('[data-workspace-group-page-row]'));
            if (!rows.length) return;
            const currentRow = document.activeElement?.closest?.('[data-workspace-group-page-row]') || null;
            const rowIdx = currentRow ? rows.indexOf(currentRow) : -1;
            const isOnClose = Boolean(document.activeElement?.hasAttribute?.('data-workspace-group-page-close'));

            const focusRow = (targetIdx, preferClose = false) => {
                const targetRow = rows[targetIdx];
                if (!targetRow) return;
                const targetClose = preferClose ? targetRow.querySelector('[data-workspace-group-page-close]') : null;
                const targetSelect = targetRow.querySelector('[data-workspace-group-page-select]');
                (targetClose || targetSelect)?.focus();
            };

            if (event.key === 'Escape') {
                event.preventDefault();
                closeTabMenu({ restoreFocus: true });
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusRow((rowIdx + 1) % rows.length, isOnClose);
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusRow((rowIdx - 1 + rows.length) % rows.length, isOnClose);
                return;
            }
            if (event.key === 'ArrowRight' && currentRow && !isOnClose) {
                const closeBtn = currentRow.querySelector('[data-workspace-group-page-close]');
                if (closeBtn) {
                    event.preventDefault();
                    closeBtn.focus();
                }
                return;
            }
            if (event.key === 'ArrowLeft' && currentRow && isOnClose) {
                const selectBtn = currentRow.querySelector('[data-workspace-group-page-select]');
                if (selectBtn) {
                    event.preventDefault();
                    selectBtn.focus();
                }
                return;
            }
            if (event.key === 'Home') {
                event.preventDefault();
                focusRow(0, false);
                return;
            }
            if (event.key === 'End') {
                event.preventDefault();
                focusRow(rows.length - 1, false);
                return;
            }
            if ((event.key === 'Delete' || event.key === 'Backspace') && currentRow) {
                const closeBtn = currentRow.querySelector('[data-workspace-group-page-close]');
                if (closeBtn) {
                    event.preventDefault();
                    closeBtn.click();
                }
                return;
            }
            if (event.key === 'Tab') {
                closeTabMenu();
            }
            return;
        }

        const items = Array.from(tabMenuEl.querySelectorAll(MENU_ITEM_SELECTOR));
        if (!items.length) return;
        const idx = items.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            closeTabMenu({ restoreFocus: true });
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            items[(idx + 1) % items.length].focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            items[(idx - 1 + items.length) % items.length].focus();
        } else if (event.key === 'Home') {
            event.preventDefault();
            items[0].focus();
        } else if (event.key === 'End') {
            event.preventDefault();
            items[items.length - 1].focus();
        } else if (event.key === 'Tab') {
            closeTabMenu();
        }
    });

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Element ? event.target : event.target?.parentElement;
        if (
            tabMenuEl
            && !tabMenuEl.hidden
            && !tabMenuEl.contains(target)
            && !target?.closest?.('[data-workspace-group-menu], [data-workspace-switcher]')
        ) {
            closeTabMenu();
        }
        dismissEmbedOverlays();
    }, true);

    document.addEventListener('keydown', (event) => {
        if (
            event.defaultPrevented
            || event.key !== 'Escape'
            || !tabMenuEl
            || tabMenuEl.hidden
            || tabMenuEl.classList.contains('is-closing')
        ) {
            return;
        }
        event.preventDefault();
        closeTabMenu({ restoreFocus: !tabMenuOpenedByHover });
    });

    document.addEventListener('adasi:dropdown-show', (event) => {
        const openingToggle = event.target instanceof Element ? event.target : null;
        closeTabMenu();
        window.AdasiDismissDropdowns?.(openingToggle);
        window.AdasiCalendar?.closeActive?.();
        window.dispatchEvent(new CustomEvent('adasi:dismiss-overlays'));
        dismissEmbedOverlays();
    });

    window.addEventListener('blur', () => {
        try {
            const active = document.activeElement;
            if (active && active.tagName === 'IFRAME') {
                dismissFloatingOverlays({ restoreFocus: false, excludeEmbedWindow: active.contentWindow });
            }
        } catch {}
    });

    // Seed initial active module once into Frequently Used when usage history does not exist yet
    try {
        if (window.localStorage?.getItem(usageStorageKey) === null && initialTab?.url) {
            const initialNp = normalizedPath(initialTab.url, origin);
            if (catalog.some((item) => item.normalizedPath === initialNp)) {
                recordModuleUse(initialTab.url);
            }
        }
    } catch {}

    // Shortcuts 3-pane bottom-docked minimize/restore & cursor-tracking dual vertical splitters
    applyShortcutsLayout(shortcutsLayout);
    renderShortcuts();

    const setPaneMinimized = (paneKey, shouldMinimize, { focusTarget = 'auto' } = {}) => {
        shortcutsLayout.minimized[paneKey] = shouldMinimize;
        const nextOrder = (shortcutsLayout.minimizedOrder || []).filter((key) => key !== paneKey);
        if (shouldMinimize) {
            nextOrder.push(paneKey);
        }
        shortcutsLayout.minimizedOrder = nextOrder;
        applyShortcutsLayout(shortcutsLayout);
        writeJsonStorage(shortcutsLayoutStorageKey, shortcutsLayout);
        scheduleShortcutsSync();

        if (focusTarget === 'dock' || (focusTarget === 'auto' && shouldMinimize)) {
            const restoreBtn = shortcutsRestoreEls[paneKey];
            if (restoreBtn && !restoreBtn.hidden) {
                restoreBtn.focus();
            }
        } else if (focusTarget === 'pane' || (focusTarget === 'auto' && !shouldMinimize)) {
            const toggleBtn = shortcutsPaneToggleEls[paneKey];
            if (toggleBtn && shortcutsPaneEls[paneKey] && !shortcutsPaneEls[paneKey].classList.contains('is-minimized')) {
                toggleBtn.focus();
            }
        }
    };

    for (const paneKey of SHORTCUTS_PANE_KEYS) {
        const toggleBtn = shortcutsPaneToggleEls[paneKey];
        toggleBtn?.addEventListener('click', () => {
            setPaneMinimized(paneKey, !shortcutsLayout.minimized[paneKey], { focusTarget: 'auto' });
        });

        const restoreBtn = shortcutsRestoreEls[paneKey];
        restoreBtn?.addEventListener('click', () => {
            setPaneMinimized(paneKey, false, { focusTarget: 'pane' });
        });
    }

    for (const splitterKey of shortcutsSplitterKeys) {
        const splitterEl = shortcutsSplitterEls[splitterKey];
        if (!splitterEl || !shortcutsBoardEl) continue;

        let dragState = null;

        const endSplitterDrag = (event) => {
            if (!dragState) return;
            dragState = null;
            splitterEl.classList.remove('is-dragging');
            shortcutsBoardEl.classList.remove('is-resizing');
            document.body?.classList?.remove('is-resizing-shortcuts');
            if (event?.pointerId !== undefined) {
                try { splitterEl.releasePointerCapture?.(event.pointerId); } catch {}
            }
            writeJsonStorage(shortcutsLayoutStorageKey, shortcutsLayout);
            scheduleShortcutsSync();
        };

        splitterEl.addEventListener('pointerenter', (event) => {
            syncSplitterGripY(splitterEl, event.clientY);
        });

        splitterEl.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || splitterEl.getAttribute('aria-disabled') === 'true') return;
            const boardWidthPx = getBoardWidthPx();
            if (boardWidthPx <= 0) return;

            event.preventDefault();
            syncSplitterGripY(splitterEl, event.clientY);
            applyShortcutsLayout(shortcutsLayout);
            dragState = {
                startClientX: event.clientX,
                startWeights: { ...shortcutsLayout.weights },
                boardWidthPx,
            };
            splitterEl.classList.add('is-dragging');
            shortcutsBoardEl.classList.add('is-resizing');
            document.body?.classList?.add('is-resizing-shortcuts');
            try { splitterEl.setPointerCapture?.(event.pointerId); } catch {}
        });

        splitterEl.addEventListener('pointermove', (event) => {
            syncSplitterGripY(splitterEl, event.clientY);
            if (!dragState) return;
            const deltaPx = event.clientX - dragState.startClientX;
            const res = resizeShortcutsPairByDelta({
                weights: dragState.startWeights,
                minimized: shortcutsLayout.minimized,
                splitterKey,
                deltaPx,
                boardWidthPx: dragState.boardWidthPx,
            });
            if (res.pair) {
                shortcutsLayout.weights = res.weights;
                applyShortcutsLayout(shortcutsLayout);
            }
        });

        splitterEl.addEventListener('pointerup', endSplitterDrag);
        splitterEl.addEventListener('pointercancel', endSplitterDrag);

        splitterEl.addEventListener('dblclick', () => {
            if (splitterEl.getAttribute('aria-disabled') === 'true') return;
            const pair = resolveShortcutsSplitterPair(splitterKey, shortcutsLayout.minimized);
            if (!pair) return;
            const [leftKey, rightKey] = pair;
            const pairWeightSum = Number(shortcutsLayout.weights[leftKey] || 0)
                + Number(shortcutsLayout.weights[rightKey] || 0);
            const defaultSum = DEFAULT_SHORTCUTS_WEIGHTS[leftKey] + DEFAULT_SHORTCUTS_WEIGHTS[rightKey];
            const nextLeft = pairWeightSum * (DEFAULT_SHORTCUTS_WEIGHTS[leftKey] / defaultSum);
            shortcutsLayout.weights[leftKey] = nextLeft;
            shortcutsLayout.weights[rightKey] = pairWeightSum - nextLeft;
            applyShortcutsLayout(shortcutsLayout);
            writeJsonStorage(shortcutsLayoutStorageKey, shortcutsLayout);
            scheduleShortcutsSync();
        });

        splitterEl.addEventListener('keydown', (event) => {
            if (splitterEl.getAttribute('aria-disabled') === 'true') return;
            const boardWidthPx = getBoardWidthPx() || 1000;
            let deltaPx = null;
            if (event.key === 'ArrowLeft') deltaPx = -SHORTCUTS_KEYBOARD_STEP_PX;
            else if (event.key === 'ArrowRight') deltaPx = SHORTCUTS_KEYBOARD_STEP_PX;
            else if (event.key === 'Home') deltaPx = -boardWidthPx;
            else if (event.key === 'End') deltaPx = boardWidthPx;
            if (deltaPx === null) return;

            event.preventDefault();
            const res = resizeShortcutsPairByDelta({
                weights: shortcutsLayout.weights,
                minimized: shortcutsLayout.minimized,
                splitterKey,
                deltaPx,
                boardWidthPx,
            });
            if (res.changed) {
                shortcutsLayout.weights = res.weights;
                applyShortcutsLayout(shortcutsLayout);
                writeJsonStorage(shortcutsLayoutStorageKey, shortcutsLayout);
                scheduleShortcutsSync();
            }
        });
    }

    if (shortcutsBoardEl) {
        if (typeof window.ResizeObserver === 'function') {
            const shortcutsRo = new window.ResizeObserver(() => {
                applyShortcutsLayout(shortcutsLayout);
            });
            shortcutsRo.observe(shortcutsBoardEl);
        } else {
            window.addEventListener('resize', () => applyShortcutsLayout(shortcutsLayout));
        }
    }

    scrollPrevBtn?.addEventListener('click', () => scrollTabStrip(-1));
    scrollNextBtn?.addEventListener('click', () => scrollTabStrip(1));
    switcherBtn?.addEventListener('click', () => {
        if (tabMenuEl && !tabMenuEl.hidden && lastMenuOpener === switcherBtn) {
            closeTabMenu({ restoreFocus: true });
        } else {
            openWorkspaceSwitcherMenu(switcherBtn);
        }
    });

    let tablistScrollEndTimer = 0;
    tablistEl.addEventListener('scroll', () => {
        updateTabOverflowState();
        if (!('onscrollend' in window)) {
            window.clearTimeout(tablistScrollEndTimer);
            tablistScrollEndTimer = window.setTimeout(() => updateTabOverflowState({ fromSettle: true }), 120);
        }
    }, { passive: true });
    if ('onscrollend' in window) {
        tablistEl.addEventListener('scrollend', () => updateTabOverflowState({ fromSettle: true }), { passive: true });
    }
    tablistEl.addEventListener('transitionend', (event) => {
        if (event.target?.classList?.contains?.('workspace-session__close') && event.propertyName === 'width') {
            const tab = event.target.closest('.workspace-session');
            if (tab?.matches?.(':hover, :has(:focus-visible)')) {
                tab.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
            }
            updateTabOverflowState();
        } else if (event.target?.classList?.contains?.('workspace-session') && event.propertyName === 'max-width') {
            updateTabOverflowState({ fromSettle: !resizeSettleTimer });
        }
    });
    const handleTabStripResize = () => {
        recomputeDynamicTabCap();
        scheduleCapSettleCheck();
        updateTabOverflowState();
    };
    if (typeof window.ResizeObserver === 'function') {
        const ro = new window.ResizeObserver(handleTabStripResize);
        ro.observe(tablistEl);
    } else {
        window.addEventListener('resize', handleTabStripResize);
    }

    refreshBtn?.addEventListener('click', () => {
        const active = store.activeFrame();
        if (active) requestRefreshFrame(active.frameId);
    });

    tablistEl.addEventListener('wheel', (event) => {
        if (Math.abs(event.deltaY) > Math.abs(event.deltaX) && tablistEl.scrollWidth > tablistEl.clientWidth) {
            tablistEl.scrollLeft += event.deltaY;
            event.preventDefault();
        }
    }, { passive: false });

    staleBtn?.addEventListener('click', () => {
        const active = store.activeFrame();
        if (active) requestRefreshFrame(active.frameId);
    });

    // Sidebar click interception (capture phase): always opens a new workspace instance per Infor LN spec.
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = event.target?.closest?.('#sidebar a[href]');
        if (!anchor) return;
        if (anchor.hasAttribute('download') || anchor.getAttribute('target') === '_blank') return;

        const target = resolveLinkTarget(anchor.href);
        const url = target.url || anchor.href;
        if (!isSameOriginUrl(url, origin)) return;
        if (isTopLevelOnlyUrl(url, origin, pathPrefix)) return;

        event.preventDefault();
        window.dispatchEvent(new CustomEvent('ui-sidebar-close'));
        const label = anchor.querySelector('.sidebar-link-label')?.textContent?.trim()
            || anchor.dataset.sidebarTooltip
            || anchor.getAttribute('title')
            || anchor.textContent.trim();

        openNewWorkspace({
            moduleKey: target.key || resolveModuleForUrl(url),
            label,
            url,
            triggerEl: anchor,
        });
    }, true);

    // Topbar link interception (bubble phase so drawer/AJAX handlers run first).
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = event.target?.closest?.('.top-navbar a[href]');
        if (!anchor) return;
        if (anchor.hasAttribute('download') || anchor.getAttribute('target') === '_blank' || anchor.hasAttribute('data-chat-drawer') || anchor.hasAttribute('data-ui-dropdown-trigger') || anchor.hasAttribute('data-ui-tab') || anchor.hasAttribute('popovertarget') || anchor.hasAttribute('data-ui-modal-open')) {
            return;
        }

        const target = resolveLinkTarget(anchor.href);
        const url = target.url || anchor.href;
        if (!isSameOriginUrl(url, origin)) return;
        if (isTopLevelOnlyUrl(url, origin, pathPrefix)) return;

        event.preventDefault();
        const label = anchor.getAttribute('title')
            || anchor.getAttribute('aria-label')
            || anchor.textContent.trim();

        openNewWorkspace({
            moduleKey: target.key || resolveModuleForUrl(url),
            label,
            url,
            triggerEl: anchor,
        });
    });

    window.addEventListener('popstate', (event) => {
        const nav = resolvePopstateNavigation({
            eventState: event.state,
            currentHref: window.location.href,
            hash: window.location.hash,
            origin,
            contextLinks: config.contextLinks,
            menuPathTitles,
            hasLiveFrame: (id) => Boolean(store.getFrame(id)),
        });

        if (nav.kind === 'shortcuts') {
            activateShortcuts({ historyMode: 'skip' });
            return;
        }
        if (nav.kind === 'activate-frame') {
            activateFrame(nav.frameId, { historyMode: 'skip' });
            return;
        }
        if (nav.kind !== 'open-url') {
            return;
        }

        if (isTopLevelOnlyUrl(nav.url, origin, pathPrefix)) {
            breakoutToTop(nav.url);
            return;
        }

        openNewWorkspace({
            label: nav.label,
            url: nav.url,
            historyMode: nav.historyMode,
        });
    });

    // Sync CSRF token refreshes across every open keep-alive iframe
    window.addEventListener('adasi:session-restored', (event) => {
        const token = event?.token || event?.detail?.token;
        if (!token) return;
        frames.forEach((iframe) => {
            try {
                iframe.contentWindow?.AdasiSessionSync?.applyExternalToken?.(token);
            } catch {}
        });
    });

    const parseWorkspaceConfigFromDocument = (doc) => {
        const scriptEl = doc?.getElementById?.('adasi-workspace-shell-config');
        if (!scriptEl?.textContent) return null;
        try {
            const parsed = JSON.parse(scriptEl.textContent);
            return (parsed && typeof parsed === 'object') ? parsed : null;
        } catch {
            return null;
        }
    };

    const applyTranslatedMetadataToGroups = () => {
        for (const group of store.groups()) {
            const parentFrame = group.frames[0];
            if (parentFrame) {
                const np = normalizedPath(parentFrame.url, origin);
                const mappedTitle = menuPathTitles.get(np);
                const catalogEntry = catalog.find((item) => item.key === group.moduleKey);
                const nextParentLabel = mappedTitle
                    || (catalogEntry?.label && indexPathSet.has(np) ? catalogEntry.label : null);

                if (nextParentLabel) {
                    parentFrame.title = nextParentLabel;
                    group.baseLabel = nextParentLabel;
                } else if (catalogEntry?.label && group.baseLabel) {
                    group.baseLabel = catalogEntry.label;
                }
            }

            for (const frame of group.frames) {
                if (frame.hibernated) {
                    frame.stale = true;
                }
            }
        }
    };

    const forceRebuildAllTabs = () => {
        const scLabelEl = shortcutsSelectBtn?.querySelector?.('.workspace-session__label');
        if (scLabelEl && i18n.shortcuts) {
            scLabelEl.textContent = i18n.shortcuts;
        }
        tablistEl.querySelectorAll('[data-workspace-group]').forEach((el) => {
            delete el.dataset.renderSig;
            delete el.dataset.layoutSig;
        });
        tablistEl.querySelectorAll('.workspace-session').forEach((pill) => {
            delete pill.dataset.naturalWidth;
            pill.style.removeProperty('--workspace-session-natural-width');
        });
        notifyActiveSurface('replace');
    };

    // Translate workspace chrome, tabs, shortcuts, config maps, and prioritize active embed before staggered background sync.
    window.addEventListener('adasi:locale-changed', async (event) => {
        const seq = ++localeSyncSeq;
        const detail = event?.detail || {};
        const nextLocale = detail.locale || document.documentElement?.lang || 'en';
        const nextConfig = detail.workspace_config || parseWorkspaceConfigFromDocument(detail.document);

        if (nextConfig) {
            const nextI18n = nextConfig.i18n || {};
            Object.keys(i18n).forEach((key) => {
                if (!(key in nextI18n)) delete i18n[key];
            });
            Object.assign(i18n, nextI18n);
            config.i18n = i18n;
            if (nextConfig.menuPathTitles) config.menuPathTitles = nextConfig.menuPathTitles;
            if (nextConfig.contextLinks) config.contextLinks = nextConfig.contextLinks;
            if (nextConfig.quickAccessPaths) config.quickAccessPaths = nextConfig.quickAccessPaths;
            if (nextConfig.shortcutsSession) config.shortcutsSession = nextConfig.shortcutsSession;
            if (nextConfig.indexPaths) {
                indexPaths.splice(0, indexPaths.length, ...nextConfig.indexPaths);
                config.indexPaths = indexPaths;
            }
        }

        syncNavigationCatalogMetadata(nextConfig || config);
        applyTranslatedMetadataToGroups();
        applyShortcutsLayout(shortcutsLayout);
        renderShortcuts();
        closeTabMenu();
        forceRebuildAllTabs();

        const syncPayload = {
            locale: nextLocale,
            i18n: detail.i18n || null,
            datatables: detail.datatables || null,
            session_copy: detail.session_copy || null,
            pagination_label: detail.pagination_label || null,
        };
        latestLocalePayload = syncPayload;
        pendingLocaleSyncFrames.clear();

        const activeFrameId = store.activeFrame()?.frameId || null;
        let activeIframe = null;
        const backgroundFrameIds = [];

        frames.forEach((iframe, frameId) => {
            if (frameId === activeFrameId) {
                activeIframe = iframe;
                return;
            }
            try {
                const childWin = iframe.contentWindow;
                if (!childWin || !isSameOriginUrl(childWin.location?.href, origin)) return;
                // Hot-swap JS dictionaries immediately with zero network cost while DOM morphing waits in queue
                childWin.AdasiLocaleSwitcher?.syncLocaleDictionaries?.(syncPayload);
            } catch {}
            pendingLocaleSyncFrames.set(frameId, syncPayload);
            backgroundFrameIds.push(frameId);
        });

        // Priority 1: Sync active visible tab immediately with zero background contention
        if (activeFrameId && activeIframe) {
            await syncFrameLocale(activeFrameId, activeIframe, syncPayload, { seq });
            if (seq !== localeSyncSeq) return;
        }

        // Priority 2: Drain remaining background tabs sequentially (1 at a time) so UI & server stay responsive
        for (const frameId of backgroundFrameIds) {
            if (seq !== localeSyncSeq) return;
            if (!pendingLocaleSyncFrames.has(frameId)) continue;

            await new Promise((resolve) => window.setTimeout(resolve, 60));
            if (seq !== localeSyncSeq) return;
            if (!pendingLocaleSyncFrames.has(frameId)) continue;

            const bgIframe = frames.get(frameId);
            if (!bgIframe) {
                pendingLocaleSyncFrames.delete(frameId);
                continue;
            }
            await syncFrameLocale(frameId, bgIframe, syncPayload, { seq });
        }
    });

    const frameIdForWindow = (frameWin) => {
        for (const [frameId, iframe] of frames.entries()) {
            try {
                if (iframe.contentWindow === frameWin) return frameId;
            } catch {}
        }
        return null;
    };

    const api = Object.freeze({
        whenInitialFrameReady: () => initialReadyPromise,
        openUrl(url, options = {}) {
            openNewWorkspace({ url, ...options });
        },
        handleEmbedLinkClick(frameWin, absUrl, linkLabel = '', options = {}) {
            const sourceFrameId = frameIdForWindow(frameWin);
            if (!sourceFrameId) return 'in-place';
            const ctx = store.getFrameContext(sourceFrameId);
            if (!ctx) return 'in-place';

            const target = resolveLinkTarget(absUrl);
            const cleanTarget = target.url || stripEmbedParam(absUrl, origin);
            const targetModuleKey = target.key || resolveModuleForUrl(cleanTarget, ctx.group.moduleKey);
            const parentFrame = ctx.group.frames[0];

            const action = classifyInternalNavigation({
                currentUrl: ctx.frame.url,
                targetUrl: cleanTarget,
                currentModuleKey: ctx.group.moduleKey,
                targetModuleKey,
                sourceRole: ctx.frame.role,
                parentUrl: parentFrame?.url || null,
                indexPaths,
                origin,
                pathPrefix,
            });

            if (action === 'invalid') {
                return 'handled';
            }
            if (action === 'external') {
                openSafeExternal(cleanTarget);
                return 'handled';
            }
            if (action === 'top-level') {
                breakoutToTop(cleanTarget);
                return 'handled';
            }
            if (action === 'in-place') {
                return 'in-place';
            }
            if (options?.detailSwitch && action === 'join-child') {
                return 'in-place';
            }
            if (action === 'focus-parent' && parentFrame) {
                activateFrame(parentFrame.frameId, { historyMode: 'push' });
                return 'handled';
            }
            if (action === 'join-child') {
                openChildInGroup(ctx.group.groupId, ctx.frame.frameId, cleanTarget, linkLabel);
                return 'handled';
            }
            openNewWorkspace({
                moduleKey: targetModuleKey,
                label: linkLabel,
                url: cleanTarget,
            });
            return 'handled';
        },
        notifyEmbedBooted(frameWin) {
            const frameId = frameIdForWindow(frameWin);
            if (!frameId) return;
            const ctx = store.getFrameContext(frameId);
            if (!ctx) return;
            ctx.frame.queued = false;
            if (navQueue.has(frameId)) {
                navQueue.markDocumentLoaded(frameId, { awaitingData: true });
            }
            const readiness = frameWin?.AdasiEmbedReadiness;
            if (readiness && !readiness.isReady) {
                attachReadinessListener(frameId, frameWin, readiness);
            }
            syncTabDom();
        },
        notifyEmbedReady(frameWin) {
            const frameId = frameIdForWindow(frameWin);
            if (!frameId) return;
            const readiness = frameWin?.AdasiEmbedReadiness;
            if (readiness && !readiness.isReady) {
                attachReadinessListener(frameId, frameWin, readiness);
                return;
            }
            finalizeFrameReady(frameId, frameWin);
        },
        notifyDirty(frameWin, dirty) {
            const frameId = frameIdForWindow(frameWin);
            if (!frameId) return;
            store.setDirty(frameId, dirty);
            syncTabDom();
        },
        getDirtyFrames() {
            const dirty = [];
            for (const group of store.groups()) {
                for (const frame of group.frames) {
                    if (frame.isDirty) {
                        dirty.push({
                            frameId: frame.frameId,
                            title: frame.title || group.baseLabel || 'ADASI Portal',
                            url: frame.url,
                        });
                    }
                }
            }
            return dirty;
        },
        notifyMutation(frameWin) {
            const frameId = frameIdForWindow(frameWin);
            if (!frameId) return;
            store.markSiblingsStale(frameId);
            syncTabDom();
        },
        notifyEmbedNavigated(frameWin, cleanUrl, title, historyMode = 'replace') {
            const frameId = frameIdForWindow(frameWin);
            if (!frameId || !cleanUrl || !isSameOriginUrl(cleanUrl, origin)) return;
            const ctx = store.getFrameContext(frameId);
            if (!ctx) return;
            ctx.frame.url = canonicalizeUrl(cleanUrl, origin);
            ctx.frame.embedUrl = appendEmbedParam(ctx.frame.url, origin);
            const nextTitle = resolveCanonicalFrameTitle(ctx, ctx.frame.url, title || ctx.frame.title);
            ctx.frame.title = nextTitle;
            if (ctx.frame.role === 'parent') ctx.group.baseLabel = nextTitle;
            const active = store.activeFrame();
            if (active?.frameId === frameId) {
                const topbarTitle = formatTopbarTitle(active, ctx.group);
                syncTopbarTitle(topbarTitle);
                syncSidebarHighlight(ctx.frame.url);
                syncHistory(historyMode, ctx.frame.url, frameId, ctx.group.groupId, topbarTitle);
            }
            syncTabDom();
        },
        syncPreferencesFromEmbed(frameWin) {
            try {
                const srcRoot = frameWin?.document?.documentElement;
                if (!srcRoot) return;
                const attrs = ['theme', 'bsTheme', 'density', 'accent', 'shell'];
                const dstRoot = document.documentElement;
                attrs.forEach((attr) => {
                    if (srcRoot.dataset[attr]) dstRoot.dataset[attr] = srcRoot.dataset[attr];
                });
                frames.forEach((iframe) => {
                    try {
                        const childWin = iframe.contentWindow;
                        if (!childWin || childWin === frameWin) return;
                        const childRoot = childWin.document.documentElement;
                        attrs.forEach((attr) => {
                            if (srcRoot.dataset[attr]) childRoot.dataset[attr] = srcRoot.dataset[attr];
                        });
                    } catch {}
                });
            } catch {}
        },
        openSmartPanel(url) {
            if (!url || !isSameOriginUrl(url, origin)) return;
            let trigger = document.getElementById('workspace-smart-panel-proxy');
            if (!trigger) {
                trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.id = 'workspace-smart-panel-proxy';
                trigger.hidden = true;
                trigger.setAttribute('data-smart-panel-trigger', '');
                document.body.appendChild(trigger);
            }
            trigger.dataset.smartPanelUrl = url;
            trigger.click();
        },
        openLightbox(src, title, downloadUrl, gallery, startIndex) {
            window.AdasiLightbox?.open?.(src, title, downloadUrl, gallery, startIndex);
        },
        openChatDrawer() {
            window.openChatDrawer?.();
        },
        openChatConversation(id) {
            window.openChatConversation?.(id);
        },
        showToast(options) {
            return window.AdasiToast?.show?.(options);
        },
        dismissFloatingOverlays(options) {
            dismissFloatingOverlays(options);
        },
        broadcastNotification(notification) {
            if (!notification || typeof notification !== 'object') return;
            frames.forEach((iframe) => {
                try {
                    const childWin = iframe.contentWindow;
                    if (!childWin) return;
                    childWin.dispatchEvent(new childWin.CustomEvent('adasi:realtime-notification', { detail: notification }));
                } catch {}
            });
        },
        breakoutToTop(url) {
            breakoutToTop(url);
        },
    });

    window.AdasiWorkspaceShell = api;
    return api;
}
