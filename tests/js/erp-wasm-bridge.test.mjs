import test from 'node:test';
import assert from 'node:assert/strict';

import {
    MAX_WASM_INPUT_BYTES,
    MAX_DELTA_BATCH_ROWS,
    chunkDeltaRows,
    createErpWasmClient,
    executeReconciliationAction,
    inspectSpreadsheetHeader,
    loadReconciliationWorkspace,
    resolvePortalEndpoint,
    resolveWorkerUrl,
    runDeltaSyncWorkspace,
} from '../../resources/js/erp-wasm-bridge.js';

function withMockWindow(adasiPortal, fn) {
    const previous = globalThis.window;
    globalThis.window = adasiPortal === undefined ? {} : { AdasiPortal: adasiPortal };
    try {
        return fn();
    } finally {
        if (previous === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = previous;
        }
    }
}

async function withMockWindowAsync(adasiPortal, fn) {
    const previous = globalThis.window;
    globalThis.window = adasiPortal === undefined ? {} : { AdasiPortal: adasiPortal };
    try {
        return await fn();
    } finally {
        if (previous === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = previous;
        }
    }
}

test('resolveWorkerUrl handles default docroot, ignores pathPrefix, respects baseUrl and custom overrides', () => {
    withMockWindow(undefined, () => {
        assert.equal(resolveWorkerUrl(), '/js/workers/portal-wasm.worker.js');
        assert.equal(resolveWorkerUrl('https://cdn.example.test/worker.js'), 'https://cdn.example.test/worker.js');
    });

    withMockWindow({ pathPrefix: 'v2/ADSI_PROD' }, () => {
        assert.equal(resolveWorkerUrl(), '/js/workers/portal-wasm.worker.js');
    });

    withMockWindow({ baseUrl: '/subapp/', pathPrefix: 'v2/ADSI_PROD' }, () => {
        assert.equal(resolveWorkerUrl(), '/subapp/js/workers/portal-wasm.worker.js');
    });

    withMockWindow({ workerUrl: '/custom/portal-wasm.worker.js' }, () => {
        assert.equal(resolveWorkerUrl(), '/custom/portal-wasm.worker.js');
    });
});

test('resolvePortalEndpoint respects AdasiPortal.pathPrefix without double-prefixing', () => {
    withMockWindow({ pathPrefix: 'v2/ADASI_PRD' }, () => {
        assert.equal(resolvePortalEndpoint('/local-supplier/erp-delta/hashes'), '/v2/ADASI_PRD/local-supplier/erp-delta/hashes');
        assert.equal(resolvePortalEndpoint('/v2/ADASI_PRD/local-supplier/erp-delta/sync'), '/v2/ADASI_PRD/local-supplier/erp-delta/sync');
        assert.equal(resolvePortalEndpoint('https://portal.test/erp-delta/hashes'), 'https://portal.test/erp-delta/hashes');
    });
});

test('compressPdf transfers ArrayBuffer and returns compressed Uint8Array matching PDF magic bytes', async () => {
    const calls = [];
    class FakeWorker {
        constructor(url, opts) {
            calls.push({ url, opts });
        }

        postMessage(message, transfer) {
            const payloadBytes = new TextEncoder().encode('%PDF-1.4\n%%EOF');
            queueMicrotask(() => {
                this.onmessage({
                    data: {
                        id: message.id,
                        ok: true,
                        buffer: payloadBytes.buffer,
                    },
                });
            });
            assert.equal(message.action, 'COMPRESS_PDF');
            assert.equal(message.payload.maxDim, 1200);
            assert.equal(transfer.length, 1);
            assert.ok(transfer[0] instanceof ArrayBuffer);
        }

        terminate() {}
    }

    const client = createErpWasmClient({ WorkerCtor: FakeWorker, workerUrl: '/js/workers/portal-wasm.worker.js' });
    const rawPdf = new TextEncoder().encode('%PDF-1.5\n1 0 obj\nendobj\n%%EOF').buffer;
    const output = await client.compressPdf(rawPdf, { maxDim: 1200 });

    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0], { url: '/js/workers/portal-wasm.worker.js', opts: { type: 'module' } });
    assert.ok(output instanceof Uint8Array);
    assert.equal(new TextDecoder().decode(output.slice(0, 5)), '%PDF-');
});

