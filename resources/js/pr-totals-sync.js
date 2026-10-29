/**
 * Server-backed PR form totals synchronization (PUR-02 / A1).
 *
 * All per-row weight calculations, row Total KG, and grand totals (Total Qty, Total KG)
 * are computed by POST /purchasing/material-calculations/totals without saving PR data.
 */

const DIMENSION_FIELDS = ['thickness', 'width', 'length', 'd_inner', 'd_outer'];

export function serializePrRow(row, index = 0) {
    const val = (selector) => {
        const el = row?.querySelector?.(selector);

        return el && typeof el.value === 'string' ? el.value.trim() : '';
    };

    const item = {
        index,
        material_master_id: val('.material-master-id'),
        shape: val('.material-shape-select'),
        quantity: val('.material-quantity'),
        weight_needed: val('.weight-unit-display'),
        weight_manual_override: val('.weight-manual-override') || '0',
        hs_code: val('.hs-code-display'),
        hs_code_manual_override: val('.hs-code-manual-override') || '0',
    };

    for (const field of DIMENSION_FIELDS) {
        const dimEl = row?.querySelector?.(`.dimension-input[data-dimension-field="${field}"]`);
        item[field] = dimEl && !dimEl.disabled && typeof dimEl.value === 'string' ? dimEl.value.trim() : '';
    }

    return item;
}

export function collectPrItemsPayload(tbody) {
    if (!tbody || typeof tbody.querySelectorAll !== 'function') {
        return [];
    }

    return Array.from(tbody.querySelectorAll('tr')).map((row, index) => serializePrRow(row, index));
}

export function applyPrTotalsResponse({ tbody, qtyOutput, kgOutput, statusEl, activeElement = null }, payload) {
    const rows = Array.isArray(payload?.rows) ? payload.rows : [];
    const domRows = tbody && typeof tbody.querySelectorAll === 'function' ? Array.from(tbody.querySelectorAll('tr')) : [];

    rows.forEach((rowResult, idx) => {
        const targetIndex = Number.isInteger(rowResult?.index) ? rowResult.index : idx;
        const tr = domRows[targetIndex];
        if (!tr) return;

        const totalCell = tr.querySelector?.('[data-pr-row-total-kg]');
        if (totalCell) {
            const nextText = rowResult?.counted_in_kg && typeof rowResult.row_total_kg_display === 'string' && rowResult.row_total_kg_display !== ''
                ? rowResult.row_total_kg_display
                : '—';
            if (totalCell.textContent !== nextText) {
                totalCell.textContent = nextText;
            }
        }

        const weightInput = tr.querySelector?.('.weight-unit-display');
        const manualInput = tr.querySelector?.('.weight-manual-override');
        if (
            weightInput
            && weightInput !== activeElement
            && manualInput?.value !== '1'
            && typeof rowResult?.unit_kg === 'string'
            && rowResult.unit_kg !== ''
            && weightInput.value !== rowResult.unit_kg
        ) {
            weightInput.value = rowResult.unit_kg;
        }
    });

    const totals = payload?.totals || {};
    if (qtyOutput && typeof totals.total_quantity_display === 'string') {
        if (qtyOutput.textContent !== totals.total_quantity_display) {
            qtyOutput.textContent = totals.total_quantity_display;
        }
    }
    if (kgOutput && typeof totals.total_kg_display === 'string') {
        if (kgOutput.textContent !== totals.total_kg_display) {
            kgOutput.textContent = totals.total_kg_display;
        }
    }

    if (statusEl) {
        const defaultText = statusEl.dataset?.defaultText || '';
        if (defaultText && statusEl.textContent !== defaultText) {
            statusEl.textContent = defaultText;
        }
        statusEl.classList?.remove?.('text-danger');
        statusEl.removeAttribute?.('data-sync-error');
    }
}

