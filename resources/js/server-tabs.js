/**
 * AdasiServerTabs — Reusable server-side AJAX tab/filter/pagination controller.
 *
 * Intercepts tab link clicks, filter form submissions, and pagination clicks
 * inside a designated container. Fetches rendered HTML fragments from the server,
 * swaps the table container content, updates metric badges and tab active states,
 * and synchronizes the browser URL via the History API.
 *
 * Features:
 * - Optimistic tab active-state (instant visual feedback on click)
 * - In-memory fragment cache (0ms on revisit, 5-minute TTL)
 * - Speculative hover/touch prefetch (60ms debounce)
 * - Cache invalidation on form submit and reset
 *
 * Usage (in Blade @push('scripts')):
 *   AdasiServerTabs.init('#drpPaidContainer', {
 *       onUpdated: () => { /* re-bind checkboxes, etc. *\/ },
 *   });
 */

const instances = new Map();

// ─── In-Memory Fragment Cache ─────────────────────────────────────────────
const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const fragmentCache = new Map(); // url → { html, tab, metrics, data, timestamp }

function cacheGet(url) {
    const entry = fragmentCache.get(url);
    if (!entry) return null;
    if (Date.now() - entry.timestamp > CACHE_TTL_MS) {
        fragmentCache.delete(url);
        return null;
    }
    return entry;
}

function cacheSet(url, data) {
    fragmentCache.set(url, {
        html: data.html,
        tab: data.tab,
        metrics: data.metrics,
        openOverpaymentsCount: data.openOverpaymentsCount,
        url: data.url,
        data,
        timestamp: Date.now(),
    });
}

function cacheInvalidateForTab(tabName) {
    for (const [url, entry] of fragmentCache) {
        if (entry.tab === tabName) {
            fragmentCache.delete(url);
        }
    }
}

function cacheInvalidateAll() {
    fragmentCache.clear();
}

// ─── Prefetch Tracking ───────────────────────────────────────────────────
const prefetchInFlight = new Set(); // URLs currently being prefetched

/**
 * @param {string} containerSelector — CSS selector for the outermost wrapper.
 * @param {Object} [options]
 * @param {Function} [options.onUpdated] — called after DOM swap completes.
 */
function init(containerSelector, options = {}) {
    const container = document.querySelector(containerSelector);
    if (!container) {
        console.warn(`[AdasiServerTabs] Container not found: ${containerSelector}`);
        return;
    }

    // Prevent double-init on the same container, but merge any new options
    if (instances.has(containerSelector)) {
        const existing = instances.get(containerSelector);
        if (options && Object.keys(options).length > 0) {
            existing.options = Object.assign(existing.options || {}, options);
        }
        return existing;
    }

    const state = {
        container,
        abortController: null,
        options,
        previousTab: null, // For optimistic rollback
    };

    instances.set(containerSelector, state);

    // --- Tab click delegation ---
    container.addEventListener('click', (e) => {
        const tabLink = e.target.closest('[data-server-tab]');
        if (tabLink) {
            e.preventDefault();
            const url = tabLink.getAttribute('href') || tabLink.dataset.serverTab;
            if (url) {
                // Optimistic: record previous tab and immediately update visual state
                const currentActive = container.querySelector('[data-server-tab][aria-current="page"]');
                state.previousTab = currentActive?.dataset.tabName || null;
                const targetTab = tabLink.dataset.tabName;
                if (targetTab) {
                    updateTabActiveStates(container, targetTab);
                }
                fetchAndSwap(state, url, true);
            }
            return;
        }

        // --- Pagination link delegation ---
        const pageLink = e.target.closest('.pagination a');
        if (pageLink) {
            e.preventDefault();
            const url = pageLink.getAttribute('href');
            if (url) fetchAndSwap(state, url, true);
        }
    });

    // --- Filter form submission interception (delegated) ---
    container.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-server-tabs-form]');
        if (form && !form.hasAttribute('data-managed-submit')) {
            e.preventDefault();
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            const actionUrl = form.getAttribute('action') || window.location.pathname;
            const sep = actionUrl.includes('?') ? '&' : '?';
            const queryString = params.toString();
            const url = queryString ? `${actionUrl}${sep}${queryString}` : actionUrl;

            // Invalidate cache for the current tab on filter submission
            const hiddenTab = form.querySelector('input[name="tab"]');
            if (hiddenTab && hiddenTab.value) {
                cacheInvalidateForTab(hiddenTab.value);
            } else {
                // If no tab input, invalidate all cache for safety
                cacheInvalidateAll();
            }

            fetchAndSwap(state, url, true);
        }
    });

    // --- Invalidate tab cache on date range commit ---
    container.addEventListener('adasi:date-range-commit', () => {
        const activeTab = container.querySelector('[data-server-tab][aria-current="page"]')?.dataset.tabName;
        if (activeTab) {
            cacheInvalidateForTab(activeTab);
        }
    });

    // --- Speculative hover/touch prefetch ---
    let prefetchTimer = null;
    container.addEventListener('pointerenter', (e) => {
        const tabLink = e.target.closest('[data-server-tab]');
        if (!tabLink) return;

        const url = tabLink.getAttribute('href') || tabLink.dataset.serverTab;
        if (!url) return;

        // Don't prefetch if already cached or already fetching
        if (cacheGet(url) || prefetchInFlight.has(url)) return;

        // 60ms debounce to avoid prefetching on accidental hover-through
        clearTimeout(prefetchTimer);
        prefetchTimer = setTimeout(() => {
            speculativePrefetch(url);
        }, 60);
    }, true);

    container.addEventListener('pointerleave', (e) => {
        const tabLink = e.target.closest('[data-server-tab]');
        if (tabLink) {
            clearTimeout(prefetchTimer);
        }
    }, true);

    // --- Browser back/forward (popstate) ---
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state._serverTabs === containerSelector) {
            fetchAndSwap(state, window.location.href, false);
        }
    });

    // Push initial state so popstate can work for the very first page
    if (!window.history.state || !window.history.state._serverTabs) {
        window.history.replaceState(
            { _serverTabs: containerSelector, url: window.location.href },
            '',
            window.location.href,
        );
    }
}

