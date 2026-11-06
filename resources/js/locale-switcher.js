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

    // 3. Walk child nodes with resilient alignment
    const liveChildren = Array.from(liveRoot.childNodes || []);
    const serverChildren = Array.from(serverRoot.childNodes || []);

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

function refreshDataTablesLanguage(windowRef, documentRef, dtLang) {
    const jq = windowRef.jQuery;
    if (!jq?.fn?.dataTable || !dtLang || typeof dtLang !== 'object') return;

    jq.extend(true, jq.fn.dataTable.defaults, { language: dtLang });

    try {
        const tables = jq.fn.dataTable.tables({ api: true });
        if (!tables || typeof tables.settings !== 'function') return;

        const settingsList = tables.settings();
        for (let i = 0; i < settingsList.length; i++) {
            const settings = settingsList[i];
            if (!settings) continue;

            if (settings.oLanguage) {
                jq.extend(true, settings.oLanguage, dtLang);
            }

            const wrapper = settings.nTableWrapper;
            if (wrapper) {
                const searchInput = wrapper.querySelector('.dataTables_filter input[type="search"]');
                if (searchInput && dtLang.searchPlaceholder) {
                    searchInput.setAttribute('placeholder', dtLang.searchPlaceholder);
                }
                if (searchInput && dtLang.search) {
                    searchInput.setAttribute('aria-label', String(dtLang.search).replace(/:$/, '').trim());
                }
            }

            const tableApi = new jq.fn.dataTable.Api(settings);
            if (settings.ajax && typeof tableApi.ajax?.reload === 'function') {
                tableApi.ajax.reload(null, false);
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
            || `${windowRef.location?.origin || ''}/locale`;
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

            const resolvedLocale = normalizeTargetLocale(payload?.locale || nextLocale);

            // 3. Hot-swap client runtime dictionaries from server payload
            if (payload?.i18n) {
                windowRef.AdasiI18n?.setPayload?.(payload.i18n);
            }
            if (payload?.session_copy && windowRef.AdasiSession?.copy) {
                Object.assign(windowRef.AdasiSession.copy, payload.session_copy);
            }
            if (documentRef.documentElement) {
                documentRef.documentElement.lang = resolvedLocale;
            }
            windowRef.AdasiServerTabs?.invalidateCache?.();

            // 4. Fetch server-rendered HTML of current page & non-destructively morph DOM
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
                    signal: controller.signal,
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
                    if (seq !== requestSequence) return false;
                    parsedDoc = new windowRef.DOMParser().parseFromString(htmlText, 'text/html');

                    if (parsedDoc?.title) {
                        documentRef.title = parsedDoc.title;
                    }

                    const regions = [
                        '.ui-skip-link',
                        'nav.top-navbar',
                        '#sidebar',
                        '#chatDrawer',
                        '#main-content',
                        '.auth-shell',
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

            // 5. Refresh DataTables language & reload active AJAX tables
            if (payload?.datatables) {
                refreshDataTablesLanguage(windowRef, documentRef, payload.datatables);
            }

            // 6. Keep clean forms clean after <option> label text morphing
            if (!wasDirtyBeforeSwitch) {
                windowRef.AdasiUnsaved?.resetSnapshot?.();
            }

            // 7. Settle segmented button state & restore focus if triggered via keyboard/click
            applySegmentedButtonState(documentRef, resolvedLocale, false);

            if (triggerElement) {
                const activeBtn = documentRef.querySelector?.(
                    `.lang-pill-btn[data-locale-option="${resolvedLocale}"], .lang-pill-btn[value="${resolvedLocale}"]`,
                );
                activeBtn?.focus?.({ preventScroll: true });
            }

            // 8. Notify Alpine components (registration wizard, customization form, etc.)
            const EventCtor = windowRef.CustomEvent || globalThis.CustomEvent;
            if (typeof EventCtor === 'function') {
                windowRef.dispatchEvent?.(
                    new EventCtor('adasi:locale-changed', {
                        detail: {
                            locale: resolvedLocale,
                            i18n: payload?.i18n || null,
                            document: parsedDoc,
                        },
                    }),
                );
            }

            return true;
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
        applySegmentedButtonState,
        morphDomTree,
    });

    windowRef.AdasiLocaleSwitcher = api;
    return api;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    bootLocaleSwitcher(window, document);
}

