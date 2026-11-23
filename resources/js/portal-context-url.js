export const PAYLOAD_PREFIX = 'scv2';
export const APPLICATION = 'erp';
export const TYPE = 'session';
export const MAX_RAW_LENGTH = 2048;
export const MAX_DECODED_BYTES = 1536;
export const MAX_JSON_DEPTH = 4;
export const MAX_QUERY_PARAMS = 12;
export const MAX_ARGUMENT_LENGTH = 1024;
export const MAX_VALUE_LENGTH = 256;

const PLAIN_INT_KEYS = new Set(['announcement', 'period_id']);
const PAYLOAD_KEYS = new Set(['description', 'application', 'type', 'action', 'path', 'argument']);
const LOGICAL_ID_RE = /^lid:\/\/adasi\.portal\.[a-z0-9_.-]{1,32}$/;
const ACTION_RE = /^[a-z0-9._-]{3,64}$/;
const STRICT_BASE64_RE = /^[A-Za-z0-9+/]+={0,2}$/;
const ARG_KEY_RE = /^[a-zA-Z0-9_]{1,64}$/;
const PLAIN_INT_RE = /^[1-9]\d{0,17}$/;
const HASH_TOKEN_RE = /^[A-Za-z0-9]{1,64}$/;
const DIGITS_ONLY_RE = /^\d+$/;
const CONTROL_CHARS_RE = /[\x00-\x1F\x7F]/;

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder('utf-8', { fatal: true });

