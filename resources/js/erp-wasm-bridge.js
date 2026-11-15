export const MAX_WASM_INPUT_BYTES = 20 * 1024 * 1024;
export const MAX_DELTA_BATCH_ROWS = 500;
export const DEFAULT_MAX_PDF_DIM = 1600;
export const DEFAULT_WORKER_TIMEOUT_MS = 30000;

export function resolveWorkerUrl(customUrl = null) {
    if (typeof customUrl === 'string' && customUrl.trim() !== '') {
        return customUrl.trim();
    }

    const portal = typeof globalThis.window === 'object' ? globalThis.window?.AdasiPortal : null;
    if (portal && typeof portal.workerUrl === 'string' && portal.workerUrl.trim() !== '') {
        return portal.workerUrl.trim();
    }

    if (portal && typeof portal.baseUrl === 'string' && portal.baseUrl.trim() !== '') {
        const baseUrl = portal.baseUrl.trim().replace(/\/+$/, '');
        return `${baseUrl}/js/workers/portal-wasm.worker.js`;
    }

    return '/js/workers/portal-wasm.worker.js';
}

export function resolvePortalEndpoint(path) {
    if (typeof path !== 'string' || path.trim() === '') {
        return '';
    }

    const trimmed = path.trim();
    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }

    const portal = typeof globalThis.window === 'object' ? globalThis.window?.AdasiPortal : null;
    const prefix = portal && typeof portal.pathPrefix === 'string'
        ? portal.pathPrefix.replace(/^\/+|\/+$/g, '')
        : '';

    const normalizedPath = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
    if (prefix === '' || normalizedPath === `/${prefix}` || normalizedPath.startsWith(`/${prefix}/`)) {
        return normalizedPath;
    }

    return `/${prefix}${normalizedPath}`;
}

export function chunkDeltaRows(rows, batchSize = MAX_DELTA_BATCH_ROWS) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return [];
    }

    const safeSize = Math.max(1, Math.min(MAX_DELTA_BATCH_ROWS, Math.trunc(batchSize)));
    const chunks = [];
    for (let index = 0; index < rows.length; index += safeSize) {
        chunks.push(rows.slice(index, index + safeSize));
    }

    return chunks;
}

async function toOwnedArrayBuffer(input) {
    if (input instanceof ArrayBuffer) {
        if (input.byteLength === 0) {
            throw new RangeError('empty_input');
        }
        if (input.byteLength > MAX_WASM_INPUT_BYTES) {
            throw new RangeError('input_too_large');
        }

        return input.slice(0);
    }

    if (ArrayBuffer.isView(input)) {
        if (input.byteLength === 0) {
            throw new RangeError('empty_input');
        }
        if (input.byteLength > MAX_WASM_INPUT_BYTES) {
            throw new RangeError('input_too_large');
        }

        return input.buffer.slice(input.byteOffset, input.byteOffset + input.byteLength);
    }

    if (input && typeof input.arrayBuffer === 'function') {
        if (typeof input.size === 'number') {
            if (input.size === 0) {
                throw new RangeError('empty_input');
            }
            if (input.size > MAX_WASM_INPUT_BYTES) {
                throw new RangeError('input_too_large');
            }
        }

        const loaded = await input.arrayBuffer();
        if (loaded.byteLength === 0) {
            throw new RangeError('empty_input');
        }
        if (loaded.byteLength > MAX_WASM_INPUT_BYTES) {
            throw new RangeError('input_too_large');
        }

        return loaded;
    }

    throw new TypeError('unsupported_input');
}

function hasPdfMagicBytes(buffer) {
    if (!(buffer instanceof ArrayBuffer) || buffer.byteLength < 5) {
        return false;
    }

    const bytes = new Uint8Array(buffer, 0, 5);
    return bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46 && bytes[4] === 0x2d;
}

