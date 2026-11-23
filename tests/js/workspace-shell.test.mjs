import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    createEmbedReadinessController,
    isTrackableBootGetRequest,
} from '../../resources/js/embed-readiness.js';
import {
    buildHistoryProjection,
    buildShortcutsHistoryProjection,
    encodeScv2Payload,
    resolveContextUrl,
    resolvePopstateNavigation,
} from '../../resources/js/portal-context-url.js';
import {
    appendEmbedParam,
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
    isDownloadOrBinaryUrl,
    isNonMutatingPostUrl,
    isSameOriginUrl,
    isTopLevelOnlyUrl,
    MIN_SHORTCUTS_PANE_WIDTH_PX,
    normalizeShortcutsLayout,
    resizeShortcutsPairByDelta,
    resolveModuleKey,
    resolveShortcutsSplitterPair,
    resolveTransitionSettleMs,
    resolveVisibleShortcutsSplitters,
    safeWebUrl,
    sanitizePersistedPinnedList,
    sanitizeRecentlyClosedList,
    SHORTCUTS_KEY,
    SHORTCUTS_SPLITTER_WIDTH_PX,
    stripEmbedParam,
    WorkspaceNavQueue,
    WorkspaceSessionStore,
} from '../../resources/js/workspace-shell-core.js';

const ORIGIN = 'http://adasi_portal_supplier.test';
const PREFIX = '/v2/ADSI_PROD';

const SAMPLE_CATALOG = [
    { key: 'dashboard', label: 'Dashboard', href: `${ORIGIN}${PREFIX}/purchasing/dashboard` },
    { key: 'requisitions', label: 'Purchase Requisition', href: `${ORIGIN}${PREFIX}/purchasing/requisitions` },
    { key: 'quotations', label: 'Quotation', href: `${ORIGIN}${PREFIX}/purchasing/quotations` },
    { key: 'purchase-orders', label: 'Purchase Order', href: `${ORIGIN}${PREFIX}/purchasing/purchase-orders` },
    { key: 'shipments', label: 'Shipments & Logistics', href: `${ORIGIN}${PREFIX}/purchasing/shipments` },
    { key: 'comparison', label: 'Price Comparison', href: `${ORIGIN}${PREFIX}/purchasing/comparison/inter-supplier` },
    { key: 'claims', label: 'Material Claims', href: `${ORIGIN}${PREFIX}/purchasing/claims` },
    { key: 'reports', label: 'Reports', href: `${ORIGIN}${PREFIX}/purchasing/reports` },
];

const SAMPLE_INDEX_PATHS = [
    `${PREFIX}/purchasing/dashboard`,
    `${PREFIX}/purchasing/requisitions`,
    `${PREFIX}/purchasing/quotations`,
    `${PREFIX}/purchasing/purchase-orders`,
    `${PREFIX}/purchasing/shipments`,
    `${PREFIX}/purchasing/comparison/inter-supplier`,
    `${PREFIX}/purchasing/claims`,
    `${PREFIX}/purchasing/reports`,
];

test('embed parameter helpers append and strip cleanly without losing query or hash', () => {
    const raw = `${ORIGIN}${PREFIX}/purchasing/shipments?status=open#summary`;
    const embedded = appendEmbedParam(raw);
    assert.equal(embedded, `${ORIGIN}${PREFIX}/purchasing/shipments?status=open&_embed=1#summary`);
    assert.equal(appendEmbedParam(embedded), embedded);
    assert.equal(stripEmbedParam(embedded), raw);
    assert.equal(canonicalizeUrl(embedded), raw);
});

test('isSameOriginUrl rejects cross-origin, javascript:, and mailto: URLs', () => {
    assert.equal(isSameOriginUrl(`${PREFIX}/purchasing/shipments`, ORIGIN), true);
    assert.equal(isSameOriginUrl(`${ORIGIN}${PREFIX}/purchasing/shipments`, ORIGIN), true);
    assert.equal(isSameOriginUrl('https://evil.example.com/phish', ORIGIN), false);
    assert.equal(isSameOriginUrl('javascript:alert(1)', ORIGIN), false);
    assert.equal(isSameOriginUrl('mailto:test@example.com', ORIGIN), false);
    assert.equal(isSameOriginUrl('about:blank', ORIGIN), false);
});

test('isTopLevelOnlyUrl protects security, auth, password confirmation, and binary download paths', () => {
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}/login`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}/confirm-password`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}/oauth/authorize`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/profile/security`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/profile/two-factor/setup`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/supplier-context`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/admin/users/create`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/admin/users/abc123/edit`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/finance/vendor-master/abc123/payment-term`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/shared/pdf/purchase-order/abc123`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/exports/12/download`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/export-presets`, ORIGIN, PREFIX), true);
    assert.equal(isDownloadOrBinaryUrl(`${ORIGIN}${PREFIX}/export-presets`, ORIGIN), true);
    assert.equal(safeWebUrl('javascript:alert(1)', ORIGIN), null);
    assert.equal(safeWebUrl(`${PREFIX}/purchasing/shipments`, ORIGIN), `${ORIGIN}${PREFIX}/purchasing/shipments`);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/purchasing/shipments`, ORIGIN, PREFIX), false);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/purchasing/shipments/abc123`, ORIGIN, PREFIX), false);
    assert.equal(isNonMutatingPostUrl(`${ORIGIN}${PREFIX}/purchasing/material-calculations/totals`, ORIGIN), true);
    assert.equal(isNonMutatingPostUrl(`${ORIGIN}${PREFIX}/purchasing/requisitions/import-preview`, ORIGIN), true);
    assert.equal(isNonMutatingPostUrl(`${ORIGIN}${PREFIX}/purchasing/requisitions`, ORIGIN), false);
});

test('resolveModuleKey maps list and detail URLs to their sidebar module key', () => {
    assert.equal(
        resolveModuleKey(`${ORIGIN}${PREFIX}/purchasing/shipments`, SAMPLE_CATALOG),
        'shipments',
    );
    assert.equal(
        resolveModuleKey(`${ORIGIN}${PREFIX}/purchasing/shipments/LzpM8Arm`, SAMPLE_CATALOG),
        'shipments',
    );
    assert.equal(
        resolveModuleKey(`${ORIGIN}${PREFIX}/purchasing/comparison/historical`, SAMPLE_CATALOG),
        'comparison',
    );
    assert.equal(
        resolveModuleKey(`${ORIGIN}${PREFIX}/profile`, SAMPLE_CATALOG),
        'profile',
    );
});

test('classifyInternalNavigation separates in-place query updates, joined child drill-downs, parent returns, cross-module links, and top-level exits', () => {
    // 1. Pagination/filter queries stay in-place inside current iframe
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions?page=2&search=STL`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'requisitions',
            sourceRole: 'parent',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'in-place',
    );

    // 2. Detail / Create from module index joins as Child frame in same workspace group
    for (const childUrl of [
        `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm`,
        `${ORIGIN}${PREFIX}/purchasing/requisitions/create`,
    ]) {
        assert.equal(
            classifyInternalNavigation({
                currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
                targetUrl: childUrl,
                currentModuleKey: 'requisitions',
                targetModuleKey: 'requisitions',
                sourceRole: 'parent',
                parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
                indexPaths: SAMPLE_INDEX_PATHS,
                origin: ORIGIN,
                pathPrefix: PREFIX,
            }),
            'join-child',
        );
    }

    // 3. Edit sub-action on the SAME detail record stays in-place inside that Child frame
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm/edit`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'requisitions',
            sourceRole: 'child',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'in-place',
    );

    // 4. Opening a DIFFERENT detail record from a Child joins another Child sibling
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/XyZ999`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'requisitions',
            sourceRole: 'child',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'join-child',
    );

    // 5. Clicking "Back to Requisitions" from inside Child focuses existing Parent frame (with or without preserved query filters)
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'requisitions',
            sourceRole: 'child',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'focus-parent',
    );
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/create?return_url=foo`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions?status=open`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'requisitions',
            sourceRole: 'child',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions?status=open`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'focus-parent',
    );

    // 6. Sibling comparison view at same index depth stays in-place
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/comparison/inter-supplier`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/comparison/historical`,
            currentModuleKey: 'comparison',
            targetModuleKey: 'comparison',
            sourceRole: 'parent',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/comparison/inter-supplier`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'in-place',
    );

    // 7. Cross-module link spawns a new separate Workspace Group
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions/LzpM8Arm`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/purchase-orders/Pq456`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'purchase-orders',
            sourceRole: 'child',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'new-workspace',
    );

    // 8. Top-level security / print paths break out to top window; external & pseudo-protocol URLs never hijack top navigation
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            targetUrl: `${ORIGIN}${PREFIX}/profile/security`,
            currentModuleKey: 'requisitions',
            targetModuleKey: 'profile',
            sourceRole: 'parent',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'top-level',
    );
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/vouchers`,
            targetUrl: `${ORIGIN}${PREFIX}/purchasing/vouchers/99/print`,
            currentModuleKey: 'vouchers',
            targetModuleKey: 'vouchers',
            sourceRole: 'parent',
            parentUrl: `${ORIGIN}${PREFIX}/purchasing/vouchers`,
            indexPaths: SAMPLE_INDEX_PATHS,
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'top-level',
    );
    assert.equal(
        isDownloadOrBinaryUrl(`${ORIGIN}${PREFIX}/purchasing/vouchers/99/print`, ORIGIN),
        false,
    );
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            targetUrl: 'https://evil.example.test/phish',
            currentModuleKey: 'requisitions',
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'external',
    );
    assert.equal(
        classifyInternalNavigation({
            currentUrl: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
            targetUrl: 'javascript:alert(document.domain)',
            currentModuleKey: 'requisitions',
            origin: ORIGIN,
            pathPrefix: PREFIX,
        }),
        'invalid',
    );
});

test('WorkspaceSessionStore allows multiple instances of the same menu, disambiguates labels, and falls back to Shortcuts when all closed', () => {
    let now = 1000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 6,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    // Starts with pinned Shortcuts when seeded empty
    assert.equal(store.shortcutsKey, SHORTCUTS_KEY);

    const dash = store.seedInitial({
        moduleKey: 'dashboard',
        label: 'Dashboard',
        url: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
    });
    assert.equal(store.activeGroup()?.groupId, dash.group.groupId);
    assert.equal(store.isShortcutsActive(), false);

    // Open same Purchase Requisition menu 3 times -> 3 separate WorkspaceGroups!
    const pr1 = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
    });
    now += 50;
    const pr2 = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
    });
    now += 50;
    const pr3 = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
    });

    assert.notEqual(pr1.group.groupId, pr2.group.groupId);
    assert.notEqual(pr2.group.groupId, pr3.group.groupId);
    assert.equal(store.groups().length, 4);

    const display = store.displayGroups();
    const prGroups = display.filter((g) => g.moduleKey === 'requisitions');
    assert.deepEqual(prGroups.map((g) => g.duplicateIndex), [1, 2, 3]);
    assert.deepEqual(prGroups.map((g) => g.duplicateTotal), [3, 3, 3]);

    // Duplicate action inserts sibling copy adjacent to original
    const dup = store.duplicateGroup(pr1.group.groupId);
    assert.equal(dup.blocked, false);
    assert.equal(store.groups()[2].groupId, dup.group.groupId);

    // Closing all groups (including Dashboard) falls back cleanly to Shortcuts
    const bulk = store.closeAllGroups();
    assert.equal(bulk.closedFrameIds.length, 5);
    assert.equal(store.groups().length, 0);
    assert.equal(store.isShortcutsActive(), true);
    assert.deepEqual(store.activeTarget(), { type: SHORTCUTS_KEY });
});