test('parseDeltaExcel transfers ArrayBuffer, forwards knownHashesJson, and parses JSON rows', async () => {
    class FakeWorker {
        postMessage(message, transfer) {
            assert.equal(message.action, 'PARSE_DELTA_EXCEL');
            assert.equal(message.payload.knownHashesJson, '["5cd229126f3d88b4"]');
            assert.equal(transfer.length, 1);
            queueMicrotask(() => {
                this.onmessage({
                    data: {
                        id: message.id,
                        ok: true,
                        deltaJson: JSON.stringify([{ doc_no: 'PO-001', item_code: 'MAT-01', row_hash: '34956c47a415bb9e' }]),
                    },
                });
            });
        }

        terminate() {}
    }

    const client = createErpWasmClient({ WorkerCtor: FakeWorker });
    const fakeExcel = new Uint8Array([0x50, 0x4b, 0x03, 0x04, 0x00]).buffer;
    const rows = await client.parseDeltaExcel(fakeExcel, ['5cd229126f3d88b4']);

    assert.equal(rows.length, 1);
    assert.equal(rows[0].doc_no, 'PO-001');
});

test('rejects oversized (> 20 MB) or empty inputs on main thread before spawning worker', async () => {
    let spawned = false;
    class NeverSpawnWorker {
        constructor() {
            spawned = true;
        }
    }

    const client = createErpWasmClient({ WorkerCtor: NeverSpawnWorker });

    await assert.rejects(() => client.compressPdf(new ArrayBuffer(0)), /empty_input/);
    await assert.rejects(() => client.parseDeltaExcel({ size: MAX_WASM_INPUT_BYTES + 1, arrayBuffer: async () => new ArrayBuffer(0) }), /input_too_large/);
    assert.equal(spawned, false);
});

test('chunkDeltaRows enforces 500-row max batch cap matching server validation limit', () => {
    assert.equal(MAX_DELTA_BATCH_ROWS, 500);
    const items = Array.from({ length: 1001 }, (_, i) => ({ doc_no: `PO-${i}` }));
    const chunks = chunkDeltaRows(items);

    assert.equal(chunks.length, 3);
    assert.equal(chunks[0].length, 500);
    assert.equal(chunks[1].length, 500);
    assert.equal(chunks[2].length, 1);
});