export function createErpWasmClient(options = {}) {
    const WorkerCtor = options.WorkerCtor ?? globalThis.Worker;
    const workerUrl = resolveWorkerUrl(options.workerUrl ?? null);
    const timeoutMs = typeof options.timeoutMs === 'number' && options.timeoutMs > 0
        ? options.timeoutMs
        : DEFAULT_WORKER_TIMEOUT_MS;

    let worker = null;
    let sequence = 0;
    const pending = new Map();

    const failAllPending = (reason) => {
        for (const [requestId, entry] of pending.entries()) {
            clearTimeout(entry.timer);
            pending.delete(requestId);
            entry.reject(reason);
        }

        if (worker) {
            try {
                worker.terminate();
            } catch {
                // Ignore termination cleanup failures.
            }
            worker = null;
        }
    };

    const ensureWorker = () => {
        if (worker) {
            return worker;
        }

        if (typeof WorkerCtor !== 'function') {
            throw new Error('worker_unavailable');
        }

        const instance = new WorkerCtor(workerUrl, { type: 'module' });
        instance.onmessage = (event) => {
            const payload = event && typeof event === 'object' ? event.data : null;
            if (!payload || typeof payload !== 'object' || !pending.has(payload.id)) {
                return;
            }

            const entry = pending.get(payload.id);
            pending.delete(payload.id);
            clearTimeout(entry.timer);

            if (payload.ok) {
                entry.resolve(payload);
            } else {
                entry.reject(new Error(typeof payload.error === 'string' && payload.error !== '' ? payload.error : 'wasm_failed'));
            }
        };

        instance.onerror = (event) => {
            const detail = event && typeof event.message === 'string' && event.message !== ''
                ? event.message
                : 'worker_crashed';
            failAllPending(new Error(detail));
        };

        worker = instance;
        return worker;
    };

    const dispatch = (action, payload, transferables) => new Promise((resolve, reject) => {
        let activeWorker;
        try {
            activeWorker = ensureWorker();
        } catch (err) {
            reject(err);
            return;
        }

        const requestId = ++sequence;
        const timer = setTimeout(() => {
            if (!pending.has(requestId)) {
                return;
            }
            pending.delete(requestId);
            reject(new Error('worker_timeout'));
            failAllPending(new Error('worker_timeout'));
        }, timeoutMs);

        pending.set(requestId, { resolve, reject, timer });

        try {
            activeWorker.postMessage({ id: requestId, action, payload }, transferables);
        } catch (err) {
            clearTimeout(timer);
            pending.delete(requestId);
            reject(err);
        }
    });

    return {
        async compressPdf(input, { maxDim = DEFAULT_MAX_PDF_DIM } = {}) {
            const buffer = await toOwnedArrayBuffer(input);
            if (!hasPdfMagicBytes(buffer)) {
                throw new TypeError('invalid_pdf_magic');
            }

            const response = await dispatch('COMPRESS_PDF', { buffer, maxDim }, [buffer]);
            if (!(response.buffer instanceof ArrayBuffer) || !hasPdfMagicBytes(response.buffer)) {
                throw new Error('invalid_wasm_pdf_output');
            }

            return new Uint8Array(response.buffer);
        },

        async parseDeltaExcel(input, knownHashes = []) {
            const buffer = await toOwnedArrayBuffer(input);
            const knownHashesJson = typeof knownHashes === 'string'
                ? knownHashes
                : JSON.stringify(Array.isArray(knownHashes) ? knownHashes : []);

            const response = await dispatch('PARSE_DELTA_EXCEL', { buffer, knownHashesJson }, [buffer]);
            const parsed = JSON.parse(typeof response.deltaJson === 'string' ? response.deltaJson : '[]');
            if (!Array.isArray(parsed)) {
                throw new Error('invalid_delta_payload');
            }

            return parsed;
        },

        terminate() {
            failAllPending(new Error('worker_terminated'));
        },
    };
}

let sharedClient = null;

function getSharedClient() {
    if (!sharedClient) {
        sharedClient = createErpWasmClient();
    }

    return sharedClient;
}

function readCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
}

const processedPdfFiles = new WeakSet();

function isEligiblePdfFile(file) {
    if (!file || typeof file.name !== 'string') {
        return false;
    }

    const isPdfMimeOrExt = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
    return isPdfMimeOrExt
        && file.size > 0
        && file.size <= MAX_WASM_INPUT_BYTES
        && !processedPdfFiles.has(file);
}