test('WorkspaceSessionStore joins Parent + multi-Child frames, auto-collapses when switching away, and re-expands on focus', () => {
    let now = 2000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 6,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const pr = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
    });
    now += 100;
    const childA = store.openChildFrame({
        sourceFrameId: pr.frame.frameId,
        label: 'PR-001',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions/PR001`,
        moduleKey: 'requisitions',
    });
    now += 100;
    const childB = store.openChildFrame({
        sourceFrameId: pr.frame.frameId,
        label: 'PR-002',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions/PR002`,
        moduleKey: 'requisitions',
    });

    assert.equal(childA.created, true);
    assert.equal(childB.created, true);
    assert.equal(store.getGroup(pr.group.groupId).frames.length, 3);

    // Focusing Detail A URL again inside the same group reuses existing Child A instead of duplicating
    const reuseA = store.openChildFrame({
        sourceFrameId: pr.frame.frameId,
        label: 'PR-001',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions/PR001`,
        moduleKey: 'requisitions',
    });
    assert.equal(reuseA.created, false);
    assert.equal(reuseA.frame.frameId, childA.frame.frameId);
    assert.equal(store.getGroup(pr.group.groupId).frames.length, 3);

    // Active group is expanded
    let prDisplay = store.displayGroups().find((g) => g.groupId === pr.group.groupId);
    assert.equal(prDisplay.isActive, true);
    assert.equal(prDisplay.isExpanded, true);
    assert.equal(prDisplay.isCollapsedStack, false);

    // Open Shipments workspace -> active PR group automatically collapses into stacked pill!
    now += 100;
    store.openWorkspace({
        moduleKey: 'shipments',
        label: 'Shipments',
        url: `${ORIGIN}${PREFIX}/purchasing/shipments`,
    });

    prDisplay = store.displayGroups().find((g) => g.groupId === pr.group.groupId);
    assert.equal(prDisplay.isActive, false);
    assert.equal(prDisplay.isExpanded, false);
    assert.equal(prDisplay.isCollapsedStack, true);
    assert.equal(prDisplay.frames.length, 3);

    // Clicking collapsed stacked pill restores expanded group at its remembered active child (PR-001)
    store.activateGroup(pr.group.groupId);
    prDisplay = store.displayGroups().find((g) => g.groupId === pr.group.groupId);
    assert.equal(prDisplay.isExpanded, true);
    assert.equal(store.activeFrame().frameId, childA.frame.frameId);

    // Closing Child A moves back inside the same group; closing remaining child leaves solo Parent; closing Parent closes group -> Shipments
    store.closeFrame(childA.frame.frameId);
    assert.equal(store.getGroup(pr.group.groupId).frames.length, 2);
    store.closeFrame(pr.frame.frameId);
    assert.equal(store.getGroup(pr.group.groupId), null);
    assert.equal(store.activeGroup()?.moduleKey, 'shipments');
});

test('WorkspaceSessionStore hibernates LRU clean inactive frames beyond maxLiveFrames while protecting dirty and active frames', () => {
    let now = 5000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 4,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const g1 = store.openWorkspace({ moduleKey: 'dashboard', label: 'Dashboard', url: `${ORIGIN}${PREFIX}/purchasing/dashboard` });
    now += 10;
    const g2 = store.openWorkspace({ moduleKey: 'requisitions', label: 'PR', url: `${ORIGIN}${PREFIX}/purchasing/requisitions` });
    store.setDirty(g2.frame.frameId, true); // dirty must never hibernate
    now += 10;
    const g3 = store.openWorkspace({ moduleKey: 'quotations', label: 'RFQ', url: `${ORIGIN}${PREFIX}/purchasing/quotations` });
    now += 10;
    const g4 = store.openWorkspace({ moduleKey: 'purchase-orders', label: 'PO', url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders` });
    now += 10;
    // Frame #5 exceeds maxLiveFrames=4 -> coldest clean inactive (g1 Dashboard) hibernates, g2 dirty stays live
    const g5 = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` });

    assert.equal(g5.blocked, false);
    assert.equal(store.groups().length, 5);
    assert.equal(store.liveFrameCount(), 4);
    assert.deepEqual(g5.hibernatedFrameIds, [g1.frame.frameId]);
    assert.equal(store.getFrame(g1.frame.frameId).hibernated, true);
    assert.equal(store.getFrame(g2.frame.frameId).hibernated, false);

    // Re-activating hibernated Dashboard frame un-hibernates it and hibernates coldest clean (g3 RFQ)
    now += 10;
    const reactivate = store.activateFrame(g1.frame.frameId);
    assert.equal(reactivate.frame.hibernated, false);
    assert.equal(reactivate.wasHibernated, true);
    assert.deepEqual(reactivate.hibernatedFrameIds, [g3.frame.frameId]);
    assert.equal(store.liveFrameCount(), 4);
});

test('WorkspaceSessionStore blocks opening past maxSessions only if all eviction candidates are dirty', () => {
    let now = 9000;
    const store = new WorkspaceSessionStore({
        maxSessions: 3,
        maxLiveFrames: 3,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const f1 = store.openWorkspace({ moduleKey: 'dashboard', label: 'D', url: `${ORIGIN}${PREFIX}/purchasing/dashboard` });
    store.setDirty(f1.frame.frameId, true);
    now += 10;
    const f2 = store.openWorkspace({ moduleKey: 'requisitions', label: 'PR', url: `${ORIGIN}${PREFIX}/purchasing/requisitions` });
    store.setDirty(f2.frame.frameId, true);
    now += 10;
    const f3 = store.openWorkspace({ moduleKey: 'quotations', label: 'RFQ', url: `${ORIGIN}${PREFIX}/purchasing/quotations` });
    store.setDirty(f3.frame.frameId, true);

    now += 10;
    const blocked = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` });
    assert.equal(blocked.blocked, true);
    assert.equal(store.totalFrames(), 3);
    assert.equal(store.dirtyFramesForClose('all').length, 3);
});

test('WorkspaceSessionStore tracks stale invalidation on mutations and TTL without auto-reloading dirty frames', () => {
    let now = 10_000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 6,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const d = store.openWorkspace({ moduleKey: 'dashboard', label: 'Dashboard', url: `${ORIGIN}${PREFIX}/purchasing/dashboard` });
    now += 10;
    const s = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` });
    now += 10;
    const p = store.openWorkspace({ moduleKey: 'purchase-orders', label: 'PO', url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders` });

    store.markSiblingsStale(p.frame.frameId);
    assert.equal(store.getFrame(d.frame.frameId).stale, true);
    assert.equal(store.getFrame(s.frame.frameId).stale, true);
    assert.equal(store.getFrame(p.frame.frameId).stale, false);

    const cleanActivate = store.activateFrame(s.frame.frameId);
    assert.equal(cleanActivate.shouldRefresh, true);
    store.markFresh(s.frame.frameId);
    assert.equal(store.getFrame(s.frame.frameId).stale, false);

    store.setDirty(d.frame.frameId, true);
    now += 130_000;
    const dirtyActivate = store.activateFrame(d.frame.frameId);
    assert.equal(dirtyActivate.shouldRefresh, false);
    assert.equal(store.getFrame(d.frame.frameId).stale, true);
});

