import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    serializePrRow,
    collectPrItemsPayload,
    applyPrTotalsResponse,
    createPrTotalsSyncController,
} from '../../resources/js/pr-totals-sync.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

function fakeRow(values = {}, disabledDims = []) {
    const totalCell = { textContent: '—' };
    const weightInput = { value: values.weight_needed ?? '0.0000' };
    const manualInput = { value: values.weight_manual_override ?? '0' };
    return {
        totalCell,
        weightInput,
        manualInput,
        querySelector(selector) {
            if (selector === '[data-pr-row-total-kg]') return totalCell;
            if (selector === '.weight-unit-display') return weightInput;
            if (selector === '.weight-manual-override') return manualInput;
            if (selector === '.material-master-id') return { value: values.material_master_id ?? '' };
            if (selector === '.material-shape-select') return { value: values.shape ?? '' };
            if (selector === '.material-quantity') return { value: values.quantity ?? '1' };
            if (selector === '.hs-code-display') return { value: values.hs_code ?? '' };
            if (selector === '.hs-code-manual-override') return { value: values.hs_code_manual_override ?? '0' };
            const dimMatch = selector.match(/^\.dimension-input\[data-dimension-field="([^"]+)"\]$/);
            if (dimMatch) {
                const field = dimMatch[1];
                return {
                    value: values[field] ?? '',
                    disabled: disabledDims.includes(field),
                };
            }
            return null;
        },
    };
}

test('serializePrRow extracts enabled dimension fields and row metadata', () => {
    const row = fakeRow(
        {
            material_master_id: '12',
            shape: 'Flat',
            quantity: '4',
            thickness: '10',
            width: '200',
            length: '1000',
            d_inner: '50',
            d_outer: '100',
            weight_needed: '15.7000',
        },
        ['d_inner', 'd_outer'],
    );

    assert.deepEqual(serializePrRow(row, 2), {
        index: 2,
        material_master_id: '12',
        shape: 'Flat',
        quantity: '4',
        thickness: '10',
        width: '200',
        length: '1000',
        d_inner: '',
        d_outer: '',
        weight_needed: '15.7000',
        weight_manual_override: '0',
        hs_code: '',
        hs_code_manual_override: '0',
    });
});

test('applyPrTotalsResponse updates row Total KG cells, non-manual unit weights, and grand totals', () => {
    const row0 = fakeRow({ quantity: '3', weight_needed: '0.0000' });
    const row1 = fakeRow({ quantity: '2', weight_needed: '9.9999', weight_manual_override: '1' });
    const row2 = fakeRow({ quantity: '5', weight_needed: '0.0000' });
    const tbody = { querySelectorAll: () => [row0, row1, row2] };
    const qtyOutput = { textContent: '0' };
    const kgOutput = { textContent: '0.0000' };
    const statusEl = {
        textContent: 'Error',
        dataset: { defaultText: 'Totals are calculated by the server without saving and verified again when you save.' },
        classList: {
            removed: [],
            remove(cls) {
                this.removed.push(cls);
            },
        },
        removeAttribute() {},
    };

    applyPrTotalsResponse(
        { tbody, qtyOutput, kgOutput, statusEl },
        {
            rows: [
                { index: 0, unit_kg: '12.5000', row_total_kg_display: '37.5000', counted_in_kg: true },
                { index: 1, unit_kg: '9.9999', row_total_kg_display: '19.9998', counted_in_kg: true },
                { index: 2, unit_kg: null, row_total_kg_display: null, counted_in_kg: false },
            ],
            totals: {
                total_quantity: 10,
                total_quantity_display: '10',
                total_kg: '57.4998',
                total_kg_display: '57.4998',
            },
        },
    );

    assert.equal(row0.totalCell.textContent, '37.5000');
    assert.equal(row0.weightInput.value, '12.5000');
    assert.equal(row1.totalCell.textContent, '19.9998');
    assert.equal(row1.weightInput.value, '9.9999');
    assert.equal(row2.totalCell.textContent, '—');
    assert.equal(qtyOutput.textContent, '10');
    assert.equal(kgOutput.textContent, '57.4998');
    assert.equal(statusEl.textContent, statusEl.dataset.defaultText);
    assert.deepEqual(statusEl.classList.removed, ['text-danger']);
});

