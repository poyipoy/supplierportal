import init, {
    sanitize_and_compress_pdf,
    parse_and_filter_erp_excel,
} from '../../wasm/portal_wasm.js?v=20261113c';

const WASM_ASSET_VERSION = '20261113c';
const MAX_WASM_INPUT_BYTES = 20 * 1024 * 1024;
const DEFAULT_MAX_DIM = 1600;

let wasmReady = null;

async function ensureWasmReady() {
    if (!wasmReady) {
        const wasmUrl = new URL(`../../wasm/portal_wasm_bg.wasm?v=${WASM_ASSET_VERSION}`, import.meta.url);
        wasmReady = init({ module_or_path: wasmUrl }).catch((err) => {
            wasmReady = null;
            throw err;
        });
    }
    return wasmReady;
}

function isArrayBufferLike(value) {
    return (
        value instanceof ArrayBuffer ||
        Object.prototype.toString.call(value) === '[object ArrayBuffer]'
    );
}

function validateInputBuffer(payload) {
    if (!payload || typeof payload !== 'object') {
        throw new Error('Payload worker tidak valid.');
    }
    const { buffer } = payload;
    if (!isArrayBufferLike(buffer)) {
        throw new Error('Payload buffer harus berupa ArrayBuffer (Transferable Object).');
    }
    if (buffer.byteLength === 0) {
        throw new Error('Buffer file kosong atau sudah di-detach.');
    }
    if (buffer.byteLength > MAX_WASM_INPUT_BYTES) {
        throw new Error('Ukuran file melampaui batas aman memori (20 MB).');
    }
    return new Uint8Array(buffer);
}

self.onmessage = async (event) => {
    const data = event && event.data && typeof event.data === 'object' ? event.data : {};
    const { id, action, payload } = data;

    try {
        const inputBytes = validateInputBuffer(payload);
        await ensureWasmReady();

        if (action === 'COMPRESS_PDF') {
            const maxDim =
                typeof payload.maxDim === 'number' && Number.isFinite(payload.maxDim)
                    ? Math.trunc(payload.maxDim)
                    : DEFAULT_MAX_DIM;

            const compressed = sanitize_and_compress_pdf(inputBytes, maxDim);
            const outBuffer =
                compressed.byteOffset === 0 && compressed.byteLength === compressed.buffer.byteLength
                    ? compressed.buffer
                    : compressed.slice().buffer;

            self.postMessage({ id, ok: true, buffer: outBuffer }, [outBuffer]);
            return;
        }

        if (action === 'PARSE_DELTA_EXCEL') {
            const knownHashesJson =
                payload.knownHashesJson === undefined ? '[]' : payload.knownHashesJson;
            if (typeof knownHashesJson !== 'string') {
                throw new Error('Daftar hash referensi harus berupa string JSON.');
            }

            const deltaJson = parse_and_filter_erp_excel(inputBytes, knownHashesJson);
            self.postMessage({ id, ok: true, deltaJson });
            return;
        }

        throw new Error(`Aksi worker tidak dikenal: ${String(action)}`);
    } catch (err) {
        self.postMessage({
            id,
            ok: false,
            error:
                typeof err === 'string'
                    ? err
                    : err && err.message
                      ? err.message
                      : 'Kesalahan pemrosesan WASM',
        });
    }
};