test('workspace-shell.css enforces connected card-tabs (non-ghost), hover/focus-visible close icon, stage loader, and smooth session motion', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const repoRoot = resolve(testDir, '../..');
    const css = readFileSync(resolve(repoRoot, 'resources/css/workspace-shell.css'), 'utf8');
    const appCss = readFileSync(resolve(repoRoot, 'resources/css/app.css'), 'utf8');

    assert.equal(css.includes('--ui-motion-workspace: var(--ui-motion-sidebar, 280ms);'), true);
    assert.equal(css.includes('--workspace-session-min-width: 7.25rem;'), true);
    assert.equal(css.includes('--workspace-session-stacked-min-width: 8.75rem;'), true);
    assert.equal(css.includes('--workspace-session-cap: var(--workspace-session-max-width);'), true);
    assert.equal(css.includes('--ui-motion-workspace-expand: 400ms;'), true);
    assert.equal(css.includes('--ui-motion-workspace-collapse: 340ms;'), true);
    assert.equal(css.includes('--ui-easing-workspace:'), true);
    assert.equal(css.includes('.workspace-session-group.is-entering'), true);
    assert.equal(css.includes('.workspace-session-group.is-morphing'), true);
    assert.equal(css.includes('.workspace-session-group.is-expanding-group'), true);
    assert.equal(css.includes('.workspace-session-group.is-collapsing-group'), true);
    assert.equal(css.includes('.workspace-session.is-morph-pill'), true);
    assert.equal(css.includes('.workspace-session__morph-overlay'), true);
    assert.equal(css.includes('.workspace-session-group__collapse-tail'), true);
    assert.equal(css.includes('.workspace-session-group--ghost.is-closing'), true);
    assert.equal(css.includes('.workspace-session--ghost.is-closing'), true);
    assert.equal(css.includes('.workspace-session.is-revealing'), true);
    assert.equal(css.includes('workspace-tab-enter'), false, 'Legacy vertical tab bounce keyframe must stay removed');
    assert.equal(css.includes('workspace-pill-enter'), false, 'Legacy pill slide keyframe must stay removed');
    assert.equal(css.includes('translateY(-1px)'), false, 'Stack pseudo-tab hover lift bounce must stay removed');
    assert.equal(css.includes('scale(0.9'), false, 'Scale press bounce on sessionbar controls must stay removed');
    assert.equal(css.includes('scaleX(0.35)'), false, 'ScaleX underline micro-pop must stay removed');
    assert.equal(css.includes('transition: all'), false, 'Never use transition: all on workspace controls');
    assert.equal(css.includes('.workspace-session:hover > .workspace-session__close'), true);
    assert.equal(css.includes('.workspace-session:has(:focus-visible) > .workspace-session__close'), true);
    assert.equal(css.includes('.workspace-session:not(.workspace-session--shortcuts):not(.workspace-session--stacked)'), true);
    assert.equal(css.includes('var(--workspace-session-natural-width'), true);
    assert.equal(css.includes('@media (hover: none)'), true);
    assert.equal(css.includes('transition: none !important'), true);

    // Connected card-tabs non-ghost contract for workspace sessions + borderless bottom-underline contract for Shortcuts
    const sessionRuleMatch = css.match(/^\.workspace-session\s*\{[^}]*\}/m);
    assert.ok(sessionRuleMatch && !sessionRuleMatch[0].includes('background: transparent'), 'Workspace session card-tabs must not use ghost transparent background');
    assert.ok(sessionRuleMatch && !sessionRuleMatch[0].includes('border: 1px solid transparent'), 'Workspace session card-tabs must not use ghost transparent border');
    const shortcutsBorderlessMatch = css.match(/\.workspace-session--shortcuts,\s*[\s\S]*?\.workspace-session--shortcuts\.is-active\s*\{[^}]*\}/);
    assert.ok(
        shortcutsBorderlessMatch
            && shortcutsBorderlessMatch[0].includes('border: 0;')
            && shortcutsBorderlessMatch[0].includes('background: transparent;'),
        'Shortcuts must stay borderless with transparent background across idle, hover, and active states',
    );
    const activeIndicatorMatch = css.match(/\.workspace-session::after\s*\{[^}]*\}/);
    assert.ok(
        activeIndicatorMatch
            && activeIndicatorMatch[0].includes('bottom: 0;')
            && activeIndicatorMatch[0].includes('left: 0.75rem;')
            && activeIndicatorMatch[0].includes('right: 0.75rem;')
            && !activeIndicatorMatch[0].includes('left: 0;')
            && !activeIndicatorMatch[0].includes('right: 0;')
            && !activeIndicatorMatch[0].includes('top: 0;'),
        'Workspace sessions and Shortcuts must use an inset bottom underline indicator instead of a full-width or top line',
    );
    assert.equal(css.includes('.workspace-sessionbar__sidebar-toggle'), true);
    assert.equal(css.includes('--ui-session-radius: 8px;'), true, 'Workspace session cards must expose 8px corner radius token');
    assert.ok(sessionRuleMatch && sessionRuleMatch[0].includes('var(--ui-session-radius, 8px)'), 'Workspace session card-tabs must use 8px top corner radius');
    assert.equal(css.includes('.workspace-sessionbar::after'), true, 'Sessionbar must provide continuous baseline under connected card-tabs');

    // Stage-scoped slow loader contract & prev/next record-nav design contract
    assert.equal(appCss.includes('.adasi-loader-overlay--stage'), true);
    assert.equal(appCss.includes('.ui-record-nav__counter'), true);

    const groupRuleMatch = css.match(/\.workspace-session-group\s*\{[^}]*\}/);
    assert.ok(groupRuleMatch && !groupRuleMatch[0].includes('max-width: 100%'), '.workspace-session-group must not clamp expanded width via max-width: 100%');
    const coarseMatch = css.match(/@media\s*\(hover:\s*none\)\s*\{[\s\S]*?\n\}/);
    assert.ok(coarseMatch && coarseMatch[0].includes(':has(:focus-visible) > .workspace-session__close'), '@media (hover: none) must keep keyboard :focus-visible close targets visible');
    assert.equal(css.includes('.workspace-session-group--shortcuts'), true);

    const sessionBarBlade = readFileSync(resolve(repoRoot, 'resources/views/partials/workspace-session-bar.blade.php'), 'utf8');
    assert.ok(
        sessionBarBlade.indexOf('data-workspace-sidebar-toggle') !== -1
            && sessionBarBlade.indexOf('data-workspace-sidebar-toggle') < sessionBarBlade.indexOf('id="workspace-session-group-shortcuts"'),
        'Sidebar toggle button must sit directly beside Shortcuts on the left of the sessionbar',
    );
    assert.ok(
        sessionBarBlade.indexOf('id="workspace-session-group-shortcuts"') < sessionBarBlade.indexOf('data-workspace-sessionlist'),
        'Shortcuts must sit fixed before the scrollable sessionlist',
    );
    assert.equal(sessionBarBlade.includes('aria-owns="workspace-session-group-shortcuts"'), true);
    assert.equal(sessionBarBlade.includes('data-workspace-live-region'), true);
    assert.equal(css.includes('.workspace-panel.is-leaving'), true);
    assert.equal(css.includes('.workspace-session[data-queued="true"]'), true);
    assert.equal(css.includes('.workspace-session-menu.is-closing'), true);

    // Semantic CSS-driven status icons replace colored dots (WCAG 1.4.1 / non-slop)
    const wsShellJs = readFileSync(resolve(repoRoot, 'resources/js/workspace-shell.js'), 'utf8');
    assert.equal(css.includes('.workspace-session__status-dot'), false, 'Legacy status-dot CSS must be removed');
    assert.equal(css.includes('workspace-status-pulse'), false, 'Pulsing dot keyframes must be removed');
    assert.equal(sessionBarBlade.includes('workspace-session__status-dot'), false, 'Blade template must not render status-dot');
    assert.equal(wsShellJs.includes('workspace-session__status-dot'), false, 'JS must not create status-dot');
    assert.equal(wsShellJs.includes("badgeParts.push('•')"), false, 'Open sessions menu must not render bullet dot for dirty state');
    assert.equal(css.includes('@property --icon-status'), true);
    assert.equal(css.includes('.workspace-session__status-icon'), true);
    assert.equal(css.includes('forced-color-adjust: none;'), true);
    assert.equal(css.includes('--workspace-session-status-warning: var(--md-chart-warning);'), true);
    for (const attr of ['data-dirty="true"', 'data-loading="true"', 'data-stale="true"', 'data-queued="true"', 'data-hibernated="true"']) {
        assert.equal(css.includes(`.workspace-session[${attr}]`), true, `Missing status icon rule for ${attr}`);
    }
    assert.equal(wsShellJs.includes("trigger.setAttribute('aria-description'"), true);

    const enNav = readFileSync(resolve(repoRoot, 'lang/en/navigation.php'), 'utf8');
    const idNav = readFileSync(resolve(repoRoot, 'lang/id/navigation.php'), 'utf8');
    for (const key of [
        'sessions_label',
        'refresh_session',
        'close_session',
        'status_queued',
        'queued_announcement',
        'ready_announcement',
        'favorites',
        'bookmarks',
        'add_favorite',
        'remove_favorite',
        'add_bookmark',
        'remove_bookmark',
        'remove_frequent',
        'minimize_pane',
        'expand_pane',
        'minimized_panels',
        'resize_shortcuts_panes',
        'resize_pane_pair',
    ]) {
        assert.equal(enNav.includes(`'${key}'`), true, `Missing ${key} in lang/en/navigation.php`);
        assert.equal(idNav.includes(`'${key}'`), true, `Missing ${key} in lang/id/navigation.php`);
    }

    // Infor LN-style flat 3-pane Shortcuts workspace contracts with dual splitters & anti-overlap rules
    const shellBlade = readFileSync(resolve(repoRoot, 'resources/views/layouts/workspace-shell.blade.php'), 'utf8');
    assert.equal(shellBlade.includes('data-shortcuts-board'), true);
    assert.equal(shellBlade.includes('workspace-shortcuts-board__left'), false, 'Sub-wrapper __left must remain removed to avoid flex spillover overlap');
    assert.equal(shellBlade.includes('data-shortcuts-pane='), true);
    assert.equal(shellBlade.includes('data-shortcuts-pane-toggle='), true);
    assert.equal(shellBlade.includes('data-shortcuts-empty='), false, 'Empty shortcuts panels must stay blank without placeholder text');
    for (const pane of ['favorites', 'bookmarks', 'frequent']) {
        assert.equal(shellBlade.includes(`'${pane}'`), true, `Missing pane config '${pane}' in workspace-shell.blade.php`);
    }
    assert.equal(shellBlade.includes('favorites-bookmarks'), true);
    assert.equal(shellBlade.includes('bookmarks-frequent'), true);
    assert.equal(shellBlade.includes('data-shortcuts-splitter'), true);
    assert.equal(shellBlade.includes('role="separator"'), true);
    assert.equal(sessionBarBlade.includes('data-workspace-icon="star"'), true);
    assert.equal(sessionBarBlade.includes('data-workspace-icon="bookmark"'), true);
    assert.equal(css.includes('.workspace-shortcuts-board__left'), false, 'Legacy __left CSS must stay removed');
    const shortcutsPaneMatch = css.match(/^\.workspace-shortcuts-pane\s*\{[^}]*\}/m);
    assert.ok(
        shortcutsPaneMatch
            && shortcutsPaneMatch[0].includes('min-width: 0;')
            && shortcutsPaneMatch[0].includes('overflow: hidden;'),
        'Open shortcuts panes must use min-width: 0 and overflow: hidden so narrow flex tracks never overlap',
    );
    assert.equal(shellBlade.includes('data-shortcuts-dock'), true);
    assert.equal(shellBlade.includes('data-shortcuts-pane-restore='), true);
    assert.equal(css.includes('writing-mode: vertical-rl;'), false, 'Minimized shortcuts panes must dock at bottom instead of vertical rails');
    const minimizedPaneMatch = css.match(/\.workspace-shortcuts-pane\.is-minimized\s*\{[^}]*\}/);
    assert.ok(minimizedPaneMatch && minimizedPaneMatch[0].includes('display: none;'), 'Minimized shortcuts panes must leave horizontal flex layout');
    const paneHoverMatch = css.match(/\.workspace-shortcuts-pane:hover,\s*\.workspace-shortcuts-pane:focus-within\s*\{[^}]*\}/);
    assert.ok(
        paneHoverMatch
            && paneHoverMatch[0].includes('var(--md-outline-strong)')
            && paneHoverMatch[0].includes('var(--ui-shadow-2)'),
        'Hovered or focused shortcuts pane must elevate border and shadow',
    );
    assert.equal(css.includes('.workspace-shortcuts-dock'), true);
    assert.equal(css.includes('.workspace-shortcuts-dock__item'), true);
    const splitterLineMatch = css.match(/\.workspace-shortcuts-splitter__line\s*\{[^}]*\}/);
    assert.ok(splitterLineMatch && splitterLineMatch[0].includes('opacity: 0;'), 'Shortcuts splitter vertical line must stay hidden until hover or drag');
    const splitterGripMatch = css.match(/\.workspace-shortcuts-splitter__grip\s*\{[^}]*\}/);
    assert.ok(
        splitterGripMatch
            && splitterGripMatch[0].includes('opacity: 0;')
            && splitterGripMatch[0].includes('top: var(--splitter-grip-y, 50%);')
            && splitterGripMatch[0].includes('background: var(--md-primary);'),
        'Shortcuts splitter grip must render cursor-tracking primary blue pill hidden at rest',
    );
    const shortcutItemMainMatch = css.match(/\.workspace-shortcuts__item-main\s*\{[^}]*\}/);
    assert.ok(
        shortcutItemMainMatch
            && shortcutItemMainMatch[0].includes('flex: 1 1 auto;')
            && shortcutItemMainMatch[0].includes('overflow: hidden;'),
        'Shortcut item main track must clamp overflow so narrow rows never collide with open badges',
    );
    assert.equal(css.includes('@container shortcuts-body (max-width: 11.5rem)'), true);
});

