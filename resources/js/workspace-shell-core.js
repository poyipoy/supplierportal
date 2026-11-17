export const EMBED_QUERY_PARAM = '_embed';
export const SHORTCUTS_KEY = 'shortcuts';
export const DEFAULT_MAX_TABS = 12;
export const DEFAULT_MAX_LIVE_FRAMES = 6;
export const DEFAULT_STALE_AFTER_MS = 120_000;

const DOWNLOAD_BINARY_SUFFIX_RE = /(\/exports\/[^/]+\/download|\/shared\/pdf\/|\/attachments\/|\/export-presets(?:\/|$)|\/export\/|\/receipt\/print$|import-template)/i;
const TOP_LEVEL_SUFFIX_RE = /(\/login|\/logout|\/confirm-password|\/forgot-password|\/two-factor-challenge|\/oauth\/|\/sso\/|\/profile\/security|\/profile\/two-factor|\/supplier-context|\/admin\/users\/create|\/admin\/users\/[^/]+\/edit|\/finance\/vendor-master\/[^/]+\/payment-term|\/verify-receipt\/|\/supplier\/register|\/supplier\/registration|\/locale\/|\/exports\/[^/]+\/download|\/shared\/pdf\/|\/attachments\/|\/export-presets(?:\/|$)|\/export\/|\/receipt\/print$|\/print$)/i;
const NON_MUTATING_POST_SUFFIX_RE = /(\/material-calculations\/(?:totals|preview)|\/import-preview|\/import\/(?:po|gr)\/preview|\/export-form|\/revalidate)$/i;

function normalizePathname(rawUrl, origin = 'http://localhost') {
    try {
        return new URL(rawUrl, origin).pathname.replace(/\/+$/, '') || '/';
    } catch {
        return '/';
    }
}

export function isNonMutatingPostUrl(rawUrl, origin = (typeof window !== 'undefined' ? window.location.origin : 'http://localhost')) {
    if (!isSameOriginUrl(rawUrl, origin)) return false;
    return NON_MUTATING_POST_SUFFIX_RE.test(normalizePathname(rawUrl, origin));
}

export function isDownloadOrBinaryUrl(rawUrl, origin = (typeof window !== 'undefined' ? window.location.origin : 'http://localhost')) {
    if (!isSameOriginUrl(rawUrl, origin)) return false;
    const pathname = normalizePathname(rawUrl, origin);
    return DOWNLOAD_BINARY_SUFFIX_RE.test(pathname);
}

