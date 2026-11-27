import { t } from './i18n.js';
import { isSameOriginUrl } from './async-form-submit.js';
import { onDataTableEvent, offDataTableEvent } from './datatables-events.js';

/**
 * ADASI Portal Supplier — Server-Side No-Reload Locale Switcher
 *
 * Processes language switching ('id' | 'en') via server-side endpoints without
 * triggering a full browser reload:
 * 1. Optimistically switches the header segmented control (`[ ID | EN ]`).
 * 2. Sends `POST /locale` with CSRF protection to persist user preference & session.
 * 3. Hot-swaps `window.AdasiI18n`, `window.AdasiDataTable`, and invalidates tab caches.
 * 4. Fetches the server-rendered HTML of the current page and performs non-destructive
 *    DOM morphing (`morphDomTree`) so all server-translated labels/headings/menus update
 *    in place while preserving live form inputs, passwords, and `<input type="file">` files.
 */

const ACTIVE_PILL_CLASSES = [
    'is-active',
    'tw:bg-surface',
    'tw:text-primary',
    'tw:font-bold',
    'tw:border',
    'tw:border-outline',
];

const INACTIVE_PILL_CLASSES = [
    'tw:border-transparent',
    'tw:text-on-surface-variant',
    'tw:font-semibold',
    'tw:hover:text-on-surface',
];

const TRANSLATED_ATTRIBUTES = [
    'aria-label',
    'title',
    'placeholder',
    'data-ui-tooltip',
    'data-sidebar-tooltip',
    'data-loading-text',
    'alt',
    'data-results-template',
    'data-empty-text',
    'data-value-template',
    'data-announcement-template',
    'data-reset-text',
    'data-collapsed-text',
    'data-default-title',
    'data-default-subtitle',
    'data-label-current',
    'data-label-read-only',
    'data-label-open-detail',
    'data-label-empty-chain',
    'data-label-empty-sla',
    'data-expand-label',
    'data-collapse-label',
];

export function normalizeTargetLocale(value) {
    return String(value || '').trim().toLowerCase() === 'id' ? 'id' : 'en';
}

function setDocumentPaginationLabel(documentRef, label) {
    const docEl = documentRef?.documentElement;
    if (!docEl || !label) return;

    if (typeof docEl.setAttribute === 'function') {
        docEl.setAttribute('data-pagination-label', label);
        if (docEl.dataset && typeof docEl.dataset === 'object' && docEl.dataset.paginationLabel !== label) {
            docEl.dataset.paginationLabel = label;
        }
        return;
    }

    if (docEl.dataset && typeof docEl.dataset === 'object') {
        docEl.dataset.paginationLabel = label;
    }
}

export function applySegmentedButtonState(root, activeLocale, busy = false) {
    if (!root) return;
    const target = normalizeTargetLocale(activeLocale);

    const tracks = [
        ...(root.matches?.('.lang-toggle-track') ? [root] : []),
        ...(root.querySelectorAll?.('.lang-toggle-track') || []),
    ];

    tracks.forEach((track) => {
        if (busy) {
            track.setAttribute('aria-busy', 'true');
        } else {
            track.removeAttribute('aria-busy');
        }
    });

    const buttons = [
        ...(root.matches?.('.lang-pill-btn') ? [root] : []),
        ...(root.querySelectorAll?.('.lang-pill-btn') || []),
    ];

    buttons.forEach((btn) => {
        const rawLocale = btn.dataset?.localeOption || btn.getAttribute?.('value');
        if (!rawLocale) return;
        const btnLocale = normalizeTargetLocale(rawLocale);
        const isActive = btnLocale === target;

        if (isActive) {
            btn.classList.add(...ACTIVE_PILL_CLASSES);
            btn.classList.remove(...INACTIVE_PILL_CLASSES);
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('aria-disabled', 'true');
        } else {
            btn.classList.remove(...ACTIVE_PILL_CLASSES);
            btn.classList.add(...INACTIVE_PILL_CLASSES);
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-disabled', 'false');
        }

        // Keep controls focusable (WCAG 2.2 AA) and allow rapid toggle cancellation
        if (btn.hasAttribute?.('disabled') || btn.disabled) {
            btn.removeAttribute?.('disabled');
            btn.disabled = false;
        }
    });
}