/**
 * Speculative prefetch: fetch a tab's content in the background and cache it.
 * Does not touch the DOM. Silent on error.
 *
 * @param {string} url
 */
async function speculativePrefetch(url) {
    if (prefetchInFlight.has(url) || cacheGet(url)) return;

    prefetchInFlight.add(url);
    try {
        const response = await fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        if (!response.ok) return;

        const data = await response.json();
        cacheSet(url, data);
    } catch {
        // Silently discard prefetch errors — they don't affect UX
    } finally {
        prefetchInFlight.delete(url);
    }
}

/**
 * Core: fetch the AJAX JSON response from the server and swap DOM content.
 * If a valid cached fragment exists, uses it instantly (0ms swap).
 *
 * @param {Object} state
 * @param {string} url
 * @param {boolean} pushHistory — true for user-initiated nav, false for popstate
 */
async function fetchAndSwap(state, url, pushHistory) {
    const { container, options } = state;
    const tableTarget = container.querySelector('[data-server-tabs-content]');
    if (!tableTarget) {
        console.warn('[AdasiServerTabs] Missing [data-server-tabs-content] inside container.');
        return;
    }

    // Cancel any in-flight request (handles rapid tab clicking)
    if (state.abortController) {
        state.abortController.abort();
    }
    state.abortController = new AbortController();

    // Close any active calendar popover before swapping DOM content
    if (window.AdasiCalendar?.closeActive) {
        window.AdasiCalendar.closeActive();
    }

    // ── Cache Hit: instant swap without network ──
    const cached = cacheGet(url);
    if (cached) {
        applyData(state, container, tableTarget, cached.data, url, pushHistory, options);
        state.abortController = null;
        return;
    }

    // ── Cache Miss: show loading state and fetch ──
    tableTarget.setAttribute('aria-busy', 'true');
    tableTarget.classList.add('tw-opacity-50');
    tableTarget.style.pointerEvents = 'none';
    showLoadingOverlay(tableTarget);

    try {
        const response = await fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            signal: state.abortController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        // Store in cache for future revisits
        cacheSet(url, data);

        applyData(state, container, tableTarget, data, url, pushHistory, options);
    } catch (err) {
        if (err.name === 'AbortError') return; // Intentional cancellation
        console.error('[AdasiServerTabs] Fetch failed:', err);

        // Rollback optimistic tab state on error
        if (state.previousTab) {
            updateTabActiveStates(container, state.previousTab);
            state.previousTab = null;
        }

        // Fallback: navigate normally
        window.location.href = url;
    } finally {
        tableTarget.removeAttribute('aria-busy');
        tableTarget.classList.remove('tw-opacity-50');
        tableTarget.style.pointerEvents = '';
        hideLoadingOverlay(tableTarget);
        state.abortController = null;
    }
}

