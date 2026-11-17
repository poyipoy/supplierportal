import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    appendEmbedParam,
    canonicalizeUrl,
    classifyInternalNavigation,
    computeWaterFillTabCap,
    isDownloadOrBinaryUrl,
    isNonMutatingPostUrl,
    isSameOriginUrl,
    isTopLevelOnlyUrl,
    resolveModuleKey,
    safeWebUrl,
    SHORTCUTS_KEY,
    stripEmbedParam,
    WorkspaceTabStore,
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

test('WorkspaceTabStore allows multiple instances of the same menu, disambiguates labels, and falls back to Shortcuts when all closed', () => {
    let now = 1000;
    const store = new WorkspaceTabStore({
        maxTabs: 12,
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

test('WorkspaceTabStore joins Parent + multi-Child frames, auto-collapses when switching away, and re-expands on focus', () => {
    let now = 2000;
    const store = new WorkspaceTabStore({
        maxTabs: 12,
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

test('WorkspaceTabStore hibernates LRU clean inactive frames beyond maxLiveFrames while protecting dirty and active frames', () => {
    let now = 5000;
    const store = new WorkspaceTabStore({
        maxTabs: 12,
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

test('WorkspaceTabStore blocks opening past maxTabs only if all eviction candidates are dirty', () => {
    let now = 9000;
    const store = new WorkspaceTabStore({
        maxTabs: 3,
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

test('WorkspaceTabStore tracks stale invalidation on mutations and TTL without auto-reloading dirty frames', () => {
    let now = 10_000;
    const store = new WorkspaceTabStore({
        maxTabs: 12,
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

test('workspace-shell.css enforces hover/focus-visible close icon and smooth non-bouncy tab & group motion', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const repoRoot = resolve(testDir, '../..');
    const css = readFileSync(resolve(repoRoot, 'resources/css/workspace-shell.css'), 'utf8');

    assert.equal(css.includes('--ui-motion-workspace: var(--ui-motion-sidebar, 280ms);'), true);
    assert.equal(css.includes('--workspace-tab-min-width: 7rem;'), true);
    assert.equal(css.includes('--workspace-tab-stacked-min-width: 8.5rem;'), true);
    assert.equal(css.includes('--workspace-tab-cap: var(--workspace-tab-max-width);'), true);
    assert.equal(css.includes('--ui-motion-workspace-expand: 320ms;'), true);
    assert.equal(css.includes('--ui-motion-workspace-collapse: 260ms;'), true);
    assert.equal(css.includes('--ui-easing-workspace:'), true);
    assert.equal(css.includes('.workspace-tab-group.is-entering'), true);
    assert.equal(css.includes('.workspace-tab-group.is-morphing'), true);
    assert.equal(css.includes('.workspace-tab-group.is-expanding-group'), true);
    assert.equal(css.includes('.workspace-tab-group.is-collapsing-group'), true);
    assert.equal(css.includes('.workspace-tab.is-morph-pill'), true);
    assert.equal(css.includes('.workspace-tab__morph-overlay'), true);
    assert.equal(css.includes('.workspace-tab-group__lip-ghost'), true);
    assert.equal(css.includes('.workspace-tab-group__collapse-tail'), true);
    assert.equal(css.includes('.workspace-tab-group--ghost.is-closing'), true);
    assert.equal(css.includes('.workspace-tab--ghost.is-closing'), true);
    assert.equal(css.includes('.workspace-tab.is-revealing'), true);
    assert.equal(css.includes('workspace-tab-enter'), false, 'Legacy vertical tab bounce keyframe must stay removed');
    assert.equal(css.includes('workspace-pill-enter'), false, 'Legacy pill slide keyframe must stay removed');
    assert.equal(css.includes('translateY(-1px)'), false, 'Stack pseudo-tab hover lift bounce must stay removed');
    assert.equal(css.includes('scale(0.9'), false, 'Scale press bounce on tabbar controls must stay removed');
    assert.equal(css.includes('scaleX(0.35)'), false, 'ScaleX underline micro-pop must stay removed');
    assert.equal(css.includes('transition: all'), false, 'Never use transition: all on workspace controls');
    assert.equal(css.includes('.workspace-tab:hover > .workspace-tab__close'), true);
    assert.equal(css.includes('.workspace-tab:has(:focus-visible) > .workspace-tab__close'), true);
    assert.equal(css.includes('.workspace-tab:not(.workspace-tab--shortcuts):not(.workspace-tab--stacked)'), true);
    assert.equal(css.includes('var(--workspace-tab-natural-width'), true);
    assert.equal(css.includes('@media (hover: none)'), true);
    assert.equal(css.includes('transition: none !important'), true);
    const groupRuleMatch = css.match(/\.workspace-tab-group\s*\{[^}]*\}/);
    assert.ok(groupRuleMatch && !groupRuleMatch[0].includes('max-width: 100%'), '.workspace-tab-group must not clamp expanded width via max-width: 100%');
    const coarseMatch = css.match(/@media\s*\(hover:\s*none\)\s*\{[\s\S]*?\n\}/);
    assert.ok(coarseMatch && coarseMatch[0].includes(':has(:focus-visible) > .workspace-tab__close'), '@media (hover: none) must keep keyboard :focus-visible close targets visible');
    assert.equal(css.includes('.workspace-tab-group--shortcuts'), true);
    const shortcutsRuleMatch = css.match(/\.workspace-tab--shortcuts,\s*[\s\S]*?\{[^}]*\}/);
    assert.ok(shortcutsRuleMatch && shortcutsRuleMatch[0].includes('border: 0;'), 'Shortcuts tab must have no top/left/right box border, leaving only underline');

    const tabbarBlade = readFileSync(resolve(repoRoot, 'resources/views/partials/workspace-tab-bar.blade.php'), 'utf8');
    assert.ok(
        tabbarBlade.indexOf('id="workspace-tab-group-shortcuts"') < tabbarBlade.indexOf('data-workspace-tablist'),
        'Shortcuts must sit fixed before the scrollable tablist',
    );
    assert.equal(tabbarBlade.includes('aria-owns="workspace-tab-group-shortcuts"'), true);
});

test('WorkspaceTabStore stageOnly keeps current tab active until committed and cleans up on discard', () => {
    let now = 20_000;
    const store = new WorkspaceTabStore({
        maxTabs: 12,
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