test('WorkspaceSessionStore stageOnly keeps current session active until committed and cleans up on discard', () => {
    let now = 20_000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 4,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const dash = store.seedInitial({
        moduleKey: 'dashboard',
        label: 'Dashboard',
        url: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
    });

    // 1. Staged new workspace leaves Dashboard active and keeps stagedNew hidden from tab strip
    const stagedPo = store.openWorkspace({
        moduleKey: 'purchase-orders',
        label: 'Purchase Order',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders`,
        stageOnly: true,
    });
    assert.equal(stagedPo.staged, true);
    assert.equal(stagedPo.frame.staged, true);
    assert.equal(store.activeFrame().frameId, dash.frame.frameId);
    assert.equal(store.displayGroups().length, 1);

    // 2. Discarding a superseded staged workspace removes it cleanly without leaving orphaned tabs
    const discardedPo = store.discardStagedFrame(stagedPo.frame.frameId);
    assert.equal(discardedPo.discarded, true);
    assert.equal(store.groups().length, 1);
    assert.equal(store.activeFrame().frameId, dash.frame.frameId);

    // 3. Committing a finished staged workspace switches active target and reveals tab pill
    const stagedPr = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
        stageOnly: true,
    });
    now += 400;
    const committed = store.commitStagedFrame(stagedPr.frame.frameId);
    assert.equal(committed.ok, true);
    assert.equal(store.activeFrame().frameId, stagedPr.frame.frameId);
    assert.equal(store.displayGroups().length, 2);
});

test('computeWaterFillTabCap progressively shrinks widest tabs down to the 112px minimum floor', () => {
    // 1. Plenty of viewport width: retains maxCap without shrinking
    assert.equal(
        computeWaterFillTabCap({ pillWidths: [100, 180, 195], overheadPx: 16, budgetPx: 940, minCapPx: 112, maxCapPx: 248 }),
        248,
    );

    // 2. Mild pressure: short tab (96px) untouched while wider tabs equalize cleanly at ~133px
    assert.equal(
        computeWaterFillTabCap({ pillWidths: [96, 150, 165, 175, 195, 195, 195], overheadPx: 43, budgetPx: 940, minCapPx: 112, maxCapPx: 248 }),
        133,
    );

    // 3. Saturated tabbar: stops at minimum floor (112px) so tabs stay readable and scrolling activates
    assert.equal(
        computeWaterFillTabCap({ pillWidths: [96, 120, 145, 150, 165, 175, 195, 195, 195], overheadPx: 53, budgetPx: 940, minCapPx: 112, maxCapPx: 248 }),
        112,
    );

    // 4. Per-pill minimum floor protects stacked multi-page pills (136px) while regular tabs stay at 112px
    assert.equal(
        computeWaterFillTabCap({
            pillWidths: [
                { width: 96, minWidth: 112 },
                { width: 199, minWidth: 136 },
                { width: 195, minWidth: 112 },
                { width: 195, minWidth: 112 },
                { width: 195, minWidth: 112 },
                { width: 195, minWidth: 112 },
                { width: 195, minWidth: 112 },
            ],
            overheadPx: 43,
            budgetPx: 900,
            minCapPx: 112,
            maxCapPx: 248,
        }),
        125,
    );

    // 5. Empty or invalid input guards safely to maxCap
    assert.equal(
        computeWaterFillTabCap({ pillWidths: [], overheadPx: 0, budgetPx: 800, minCapPx: 112, maxCapPx: 248 }),
        248,
    );
});

function createFakeTimerHarness() {
    let nowMs = 0;
    let seq = 0;
    const tasks = new Map();

    return {
        now: () => nowMs,
        timers: {
            now: () => nowMs,
            setTimeout(fn, ms) {
                const id = ++seq;
                tasks.set(id, { fn, at: nowMs + Math.max(0, Number(ms) || 0) });
                return id;
            },
            clearTimeout(id) {
                tasks.delete(id);
            },
            raf(fn) {
                const id = ++seq;
                tasks.set(id, { fn, at: nowMs + 16 });
                return id;
            },
            microtask(fn) {
                fn();
            },
        },
        advanceBy(deltaMs) {
            const target = nowMs + deltaMs;
            while (true) {
                let nextId = null;
                let nextTask = null;
                for (const [id, task] of tasks.entries()) {
                    if (task.at <= target && (!nextTask || task.at < nextTask.at || (task.at === nextTask.at && id < nextId))) {
                        nextId = id;
                        nextTask = task;
                    }
                }
                if (!nextTask) break;
                tasks.delete(nextId);
                nowMs = nextTask.at;
                nextTask.fn();
            }
            nowMs = target;
        },
    };
}

test('WorkspaceSessionStore hides staged sessions until render completes while still protecting queued capacity', () => {
    let now = 30_000;
    const store = new WorkspaceSessionStore({
        maxSessions: 5,
        maxLiveFrames: 3,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const dash = store.seedInitial({
        moduleKey: 'dashboard',
        label: 'Dashboard',
        url: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
    });

    now += 10;
    const po1 = store.openWorkspace({
        moduleKey: 'purchase-orders',
        label: 'Purchase Order',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    now += 10;
    const po2 = store.openWorkspace({
        moduleKey: 'purchase-orders',
        label: 'Purchase Order',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    now += 10;
    const ship = store.openWorkspace({
        moduleKey: 'shipments',
        label: 'Shipments & Logistics',
        url: `${ORIGIN}${PREFIX}/purchasing/shipments`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    now += 10;
    const pr = store.openWorkspace({
        moduleKey: 'requisitions',
        label: 'Purchase Requisition',
        url: `${ORIGIN}${PREFIX}/purchasing/requisitions`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });

    // While rendering on server, Dashboard stays active and staged sessions are omitted from the session strip
    assert.equal(store.activeFrame().frameId, dash.frame.frameId);
    assert.deepEqual(store.displayGroups().map((g) => g.groupId), [dash.group.groupId]);
    assert.equal(store.totalFrames(), 5);

    // Next open hits maxSessions=5 while all non-active frames are staged -> blocks instead of evicting staged items
    const overflow = store.openWorkspace({
        moduleKey: 'claims',
        label: 'Material Claims',
        url: `${ORIGIN}${PREFIX}/purchasing/claims`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    assert.equal(overflow.blocked, true);

    // Committing each finished render reveals its session in the bar simultaneously
    now += 200;
    const commit1 = store.commitStagedFrame(po1.frame.frameId, { activate: true });
    assert.equal(commit1.ok, true);
    assert.equal(commit1.activated, true);
    assert.equal(store.activeFrame().frameId, po1.frame.frameId);
    assert.deepEqual(store.displayGroups().map((g) => g.groupId), [dash.group.groupId, po1.group.groupId]);

    now += 220;
    const commit2 = store.commitStagedFrame(po2.frame.frameId, { activate: true });
    assert.equal(commit2.ok, true);
    assert.equal(store.activeFrame().frameId, po2.frame.frameId);
    const groupsAfter2 = store.displayGroups();
    assert.equal(groupsAfter2[1].duplicateIndex, 1);
    assert.equal(groupsAfter2[2].duplicateIndex, 2);

    // Child stagedNew hides child pill until ready while keeping parent visible
    now += 100;
    const child = store.openChildFrame({
        sourceFrameId: po2.frame.frameId,
        label: 'PO-001',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders/PO001`,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    assert.equal(child.created, true);
    assert.equal(store.displayGroups().find((g) => g.groupId === po2.group.groupId).frames.length, 1);
    store.commitStagedFrame(child.frame.frameId, { activate: true });
    assert.equal(store.displayGroups().find((g) => g.groupId === po2.group.groupId).frames.length, 2);
});

test('WorkspaceNavQueue steps sequentially in strict FIFO click order with 220ms cadence and manages stage slow loader', () => {
    const harness = createFakeTimerHarness();
    const started = [];
    const committed = [];
    const timeouts = [];
    const dataTimeouts = [];
    let loaderShownCount = 0;
    let loaderHiddenCount = 0;

    const queue = new WorkspaceNavQueue({
        maxConcurrent: 2,
        slowThresholdMs: 2500,
        stepIntervalMs: 220,
        slotTimeoutMs: 15000,
        dataReadyTimeoutMs: 12000,
        timers: harness.timers,
        onStartSlot: (entry) => started.push(entry.frameId),
        onCommitReady: (entry, outcome) => committed.push({ frameId: entry.frameId, activate: outcome.activate, at: harness.now() }),
        onSlowThreshold: () => { loaderShownCount += 1; },
        onHideLoader: () => { loaderHiddenCount += 1; },
        onSlotTimeout: (entry) => timeouts.push(entry.frameId),
        onDataTimeout: (entry) => dataTimeouts.push(entry.frameId),
    });

    const poAnchor = { id: 'sidebar-po' };

    queue.enqueue({ frameId: 'po1', groupId: 'g1', label: 'PO #1', triggerEl: poAnchor });
    queue.enqueue({ frameId: 'po2', groupId: 'g2', label: 'PO #2', triggerEl: poAnchor });
    queue.enqueue({ frameId: 'ship', groupId: 'g3', label: 'Shipments' });
    queue.enqueue({ frameId: 'pr', groupId: 'g4', label: 'Requisitions' });

    assert.deepEqual(started, ['po1', 'po2']);
    assert.equal(queue.activeCount(), 2);
    assert.equal(queue.queuedCount(), 2);
    assert.deepEqual(queue.commitOrder(), ['po1', 'po2', 'ship', 'pr']);
    assert.equal(queue.isTriggerPending(poAnchor), true);

    // Out-of-order completion: po2 finishes BEFORE po1 -> frees slot for ship, but holds po2 behind po1 in FIFO commit order!
    harness.advanceBy(800);
    const resPo2 = queue.complete('po2');
    assert.equal(resPo2.handled, true);
    assert.equal(resPo2.deferred, true);
    assert.deepEqual(committed, [], 'po2 must wait for older po1 before appearing');
    assert.deepEqual(started, ['po1', 'po2', 'ship']);
    assert.equal(queue.isTriggerPending(poAnchor), true);

    // po1 finishes HTML and enters waiting-data (DataTables AJAX still running)
    harness.advanceBy(200);
    assert.equal(queue.markDocumentLoaded('po1', { awaitingData: true }), true);
    assert.equal(queue.activeCount(), 2);
    assert.equal(queue.queuedCount(), 1);

    // Crosses 2500ms threshold -> stage loader appears
    harness.advanceBy(1600);
    assert.equal(loaderShownCount, 1);
    assert.equal(queue.isLoaderShown(), true);

    // po1 completes at t=2600ms -> hides loader, reveals & activates po1 IMMEDIATELY; schedules 220ms step timer before po2!
    const resPo1 = queue.complete('po1');
    assert.equal(resPo1.activate, true);
    assert.equal(loaderHiddenCount, 1);
    assert.equal(queue.isLoaderShown(), false);
    assert.deepEqual(started, ['po1', 'po2', 'ship', 'pr']);
    assert.deepEqual(committed, [{ frameId: 'po1', activate: true, at: 2600 }]);

    // After 219ms po2 is still stepping; at +220ms (t=2820ms) po2 steps in and activates cleanly
    harness.advanceBy(219);
    assert.equal(committed.length, 1);
    harness.advanceBy(1);
    assert.deepEqual(committed, [
        { frameId: 'po1', activate: true, at: 2600 },
        { frameId: 'po2', activate: true, at: 2820 },
    ]);

    // Ship finishes at 2900ms (80ms after po2) -> waits remaining 140ms, then activates at 3040ms; pr waits 220ms after ship -> 3260ms
    harness.advanceBy(80);
    queue.complete('ship');
    queue.complete('pr');
    assert.equal(committed.length, 2);
    harness.advanceBy(140);
    assert.equal(committed.at(-1).frameId, 'ship');
    assert.equal(committed.at(-1).activate, true);
    harness.advanceBy(220);
    assert.equal(committed.at(-1).frameId, 'pr');
    assert.equal(committed.at(-1).activate, true);
    assert.equal(queue.pendingCommitsCount(), 0);
    assert.equal(queue.isTriggerPending(poAnchor), false);

    // Manual prioritize & clearAutoFocus behavior
    queue.enqueue({ frameId: 'm1', groupId: 'gm1', label: 'M1' });
    queue.enqueue({ frameId: 'm2', groupId: 'gm2', label: 'M2' });
    queue.enqueue({ frameId: 'm3', groupId: 'gm3', label: 'M3' });
    queue.enqueue({ frameId: 'm4', groupId: 'gm4', label: 'M4' });
    assert.equal(queue.prioritize('m3'), true);
    assert.equal(queue.commitOrder()[0], 'm3');
    queue.complete('m1');
    assert.equal(started.at(-1), 'm3', 'Prioritized item dequeues before older queued m4');

    // Slow loader after 2500ms -> manual tab click calls clearAutoFocus -> hides loader & prevents stealing focus later
    harness.advanceBy(2600);
    assert.equal(loaderShownCount, 2);
    queue.clearAutoFocus();
    assert.equal(loaderHiddenCount, 2);
    const prevCommitLen = committed.length;
    queue.complete('m3');
    assert.equal(committed.length, prevCommitLen + 1);
    assert.equal(committed.at(-1).activate, false, 'Cleared autoFocus keeps new session ready in bar without stealing user focus');
    assert.deepEqual(timeouts, []);

    // Verify 12s dataReadyTimeout fires cleanly on stalled waiting-data entry
    queue.markDocumentLoaded('m2', { awaitingData: true });
    harness.advanceBy(12100);
    assert.deepEqual(dataTimeouts, ['m2']);

    // Verify cancelling/failing stalled head immediately unblocks ready entry behind it
    const failCommitted = [];
    const failQueue = new WorkspaceNavQueue({
        maxConcurrent: 2,
        slowThresholdMs: 2500,
        stepIntervalMs: 220,
        timers: harness.timers,
        onCommitReady: (entry) => failCommitted.push(entry.frameId),
    });
    failQueue.enqueue({ frameId: 'x1', groupId: 'gx1', label: 'X1' });
    failQueue.enqueue({ frameId: 'x2', groupId: 'gx2', label: 'X2' });
    harness.advanceBy(300);
    failQueue.complete('x2');
    assert.deepEqual(failCommitted, [], 'x2 waits behind stalled x1');
    failQueue.cancel('x1', 'closed');
    harness.advanceBy(300);
    assert.deepEqual(failCommitted, ['x2'], 'Cancelling stalled head unblocks queued follower');
});

test('createEmbedReadinessController waits for serverSide DataTables draw.dt and boot GET fetches before signaling ready', async () => {
    const harness = createFakeTimerHarness();
    let readyDetail = null;

    const ctrl = createEmbedReadinessController({
        origin: ORIGIN,
        maxDataWaitMs: 12000,
        timers: harness.timers,
        onReady: (detail) => { readyDetail = detail; },
    });

    // 1. DataTable initializes during boot
    const poTable = { id: 'poTable' };
    ctrl.onTableInitStart(poTable);
    ctrl.onTablePreXhr(poTable);
    ctrl.onTableProcessing(poTable, true);

    // 2. Also simulate an initial GET fetch during boot window plus ignored background endpoint
    const tracked = ctrl.onRequestStart('req-1', { url: `${ORIGIN}${PREFIX}/purchasing/comparison/data`, method: 'GET' });
    const ignored = ctrl.onRequestStart('req-poll', { url: `${ORIGIN}/notifications/unread-count`, method: 'GET' });
    assert.equal(tracked, true);
    assert.equal(ignored, false);
    assert.equal(isTrackableBootGetRequest({ url: 'https://other.example.com/data', method: 'GET', origin: ORIGIN }), false);
    assert.equal(isTrackableBootGetRequest({ url: `${ORIGIN}/broadcasting/auth`, method: 'POST', origin: ORIGIN }), false);

    // 3. Even after DOMContentLoaded & window.load finish, page must NOT be ready while DataTable AJAX runs!
    ctrl.markDomReady();
    ctrl.markWindowLoaded();
    harness.advanceBy(50);
    assert.equal(ctrl.isReady, false);
    assert.equal(readyDetail, null);

    // Background polling started AFTER boot settle window closes must not re-block readiness
    assert.equal(ctrl.onRequestStart('late-poll', { url: `${ORIGIN}${PREFIX}/exports`, method: 'GET' }), false);

    // 4. Boot fetch finishes, still waiting for DataTable draw.dt
    ctrl.onRequestEnd('req-1');
    harness.advanceBy(50);
    assert.equal(ctrl.isReady, false);

    // 5. DataTable draw.dt completes -> waits 1 paint rAF then resolves whenReady()!
    ctrl.onTableSettled(poTable);
    assert.equal(ctrl.isReady, false, 'Allows 1 paint frame for rendered tbody rows');
    harness.advanceBy(20);
    assert.equal(ctrl.isReady, true);
    const resolved = await ctrl.whenReady();
    assert.equal(resolved.reason, 'settled');
    assert.equal(readyDetail.reason, 'settled');

    // 6. Failed DataTable (error.dt / processing=false) still settles cleanly so error UI displays
    const errHarness = createFakeTimerHarness();
    const errCtrl = createEmbedReadinessController({
        origin: ORIGIN,
        maxDataWaitMs: 12000,
        timers: errHarness.timers,
    });
    const brokenTable = { id: 'brokenTable' };
    errCtrl.onTablePreXhr(brokenTable);
    errCtrl.markWindowLoaded();
    errHarness.advanceBy(30);
    assert.equal(errCtrl.isReady, false);
    errCtrl.onTableProcessing(brokenTable, false);
    errHarness.advanceBy(30);
    assert.equal(errCtrl.isReady, true);
});

test('workspace-shell.css group morph uses dedicated easing, asymmetric 400/340ms tokens, and entering groups follow the expand duration', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const css = readFileSync(resolve(testDir, '../../resources/css/workspace-shell.css'), 'utf8');

    assert.equal(css.includes('--ui-easing-workspace-morph: cubic-bezier(0.4, 0, 0.2, 1);'), true);
    assert.equal(css.includes('--ui-motion-workspace-reveal: 240ms;'), true);
    assert.equal(css.includes('--ui-motion-workspace-reveal-delay: 110ms;'), true);
    assert.match(
        css,
        /\.workspace-session-group\.is-entering,\s*\.workspace-session-group\.is-expanding-group\s*\{\s*--workspace-group-motion-duration:\s*var\(--ui-motion-workspace-expand/,
    );

    const groupRule = css.match(/\.workspace-session-group\.is-entering,\s*\.workspace-session-group\.is-morphing,\s*\.workspace-session-group--ghost\.is-closing\s*\{[^}]*\}/);
    assert.ok(groupRule, 'group morph rule must exist');
    assert.equal(groupRule[0].includes('var(--ui-easing-workspace)'), false, 'group width morph must not use the front-loaded hover easing');
    assert.equal(groupRule[0].includes('var(--ui-easing-workspace-morph)'), true);
});

test('resolveTransitionSettleMs derives the settle timer from computed transition durations and delays', () => {
    assert.equal(resolveTransitionSettleMs('0.4s', '0s'), 480);
    assert.equal(resolveTransitionSettleMs('0.4s, 240ms', '0s, 110ms'), 480);
    assert.equal(resolveTransitionSettleMs('0.24s, 0.4s', '0.11s, 0s', { bufferMs: 0 }), 400);
    assert.equal(resolveTransitionSettleMs('0.1s', '0s', { floorMs: 420 }), 420);
    assert.equal(resolveTransitionSettleMs('', '', { floorMs: 420 }), 420);
    assert.equal(resolveTransitionSettleMs(undefined, undefined, { floorMs: 300 }), 300);
    assert.equal(resolveTransitionSettleMs('garbage', '-1s', { floorMs: 0, bufferMs: 0 }), 0);
});

test('normalizeShortcutsLayout safely migrates legacy splitRatio payload, preserves sliver weights, and deduplicates minimizedOrder', () => {
    const legacy = normalizeShortcutsLayout({
        splitRatio: 24,
        minimized: { favorites: false, bookmarks: true, frequent: false },
    });
    assert.deepEqual(legacy.minimized, { favorites: false, bookmarks: true, frequent: false });
    assert.deepEqual(legacy.minimizedOrder, ['bookmarks']);
    assert.deepEqual(legacy.weights, DEFAULT_SHORTCUTS_WEIGHTS);

    const ordered = normalizeShortcutsLayout({
        weights: { favorites: 0.25, bookmarks: 50, frequent: 49.75 },
        minimized: { favorites: false, bookmarks: true, frequent: true },
        minimizedOrder: ['frequent', 'favorites', 'frequent', 'unknown', 'bookmarks'],
    });
    assert.deepEqual(ordered.minimizedOrder, ['frequent', 'bookmarks']);
    assert.ok(ordered.weights.favorites >= 0.01 && ordered.weights.favorites < 1);
});

test('resolveVisibleShortcutsSplitters and resolveShortcutsSplitterPair expose only active horizontal dividers', () => {
    assert.deepEqual(resolveVisibleShortcutsSplitters({}), ['favorites-bookmarks', 'bookmarks-frequent']);
    assert.deepEqual(resolveVisibleShortcutsSplitters({ favorites: true }), ['bookmarks-frequent']);
    assert.deepEqual(resolveVisibleShortcutsSplitters({ bookmarks: true }), ['favorites-bookmarks']);
    assert.deepEqual(resolveVisibleShortcutsSplitters({ frequent: true }), ['favorites-bookmarks']);
    assert.deepEqual(resolveVisibleShortcutsSplitters({ bookmarks: true, frequent: true }), []);

    assert.deepEqual(resolveShortcutsSplitterPair('favorites-bookmarks', {}), ['favorites', 'bookmarks']);
    assert.deepEqual(resolveShortcutsSplitterPair('bookmarks-frequent', {}), ['bookmarks', 'frequent']);
    assert.equal(resolveShortcutsSplitterPair('favorites-bookmarks', { favorites: true }), null);
    assert.deepEqual(resolveShortcutsSplitterPair('bookmarks-frequent', { favorites: true }), ['bookmarks', 'frequent']);
    assert.deepEqual(resolveShortcutsSplitterPair('favorites-bookmarks', { bookmarks: true }), ['favorites', 'frequent']);
    assert.equal(resolveShortcutsSplitterPair('bookmarks-frequent', { bookmarks: true }), null);
    assert.deepEqual(resolveShortcutsSplitterPair('favorites-bookmarks', { frequent: true }), ['favorites', 'bookmarks']);
    assert.equal(resolveShortcutsSplitterPair('bookmarks-frequent', { frequent: true }), null);
    assert.equal(resolveShortcutsSplitterPair('favorites-bookmarks', { bookmarks: true, frequent: true }), null);
    assert.equal(resolveShortcutsSplitterPair('bookmarks-frequent', { bookmarks: true, frequent: true }), null);
});

test('resizeShortcutsPairByDelta isolates width changes to the adjacent pair, enforces 1-3px sliver minimum, and removes minimized panes from flex width', () => {
    assert.ok(MIN_SHORTCUTS_PANE_WIDTH_PX >= 1 && MIN_SHORTCUTS_PANE_WIDTH_PX <= 3);
    const boardWidthPx = 1000;
    const minimized = { favorites: false, bookmarks: false, frequent: false };
    const startSnap = computeOpenPaneWidthPx(DEFAULT_SHORTCUTS_WEIGHTS, minimized, boardWidthPx);

    // 1. Resize between Favorites & Bookmarks -> Frequently Used must not shift
    const resize1 = resizeShortcutsPairByDelta({
        weights: DEFAULT_SHORTCUTS_WEIGHTS,
        minimized,
        splitterKey: 'favorites-bookmarks',
        deltaPx: 90,
        boardWidthPx,
    });
    assert.equal(resize1.changed, true);
    const snap1 = computeOpenPaneWidthPx(resize1.weights, minimized, boardWidthPx);
    assert.ok(Math.abs(snap1.widthsPx.frequent - startSnap.widthsPx.frequent) < 0.001, 'Frequently Used width must stay untouched');
    assert.ok(Math.abs((snap1.widthsPx.favorites - startSnap.widthsPx.favorites) - 90) < 0.01);

    // 2. Extreme drag on Bookmarks <-> Frequently Used clamps at 2px sliver floor and leaves Favorites untouched
    const resizeExtreme = resizeShortcutsPairByDelta({
        weights: resize1.weights,
        minimized,
        splitterKey: 'bookmarks-frequent',
        deltaPx: -800,
        boardWidthPx,
    });
    const snap2 = computeOpenPaneWidthPx(resizeExtreme.weights, minimized, boardWidthPx);
    assert.ok(Math.abs(snap2.widthsPx.favorites - snap1.widthsPx.favorites) < 0.001, 'Favorites width must stay untouched when dragging bookmarks-frequent');
    assert.ok(snap2.widthsPx.bookmarks >= MIN_SHORTCUTS_PANE_WIDTH_PX - 0.01, 'Bookmarks must respect 2px minimum floor');
    assert.ok(snap2.widthsPx.bookmarks <= 3, 'Extreme drag must allow squeezing pane down to 1-3 px sliver');
    assert.ok(snap2.widthsPx.frequent >= MIN_SHORTCUTS_PANE_WIDTH_PX - 0.01, 'Frequently Used must respect 2px minimum floor');
    const totalUsed = snap2.widthsPx.favorites + snap2.widthsPx.bookmarks + snap2.widthsPx.frequent + (2 * SHORTCUTS_SPLITTER_WIDTH_PX);
    assert.ok(Math.abs(totalUsed - boardWidthPx) < 0.01, 'Total pane + splitter widths must equal boardWidthPx with zero overlap');

    // 3. Minimized bookmarks dock leaves 0px horizontal reservation and only 1 visible splitter
    const minBookmarks = { favorites: false, bookmarks: true, frequent: false };
    const bridged = resizeShortcutsPairByDelta({
        weights: DEFAULT_SHORTCUTS_WEIGHTS,
        minimized: minBookmarks,
        splitterKey: 'favorites-bookmarks',
        deltaPx: 120,
        boardWidthPx,
    });
    const snapBridged = computeOpenPaneWidthPx(bridged.weights, minBookmarks, boardWidthPx);
    assert.equal(snapBridged.widthsPx.bookmarks, 0);
    assert.ok(Math.abs(
        (snapBridged.widthsPx.favorites + snapBridged.widthsPx.frequent + SHORTCUTS_SPLITTER_WIDTH_PX)
        - boardWidthPx,
    ) < 0.01);

    // 4. Shrinking board width from 1400px -> 760px clamps sub-2px weights back to at least 2px per open pane
    const clampedWeights = clampOpenPaneWeights({
        weights: { favorites: 0.05, bookmarks: 99, frequent: 0.95 },
        minimized,
        boardWidthPx: 760,
    });
    const snapNarrow = computeOpenPaneWidthPx(clampedWeights, minimized, 760);
    assert.ok(snapNarrow.widthsPx.favorites >= MIN_SHORTCUTS_PANE_WIDTH_PX - 0.01);
    assert.ok(snapNarrow.widthsPx.bookmarks >= MIN_SHORTCUTS_PANE_WIDTH_PX - 0.01);
    assert.ok(snapNarrow.widthsPx.frequent >= MIN_SHORTCUTS_PANE_WIDTH_PX - 0.01);
});

test('workspace-shell.js clears stage loading indicator when initialReadyResolve runs or booting on #shortcuts', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const shellJs = readFileSync(resolve(testDir, '../../resources/js/workspace-shell.js'), 'utf8');

    const resolveBlock = shellJs.match(/initialReadyResolve\s*=\s*\(\)\s*=>\s*\{[\s\S]*?\n\s*\};/);
    assert.ok(resolveBlock, 'initialReadyResolve handler must exist');
    assert.equal(
        resolveBlock[0].includes('syncStageControls()'),
        true,
        'initialReadyResolve must re-sync stage controls so .is-loading clears even when no iframe finalize runs',
    );

    const shortcutsBoot = shellJs.match(/if\s*\([\s\S]*?window\.location\.hash\s*===\s*`#\$\{SHORTCUTS_KEY\}`[\s\S]*?\)\s*\{[\s\S]*?\n\s*\}\s*else\s*\{/);
    assert.ok(shortcutsBoot, '#shortcuts boot branch must exist');
    const resolveIdx = shortcutsBoot[0].indexOf('initialReadyResolve');
    const notifyIdx = shortcutsBoot[0].indexOf('notifyActiveSurface');
    assert.ok(resolveIdx !== -1 && notifyIdx !== -1 && resolveIdx < notifyIdx, '#shortcuts boot must resolve initialReady before notifyActiveSurface');
});

test('workspace-shell.js wires bottom-dock minimize/restore, narrow sliver class, and cursor-tracking splitter grip', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const shellJs = readFileSync(resolve(testDir, '../../resources/js/workspace-shell.js'), 'utf8');

    assert.equal(shellJs.includes('[data-shortcuts-dock]'), true);
    assert.equal(shellJs.includes('data-shortcuts-pane-restore='), true);
    assert.equal(shellJs.includes("paneEl.classList.toggle('is-sliver', isSliver)"), true);
    assert.equal(shellJs.includes("'--splitter-grip-y'"), true);
    assert.equal(shellJs.includes("splitterEl.addEventListener('pointerenter'"), true);
    assert.equal(shellJs.includes('resolveVisibleShortcutsSplitters(shortcutsLayout.minimized)'), true);
});

test('workspace and page transitions run ease-in enter and ease-out exit simultaneously over page fade duration', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const shellCss = readFileSync(resolve(testDir, '../../resources/css/workspace-shell.css'), 'utf8');
    const appCss = readFileSync(resolve(testDir, '../../resources/css/app.css'), 'utf8');
    const shellJs = readFileSync(resolve(testDir, '../../resources/js/workspace-shell.js'), 'utf8');

    assert.equal(appCss.includes('--ui-easing-enter: ease-in;'), true);
    assert.equal(appCss.includes('--ui-easing-exit: ease-out;'), true);

    const pageFadeRule = appCss.match(/\.ui-page-fade\s*\{[\s\S]*?\}/);
    assert.ok(pageFadeRule, '.ui-page-fade rule must exist');
    assert.equal(pageFadeRule[0].includes('var(--ui-easing-enter, ease-in)'), true);

    const stageRule = shellCss.match(/\.content-area\.workspace-stage\s*\{[\s\S]*?\}/);
    assert.ok(stageRule, '.content-area.workspace-stage rule must exist');
    assert.equal(stageRule[0].includes('background: var(--md-surface);'), true);

    const activePanelRule = shellCss.match(/\.workspace-panel\.is-active\s*\{[\s\S]*?\}/);
    assert.ok(activePanelRule, '.workspace-panel.is-active rule must exist');
    assert.equal(activePanelRule[0].includes('var(--ui-page-fade-duration, 500ms)'), true);
    assert.equal(activePanelRule[0].includes('var(--ui-easing-enter, ease-in)'), true);

    const leavingPanelRule = shellCss.match(/\.workspace-panel\.is-leaving\s*\{[\s\S]*?\}/);
    assert.ok(leavingPanelRule, '.workspace-panel.is-leaving rule must exist');
    assert.equal(leavingPanelRule[0].includes('animation: workspace-panel-leave var(--ui-page-fade-duration, 500ms) var(--ui-easing-exit, ease-out) both;'), true);

    const leaveKeyframes = shellCss.match(/@keyframes workspace-panel-leave\s*\{[\s\S]*?\n\}/);
    assert.ok(leaveKeyframes, '@keyframes workspace-panel-leave must exist');
    assert.equal(leaveKeyframes[0].includes('opacity: var(--workspace-leave-opacity, 1);'), true);
    assert.equal(leaveKeyframes[0].includes('opacity: 0;'), true);

    assert.equal(shellJs.includes("event.animationName === 'workspace-panel-leave'"), true);
    assert.equal(shellJs.includes("panel.style.removeProperty('--workspace-leave-opacity')"), true);
    assert.equal(shellJs.includes("panel.setAttribute('aria-hidden', 'true');"), true);
    assert.equal(shellJs.includes("panel.removeAttribute('aria-hidden');"), true);
    assert.equal(shellJs.includes('panel.inert = true;'), true);
    assert.equal(shellJs.includes('panel.inert = false;'), true);
});

