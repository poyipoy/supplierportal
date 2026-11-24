import { t } from './i18n.js';
import { isSameOriginUrl } from './async-form-submit.js';

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
    'data-bs-title',
    'data-bs-original-title',
    'data-sidebar-tooltip',
    'data-loading-text',
    'alt',
];

export function normalizeTargetLocale(value) {
    return String(value || '').trim().toLowerCase() === 'id' ? 'id' : 'en';
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
        } else {
            btn.classList.remove(...ACTIVE_PILL_CLASSES);
            btn.classList.add(...INACTIVE_PILL_CLASSES);
            btn.setAttribute('aria-pressed', 'false');
        }

        if (busy || isActive) {
            btn.setAttribute('disabled', 'disabled');
            btn.disabled = true;
        } else {
            btn.removeAttribute('disabled');
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

    if (liveRoot.hasAttribute?.('data-bs-original-title')) {
        const nextTooltip = serverRoot.getAttribute?.('data-bs-title') ?? serverRoot.getAttribute?.('title');
        if (nextTooltip) {
            liveRoot.setAttribute?.('data-bs-original-title', nextTooltip);
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
    const jq = windowRef.jQuery;
    const dtCtor = windowRef.DataTable || jq?.fn?.dataTable;
    if (!dtCtor || !dtLang || typeof dtLang !== 'object') return;

    if (jq?.extend) {
        jq.extend(true, dtCtor.defaults, { language: dtLang });
    } else if (dtCtor.defaults) {
        dtCtor.defaults.language = { ...(dtCtor.defaults.language || {}), ...dtLang };
    }

    try {
        const tables = typeof dtCtor.tables === 'function' ? dtCtor.tables({ api: true }) : null;
        if (!tables || typeof tables.settings !== 'function') return;

        const ApiCtor = dtCtor.Api || jq?.fn?.dataTable?.Api;
        const settingsList = tables.settings();
        for (let i = 0; i < settingsList.length; i++) {
            const settings = settingsList[i];
            if (!settings) continue;

            const targetLang = settings.language;
            if (targetLang) {
                if (jq?.extend) {
                    jq.extend(true, targetLang, dtLang);
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
            if (jq && tableNode) {
                jq(tableNode).off('draw.dt.adasiLocaleInfo').on('draw.dt.adasiLocaleInfo', syncLocaleDom);
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
    let requestSequence = 0;

    async function performLocaleSync({
        locale,
        i18n = null,
        datatables = null,
        session_copy = null,
        signal = null,
        seq = requestSequence,
        restoreFocusTrigger = null,
        wasDirtyBeforeSwitch = Boolean(windowRef.AdasiUnsaved?.isDirty?.()),
    }) {
        const resolvedLocale = normalizeTargetLocale(locale);

        // Hot-swap client runtime dictionaries from payload
        if (i18n) {
            windowRef.AdasiI18n?.setPayload?.(i18n);
        }
        if (session_copy && windowRef.AdasiSession?.copy) {
            Object.assign(windowRef.AdasiSession.copy, session_copy);
        }
        if (documentRef.documentElement) {
            documentRef.documentElement.lang = resolvedLocale;
        }
        windowRef.AdasiServerTabs?.invalidateCache?.();

        // Fetch server-rendered HTML of current page & non-destructively morph DOM
        const currentUrl = windowRef.location?.href;
        let parsedDoc = null;

        if (
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

                if (parsedDoc?.title) {
                    documentRef.title = parsedDoc.title;
                }
                const nextPaginationLabel = parsedDoc?.documentElement?.dataset?.paginationLabel;
                if (nextPaginationLabel && documentRef.documentElement) {
                    documentRef.documentElement.dataset = documentRef.documentElement.dataset || {};
                    documentRef.documentElement.dataset.paginationLabel = nextPaginationLabel;
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
        }

        // Refresh DataTables language & reload active AJAX tables
        if (datatables) {
            refreshDataTablesLanguage(windowRef, documentRef, datatables);
        }

        // Keep clean forms clean after <option> label text morphing
        if (!wasDirtyBeforeSwitch) {
            windowRef.AdasiUnsaved?.resetSnapshot?.();
        }

        // Settle segmented button state & optionally restore focus to active language option
        applySegmentedButtonState(documentRef, resolvedLocale, false);

        if (restoreFocusTrigger) {
            const activeBtn = documentRef.querySelector?.(
                `.lang-pill-btn[data-locale-option="${resolvedLocale}"], .lang-pill-btn[value="${resolvedLocale}"]`,
            );
            activeBtn?.focus?.({ preventScroll: true });
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
    } = {}) {
        const resolvedLocale = normalizeTargetLocale(
            locale || documentRef.documentElement?.lang || windowRef.AdasiI18n?.locale || 'en',
        );

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
        const seq = ++requestSequence;

        try {
            return await performLocaleSync({
                locale: resolvedLocale,
                i18n,
                datatables,
                session_copy,
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
            }
        }
    }

    async function switchLocale(targetLocale, { actionUrl = null, triggerElement = null } = {}) {
        const nextLocale = normalizeTargetLocale(targetLocale);
        const currentLocale = normalizeTargetLocale(
            documentRef.documentElement?.lang || windowRef.AdasiI18n?.locale || 'en',
        );

        if (nextLocale === currentLocale && !activeController) {
            applySegmentedButtonState(documentRef, nextLocale, false);
            return true;
        }

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
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

        const wasDirtyBeforeSwitch = Boolean(windowRef.AdasiUnsaved?.isDirty?.());

        try {
            // 2. Server-side locale mutation & dictionary generation
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
                }),
            });

            if (!postResponse || !postResponse.ok) {
                throw new Error(`Locale switch failed with HTTP ${postResponse?.status || 'unknown'}`);
            }

            const payload = await postResponse.json();
            if (seq !== requestSequence) return false;

            const result = await performLocaleSync({
                locale: payload?.locale || nextLocale,
                i18n: payload?.i18n || null,
                datatables: payload?.datatables || null,
                session_copy: payload?.session_copy || null,
                signal: controller.signal,
                seq,
                restoreFocusTrigger: triggerElement,
                wasDirtyBeforeSwitch,
            });

            return Boolean(result?.ok);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return false;
            }
            if (seq === requestSequence) {
                applySegmentedButtonState(documentRef, currentLocale, false);
                windowRef.AdasiToast?.error?.(t('js.async_form.failed'));
            }
            return false;
        } finally {
            if (activeController === controller) {
                activeController = null;
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
        const submitter = event.submitter || form.querySelector?.('button[name="locale"]:not([disabled])');
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
        applySegmentedButtonState,
        morphDomTree,
    });

    windowRef.AdasiLocaleSwitcher = api;
    return api;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    bootLocaleSwitcher(window, document);
}