/**
 * Non-destructively morphs translated text nodes and safe accessibility/UI attributes
 * from `serverRoot` into `liveRoot` while preserving user-typed form values,
 * `<input type="file">` FileList objects, Alpine state, and event listeners.
 */
function findDescendantByClass(node, className) {
    if (!node || typeof node !== 'object') return null;
    if (typeof node.querySelector === 'function') {
        const found = node.querySelector(`.${className}`);
        if (found) return found;
    }
    for (const child of Array.from(node.childNodes || [])) {
        if (child?.nodeType !== 1) continue;
        if (child.classList?.contains?.(className)) return child;
        const nested = findDescendantByClass(child, className);
        if (nested) return nested;
    }
    return null;
}

function morphChildNodes(liveParent, serverParent) {
    const liveChildren = Array.from(liveParent.childNodes || []);
    const serverChildren = Array.from(serverParent.childNodes || []);

    let li = 0;
    let si = 0;
    while (li < liveChildren.length && si < serverChildren.length) {
        const liveChild = liveChildren[li];
        const serverChild = serverChildren[si];

        if (
            liveChild.nodeType === serverChild.nodeType
            && (liveChild.nodeType !== 1 || liveChild.tagName === serverChild.tagName)
        ) {
            morphDomTree(liveChild, serverChild);
            li++;
            si++;
            continue;
        }

        // DataTables wraps SSR <table> inside <div class="dt-container">; keep visible column headers translated.
        if (
            liveChild.nodeType === 1
            && serverChild.nodeType === 1
            && serverChild.tagName?.toUpperCase?.() === 'TABLE'
            && liveChild.classList?.contains?.('dt-container')
        ) {
            syncDataTableWrapperHeaders(liveChild, serverChild);
            li++;
            si++;
            continue;
        }

        // Skip unmatched comment or whitespace text nodes on either side
        if (liveChild.nodeType !== 1 && serverChild.nodeType === 1) {
            li++;
            continue;
        }
        if (serverChild.nodeType !== 1 && liveChild.nodeType === 1) {
            si++;
            continue;
        }

        li++;
        si++;
    }
}

function syncDataTableWrapperHeaders(liveWrapper, serverTable) {
    const serverThead = serverTable.querySelector?.('thead');
    if (!serverThead || typeof liveWrapper.querySelectorAll !== 'function') return;

    const tableId = serverTable.getAttribute?.('id');
    const allTables = Array.from(liveWrapper.querySelectorAll('table') || []);
    const matchedTables = tableId
        ? allTables.filter((table) => table.getAttribute?.('id') === tableId)
        : allTables;
    const scrollHeadTables = Array.from(liveWrapper.querySelectorAll('.dt-scroll-headInner table') || []);
    const targetTables = new Set([
        ...(matchedTables.length ? matchedTables : allTables),
        ...scrollHeadTables,
    ]);

    targetTables.forEach((liveTable) => {
        const liveThead = liveTable?.querySelector?.('thead');
        if (liveThead) {
            morphDomTree(liveThead, serverThead);
        }
    });
}