export function safeWebUrl(rawUrl, origin = 'http://localhost') {
    if (!rawUrl || typeof rawUrl !== 'string') return null;
    try {
        const parsed = new URL(rawUrl.trim(), origin);
        return (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed.href : null;
    } catch {
        return null;
    }
}

export function isSameOriginUrl(rawUrl, origin = (typeof window !== 'undefined' ? window.location.origin : '')) {
    if (!rawUrl || typeof rawUrl !== 'string') return false;
    const trimmed = rawUrl.trim();
    if (!trimmed || trimmed.startsWith('#') || /^(javascript|data|mailto|tel|blob):/i.test(trimmed)) {
        return false;
    }

    try {
        const parsed = new URL(trimmed, origin);
        return (parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.origin === origin;
    } catch {
        return false;
    }
}

export function appendEmbedParam(rawUrl, origin = 'http://localhost') {
    if (!rawUrl || typeof rawUrl !== 'string') return '';

    const isRelative = !/^[a-zA-Z][a-zA-Z\d+\-.]*:/.test(rawUrl);
    const parsed = new URL(rawUrl, origin);
    parsed.searchParams.set(EMBED_QUERY_PARAM, '1');

    if (isRelative) {
        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }

    return parsed.toString();
}

export function stripEmbedParam(rawUrl, origin = 'http://localhost') {
    if (!rawUrl || typeof rawUrl !== 'string') return '';

    const isRelative = !/^[a-zA-Z][a-zA-Z\d+\-.]*:/.test(rawUrl);
    const parsed = new URL(rawUrl, origin);
    parsed.searchParams.delete(EMBED_QUERY_PARAM);

    if (isRelative) {
        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }

    return parsed.toString();
}

export function canonicalizeUrl(rawUrl, origin = 'http://localhost') {
    return stripEmbedParam(rawUrl, origin);
}

export function isTopLevelOnlyUrl(rawUrl, origin = 'http://localhost', pathPrefix = '') {
    if (!isSameOriginUrl(rawUrl, origin)) return false;

    const parsed = new URL(rawUrl, origin);
    const normalizedPath = parsed.pathname.replace(/\/+$/, '') || '/';
    if (normalizedPath === '/' || normalizedPath === pathPrefix.replace(/\/+$/, '')) {
        return false;
    }

    return TOP_LEVEL_SUFFIX_RE.test(normalizedPath) || parsed.searchParams.has('signature');
}

export function resolveModuleKey(rawUrl, catalog = [], origin = 'http://localhost') {
    if (!rawUrl) return 'page';

    let parsed;
    try {
        parsed = new URL(rawUrl, origin);
    } catch {
        return 'page';
    }

    const normalizedTarget = parsed.pathname.replace(/\/+$/, '') || '/';

    if (Array.isArray(catalog) && catalog.length > 0) {
        let bestMatch = null;
        let bestLen = -1;

        for (const item of catalog) {
            if (!item || !item.href || !item.key) continue;
            try {
                const itemPath = normalizePathname(item.href, origin);
                if (normalizedTarget === itemPath || normalizedTarget.startsWith(`${itemPath}/`)) {
                    if (itemPath.length > bestLen) {
                        bestLen = itemPath.length;
                        bestMatch = item.key;
                    }
                }
            } catch {
                // Ignore malformed catalog entries.
            }
        }

        if (bestMatch) return bestMatch;

        // Match sibling overview views under the same parent module directory (e.g. /comparison/historical).
        const targetSegments = normalizedTarget.split('/').filter(Boolean);
        if (targetSegments.length >= 4) {
            const targetParent = targetSegments.slice(0, -1).join('/');
            for (const item of catalog) {
                if (!item || !item.href || !item.key) continue;
                try {
                    const itemPath = normalizePathname(item.href, origin);
                    const itemSegments = itemPath.split('/').filter(Boolean);
                    if (itemSegments.length === targetSegments.length && itemSegments.slice(0, -1).join('/') === targetParent) {
                        return item.key;
                    }
                } catch {
                    // Ignore malformed catalog entries.
                }
            }
        }
    }

    if (/\/profile(\/|$)/i.test(normalizedTarget)) return 'profile';
    if (/\/notifications(\/|$)/i.test(normalizedTarget)) return 'notifications';
    if (/\/exports(\/|$)/i.test(normalizedTarget)) return 'exports';

    const parts = normalizedTarget.split('/').filter(Boolean);
    return parts.slice(-2).join('-') || 'page';
}

function normalizedPathnameAndSearch(rawUrl, origin = 'http://localhost') {
    try {
        const parsed = new URL(stripEmbedParam(rawUrl, origin), origin);
        return `${parsed.pathname.replace(/\/+$/, '') || '/'}${parsed.search}`;
    } catch {
        return '';
    }
}

/**
 * Classify an internal link click inside an active iframe frame so the workspace shell
 * knows whether to keep navigation in-place, join as a child tab, focus the parent frame,
 * spawn a separate workspace group, or break out to the top window.
 */
export function classifyInternalNavigation({
    currentUrl,
    targetUrl,
    currentModuleKey,
    targetModuleKey,
    sourceRole = 'parent',
    parentUrl = null,
    indexPaths = [],
    origin = 'http://localhost',
    pathPrefix = '',
} = {}) {
    if (!targetUrl || typeof targetUrl !== 'string') {
        return 'invalid';
    }
    if (!isSameOriginUrl(targetUrl, origin)) {
        return safeWebUrl(targetUrl, origin) ? 'external' : 'invalid';
    }
    if (isTopLevelOnlyUrl(targetUrl, origin, pathPrefix) || isDownloadOrBinaryUrl(targetUrl, origin)) {
        return 'top-level';
    }

    const effectiveTargetKey = targetModuleKey || currentModuleKey;
    if (currentModuleKey && effectiveTargetKey && effectiveTargetKey !== currentModuleKey) {
        return 'new-workspace';
    }

    const currentPath = normalizePathname(currentUrl, origin);
    const targetParsed = new URL(stripEmbedParam(targetUrl, origin), origin);
    const targetPath = targetParsed.pathname.replace(/\/+$/, '') || '/';

    if (targetPath === currentPath) {
        return 'in-place';
    }

    if (sourceRole === 'child' && parentUrl) {
        const parentPath = normalizePathname(parentUrl, origin);
        if (
            targetPath === parentPath
            && (!targetParsed.search || normalizedPathnameAndSearch(targetUrl, origin) === normalizedPathnameAndSearch(parentUrl, origin))
        ) {
            return 'focus-parent';
        }
        // Navigating between Show and Edit of the same record stays inside the child frame.
        if (targetPath === `${currentPath}/edit` || currentPath === `${targetPath}/edit`) {
            return 'in-place';
        }
    }

    const indexPathSet = new Set(
        (Array.isArray(indexPaths) ? indexPaths : []).map((entry) => normalizePathname(entry, origin)),
    );

    if (indexPathSet.has(targetPath)) {
        return 'in-place';
    }

    // Fallback for sibling static overview routes (same directory depth, hyphenated view slug, non-record).
    const currentSegs = currentPath.split('/').filter(Boolean);
    const targetSegs = targetPath.split('/').filter(Boolean);
    const prefixSegCount = pathPrefix.split('/').filter(Boolean).length;
    const relativeDepth = targetSegs.length - prefixSegCount;

    const lastTargetSeg = (targetSegs[targetSegs.length - 1] || '').toLowerCase();
    const isChildActionSlug = ['create', 'edit', 'new'].includes(lastTargetSeg);

    if (
        indexPathSet.has(currentPath)
        && relativeDepth >= 3
        && targetSegs.length === currentSegs.length
        && currentSegs.slice(0, -1).join('/') === targetSegs.slice(0, -1).join('/')
        && !isChildActionSlug
        && /^[a-z]+(?:-[a-z]+)*$/.test(targetSegs[targetSegs.length - 1] || '')
    ) {
        return 'in-place';
    }

    return 'join-child';
}

export class WorkspaceTabStore {
    constructor({
        maxTabs = DEFAULT_MAX_TABS,
        maxLiveFrames = DEFAULT_MAX_LIVE_FRAMES,
        staleAfterMs = DEFAULT_STALE_AFTER_MS,
        now = () => Date.now(),
        catalog = [],
        indexPaths = [],
    } = {}) {
        this.shortcutsKey = SHORTCUTS_KEY;
        this.maxTabs = Math.max(2, Number(maxTabs) || DEFAULT_MAX_TABS);
        this.maxLiveFrames = Math.max(2, Number(maxLiveFrames) || DEFAULT_MAX_LIVE_FRAMES);
        this.staleAfterMs = Math.max(1000, Number(staleAfterMs) || DEFAULT_STALE_AFTER_MS);
        this.now = now;
        this.catalog = catalog;
        this.indexPaths = indexPaths;
        this._groups = [];
        this._activeTarget = { type: SHORTCUTS_KEY };
        this._groupSeq = 0;
        this._frameSeq = 0;
    }

    activeTarget() {
        return { ...this._activeTarget };
    }

    isShortcutsActive() {
        return this._activeTarget.type === SHORTCUTS_KEY;
    }

    groups() {
        return this._groups.map((group) => ({
            ...group,
            frames: group.frames.slice(),
        }));
    }

    getGroup(groupId) {
        return this._groups.find((group) => group.groupId === groupId) || null;
    }

    getFrame(frameId) {
        for (const group of this._groups) {
            const frame = group.frames.find((entry) => entry.frameId === frameId);
            if (frame) return frame;
        }
        return null;
    }

    getFrameContext(frameId) {
        for (const group of this._groups) {
            const index = group.frames.findIndex((entry) => entry.frameId === frameId);
            if (index !== -1) {
                return { group, frame: group.frames[index], index };
            }
        }
        return null;
    }

    activeGroup() {
        if (this._activeTarget.type !== 'frame') return null;
        return this.getGroup(this._activeTarget.groupId);
    }

    activeFrame() {
        if (this._activeTarget.type !== 'frame') return null;
        return this.getFrame(this._activeTarget.frameId);
    }

    totalFrames() {
        return this._groups.reduce((sum, group) => sum + group.frames.length, 0);
    }

    liveFrameCount() {
        return this._groups.reduce(
            (sum, group) => sum + group.frames.filter((frame) => !frame.hibernated).length,
            0,
        );
    }

    _nextGroupId() {
        this._groupSeq += 1;
        return `ws-${this._groupSeq}`;
    }

    _nextFrameId() {
        this._frameSeq += 1;
        return `f-${this._frameSeq}`;
    }

    _createFrame({ groupId, role, title, url, timestamp, staged = false, stagedNew = false }) {
        const cleanUrl = canonicalizeUrl(url);
        return {
            frameId: this._nextFrameId(),
            groupId,
            role,
            title: title || 'ADASI Portal',
            url: cleanUrl,
            embedUrl: appendEmbedParam(cleanUrl),
            isDirty: false,
            stale: false,
            loading: true,
            hibernated: false,
            staged: Boolean(staged),
            stagedNew: Boolean(stagedNew),
            createdAt: timestamp,
            lastAccessedAt: timestamp,
            lastLoadedAt: timestamp,
        };
    }

    _evictOldestCleanSlotForCapacity(protectedGroupId = null) {
        const cleanInactiveGroups = this._groups
            .filter((group) => (
                group.groupId !== protectedGroupId
                && !(this._activeTarget.type === 'frame' && this._activeTarget.groupId === group.groupId)
                && !group.frames.some((frame) => frame.isDirty)
            ))
            .sort((a, b) => a.lastAccessedAt - b.lastAccessedAt);

        if (cleanInactiveGroups.length > 0) {
            const victim = cleanInactiveGroups[0];
            const closedFrameIds = victim.frames.map((frame) => frame.frameId);
            this._groups = this._groups.filter((group) => group.groupId !== victim.groupId);
            return { closedGroupIds: [victim.groupId], closedFrameIds };
        }

        // Fall back to evicting the oldest clean non-active child frame if no whole group can close.
        const cleanChildren = [];
        for (const group of this._groups) {
            for (const frame of group.frames) {
                if (
                    frame.role === 'child'
                    && !frame.isDirty
                    && !(this._activeTarget.type === 'frame' && this._activeTarget.frameId === frame.frameId)
                ) {
                    cleanChildren.push(frame);
                }
            }
        }
        cleanChildren.sort((a, b) => a.lastAccessedAt - b.lastAccessedAt);
        if (cleanChildren.length > 0) {
            const victimFrame = cleanChildren[0];
            const ctx = this.getFrameContext(victimFrame.frameId);
            if (ctx) {
                ctx.group.frames.splice(ctx.index, 1);
                if (ctx.group.activeFrameId === victimFrame.frameId) {
                    const fallback = ctx.group.frames[Math.max(0, ctx.index - 1)] || ctx.group.frames[0];
                    ctx.group.activeFrameId = fallback ? fallback.frameId : null;
                }
                return { closedGroupIds: [], closedFrameIds: [victimFrame.frameId] };
            }
        }

        return null;
    }

    rebalanceLiveFrames() {
        const hibernatedFrameIds = [];
        const activeFrameId = this._activeTarget.type === 'frame' ? this._activeTarget.frameId : null;
        const activeGroupId = this._activeTarget.type === 'frame' ? this._activeTarget.groupId : null;

        while (this.liveFrameCount() > this.maxLiveFrames) {
            const candidates = [];
            for (const group of this._groups) {
                const isInactiveGroup = group.groupId !== activeGroupId;
                for (const frame of group.frames) {
                    if (frame.hibernated || frame.isDirty || frame.staged || frame.frameId === activeFrameId) {
                        continue;
                    }
                    candidates.push({ frame, isInactiveGroup });
                }
            }

            if (candidates.length === 0) break;

            candidates.sort((a, b) => {
                if (a.isInactiveGroup !== b.isInactiveGroup) {
                    return a.isInactiveGroup ? -1 : 1;
                }
                return a.frame.lastAccessedAt - b.frame.lastAccessedAt;
            });

            const coldest = candidates[0].frame;
            coldest.hibernated = true;
            coldest.loading = false;
            hibernatedFrameIds.push(coldest.frameId);
        }

        return hibernatedFrameIds;
    }

    seedInitial({ moduleKey, label, url, icon = '', activate = true }) {
        return this.openWorkspace({
            moduleKey,
            label,
            url,
            icon,
            activate,
        });
    }

    openWorkspace({ moduleKey, label, url, icon = '', insertAfterGroupId = null, activate = true, stageOnly = false }) {
        const cleanUrl = canonicalizeUrl(url);
        const resolvedKey = moduleKey || resolveModuleKey(cleanUrl, this.catalog);
        const timestamp = this.now();

        const closedGroupIds = [];
        const closedFrameIds = [];
        while (this.totalFrames() >= this.maxTabs) {
            const evicted = this._evictOldestCleanSlotForCapacity();
            if (!evicted) {
                return {
                    group: null,
                    frame: null,
                    blocked: true,
                    staged: false,
                    closedGroupIds: [],
                    closedFrameIds: [],
                    hibernatedFrameIds: [],
                };
            }
            closedGroupIds.push(...evicted.closedGroupIds);
            closedFrameIds.push(...evicted.closedFrameIds);
        }

        const groupId = this._nextGroupId();
        const frame = this._createFrame({
            groupId,
            role: 'parent',
            title: label || resolvedKey,
            url: cleanUrl,
            timestamp,
            staged: stageOnly,
            stagedNew: stageOnly,
        });

        const group = {
            groupId,
            moduleKey: resolvedKey,
            baseLabel: label || resolvedKey,
            icon: icon || '',
            activeFrameId: frame.frameId,
            lastAccessedAt: timestamp,
            frames: [frame],
        };

        if (insertAfterGroupId) {
            const idx = this._groups.findIndex((item) => item.groupId === insertAfterGroupId);
            if (idx !== -1) {
                this._groups.splice(idx + 1, 0, group);
            } else {
                this._groups.push(group);
            }
        } else {
            this._groups.push(group);
        }

        if (activate && !stageOnly) {
            this._activeTarget = { type: 'frame', groupId: group.groupId, frameId: frame.frameId };
        }

        const hibernatedFrameIds = stageOnly ? [] : this.rebalanceLiveFrames();

        return {
            group,
            frame,
            blocked: false,
            staged: Boolean(stageOnly),
            closedGroupIds,
            closedFrameIds,
            hibernatedFrameIds,
        };
    }

    duplicateGroup(groupId, { stageOnly = false } = {}) {
        const source = this.getGroup(groupId);
        if (!source || source.frames.length === 0) {
            return { blocked: false, staged: false, group: null, frame: null };
        }
        const seedFrame = source.frames.find((f) => f.frameId === source.activeFrameId) || source.frames[0];
        return this.openWorkspace({
            moduleKey: source.moduleKey,
            label: source.baseLabel || seedFrame.title,
            url: seedFrame.url,
            icon: source.icon,
            insertAfterGroupId: source.groupId,
            activate: true,
            stageOnly,
        });
    }

    openChildFrame({ groupId = null, sourceFrameId = null, label, url, moduleKey = null, stageOnly = false }) {
        const targetGroup = groupId
            ? this.getGroup(groupId)
            : (sourceFrameId ? this.getFrameContext(sourceFrameId)?.group : this.activeGroup());

        if (!targetGroup) {
            return this.openWorkspace({ moduleKey, label, url, stageOnly });
        }

        const cleanUrl = canonicalizeUrl(url);
        const signature = normalizedPathnameAndSearch(cleanUrl);
        const existing = targetGroup.frames.find(
            (frame) => !frame.stagedNew && normalizedPathnameAndSearch(frame.url) === signature,
        );

        if (existing) {
            const activation = this.activateFrame(existing.frameId, { stageIfHibernated: stageOnly });
            return {
                group: targetGroup,
                frame: activation.frame,
                created: false,
                blocked: false,
                staged: Boolean(activation.staged),
                wasHibernated: activation.wasHibernated,
                shouldRefresh: activation.shouldRefresh,
                closedGroupIds: [],
                closedFrameIds: [],
                hibernatedFrameIds: activation.hibernatedFrameIds,
            };
        }

        const closedGroupIds = [];
        const closedFrameIds = [];
        while (this.totalFrames() >= this.maxTabs) {
            const evicted = this._evictOldestCleanSlotForCapacity(targetGroup.groupId);
            if (!evicted) {
                return {
                    group: targetGroup,
                    frame: null,
                    created: false,
                    blocked: true,
                    staged: false,
                    wasHibernated: false,
                    shouldRefresh: false,
                    closedGroupIds: [],
                    closedFrameIds: [],
                    hibernatedFrameIds: [],
                };
            }
            closedGroupIds.push(...evicted.closedGroupIds);
            closedFrameIds.push(...evicted.closedFrameIds);
        }

        const timestamp = this.now();
        const child = this._createFrame({
            groupId: targetGroup.groupId,
            role: 'child',
            title: label || targetGroup.baseLabel,
            url: cleanUrl,
            timestamp,
            staged: stageOnly,
            stagedNew: stageOnly,
        });

        targetGroup.frames.push(child);
        if (!stageOnly) {
            targetGroup.activeFrameId = child.frameId;
            targetGroup.lastAccessedAt = timestamp;
            this._activeTarget = {
                type: 'frame',
                groupId: targetGroup.groupId,
                frameId: child.frameId,
            };
        }

        const hibernatedFrameIds = stageOnly ? [] : this.rebalanceLiveFrames();

        return {
            group: targetGroup,
            frame: child,
            created: true,
            blocked: false,
            staged: Boolean(stageOnly),
            wasHibernated: false,
            shouldRefresh: false,
            closedGroupIds,
            closedFrameIds,
            hibernatedFrameIds,
        };
    }

    activateShortcuts() {
        this._activeTarget = { type: SHORTCUTS_KEY };
        return { activeTarget: this.activeTarget() };
    }

    activateGroup(groupId, options = {}) {
        const group = this.getGroup(groupId);
        if (!group || group.frames.length === 0) {
            return { group: null, frame: null, staged: false, wasHibernated: false, shouldRefresh: false, hibernatedFrameIds: [] };
        }

        const targetFrameId = group.frames.some((frame) => !frame.stagedNew && frame.frameId === group.activeFrameId)
            ? group.activeFrameId
            : (group.frames.find((frame) => !frame.stagedNew) || group.frames[group.frames.length - 1]).frameId;

        return this.activateFrame(targetFrameId, options);
    }

    activateFrame(frameId, { stageIfHibernated = false } = {}) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) {
            return { group: null, frame: null, staged: false, wasHibernated: false, shouldRefresh: false, hibernatedFrameIds: [] };
        }

        const { group, frame } = ctx;
        const timestamp = this.now();
        const wasHibernated = Boolean(frame.hibernated);

        if (wasHibernated && stageIfHibernated) {
            frame.hibernated = false;
            frame.stale = false;
            frame.loading = true;
            frame.staged = true;
            frame.stagedNew = false;
            frame._wasHibernatedBeforeStage = true;
            frame.lastLoadedAt = timestamp;
            return { group, frame, staged: true, wasHibernated: true, shouldRefresh: true, hibernatedFrameIds: [] };
        }

        frame.lastAccessedAt = timestamp;
        group.lastAccessedAt = timestamp;
        group.activeFrameId = frame.frameId;
        this._activeTarget = {
            type: 'frame',
            groupId: group.groupId,
            frameId: frame.frameId,
        };

        if (wasHibernated) {
            frame.hibernated = false;
            frame.stale = false;
            frame.loading = true;
            frame.lastLoadedAt = timestamp;
        }

        const hibernatedFrameIds = this.rebalanceLiveFrames();

        if (wasHibernated) {
            return { group, frame, staged: false, wasHibernated: true, shouldRefresh: true, hibernatedFrameIds };
        }

        const aged = (timestamp - frame.lastLoadedAt) > this.staleAfterMs;
        if ((frame.stale || aged) && !frame.isDirty) {
            frame.stale = false;
            frame.lastLoadedAt = timestamp;
            return { group, frame, staged: false, wasHibernated: false, shouldRefresh: true, hibernatedFrameIds };
        }

        if (aged && frame.isDirty) {
            frame.stale = true;
        }

        return { group, frame, staged: false, wasHibernated: false, shouldRefresh: false, hibernatedFrameIds };
    }

    commitStagedFrame(frameId) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) {
            return { ok: false, group: null, frame: null, hibernatedFrameIds: [] };
        }

        const { group, frame } = ctx;
        const timestamp = this.now();
        frame.staged = false;
        frame.stagedNew = false;
        delete frame._wasHibernatedBeforeStage;
        frame.hibernated = false;
        frame.stale = false;
        frame.lastAccessedAt = timestamp;
        frame.lastLoadedAt = timestamp;
        group.activeFrameId = frame.frameId;
        group.lastAccessedAt = timestamp;
        this._activeTarget = {
            type: 'frame',
            groupId: group.groupId,
            frameId: frame.frameId,
        };

        const hibernatedFrameIds = this.rebalanceLiveFrames();

        return { ok: true, group, frame, hibernatedFrameIds };
    }

    discardStagedFrame(frameId) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) {
            return { discarded: false, removedGroupId: null, revertedHibernate: false, frameId };
        }

        const { group, frame, index } = ctx;
        if (frame._wasHibernatedBeforeStage) {
            frame.staged = false;
            frame.stagedNew = false;
            frame.loading = false;
            frame.hibernated = true;
            delete frame._wasHibernatedBeforeStage;
            return { discarded: true, removedGroupId: null, revertedHibernate: true, frameId };
        }

        if (!frame.staged) {
            return { discarded: false, removedGroupId: null, revertedHibernate: false, frameId };
        }

        group.frames.splice(index, 1);
        let removedGroupId = null;
        if (group.frames.length === 0) {
            this._groups = this._groups.filter((g) => g.groupId !== group.groupId);
            removedGroupId = group.groupId;
        } else if (group.activeFrameId === frameId) {
            group.activeFrameId = group.frames[group.frames.length - 1].frameId;
        }

        return { discarded: true, removedGroupId, revertedHibernate: false, frameId };
    }

    _resolveNextTargetAfterGroupRemoval(removedIdx) {
        if (this._groups.length === 0) {
            this._activeTarget = { type: SHORTCUTS_KEY };
            return this.activeTarget();
        }
        const nextGroup = this._groups[Math.min(removedIdx, this._groups.length - 1)] || this._groups[0];
        const nextFrameId = nextGroup.activeFrameId || nextGroup.frames[0]?.frameId;
        if (!nextFrameId) {
            this._activeTarget = { type: SHORTCUTS_KEY };
            return this.activeTarget();
        }
        const res = this.activateFrame(nextFrameId);
        return {
            type: 'frame',
            groupId: res.group.groupId,
            frameId: res.frame.frameId,
            wasHibernated: res.wasHibernated,
            shouldRefresh: res.shouldRefresh,
            hibernatedFrameIds: res.hibernatedFrameIds,
        };
    }

    closeFrame(frameId) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) return { closed: false, closedFrameIds: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };

        const { group, frame, index } = ctx;
        if (frame.role === 'parent') {
            return this.closeGroup(group.groupId);
        }

        const wasActive = this._activeTarget.type === 'frame' && this._activeTarget.frameId === frameId;
        group.frames.splice(index, 1);

        const nextSibling = group.frames[Math.max(0, index - 1)] || group.frames[0] || null;
        if (group.activeFrameId === frameId) {
            group.activeFrameId = nextSibling ? nextSibling.frameId : null;
        }

        if (wasActive && nextSibling) {
            const activation = this.activateFrame(nextSibling.frameId);
            return {
                closed: true,
                closedFrameIds: [frameId],
                nextTarget: this.activeTarget(),
                wasHibernated: activation.wasHibernated,
                shouldRefresh: activation.shouldRefresh,
                hibernatedFrameIds: activation.hibernatedFrameIds,
            };
        }

        return {
            closed: true,
            closedFrameIds: [frameId],
            nextTarget: this.activeTarget(),
            wasHibernated: false,
            shouldRefresh: false,
            hibernatedFrameIds: [],
        };
    }

    closeGroup(groupId) {
        const idx = this._groups.findIndex((group) => group.groupId === groupId);
        if (idx === -1) {
            return { closed: false, closedFrameIds: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        const [removed] = this._groups.splice(idx, 1);
        const closedFrameIds = removed.frames.map((frame) => frame.frameId);
        const wasActive = this._activeTarget.type === 'frame' && this._activeTarget.groupId === groupId;

        if (!wasActive) {
            return {
                closed: true,
                closedFrameIds,
                nextTarget: this.activeTarget(),
                wasHibernated: false,
                shouldRefresh: false,
                hibernatedFrameIds: [],
            };
        }

        const nextTarget = this._resolveNextTargetAfterGroupRemoval(idx);
        return {
            closed: true,
            closedFrameIds,
            nextTarget,
            wasHibernated: Boolean(nextTarget.wasHibernated),
            shouldRefresh: Boolean(nextTarget.shouldRefresh),
            hibernatedFrameIds: nextTarget.hibernatedFrameIds || [],
        };
    }

    dirtyFramesForClose(scope, anchorId = null) {
        let candidateGroups = [];
        if (scope === 'all') {
            candidateGroups = this._groups;
        } else if (scope === 'others') {
            candidateGroups = this._groups.filter((g) => g.groupId !== anchorId);
        } else if (scope === 'right') {
            const idx = this._groups.findIndex((g) => g.groupId === anchorId);
            candidateGroups = idx === -1 ? [] : this._groups.slice(idx + 1);
        } else if (scope === 'group') {
            const g = this.getGroup(anchorId);
            candidateGroups = g ? [g] : [];
        } else if (scope === 'frame') {
            const frame = this.getFrame(anchorId);
            return frame && frame.isDirty ? [frame] : [];
        }

        return candidateGroups.flatMap((group) => group.frames.filter((frame) => frame.isDirty));
    }

    closeOtherGroups(keepGroupId) {
        const keep = this.getGroup(keepGroupId);
        if (!keep) {
            return { closedFrameIds: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        const closedFrameIds = this._groups
            .filter((group) => group.groupId !== keepGroupId)
            .flatMap((group) => group.frames.map((frame) => frame.frameId));

        this._groups = [keep];
        const activation = this.activateGroup(keepGroupId);

        return {
            closedFrameIds,
            nextTarget: this.activeTarget(),
            wasHibernated: activation.wasHibernated,
            shouldRefresh: activation.shouldRefresh,
            hibernatedFrameIds: activation.hibernatedFrameIds,
        };
    }

    closeGroupsToRight(anchorGroupId) {
        const idx = this._groups.findIndex((group) => group.groupId === anchorGroupId);
        if (idx === -1) {
            return { closedFrameIds: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        const removed = this._groups.splice(idx + 1);
        const closedFrameIds = removed.flatMap((group) => group.frames.map((frame) => frame.frameId));

        if (
            this._activeTarget.type === 'frame'
            && !this.getGroup(this._activeTarget.groupId)
        ) {
            const activation = this.activateGroup(anchorGroupId);
            return {
                closedFrameIds,
                nextTarget: this.activeTarget(),
                wasHibernated: activation.wasHibernated,
                shouldRefresh: activation.shouldRefresh,
                hibernatedFrameIds: activation.hibernatedFrameIds,
            };
        }

        return {
            closedFrameIds,
            nextTarget: this.activeTarget(),
            wasHibernated: false,
            shouldRefresh: false,
            hibernatedFrameIds: [],
        };
    }

    closeAllGroups() {
        const closedFrameIds = this._groups.flatMap((group) => group.frames.map((frame) => frame.frameId));
        this._groups = [];
        this._activeTarget = { type: SHORTCUTS_KEY };
        return {
            closedFrameIds,
            nextTarget: this.activeTarget(),
            hibernatedFrameIds: [],
        };
    }

    setDirty(frameId, dirty) {
        const frame = this.getFrame(frameId);
        if (!frame) return;
        frame.isDirty = Boolean(dirty);
    }

    markLoaded(frameId, { url, label } = {}) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) return;
        const { group, frame } = ctx;
        if (url) {
            frame.url = canonicalizeUrl(url);
            frame.embedUrl = appendEmbedParam(frame.url);
        }
        if (label) {
            frame.title = label;
            if (frame.role === 'parent') {
                group.baseLabel = label;
            }
        }
        frame.loading = false;
        frame.stale = false;
        frame.hibernated = false;
        frame.lastLoadedAt = this.now();
    }

    markFresh(frameId) {
        const frame = this.getFrame(frameId);
        if (!frame) return;
        frame.stale = false;
        frame.lastLoadedAt = this.now();
    }

    markSiblingsStale(sourceFrameId = null) {
        const activeFrame = this.activeFrame();
        const originFrameId = sourceFrameId || activeFrame?.frameId;
        for (const group of this._groups) {
            for (const frame of group.frames) {
                if (frame.frameId !== originFrameId) {
                    frame.stale = true;
                }
            }
        }
    }

    displayGroups() {
        const visibleGroups = this._groups.filter((group) => group.frames.some((frame) => !frame.stagedNew));
        const totalsByTitle = new Map();
        for (const group of visibleGroups) {
            const visibleFrames = group.frames.filter((frame) => !frame.stagedNew);
            const parent = visibleFrames[0];
            const key = (parent?.title || group.baseLabel || group.moduleKey).trim().toLowerCase();
            totalsByTitle.set(key, (totalsByTitle.get(key) || 0) + 1);
        }

        const seenByTitle = new Map();
        const activeGroupId = this._activeTarget.type === 'frame' ? this._activeTarget.groupId : null;
        const activeFrameId = this._activeTarget.type === 'frame' ? this._activeTarget.frameId : null;

        return visibleGroups.map((group) => {
            const visibleFrames = group.frames.filter((frame) => !frame.stagedNew);
            const parent = visibleFrames[0];
            const rawTitle = (parent?.title || group.baseLabel || group.moduleKey).trim();
            const titleKey = rawTitle.toLowerCase();
            const duplicateTotal = totalsByTitle.get(titleKey) || 1;
            const nextSeen = (seenByTitle.get(titleKey) || 0) + 1;
            seenByTitle.set(titleKey, nextSeen);

            const isActive = group.groupId === activeGroupId;
            const isExpanded = isActive && visibleFrames.length > 1;
            const isCollapsedStack = !isActive && visibleFrames.length > 1;
            const hasDirty = visibleFrames.some((frame) => frame.isDirty);
            const hasStale = visibleFrames.some((frame) => frame.stale);

            return {
                groupId: group.groupId,
                moduleKey: group.moduleKey,
                baseLabel: group.baseLabel,
                displayLabel: rawTitle,
                icon: group.icon,
                duplicateIndex: nextSeen,
                duplicateTotal,
                isActive,
                isExpanded,
                isCollapsedStack,
                activeFrameId: group.activeFrameId,
                currentActiveFrameId: activeFrameId,
                dirty: hasDirty,
                stale: hasStale,
                hibernated: visibleFrames.every((frame) => frame.hibernated),
                frames: visibleFrames.map((frame) => ({ ...frame })),
            };
        });
    }
}

/**
 * Water-filling dynamic pill cap (Infor/Chrome tab shrinking): trims widest tabs first
 * so open tabs fit cleanly inside the tablist budget down to per-pill minimum floors
 * (default 7rem / 112px, or item-level `minWidth` e.g. 8.5rem / 136px on stacked pills).
 */
export function computeWaterFillTabCap({
    pillWidths = [],
    overheadPx = 0,
    budgetPx = 0,
    minCapPx = 112,
    maxCapPx = 248,
} = {}) {
    const defaultMinCap = Math.max(0, Number(minCapPx) || 0);
    const maxCap = Math.max(defaultMinCap, Number(maxCapPx) || defaultMinCap);
    const budget = Math.max(0, Number(budgetPx) || 0);
    const overhead = Math.max(0, Number(overheadPx) || 0);
    const items = (Array.isArray(pillWidths) ? pillWidths : [])
        .map((entry) => {
            const rawWidth = typeof entry === 'object' && entry !== null ? Number(entry.width) : Number(entry);
            const rawMin = typeof entry === 'object' && entry !== null && entry.minWidth != null
                ? Number(entry.minWidth)
                : defaultMinCap;
            if (!Number.isFinite(rawWidth) || rawWidth <= 0) return null;
            const naturalW = Math.min(rawWidth, maxCap);
            const minW = Math.max(0, Math.min(maxCap, Number.isFinite(rawMin) ? rawMin : defaultMinCap));
            return {
                naturalW,
                floorW: Math.min(naturalW, minW),
            };
        })
        .filter(Boolean);

    if (items.length === 0 || budget <= 0) {
        return maxCap;
    }

    const availableForPills = Math.max(0, budget - overhead);
    const naturalTotal = items.reduce((sum, item) => sum + item.naturalW, 0);
    if (naturalTotal <= availableForPills) {
        return maxCap;
    }

    let remainingBudget = availableForPills;
    let active = [...items];
    let solved = defaultMinCap;

    while (active.length > 0) {
        const sorted = [...active].sort((a, b) => a.naturalW - b.naturalW);
        let prefixSum = 0;
        let candidateCap = maxCap;

        for (let i = 0; i < sorted.length; i += 1) {
            const rem = sorted.length - i;
            const cand = (remainingBudget - prefixSum) / rem;
            if (cand <= sorted[i].naturalW) {
                candidateCap = cand;
                break;
            }
            prefixSum += sorted[i].naturalW;
        }

        const clampedCand = Math.max(defaultMinCap, Math.floor(candidateCap));
        const lockedByFloor = active.filter((item) => item.floorW > clampedCand);
        if (lockedByFloor.length === 0) {
            solved = clampedCand;
            break;
        }

        remainingBudget = Math.max(
            0,
            remainingBudget - lockedByFloor.reduce((sum, item) => sum + item.floorW, 0),
        );
        active = active.filter((item) => item.floorW <= clampedCand);
        if (active.length === 0) {
            solved = defaultMinCap;
            break;
        }
    }

    return Math.max(defaultMinCap, Math.min(maxCap, solved));
}