test('runDeltaSyncWorkspace forwards selected supplier_id to hashes and sync endpoints', async () => {
    const requests = [];
    const dispatched = [];
    const statusBox = {
        textContent: '',
        classList: {
            removed: [],
            added: [],
            remove(...classes) { this.removed.push(...classes); },
            add(...classes) { this.added.push(...classes); },
        },
    };
    const workspace = {
        dataset: {
            hashesUrl: '/finance/local-procurement/erp-delta/hashes',
            syncUrl: '/finance/local-procurement/erp-delta/sync',
            msgSynced: 'Synced :count in :batches',
            msgNoChanges: 'No changes',
            msgSelectSupplier: 'Pick supplier',
        },
        querySelector(selector) {
            if (selector === '[data-erp-delta-supplier]') {
                return { value: '42', hasAttribute: (name) => name === 'required' };
            }
            if (selector === '[data-erp-delta-file]') {
                return { files: [{ name: 'erp.csv', size: 16 }] };
            }
            if (selector === '[data-erp-delta-status]') {
                return statusBox;
            }
            return null;
        },
        dispatchEvent(event) {
            dispatched.push(event);
        },
    };

    const previousFetch = globalThis.fetch;
    const previousDocument = globalThis.document;
    const previousCustomEvent = globalThis.CustomEvent;
    globalThis.document = { querySelector: () => ({ getAttribute: () => 'csrf-tok' }) };
    globalThis.CustomEvent = class CustomEvent {
        constructor(type, init = {}) {
            this.type = type;
            this.detail = init.detail;
        }
    };
    globalThis.fetch = async (url, opts) => {
        requests.push({ url, opts });
        if (url.includes('hashes')) {
            return { ok: true, json: async () => ({ hashes: ['5cd229126f3d88b4'] }) };
        }
        return { ok: true, json: async () => ({ synced_count: 1 }) };
    };

    try {
        await withMockWindow({ pathPrefix: 'v2/ADSI_PROD' }, async () => {
            await runDeltaSyncWorkspace(workspace, {
                async parseDeltaExcel(_file, known) {
                    assert.deepEqual(known, ['5cd229126f3d88b4']);
                    return [{ doc_no: 'PO-1', item_code: 'MAT-1', qty_norm: '1.0000', price_norm: '10.00', status: 'ACTIVE', row_hash: '34956c47a415bb9e' }];
                },
            });
        });

        assert.equal(requests.length, 2);
        assert.equal(requests[0].url, '/v2/ADSI_PROD/finance/local-procurement/erp-delta/hashes?supplier_id=42');
        assert.equal(requests[1].url, '/v2/ADSI_PROD/finance/local-procurement/erp-delta/sync');
        const syncPayload = JSON.parse(requests[1].opts.body);
        assert.equal(syncPayload.supplier_id, '42');
        assert.deepEqual(syncPayload.rows, [{
            doc_no: 'PO-1',
            item_code: 'MAT-1',
            qty: '1.0000',
            price: '10.00',
            status: 'ACTIVE',
            row_hash: '34956c47a415bb9e',
        }]);
        assert.equal(statusBox.textContent, 'Synced 1 in 1');
        assert.equal(dispatched[0].type, 'adasi:erp-delta-synced');
        assert.deepEqual(dispatched[0].detail, { syncedCount: 1, batchCount: 1 });
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDocument;
        globalThis.CustomEvent = previousCustomEvent;
    }
});

test('inspectSpreadsheetHeader bypasses legacy .xls and enforces size and ZIP container header on .xlsx', async () => {
    const legacyXls = { name: 'infor-po.xls', size: 1024 };
    assert.deepEqual(await inspectSpreadsheetHeader(legacyXls), { valid: true, skipped: true });

    const oversized = { name: 'huge.xlsx', size: MAX_WASM_INPUT_BYTES + 1 };
    assert.deepEqual(await inspectSpreadsheetHeader(oversized), { valid: false, reason: 'input_too_large' });

    const fakeXlsx = {
        name: 'spoof.xlsx',
        size: 100,
        slice: () => ({ arrayBuffer: async () => new Uint8Array([0x25, 0x50, 0x44, 0x46]).buffer }),
    };
    assert.deepEqual(await inspectSpreadsheetHeader(fakeXlsx), { valid: false, reason: 'invalid_xlsx_container' });

    const validZipXlsx = {
        name: 'real.xlsx',
        size: 100,
        slice: () => ({ arrayBuffer: async () => new Uint8Array([0x50, 0x4b, 0x03, 0x04]).buffer }),
    };
    assert.deepEqual(await inspectSpreadsheetHeader(validZipXlsx), { valid: true, skipped: false });
});

function createFakeDomNode(tagName) {
    return {
        tagName: tagName.toUpperCase(),
        children: [],
        dataset: {},
        textContent: '',
        className: '',
        type: '',
        colSpan: 1,
        _innerHtmlAccessed: false,
        classList: {
            add() {},
            remove() {},
        },
        append(...nodes) { this.children.push(...nodes); },
        replaceChildren(...nodes) { this.children = nodes; },
    };
}

function collectText(node) {
    if (!node) return '';
    let out = node.textContent || '';
    for (const child of node.children || []) {
        out += ` ${collectText(child)}`;
    }
    return out;
}