export function morphDomTree(liveRoot, serverRoot) {
    if (!liveRoot || !serverRoot) return;

    if (liveRoot.nodeType === 3 && serverRoot.nodeType === 3) {
        if (liveRoot.nodeValue !== serverRoot.nodeValue) {
            liveRoot.nodeValue = serverRoot.nodeValue;
        }
        return;
    }

    if (liveRoot.nodeType !== 1 || serverRoot.nodeType !== 1) return;
    if (liveRoot.tagName !== serverRoot.tagName) return;
    if (liveRoot.matches?.('[data-locale-ignore], script, style, svg, canvas, iframe')) return;

    // 1. Sync translated attributes from allowlist
    for (const attr of TRANSLATED_ATTRIBUTES) {
        const serverVal = serverRoot.getAttribute?.(attr);
        if (serverVal !== null && serverVal !== undefined) {
            if (liveRoot.getAttribute?.(attr) !== serverVal) {
                liveRoot.setAttribute?.(attr, serverVal);
            }
        }
    }

    if (liveRoot.hasAttribute?.('data-ui-stashed-title') || liveRoot.hasAttribute?.('data-ui-tooltip-stashed')) {
        const nextTooltip = serverRoot.getAttribute?.('data-ui-tooltip') ?? serverRoot.getAttribute?.('title');
        if (nextTooltip) {
            liveRoot.setAttribute?.('data-ui-tooltip', nextTooltip);
            if (liveRoot.hasAttribute?.('data-ui-stashed-title')) {
                liveRoot.setAttribute?.('data-ui-stashed-title', nextTooltip);
            }
        }
    }

    // 2. Preserve live form control states
    const tag = liveRoot.tagName.toUpperCase();
    if (tag === 'INPUT') {
        const type = String(liveRoot.getAttribute?.('type') || liveRoot.type || 'text').toLowerCase();
        if (['submit', 'button', 'reset'].includes(type)) {
            const serverVal = serverRoot.getAttribute?.('value') ?? serverRoot.value;
            if (serverVal !== null && serverVal !== undefined) {
                liveRoot.value = serverVal;
                liveRoot.setAttribute?.('value', serverVal);
            }
        }
        return;
    }

    if (tag === 'TEXTAREA') {
        return;
    }

    if (tag === 'SELECT') {
        const serverOptions = serverRoot.querySelectorAll?.('option') || [];
        const optionTextByValue = new Map();
        serverOptions.forEach((opt) => {
            const val = opt.getAttribute?.('value') ?? opt.value ?? '';
            optionTextByValue.set(String(val), opt.textContent);
        });

        const liveOptions = liveRoot.querySelectorAll?.('option') || [];
        liveOptions.forEach((opt) => {
            const val = String(opt.getAttribute?.('value') ?? opt.value ?? '');
            if (optionTextByValue.has(val)) {
                const nextText = optionTextByValue.get(val);
                if (opt.textContent !== nextText) {
                    opt.textContent = nextText;
                }
            }
        });
        return;
    }

    if (tag === 'TH' || tag === 'TD') {
        const liveTitle = findDescendantByClass(liveRoot, 'dt-column-title');
        const serverTitle = findDescendantByClass(serverRoot, 'dt-column-title');
        if (liveTitle && !serverTitle) {
            if ((liveTitle.childNodes || []).length === 0 && serverRoot.textContent !== undefined) {
                liveTitle.textContent = serverRoot.textContent;
            } else {
                morphChildNodes(liveTitle, serverRoot);
            }
            return;
        }
    }

    // 3. Walk child nodes with resilient alignment
    morphChildNodes(liveRoot, serverRoot);
}

function formatDataTableInfoText(settings, tableApi, lang) {
    const info = tableApi?.page?.info?.();
    if (!info || !lang || typeof lang !== 'object') return null;

    const total = Number(info.recordsTotal ?? 0);
    const display = Number(info.recordsDisplay ?? 0);
    const start = display > 0 ? Number(info.start ?? 0) + 1 : 0;
    const end = Number(info.end ?? 0);
    const fmt = (n) => (typeof settings?.formatNumber === 'function' ? settings.formatNumber(n, settings) : String(n));

    let template = display > 0 ? (lang.info || '') : (lang.infoEmpty || '');
    if (!template) return null;
    if (display !== total && lang.infoFiltered) {
        template = `${template} ${lang.infoFiltered}`.trim();
    }

    return template
        .replace(/_START_/g, fmt(start))
        .replace(/_END_/g, fmt(end))
        .replace(/_TOTAL_/g, fmt(display))
        .replace(/_MAX_/g, fmt(total));
}

