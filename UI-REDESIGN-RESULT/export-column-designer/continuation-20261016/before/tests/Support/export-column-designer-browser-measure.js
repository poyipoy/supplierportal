// Execute as a DevTools function on an existing Tier-1 page. Metadata-only
// fixtures isolate UI computation from PHP latency and the search rate limit.
async () => {
    const root = document.querySelector('[data-advanced-export]');
    if (document.visibilityState !== 'visible') throw new Error('Bring this measured tab to the foreground first.');
    const modal = root.querySelector('.modal');
    const fixture = window.__designerMeasure;
    if (!fixture) throw new Error('Fetch and retain the real PO/quotation metadata first.');
    const size = window.__designerMeasureSize || 12;
    const batch = window.__designerMeasureBatch || 0;
    const definition = structuredClone(size === 24 ? fixture.quotes : fixture.po);
    definition.export_key = root.dataset.exportKey;
    if (size === 64) definition.columns = Array.from({ length: 64 }, (_, i) => ({ key: `column_${i}`, label: `Column ${i + 1}`, default: true, required: i === 0 }));
    const count = definition.columns.length;
    const modern = Boolean(root.querySelector('[data-column-designer]'));
    const expectedHeaders = modern ? definition.columns.filter((column) => column.default).length : count;
    const requests = [];
    const originalFetch = window.fetch;
    window.fetch = (url, init) => {
        const href = String(url);
        if (href === root.dataset.definitionUrl || href.startsWith(root.dataset.presetsUrl + '?')) {
            requests.push(href);
            return Promise.resolve(new Response(JSON.stringify(href === root.dataset.definitionUrl ? definition : { data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }
        return originalFetch(url, init);
    };
    const frame = () => new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('No paint frame: inspect tab visibility before retrying.')), 1500);
        requestAnimationFrame(() => { clearTimeout(timeout); resolve(); });
    });
    const paint = async () => { await frame(); await frame(); };
    const once = (type) => new Promise((resolve) => modal.addEventListener(type, resolve, { once: true }));
    const instance = bootstrap.Modal.getOrCreateInstance(modal);
    if (modal.classList.contains('show')) { const hidden = once('hidden.bs.modal'); instance.hide(); await hidden; }
    const faded = modal.classList.contains('fade');
    modal.classList.remove('fade');
    const records = [];
    try {
        for (let iteration = -3; iteration < 30; iteration += 1) {
            const start = performance.now();
            const shown = once('shown.bs.modal');
            root.querySelector('[data-export-open]').click();
            await shown;
            let limit = 0;
            while (root.querySelector('[data-export-submit]').disabled || root.querySelectorAll('[data-export-column]').length !== expectedHeaders) {
                if (++limit > 300) throw new Error('Modal did not become ready.');
                await frame();
            }
            const ready = performance.now();
            await paint();
            const painted = performance.now();
            const key = definition.columns[1].key;
            const header = [...root.querySelectorAll('[data-export-column]')].find((el) => el.dataset.key === key);
            if (modern) header.click();
            const commandStart = performance.now();
            (modern ? root.querySelector('[data-column-command="move-right"]') : header.querySelector('[data-column-down]')).click();
            const computed = performance.now();
            await paint();
            const commandPainted = performance.now();
            if (iteration >= 0) records.push({ ready: ready - start, paint: painted - ready, command: computed - commandStart, commandPaint: commandPainted - commandStart, columnNodes: root.querySelector('[data-export-columns]').querySelectorAll('*').length, modalNodes: modal.querySelectorAll('*').length });
            const hidden = once('hidden.bs.modal'); instance.hide(); await hidden;
        }
    } finally {
        if (faded) modal.classList.add('fade');
        window.fetch = originalFetch;
    }
    const p95 = (key) => records.map((r) => r[key]).sort((a, b) => a - b)[Math.ceil(records.length * .95) - 1];
    const result = { size, batch, viewport: [innerWidth, innerHeight], records, p95: Object.fromEntries(['ready', 'paint', 'command', 'commandPaint'].map((k) => [k, p95(k)])), metadataRequests: requests.length, expectedMetadataRequests: 66, closed: !modal.classList.contains('show'), backdrops: document.querySelectorAll('.modal-backdrop').length, openLayers: root.querySelectorAll('[data-column-layer]:not([hidden])').length };
    fixture.runs.push(result);
    return result;
}