export function createPrTotalsSyncController({
    tbody,
    tfoot,
    qtyOutput,
    kgOutput,
    statusEl = null,
    fetchImpl = globalThis.fetch,
    csrfTokenResolver = () => '',
    activeElementResolver = () => (typeof document !== 'undefined' ? document.activeElement : null),
    debounceMs = 150,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
}) {
    let timerId = null;
    let activeAbort = null;
    let requestSeq = 0;

    const url = tfoot?.dataset?.totalsUrl || '';
    const errorText = tfoot?.dataset?.errorText || 'Could not refresh totals from the server. Totals will be recalculated when you save.';

    const markError = () => {
        if (!statusEl) return;
        statusEl.textContent = errorText;
        statusEl.classList?.add?.('text-danger');
        statusEl.setAttribute?.('data-sync-error', '1');
    };

    const syncNow = async () => {
        if (timerId !== null) {
            clearTimer(timerId);
            timerId = null;
        }
        if (!tbody || !url || typeof fetchImpl !== 'function') {
            return { ok: false, reason: 'missing_configuration' };
        }

        if (activeAbort) {
            activeAbort.abort();
            activeAbort = null;
        }

        const seq = ++requestSeq;
        const abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        activeAbort = abortController;

        const items = collectPrItemsPayload(tbody);
        tfoot?.setAttribute?.('aria-busy', 'true');

        try {
            const response = await fetchImpl(url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: abortController?.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfTokenResolver() || '',
                },
                body: JSON.stringify({ items }),
            });

            if (seq !== requestSeq) {
                return { ok: false, aborted: true };
            }

            if (!response.ok) {
                markError();

                return { ok: false, status: response.status };
            }

            const payload = await response.json();
            if (seq !== requestSeq) {
                return { ok: false, aborted: true };
            }

            applyPrTotalsResponse(
                { tbody, qtyOutput, kgOutput, statusEl, activeElement: activeElementResolver() },
                payload,
            );

            return { ok: true, payload };
        } catch (error) {
            if (error?.name === 'AbortError' || seq !== requestSeq) {
                return { ok: false, aborted: true };
            }
            markError();

            return { ok: false, reason: 'network_error' };
        } finally {
            if (seq === requestSeq) {
                activeAbort = null;
                tfoot?.removeAttribute?.('aria-busy');
            }
        }
    };

    const schedule = () => {
        if (timerId !== null) {
            clearTimer(timerId);
        }
        timerId = setTimer(() => {
            timerId = null;
            void syncNow();
        }, debounceMs);
    };

    return { syncNow, schedule };
}

export function bootPrTotalsSync(root = document) {
    const tfoot = root.getElementById ? root.getElementById('prFormTotals') : root.querySelector?.('#prFormTotals');
    const tbody = root.getElementById ? root.getElementById('itemsBody') : root.querySelector?.('#itemsBody');
    const qtyOutput = root.getElementById ? root.getElementById('prFormTotalQty') : root.querySelector?.('#prFormTotalQty');
    const kgOutput = root.getElementById ? root.getElementById('prFormTotalKg') : root.querySelector?.('#prFormTotalKg');
    const statusEl = root.getElementById ? root.getElementById('prFormTotalsStatus') : root.querySelector?.('#prFormTotalsStatus');

    if (!tfoot || !tbody || !qtyOutput || !kgOutput || tfoot.dataset.prTotalsSyncBound === '1') {
        return null;
    }
    tfoot.dataset.prTotalsSyncBound = '1';

    const controller = createPrTotalsSyncController({
        tbody,
        tfoot,
        qtyOutput,
        kgOutput,
        statusEl,
        csrfTokenResolver: () =>
            document.querySelector('#prForm input[name="_token"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.content
            || '',
    });

    tbody.addEventListener('input', () => controller.schedule());
    tbody.addEventListener('change', () => controller.schedule());
    document.addEventListener('adasi:pr-items-changed', () => controller.schedule());

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(() => controller.schedule()).observe(tbody, { childList: true });
    }

    void controller.syncNow();

    return controller;
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootPrTotalsSync(document));
    } else {
        bootPrTotalsSync(document);
    }
}