function syncDataTableChromeLabels(wrapper, dtLang, documentRef = wrapper?.ownerDocument) {
    if (!wrapper || !dtLang || typeof dtLang !== 'object') return;

    const searchInput = wrapper.querySelector('.dt-search input[type="search"]');
    if (searchInput && dtLang.searchPlaceholder) {
        searchInput.setAttribute('placeholder', dtLang.searchPlaceholder);
    }
    if (searchInput && dtLang.search) {
        searchInput.setAttribute('aria-label', String(dtLang.search).replace(/:$/, '').trim());
    }

    const searchLabel = wrapper.querySelector('.dt-search label');
    if (searchLabel && dtLang.search) {
        const cleanSearch = String(dtLang.search).replace(/_INPUT_/g, '');
        const textNode = Array.from(searchLabel.childNodes || []).find((node) => node.nodeType === 3);
        if (textNode) {
            textNode.nodeValue = cleanSearch;
        } else if (!searchLabel.querySelector?.('input')) {
            searchLabel.textContent = cleanSearch;
        }
    }

    const lengthLabel = wrapper.querySelector('.dt-length label');
    if (lengthLabel && dtLang.lengthMenu && String(dtLang.lengthMenu).includes('_MENU_')) {
        const [prefix, suffix] = String(dtLang.lengthMenu).split('_MENU_');
        const selectEl = lengthLabel.querySelector?.('select');
        const textNodes = Array.from(lengthLabel.childNodes || []).filter((node) => node.nodeType === 3);
        if (selectEl && textNodes.length >= 2) {
            textNodes[0].nodeValue = prefix;
            textNodes[textNodes.length - 1].nodeValue = suffix;
        } else if (!selectEl && textNodes.length > 0) {
            textNodes[0].nodeValue = `${prefix}${suffix}`.trim();
        }
    }

    const paginationBase = documentRef?.documentElement?.dataset?.paginationLabel;
    const pager = wrapper.querySelector('.dt-paging');
    if (pager && paginationBase) {
        const navTarget = pager.matches?.('nav') ? pager : pager.querySelector?.('nav');
        const title = wrapper.closest?.('.ui-data-table')?.querySelector?.('.ui-data-table__header h2')?.textContent?.trim();
        const label = title ? `${paginationBase}: ${title}` : paginationBase;
        if (navTarget) {
            navTarget.setAttribute('aria-label', label);
        } else if (pager.getAttribute?.('role') === 'navigation') {
            pager.setAttribute('aria-label', label);
        }
    }
}

function refreshDataTablesLanguage(windowRef, documentRef, dtLang) {
    const dtCtor = windowRef.DataTable;
    if (!dtCtor || !dtLang || typeof dtLang !== 'object') return;

    if (typeof dtCtor.util?.object?.assignDeepObjects === 'function' && dtCtor.defaults) {
        dtCtor.util.object.assignDeepObjects(dtCtor.defaults, { language: dtLang }, true);
    } else if (dtCtor.defaults) {
        dtCtor.defaults.language = { ...(dtCtor.defaults.language || {}), ...dtLang };
    }

    try {
        const tables = typeof dtCtor.tables === 'function' ? dtCtor.tables({ api: true }) : null;
        if (!tables || typeof tables.settings !== 'function') return;

        const ApiCtor = dtCtor.Api;
        const settingsList = tables.settings();
        for (let i = 0; i < settingsList.length; i++) {
            const settings = settingsList[i];
            if (!settings) continue;

            const targetLang = settings.language;
            if (targetLang) {
                if (typeof dtCtor.util?.object?.assignDeepObjects === 'function') {
                    dtCtor.util.object.assignDeepObjects(targetLang, dtLang, true);
                } else {
                    Object.assign(targetLang, dtLang);
                }
            }

            const tableApi = ApiCtor ? new ApiCtor(settings) : null;
            const wrapper = tableApi?.table?.()?.container?.() || settings.tableWrapper;
            const syncInfoElement = () => {
                if (!wrapper) return;
                const infoEl = wrapper.querySelector('.dt-info');
                const formatted = formatDataTableInfoText(settings, tableApi, settings.language || dtLang);
                if (infoEl && formatted !== null) {
                    infoEl.textContent = formatted;
                }
            };
            const syncLocaleDom = () => {
                if (!wrapper) return;
                syncDataTableChromeLabels(wrapper, settings.language || dtLang, documentRef);
                syncInfoElement();
            };

            if (wrapper) {
                syncLocaleDom();
            }

            // DataTables 3's info and pageLength features cache init-time language strings in closures;
            // re-sync chrome labels and .dt-info after subsequent draws so live locale changes stick across reloads/paging.
            const tableNode = settings.table;
            if (tableNode) {
                offDataTableEvent(tableNode, 'draw.dt.adasiLocaleInfo', undefined, windowRef);
                onDataTableEvent(tableNode, 'draw.dt.adasiLocaleInfo', syncLocaleDom, windowRef);
            }

            if (settings.ajax && typeof tableApi?.ajax?.reload === 'function') {
                tableApi.ajax.reload(null, false);
            } else if (typeof tableApi?.draw === 'function') {
                tableApi.draw('page');
            }
        }
    } catch {
        // Non-fatal if a table is mid-initialization.
    }
}