test('WorkspaceSessionStore enforces pinned/unpinned zone partitioning, maxPinnedSessions cap, and duplicate placement', () => {
    let clock = 1000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxPinnedSessions: 2,
        now: () => (clock += 100),
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const a = store.openWorkspace({ moduleKey: 'dashboard', label: 'Dashboard', url: `${ORIGIN}${PREFIX}/purchasing/dashboard` });
    const b = store.openWorkspace({ moduleKey: 'quotations', label: 'Quotation', url: `${ORIGIN}${PREFIX}/purchasing/quotations` });
    const c = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` });

    // Pin c -> moves to index 0 (end of pinned zone)
    const pinC = store.pinGroup(c.group.groupId);
    assert.equal(pinC.changed, true);
    assert.equal(pinC.pinned, true);
    assert.deepEqual(store.groups().map((g) => g.groupId), [c.group.groupId, a.group.groupId, b.group.groupId]);

    // Pin b -> moves to index 1 (end of pinned zone, right after c)
    const pinB = store.pinGroup(b.group.groupId);
    assert.equal(pinB.changed, true);
    assert.deepEqual(store.groups().map((g) => g.groupId), [c.group.groupId, b.group.groupId, a.group.groupId]);

    // Pinning a exceeds maxPinnedSessions=2
    const pinA = store.pinGroup(a.group.groupId);
    assert.equal(pinA.changed, false);
    assert.equal(pinA.blocked, true);
    assert.equal(pinA.reason, 'max_pinned_reached');

    // Duplicating a pinned group creates an unpinned copy at the start of the unpinned zone (index 2)
    const dup = store.duplicateGroup(c.group.groupId);
    assert.equal(dup.group.pinned, false);
    assert.deepEqual(store.groups().map((g) => g.groupId), [c.group.groupId, b.group.groupId, dup.group.groupId, a.group.groupId]);

    // Unpinning c moves it to the start of the unpinned zone (right after b)
    const unpinC = store.unpinGroup(c.group.groupId);
    assert.equal(unpinC.changed, true);
    assert.equal(unpinC.pinned, false);
    assert.deepEqual(store.groups().map((g) => g.groupId), [b.group.groupId, c.group.groupId, dup.group.groupId, a.group.groupId]);
});

test('WorkspaceSessionStore protects pinned groups from close, bulk close, and capacity eviction while allowing child close', () => {
    let clock = 2000;
    const store = new WorkspaceSessionStore({
        maxSessions: 4,
        maxPinnedSessions: 2,
        now: () => (clock += 100),
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const p1 = store.openWorkspace({ moduleKey: 'purchase-orders', label: 'Purchase Order', url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders` });
    store.markLoaded(p1.frame.frameId);
    store.pinGroup(p1.group.groupId);

    // Open child frame inside pinned group
    const child = store.openChildFrame({
        groupId: p1.group.groupId,
        sourceFrameId: p1.frame.frameId,
        label: 'PO-001',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders/1`,
    });
    store.markLoaded(child.frame.frameId);

    // Direct close of pinned parent / group is blocked unless force: true
    const blockedParent = store.closeFrame(p1.frame.frameId);
    assert.equal(blockedParent.closed, false);
    assert.equal(blockedParent.blockedByPin, true);

    const blockedGroup = store.closeGroup(p1.group.groupId);
    assert.equal(blockedGroup.closed, false);
    assert.equal(blockedGroup.blockedByPin, true);

    // Closing child frame inside pinned group succeeds and returns closedSnapshots
    const closedChild = store.closeFrame(child.frame.frameId);
    assert.equal(closedChild.closed, true);
    assert.equal(closedChild.closedSnapshots.length, 1);
    assert.equal(closedChild.closedSnapshots[0].role, 'child');
    assert.equal(store.getGroup(p1.group.groupId).frames.length, 1);

    // Open unpinned sessions to fill capacity (maxSessions=4)
    const u1 = store.openWorkspace({ moduleKey: 'quotations', label: 'Quotation', url: `${ORIGIN}${PREFIX}/purchasing/quotations` });
    store.markLoaded(u1.frame.frameId);
    const u2 = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` });
    store.markLoaded(u2.frame.frameId);
    const u3 = store.openWorkspace({ moduleKey: 'claims', label: 'Claims', url: `${ORIGIN}${PREFIX}/purchasing/claims` });
    store.markLoaded(u3.frame.frameId);

    // Opening one more evicts oldest unpinned (u1), NEVER pinned p1 even though p1 is older
    const u4 = store.openWorkspace({ moduleKey: 'reports', label: 'Reports', url: `${ORIGIN}${PREFIX}/purchasing/reports` });
    assert.equal(u4.blocked, false);
    assert.ok(store.getGroup(p1.group.groupId), 'pinned group must survive capacity eviction');
    assert.equal(store.getGroup(u1.group.groupId), null, 'oldest clean unpinned group must be evicted');

    // closeGroupsToRight from u2 skips pinned groups and returns closedSnapshots for u3, u4
    const rightRes = store.closeGroupsToRight(u2.group.groupId);
    assert.equal(rightRes.closedSnapshots.length, 2);
    assert.ok(store.getGroup(p1.group.groupId));
    assert.ok(store.getGroup(u2.group.groupId));

    // closeAllGroups preserves pinned group p1 and activates it
    const allRes = store.closeAllGroups();
    assert.equal(allRes.closedSnapshots.length, 1);
    assert.equal(allRes.closedSnapshots[0].url, `${ORIGIN}${PREFIX}/purchasing/shipments`);
    assert.deepEqual(store.groups().map((g) => g.groupId), [p1.group.groupId]);
    assert.equal(store.activeTarget().groupId, p1.group.groupId);
});