export function encodeRfc3986(value) {
    return encodeURIComponent(String(value)).replace(
        /[!'()*]/g,
        (ch) => `%${ch.charCodeAt(0).toString(16).toUpperCase()}`
    );
}

function bytesToBase64(bytes) {
    let binary = '';
    for (let i = 0; i < bytes.length; i += 1) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

function base64ToBytes(raw) {
    const binary = atob(raw);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}

function exceedsPhpJsonDepth(value, currentDepth = 1) {
    // PHP json_decode(..., depth: 4) rejects 3+ nested objects/arrays inside the root object.
    if (value === null || typeof value !== 'object') {
        return false;
    }
    if (currentDepth >= MAX_JSON_DEPTH) {
        return true;
    }
    const children = Array.isArray(value) ? value : Object.values(value);
    for (const child of children) {
        if (exceedsPhpJsonDepth(child, currentDepth + 1)) {
            return true;
        }
    }
    return false;
}

function buildContextQueryString(favoriteContext, logicalId) {
    return `favoriteContext=${encodeRfc3986(favoriteContext)}&LogicalId=${encodeRfc3986(logicalId)}`;
}

function normalizePath(path) {
    const trimmed = String(path || '/').replace(/\/+$/, '');
    return trimmed === '' ? '/' : trimmed;
}

function normalizeBasePath(raw) {
    const trimmed = String(raw || '').trim().replace(/\/+$/, '');
    if (trimmed === '' || trimmed === '/') {
        return '';
    }
    if (
        !trimmed.startsWith('/')
        || trimmed.startsWith('//')
        || trimmed.includes('\\')
        || trimmed.includes('?')
        || trimmed.includes('#')
        || CONTROL_CHARS_RE.test(trimmed)
        || /(?:^|\/)\.\.(?:\/|$)/.test(trimmed)
    ) {
        return '';
    }
    return trimmed;
}

function stripBasePath(pathname, basePath) {
    const normalized = normalizePath(pathname);
    if (!basePath) {
        return normalized;
    }
    if (normalized === basePath) {
        return '/';
    }
    if (normalized.startsWith(`${basePath}/`)) {
        return normalized.slice(basePath.length);
    }
    return null;
}

function contextAppPath(contextLinks) {
    const basePath = normalizeBasePath(contextLinks?.basePath);
    const rawPrefix = String(contextLinks?.pathPrefix || '').replace(/^\/+|\/+$/g, '');
    const appUuid = String(contextLinks?.appUuid || '').toLowerCase();
    if (!appUuid) {
        return '';
    }
    return `${basePath}${rawPrefix !== '' ? `/${rawPrefix}` : ''}/${appUuid}`;
}

function encodeQueryPairs(orderedEntries) {
    if (!orderedEntries.length) {
        return '';
    }
    return orderedEntries.map(([k, v]) => `${encodeRfc3986(k)}=${encodeRfc3986(v)}`).join('&');
}

function safeDecodeUriComponentStrict(raw) {
    try {
        return decodeURIComponent(raw);
    } catch {
        return null;
    }
}

export function encodeScv2Payload(description, action, argument) {
    const payload = {
        description,
        application: APPLICATION,
        type: TYPE,
        action,
        path: null,
        argument,
    };
    const json = JSON.stringify(payload);
    return bytesToBase64(textEncoder.encode(`${PAYLOAD_PREFIX}${json}`));
}

export function decodeScv2Payload(raw) {
    if (typeof raw !== 'string' || raw === '' || textEncoder.encode(raw).length > MAX_RAW_LENGTH) {
        return null;
    }
    if (raw.length % 4 !== 0 || !STRICT_BASE64_RE.test(raw)) {
        return null;
    }

    let bytes;
    let decoded;
    try {
        bytes = base64ToBytes(raw);
        if (bytes.length === 0 || bytes.length > MAX_DECODED_BYTES) {
            return null;
        }
        decoded = textDecoder.decode(bytes);
    } catch {
        return null;
    }

    if (!decoded.startsWith(PAYLOAD_PREFIX)) {
        return null;
    }

    let data;
    try {
        data = JSON.parse(decoded.slice(PAYLOAD_PREFIX.length));
    } catch {
        return null;
    }

    if (data === null || typeof data !== 'object' || Array.isArray(data) || exceedsPhpJsonDepth(data)) {
        return null;
    }

    for (const key of Object.keys(data)) {
        if (!PAYLOAD_KEYS.has(key)) {
            return null;
        }
    }

    if (data.application !== APPLICATION || data.type !== TYPE) {
        return null;
    }

    if (typeof data.action !== 'string' || !ACTION_RE.test(data.action)) {
        return null;
    }

    const path = data.path ?? null;
    if (path !== null && path !== '') {
        return null;
    }

    const description = data.description ?? '';
    if (typeof description !== 'string' || CONTROL_CHARS_RE.test(description)) {
        return null;
    }

    const argument = data.argument ?? '';
    if (typeof argument !== 'string' || textEncoder.encode(argument).length > MAX_ARGUMENT_LENGTH) {
        return null;
    }

    return {
        description,
        application: APPLICATION,
        type: TYPE,
        action: data.action,
        path: null,
        argument,
    };
}

function validateTypedValue(key, value, entry) {
    if (PLAIN_INT_KEYS.has(key)) {
        return PLAIN_INT_RE.test(value);
    }

    const hashedKeys = Array.isArray(entry.hashedKeys) ? entry.hashedKeys : [];
    if (hashedKeys.includes(key)) {
        return HASH_TOKEN_RE.test(value) && !DIGITS_ONLY_RE.test(value);
    }

    return textEncoder.encode(value).length <= MAX_VALUE_LENGTH && !CONTROL_CHARS_RE.test(value);
}

function matchPathAgainstIndex(path, sessionMap) {
    const pathSegments = path === '/' ? [] : path.replace(/^\/+|\/+$/g, '').split('/');
    let best = null;
    let bestScore = null;

    for (const entry of sessionMap) {
        const template = String(entry.pathTemplate || '');
        const tplSegments = template === '/' ? [] : template.replace(/^\/+|\/+$/g, '').split('/');
        if (tplSegments.length !== pathSegments.length) {
            continue;
        }

        const matchedParams = {};
        let staticCount = 0;
        let ok = true;

        for (let i = 0; i < tplSegments.length; i += 1) {
            const tplSeg = tplSegments[i];
            const actualSeg = pathSegments[i];
            if (tplSeg.startsWith('{') && tplSeg.endsWith('}')) {
                if (actualSeg === '') {
                    ok = false;
                    break;
                }
                const decodedSeg = safeDecodeUriComponentStrict(actualSeg);
                if (decodedSeg === null || decodedSeg === '' || decodedSeg.includes('/')) {
                    ok = false;
                    break;
                }
                matchedParams[tplSeg.slice(1, -1)] = decodedSeg;
            } else if (tplSeg === actualSeg) {
                staticCount += 1;
            } else {
                ok = false;
                break;
            }
        }

        if (!ok) {
            continue;
        }

        const paramCount = Object.keys(matchedParams).length;
        const score = (staticCount * 1000) - (paramCount * 100) + template.length;
        if (bestScore === null || score > bestScore) {
            bestScore = score;
            best = { entry, params: matchedParams };
        }
    }

    return best;
}

export function buildSessionIndex(sessionMap) {
    if (Array.isArray(sessionMap)) {
        return sessionMap;
    }
    if (sessionMap && typeof sessionMap === 'object') {
        return Object.values(sessionMap);
    }
    return [];
}

/**
 * Project a clean internal portal URL into its canonical Infor-style context URL.
 */
export function projectShellUrl(internalUrl, origin, contextLinks) {
    if (!internalUrl || !contextLinks || !contextLinks.enabled) {
        return null;
    }

    let parsed;
    let baseOrigin;
    try {
        baseOrigin = new URL(origin);
        parsed = new URL(internalUrl, baseOrigin);
    } catch {
        return null;
    }

    if (parsed.origin !== baseOrigin.origin) {
        return null;
    }

    const basePath = normalizeBasePath(contextLinks.basePath);
    const appRelativePath = stripBasePath(parsed.pathname, basePath);
    if (appRelativePath === null) {
        return null;
    }

    const index = buildSessionIndex(contextLinks.sessionMap);
    const matched = matchPathAgainstIndex(appRelativePath, index);
    if (!matched) {
        return null;
    }

    const { entry, params } = matched;
    for (const [k, v] of Object.entries(params)) {
        if (!validateTypedValue(k, v, entry)) {
            return null;
        }
    }

    const allowlist = new Set(Array.isArray(entry.queryAllowlist) ? entry.queryAllowlist : []);
    const filteredQuery = new Map();
    const rawSearch = parsed.search.startsWith('?') ? parsed.search.slice(1) : parsed.search;

    if (rawSearch !== '') {
        for (const pair of rawSearch.split('&')) {
            if (pair === '' || !pair.includes('=')) {
                continue;
            }
            const eqIdx = pair.indexOf('=');
            const rawKey = pair.slice(0, eqIdx);
            const rawVal = pair.slice(eqIdx + 1);
            // Only decode query values without `+ -> space` drift for strict parity with PHP parse rawurlencode,
            // except standard form filters where `+` may come from HTML GET forms.
            const key = safeDecodeUriComponentStrict(rawKey.replace(/\+/g, '%20'));
            const val = safeDecodeUriComponentStrict(rawVal.replace(/\+/g, '%20'));
            if (key === null || val === null || key.includes('[')) {
                continue;
            }
            if (val === '' || !allowlist.has(key)) {
                continue;
            }
            if (filteredQuery.has(key)) {
                return null;
            }
            if (!validateTypedValue(key, val, entry)) {
                return null;
            }
            filteredQuery.set(key, val);
        }
    }

    if (filteredQuery.size > MAX_QUERY_PARAMS) {
        return null;
    }

    const paramOrder = Array.isArray(entry.params) ? entry.params : [];
    const orderedArgumentPairs = [];
    for (const key of paramOrder) {
        if (Object.prototype.hasOwnProperty.call(params, key)) {
            orderedArgumentPairs.push([key, params[key]]);
        }
    }

    const sortedQueryKeys = Array.from(filteredQuery.keys()).sort();
    for (const key of sortedQueryKeys) {
        orderedArgumentPairs.push([key, filteredQuery.get(key)]);
    }

    const argument = encodeQueryPairs(orderedArgumentPairs);
    if (textEncoder.encode(argument).length > MAX_ARGUMENT_LENGTH) {
        return null;
    }

    const description = String(entry.description || entry.action);
    const jsonString = JSON.stringify({
        description,
        application: APPLICATION,
        type: TYPE,
        action: entry.action,
        path: null,
        argument,
    });
    if (textEncoder.encode(`${PAYLOAD_PREFIX}${jsonString}`).length > MAX_DECODED_BYTES) {
        return null;
    }

    const favoriteContext = encodeScv2Payload(description, entry.action, argument);
    if (textEncoder.encode(favoriteContext).length > MAX_RAW_LENGTH) {
        return null;
    }

    const appPath = contextAppPath(contextLinks);
    if (!appPath) {
        return null;
    }

    return `${baseOrigin.origin}${appPath}?${buildContextQueryString(favoriteContext, entry.logicalId)}`;
}

/**
 * Reverse-resolve an Infor-style context URL back to its clean internal portal URL for popstate/tabs.
 */
export function resolveContextUrl(contextUrl, origin, contextLinks) {
    if (!contextUrl || !contextLinks || !contextLinks.enabled) {
        return null;
    }

    let parsed;
    let baseOrigin;
    try {
        baseOrigin = new URL(origin);
        parsed = new URL(contextUrl, baseOrigin);
    } catch {
        return null;
    }

    if (parsed.origin !== baseOrigin.origin) {
        return null;
    }

    const basePath = normalizeBasePath(contextLinks.basePath);
    const expectedPath = contextAppPath(contextLinks);
    if (!expectedPath || normalizePath(parsed.pathname).toLowerCase() !== expectedPath.toLowerCase()) {
        return null;
    }

    const rawSearch = parsed.search.startsWith('?') ? parsed.search.slice(1) : parsed.search;
    if (rawSearch === '') {
        return null;
    }

    let rawFavoriteContext = null;
    let rawLogicalId = null;
    for (const pair of rawSearch.split('&')) {
        if (!pair.includes('=')) {
            continue;
        }
        const eqIdx = pair.indexOf('=');
        const k = safeDecodeUriComponentStrict(pair.slice(0, eqIdx));
        const vRaw = pair.slice(eqIdx + 1);
        if (k === 'favoriteContext') {
            rawFavoriteContext = safeDecodeUriComponentStrict(vRaw);
        } else if (k === 'LogicalId') {
            rawLogicalId = safeDecodeUriComponentStrict(vRaw);
        }
    }

    if (!rawFavoriteContext) {
        return null;
    }

    const payload = decodeScv2Payload(rawFavoriteContext);
    if (!payload) {
        return null;
    }

    const index = buildSessionIndex(contextLinks.sessionMap);
    const entry = index.find((item) => item.action === payload.action);
    if (!entry) {
        return null;
    }

    if (rawLogicalId !== null && (!LOGICAL_ID_RE.test(rawLogicalId) || rawLogicalId !== entry.logicalId)) {
        return null;
    }

    const entryParams = Array.isArray(entry.params) ? entry.params : [];
    const allowlist = new Set(Array.isArray(entry.queryAllowlist) ? entry.queryAllowlist : []);
    const seen = new Set();
    const routeParams = {};
    const queryMap = new Map();

    if (payload.argument !== '') {
        if (textEncoder.encode(payload.argument).length > MAX_ARGUMENT_LENGTH) {
            return null;
        }
        for (const pair of payload.argument.split('&')) {
            if (pair === '' || !pair.includes('=')) {
                return null;
            }
            const eqIdx = pair.indexOf('=');
            const decodedKey = safeDecodeUriComponentStrict(pair.slice(0, eqIdx));
            const decodedVal = safeDecodeUriComponentStrict(pair.slice(eqIdx + 1));
            if (
                decodedKey === null
                || decodedVal === null
                || !ARG_KEY_RE.test(decodedKey)
                || seen.has(decodedKey)
                || CONTROL_CHARS_RE.test(decodedVal)
                || textEncoder.encode(decodedVal).length > MAX_VALUE_LENGTH
            ) {
                return null;
            }
            seen.add(decodedKey);

            if (entryParams.includes(decodedKey)) {
                if (decodedVal === '' || decodedVal.includes('/') || !validateTypedValue(decodedKey, decodedVal, entry)) {
                    return null;
                }
                routeParams[decodedKey] = decodedVal;
            } else if (allowlist.has(decodedKey)) {
                if (decodedVal !== '') {
                    if (!validateTypedValue(decodedKey, decodedVal, entry)) {
                        return null;
                    }
                    queryMap.set(decodedKey, decodedVal);
                }
            } else {
                return null;
            }
        }
    }

    for (const reqParam of entryParams) {
        if (!Object.prototype.hasOwnProperty.call(routeParams, reqParam)) {
            return null;
        }
    }

    if (queryMap.size > MAX_QUERY_PARAMS) {
        return null;
    }

    let resolvedPath = String(entry.pathTemplate || '/');
    for (const param of entryParams) {
        resolvedPath = resolvedPath.replace(`{${param}}`, encodeRfc3986(routeParams[param]));
    }

    const sortedQueryEntries = Array.from(queryMap.keys())
        .sort()
        .map((k) => [k, queryMap.get(k)]);
    const qs = encodeQueryPairs(sortedQueryEntries);

    const httpResolvedPath = `${basePath}${resolvedPath}`;

    return {
        action: entry.action,
        route: entry.route,
        logicalId: entry.logicalId,
        params: routeParams,
        query: Object.fromEntries(sortedQueryEntries),
        internalPath: `${httpResolvedPath}${qs !== '' ? `?${qs}` : ''}`,
        internalUrl: `${baseOrigin.origin}${httpResolvedPath}${qs !== '' ? `?${qs}` : ''}`,
    };
}

export function getContextRootUrl(origin, contextLinks, fallbackShortcutsUrl = '') {
    let baseOrigin;
    try {
        baseOrigin = new URL(origin);
    } catch {
        return '';
    }

    const explicit = fallbackShortcutsUrl || contextLinks?.shortcutsUrl || '';
    if (explicit) {
        try {
            const parsed = new URL(explicit, baseOrigin);
            if (parsed.origin === baseOrigin.origin) {
                parsed.search = '';
                parsed.hash = '';
                return parsed.toString();
            }
        } catch {
            // Fall through to appUuid path derivation.
        }
    }

    const appPath = contextAppPath(contextLinks);
    if (!appPath) {
        return '';
    }

    return `${baseOrigin.origin}${appPath}`;
}

export function buildHistoryProjection({
    internalUrl,
    origin,
    contextLinks,
    frameId = null,
    groupId = null,
    title = '',
}) {
    const displayUrl = contextLinks?.enabled
        ? projectShellUrl(internalUrl, origin, contextLinks) || internalUrl
        : internalUrl;

    return {
        displayUrl,
        state: {
            workspaceShell: true,
            shortcuts: false,
            frameId,
            groupId,
            url: displayUrl,
            internalUrl,
            title,
        },
    };
}

export function buildShortcutsHistoryProjection({
    lastContextUrl = null,
    origin,
    contextLinks,
    dashboardUrl = '',
    shortcutsUrl = '',
    title = 'Shortcuts',
}) {
    if (contextLinks?.enabled) {
        let baseOrigin;
        try {
            baseOrigin = new URL(origin);
        } catch {
            baseOrigin = null;
        }

        let validLastUrl = null;
        if (lastContextUrl && baseOrigin) {
            try {
                const parsedLast = new URL(lastContextUrl, baseOrigin);
                if (parsedLast.origin === baseOrigin.origin && parsedLast.searchParams.has('favoriteContext')) {
                    validLastUrl = parsedLast.toString();
                }
            } catch {
                validLastUrl = null;
            }
        }

        const rootUrl = getContextRootUrl(origin, contextLinks, shortcutsUrl);
        const target = validLastUrl || rootUrl || String(dashboardUrl || origin).split('#')[0];

        return {
            target,
            state: {
                workspaceShell: true,
                shortcuts: true,
                lastContextUrl: validLastUrl,
                url: target,
                title,
            },
        };
    }

    const dashBase = String(dashboardUrl || origin).split('#')[0];
    const target = `${dashBase}#shortcuts`;

    return {
        target,
        state: {
            workspaceShell: true,
            shortcuts: true,
            url: target,
            title,
        },
    };
}

export function resolvePopstateNavigation({
    eventState,
    currentHref,
    hash,
    origin,
    contextLinks,
    menuPathTitles,
    hasLiveFrame,
}) {
    if (eventState?.shortcuts || hash === '#shortcuts') {
        return { kind: 'shortcuts' };
    }

    const stateFrameId = eventState?.frameId;
    if (stateFrameId && typeof hasLiveFrame === 'function' && hasLiveFrame(stateFrameId)) {
        return { kind: 'activate-frame', frameId: stateFrameId };
    }

    const rawCandidate = eventState?.internalUrl || eventState?.url || currentHref;
    if (!rawCandidate) {
        return { kind: 'ignore' };
    }

    let internalUrl = rawCandidate;
    if (contextLinks?.enabled) {
        const resolved = resolveContextUrl(rawCandidate, origin, contextLinks);
        if (resolved?.internalUrl) {
            internalUrl = resolved.internalUrl;
        } else {
            try {
                const parsedCandidate = new URL(rawCandidate, origin);
                const candidatePath = normalizePath(parsedCandidate.pathname).toLowerCase();
                const expectedPath = contextAppPath(contextLinks).toLowerCase();
                if (expectedPath && candidatePath === expectedPath) {
                    if (parsedCandidate.search === '') {
                        return { kind: 'shortcuts' };
                    }
                    return { kind: 'ignore' };
                }
            } catch {
                return { kind: 'ignore' };
            }
        }
    }

    let path = '/';
    try {
        path = normalizePath(new URL(internalUrl, origin).pathname);
    } catch {
        return { kind: 'ignore' };
    }

    // Never trust favoriteContext.description or arbitrary state text for chrome labels (SEC-05).
    const safeLabel = menuPathTitles instanceof Map
        ? menuPathTitles.get(path) || ''
        : (menuPathTitles && typeof menuPathTitles === 'object' ? menuPathTitles[path] || '' : '');

    return {
        kind: 'open-url',
        url: internalUrl,
        label: safeLabel,
        historyMode: 'replace',
    };
}
