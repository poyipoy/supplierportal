/**
 * Asset Protection Module
 *
 * Prevents unauthorized copying, dragging, and context menu inspection
 * on brand images (logos, background banners, and system visual assets)
 * while preserving natural interactions on forms and text.
 */

function isProtectedImage(target) {
    if (!target || !(target instanceof Element)) {
        return false;
    }

    if (target.tagName === 'IMG') {
        return true;
    }

    if (target.closest('img')) {
        return true;
    }

    if (
        target.closest('.auth-brand-panel') ||
        target.closest('.sidebar-brand-logo') ||
        target.closest('.no-copy') ||
        target.classList.contains('sidebar-brand-logo') ||
        target.classList.contains('no-copy')
    ) {
        return true;
    }

    return false;
}

export function initAssetProtection() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    // Prevent context menu (right-click) specifically on images and brand panels
    document.addEventListener('contextmenu', (event) => {
        if (isProtectedImage(event.target)) {
            event.preventDefault();
        }
    }, { capture: true });

    // Prevent drag-and-drop export of images to desktop or other tabs
    document.addEventListener('dragstart', (event) => {
        if (isProtectedImage(event.target)) {
            event.preventDefault();
        }
    }, { capture: true });
}

// Auto-initialize when script loads
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAssetProtection);
    } else {
        initAssetProtection();
    }
}
