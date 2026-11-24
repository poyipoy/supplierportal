/**
 * ADASI Portal Supplier — Spec-Compliant Origin Validation Utility
 *
 * Uses the native `Origin` API (`Origin.from()`, `.isSameOrigin()`) when supported,
 * falling back to `new URL()` comparison that explicitly rejects opaque `"null"`
 * origins (`"null" === "null"` collision prevention).
 */

function extractUrlString(candidate, baseHref) {
    if (candidate === null || candidate === undefined) return '';
    if (typeof candidate === 'string') return candidate;
    if (typeof MessageEvent !== 'undefined' && candidate instanceof MessageEvent) {
        return typeof candidate.origin === 'string' ? candidate.origin : '';
    }
    if (candidate === globalThis || (typeof Window !== 'undefined' && candidate instanceof Window)) {
        return candidate.location?.href ?? '';
    }
    if (typeof candidate.origin === 'string' && !('href' in candidate)) {
        return candidate.origin;
    }
    if (typeof candidate.href === 'string') {
        return candidate.href;
    }
    if (candidate instanceof URL) {
        return candidate.href;
    }
    return baseHref ? '' : String(candidate);
}

function extractNativeOrigin(candidate, baseHref) {
    const OriginCtor = globalThis.Origin;
    try {
        return OriginCtor.from(candidate);
    } catch (err) {
        if (typeof MessageEvent !== 'undefined' && candidate instanceof MessageEvent) {
            return OriginCtor.from(candidate.origin);
        }
        if (typeof candidate === 'string' && baseHref) {
            const resolved = new URL(candidate, baseHref);
            return OriginCtor.from(resolved);
        }
        if (candidate && typeof candidate === 'object' && typeof candidate.origin === 'string' && !('href' in candidate)) {
            return OriginCtor.from(candidate.origin);
        }
        throw err;
    }
}

/**
 * Strictly verify that `candidate` (a URL string, MessageEvent, URL, anchor, or Window)
 * has the exact same tuple origin (scheme, host, port) as `reference` (defaults to current window).
 * Always rejects invalid URLs and opaque `"null"` origins.
 *
 * @param {string|URL|MessageEvent|Window|Element|{origin?: string, href?: string}} candidate
 * @param {string|URL|Window|{origin?: string, href?: string}} [reference]
 * @returns {boolean}
 */
export function isTrustedSameOrigin(candidate, reference = globalThis.window ?? globalThis) {
    if (candidate === null || candidate === undefined || reference === null || reference === undefined) {
        return false;
    }

    const refUrlStr = extractUrlString(reference);
    if (!refUrlStr || refUrlStr === 'null') {
        return false;
    }

    try {
        if ('Origin' in globalThis && typeof globalThis.Origin?.from === 'function') {
            const candidateOrigin = extractNativeOrigin(candidate, refUrlStr);
            const referenceOrigin = extractNativeOrigin(reference, refUrlStr);
            if (candidateOrigin.opaque || referenceOrigin.opaque) {
                return false;
            }
            return candidateOrigin.isSameOrigin(referenceOrigin);
        }

        const candidateStr = extractUrlString(candidate, refUrlStr);
        if (!candidateStr || candidateStr === 'null') {
            return false;
        }

        const trustedUrl = new URL(refUrlStr);
        const candidateUrl = new URL(candidateStr, trustedUrl.href);

        return (
            trustedUrl.origin !== 'null'
            && candidateUrl.origin !== 'null'
            && ['http:', 'https:'].includes(candidateUrl.protocol)
            && candidateUrl.origin === trustedUrl.origin
        );
    } catch {
        return false;
    }
}

/**
 * Validate a relative or absolute HTTP(S) URL against a reference origin string.
 * Rejects `javascript:`, `data:`, opaque `"null"` origins, and cross-origin URLs.
 *
 * @param {string} url
 * @param {string} origin
 * @returns {boolean}
 */
export function isSameOriginHttpUrl(url, origin) {
    if (typeof url !== 'string' && !(url instanceof URL)) {
        return false;
    }
    const raw = String(url).trim();
    if (!raw || !origin || origin === 'null') {
        return false;
    }
    try {
        const trusted = new URL(String(origin));
        const resolved = new URL(raw, trusted.href);
        if (!['http:', 'https:'].includes(resolved.protocol)) {
            return false;
        }
        return isTrustedSameOrigin(resolved, trusted);
    } catch {
        return false;
    }
}