/**
 * Apply fetched or cached data to the DOM.
 * Extracted so both cache-hit and network paths use the same logic.
 */
function applyData(state, container, tableTarget, data, url, pushHistory, options) {
    // 0. Dispatch before-swap event for cleanup (Chart.js, DataTables, etc.)
    container.dispatchEvent(new CustomEvent('adasi:server-tabs:before-swap', {
        bubbles: true,
        detail: {
            currentTab: container.querySelector('[data-server-tab][aria-current="page"]')?.dataset.tabName,
            nextTab: data.tab,
        },
    }));

    // 1. Swap table HTML
    if (data.html) {
        tableTarget.innerHTML = data.html;
    }

    // 2. Update tab active states (may already be set optimistically, but ensure consistency)
    if (data.tab) {
        updateTabActiveStates(container, data.tab);
    }

    // 3. Update metric badges
    if (data.metrics) {
        updateMetrics(container, data.metrics);
    }

    // 4. Update overpayment badge
    if (data.openOverpaymentsCount !== undefined) {
        updateOverpaymentBadge(container, data.openOverpaymentsCount);
    }

    // 5. Update hidden tab input in filter form
    if (data.tab) {
        const hiddenTab = container.querySelector('[data-server-tabs-form] input[name="tab"]');
        if (hiddenTab) hiddenTab.value = data.tab;
    }

    // 6. Sync URL
    if (pushHistory) {
        const resolvedUrl = data.url || url;
        window.history.pushState(
            { _serverTabs: getContainerSelector(state), url: resolvedUrl },
            '',
            resolvedUrl,
        );
    }

    // 7. Re-init Bootstrap tooltips inside swapped content
    reinitTooltips(tableTarget);

    // 7b. Re-init Adasi Calendars inside swapped content
    if (window.AdasiCalendar?.initialize) {
        window.AdasiCalendar.initialize(tableTarget);
    }

    // 8. Dispatch custom event for page-specific re-binding
    container.dispatchEvent(new CustomEvent('adasi:server-tabs:updated', {
        bubbles: true,
        detail: { tab: data.tab, metrics: data.metrics, data },
    }));

    // 9. Callback
    if (typeof options.onUpdated === 'function') {
        options.onUpdated(data);
    }

    // Clear optimistic rollback state on success
    state.previousTab = null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────

function getContainerSelector(state) {
    for (const [selector, s] of instances) {
        if (s === state) return selector;
    }
    return '';
}

function updateTabActiveStates(container, activeTab) {
    // 1. Update Segmented Pill Tabs (exclude metric cards)
    const pillTabs = container.querySelectorAll('[data-server-tab]:not([data-server-tab-card])');
    const pillActiveClasses = ['tw-bg-primary', 'tw-text-primary-foreground', 'tw-shadow-xs'];
    const pillInactiveClasses = ['tw-text-on-surface-variant'];
    const pillHoverClasses = ['hover:tw-bg-surface', 'hover:tw-text-on-surface'];
    const activeBadgeClasses = ['tw-bg-white/20', 'tw-text-white'];
    const inactiveBadgeClasses = ['tw-bg-surface', 'tw-text-on-surface-variant'];

    pillTabs.forEach((tab) => {
        const tabName = tab.dataset.tabName;
        const badge = tab.querySelector('.tw-rounded-full');
        const isActive = tabName === activeTab;

        if (isActive) {
            tab.setAttribute('aria-current', 'page');
            tab.classList.add(...pillActiveClasses);
            tab.classList.remove(...pillInactiveClasses, ...pillHoverClasses);
            if (badge) {
                badge.classList.add(...activeBadgeClasses);
                badge.classList.remove(...inactiveBadgeClasses);
            }
        } else {
            tab.removeAttribute('aria-current');
            tab.classList.remove(...pillActiveClasses);
            tab.classList.add(...pillInactiveClasses, ...pillHoverClasses);
            if (badge) {
                badge.classList.remove(...activeBadgeClasses);
                badge.classList.add(...inactiveBadgeClasses);
            }
        }
    });

    // 2. Update Metric Cards
    const cardTabs = container.querySelectorAll('[data-server-tab-card]');
    const cardActiveClasses = ['tw-border-primary', 'tw-ring-2', 'tw-ring-primary'];
    const cardInactiveClasses = ['tw-border-outline'];

    cardTabs.forEach((card) => {
        const tabName = card.dataset.tabName;
        const isActive = tabName === activeTab;

        if (isActive) {
            card.classList.add(...cardActiveClasses);
            card.classList.remove(...cardInactiveClasses);
        } else {
            card.classList.remove(...cardActiveClasses);
            card.classList.add(...cardInactiveClasses);
        }
    });
}

function updateMetrics(container, metrics) {
    // Update tab count badges
    const badgeMap = {
        unpaid_count: '[data-tab-count="unpaid"]',
        paid_count: '[data-tab-count="paid"]',
        total_batches: '[data-tab-count="all"]',
    };

    for (const [key, selector] of Object.entries(badgeMap)) {
        if (metrics[key] !== undefined) {
            const badges = container.querySelectorAll(selector);
            badges.forEach((badge) => {
                badge.textContent = Number(metrics[key]).toLocaleString('id-ID');
            });
        }
    }

    // Update metric card values and meta descriptions
    const metricCardMap = {
        total_batches: '[data-metric="total_batches"]',
        unpaid_amount: '[data-metric="unpaid_amount"]',
        unpaid_count: '[data-metric="unpaid_count"]',
        paid_amount: '[data-metric="paid_amount"]',
        paid_count: '[data-metric="paid_count"]',
    };

    for (const [key, selector] of Object.entries(metricCardMap)) {
        if (metrics[key] !== undefined) {
            const elements = container.querySelectorAll(selector);
            elements.forEach((el) => {
                if (key.endsWith('_amount')) {
                    el.textContent = `Rp ${Number(metrics[key]).toLocaleString('id-ID')}`;
                } else if (key === 'total_batches') {
                    el.textContent = Number(metrics[key]).toLocaleString('id-ID');
                } else {
                    // count metrics used as meta descriptions
                    el.textContent = `${Number(metrics[key]).toLocaleString('id-ID')} Batch ${key === 'unpaid_count' ? 'belum lunas' : 'selesai dibayar'}`;
                }
            });
        }
    }
}

function updateOverpaymentBadge(container, count) {
    const badge = container.querySelector('[data-overpayment-count]');
    if (!badge) return;

    badge.textContent = count;
    if (count > 0) {
        badge.classList.remove('tw-hidden');
    } else {
        badge.classList.add('tw-hidden');
    }
}

function showLoadingOverlay(target) {
    let overlay = target.querySelector('.server-tabs-loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'server-tabs-loading-overlay tw-absolute tw-inset-0 tw-flex tw-items-center tw-justify-center tw-z-10';
        overlay.innerHTML = `
            <div class="tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-lg tw-bg-surface tw-shadow-md tw-border tw-border-outline-variant">
                <div class="tw-animate-spin tw-w-4 tw-h-4 tw-border-2 tw-border-primary tw-border-t-transparent tw-rounded-full"></div>
                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">Memuat data...</span>
            </div>
        `;
        target.style.position = 'relative';
        target.appendChild(overlay);
    }
    overlay.style.display = '';
}

function hideLoadingOverlay(target) {
    const overlay = target.querySelector('.server-tabs-loading-overlay');
    if (overlay) overlay.style.display = 'none';
}

function reinitTooltips(container) {
    const Tooltip = window.bootstrap?.Tooltip;
    if (!Tooltip) return;

    container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        Tooltip.getOrCreateInstance(el);
    });
}

export function bootServerTabs() {
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-server-tabs-container]').forEach((container) => {
        const selector = container.id ? `#${container.id}` : null;
        if (selector) {
            init(selector);
        }
    });
}

// Auto-boot on DOM ready or immediately if document is already interactive/complete
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootServerTabs);
    } else {
        bootServerTabs();
    }
}

// ─── Public API ───────────────────────────────────────────────────────────

window.AdasiServerTabs = Object.freeze({
    init,
    boot: bootServerTabs,
    invalidateCache: cacheInvalidateAll,
    invalidateCacheForTab: cacheInvalidateForTab,
});