test('WorkspaceSessionStore clamps moveGroup and moveGroupByOffset strictly within pinned and unpinned zones', () => {
    const store = new WorkspaceSessionStore({
        maxSessions: 10,
        maxPinnedSessions: 4,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const g1 = store.openWorkspace({ moduleKey: 'dashboard', label: 'Dashboard', url: `${ORIGIN}${PREFIX}/purchasing/dashboard` }).group;
    const g2 = store.openWorkspace({ moduleKey: 'quotations', label: 'Quotation', url: `${ORIGIN}${PREFIX}/purchasing/quotations` }).group;
    const g3 = store.openWorkspace({ moduleKey: 'shipments', label: 'Shipments', url: `${ORIGIN}${PREFIX}/purchasing/shipments` }).group;
    const g4 = store.openWorkspace({ moduleKey: 'reports', label: 'Reports', url: `${ORIGIN}${PREFIX}/purchasing/reports` }).group;

    store.pinGroup(g1.groupId);
    store.pinGroup(g2.groupId);
    // Order: [g1(P), g2(P), g3(U), g4(U)]

    // Moving g1 right stays inside pinned zone
    assert.equal(store.moveGroupByOffset(g1.groupId, 1).moved, true);
    assert.deepEqual(store.groups().map((g) => g.groupId), [g2.groupId, g1.groupId, g3.groupId, g4.groupId]);

    // Attempting to move g1 further right into unpinned zone is clamped (no-op)
    assert.equal(store.moveGroup(g1.groupId, 3).moved, false);
    assert.deepEqual(store.groups().map((g) => g.groupId), [g2.groupId, g1.groupId, g3.groupId, g4.groupId]);

    // Attempting to move unpinned g3 left into pinned zone is clamped (no-op)
    assert.equal(store.moveGroup(g3.groupId, 0).moved, false);

    // Moving unpinned g4 left swaps with g3 inside unpinned zone
    const moveU = store.moveGroupByOffset(g4.groupId, -1);
    assert.equal(moveU.moved, true);
    assert.equal(moveU.zoneIndex, 0);
    assert.equal(moveU.zoneTotal, 2);
    assert.deepEqual(store.groups().map((g) => g.groupId), [g2.groupId, g1.groupId, g4.groupId, g3.groupId]);

    const display = store.displayGroups();
    assert.equal(display[0].canMoveLeft, false);
    assert.equal(display[0].canMoveRight, true);
    assert.equal(display[1].canMoveLeft, true);
    assert.equal(display[1].canMoveRight, false);
    assert.equal(display[2].canMoveLeft, false);
    assert.equal(display[2].canMoveRight, true);
});

test('WorkspaceSessionStore seedHibernatedPinned restores pinned tabs hibernated without changing active target', () => {
    const store = new WorkspaceSessionStore({
        maxSessions: 10,
        maxPinnedSessions: 4,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const restored = store.seedHibernatedPinned({
        moduleKey: 'shipments',
        label: 'Shipments',
        url: `${ORIGIN}${PREFIX}/purchasing/shipments`,
    });
    assert.equal(restored.blocked, false);
    assert.equal(restored.group.pinned, true);
    assert.equal(restored.frame.hibernated, true);
    assert.equal(restored.frame.loading, false);
    assert.equal(store.isShortcutsActive(), true);

    const initial = store.seedInitial({
        moduleKey: 'dashboard',
        label: 'Dashboard',
        url: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        pinned: false,
    });
    assert.deepEqual(store.groups().map((g) => g.groupId), [restored.group.groupId, initial.group.groupId]);
    assert.equal(store.activeTarget().groupId, initial.group.groupId);
});

test('sanitizePersistedPinnedList and sanitizeRecentlyClosedList enforce same-origin, security allowlist, deduplication, and caps', () => {
    const raw = [
        { url: `${ORIGIN}${PREFIX}/purchasing/shipments?_embed=1`, label: 'Shipments', moduleKey: 'shipments', closedAt: 100 },
        { url: `${ORIGIN}${PREFIX}/purchasing/shipments`, label: 'Shipments Dup', moduleKey: 'shipments', closedAt: 200 },
        { url: 'https://evil.example.com/phish', label: 'Evil', moduleKey: 'evil', closedAt: 300 },
        { url: 'javascript:alert(1)', label: 'XSS', moduleKey: 'xss', closedAt: 400 },
        { url: `${ORIGIN}${PREFIX}/login`, label: 'Login', moduleKey: 'login', closedAt: 500 },
        { url: `${ORIGIN}${PREFIX}/profile/security`, label: 'Security', moduleKey: 'security', closedAt: 600 },
        { url: `${ORIGIN}${PREFIX}/exports/abc/download`, label: 'Download', moduleKey: 'exports', closedAt: 700 },
        { url: `${ORIGIN}${PREFIX}/purchasing/quotations?signature=secret`, label: 'Signed', moduleKey: 'quotations', closedAt: 800 },
        { url: `${ORIGIN}${PREFIX}/purchasing/quotations`, label: 'Quotations', moduleKey: 'quotations', closedAt: 900 },
    ];

    const pinned = sanitizePersistedPinnedList(raw, { origin: ORIGIN, pathPrefix: PREFIX, maxPinned: DEFAULT_MAX_PINNED_SESSIONS });
    assert.equal(pinned.length, 2);
    assert.deepEqual(pinned.map((item) => item.url), [
        `${ORIGIN}${PREFIX}/purchasing/shipments`,
        `${ORIGIN}${PREFIX}/purchasing/quotations`,
    ]);

    const recent = sanitizeRecentlyClosedList(raw, { origin: ORIGIN, pathPrefix: PREFIX, maxEntries: DEFAULT_MAX_RECENTLY_CLOSED });
    assert.equal(recent.length, 2);
    assert.deepEqual(recent.map((item) => item.url), [
        `${ORIGIN}${PREFIX}/purchasing/shipments`,
        `${ORIGIN}${PREFIX}/purchasing/quotations`,
    ]);

    const many = Array.from({ length: 15 }, (_, idx) => ({
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders/${idx + 1}`,
        label: `PO ${idx + 1}`,
        moduleKey: 'purchase-orders',
        closedAt: idx + 1,
    }));
    assert.equal(sanitizePersistedPinnedList(many, { origin: ORIGIN, pathPrefix: PREFIX }).length, DEFAULT_MAX_PINNED_SESSIONS);
    assert.equal(sanitizeRecentlyClosedList(many, { origin: ORIGIN, pathPrefix: PREFIX }).length, DEFAULT_MAX_RECENTLY_CLOSED);
});

test('workspace-shell.js wires pinned tabs, drag-and-drop reorder, recently closed recovery, and WCAG 2.5.7 keyboard controls', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const shellJs = readFileSync(resolve(testDir, '../../resources/js/workspace-shell.js'), 'utf8');
    const shellCss = readFileSync(resolve(testDir, '../../resources/css/workspace-shell.css'), 'utf8');

    assert.equal(shellJs.includes('adasi:workspace:pinned:'), true);
    assert.equal(shellJs.includes('adasi:workspace:recently-closed:'), true);
    assert.equal(shellJs.includes('requestTogglePinGroup'), true);
    assert.equal(shellJs.includes('requestMoveGroupByOffset'), true);
    assert.equal(shellJs.includes('reopenRecentlyClosed'), true);
    assert.equal(shellJs.includes("tablistEl.addEventListener('pointerdown'"), true);
    assert.equal(shellJs.includes("event.key === 'ArrowLeft'"), true);
    assert.equal(shellJs.includes("event.key === 'ArrowRight'"), true);
    assert.equal(shellJs.includes("event.code === 'KeyP'"), true);
    assert.equal(shellJs.includes("event.code !== 'KeyT'"), true);
    assert.equal(shellCss.includes('.workspace-session-group.is-dragging'), true);
    assert.equal(shellCss.includes('.workspace-session-group.is-last-pinned::after'), true);
    assert.equal(shellCss.includes('.ui-environment-tag'), true);
    assert.equal(shellJs.includes('buildHistoryProjection'), true);
    assert.equal(shellJs.includes('buildShortcutsHistoryProjection'), true);
    assert.equal(shellJs.includes('lastProjectedContextUrl'), true);
    assert.equal(shellJs.includes('resolvePopstateNavigation'), true);
    assert.equal(shellJs.includes('resolveContextUrl'), true);
    assert.equal(shellJs.includes('nextConfig.contextLinks'), true);
});

test('workspace-shell history projection preserves internalUrl and popstate ignores untrusted context descriptions without pushState loops', () => {
    const appUuid = '934f47ee-06a4-4f2d-a619-e7cf35e9d91b';
    const contextLinks = {
        enabled: true,
        appUuid,
        pathPrefix: 'v2/ADSI_PROD',
        sessionMap: [
            {
                action: 'purords410m000',
                route: 'purchasing.purchase-orders.show',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADSI_PROD/purchasing/purchase-orders/{id}',
                params: ['id'],
                queryAllowlist: ['tab'],
                hashedKeys: ['id'],
                description: 'Purchase Order Details',
            },
        ],
    };

    const internalUrl = `${ORIGIN}${PREFIX}/purchasing/purchase-orders/gwpZa2E78jMEB3zbrvNnYXP5?tab=items&return_url=%2Fv2%2FADSI_PROD%2Fpurchasing%2Fpurchase-orders`;
    const projection = buildHistoryProjection({
        internalUrl,
        origin: ORIGIN,
        contextLinks,
        frameId: 'frame-1',
        groupId: 'group-1',
        title: 'Purchase Order Details',
    });

    assert.equal(projection.state.internalUrl, internalUrl);
    assert.ok(projection.displayUrl.startsWith(`${ORIGIN}${PREFIX}/${appUuid}?favoriteContext=`));
    assert.equal(resolveContextUrl(projection.displayUrl, ORIGIN, contextLinks)?.internalUrl, `${ORIGIN}${PREFIX}/purchasing/purchase-orders/gwpZa2E78jMEB3zbrvNnYXP5?tab=items`);

    const disabledProjection = buildHistoryProjection({
        internalUrl,
        origin: ORIGIN,
        contextLinks: { ...contextLinks, enabled: false },
        frameId: 'frame-1',
        groupId: 'group-1',
        title: 'Purchase Order Details',
    });
    assert.equal(disabledProjection.displayUrl, internalUrl);

    // Live frame popstate must focus without pushing or replacing history
    const liveNav = resolvePopstateNavigation({
        eventState: { frameId: 'frame-1', url: projection.displayUrl },
        currentHref: projection.displayUrl,
        hash: '',
        origin: ORIGIN,
        contextLinks,
        menuPathTitles: new Map(),
        hasLiveFrame: (id) => id === 'frame-1',
    });
    assert.deepEqual(liveNav, { kind: 'activate-frame', frameId: 'frame-1' });

    // Cold-start/un-cached popstate with spoofed XSS description must ignore description and use replace mode (SEC-05)
    const spoofedPayload = encodeScv2Payload('<img onerror=alert(1)>', 'purords410m000', 'id=gwpZa2E78jMEB3zbrvNnYXP5&tab=items');
    const spoofedUrl = `${ORIGIN}${PREFIX}/${appUuid}?favoriteContext=${encodeURIComponent(spoofedPayload)}&LogicalId=${encodeURIComponent('lid://adasi.portal.purchasing')}`;
    const menuPathTitles = new Map([[`${PREFIX}/purchasing/purchase-orders/gwpZa2E78jMEB3zbrvNnYXP5`, 'Official PO Title']]);

    const coldNav = resolvePopstateNavigation({
        eventState: null,
        currentHref: spoofedUrl,
        hash: '',
        origin: ORIGIN,
        contextLinks,
        menuPathTitles,
        hasLiveFrame: () => false,
    });
    assert.equal(coldNav.kind, 'open-url');
    assert.equal(coldNav.historyMode, 'replace');
    assert.equal(coldNav.label, 'Official PO Title');
    assert.equal(coldNav.label.includes('<img'), false);
    assert.equal(coldNav.url, `${ORIGIN}${PREFIX}/purchasing/purchase-orders/gwpZa2E78jMEB3zbrvNnYXP5?tab=items`);

    // Malformed context URL at appUuid must never recurse into iframe loading
    const invalidPop = resolvePopstateNavigation({
        eventState: null,
        currentHref: `${ORIGIN}${PREFIX}/${appUuid}?favoriteContext=tampered`,
        hash: '',
        origin: ORIGIN,
        contextLinks,
        menuPathTitles,
        hasLiveFrame: () => false,
    });
    assert.deepEqual(invalidPop, { kind: 'ignore' });
});

test('shortcuts history stays at bare appUuid on fresh login and retains previous context URL after visiting pages', () => {
    const appUuid = '934f47ee-06a4-4f2d-a619-e7cf35e9d91b';
    const shortcutsUrl = `${ORIGIN}${PREFIX}/${appUuid}`;
    const contextLinks = {
        enabled: true,
        appUuid,
        pathPrefix: 'v2/ADSI_PROD',
        shortcutsUrl,
        sessionMap: [
            {
                action: 'purords000m000',
                route: 'purchasing.purchase-orders.index',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADSI_PROD/purchasing/purchase-orders',
                params: [],
                queryAllowlist: ['status'],
                hashedKeys: [],
                description: 'Purchase Order',
            },
            {
                action: 'purshps000m000',
                route: 'purchasing.shipments.index',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADSI_PROD/purchasing/shipments',
                params: [],
                queryAllowlist: ['status'],
                hashedKeys: [],
                description: 'Shipments & Logistics',
            },
        ],
    };

    // 1. Fresh login on Shortcuts -> clean bare appUuid (no #shortcuts, no ?favoriteContext=)
    const initialShortcuts = buildShortcutsHistoryProjection({
        lastContextUrl: null,
        origin: ORIGIN,
        contextLinks,
        dashboardUrl: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        shortcutsUrl,
        title: 'Shortcuts',
    });
    assert.equal(initialShortcuts.target, shortcutsUrl);
    assert.equal(initialShortcuts.state.shortcuts, true);
    assert.equal(initialShortcuts.state.lastContextUrl, null);

    // Back/reload onto bare appUuid resolves cleanly to Shortcuts
    assert.deepEqual(
        resolvePopstateNavigation({
            eventState: null,
            currentHref: shortcutsUrl,
            hash: '',
            origin: ORIGIN,
            contextLinks,
            menuPathTitles: new Map(),
            hasLiveFrame: () => false,
        }),
        { kind: 'shortcuts' }
    );

    // 2. Navigate from Shortcuts to Purchase Orders -> long context URL
    const poProjection = buildHistoryProjection({
        internalUrl: `${ORIGIN}${PREFIX}/purchasing/purchase-orders?status=active`,
        origin: ORIGIN,
        contextLinks,
        frameId: 'frame-po',
        groupId: 'group-po',
        title: 'Purchase Order',
    });
    assert.ok(poProjection.displayUrl.includes('favoriteContext='));

    // 3. Return to Shortcuts -> URL follows previous PO context URL (NOT dashboard#shortcuts)
    const backToShortcuts = buildShortcutsHistoryProjection({
        lastContextUrl: poProjection.displayUrl,
        origin: ORIGIN,
        contextLinks,
        dashboardUrl: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        shortcutsUrl,
        title: 'Shortcuts',
    });
    assert.equal(backToShortcuts.target, poProjection.displayUrl);
    assert.equal(backToShortcuts.state.shortcuts, true);
    assert.equal(backToShortcuts.state.lastContextUrl, poProjection.displayUrl);

    // 4. Open Shipments then return to Shortcuts -> URL now follows Shipments context URL
    const shpProjection = buildHistoryProjection({
        internalUrl: `${ORIGIN}${PREFIX}/purchasing/shipments?status=submitted`,
        origin: ORIGIN,
        contextLinks,
        frameId: 'frame-shp',
        groupId: 'group-shp',
        title: 'Shipments & Logistics',
    });
    const backAfterShipments = buildShortcutsHistoryProjection({
        lastContextUrl: shpProjection.displayUrl,
        origin: ORIGIN,
        contextLinks,
        dashboardUrl: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        shortcutsUrl,
        title: 'Shortcuts',
    });
    assert.equal(backAfterShipments.target, shpProjection.displayUrl);

    // 5. Cross-origin or forged lastContextUrl is rejected
    const forgedShortcuts = buildShortcutsHistoryProjection({
        lastContextUrl: 'https://evil.example/v2/ADSI_PROD/x?favoriteContext=abc',
        origin: ORIGIN,
        contextLinks,
        dashboardUrl: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        shortcutsUrl,
        title: 'Shortcuts',
    });
    assert.equal(forgedShortcuts.target, shortcutsUrl);

    // 6. When contextLinks disabled, legacy #shortcuts hash behavior stays preserved
    const legacyShortcuts = buildShortcutsHistoryProjection({
        lastContextUrl: poProjection.displayUrl,
        origin: ORIGIN,
        contextLinks: { ...contextLinks, enabled: false },
        dashboardUrl: `${ORIGIN}${PREFIX}/purchasing/dashboard`,
        shortcutsUrl,
        title: 'Shortcuts',
    });
    assert.equal(legacyShortcuts.target, `${ORIGIN}${PREFIX}/purchasing/dashboard#shortcuts`);
});

test('workspace-shell handles embedded login redirects via Session Ended modal and exposes getDirtyFrames', async () => {
    const { readFile } = await import('node:fs/promises');
    const source = await readFile(new URL('../../resources/js/workspace-shell.js', import.meta.url), 'utf8');

    const loginCheckIdx = source.indexOf("frameDoc.querySelector('[data-auth-login-page]')");
    const topLevelIdx = source.indexOf('if (isTopLevelOnlyUrl(frameHref, origin, pathPrefix))');
    assert.ok(loginCheckIdx > 0 && topLevelIdx > 0, 'both checks exist in onFrameLoad');
    assert.ok(
        loginCheckIdx < topLevelIdx,
        'login document detection in onFrameLoad must run before isTopLevelOnlyUrl so dirty tabs are not destroyed by breakoutToTop',
    );
    assert.match(source, /window\.AdasiSessionSync\?\.requestReconnect/);
    assert.match(source, /getDirtyFrames\(\)\s*\{[\s\S]*frame\.isDirty/);
});

test('frontend top-level and download classifiers stay aligned with non-shell document, export, template, dashboard, and UUID routes', () => {
    const downloadPaths = [
        `${ORIGIN}${PREFIX}/finance/drp/abc123/export`,
        `${ORIGIN}${PREFIX}/finance/master-invoices/export`,
        `${ORIGIN}${PREFIX}/supplier/price-history/export`,
        `${ORIGIN}${PREFIX}/local-invoice-documents/doc123`,
        `${ORIGIN}${PREFIX}/supplier-master-documents/doc123`,
        `${ORIGIN}${PREFIX}/ga-claim-documents/doc123`,
        `${ORIGIN}${PREFIX}/supplier-registrations/att123/documents/doc123`,
        `${ORIGIN}${PREFIX}/finance/payment-vouchers/pv123/documents/doc123`,
        `${ORIGIN}${PREFIX}/purchasing/payment-vouchers/pv123/documents/doc123`,
        `${ORIGIN}${PREFIX}/finance/local-procurement/import/po/template`,
        `${ORIGIN}${PREFIX}/purchasing/local-procurement/import/gr/template`,
    ];

    for (const url of downloadPaths) {
        assert.equal(isDownloadOrBinaryUrl(url, ORIGIN), true, `Expected download/binary match for ${url}`);
        assert.equal(isTopLevelOnlyUrl(url, ORIGIN, PREFIX), true, `Expected top-level match for ${url}`);
    }

    // Bare root /dashboard and unresolved portal.page UUIDs must break out to top-level,
    // while role dashboards remain shell-frameable.
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/dashboard`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}/dashboard`, ORIGIN, ''), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/934f47ee-06a4-4f2d-a619-e7cf35e9d91b`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/4e5e45a9-e738-4870-9deb-5efddec4dec9`, ORIGIN, PREFIX), true);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/purchasing/dashboard`, ORIGIN, PREFIX), false);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/finance/dashboard`, ORIGIN, PREFIX), false);
    assert.equal(isTopLevelOnlyUrl(`${ORIGIN}${PREFIX}/supplier/dashboard`, ORIGIN, PREFIX), false);
});

test('WorkspaceSessionStore openChildFrame deduplicates rapid double-clicks on an in-flight stagedNew child URL', () => {
    let now = 50_000;
    const store = new WorkspaceSessionStore({
        maxSessions: 12,
        maxLiveFrames: 6,
        staleAfterMs: 120_000,
        now: () => now,
        catalog: SAMPLE_CATALOG,
        indexPaths: SAMPLE_INDEX_PATHS,
    });

    const parent = store.seedInitial({
        moduleKey: 'purchase-orders',
        label: 'Purchase Order',
        url: `${ORIGIN}${PREFIX}/purchasing/purchase-orders`,
    });

    const childUrl = `${ORIGIN}${PREFIX}/purchasing/purchase-orders/POHASH99`;
    const first = store.openChildFrame({
        groupId: parent.group.groupId,
        sourceFrameId: parent.frame.frameId,
        label: 'PO-099',
        url: childUrl,
        stageOnly: true,
        showSessionWhileStaged: false,
    });
    const second = store.openChildFrame({
        groupId: parent.group.groupId,
        sourceFrameId: parent.frame.frameId,
        label: 'PO-099',
        url: childUrl,
        stageOnly: true,
        showSessionWhileStaged: false,
    });

    assert.equal(first.created, true);
    assert.equal(second.created, false);
    assert.equal(second.staged, true);
    assert.equal(second.frame.frameId, first.frame.frameId);
    assert.equal(parent.group.frames.length, 2, 'must not spawn duplicate staged child frames');
});

test('WorkspaceNavQueue supports resetSlotTimer and defers slot timeout while document is hidden', () => {
    const harness = createFakeTimerHarness();
    let hidden = false;
    const timeouts = [];

    const queue = new WorkspaceNavQueue({
        maxConcurrent: 2,
        slotTimeoutMs: 10000,
        visibilityGraceMs: 4000,
        isDocumentHidden: () => hidden,
        timers: harness.timers,
        onSlotTimeout: (entry, reason) => timeouts.push({ frameId: entry.frameId, reason }),
    });

    queue.enqueue({ frameId: 'f1', groupId: 'g1', label: 'Tab 1', url: `${ORIGIN}${PREFIX}/purchasing/requisitions` });
    harness.advanceBy(8000);
    assert.equal(queue.resetSlotTimer('f1'), true);
    harness.advanceBy(8000);
    assert.deepEqual(timeouts, [], 'resetSlotTimer extends deadline cleanly');

    // Switch browser tab to background right before expiry: must not destroy healthy loading tab while hidden.
    hidden = true;
    harness.advanceBy(5000);
    assert.deepEqual(timeouts, [], 'must defer timeout while document is hidden');

    // Return to foreground: grace window runs before failing if still stuck.
    hidden = false;
    queue.notifyVisibilityRestored();
    harness.advanceBy(3900);
    assert.deepEqual(timeouts, [], 'must honor visibility grace window after foregrounding');
    harness.advanceBy(200);
    assert.deepEqual(timeouts, [{ frameId: 'f1', reason: 'timeout' }]);
});

test('createEmbedReadinessController resolves via DOM-to-load fallback when window.onload stalls on a subresource', async () => {
    const harness = createFakeTimerHarness();
    let readyDetail = null;

    const ctrl = createEmbedReadinessController({
        origin: ORIGIN,
        maxDomLoadWaitMs: 5000,
        maxDataWaitMs: 12000,
        timers: harness.timers,
        onReady: (detail) => { readyDetail = detail; },
    });

    ctrl.markDomReady();
    harness.advanceBy(4900);
    assert.equal(ctrl.isReady, false);

    // Subresource stalls past maxDomLoadWaitMs -> promotes windowLoaded and settles after 1 rAF paint.
    harness.advanceBy(200);
    assert.equal(ctrl.isReady, true);
    const result = await ctrl.whenReady();
    assert.equal(result.reason, 'settled');
    assert.equal(readyDetail.reason, 'settled');
});

test('workspace-shell and embed-bridge wire early boot ack, inaccessible-frame probing, error listener, and Retry toast', async () => {
    const { readFile } = await import('node:fs/promises');
    const shellSrc = await readFile(new URL('../../resources/js/workspace-shell.js', import.meta.url), 'utf8');
    const bridgeSrc = await readFile(new URL('../../resources/js/workspace-embed-bridge.js', import.meta.url), 'utf8');

    assert.match(bridgeSrc, /shell\.notifyEmbedBooted\?\.\(window\)/);
    assert.match(shellSrc, /notifyEmbedBooted\(frameWin\)/);
    assert.match(shellSrc, /handleInaccessibleFrameLoad/);
    assert.match(shellSrc, /probeSessionStatus/);
    assert.match(shellSrc, /cleanupAbortedFrame/);
    assert.match(shellSrc, /addEventListener\('error'/);
    assert.match(shellSrc, /retryFailedNavigation/);
    assert.match(shellSrc, /announceLiveStatus\(message\)/);
});

test('workspace-shell-core supports subdirectory basePath prefixes for top-level detection, module matching, and child navigation', () => {
    const subOrigin = 'http://localhost';
    const subBase = '/adasi_portal_supplier/public';
    const subPrefix = `${subBase}/v2/ADSI_PROD`;

    // Top-level-only detection works both with leading slash and bare pathPrefix.
    for (const prefixVariant of [subPrefix, subPrefix.slice(1)]) {
        assert.equal(isTopLevelOnlyUrl(`${subOrigin}${subPrefix}/dashboard`, subOrigin, prefixVariant), true);
        assert.equal(
            isTopLevelOnlyUrl(`${subOrigin}${subPrefix}/934f47ee-06a4-4f2d-a619-e7cf35e9d91b`, subOrigin, prefixVariant),
            true
        );
        assert.equal(isTopLevelOnlyUrl(`${subOrigin}${subBase}/login`, subOrigin, prefixVariant), true);
        assert.equal(isTopLevelOnlyUrl(`${subOrigin}${subPrefix}/purchasing/dashboard`, subOrigin, prefixVariant), false);
        assert.equal(isTopLevelOnlyUrl(`${subOrigin}${subPrefix}/purchasing/purchase-orders`, subOrigin, prefixVariant), false);
    }

    const subCatalog = [
        { key: 'dashboard', label: 'Dashboard', href: `${subOrigin}${subPrefix}/purchasing/dashboard` },
        { key: 'purchase-orders', label: 'Purchase Order', href: `${subOrigin}${subPrefix}/purchasing/purchase-orders` },
    ];
    const subIndexPaths = [
        `${subPrefix}/purchasing/dashboard`,
        `${subPrefix}/purchasing/purchase-orders`,
    ];

    assert.equal(
        resolveModuleKey(`${subOrigin}${subPrefix}/purchasing/purchase-orders/POHASH99`, subCatalog),
        'purchase-orders'
    );

    const childNav = classifyInternalNavigation({
        currentUrl: `${subOrigin}${subPrefix}/purchasing/purchase-orders`,
        targetUrl: `${subOrigin}${subPrefix}/purchasing/purchase-orders/POHASH99`,
        origin: subOrigin,
        pathPrefix: subPrefix,
        indexPaths: subIndexPaths,
    });
    assert.equal(childNav, 'join-child');

    const filterNav = classifyInternalNavigation({
        currentUrl: `${subOrigin}${subPrefix}/purchasing/purchase-orders`,
        targetUrl: `${subOrigin}${subPrefix}/purchasing/purchase-orders?status=open`,
        origin: subOrigin,
        pathPrefix: subPrefix,
        indexPaths: subIndexPaths,
    });
    assert.equal(filterNav, 'in-place');

    const topLevelNav = classifyInternalNavigation({
        currentUrl: `${subOrigin}${subPrefix}/purchasing/purchase-orders`,
        targetUrl: `${subOrigin}${subBase}/login`,
        origin: subOrigin,
        pathPrefix: subPrefix,
        indexPaths: subIndexPaths,
    });
    assert.equal(topLevelNav, 'top-level');
});

test('ERP duplicate session badges omit # on first tab, use parenthetical index on duplicates, extract context hints, and keep WCAG target sizes in compact desktop bar', async () => {
    assert.equal(formatSessionDuplicateBadge(1, 1), '');
    assert.equal(formatSessionDuplicateBadge(1, 3), '');
    assert.equal(formatSessionDuplicateBadge(2, 3), '(2)');
    assert.equal(formatSessionDuplicateBadge(3, 3), '(3)');

    assert.equal(
        formatSessionContextHint(`${ORIGIN}${PREFIX}/purchasing/purchase-orders?_embed=1&status=submitted&search=ADSI`),
        'status: submitted · "ADSI"',
    );
    assert.equal(
        formatSessionContextHint(`${ORIGIN}${PREFIX}/purchasing/purchase-orders?supplier=ABCDEF1234567890`, {
            childTitle: 'PO-2026-0091',
            parentTitle: 'Purchase Orders',
        }),
        'PO-2026-0091',
    );
    assert.equal(formatSessionContextHint(`${ORIGIN}${PREFIX}/purchasing/purchase-orders?_embed=1&status=all`), '');

    const { readFile } = await import('node:fs/promises');
    const shellSrc = await readFile(new URL('../../resources/js/workspace-shell.js', import.meta.url), 'utf8');
    const wsCss = await readFile(new URL('../../resources/css/workspace-shell.css', import.meta.url), 'utf8');
    const appCss = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    assert.doesNotMatch(shellSrc, /`#\$\{group\.duplicateIndex\}`/);
    assert.match(shellSrc, /formatSessionDuplicateBadge\(group\.duplicateIndex,\s*group\.duplicateTotal\)/);
    assert.match(shellSrc, /formatSessionContextHint\(/);

    // Infor LN proportional desktop bar aligns with sidebar-control and slims desktop topbar without locking hybrid touchscreens via any-pointer.
    assert.doesNotMatch(wsCss, /@media\s*\(\s*any-pointer:\s*coarse\s*\)/);
    assert.match(wsCss, /@media\s*\(min-width:\s*992px\)\s*and\s*\(pointer:\s*fine\)\s*\{[^}]*html\[data-workspace-shell="true"\]\s*\{\s*--topbar-height:\s*40px;/s);
    assert.match(appCss, /html\[data-workspace-shell="true"\] \.sidebar-control\s*\{[^}]*height:\s*var\(--ui-density-sessionbar-min,\s*2\.875rem\)/s);
    assert.match(wsCss, /\.workspace-session__close,\s*\.workspace-session__menu-btn\s*\{[^}]*width:\s*1\.5rem;\s*height:\s*1\.5rem;/s);
    assert.match(wsCss, /@media\s*\(pointer:\s*coarse\),\s*\(max-width:\s*991\.98px\)[\s\S]*min-height:\s*2\.75rem;/);

    const dupRule = wsCss.match(/\.workspace-session__dup-index\s*\{[^}]*\}/)?.[0] || '';
    assert.ok(dupRule && !dupRule.includes('border:'), 'Duplicate badge must be clean inline text without heavy box border');
    assert.ok(dupRule.includes('tabular-nums'), 'Duplicate badge must preserve tabular-nums');
});