export function bootLocaleSwitcher(windowRef = globalThis.window, documentRef = globalThis.document) {
    if (!windowRef || !documentRef) return null;

    let activeController = null;
    let activeTargetLocale = null;
    let requestSequence = 0;
    let announceTimer = null;

    function announceLocaleSwitch(resolvedLocale) {
        const languageName = resolvedLocale === 'id' ? 'Bahasa Indonesia' : 'English';
        const translatedMsg = t('js.locale.switched', { language: languageName });
        const message = translatedMsg && translatedMsg !== 'js.locale.switched'
            ? translatedMsg
            : `Language switched to ${languageName}.`;

        if (typeof documentRef.ariaNotify === 'function') {
            try {
                documentRef.ariaNotify(message, { priority: 'normal' });
                return;
            } catch {
                // Fall back to polite live region below.
            }
        }

        if (typeof documentRef.createElement !== 'function' || !documentRef.body?.appendChild) {
            return;
        }

        let announcer = documentRef.getElementById?.('adasi-locale-announcer');
        if (!announcer) {
            announcer = documentRef.createElement('div');
            announcer.id = 'adasi-locale-announcer';
            announcer.className = 'tw:sr-only';
            announcer.setAttribute('role', 'status');
            announcer.setAttribute('aria-live', 'polite');
            announcer.setAttribute('aria-atomic', 'true');
            documentRef.body.appendChild(announcer);
        }

        if (announceTimer) {
            clearTimeout(announceTimer);
            announceTimer = null;
        }
        announcer.textContent = '';
        announceTimer = setTimeout(() => {
            announceTimer = null;
            announcer.textContent = message;
        }, 40);
    }

    function syncLocaleDictionaries({
        i18n = null,
        datatables = null,
        session_copy = null,
        pagination_label = null,
    } = {}) {
        if (i18n) {
            windowRef.AdasiI18n?.setPayload?.(i18n);
        }
        if (session_copy && windowRef.AdasiSession?.copy) {
            Object.assign(windowRef.AdasiSession.copy, session_copy);
        }
        if (pagination_label) {
            setDocumentPaginationLabel(documentRef, pagination_label);
        }
        if (datatables && windowRef.DataTable?.defaults) {
            if (typeof windowRef.DataTable.util?.object?.assignDeepObjects === 'function') {
                windowRef.DataTable.util.object.assignDeepObjects(windowRef.DataTable.defaults, { language: datatables }, true);
            } else {
                windowRef.DataTable.defaults.language = {
                    ...(windowRef.DataTable.defaults.language || {}),
                    ...datatables,
                };
            }
        }
        windowRef.AdasiServerTabs?.invalidateCache?.();
    }

    async function performLocaleSync({
        locale,
        i18n = null,
        datatables = null,
        session_copy = null,
        pagination_label = null,
        shell_html = null,
        workspace_config = null,
        signal = null,
        seq = requestSequence,
        restoreFocusTrigger = null,
        wasDirtyBeforeSwitch = Boolean(windowRef.AdasiUnsaved?.isDirty?.()),
        announce = false,
    }) {
        const resolvedLocale = normalizeTargetLocale(locale);

        // Hot-swap client runtime dictionaries from payload
        syncLocaleDictionaries({ i18n, datatables, session_copy, pagination_label });
        if (documentRef.documentElement) {
            documentRef.documentElement.lang = resolvedLocale;
        }

        // Resolve server-rendered HTML: use inline shell_html (single-RTT) when present, otherwise fetch current page
        const currentUrl = windowRef.location?.href;
        let parsedDoc = null;

        if (shell_html && typeof windowRef.DOMParser === 'function') {
            parsedDoc = new windowRef.DOMParser().parseFromString(shell_html, 'text/html');
        } else if (
            currentUrl
            && isSameOriginUrl(currentUrl, windowRef.location?.origin)
            && typeof windowRef.DOMParser === 'function'
        ) {
            const pageResponse = await windowRef.fetch(currentUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal,
                headers: {
                    Accept: 'text/html, application/xhtml+xml',
                    'X-Adasi-Locale-Refresh': '1',
                },
            });

            if (
                pageResponse?.ok
                && (!pageResponse.url || isSameOriginUrl(pageResponse.url, windowRef.location?.origin))
            ) {
                const htmlText = await pageResponse.text();
                if (seq !== requestSequence) {
                    return { ok: false, locale: resolvedLocale, title: documentRef.title || '', document: null };
                }
                parsedDoc = new windowRef.DOMParser().parseFromString(htmlText, 'text/html');
            }
        }

        if (parsedDoc) {
            if (parsedDoc.title) {
                documentRef.title = parsedDoc.title;
            }
            const nextPaginationLabel = pagination_label
                || parsedDoc.documentElement?.dataset?.paginationLabel
                || parsedDoc.documentElement?.getAttribute?.('data-pagination-label');
            if (nextPaginationLabel) {
                setDocumentPaginationLabel(documentRef, nextPaginationLabel);
            }

            const regions = [
                '.ui-skip-link',
                'nav.top-navbar',
                '#sidebar',
                '#chatDrawer',
                '.workspace-sessionbar',
                '#main-content',
                '.auth-shell',
                '#adasi-smart-panel',
                '#adasiImageLightbox',
            ];

            regions.forEach((selector) => {
                const liveRegion = documentRef.querySelector?.(selector);
                const serverRegion = parsedDoc.querySelector?.(selector);
                if (liveRegion && serverRegion) {
                    morphDomTree(liveRegion, serverRegion);
                }
            });

            windowRef?.AdasiTooltip?.refresh?.(documentRef);

            // Reset notification dropdown summary container if idle/loaded so next open fetches in new locale
            const notifContainer = documentRef.querySelector?.('[data-notification-summary-container]');
            const serverNotifContainer = parsedDoc.querySelector?.('[data-notification-summary-container]');
            if (notifContainer && notifContainer.dataset?.notificationSummaryState === 'loaded') {
                notifContainer.dataset.notificationSummaryState = 'idle';
                if (serverNotifContainer) {
                    notifContainer.textContent = serverNotifContainer.textContent.trim();
                }
            }
        }

        // Refresh DataTables language & reload active AJAX tables
        if (datatables) {
            refreshDataTablesLanguage(windowRef, documentRef, datatables);
        }

        // Keep clean forms clean after <option> label text morphing
        if (!wasDirtyBeforeSwitch) {
            windowRef.AdasiUnsaved?.resetSnapshot?.();
        }

        // Settle segmented button state & optionally restore focus to active language option in same track
        applySegmentedButtonState(documentRef, resolvedLocale, false);

        if (restoreFocusTrigger) {
            const track = restoreFocusTrigger.closest?.('.lang-toggle-track');
            const selector = `.lang-pill-btn[data-locale-option="${resolvedLocale}"], .lang-pill-btn[value="${resolvedLocale}"]`;
            const activeBtn = track?.querySelector?.(selector) || documentRef.querySelector?.(selector);
            activeBtn?.focus?.({ preventScroll: true });
        }

        if (announce) {
            announceLocaleSwitch(resolvedLocale);
        }

        // Notify Alpine & shell components (workspace shell, embeds, wizards, forms)
        const EventCtor = windowRef.CustomEvent || globalThis.CustomEvent;
        if (typeof EventCtor === 'function') {
            windowRef.dispatchEvent?.(
                new EventCtor('adasi:locale-changed', {
                    detail: {
                        locale: resolvedLocale,
                        i18n: i18n || null,
                        datatables: datatables || null,
                        session_copy: session_copy || null,
                        pagination_label: pagination_label || null,
                        workspace_config: workspace_config || null,
                        document: parsedDoc,
                    },
                }),
            );
        }

        return {
            ok: true,
            locale: resolvedLocale,
            title: parsedDoc?.title || documentRef.title || '',
            document: parsedDoc,
        };
    }

    async function syncLocaleInPlace({
        locale,
        i18n = null,
        datatables = null,
        session_copy = null,
        pagination_label = null,
    } = {}) {
        const resolvedLocale = normalizeTargetLocale(
            locale || documentRef.documentElement?.lang || windowRef.AdasiI18n?.locale || 'en',
        );

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
        activeTargetLocale = resolvedLocale;
        const seq = ++requestSequence;

        try {
            return await performLocaleSync({
                locale: resolvedLocale,
                i18n,
                datatables,
                session_copy,
                pagination_label,
                signal: controller.signal,
                seq,
            });
        } catch (error) {
            if (error?.name === 'AbortError') {
                return { ok: false, aborted: true, locale: resolvedLocale, title: documentRef.title || '', document: null };
            }
            return { ok: false, error, locale: resolvedLocale, title: documentRef.title || '', document: null };
        } finally {
            if (activeController === controller) {
                activeController = null;
                activeTargetLocale = null;
            }
        }
    }

    async function switchLocale(targetLocale, { actionUrl = null, triggerElement = null } = {}) {
        const nextLocale = normalizeTargetLocale(targetLocale);
        const currentLocale = normalizeTargetLocale(
            documentRef.documentElement?.lang || windowRef.AdasiI18n?.locale || 'en',
        );

        if (activeController && activeTargetLocale === nextLocale) {
            return true;
        }

        if (nextLocale === currentLocale && !activeController) {
            applySegmentedButtonState(documentRef, nextLocale, false);
            return true;
        }

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
        activeTargetLocale = nextLocale;
        const seq = ++requestSequence;

        // 1. Optimistic UI state
        applySegmentedButtonState(documentRef, nextLocale, true);

        const form = documentRef.querySelector?.('form[data-locale-switcher]');
        const endpoint = actionUrl
            || form?.getAttribute?.('action')
            || `${windowRef.location?.origin || ''}${windowRef.AdasiPortal?.basePath || ''}/locale`;
        const csrfToken = form?.querySelector?.('input[name="_token"]')?.value
            || documentRef.querySelector?.('meta[name="csrf-token"]')?.content
            || '';

        const isWorkspaceShell = documentRef.documentElement?.dataset?.workspaceShell === 'true'
            || documentRef.documentElement?.getAttribute?.('data-workspace-shell') === 'true';

        const wasDirtyBeforeSwitch = Boolean(windowRef.AdasiUnsaved?.isDirty?.());

        try {
            // 2. Server-side locale mutation & dictionary generation (+ single-RTT shell chrome when applicable)
            const postResponse = await windowRef.fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({
                    locale: nextLocale,
                    return_to: `${windowRef.location?.pathname || '/'}${windowRef.location?.search || ''}`,
                    ...(isWorkspaceShell ? { include_shell: true } : {}),
                }),
            });

            if (!postResponse || !postResponse.ok) {
                const err = new Error(`Locale switch failed with HTTP ${postResponse?.status || 'unknown'}`);
                err.status = postResponse?.status || 0;
                throw err;
            }

            const payload = await postResponse.json();
            if (seq !== requestSequence) return false;

            const result = await performLocaleSync({
                locale: payload?.locale || nextLocale,
                i18n: payload?.i18n || null,
                datatables: payload?.datatables || null,
                session_copy: payload?.session_copy || null,
                pagination_label: payload?.pagination_label || null,
                shell_html: payload?.shell_html || null,
                workspace_config: payload?.workspace_config || null,
                signal: controller.signal,
                seq,
                restoreFocusTrigger: triggerElement,
                wasDirtyBeforeSwitch,
                announce: true,
            });

            return Boolean(result?.ok);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return false;
            }
            if (seq === requestSequence) {
                applySegmentedButtonState(documentRef, currentLocale, false);
                const isAuthFailure = error?.status === 401 || error?.status === 419;
                if (!isAuthFailure || !windowRef.AdasiSessionSync) {
                    const dedicatedMsg = t('js.locale.switch_failed');
                    const toastMsg = dedicatedMsg && dedicatedMsg !== 'js.locale.switch_failed'
                        ? dedicatedMsg
                        : t('js.async_form.failed');
                    windowRef.AdasiToast?.error?.(toastMsg);
                }
            }
            return false;
        } finally {
            if (activeController === controller) {
                activeController = null;
                activeTargetLocale = null;
            }
        }
    }

    documentRef.addEventListener?.('submit', (event) => {
        const form = event.target;
        if (!form || typeof form.matches !== 'function' || !form.matches('form[data-locale-switcher]')) {
            return;
        }
        if (typeof windowRef.fetch !== 'function') return;

        event.preventDefault();
        const submitter = event.submitter
            || form.querySelector?.('button[name="locale"][aria-pressed="false"], button[name="locale"]:not([aria-disabled="true"]):not([disabled])');
        const targetLocale = submitter?.value || submitter?.dataset?.localeOption;
        if (!targetLocale) return;

        switchLocale(targetLocale, {
            actionUrl: form.getAttribute('action'),
            triggerElement: submitter,
        });
    });

    const api = Object.freeze({
        switchLocale,
        syncLocaleInPlace,
        syncLocaleDictionaries,
        applySegmentedButtonState,
        morphDomTree,
    });

    windowRef.AdasiLocaleSwitcher = api;
    return api;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    bootLocaleSwitcher(window, document);
}