test('loadReconciliationWorkspace renders safely with textContent and executes apply / 409 refresh', async () => {
    const tbody = createFakeDomNode('tbody');
    const statusBox = createFakeDomNode('div');
    const requests = [];
    const dispatched = [];
    const workspace = {
        dataset: {
            reconciliationUrl: '/finance/local-procurement/erp-delta/reconciliation',
            applyUrl: '/finance/local-procurement/erp-delta/apply-document',
            ignoreUrl: '/finance/local-procurement/erp-delta/ignore-document',
            canApply: '1',
            reconciliationI18n: JSON.stringify({
                apply: 'Apply',
                ignore: 'Ignore',
                kind_amount_diff: 'Amount Diff',
                applied_ok: 'Applied :po (:total)',
                stale_digest: 'Stale digest; refreshed',
            }),
        },
        querySelector(selector) {
            if (selector === '[data-erp-delta-supplier]') return { value: '7' };
            if (selector === '[data-erp-delta-recon-body]') return tbody;
            if (selector === '[data-erp-delta-recon-status]') return statusBox;
            return null;
        },
        dispatchEvent(event) { dispatched.push(event); },
    };

    const previousFetch = globalThis.fetch;
    const previousDocument = globalThis.document;
    const previousCustomEvent = globalThis.CustomEvent;
    globalThis.document = {
        createElement: (tag) => createFakeDomNode(tag),
        querySelector: () => ({ getAttribute: () => 'csrf-recon' }),
    };
    globalThis.CustomEvent = class CustomEvent {
        constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
    };

    let applyAttempts = 0;
    globalThis.fetch = async (url, opts) => {
        requests.push({ url, opts });
        if (url.includes('reconciliation')) {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    can_apply: true,
                    documents: [{
                        doc_no: '<img src=x onerror=alert(1)>',
                        line_count: 2,
                        erp_total_amount: '250.00',
                        erp_status: 'OPEN',
                        portal_po_number: 'PO-001',
                        portal_total_amount: '100.00',
                        portal_status: 'OPEN',
                        diff_kind: 'amount_diff',
                        reconciliation_status: 'pending',
                        can_apply: true,
                        composite_digest: '0123456789abcdef',
                    }],
                }),
            };
        }
        applyAttempts += 1;
        if (applyAttempts === 1) {
            return { ok: false, status: 409, json: async () => ({ message: 'Digest conflict' }) };
        }
        return {
            ok: true,
            status: 200,
            json: async () => ({ po_number: 'PO-001', total_amount: '250.00', status: 'OPEN', applied_count: 2 }),
        };
    };

    try {
        await withMockWindowAsync({ pathPrefix: 'v2/ADSI_PROD' }, async () => {
            const docs = await loadReconciliationWorkspace(workspace);
            assert.equal(docs.length, 1);
            assert.equal(tbody.children.length, 1);
            assert.ok(collectText(tbody.children[0]).includes('<img src=x onerror=alert(1)>'));
            assert.ok(collectText(tbody.children[0]).includes('Apply'));
            assert.ok(collectText(tbody.children[0]).includes('Ignore'));

            const conflictRes = await executeReconciliationAction(workspace, 'apply', 'PO-001', '0123456789abcdef');
            assert.equal(conflictRes, null);
            assert.equal(statusBox.textContent, 'Digest conflict');

            const appliedRes = await executeReconciliationAction(workspace, 'apply', 'PO-001', '0123456789abcdef');
            assert.equal(appliedRes.po_number, 'PO-001');
            assert.equal(dispatched[0].type, 'adasi:erp-reconciliation-updated');
            assert.equal(requests[0].url, '/v2/ADSI_PROD/finance/local-procurement/erp-delta/reconciliation?supplier_id=7');
            assert.equal(requests[1].url, '/v2/ADSI_PROD/finance/local-procurement/erp-delta/apply-document');
        });
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDocument;
        globalThis.CustomEvent = previousCustomEvent;
    }
});

