/**
 * Navigate to an application URL inside the active Workspace Shell when available,
 * preserving all open workspaces instead of refreshing the top-level browser window.
 * Falls back to standard top-level navigation for cross-origin URLs or when the
 * Workspace Shell is disabled/unavailable.
 */
export async function navigateInWorkspaceOrTop(
    rawUrl,
    {
        win = (typeof window !== 'undefined' ? window : null),
        ensureShell = null,
    } = {},
) {
    if (!rawUrl || typeof rawUrl !== 'string' || !win?.location?.href) {
        return { navigated: false, inShell: false, url: null };
    }

    let target;
    try {
        target = new URL(rawUrl, win.location.href);
    } catch {
        return { navigated: false, inShell: false, url: null };
    }

    if (!['http:', 'https:'].includes(target.protocol)) {
        return { navigated: false, inShell: false, url: null };
    }

    const isSameOrigin = target.origin === win.location.origin;
    let shell = win.AdasiWorkspaceShell || null;

    if (!shell && isSameOrigin && typeof ensureShell === 'function') {
        try {
            shell = await ensureShell();
        } catch {
            shell = null;
        }
    }

    if (isSameOrigin && shell && typeof shell.openUrl === 'function') {
        shell.openUrl(target.href);
        return { navigated: true, inShell: true, url: target.href };
    }

    if (typeof win.location.assign === 'function') {
        win.location.assign(target.href);
    } else {
        win.location.href = target.href;
    }

    return { navigated: true, inShell: false, url: target.href };
}