test('createPrTotalsSyncController aborts stale in-flight requests and applies only the latest response', async () => {
    const row = fakeRow({ material_master_id: '5', shape: 'Round', quantity: '2', d_outer: '50', length: '1000' });
    const tbody = { querySelectorAll: () => [row] };
    const tfoot = {
        dataset: {
            totalsUrl: '/purchasing/material-calculations/totals',
            errorText: 'Sync failed.',
        },
        setAttribute() {},
        removeAttribute() {},
    };
    const qtyOutput = { textContent: '0' };
    const kgOutput = { textContent: '0.0000' };

    const calls = [];
    const resolvers = [];
    const controller = createPrTotalsSyncController({
        tbody,
        tfoot,
        qtyOutput,
        kgOutput,
        csrfTokenResolver: () => 'csrf-123',
        fetchImpl: (url, init) => {
            calls.push({ url, init });
            return new Promise((resolve, reject) => {
                init.signal?.addEventListener('abort', () => {
                    const err = new Error('Aborted');
                    err.name = 'AbortError';
                    reject(err);
                });
                resolvers.push(resolve);
            });
        },
    });

    const firstPromise = controller.syncNow();
    const secondPromise = controller.syncNow();

    assert.equal(calls.length, 2);
    assert.equal(calls[0].init.signal.aborted, true);

    resolvers[1](
        new Response(
            JSON.stringify({
                rows: [{ index: 0, unit_kg: '15.4175', row_total_kg_display: '30.8350', counted_in_kg: true }],
                totals: { total_quantity: 2, total_quantity_display: '2', total_kg: '30.8350', total_kg_display: '30.8350' },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );

    const [firstResult, secondResult] = await Promise.all([firstPromise, secondPromise]);
    assert.equal(firstResult.aborted, true);
    assert.equal(secondResult.ok, true);
    assert.equal(qtyOutput.textContent, '2');
    assert.equal(kgOutput.textContent, '30.8350');
    assert.equal(row.totalCell.textContent, '30.8350');
});

test('createPrTotalsSyncController surfaces non-blocking status notice on server error', async () => {
    const tbody = { querySelectorAll: () => [fakeRow({ quantity: '4' })] };
    const tfoot = {
        dataset: {
            totalsUrl: '/purchasing/material-calculations/totals',
            errorText: 'Could not refresh totals from the server.',
        },
        setAttribute() {},
        removeAttribute() {},
    };
    const qtyOutput = { textContent: '4' };
    const kgOutput = { textContent: '0.0000' };
    const statusEl = {
        textContent: 'Default hint',
        dataset: { defaultText: 'Default hint' },
        classList: {
            added: [],
            add(cls) {
                this.added.push(cls);
            },
        },
        setAttribute() {},
    };

    const controller = createPrTotalsSyncController({
        tbody,
        tfoot,
        qtyOutput,
        kgOutput,
        statusEl,
        fetchImpl: async () => new Response('Server Error', { status: 500 }),
    });

    const result = await controller.syncNow();
    assert.equal(result.ok, false);
    assert.equal(statusEl.textContent, 'Could not refresh totals from the server.');
    assert.deepEqual(statusEl.classList.added, ['text-danger']);
    assert.equal(qtyOutput.textContent, '4', 'existing output is preserved on transient sync error');
});

test('the PR form wires server-side totals sync lazily via #prFormTotals without client-side weight math', () => {
    const app = read('resources/js/app.js');
    assert.match(app, /hasMarker\('#prFormTotals'\)\) import\('\.\/pr-totals-sync'\)/);
    assert.doesNotMatch(app, /pr-totals-core/);
    const totalsView = read('resources/views/purchasing/pr/_form_totals.blade.php');
    assert.match(totalsView, /data-totals-url="\{\{\s*route\('purchasing\.material-calculations\.totals'\)\s*\}\}"/);
    assert.doesNotMatch(totalsView, /AdasiPrTotals/);
});

