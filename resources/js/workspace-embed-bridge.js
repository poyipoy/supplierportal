import {
    appendEmbedParam,
    isDownloadOrBinaryUrl,
    isNonMutatingPostUrl,
    isSameOriginUrl,
    isTopLevelOnlyUrl,
    safeWebUrl,
    stripEmbedParam,
} from './workspace-shell-core.js';

function getParentShell() {
    if (typeof window === 'undefined' || window.parent === window) return null;
    try {
        if (window.parent.location.origin !== window.location.origin) return null;
        return window.parent.AdasiWorkspaceShell || null;
    } catch {
        return null;
    }
}

export function bootWorkspaceEmbedBridge() {
    const shell = getParentShell();
    if (!shell) return null;

    const origin = window.location.origin;
    const prefix = window.AdasiPortal?.pathPrefix || '';

    const toAbsoluteUrl = (raw) => {
        try {
            return new URL(raw, window.location.href).toString();
        } catch {
            return raw;
        }
    };

    const normalizeToastOptions = (options = {}) => {
        if (!options || typeof options !== 'object') return options;
        const cloned = { ...options };
        if (Array.isArray(options.actions)) {
            cloned.actions = options.actions.map((action) => {
                if (!action || typeof action !== 'object') return action;
                const nextAction = { ...action };
                if (typeof nextAction.url === 'string' && nextAction.url) {
                    const safeHref = safeWebUrl(toAbsoluteUrl(nextAction.url), window.location.href);
                    nextAction.url = null;
                    if (safeHref && isSameOriginUrl(safeHref, origin)) {
                        nextAction.onClick = () => {
                            if (isTopLevelOnlyUrl(safeHref, origin, prefix)) {
                                shell.breakoutToTop(safeHref);
                            } else {
                                shell.openUrl(safeHref);
                            }
                        };
                    }
                }
                return nextAction;
            });
        }
        return cloned;
    };

    // Delegate App Toasts to top Workspace Shell so they never clip inside iframe stage.
    if (window.AdasiToast) {
        const parentToast = window.parent.AdasiToast;
        window.AdasiToast = Object.freeze({
            show: (options) => shell.showToast(normalizeToastOptions(options)),
            success: (message, options = {}) => shell.showToast(normalizeToastOptions({ ...options, type: 'success', message })),
            error: (message, options = {}) => shell.showToast(normalizeToastOptions({ ...options, type: 'error', message })),
            warning: (message, options = {}) => shell.showToast(normalizeToastOptions({ ...options, type: 'warning', message })),
            info: (message, options = {}) => shell.showToast(normalizeToastOptions({ ...options, type: 'info', message })),
            progress: (options = {}) => shell.showToast(normalizeToastOptions({ ...options, type: 'progress', autoClose: 0 })),
            update: (id, changes) => parentToast?.update?.(id, changes),
            dismiss: (id, reason) => parentToast?.dismiss?.(id, reason),
            clear: () => parentToast?.clear?.(),
        });
    }

    // Delegate Chat Drawer & Lightbox to top window overlays.
    window.openChatDrawer = () => shell.openChatDrawer();
    window.openChatConversation = (id) => shell.openChatConversation(id);
    if (window.parent.AdasiLightbox) {
        window.AdasiLightbox = Object.freeze({
            open: (src, title, downloadUrl, gallery = null, startIndex = 0) => {
                const absGallery = Array.isArray(gallery)
                    ? gallery.map((item) => ({
                        ...item,
                        src: toAbsoluteUrl(item.src),
                        downloadUrl: item.downloadUrl ? toAbsoluteUrl(item.downloadUrl) : null,
                    }))
                    : null;
                shell.openLightbox(toAbsoluteUrl(src), title, downloadUrl ? toAbsoluteUrl(downloadUrl) : null, absGallery, startIndex);
            },
            close: () => window.parent.AdasiLightbox?.close?.(),
            zoomIn: () => window.parent.AdasiLightbox?.zoomIn?.(),
            zoomOut: () => window.parent.AdasiLightbox?.zoomOut?.(),
            resetZoom: () => window.parent.AdasiLightbox?.resetZoom?.(),
            next: () => window.parent.AdasiLightbox?.next?.(),
            prev: () => window.parent.AdasiLightbox?.prev?.(),
        });
    }

    // Delegate Smart Panel triggers in capture phase before local handler runs.
    document.addEventListener('click', (event) => {
        const trigger = event.target?.closest?.('[data-smart-panel-trigger]');
        if (!trigger) return;
        const url = trigger.dataset.smartPanelUrl;
        if (!url) return;
        const absUrl = toAbsoluteUrl(url);
        if (!isSameOriginUrl(absUrl, origin)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        shell.openSmartPanel(absUrl);
    }, true);

    // Delegate Image Lightbox triggers in capture phase to parent fullscreen lightbox.
    document.addEventListener('click', (event) => {
        const trigger = event.target?.closest?.('[data-lightbox-image], a.image-preview-trigger, a[data-lightbox-preview], a[href*="/attachments/"]:has(img), a.qc-evidence-preview');
        if (!trigger) return;
        const href = trigger.getAttribute('href') || trigger.dataset.lightboxImage || trigger.dataset.src;
        const img = trigger.querySelector('img');
        if (!href || !(img || /\.(jpe?g|png|webp|gif|bmp|svg)(\?.*)?$/i.test(href) || trigger.hasAttribute('data-lightbox-image') || trigger.classList.contains('image-preview-trigger'))) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        const container = trigger.closest('.row, [class~="tw:grid"], .card, [data-lightbox-group]') || document;
        const triggers = Array.from(container.querySelectorAll('[data-lightbox-image], a.image-preview-trigger, a[data-lightbox-preview], a[href*="/attachments/"]:has(img), a.qc-evidence-preview'));
        const gallery = triggers.map((item) => {
            const itemHref = item.getAttribute('href') || item.dataset.lightboxImage || item.dataset.src;
            const itemImg = item.querySelector('img');
            return {
                src: toAbsoluteUrl(itemHref),
                title: item.getAttribute('title') || item.dataset.title || itemImg?.getAttribute('alt') || '',
                downloadUrl: toAbsoluteUrl(itemHref),
            };
        }).filter((item) => item.src);
        const idx = triggers.indexOf(trigger);
        window.AdasiLightbox?.open?.(toAbsoluteUrl(href), trigger.getAttribute('title') || trigger.dataset.title || img?.getAttribute('alt') || '', toAbsoluteUrl(href), gallery, idx >= 0 ? idx : 0);
    }, true);

    // Classify internal navigation clicks (bubble phase so AJAX/ServerTabs/DataTables run first).
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = event.target?.closest?.('a[href]');
        if (!anchor) return;
        if (
            anchor.hasAttribute('download')
            || anchor.getAttribute('target') === '_blank'
            || anchor.dataset.bsToggle
            || anchor.hasAttribute('data-server-tab')
            || anchor.hasAttribute('data-tabs-link')
            || anchor.closest?.('[data-server-tabs-nav]')
        ) {
            return;
        }

        const rawHref = anchor.getAttribute('href');
        if (!rawHref || rawHref.startsWith('#') || /^(javascript|mailto|tel|blob):/i.test(rawHref)) return;

        const absUrl = toAbsoluteUrl(rawHref);
        if (!isSameOriginUrl(absUrl, origin)) {
            const safeExternal = safeWebUrl(absUrl, origin);
            event.preventDefault();
            if (safeExternal) {
                window.open(safeExternal, '_blank', 'noopener,noreferrer');
            }
            return;
        }

        if (isDownloadOrBinaryUrl(absUrl, origin)) return;

        if (isTopLevelOnlyUrl(absUrl, origin, prefix)) {
            event.preventDefault();
            shell.breakoutToTop(absUrl);
            return;
        }

        const linkLabel = anchor.dataset.workspaceTitle
            || anchor.getAttribute('aria-label')
            || anchor.getAttribute('title')
            || anchor.textContent?.trim()
            || '';

        if (typeof shell.handleEmbedLinkClick === 'function') {
            const clickOptions = anchor.dataset.workspaceDetailSwitch === 'true' ? { detailSwitch: true } : {};
            const outcome = shell.handleEmbedLinkClick(window, absUrl, linkLabel, clickOptions);
            if (outcome === 'handled') {
                event.preventDefault();
                return;
            }
        }

        if (!anchor.href.includes('_embed=')) {
            anchor.href = appendEmbedParam(absUrl, origin);
        }
    });

    // Keep GET filter forms embedded without losing _embed=1.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        if (form.hasAttribute('data-managed-submit')) return;

        const method = (form.getAttribute('method') || 'get').toLowerCase();
        const actionUrl = toAbsoluteUrl(form.getAttribute('action') || window.location.href);

        if (isTopLevelOnlyUrl(actionUrl, origin, prefix)) {
            form.target = '_top';
            return;
        }

        if (method === 'get' && !form.querySelector('input[name="_embed"]')) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_embed';
            hidden.value = '1';
            form.appendChild(hidden);
        } else if (method !== 'get') {
            shell.notifyMutation(window);
        }
    });

    // Keep async-form-submit JSON redirects embedded (or break out for top-level-only targets).
    document.addEventListener('adasi:form-success', (event) => {
        const rawTarget = event?.detail?.redirect;
        if (typeof rawTarget !== 'string' || !rawTarget) return;

        const absUrl = toAbsoluteUrl(rawTarget);
        if (!isSameOriginUrl(absUrl, origin)) return;

        if (isTopLevelOnlyUrl(absUrl, origin, prefix)) {
            event.preventDefault();
            shell.breakoutToTop(absUrl);
            return;
        }

        if (!isDownloadOrBinaryUrl(absUrl, origin)) {
            const embeddedUrl = appendEmbedParam(absUrl, origin);
            if (embeddedUrl !== absUrl) {
                event.preventDefault();
                window.location.assign(embeddedUrl);
            }
        }
    });

    // Propagate dirty form transitions cleanly to parent tab pill.
    window.addEventListener('adasi:dirty-change', (event) => {
        shell.notifyDirty(window, Boolean(event?.detail?.dirty));
    });

    // Propagate AJAX mutations (`fetch` & `jQuery`) to sibling tabs for freshness,
    // excluding read-only live calculators and preview endpoints.
    if (window.fetch) {
        const prevFetch = window.fetch.bind(window);
        window.fetch = async (input, init = {}) => {
            const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
            const rawUrl = typeof input === 'string' ? input : (input instanceof Request ? input.url : '');
            const absUrl = rawUrl ? toAbsoluteUrl(rawUrl) : window.location.href;
            const response = await prevFetch(input, init);
            if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && response.ok && !isNonMutatingPostUrl(absUrl, origin)) {
                shell.notifyMutation(window);
            }
            return response;
        };
    }

    if (window.jQuery) {
        window.jQuery(document).ajaxSuccess((_event, _xhr, settings) => {
            const method = String(settings?.type || 'GET').toUpperCase();
            const absUrl = settings?.url ? toAbsoluteUrl(settings.url) : window.location.href;
            if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && !isNonMutatingPostUrl(absUrl, origin)) {
                shell.notifyMutation(window);
            }
        });
    }

    // Mirror theme/density/accent changes to parent & sibling iframes cleanly.
    ['adasi:theme-change', 'adasi:accent-change', 'adasi:shell-change', 'adasi:density-change'].forEach((evtName) => {
        window.addEventListener(evtName, () => shell.syncPreferencesFromEmbed(window));
    });
    shell.syncPreferencesFromEmbed(window);

    // Sync ServerTabs or in-frame history updates to top shell URL cleanly.
    const rawPushState = window.history.pushState.bind(window.history);
    const rawReplaceState = window.history.replaceState.bind(window.history);

    window.history.pushState = (state, unused, url) => {
        const target = url ? appendEmbedParam(toAbsoluteUrl(String(url)), origin) : url;
        rawPushState(state, unused, target);
        if (url && !state?.adasiUnsaved) {
            shell.notifyEmbedNavigated(window, stripEmbedParam(toAbsoluteUrl(String(url)), origin), document.title, 'replace');
        }
    };

    window.history.replaceState = (state, unused, url) => {
        const target = url ? appendEmbedParam(toAbsoluteUrl(String(url)), origin) : url;
        rawReplaceState(state, unused, target);
        if (url && !state?.adasiUnsaved) {
            shell.notifyEmbedNavigated(window, stripEmbedParam(toAbsoluteUrl(String(url)), origin), document.title, 'replace');
        }
    };

    window.addEventListener('popstate', (event) => {
        if (!event.state?.adasiUnsaved) {
            shell.notifyEmbedNavigated(window, stripEmbedParam(window.location.href, origin), document.title, 'replace');
        }
    });

    // Dismiss floating overlays in the parent shell (and sibling embeds) without
    // interfering with clicks inside an open dropdown/select inside this frame.
    document.addEventListener('pointerdown', () => {
        shell.dismissFloatingOverlays?.({ restoreFocus: false, excludeEmbedWindow: window });
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !event.defaultPrevented) {
            shell.dismissFloatingOverlays?.({ restoreFocus: false, excludeEmbedWindow: window });
        }
    });

    window.addEventListener('adasi:dismiss-overlays', () => {
        window.AdasiDismissDropdowns?.();
        window.AdasiCalendar?.closeActive?.();
    });

    // Inform outer shell only once DOM, DataTables initial draws, and boot fetches have settled.
    if (window.AdasiEmbedReadiness?.whenReady) {
        window.AdasiEmbedReadiness.whenReady().then(() => {
            shell.notifyEmbedReady(window);
        });
    } else {
        shell.notifyEmbedReady(window);
    }
    return shell;
}
