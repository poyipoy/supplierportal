export const EMBED_QUERY_PARAM = '_embed';
export const SHORTCUTS_KEY = 'shortcuts';
export const DEFAULT_MAX_SESSIONS = 12;
export const DEFAULT_MAX_TABS = DEFAULT_MAX_SESSIONS;
export const DEFAULT_MAX_LIVE_FRAMES = 6;
export const DEFAULT_STALE_AFTER_MS = 120_000;
export const DEFAULT_STEP_INTERVAL_MS = 220;
export const DEFAULT_MAX_PINNED_SESSIONS = 8;
export const DEFAULT_MAX_RECENTLY_CLOSED = 10;

const DOWNLOAD_BINARY_SUFFIX_RE = /(\/exports\/[^/]+\/download|\/shared\/pdf\/|\/attachments\/|\/local-invoice-documents\/|\/supplier-master-documents\/|\/ga-claim-documents\/|\/supplier-registrations\/[^/]+\/documents\/|\/payment-vouchers\/[^/]+\/documents\/|\/export-presets(?:\/|$)|\/export(?:\/|$)|\/receipt\/print$|import-template|\/import\/(?:po|gr)\/template$)/i;
const TOP_LEVEL_SUFFIX_RE = /(\/login|\/logout|\/confirm-password|\/forgot-password|\/two-factor-challenge|\/oauth\/|\/sso\/|\/profile\/security|\/profile\/two-factor|\/supplier-context|\/admin\/users\/create|\/admin\/users\/[^/]+\/edit|\/finance\/vendor-master\/[^/]+\/payment-term|\/verify-receipt\/|\/supplier\/register|\/supplier\/registration|\/locale\/|\/exports\/[^/]+\/download|\/shared\/pdf\/|\/attachments\/|\/local-invoice-documents\/|\/supplier-master-documents\/|\/ga-claim-documents\/|\/supplier-registrations\/[^/]+\/documents\/|\/payment-vouchers\/[^/]+\/documents\/|\/export-presets(?:\/|$)|\/export(?:\/|$)|\/receipt\/print$|\/print$|import-template|\/import\/(?:po|gr)\/template$)/i;
const UUID_SEGMENT_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NON_MUTATING_POST_SUFFIX_RE = /(\/material-calculations\/(?:totals|preview)|\/import-preview|\/import\/(?:po|gr)\/preview|\/export-form|\/revalidate)$/i;

export function resolveTransitionSettleMs(durations, delays, { floorMs = 0, bufferMs = 80 } = {}) {
    const toMs = (raw) => {
        const text = String(raw ?? '').trim();
        const value = parseFloat(text);
        if (!Number.isFinite(value) || value < 0) return 0;
        return text.endsWith('ms') ? value : value * 1000;
    };
    const durationList = String(durations ?? '').split(',').map(toMs);
    const delayList = String(delays ?? '').split(',').map(toMs);
    const longest = durationList.reduce(
        (max, duration, index) => Math.max(max, duration + delayList[index % delayList.length]),
        0,
    );
    return Math.max(floorMs, Math.round(longest + bufferMs));
}

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
    const strippedPrefix = String(pathPrefix || '').trim().replace(/^\/+|\/+$/g, '');
    const normalizedPrefix = strippedPrefix !== '' ? `/${strippedPrefix}` : '';
    if (normalizedPath === '/' || (normalizedPrefix !== '' && normalizedPath === normalizedPrefix)) {
        return false;
    }

    const relativePath = normalizedPrefix !== '' && normalizedPath.startsWith(`${normalizedPrefix}/`)
        ? normalizedPath.slice(normalizedPrefix.length)
        : normalizedPath;
    if (relativePath === '/dashboard') {
        return true;
    }

    const singleSegment = relativePath.startsWith('/') ? relativePath.slice(1) : relativePath;
    if (singleSegment !== '' && !singleSegment.includes('/') && UUID_SEGMENT_RE.test(singleSegment)) {
        return true;
    }

    return TOP_LEVEL_SUFFIX_RE.test(normalizedPath) || parsed.searchParams.has('signature');
}

const SENSITIVE_STORED_QUERY_KEYS = ['signature', 'token'];

function isStorableSessionUrl(rawUrl, origin, pathPrefix) {
    if (typeof rawUrl !== 'string' || !isSameOriginUrl(rawUrl, origin)) return false;
    if (isTopLevelOnlyUrl(rawUrl, origin, pathPrefix) || isDownloadOrBinaryUrl(rawUrl, origin)) return false;
    try {
        const parsed = new URL(rawUrl, origin);
        return !SENSITIVE_STORED_QUERY_KEYS.some((key) => parsed.searchParams.has(key));
    } catch {
        return false;
    }
}

function normalizeStoredSessionEntry(item, origin, pathPrefix) {
    if (!item || typeof item !== 'object' || !isStorableSessionUrl(item.url, origin, pathPrefix)) return null;
    return {
        url: canonicalizeUrl(item.url, origin),
        label: String(item.label || '').trim() || 'ADASI Portal',
        moduleKey: String(item.moduleKey || '').trim() || null,
        icon: String(item.icon || '').trim(),
    };
}

function sanitizeStoredSessionList(rawList, { origin, pathPrefix, limit }) {
    if (!Array.isArray(rawList)) return [];
    const seen = new Set();
    const result = [];
    for (const item of rawList) {
        const entry = normalizeStoredSessionEntry(item, origin, pathPrefix);
        if (!entry || seen.has(entry.url)) continue;
        seen.add(entry.url);
        result.push({ ...entry, closedAt: Number(item.closedAt) || 0 });
        if (result.length >= limit) break;
    }
    return result;
}

export function sanitizePersistedPinnedList(rawList, {
    origin = 'http://localhost',
    pathPrefix = '',
    maxPinned = DEFAULT_MAX_PINNED_SESSIONS,
} = {}) {
    const limit = Math.max(1, Number(maxPinned) || DEFAULT_MAX_PINNED_SESSIONS);
    return sanitizeStoredSessionList(rawList, { origin, pathPrefix, limit })
        .map(({ url, label, moduleKey, icon }) => ({ url, label, moduleKey, icon }));
}