async function compressPdfInputFiles(input) {
    const currentFiles = input.files ? Array.from(input.files) : [];
    if (currentFiles.length === 0 || !currentFiles.some(isEligiblePdfFile)) {
        return false;
    }

    if (typeof globalThis.Worker !== 'function' || typeof globalThis.DataTransfer !== 'function') {
        return false;
    }

    const nextDt = new DataTransfer();
    let changed = false;

    for (const file of currentFiles) {
        if (!isEligiblePdfFile(file)) {
            nextDt.items.add(file);
            continue;
        }

        processedPdfFiles.add(file);

        try {
            const compressedBytes = await getSharedClient().compressPdf(file);
            if (compressedBytes.byteLength > 0 && compressedBytes.byteLength < file.size) {
                const compressedFile = new File([compressedBytes], file.name, {
                    type: 'application/pdf',
                    lastModified: file.lastModified || Date.now(),
                });
                processedPdfFiles.add(compressedFile);
                nextDt.items.add(compressedFile);
                changed = true;
            } else {
                nextDt.items.add(file);
            }
        } catch {
            // Fall back safely to the original file for server-side validation.
            nextDt.items.add(file);
        }
    }

    if (changed) {
        input.files = nextDt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    return changed;
}

function wirePdfCompressionInput(input) {
    if (input.dataset.wasmPdfBound === '1') {
        return;
    }
    input.dataset.wasmPdfBound = '1';

    let compressing = false;
    const handleSelection = async () => {
        if (compressing) {
            return;
        }
        compressing = true;
        try {
            await compressPdfInputFiles(input);
        } finally {
            compressing = false;
        }
    };

    input.addEventListener('change', handleSelection);
    const dropzoneHost = typeof input.closest === 'function' ? input.closest('[x-data]') : null;
    if (dropzoneHost) {
        dropzoneHost.addEventListener('adasi:files-changed', handleSelection);
    }
}

function appendSupplierQuery(url, supplierId) {
    if (!supplierId) {
        return url;
    }

    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}supplier_id=${encodeURIComponent(String(supplierId))}`;
}

const STATUS_TONE_CLASSES = {
    success: ['tw:border-success/40', 'tw:bg-success/10', 'tw:text-success'],
    error: ['tw:border-error/40', 'tw:bg-error-container/30', 'tw:text-error'],
    info: ['tw:border-outline-variant', 'tw:bg-surface-container', 'tw:text-on-surface'],
};

const ALL_TONE_CLASSES = Object.values(STATUS_TONE_CLASSES).flat();

function renderBannerStatus(statusBox, tone, message) {
    if (!statusBox) {
        return;
    }

    statusBox.classList.remove('d-none', ...ALL_TONE_CLASSES);
    statusBox.classList.add(...(STATUS_TONE_CLASSES[tone] ?? STATUS_TONE_CLASSES.info));
    statusBox.textContent = message;
}

function hideBannerStatus(statusBox) {
    if (!statusBox) {
        return;
    }
    statusBox.classList.add('d-none');
    statusBox.textContent = '';
}

function renderDeltaStatus(workspace, tone, message) {
    renderBannerStatus(workspace.querySelector('[data-erp-delta-status]'), tone, message);
}

function resolveDeltaSupplierId(workspace) {
    const supplierSelect = workspace.querySelector('[data-erp-delta-supplier]');
    return supplierSelect
        ? String(supplierSelect.value || '').trim()
        : String(workspace.dataset.supplierId || '').trim();
}

function parseReconciliationI18n(workspace) {
    try {
        const parsed = JSON.parse(workspace.dataset.reconciliationI18n || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

function formatI18nTemplate(template, replacements = {}) {
    if (typeof template !== 'string') {
        return '';
    }
    return template.replace(/:([a-zA-Z0-9_]+)/g, (match, key) => (
        Object.hasOwn(replacements, key) ? String(replacements[key]) : match
    ));
}

function extractApiErrorMessage(payload, fallback) {
    if (payload && typeof payload === 'object') {
        if (payload.errors && typeof payload.errors === 'object') {
            const flat = Object.values(payload.errors).flat().filter((item) => typeof item === 'string' && item !== '');
            if (flat.length > 0) {
                return flat.join(' ');
            }
        }
        if (typeof payload.message === 'string' && payload.message.trim() !== '') {
            return payload.message.trim();
        }
    }
    return fallback;
}

function mapDeltaRowForSync(row) {
    return {
        doc_no: row?.doc_no ?? '',
        item_code: row?.item_code ?? '',
        qty: row?.qty ?? row?.qty_norm ?? '',
        price: row?.price ?? row?.price_norm ?? '',
        status: row?.status ?? '',
        row_hash: row?.row_hash ?? '',
    };
}

export async function inspectSpreadsheetHeader(file) {
    if (!file || typeof file.name !== 'string') {
        return { valid: true, skipped: true };
    }
    const lowerName = file.name.toLowerCase();
    if (lowerName.endsWith('.xls')) {
        return { valid: true, skipped: true };
    }
    if (typeof file.size === 'number' && (file.size === 0 || file.size > MAX_WASM_INPUT_BYTES)) {
        return { valid: false, reason: file.size === 0 ? 'empty_input' : 'input_too_large' };
    }
    if (lowerName.endsWith('.xlsx') && typeof file.slice === 'function') {
        const headerBuffer = await file.slice(0, 4).arrayBuffer();
        const bytes = new Uint8Array(headerBuffer);
        const isZip = bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4b && bytes[2] === 0x03 && bytes[3] === 0x04;
        if (!isZip) {
            return { valid: false, reason: 'invalid_xlsx_container' };
        }
    }
    return { valid: true, skipped: false };
}

function diffVisualMeta(doc, i18n) {
    const kind = String(doc?.diff_kind || 'matched');
    const label = i18n[`kind_${kind}`] || kind;
    const blockText = doc?.block_reason ? (i18n[`block_${doc.block_reason}`] || doc.block_reason) : '';

    if (kind === 'applied') {
        return { icon: 'applied', pillClass: 'tw:border-success/40 tw:bg-success/10 tw:text-success', label, blockText };
    }
    if (kind === 'matched') {
        return { icon: 'matched', pillClass: 'tw:border-success/40 tw:bg-success/10 tw:text-success', label, blockText };
    }
    if (kind === 'blocked') {
        return { icon: 'blocked', pillClass: 'tw:border-error/40 tw:bg-error-container/30 tw:text-error', label, blockText };
    }
    if (kind === 'ignored') {
        return { icon: 'ignored', pillClass: 'tw:border-outline-variant tw:bg-surface-container tw:text-on-surface-variant', label, blockText };
    }
    return { icon: 'diff', pillClass: 'tw:border-primary/40 tw:bg-primary/10 tw:text-primary', label, blockText };
}

function cloneStatusIcon(workspace, iconKey) {
    const tpl = workspace.querySelector(`template[data-erp-delta-icon="${iconKey}"]`);
    const node = tpl?.content?.firstElementChild;
    return node ? node.cloneNode(true) : null;
}

function renderMessageRow(tbody, text) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 5;
    cell.className = 'tw:py-4 tw:px-3 tw:text-center tw:text-on-surface-variant';
    cell.textContent = text;
    row.append(cell);
    tbody.replaceChildren(row);
}

function buildTextStackCell(primaryText, secondaryText, alignEnd = false) {
    const cell = document.createElement('td');
    cell.className = alignEnd ? 'tw:py-2 tw:px-3 tw:text-end tw:tabular-nums' : 'tw:py-2 tw:px-3';

    const primary = document.createElement('div');
    primary.className = 'tw:font-semibold tw:text-on-surface';
    primary.textContent = primaryText;
    cell.append(primary);

    if (secondaryText) {
        const secondary = document.createElement('div');
        secondary.className = 'tw:text-[11px] tw:text-on-surface-variant';
        secondary.textContent = secondaryText;
        cell.append(secondary);
    }

    return cell;
}

function renderReconciliationRows(workspace, documents, serverCanApply, i18n) {
    const tbody = workspace.querySelector('[data-erp-delta-recon-body]');
    if (!tbody) {
        return;
    }

    if (!Array.isArray(documents) || documents.length === 0) {
        renderMessageRow(tbody, i18n.empty || 'No synced ERP documents for reconciliation yet.');
        return;
    }

    const canStaffAct = workspace.dataset.canApply === '1'
        && serverCanApply !== false
        && Boolean(workspace.dataset.applyUrl);

    const renderedRows = documents.map((doc) => {
        const row = document.createElement('tr');

        const docMeta = formatI18nTemplate(i18n.lines_badge || ':count line(s)', {
            count: Number(doc.line_count || 0),
        });
        row.append(buildTextStackCell(String(doc.doc_no || ''), docMeta, false));
        row.append(buildTextStackCell(String(doc.erp_total_amount ?? '0.00'), String(doc.erp_status || '—'), true));

        const portalPrimary = doc.portal_po_number ? String(doc.portal_po_number) : '—';
        const portalSecondary = doc.portal_po_number
            ? `${doc.portal_total_amount ?? '0.00'} · ${doc.portal_status ?? ''}`
            : '';
        row.append(buildTextStackCell(portalPrimary, portalSecondary, true));

        const statusCell = document.createElement('td');
        statusCell.className = 'tw:py-2 tw:px-3';
        const meta = diffVisualMeta(doc, i18n);
        const pill = document.createElement('span');
        pill.className = `tw:inline-flex tw:items-center tw:gap-1 tw:rounded-ui-sm tw:border tw:px-2 tw:py-0.5 tw:font-medium ${meta.pillClass}`;
        const iconNode = cloneStatusIcon(workspace, meta.icon);
        if (iconNode) {
            pill.append(iconNode);
        }
        const pillText = document.createElement('span');
        pillText.textContent = meta.label;
        pill.append(pillText);
        statusCell.append(pill);
        if (meta.blockText) {
            const hint = document.createElement('div');
            hint.className = 'tw:text-[11px] tw:text-on-surface-variant tw:mt-0.5';
            hint.textContent = meta.blockText;
            statusCell.append(hint);
        }
        row.append(statusCell);

        const actionCell = document.createElement('td');
        actionCell.className = 'tw:py-2 tw:px-3 tw:text-end';
        if (canStaffAct) {
            const btnGroup = document.createElement('div');
            btnGroup.className = 'tw:inline-flex tw:items-center tw:justify-end tw:gap-1.5';
            if (doc.can_apply) {
                const applyBtn = document.createElement('button');
                applyBtn.type = 'button';
                applyBtn.className = 'btn btn-primary btn-sm tw:min-h-[28px] tw:min-w-[28px] tw:text-ui-xs';
                applyBtn.dataset.reconAction = 'apply';
                applyBtn.dataset.docNo = String(doc.doc_no || '');
                applyBtn.dataset.digest = String(doc.composite_digest || '');
                applyBtn.textContent = i18n.apply || 'Apply';
                btnGroup.append(applyBtn);
            }
            if (doc.reconciliation_status === 'pending' && workspace.dataset.ignoreUrl) {
                const ignoreBtn = document.createElement('button');
                ignoreBtn.type = 'button';
                ignoreBtn.className = 'btn btn-outline-secondary btn-sm tw:min-h-[28px] tw:min-w-[28px] tw:text-ui-xs';
                ignoreBtn.dataset.reconAction = 'ignore';
                ignoreBtn.dataset.docNo = String(doc.doc_no || '');
                ignoreBtn.dataset.digest = String(doc.composite_digest || '');
                ignoreBtn.textContent = i18n.ignore || 'Ignore';
                btnGroup.append(ignoreBtn);
            }
            if (btnGroup.children.length === 0) {
                const dash = document.createElement('span');
                dash.className = 'tw:text-on-surface-variant';
                dash.textContent = '—';
                actionCell.append(dash);
            } else {
                actionCell.append(btnGroup);
            }
        } else {
            const readOnly = document.createElement('span');
            readOnly.className = 'tw:text-[11px] tw:text-on-surface-variant';
            readOnly.textContent = i18n.read_only_hint || 'Read-only';
            actionCell.append(readOnly);
        }
        row.append(actionCell);

        return row;
    });

    tbody.replaceChildren(...renderedRows);
}

export async function loadReconciliationWorkspace(workspace) {
    const baseUrl = resolvePortalEndpoint(workspace.dataset.reconciliationUrl || '');
    const tbody = workspace.querySelector('[data-erp-delta-recon-body]');
    if (!baseUrl || !tbody) {
        return [];
    }

    const i18n = parseReconciliationI18n(workspace);
    const statusBox = workspace.querySelector('[data-erp-delta-recon-status]');
    const supplierSelect = workspace.querySelector('[data-erp-delta-supplier]');
    const supplierId = resolveDeltaSupplierId(workspace);

    if (supplierSelect && supplierId === '') {
        hideBannerStatus(statusBox);
        renderMessageRow(tbody, i18n.select_supplier_hint || 'Select a supplier first.');
        return [];
    }

    renderMessageRow(tbody, i18n.loading || 'Loading...');
    const requestUrl = appendSupplierQuery(baseUrl, supplierId);
    const response = await fetch(requestUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = extractApiErrorMessage(payload, `reconciliation_${response.status}`);
        renderBannerStatus(statusBox, 'error', message);
        renderMessageRow(tbody, message);
        return [];
    }

    const documents = Array.isArray(payload.documents) ? payload.documents : [];
    renderReconciliationRows(workspace, documents, payload.can_apply, i18n);
    return documents;
}

export async function executeReconciliationAction(workspace, action, docNo, expectedDigest) {
    const i18n = parseReconciliationI18n(workspace);
    const statusBox = workspace.querySelector('[data-erp-delta-recon-status]');
    const rawEndpoint = action === 'apply' ? workspace.dataset.applyUrl : workspace.dataset.ignoreUrl;
    const endpoint = resolvePortalEndpoint(rawEndpoint || '');
    const supplierId = resolveDeltaSupplierId(workspace);

    if (!endpoint || supplierId === '' || !docNo || !expectedDigest) {
        throw new Error(workspace.dataset.msgSelectSupplier || 'missing_reconciliation_params');
    }

    renderBannerStatus(
        statusBox,
        'info',
        formatI18nTemplate(i18n.applying || 'Updating :doc...', { doc: docNo }),
    );

    const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': readCsrfToken(),
        },
        body: JSON.stringify({
            supplier_id: Number(supplierId),
            doc_no: docNo,
            expected_digest: expectedDigest,
        }),
    });

    const payload = await response.json().catch(() => ({}));
    if (response.status === 409) {
        const staleMsg = extractApiErrorMessage(payload, i18n.stale_digest || 'ERP data changed; refreshed.');
        renderBannerStatus(statusBox, 'error', staleMsg);
        await loadReconciliationWorkspace(workspace);
        return null;
    }

    if (!response.ok) {
        const errorMsg = extractApiErrorMessage(payload, `reconcile_${response.status}`);
        renderBannerStatus(statusBox, 'error', errorMsg);
        throw new Error(errorMsg);
    }

    const successMsg = action === 'apply'
        ? formatI18nTemplate(i18n.applied_ok || 'Updated PO :po.', {
            po: payload.po_number || docNo,
            total: payload.total_amount || '',
            status: payload.status || '',
            count: payload.applied_count || 0,
        })
        : formatI18nTemplate(i18n.ignored_ok || 'Ignored :doc.', {
            doc: payload.doc_no || docNo,
            count: payload.ignored_count || 0,
        });

    renderBannerStatus(statusBox, 'success', successMsg);
    await loadReconciliationWorkspace(workspace);

    workspace.dispatchEvent(new CustomEvent('adasi:erp-reconciliation-updated', {
        bubbles: true,
        detail: { action, docNo, payload },
    }));

    return payload;
}

export async function runDeltaSyncWorkspace(workspace, client = getSharedClient()) {
    const hashesBaseUrl = resolvePortalEndpoint(workspace.dataset.hashesUrl || '');
    const syncUrl = resolvePortalEndpoint(workspace.dataset.syncUrl || '');
    const supplierSelect = workspace.querySelector('[data-erp-delta-supplier]');
    const supplierId = resolveDeltaSupplierId(workspace);
    const fileInput = workspace.querySelector('[data-erp-delta-file]');
    const selectedFile = fileInput && fileInput.files ? fileInput.files[0] : null;

    if (supplierSelect && supplierSelect.hasAttribute('required') && supplierId === '') {
        throw new Error(workspace.dataset.msgSelectSupplier || 'missing_supplier');
    }
    if (!hashesBaseUrl || !syncUrl || !selectedFile) {
        throw new Error(workspace.dataset.msgSelectFile || 'missing_file');
    }

    renderDeltaStatus(workspace, 'info', workspace.dataset.msgSyncing || 'Processing...');

    const hashesUrl = appendSupplierQuery(hashesBaseUrl, supplierId);
    const csrf = readCsrfToken();
    const hashesResponse = await fetch(hashesUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    if (!hashesResponse.ok) {
        throw new Error(`hashes_${hashesResponse.status}`);
    }

    const hashesData = await hashesResponse.json();
    const knownHashes = Array.isArray(hashesData.hashes) ? hashesData.hashes : [];
    const deltaRows = (await client.parseDeltaExcel(selectedFile, knownHashes)).map(mapDeltaRowForSync);
    const batches = chunkDeltaRows(deltaRows, MAX_DELTA_BATCH_ROWS);

    let totalSynced = 0;
    for (const batch of batches) {
        const body = { rows: batch };
        if (supplierId !== '') {
            body.supplier_id = supplierId;
        }

        const syncResponse = await fetch(syncUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        if (!syncResponse.ok) {
            throw new Error(`sync_${syncResponse.status}`);
        }

        const result = await syncResponse.json();
        totalSynced += Number(result.synced_count || batch.length);
    }

    if (batches.length === 0) {
        renderDeltaStatus(workspace, 'info', workspace.dataset.msgNoChanges || '0 changes');
    } else {
        const template = workspace.dataset.msgSynced || 'Synced :count row(s) across :batches batch(es).';
        renderDeltaStatus(
            workspace,
            'success',
            template.replace(':count', String(totalSynced)).replace(':batches', String(batches.length)),
        );
    }

    workspace.dispatchEvent(new CustomEvent('adasi:erp-delta-synced', {
        bubbles: true,
        detail: { syncedCount: totalSynced, batchCount: batches.length },
    }));
}

function wireReconciliationWorkspace(workspace) {
    const refreshBtn = workspace.querySelector('[data-erp-delta-recon-refresh]');
    const supplierSelect = workspace.querySelector('[data-erp-delta-supplier]');
    const reconBody = workspace.querySelector('[data-erp-delta-recon-body]');
    const modalEl = typeof workspace.closest === 'function' ? workspace.closest('.modal') : null;

    const refreshSafely = () => {
        loadReconciliationWorkspace(workspace).catch(() => {});
    };

    refreshBtn?.addEventListener('click', refreshSafely);
    supplierSelect?.addEventListener('change', refreshSafely);
    modalEl?.addEventListener('shown.bs.modal', refreshSafely);
    workspace.addEventListener('adasi:erp-delta-synced', refreshSafely);

    reconBody?.addEventListener('click', async (event) => {
        const btn = event.target?.closest?.('[data-recon-action]');
        if (!btn || btn.disabled) {
            return;
        }

        const rowButtons = [...(btn.closest('tr')?.querySelectorAll('[data-recon-action]') ?? [btn])];
        rowButtons.forEach((b) => { b.disabled = true; });
        try {
            await executeReconciliationAction(
                workspace,
                btn.dataset.reconAction,
                btn.dataset.docNo,
                btn.dataset.digest,
            );
        } catch {
            // Error banner is already populated inside executeReconciliationAction.
        } finally {
            rowButtons.forEach((b) => { b.disabled = false; });
        }
    });
}

function wireLocalImportPreGuard(input) {
    if (input.dataset.wasmImportGuardBound === '1') {
        return;
    }
    input.dataset.wasmImportGuardBound = '1';

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        if (!file) {
            return;
        }
        try {
            const check = await inspectSpreadsheetHeader(file);
            if (!check.valid && typeof input.setCustomValidity === 'function') {
                input.setCustomValidity(check.reason || 'invalid_xlsx_container');
            } else if (typeof input.setCustomValidity === 'function') {
                input.setCustomValidity('');
            }
        } catch {
            // Let server-side validation remain authoritative if browser inspection fails.
        }
    });
}

export function bootErpWasmBridge(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('[data-wasm-pdf-compress]').forEach((element) => {
        if (element.tagName === 'INPUT') {
            wirePdfCompressionInput(element);
        }
    });

    root.querySelectorAll('[data-local-import] input[type="file"]').forEach((element) => {
        wireLocalImportPreGuard(element);
    });

    root.querySelectorAll('[data-erp-delta-sync]').forEach((workspace) => {
        if (workspace.dataset.erpDeltaBound === '1') {
            return;
        }
        workspace.dataset.erpDeltaBound = '1';

        wireReconciliationWorkspace(workspace);

        const trigger = workspace.querySelector('[data-erp-delta-submit]');
        if (!trigger) {
            return;
        }

        trigger.addEventListener('click', async () => {
            if (trigger.disabled) {
                return;
            }
            trigger.disabled = true;
            try {
                await runDeltaSyncWorkspace(workspace);
            } catch (err) {
                const reason = err instanceof Error ? err.message : 'sync_failed';
                const prefix = workspace.dataset.msgErrorPrefix || '';
                renderDeltaStatus(
                    workspace,
                    'error',
                    prefix && !reason.startsWith(prefix) && reason !== workspace.dataset.msgSelectSupplier && reason !== workspace.dataset.msgSelectFile
                        ? `${prefix}: ${reason}`
                        : reason,
                );
                workspace.dispatchEvent(new CustomEvent('adasi:erp-delta-error', {
                    bubbles: true,
                    detail: { reason },
                }));
            } finally {
                trigger.disabled = false;
            }
        });
    });
}

