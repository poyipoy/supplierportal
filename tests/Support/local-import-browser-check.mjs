// Browser QA against real Laravel-rendered test fixtures, with controlled HTTP feeds.
// Backend persistence/concurrency is verified separately by LocalImportProgressTest.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const project = process.cwd();
const fixtures = path.join(project, 'storage/framework/testing/import-ui');
const output = path.join(fixtures, 'browser');
await mkdir(output, { recursive: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let locale = 'en';
let audience = 'finance';
let kind = 'GR';
let status = 'QUEUED';
let phase = 'QUEUED';
let reads = 0;
const progress = () => ({ phase, mode: phase === 'SAVING' ? 'determinate' : 'indeterminate',
    current: phase === 'SAVING' ? 80 : phase === 'FINALIZING' ? 120 : 0,
    total: phase === 'SAVING' || phase === 'FINALIZING' ? 120 : null,
    percent: phase === 'SAVING' ? 66 : null, execution: 'browser-fixture', stale: false });
const payload = () => ({ kind, filename: 'GR Consumable Sept 2026.xls', status, progress: progress(),
    processed_rows: 544, source_rows: 544, failure: null, warnings: [],
    token: null, records_url: '/fixture/records', errors_url: '/fixture/errors', records_total: 0, errors_total: 0,
    confirm_url: '/fixture/confirm', preview: { summary: { source_rows: 544, consolidated_gr: 120, new_gr: 100, existing_gr: 20 }, rows: [], errors: [] } });
const json = (response, data) => { response.writeHead(200, { 'Content-Type': 'application/json' }); response.end(JSON.stringify(data)); };
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname.startsWith('/build/')) {
            const file = path.resolve(project, 'public', url.pathname.slice(1));
            if (!file.startsWith(path.resolve(project, 'public/build') + path.sep)) { response.writeHead(404).end(); return; }
            response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
            response.end(await readFile(file)); return;
        }
        if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/images/')) {
            const file = path.resolve(project, 'public', url.pathname.slice(1));
            if (file.startsWith(path.resolve(project, 'public') + path.sep) && existsSync(file)) {
                response.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png');
                response.end(await readFile(file)); return;
            }
        }
        if (url.pathname.endsWith('/goods-receipts/data')) {
            const feed = JSON.parse(await readFile(path.join(fixtures, `${locale}-${audience}-feed.json`), 'utf8'));
            const filter = url.searchParams.get('status');
            const start = Number(url.searchParams.get('start') || 0);
            const length = Number(url.searchParams.get('length') || 25);
            const rows = filter === 'CANCELLED' ? [] : feed.data;
            json(response, { ...feed, draw: Number(url.searchParams.get('draw') || 0), recordsFiltered: rows.length,
                data: rows.slice(start, start + length).map((row, index) => ({ ...row, DT_RowIndex: start + index + 1, edit_data: row.edit_data ? { ...row.edit_data, update_url: origin + new URL(row.edit_data.update_url).pathname, cancel_url: origin + new URL(row.edit_data.cancel_url).pathname } : null })) }); return;
        }
        if (url.pathname.endsWith('/imports')) {
            json(response, [{ id: 'fixture', kind, filename: payload().filename, status, progress: progress(),
                processed_rows: 544, created_at: '12 Oct 2026 17:00', status_url: '/fixture/status' }]); return;
        }
        if (url.pathname === '/fixture/status') {
            reads++;
            const data = payload();
            if (url.searchParams.has('progress_only')) delete data.preview;
            json(response, data); return;
        }
        if (url.pathname.startsWith('/fixtures/')) {
            response.setHeader('Content-Type', 'text/html; charset=utf-8');
            response.end(await readFile(path.join(fixtures, path.basename(url.pathname)))); return;
        }
        json(response, { count: 0, unread_count: 0, messages: [], notifications: [], data: [], success: true });
    } catch (error) { response.writeHead(500).end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const profile = await mkdtemp(path.join(tmpdir(), 'adasi-import-browser-'));
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath, 'Chrome or Edge is required for browser verification.');
const chrome = spawn(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
let socket;
const browserErrors = [];
let commandId = 0;
const pending = new Map();
const findings = [];
const corePath = path.join(project, 'storage/framework/testing/jquery.dataTables-1.13.6.min.js');
if (!existsSync(corePath)) {
    const response = await fetch('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', { signal: AbortSignal.timeout(15000) });
    assert.equal(response.ok, true);
    await writeFile(corePath, Buffer.from(await response.arrayBuffer()));
}
const dataTablesCore = await readFile(corePath);
assert.equal('sha384-' + createHash('sha384').update(dataTablesCore).digest('base64'), 'sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK');
function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++commandId;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function until(expression, timeout = 25000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) return;
        await sleep(100);
    }
    await screenshot('failure');
    console.log(await evaluate("JSON.stringify({ ready:document.readyState, jq:typeof window.jQuery, dt:typeof window.jQuery?.fn?.dataTable, initialized:document.querySelector('[data-gr-table]')?.dataset.initialized, first:document.querySelector('#poGoodsReceiptsTable tbody tr td')?.textContent, text:document.body.textContent.slice(-400) })"));
    throw new Error(`Browser condition timed out: ${expression}; errors: ${JSON.stringify(browserErrors)}`);
}
async function screenshot(name) {
    const shot = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(path.join(output, `${name}.png`), Buffer.from(shot.data, 'base64'));
}
try {
    let port;
    for (let attempt = 0; attempt < 100; attempt++) {
        try { port = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); break; } catch { await sleep(100); }
    }
    assert.ok(port, 'Headless browser did not start.');
    const target = await (await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    socket.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Fetch.requestPaused') {
            command('Fetch.fulfillRequest', { requestId: data.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/javascript' }, { name: 'Access-Control-Allow-Origin', value: '*' }], body: dataTablesCore.toString('base64') }).catch(error => browserErrors.push(String(error)));
            return;
        }
        if (data.method === 'Runtime.exceptionThrown') browserErrors.push(data.params.exceptionDetails.exception?.description ?? data.params.exceptionDetails.text);
        if (!pending.has(data.id)) return;
        const task = pending.get(data.id); pending.delete(data.id);
        data.error ? task.reject(new Error(data.error.message)) : task.resolve(data.result);
    });
    await command('Fetch.enable', { patterns: [{ urlPattern: 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js' }] });
    await command('Page.enable'); await command('Runtime.enable'); await command('Accessibility.enable');
    for (locale of ['en', 'id']) {
        audience = 'finance';
        for (const theme of (process.env.LOCAL_IMPORT_BROWSER_TABLES_ONLY === '1' ? [] : ['light', 'dark'])) {
            for (const width of [375, 768, 1440]) {
                status = 'QUEUED'; phase = 'QUEUED'; kind = 'GR';
                await command('Emulation.setDeviceMetricsOverride', { width, height: 950, deviceScaleFactor: 1, mobile: false });
                await command('Page.navigate', { url: `${origin}/fixtures/${locale}-imports.html` });
                await until("document.querySelector('[data-local-import=GR]')?.dataset.initialized === 'true' && Boolean(window.bootstrap?.Modal)");
                await evaluate(`document.documentElement.dataset.theme=${JSON.stringify(theme)}; window.bootstrap.Modal.getOrCreateInstance(document.getElementById('localGrImportModal')).show()`);
                await until("Boolean(document.querySelector('#localGrImportModal [data-recovery-list] button'))");
                await evaluate("document.querySelector('#localGrImportModal [data-recovery-list] button').click()");
                await until("Boolean(document.querySelector('#localGrImportModal [role=progressbar]'))");
                const queued = await evaluate("document.querySelector('#localGrImportModal .modal-header [role=progressbar]').hasAttribute('aria-valuenow')");
                assert.equal(queued, false);
                status = 'IMPORTING'; phase = 'SAVING';
                await until("document.querySelector('#localGrImportModal .modal-header [role=progressbar]')?.getAttribute('aria-valuenow') === '66'");
                assert.equal(await evaluate("document.getElementById('localGrImportProgressLabel').textContent"), locale === 'en' ? 'Saving records' : 'Menyimpan record');
                const layout = await evaluate(`(() => { const modal=document.querySelector('#localGrImportModal .modal-content'); const rect=modal.getBoundingClientRect(); return { left:rect.left, right:rect.right, viewport:innerWidth, overflow:modal.querySelector('.modal-body').scrollWidth>modal.querySelector('.modal-body').clientWidth+1, locale:document.documentElement.lang, hiddenInput:document.querySelector('#localGrImportModal [data-import-file-picker]').hidden }; })()`);
                assert.ok(layout.left >= 0 && layout.right <= layout.viewport + 1, JSON.stringify(layout));
                assert.equal(layout.overflow, false);
                assert.equal(layout.hiddenInput, true);
                assert.equal(await evaluate("/js\\.local_import\\./.test(document.querySelector('#localGrImportModal .modal-header').textContent)"), false);
                assert.equal(layout.locale, locale);
                const ax = await command('Accessibility.getFullAXTree');
                assert.ok(ax.nodes.some(node => node.role?.value === 'progressbar' && node.name?.value && node.value?.value === 66));
                const contrast = await evaluate(`(() => {
                    const panel=document.querySelector('#localGrImportModal .modal-header section');
                    const foreground=getComputedStyle(panel.querySelector('p')).color;
                    const background=getComputedStyle(panel).backgroundColor;
                    const luminance=color => { const rgb=color.match(/[0-9.]+/g).slice(0,3).map(Number).map(c => { c/=255; return c<=0.04045 ? c/12.92 : Math.pow((c+0.055)/1.055,2.4); }); return rgb[0]*0.2126+rgb[1]*0.7152+rgb[2]*0.0722; };
                    const a=luminance(foreground), b=luminance(background);
                    return (Math.max(a,b)+0.05)/(Math.min(a,b)+0.05);
                })()`);
                assert.ok(contrast >= 4.5, `Normal text contrast was ${contrast}`);
                for (let tab=0; tab<6; tab++) {
                    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
                    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
                    assert.ok(await evaluate("document.getElementById('localGrImportModal').contains(document.activeElement)"));
                }
                await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
                phase = 'FINALIZING';
                await until("!document.querySelector('#localGrImportModal .modal-header [role=progressbar]').hasAttribute('aria-valuenow')");
                assert.equal(await evaluate("getComputedStyle(document.querySelector('#localGrImportModal .modal-header .local-import-progress-fill')).animationName"), 'none');
                assert.equal(await evaluate("document.getElementById('localGrImportProgressLabel').textContent"), locale === 'en' ? 'Finalizing the import' : 'Menyelesaikan penyimpanan');
                await screenshot(`${locale}-${theme}-${width}-gr-progress`);
                await command('Emulation.setEmulatedMedia', { features: [] });
                await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
                await until("!document.getElementById('localGrImportModal').classList.contains('show')");
                findings.push({ area: 'GR modal', locale, theme, width, queuedUnknown: true, savingPercent: 66, noOverflow: true, accessibleProgress: true, reducedMotion: true, escape: true });
                console.log(`PASS modal ${locale} ${theme} ${width}`);
                if (width === 1440) {
                    kind = 'PO'; status = 'QUEUED'; phase = 'QUEUED';
                    await evaluate("window.bootstrap.Modal.getOrCreateInstance(document.getElementById('localPoImportModal')).show()");
                    await until("Boolean(document.querySelector('#localPoImportModal [data-recovery-list] button'))");
                    await evaluate("document.querySelector('#localPoImportModal [data-recovery-list] button').click()");
                    await until("Boolean(document.querySelector('#localPoImportModal .modal-header [role=progressbar]'))");
                    assert.equal(await evaluate("document.querySelector('#localPoImportModal .modal-header [role=progressbar]').hasAttribute('aria-valuenow')"), false);
                    status = 'IMPORTING'; phase = 'SAVING';
                    await until("document.querySelector('#localPoImportModal .modal-header [role=progressbar]')?.getAttribute('aria-valuenow') === '66'");
                    assert.ok(await evaluate("document.querySelector('#localPoImportModal .modal-header').textContent.includes('120 PO')"));
                    await screenshot(`${locale}-${theme}-po-progress`);
                    await command('Emulation.setDeviceMetricsOverride', { width: 360, height: 237, deviceScaleFactor: 1, mobile: false });
                    const reflow = await evaluate("(() => { const content=document.querySelector('#localPoImportModal .modal-content'); return { overflowX:content.scrollWidth>content.clientWidth+1, scrollable:getComputedStyle(content).overflowY, maxHeight:content.clientHeight }; })()");
                    assert.equal(reflow.overflowX, false);
                    assert.equal(reflow.scrollable, 'auto');
                    assert.ok(reflow.maxHeight <= 237);
                    await screenshot(`${locale}-${theme}-400-percent-reflow`);
                    await evaluate("window.bootstrap.Modal.getOrCreateInstance(document.getElementById('localPoImportModal')).hide()");
                    await until("!document.getElementById('localPoImportModal').classList.contains('show')");
                    findings.push({ area:'PO modal', locale, theme, width, queuedUnknown:true, savingPercent:66, reflow400:true });
                    console.log(`PASS PO modal ${locale} ${theme}, including 400% reflow`);
                }
            }
        }
        for (audience of ['finance', 'purchasing', 'supplier']) {
            await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 950, deviceScaleFactor: 1, mobile: false });
            await command('Page.navigate', { url: `${origin}/fixtures/${locale}-${audience}-detail.html` });
            await until("document.querySelector('[data-gr-table]')?.dataset.initialized === 'true' && Boolean(window.jQuery('#poGoodsReceiptsTable').DataTable().ajax.json()) && window.jQuery('#poGoodsReceiptsTable').DataTable().settings()[0]._bInitComplete && document.querySelector('#poGoodsReceiptsTable tbody tr td')?.textContent.trim() === '1'");
            await evaluate("window.jQuery('#poGoodsReceiptsTable').DataTable().page(1).draw('page')");
            await until("document.querySelector('#poGoodsReceiptsTable tbody tr td')?.textContent.trim() === '26'");
            if (audience !== 'supplier') {
                await evaluate("document.querySelector('[data-gr-edit]').click()");
                await until("document.getElementById('editPoGrModal').classList.contains('show') && !window.bootstrap.Modal.getInstance(document.getElementById('editPoGrModal'))._isTransitioning");
                assert.ok(await evaluate("document.querySelector('[data-gr-edit-form] [name=gr_number]').value.startsWith('UI-GR-')"));
                assert.equal(await evaluate("document.querySelector('[data-gr-edit-form] [name=qty]').value"), '1.125');
                assert.ok(await evaluate("document.querySelector('[data-gr-edit-form]').action.includes('/goods-receipts/')"));
                await evaluate("window.bootstrap.Modal.getOrCreateInstance(document.getElementById('editPoGrModal')).hide()");
                await until("!document.getElementById('editPoGrModal').classList.contains('show')");
                await until("document.activeElement?.hasAttribute('data-gr-edit')");
                await evaluate("document.querySelector('[data-gr-cancel]').click()");
                await until("document.getElementById('cancelPoGrModal').classList.contains('show') && !window.bootstrap.Modal.getInstance(document.getElementById('cancelPoGrModal'))._isTransitioning");
                assert.ok(await evaluate("document.querySelector('[data-gr-cancel-form]').action.includes('/cancel')"));
                await evaluate("window.bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelPoGrModal')).hide()");
                await until("getComputedStyle(document.getElementById('cancelPoGrModal')).display === 'none'");
            } else {
                assert.equal(await evaluate("document.querySelectorAll('[data-gr-edit], [data-gr-cancel]').length"), 0);
            }
            await screenshot(`${locale}-${audience}-gr-page2`);
            findings.push({ area: 'GR table', locale, audience, page2Number: 26, roleActions: true });
            console.log(`PASS table ${locale} ${audience}`);
        }
    }
    await writeFile(path.join(output, 'result.json'), JSON.stringify({ findings, statusReads: reads }, null, 2));
    console.log(`${findings.length} browser scenarios passed; screenshots: ${output}`);
} finally {
    socket?.close(); chrome.kill(); server.close();
    await sleep(500);
    assert.ok(path.resolve(profile).startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(profile).startsWith('adasi-import-browser-'));
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }).catch(() => {});
}