export function sanitizeRecentlyClosedList(rawList, {
    origin = 'http://localhost',
    pathPrefix = '',
    maxEntries = DEFAULT_MAX_RECENTLY_CLOSED,
} = {}) {
    const limit = Math.max(1, Number(maxEntries) || DEFAULT_MAX_RECENTLY_CLOSED);
    return sanitizeStoredSessionList(rawList, { origin, pathPrefix, limit });
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

export class WorkspaceSessionStore {
    constructor({
        maxSessions = null,
        maxTabs = DEFAULT_MAX_SESSIONS,
        maxLiveFrames = DEFAULT_MAX_LIVE_FRAMES,
        maxPinnedSessions = DEFAULT_MAX_PINNED_SESSIONS,
        staleAfterMs = DEFAULT_STALE_AFTER_MS,
        now = () => Date.now(),
        catalog = [],
        indexPaths = [],
    } = {}) {
        const resolvedMax = Number(maxSessions ?? maxTabs) || DEFAULT_MAX_SESSIONS;
        this.shortcutsKey = SHORTCUTS_KEY;
        this.maxSessions = Math.max(2, resolvedMax);
        this.maxTabs = this.maxSessions;
        this.maxPinnedSessions = Math.max(
            1,
            Math.min(Number(maxPinnedSessions) || DEFAULT_MAX_PINNED_SESSIONS, Math.floor(this.maxSessions / 2)),
        );
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

    pinnedGroupsCount() {
        return this._groups.filter((group) => Boolean(group.pinned)).length;
    }

    unpinnedGroupsCount() {
        return this._groups.length - this.pinnedGroupsCount();
    }

    _partitionBoundaryIndex() {
        const firstUnpinned = this._groups.findIndex((group) => !group.pinned);
        return firstUnpinned === -1 ? this._groups.length : firstUnpinned;
    }

    _snapshotClosedGroup(group) {
        const parent = group.frames.find((frame) => frame.role === 'parent') || group.frames[0];
        return {
            groupId: group.groupId,
            moduleKey: group.moduleKey,
            label: group.baseLabel || parent?.title || group.moduleKey,
            url: parent?.url || '',
            icon: group.icon || '',
            role: 'parent',
            closedAt: this.now(),
        };
    }

    _snapshotClosedFrame(frame, group) {
        return {
            groupId: group.groupId,
            moduleKey: group.moduleKey,
            label: frame.title || group.baseLabel || group.moduleKey,
            url: frame.url,
            icon: group.icon || '',
            role: frame.role,
            closedAt: this.now(),
        };
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

    _createFrame({ groupId, role, title, url, timestamp, staged = false, stagedNew = false, queued = false }) {
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
            queued: Boolean(queued),
            createdAt: timestamp,
            lastAccessedAt: timestamp,
            lastLoadedAt: timestamp,
        };
    }

    _evictOldestCleanSlotForCapacity(protectedGroupId = null) {
        const cleanInactiveGroups = this._groups
            .filter((group) => (
                group.groupId !== protectedGroupId
                && !group.pinned
                && !(this._activeTarget.type === 'frame' && this._activeTarget.groupId === group.groupId)
                && !group.frames.some((frame) => frame.isDirty || frame.staged || frame.stagedNew || frame.queued || frame.loading)
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
                    && !frame.staged
                    && !frame.stagedNew
                    && !frame.queued
                    && !frame.loading
                    && !(this._activeTarget.type === 'frame' && this._activeTarget.frameId === frame.frameId)
                ) {
                    cleanChildren.push({ frame, isPinnedGroup: Boolean(group.pinned) });
                }
            }
        }
        cleanChildren.sort((a, b) => {
            if (a.isPinnedGroup !== b.isPinnedGroup) return a.isPinnedGroup ? 1 : -1;
            return a.frame.lastAccessedAt - b.frame.lastAccessedAt;
        });
        if (cleanChildren.length > 0) {
            const victimFrame = cleanChildren[0].frame;
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
                const isPinnedGroup = Boolean(group.pinned);
                for (const frame of group.frames) {
                    if (frame.hibernated || frame.isDirty || frame.staged || frame.queued || frame.frameId === activeFrameId) {
                        continue;
                    }
                    candidates.push({ frame, isInactiveGroup, isPinnedGroup });
                }
            }

            if (candidates.length === 0) break;

            candidates.sort((a, b) => {
                if (a.isPinnedGroup !== b.isPinnedGroup) {
                    return a.isPinnedGroup ? 1 : -1;
                }
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

    seedInitial({ moduleKey, label, url, icon = '', activate = true, pinned = false }) {
        return this.openWorkspace({
            moduleKey,
            label,
            url,
            icon,
            activate,
            pinned,
        });
    }

    seedHibernatedPinned({ moduleKey = null, label, url, icon = '' }) {
        if (this.pinnedGroupsCount() >= this.maxPinnedSessions || this.totalFrames() >= this.maxSessions) {
            return { group: null, frame: null, blocked: true };
        }

        const cleanUrl = canonicalizeUrl(url);
        const resolvedKey = moduleKey || resolveModuleKey(cleanUrl, this.catalog);
        const timestamp = this.now();
        const groupId = this._nextGroupId();
        const frame = this._createFrame({
            groupId,
            role: 'parent',
            title: label || resolvedKey,
            url: cleanUrl,
            timestamp,
        });
        frame.hibernated = true;
        frame.loading = false;

        const group = {
            groupId,
            moduleKey: resolvedKey,
            baseLabel: label || resolvedKey,
            icon: icon || '',
            pinned: true,
            activeFrameId: frame.frameId,
            lastAccessedAt: timestamp,
            frames: [frame],
        };
        this._groups.splice(this._partitionBoundaryIndex(), 0, group);

        return { group, frame, blocked: false };
    }

    _zoneBounds(groupId) {
        const index = this._groups.findIndex((group) => group.groupId === groupId);
        if (index === -1) return null;
        const group = this._groups[index];
        const boundary = this._partitionBoundaryIndex();
        const pinned = Boolean(group.pinned);
        const zoneStart = pinned ? 0 : boundary;
        const zoneEnd = pinned ? boundary - 1 : this._groups.length - 1;
        return {
            index,
            pinned,
            zoneStart,
            zoneEnd,
            zoneIndex: index - zoneStart,
            zoneTotal: zoneEnd - zoneStart + 1,
        };
    }

    _isGroupVisible(group) {
        return Boolean(group) && group.frames.some((frame) => !frame.stagedNew);
    }

    _hasVisibleGroupBetween(fromIndex, toIndex) {
        for (let i = fromIndex; i <= toIndex; i += 1) {
            if (this._isGroupVisible(this._groups[i])) return true;
        }
        return false;
    }

    canMoveGroup(groupId) {
        const bounds = this._zoneBounds(groupId);
        if (!bounds) {
            return { canMoveLeft: false, canMoveRight: false, index: -1, zoneStart: 0, zoneEnd: 0, zoneIndex: 0, zoneTotal: 0, pinned: false };
        }
        return {
            ...bounds,
            canMoveLeft: this._hasVisibleGroupBetween(bounds.zoneStart, bounds.index - 1),
            canMoveRight: this._hasVisibleGroupBetween(bounds.index + 1, bounds.zoneEnd),
        };
    }

    moveGroup(groupId, targetIndex) {
        const bounds = this._zoneBounds(groupId);
        if (!bounds) {
            return { moved: false, fromIndex: -1, toIndex: -1, zoneIndex: 0, zoneTotal: 0, pinned: false };
        }
        const requested = Math.trunc(Number(targetIndex));
        const clamped = Math.min(bounds.zoneEnd, Math.max(bounds.zoneStart, Number.isFinite(requested) ? requested : bounds.index));
        if (clamped !== bounds.index) {
            const [group] = this._groups.splice(bounds.index, 1);
            this._groups.splice(clamped, 0, group);
        }
        return {
            moved: clamped !== bounds.index,
            fromIndex: bounds.index,
            toIndex: clamped,
            zoneIndex: clamped - bounds.zoneStart,
            zoneTotal: bounds.zoneTotal,
            pinned: bounds.pinned,
        };
    }

    moveGroupByOffset(groupId, delta) {
        const bounds = this._zoneBounds(groupId);
        if (!bounds) return this.moveGroup(groupId, -1);

        const direction = Number(delta) < 0 ? -1 : 1;
        let remaining = Math.abs(Math.trunc(Number(delta) || 0));
        let target = bounds.index;
        while (remaining > 0) {
            let next = target + direction;
            while (next >= bounds.zoneStart && next <= bounds.zoneEnd && !this._isGroupVisible(this._groups[next])) {
                next += direction;
            }
            if (next < bounds.zoneStart || next > bounds.zoneEnd) break;
            target = next;
            remaining -= 1;
        }
        return this.moveGroup(groupId, target);
    }

    pinGroup(groupId) {
        const index = this._groups.findIndex((group) => group.groupId === groupId);
        if (index === -1) return { changed: false, pinned: false, blocked: false, reason: 'not_found' };
        const group = this._groups[index];
        if (group.pinned) return { changed: false, pinned: true, blocked: false, reason: 'already_pinned' };
        if (this.pinnedGroupsCount() >= this.maxPinnedSessions) {
            return { changed: false, pinned: false, blocked: true, reason: 'max_pinned_reached' };
        }

        this._groups.splice(index, 1);
        group.pinned = true;
        this._groups.splice(this._partitionBoundaryIndex(), 0, group);

        return {
            changed: true,
            pinned: true,
            blocked: false,
            groupId,
            index: this._groups.indexOf(group),
            zoneIndex: this.pinnedGroupsCount() - 1,
            zoneTotal: this.pinnedGroupsCount(),
        };
    }

    unpinGroup(groupId) {
        const index = this._groups.findIndex((group) => group.groupId === groupId);
        if (index === -1 || !this._groups[index].pinned) {
            return { changed: false, pinned: false, blocked: false, reason: 'not_pinned' };
        }
        const group = this._groups[index];

        this._groups.splice(index, 1);
        group.pinned = false;
        this._groups.splice(this._partitionBoundaryIndex(), 0, group);

        return {
            changed: true,
            pinned: false,
            blocked: false,
            groupId,
            index: this._groups.indexOf(group),
            zoneIndex: 0,
            zoneTotal: this.unpinnedGroupsCount(),
        };
    }

    togglePinGroup(groupId) {
        const group = this.getGroup(groupId);
        return group?.pinned ? this.unpinGroup(groupId) : this.pinGroup(groupId);
    }

    openWorkspace({
        moduleKey,
        label,
        url,
        icon = '',
        insertAfterGroupId = null,
        activate = true,
        stageOnly = false,
        showSessionWhileStaged = null,
        showTabWhileStaged = false,
        pinned = false,
    }) {
        const cleanUrl = canonicalizeUrl(url);
        const resolvedKey = moduleKey || resolveModuleKey(cleanUrl, this.catalog);
        const timestamp = this.now();
        const showWhileStaged = Boolean(showSessionWhileStaged ?? showTabWhileStaged);

        const closedGroupIds = [];
        const closedFrameIds = [];
        while (this.totalFrames() >= this.maxSessions) {
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
            stagedNew: Boolean(stageOnly && !showWhileStaged),
            queued: Boolean(stageOnly && showWhileStaged),
        });

        const isPinned = Boolean(pinned) && !stageOnly && this.pinnedGroupsCount() < this.maxPinnedSessions;
        const group = {
            groupId,
            moduleKey: resolvedKey,
            baseLabel: label || resolvedKey,
            icon: icon || '',
            pinned: isPinned,
            activeFrameId: frame.frameId,
            lastAccessedAt: timestamp,
            frames: [frame],
        };

        const boundary = this._partitionBoundaryIndex();
        const anchorIdx = insertAfterGroupId
            ? this._groups.findIndex((item) => item.groupId === insertAfterGroupId)
            : -1;
        let insertAt = this._groups.length;
        if (anchorIdx !== -1) {
            insertAt = anchorIdx + 1;
        }
        // Keep the pinned zone contiguous: pinned groups never land after unpinned ones, and unpinned groups never land inside the pinned zone.
        insertAt = isPinned ? Math.min(insertAt, boundary) : Math.max(insertAt, boundary);
        this._groups.splice(insertAt, 0, group);

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

    openChildFrame({
        groupId = null,
        sourceFrameId = null,
        label,
        url,
        moduleKey = null,
        stageOnly = false,
        showSessionWhileStaged = null,
        showTabWhileStaged = false,
    }) {
        const showWhileStaged = showSessionWhileStaged ?? showTabWhileStaged;
        const targetGroup = groupId
            ? this.getGroup(groupId)
            : (sourceFrameId ? this.getFrameContext(sourceFrameId)?.group : this.activeGroup());

        if (!targetGroup) {
            return this.openWorkspace({
                moduleKey,
                label,
                url,
                stageOnly,
                showSessionWhileStaged: Boolean(showWhileStaged),
            });
        }

        const cleanUrl = canonicalizeUrl(url);
        const signature = normalizedPathnameAndSearch(cleanUrl);
        const existing = targetGroup.frames.find(
            (frame) => (!frame.stagedNew || frame.loading || frame.queued)
                && normalizedPathnameAndSearch(frame.url) === signature,
        );

        if (existing) {
            const activation = this.activateFrame(existing.frameId, { stageIfHibernated: stageOnly, stageIfLoading: stageOnly });
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
        while (this.totalFrames() >= this.maxSessions) {
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
            stagedNew: Boolean(stageOnly && !showWhileStaged),
            queued: Boolean(stageOnly && showWhileStaged),
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

    activateFrame(frameId, { stageIfHibernated = false, stageIfLoading = false } = {}) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) {
            return { group: null, frame: null, staged: false, wasHibernated: false, shouldRefresh: false, hibernatedFrameIds: [] };
        }

        const { group, frame } = ctx;
        const timestamp = this.now();
        const wasHibernated = Boolean(frame.hibernated);

        if ((frame.staged || frame.queued) && (frame.loading || frame.queued) && stageIfLoading) {
            frame.staged = true;
            return {
                group,
                frame,
                staged: true,
                wasHibernated: Boolean(frame._wasHibernatedBeforeStage),
                shouldRefresh: false,
                hibernatedFrameIds: [],
            };
        }

        if (wasHibernated && stageIfHibernated) {
            frame.hibernated = false;
            frame.stale = false;
            frame.loading = true;
            frame.staged = true;
            frame.stagedNew = false;
            frame.queued = false;
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

    commitStagedFrame(frameId, { activate = true } = {}) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) {
            return { ok: false, group: null, frame: null, activated: false, hibernatedFrameIds: [] };
        }

        const { group, frame } = ctx;
        const timestamp = this.now();
        frame.staged = false;
        frame.stagedNew = false;
        frame.queued = false;
        frame.loading = false;
        delete frame._wasHibernatedBeforeStage;
        frame.hibernated = false;
        frame.stale = false;
        frame.lastAccessedAt = timestamp;
        frame.lastLoadedAt = timestamp;

        if (activate) {
            group.activeFrameId = frame.frameId;
            group.lastAccessedAt = timestamp;
            this._activeTarget = {
                type: 'frame',
                groupId: group.groupId,
                frameId: frame.frameId,
            };
        } else {
            const hasReadyGroupActive = group.frames.some(
                (entry) => entry.frameId === group.activeFrameId && !entry.staged && !entry.stagedNew,
            );
            if (!group.activeFrameId || !hasReadyGroupActive) {
                group.activeFrameId = frame.frameId;
            }
        }

        const hibernatedFrameIds = this.rebalanceLiveFrames();

        return { ok: true, group, frame, activated: Boolean(activate), hibernatedFrameIds };
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
            frame.queued = false;
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

    closeFrame(frameId, { force = false } = {}) {
        const ctx = this.getFrameContext(frameId);
        if (!ctx) return { closed: false, closedFrameIds: [], closedSnapshots: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };

        const { group, frame, index } = ctx;
        if (frame.role === 'parent') {
            return this.closeGroup(group.groupId, { force });
        }

        const wasActive = this._activeTarget.type === 'frame' && this._activeTarget.frameId === frameId;
        const closedSnapshots = [this._snapshotClosedFrame(frame, group)];
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
                closedSnapshots,
                nextTarget: this.activeTarget(),
                wasHibernated: activation.wasHibernated,
                shouldRefresh: activation.shouldRefresh,
                hibernatedFrameIds: activation.hibernatedFrameIds,
            };
        }

        return {
            closed: true,
            closedFrameIds: [frameId],
            closedSnapshots,
            nextTarget: this.activeTarget(),
            wasHibernated: false,
            shouldRefresh: false,
            hibernatedFrameIds: [],
        };
    }

    closeGroup(groupId, { force = false } = {}) {
        const idx = this._groups.findIndex((group) => group.groupId === groupId);
        if (idx === -1) {
            return { closed: false, closedFrameIds: [], closedSnapshots: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        if (this._groups[idx].pinned && !force) {
            return {
                closed: false,
                blockedByPin: true,
                closedFrameIds: [],
                closedSnapshots: [],
                nextTarget: this.activeTarget(),
                hibernatedFrameIds: [],
            };
        }

        const [removed] = this._groups.splice(idx, 1);
        const closedFrameIds = removed.frames.map((frame) => frame.frameId);
        const closedSnapshots = [this._snapshotClosedGroup(removed)];
        const wasActive = this._activeTarget.type === 'frame' && this._activeTarget.groupId === groupId;

        if (!wasActive) {
            return {
                closed: true,
                closedFrameIds,
                closedSnapshots,
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
            closedSnapshots,
            nextTarget,
            wasHibernated: Boolean(nextTarget.wasHibernated),
            shouldRefresh: Boolean(nextTarget.shouldRefresh),
            hibernatedFrameIds: nextTarget.hibernatedFrameIds || [],
        };
    }

    dirtyFramesForClose(scope, anchorId = null) {
        let candidateGroups = [];
        if (scope === 'all') {
            candidateGroups = this._groups.filter((g) => !g.pinned);
        } else if (scope === 'others') {
            candidateGroups = this._groups.filter((g) => !g.pinned && g.groupId !== anchorId);
        } else if (scope === 'right') {
            const idx = this._groups.findIndex((g) => g.groupId === anchorId);
            candidateGroups = idx === -1 ? [] : this._groups.slice(idx + 1).filter((g) => !g.pinned);
        } else if (scope === 'group') {
            const g = this.getGroup(anchorId);
            candidateGroups = g && !g.pinned ? [g] : [];
        } else if (scope === 'frame') {
            const frame = this.getFrame(anchorId);
            return frame && frame.isDirty ? [frame] : [];
        }

        return candidateGroups.flatMap((group) => group.frames.filter((frame) => frame.isDirty));
    }

    closeOtherGroups(keepGroupId) {
        const keep = this.getGroup(keepGroupId);
        if (!keep) {
            return { closedFrameIds: [], closedSnapshots: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        const removed = this._groups.filter((group) => !group.pinned && group.groupId !== keepGroupId);
        const closedFrameIds = removed.flatMap((group) => group.frames.map((frame) => frame.frameId));
        const closedSnapshots = removed.map((group) => this._snapshotClosedGroup(group));

        this._groups = this._groups.filter((group) => group.pinned || group.groupId === keepGroupId);
        const activation = this.activateGroup(keepGroupId);

        return {
            closedFrameIds,
            closedSnapshots,
            nextTarget: this.activeTarget(),
            wasHibernated: activation.wasHibernated,
            shouldRefresh: activation.shouldRefresh,
            hibernatedFrameIds: activation.hibernatedFrameIds,
        };
    }

    closeGroupsToRight(anchorGroupId) {
        const idx = this._groups.findIndex((group) => group.groupId === anchorGroupId);
        if (idx === -1) {
            return { closedFrameIds: [], closedSnapshots: [], nextTarget: this.activeTarget(), hibernatedFrameIds: [] };
        }

        const removed = this._groups.slice(idx + 1).filter((group) => !group.pinned);
        const removedIds = new Set(removed.map((group) => group.groupId));
        this._groups = this._groups.filter((group) => !removedIds.has(group.groupId));
        const closedFrameIds = removed.flatMap((group) => group.frames.map((frame) => frame.frameId));
        const closedSnapshots = removed.map((group) => this._snapshotClosedGroup(group));

        if (
            this._activeTarget.type === 'frame'
            && !this.getGroup(this._activeTarget.groupId)
        ) {
            const activation = this.activateGroup(anchorGroupId);
            return {
                closedFrameIds,
                closedSnapshots,
                nextTarget: this.activeTarget(),
                wasHibernated: activation.wasHibernated,
                shouldRefresh: activation.shouldRefresh,
                hibernatedFrameIds: activation.hibernatedFrameIds,
            };
        }

        return {
            closedFrameIds,
            closedSnapshots,
            nextTarget: this.activeTarget(),
            wasHibernated: false,
            shouldRefresh: false,
            hibernatedFrameIds: [],
        };
    }

    closeAllGroups() {
        const removed = this._groups.filter((group) => !group.pinned);
        const closedFrameIds = removed.flatMap((group) => group.frames.map((frame) => frame.frameId));
        const closedSnapshots = removed.map((group) => this._snapshotClosedGroup(group));
        this._groups = this._groups.filter((group) => group.pinned);

        const activeGroupLost = this._activeTarget.type === 'frame' && !this.getGroup(this._activeTarget.groupId);
        if (this._groups.length === 0) {
            this._activeTarget = { type: SHORTCUTS_KEY };
        } else if (activeGroupLost) {
            const nextTarget = this._resolveNextTargetAfterGroupRemoval(this._groups.length - 1);
            return {
                closedFrameIds,
                closedSnapshots,
                nextTarget,
                wasHibernated: Boolean(nextTarget.wasHibernated),
                shouldRefresh: Boolean(nextTarget.shouldRefresh),
                hibernatedFrameIds: nextTarget.hibernatedFrameIds || [],
            };
        }

        return {
            closedFrameIds,
            closedSnapshots,
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
        frame.queued = false;
        frame.stale = false;
        frame.hibernated = false;
        frame.lastLoadedAt = this.now();
        frame.lastAccessedAt = Math.max(frame.lastAccessedAt || 0, frame.lastLoadedAt);
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
        const zoneTotals = { pinned: 0, unpinned: 0 };
        for (const group of visibleGroups) {
            zoneTotals[group.pinned ? 'pinned' : 'unpinned'] += 1;
        }
        const zoneSeen = { pinned: 0, unpinned: 0 };
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
            const zoneKey = group.pinned ? 'pinned' : 'unpinned';
            const zoneIndex = zoneSeen[zoneKey];
            zoneSeen[zoneKey] += 1;

            return {
                groupId: group.groupId,
                moduleKey: group.moduleKey,
                baseLabel: group.baseLabel,
                displayLabel: rawTitle,
                icon: group.icon,
                pinned: Boolean(group.pinned),
                canMoveLeft: zoneIndex > 0,
                canMoveRight: zoneIndex < zoneTotals[zoneKey] - 1,
                zoneIndex,
                zoneTotal: zoneTotals[zoneKey],
                duplicateIndex: nextSeen,
                duplicateTotal,
                isActive,
                isExpanded,
                isCollapsedStack,
                activeFrameId: group.activeFrameId,
                currentActiveFrameId: activeFrameId,
                dirty: hasDirty,
                stale: hasStale,
                queued: visibleFrames.some((frame) => Boolean(frame.queued)),
                hibernated: visibleFrames.every((frame) => frame.hibernated),
                frames: visibleFrames.map((frame) => ({ ...frame })),
            };
        });
    }
}

export const WorkspaceTabStore = WorkspaceSessionStore;

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

function defaultQueueTimers() {
    const hasWin = typeof window !== 'undefined' && typeof window.setTimeout === 'function';
    return {
        setTimeout: (fn, ms) => (hasWin ? window.setTimeout(fn, ms) : globalThis.setTimeout(fn, ms)),
        clearTimeout: (id) => (hasWin ? window.clearTimeout(id) : globalThis.clearTimeout(id)),
        now: () => (typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now()),
    };
}

/**
 * Bounded-concurrency FIFO navigation queue for workspace sessions and staged embeds.
 *
 * Preserves click order while capping concurrent active loads (HTML + initial data)
 * to protect backend session/DB capacity, committing and stepping through ready
 * workspaces sequentially in strict FIFO click order (~220ms step dwell).
 */
export class WorkspaceNavQueue {
    constructor({
        maxConcurrent = 2,
        slowThresholdMs = 2500,
        stepIntervalMs = DEFAULT_STEP_INTERVAL_MS,
        slotTimeoutMs = 30000,
        dataReadyTimeoutMs = 12000,
        queueWaitTimeoutMs = 60000,
        visibilityGraceMs = 5000,
        isDocumentHidden = null,
        timers = defaultQueueTimers(),
        onStartSlot = null,
        onCommitReady = null,
        onSlowThreshold = null,
        onHideLoader = null,
        onSlotTimeout = null,
        onDataTimeout = null,
        onQueueChange = null,
    } = {}) {
        this.maxConcurrent = Math.max(1, Number(maxConcurrent) || 2);
        this.slowThresholdMs = Math.max(0, Number(slowThresholdMs) >= 0 ? Number(slowThresholdMs) : 2500);
        this.stepIntervalMs = Math.max(0, Number(stepIntervalMs) >= 0 ? Number(stepIntervalMs) : DEFAULT_STEP_INTERVAL_MS);
        this._stepIntervalMs = this.stepIntervalMs;
        this.slotTimeoutMs = Math.max(0, Number(slotTimeoutMs) || 0);
        this.dataReadyTimeoutMs = Math.max(0, Number(dataReadyTimeoutMs) || 0);
        this.queueWaitTimeoutMs = Math.max(0, Number(queueWaitTimeoutMs) || 0);
        this.visibilityGraceMs = Math.max(0, Number(visibilityGraceMs) || 0);
        this.isDocumentHidden = typeof isDocumentHidden === 'function' ? isDocumentHidden : () => false;
        this.timers = timers;
        this.onStartSlot = onStartSlot;
        this.onCommitReady = onCommitReady;
        this.onSlowThreshold = onSlowThreshold;
        this.onHideLoader = onHideLoader;
        this.onSlotTimeout = onSlotTimeout;
        this.onDataTimeout = onDataTimeout;
        this.onQueueChange = onQueueChange;

        this._entries = [];
        this._commitOrder = [];
        this._byFrameId = new Map();
        this._autoFocusFrameId = null;
        this._slowTimerId = 0;
        this._stepTimerId = 0;
        this._lastCommitAt = null;
        this._loaderShown = false;
        this._prioritySeq = 0;
    }

    _now() {
        if (typeof this.timers?.now === 'function') {
            return Number(this.timers.now()) || 0;
        }
        return typeof performance !== 'undefined' && typeof performance.now === 'function'
            ? performance.now()
            : Date.now();
    }

    size() {
        return this._entries.length;
    }

    pendingCommitsCount() {
        return this._commitOrder.length;
    }

    commitOrder() {
        return this._commitOrder.map((entry) => entry.frameId);
    }

    has(frameId) {
        return this._byFrameId.has(frameId);
    }

    get(frameId) {
        return this._byFrameId.get(frameId) || null;
    }

    autoFocusFrameId() {
        return this._autoFocusFrameId;
    }

    isLoaderShown() {
        return this._loaderShown;
    }

    activeCount() {
        let count = 0;
        for (const entry of this._entries) {
            if (entry.status === 'loading' || entry.status === 'waiting-data') {
                count += 1;
            }
        }
        return count;
    }

    queuedCount() {
        let count = 0;
        for (const entry of this._entries) {
            if (entry.status === 'queued') {
                count += 1;
            }
        }
        return count;
    }

    entries() {
        return this._entries.slice();
    }

    isTriggerPending(triggerEl) {
        if (!triggerEl) return false;
        return this._entries.some(
            (entry) => entry.triggerEl === triggerEl
                && (entry.status === 'queued' || entry.status === 'loading' || entry.status === 'waiting-data'),
        );
    }

    _notifyChange() {
        try {
            this.onQueueChange?.({
                total: this._entries.length,
                active: this.activeCount(),
                queued: this.queuedCount(),
                pendingCommits: this._commitOrder.length,
                autoFocusFrameId: this._autoFocusFrameId,
                loaderShown: this._loaderShown,
            });
        } catch {}
    }

    _clearEntryTimers(entry) {
        if (!entry) return;
        if (entry.slotTimerId) {
            this.timers.clearTimeout(entry.slotTimerId);
            entry.slotTimerId = 0;
        }
        if (entry.dataTimerId) {
            this.timers.clearTimeout(entry.dataTimerId);
            entry.dataTimerId = 0;
        }
        if (entry.queueWaitTimerId) {
            this.timers.clearTimeout(entry.queueWaitTimerId);
            entry.queueWaitTimerId = 0;
        }
    }

    _removeEntry(entry) {
        this._byFrameId.delete(entry.frameId);
        const idx = this._entries.indexOf(entry);
        if (idx !== -1) {
            this._entries.splice(idx, 1);
        }
    }

    _removeFromCommitOrder(frameId) {
        const idx = this._commitOrder.findIndex((entry) => entry.frameId === frameId);
        if (idx !== -1) {
            return this._commitOrder.splice(idx, 1)[0];
        }
        return null;
    }

    _hasPendingUnreadyAutoFocus() {
        return this._commitOrder.some((entry) => entry.autoFocus && !entry.readyToCommit);
    }

    _syncAutoFocusTarget() {
        let lastAutoFocus = null;
        for (const entry of this._commitOrder) {
            if (entry.autoFocus) {
                lastAutoFocus = entry.frameId;
            }
        }
        this._autoFocusFrameId = lastAutoFocus;
        this._ensureSlowTimer();
    }

    _clearWaitPresentation() {
        if (this._slowTimerId) {
            this.timers.clearTimeout(this._slowTimerId);
            this._slowTimerId = 0;
        }
        if (this._loaderShown) {
            this._loaderShown = false;
            try {
                this.onHideLoader?.();
            } catch {}
        }
    }

    _ensureSlowTimer() {
        if (!this._hasPendingUnreadyAutoFocus()) {
            this._clearWaitPresentation();
            return;
        }
        if (this._loaderShown || this._slowTimerId) return;

        this._slowTimerId = this.timers.setTimeout(() => {
            this._slowTimerId = 0;
            if (!this._hasPendingUnreadyAutoFocus()) return;
            this._loaderShown = true;
            try {
                this.onSlowThreshold?.(this._autoFocusFrameId);
            } catch {}
            this._notifyChange();
        }, this.slowThresholdMs);
    }

    _drainReadyCommits(triggerFrameId = null) {
        let triggerResult = null;
        if (this._stepTimerId) {
            this._syncAutoFocusTarget();
            return { triggerResult };
        }

        while (this._commitOrder.length > 0 && this._commitOrder[0].readyToCommit) {
            const head = this._commitOrder[0];
            const elapsed = this._lastCommitAt !== null ? (this._now() - this._lastCommitAt) : Infinity;

            if (this._lastCommitAt !== null && this._stepIntervalMs > 0 && elapsed < this._stepIntervalMs) {
                const delay = Math.max(1, Math.ceil(this._stepIntervalMs - elapsed));
                this._stepTimerId = this.timers.setTimeout(() => {
                    this._stepTimerId = 0;
                    this._drainReadyCommits();
                    this._notifyChange();
                }, delay);
                break;
            }

            this._commitOrder.shift();
            const shouldActivate = Boolean(head.autoFocus);
            if (shouldActivate) {
                // Hide stage loader when a workspace actually finishes rendering onto the stage.
                this._clearWaitPresentation();
            }
            this._lastCommitAt = this._now();

            if (head.frameId === triggerFrameId) {
                triggerResult = { committedNow: true, activate: shouldActivate };
            }

            try {
                this.onCommitReady?.(head, { activate: shouldActivate });
            } catch {}
        }

        this._syncAutoFocusTarget();
        return { triggerResult };
    }

    _nextQueuedEntry() {
        let best = null;
        for (const entry of this._entries) {
            if (entry.status !== 'queued') continue;
            if (!best || entry.priorityOrder > best.priorityOrder) {
                best = entry;
            }
        }
        return best;
    }

    _armSlotTimer(entry, timeoutMs = this.slotTimeoutMs) {
        if (!entry) return;
        if (entry.slotTimerId) {
            this.timers.clearTimeout(entry.slotTimerId);
            entry.slotTimerId = 0;
        }
        entry.deferredTimeout = false;
        if (timeoutMs <= 0) return;

        const fid = entry.frameId;
        entry.slotTimerId = this.timers.setTimeout(() => {
            const current = this._byFrameId.get(fid);
            if (!current || current.status !== 'loading') return;
            current.slotTimerId = 0;
            // Background tabs throttle iframe subresources; defer timeout until foregrounded.
            if (this.isDocumentHidden()) {
                current.deferredTimeout = true;
                return;
            }
            this.fail(fid, 'timeout');
        }, timeoutMs);
    }

    resetSlotTimer(frameId) {
        const entry = this._byFrameId.get(frameId);
        if (!entry || entry.status !== 'loading') return false;
        entry.startedAt = this._now();
        this._armSlotTimer(entry, this.slotTimeoutMs);
        return true;
    }

    notifyVisibilityRestored() {
        for (const entry of this._entries) {
            if (entry.status === 'loading' && entry.deferredTimeout && !entry.slotTimerId) {
                const graceMs = this.visibilityGraceMs > 0 ? this.visibilityGraceMs : Math.min(this.slotTimeoutMs, 5000);
                this._armSlotTimer(entry, graceMs);
            }
        }
    }

    _pump() {
        while (this.activeCount() < this.maxConcurrent) {
            const next = this._nextQueuedEntry();
            if (!next) break;

            if (next.queueWaitTimerId) {
                this.timers.clearTimeout(next.queueWaitTimerId);
                next.queueWaitTimerId = 0;
            }
            next.status = 'loading';
            next.startedAt = this._now();
            this._armSlotTimer(next, this.slotTimeoutMs);

            try {
                this.onStartSlot?.(next);
            } catch {}
        }
    }

    enqueue({
        frameId,
        groupId,
        kind = 'workspace',
        canonicalKey = '',
        url = '',
        label = '',
        historyMode = 'push',
        triggerEl = null,
        wasHibernated = false,
        autoFocus = true,
    }) {
        if (!frameId) return null;
        const existing = this._byFrameId.get(frameId);
        if (existing) {
            if (autoFocus) {
                existing.autoFocus = true;
                this._syncAutoFocusTarget();
            }
            this._pump();
            this._notifyChange();
            return existing;
        }

        const entry = {
            frameId,
            groupId,
            kind,
            canonicalKey,
            url,
            label,
            historyMode,
            triggerEl,
            wasHibernated: Boolean(wasHibernated),
            autoFocus: Boolean(autoFocus),
            readyToCommit: false,
            status: 'queued',
            priorityOrder: 0,
            slotTimerId: 0,
            dataTimerId: 0,
            queueWaitTimerId: 0,
            startedAt: null,
            deferredTimeout: false,
        };

        this._entries.push(entry);
        this._commitOrder.push(entry);
        this._byFrameId.set(frameId, entry);

        if (this.queueWaitTimeoutMs > 0) {
            entry.queueWaitTimerId = this.timers.setTimeout(() => {
                const current = this._byFrameId.get(frameId);
                if (!current || current.status !== 'queued') return;
                current.queueWaitTimerId = 0;
                this.fail(frameId, 'queue-timeout');
            }, this.queueWaitTimeoutMs);
        }

        this._syncAutoFocusTarget();
        this._pump();
        this._notifyChange();
        return entry;
    }

    prioritize(frameId) {
        const entry = this._byFrameId.get(frameId)
            || this._commitOrder.find((item) => item.frameId === frameId);
        if (!entry) return false;

        entry.autoFocus = true;
        this._removeFromCommitOrder(frameId);
        this._commitOrder.unshift(entry);
        this._syncAutoFocusTarget();

        if (entry.status === 'queued') {
            entry.priorityOrder = ++this._prioritySeq;
            this._pump();
        } else if (entry.readyToCommit) {
            this._drainReadyCommits();
        }
        this._notifyChange();
        return true;
    }

    markDocumentLoaded(frameId, { awaitingData = false } = {}) {
        const entry = this._byFrameId.get(frameId);
        if (!entry || entry.status === 'ready' || entry.status === 'cancelled' || entry.status === 'failed') {
            return false;
        }

        if (entry.slotTimerId) {
            this.timers.clearTimeout(entry.slotTimerId);
            entry.slotTimerId = 0;
        }

        if (!awaitingData) {
            this.complete(frameId);
            return true;
        }

        entry.status = 'waiting-data';
        if (!entry.dataTimerId && this.dataReadyTimeoutMs > 0) {
            entry.dataTimerId = this.timers.setTimeout(() => {
                const current = this._byFrameId.get(frameId);
                if (!current || current.status !== 'waiting-data') return;
                current.dataTimerId = 0;
                if (typeof this.onDataTimeout === 'function') {
                    this.onDataTimeout(current);
                } else {
                    this.complete(frameId);
                }
            }, this.dataReadyTimeoutMs);
        }

        this._notifyChange();
        return true;
    }

    complete(frameId) {
        const entry = this._byFrameId.get(frameId);
        if (!entry || entry.status === 'ready' || entry.status === 'cancelled' || entry.status === 'failed') {
            return { handled: false, activate: false, deferred: false, entry: null };
        }

        this._clearEntryTimers(entry);
        entry.status = 'ready';
        entry.readyToCommit = true;
        this._removeEntry(entry);

        this._pump();
        const { triggerResult } = this._drainReadyCommits(frameId);
        const committedNow = Boolean(triggerResult?.committedNow);
        const activate = committedNow ? Boolean(triggerResult?.activate) : false;

        this._notifyChange();
        return {
            handled: true,
            activate,
            deferred: !committedNow,
            entry,
        };
    }

    clearAutoFocus() {
        let changed = Boolean(this._autoFocusFrameId || this._loaderShown || this._slowTimerId);
        for (const entry of this._commitOrder) {
            if (entry.autoFocus) {
                entry.autoFocus = false;
                changed = true;
            }
        }
        if (!changed) {
            return;
        }
        this._autoFocusFrameId = null;
        this._clearWaitPresentation();
        this._notifyChange();
    }

    cancel(frameId, reason = 'cancelled') {
        const entry = this._byFrameId.get(frameId) || this._removeFromCommitOrder(frameId);
        if (!entry) return null;

        this._clearEntryTimers(entry);
        entry.status = 'cancelled';
        entry.cancelReason = reason;
        this._removeEntry(entry);
        this._removeFromCommitOrder(frameId);

        this._pump();
        this._drainReadyCommits();
        this._notifyChange();
        return entry;
    }

    fail(frameId, reason = 'timeout') {
        const entry = this._byFrameId.get(frameId) || this._removeFromCommitOrder(frameId);
        if (!entry) return null;

        this._clearEntryTimers(entry);
        entry.status = 'failed';
        entry.failReason = reason;
        this._removeEntry(entry);
        this._removeFromCommitOrder(frameId);

        this._pump();
        try {
            this.onSlotTimeout?.(entry, reason);
        } catch {}
        this._drainReadyCommits();
        this._notifyChange();
        return entry;
    }
}

export const SHORTCUTS_PANE_KEYS = Object.freeze(['favorites', 'bookmarks', 'frequent']);

export const DEFAULT_SHORTCUTS_WEIGHTS = Object.freeze({
    favorites: 32,
    bookmarks: 32,
    frequent: 36,
});

export const MIN_SHORTCUTS_PANE_WIDTH_PX = 1;
export const SHORTCUTS_SPLITTER_WIDTH_PX = 16;

export function normalizeShortcutsLayout(rawLayout) {
    const rawMin = rawLayout && typeof rawLayout.minimized === 'object' && rawLayout.minimized
        ? rawLayout.minimized
        : {};
    const minimized = {
        favorites: Boolean(rawMin.favorites),
        bookmarks: Boolean(rawMin.bookmarks),
        frequent: Boolean(rawMin.frequent),
    };

    const rawOrder = Array.isArray(rawLayout?.minimizedOrder) ? rawLayout.minimizedOrder : [];
    const minimizedOrder = [];
    for (const k of rawOrder) {
        if (SHORTCUTS_PANE_KEYS.includes(k) && minimized[k] && !minimizedOrder.includes(k)) {
            minimizedOrder.push(k);
        }
    }
    for (const k of SHORTCUTS_PANE_KEYS) {
        if (minimized[k] && !minimizedOrder.includes(k)) {
            minimizedOrder.push(k);
        }
    }

    const rawWeights = rawLayout && typeof rawLayout.weights === 'object' && rawLayout.weights
        ? rawLayout.weights
        : null;
    const validWeights = rawWeights
        && SHORTCUTS_PANE_KEYS.every((k) => Number.isFinite(Number(rawWeights[k])) && Number(rawWeights[k]) > 0);

    const source = validWeights
        ? {
            favorites: Number(rawWeights.favorites),
            bookmarks: Number(rawWeights.bookmarks),
            frequent: Number(rawWeights.frequent),
        }
        : { ...DEFAULT_SHORTCUTS_WEIGHTS };

    const total = SHORTCUTS_PANE_KEYS.reduce((acc, k) => acc + source[k], 0) || 100;
    const weights = {
        favorites: Math.max(0.01, (source.favorites / total) * 100),
        bookmarks: Math.max(0.01, (source.bookmarks / total) * 100),
        frequent: Math.max(0.01, (source.frequent / total) * 100),
    };

    return { weights, minimized, minimizedOrder };
}

export function resolveVisibleShortcutsSplitters(minimized = {}) {
    const min = minimized && typeof minimized === 'object' ? minimized : {};
    const openCount = SHORTCUTS_PANE_KEYS.filter((k) => !min[k]).length;
    if (openCount <= 1) return [];
    if (openCount === 3) return ['favorites-bookmarks', 'bookmarks-frequent'];
    if (min.favorites) return ['bookmarks-frequent'];
    return ['favorites-bookmarks'];
}

export function resolveShortcutsSplitterPair(splitterKey, minimized = {}) {
    const visible = resolveVisibleShortcutsSplitters(minimized);
    if (!visible.includes(splitterKey)) return null;
    const min = minimized && typeof minimized === 'object' ? minimized : {};
    if (splitterKey === 'favorites-bookmarks') {
        if (min.favorites) return null;
        if (!min.bookmarks) return ['favorites', 'bookmarks'];
        if (!min.frequent) return ['favorites', 'frequent'];
        return null;
    }
    if (splitterKey === 'bookmarks-frequent') {
        if (min.frequent) return null;
        if (!min.bookmarks) return ['bookmarks', 'frequent'];
        if (!min.favorites) return ['favorites', 'frequent'];
        return null;
    }
    return null;
}

export function computeOpenPaneWidthPx(weights, minimized = {}, boardWidthPx = 1000) {
    const min = minimized && typeof minimized === 'object' ? minimized : {};
    const openKeys = SHORTCUTS_PANE_KEYS.filter((k) => !min[k]);
    const visibleSplitters = resolveVisibleShortcutsSplitters(min);
    const openTotalPx = Math.max(
        0,
        Number(boardWidthPx || 0) - (visibleSplitters.length * SHORTCUTS_SPLITTER_WIDTH_PX),
    );
    const openWeightSum = openKeys.reduce((acc, k) => acc + Math.max(0.001, Number(weights?.[k] || 0)), 0);
    const widthsPx = {};

    for (const k of SHORTCUTS_PANE_KEYS) {
        if (min[k]) {
            widthsPx[k] = 0;
        } else {
            widthsPx[k] = openWeightSum > 0
                ? openTotalPx * (Math.max(0.001, Number(weights?.[k] || 0)) / openWeightSum)
                : 0;
        }
    }

    return { openKeys, openTotalPx, openWeightSum, widthsPx };
}

export function clampOpenPaneWeights({
    weights,
    minimized = {},
    boardWidthPx = 1000,
    minPaneWidthPx = MIN_SHORTCUTS_PANE_WIDTH_PX,
}) {
    const snapshot = computeOpenPaneWidthPx(weights, minimized, boardWidthPx);
    const { openKeys, openTotalPx, openWeightSum, widthsPx } = snapshot;
    if (openKeys.length <= 1 || openTotalPx <= 0 || openWeightSum <= 0) {
        return { ...weights };
    }

    const effectiveMin = Math.min(minPaneWidthPx, openTotalPx / openKeys.length);
    const under = openKeys.filter((k) => widthsPx[k] < effectiveMin - 0.01);
    if (under.length === 0) {
        return { ...weights };
    }

    const nextWidths = { ...widthsPx };
    let deficit = 0;
    for (const k of under) {
        deficit += effectiveMin - nextWidths[k];
        nextWidths[k] = effectiveMin;
    }

    const overKeys = openKeys.filter((k) => nextWidths[k] > effectiveMin + 0.01);
    const surplus = overKeys.reduce((acc, k) => acc + (nextWidths[k] - effectiveMin), 0);
    if (surplus > 0) {
        const takeRatio = Math.min(1, deficit / surplus);
        for (const k of overKeys) {
            nextWidths[k] -= (nextWidths[k] - effectiveMin) * takeRatio;
        }
    }

    const nextWeights = { ...weights };
    for (const k of openKeys) {
        nextWeights[k] = openWeightSum * (nextWidths[k] / openTotalPx);
    }
    return nextWeights;
}

export function resizeShortcutsPairByDelta({
    weights,
    minimized = {},
    splitterKey,
    deltaPx = 0,
    boardWidthPx = 1000,
    minPaneWidthPx = MIN_SHORTCUTS_PANE_WIDTH_PX,
}) {
    const pair = resolveShortcutsSplitterPair(splitterKey, minimized);
    if (!pair || !Number.isFinite(Number(boardWidthPx)) || Number(boardWidthPx) <= 0) {
        return { changed: false, pair: null, weights: { ...weights }, leftPct: 50, minPct: 20 };
    }

    const [leftKey, rightKey] = pair;
    const snapshot = computeOpenPaneWidthPx(weights, minimized, boardWidthPx);
    const leftWidth0 = snapshot.widthsPx[leftKey];
    const rightWidth0 = snapshot.widthsPx[rightKey];
    const pairTotalPx = leftWidth0 + rightWidth0;
    if (pairTotalPx <= 0) {
        return { changed: false, pair, weights: { ...weights }, leftPct: 50, minPct: 20 };
    }

    const pairWeightSum = Math.max(0.002, Number(weights[leftKey] || 0) + Number(weights[rightKey] || 0));
    const effectiveMin = Math.min(minPaneWidthPx, pairTotalPx / 2);
    const rawLeft = leftWidth0 + Number(deltaPx || 0);
    const nextLeftPx = Math.min(pairTotalPx - effectiveMin, Math.max(effectiveMin, rawLeft));
    const nextRightPx = pairTotalPx - nextLeftPx;

    const nextLeftWeight = pairWeightSum * (nextLeftPx / pairTotalPx);
    const nextRightWeight = pairWeightSum - nextLeftWeight;

    return {
        changed: Math.abs(nextLeftPx - leftWidth0) > 0.01,
        pair,
        weights: {
            ...weights,
            [leftKey]: nextLeftWeight,
            [rightKey]: nextRightWeight,
        },
        leftPct: (nextLeftPx / pairTotalPx) * 100,
        minPct: (effectiveMin / pairTotalPx) * 100,
    };
}

const CONTEXT_FILTER_KEYS = ['status', 'search', 'q', 'month', 'period', 'year', 'type', 'category', 'filter', 'scope', 'tab'];
const HASH_TOKEN_LIKE_RE = /^[A-Za-z0-9]{12,}$/;

export function formatSessionDuplicateBadge(duplicateIndex = 1, duplicateTotal = 1) {
    const idx = Number(duplicateIndex) || 1;
    const total = Number(duplicateTotal) || 1;

    return total > 1 && idx > 1 ? `(${idx})` : '';
}

export function formatSessionContextHint(rawUrl, { childTitle = '', parentTitle = '', maxLength = 42 } = {}) {
    const parts = [];
    const cleanChild = typeof childTitle === 'string' ? childTitle.trim() : '';
    const cleanParent = typeof parentTitle === 'string' ? parentTitle.trim() : '';

    if (cleanChild && cleanChild.toLowerCase() !== cleanParent.toLowerCase()) {
        parts.push(cleanChild);
    }

    if (typeof rawUrl === 'string' && rawUrl.trim()) {
        try {
            const parsed = new URL(rawUrl, 'http://localhost');
            for (const key of CONTEXT_FILTER_KEYS) {
                const rawVal = parsed.searchParams.get(key);
                const val = typeof rawVal === 'string' ? rawVal.trim() : '';
                if (!val || val.toLowerCase() === 'all' || HASH_TOKEN_LIKE_RE.test(val)) {
                    continue;
                }
                parts.push(key === 'search' || key === 'q' ? `"${val}"` : `${key}: ${val}`);
                if (parts.length >= 2) break;
            }
        } catch {
            // Ignore malformed URLs when deriving optional UI hint.
        }
    }

    if (parts.length === 0) return '';
    const joined = parts.join(' · ');
    return joined.length > maxLength ? `${joined.slice(0, Math.max(8, maxLength - 1)).trimEnd()}…` : joined;
}
