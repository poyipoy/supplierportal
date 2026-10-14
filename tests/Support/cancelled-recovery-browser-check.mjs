// Real Laravel-rendered fixtures and built assets; HTTP persistence is tested separately.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const project = process.cwd();
const fixtures = path.join(project, 'storage/framework/testing/cancelled-recovery-ui');
const output = path.join(fixtures, 'browser');
await mkdir(output, { recursive: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let locale = 'en';
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname.endsWith('/goods-receipts/data')) {
            const feed = JSON.parse(await readFile(path.join(fixtures, `${locale}-feed.json`), 'utf8'));
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ ...feed, draw: Number(url.searchParams.get('draw') || 0),
                data: feed.data.map(row => ({ ...row, actions: row.actions.replace(/https?:\/\/[^/]+/g, origin) })) })); return;
        }
        if (url.pathname.startsWith('/fixtures/')) {
            response.setHeader('Content-Type', 'text/html');
            const manifest = JSON.parse(await readFile(path.join(project, 'public/build/manifest.json'), 'utf8'));
            const html = await readFile(path.join(fixtures, path.basename(url.pathname)), 'utf8');
            response.end(html.replace(/\/build\/assets\/app-[\w-]+\.js/g, '/build/'+manifest['resources/js/app.js'].file)); return;
        }
        const file = path.resolve(project, 'public', url.pathname.slice(1));
        if (file.startsWith(path.resolve(project, 'public') + path.sep) && existsSync(file)) {
            response.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'image/png');
            response.end(await readFile(file)); return;
        }
        response.writeHead(404).end();
    } catch (error) { response.writeHead(500).end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const profile = await mkdtemp(path.join(tmpdir(), 'adasi-recovery-browser-'));
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath);
const chrome = spawn(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
let socket;
const errors = [];
let sequence = 0;
const pending = new Map();
const findings = [];
const core = await readFile(path.join(project, 'storage/framework/testing/jquery.dataTables-1.13.6.min.js'));
assert.equal('sha384-' + createHash('sha384').update(core).digest('base64'), 'sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK');
const vendor = new Map([['https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', {body:core,type:'application/javascript'}]]);
function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function until(expression) {
    const deadline = Date.now() + 25000;
    while (Date.now() < deadline) { if (await evaluate(expression)) return; await sleep(100); }
    console.log(await evaluate("JSON.stringify({ready:document.readyState,url:location.href,bootstrap:typeof window.bootstrap,jq:typeof window.jQuery,dt:typeof window.jQuery?.fn?.dataTable,table:document.querySelector('[data-gr-table]')?.dataset,resources:performance.getEntriesByType('resource').map(r=>r.name)})"));
    throw new Error(`Timed out: ${expression}; ${JSON.stringify(errors)}`);
}
async function key(name, code) {
    for (const type of ['keyDown', 'keyUp']) await command('Input.dispatchKeyEvent', { type, key: name, code: name, windowsVirtualKeyCode: code });
}
try {
    const fixtureHtml = await readFile(path.join(fixtures, 'en-po.html'), 'utf8');
    const cache = path.join(fixtures, 'vendor');
    await mkdir(cache, {recursive:true});
    for (const match of fixtureHtml.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="(https:\/\/[^" ]+)"[^>]*integrity="(sha384-[^"]+)"[^>]*>/g)) {
        const [,url,integrity] = match;
        if (vendor.has(url)) continue;
        const file = path.join(cache, createHash('sha256').update(url).digest('hex'));
        if (!existsSync(file)) {
            const response = await fetch(url,{signal:AbortSignal.timeout(20000)});
            assert.ok(response.ok,url);
            await writeFile(file,Buffer.from(await response.arrayBuffer()));
        }
        const body = await readFile(file);
        assert.equal('sha384-'+createHash('sha384').update(body).digest('base64'),integrity,url);
        vendor.set(url,{body,type:url.includes('.css')?'text/css':'application/javascript'});
    }
    let port;
    for (let i = 0; i < 100; i++) {
        try { port = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); break; } catch { await sleep(100); }
    }
    assert.ok(port);
    const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    socket.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Fetch.requestPaused') {
            const asset = vendor.get(data.params.request.url);
            if (!asset) { command('Fetch.continueRequest',{requestId:data.params.requestId}); return; }
            command('Fetch.fulfillRequest', { requestId: data.params.requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: asset.type }, { name: 'Access-Control-Allow-Origin', value: '*' }], body: asset.body.toString('base64') }).catch(error => errors.push(String(error))); return;
        }
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description ?? data.params.exceptionDetails.text);
        if (pending.has(data.id)) { const task = pending.get(data.id); pending.delete(data.id); data.error ? task.reject(new Error(data.error.message)) : task.resolve(data.result); }
    });
    await command('Fetch.enable', { patterns: [...vendor.keys()].map(url=>({urlPattern:url})) });
    await command('Page.enable'); await command('Runtime.enable'); await command('Accessibility.enable');
    for (locale of ['en', 'id']) {
        for (const theme of ['light', 'dark']) {
            for (const kind of ['po', 'gr']) {
                const id = kind === 'po' ? 'restorePoModal' : 'restorePoGrModal';
                await command('Emulation.setDeviceMetricsOverride', { width: 375, height: 850, deviceScaleFactor: 1, mobile: false });
                await command('Page.navigate', { url: `${origin}/fixtures/${locale}-${kind}.html` });
                await until("Boolean(window.bootstrap?.Modal) && document.querySelector('[data-gr-table]')?.dataset.initialized === 'true'");
                if (kind === 'gr') await until("Boolean(document.querySelector('[data-gr-restore]'))");
                const selector = kind === 'po' ? '[data-bs-target="#restorePoModal"]' : '[data-gr-restore]';
                await evaluate(`document.documentElement.dataset.theme=${JSON.stringify(theme)}; document.querySelector(${JSON.stringify(selector)}).click()`);
                await until(`document.activeElement?.id === '${id}Reason'`);
                const layout = await evaluate(`(() => { const m=document.querySelector('#${id} .modal-content'); const r=m.getBoundingClientRect(); return {left:r.left,right:r.right,width:innerWidth,overflow:m.scrollWidth>m.clientWidth+1}; })()`);
                assert.ok(layout.left >= 0 && layout.right <= layout.width + 1 && !layout.overflow, JSON.stringify(layout));
                assert.equal(await evaluate(`document.querySelector('#${id} form').checkValidity()`), false);
                const ax = await command('Accessibility.getFullAXTree');
                assert.ok(ax.nodes.some(node => node.role?.value === 'dialog' && node.name?.value));
                for (let i = 0; i < 6; i++) {
                    await key('Tab', 9);
                    const focus = await evaluate(`({inside:document.getElementById('${id}').contains(document.activeElement),tag:document.activeElement.tagName,id:document.activeElement.id,html:document.activeElement.outerHTML.slice(0,200)})`);
                    assert.ok(focus.inside, JSON.stringify({kind,i,focus}));
                }
                await evaluate(`document.querySelector('#${id} [name=reason]').value='Accidental portal cancellation'; document.querySelector('#${id} [name=business_document_valid]').checked=true`);
                assert.equal(await evaluate(`document.querySelector('#${id} form').checkValidity()`), true);
                const contrast = await evaluate(`(() => { const p=document.querySelector('#${id} .modal-body p:nth-child(2)'); const lum=c=>{const a=c.match(/[0-9.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return a[0]*.2126+a[1]*.7152+a[2]*.0722}; const f=lum(getComputedStyle(p).color), b=lum(getComputedStyle(document.querySelector('#${id} .modal-content')).backgroundColor); return (Math.max(f,b)+.05)/(Math.min(f,b)+.05)})()`);
                assert.ok(contrast >= 4.5, String(contrast));
                await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
                const shot = await command('Page.captureScreenshot', { format: 'png' });
                await writeFile(path.join(output, `${locale}-${theme}-${kind}.png`), Buffer.from(shot.data, 'base64'));
                await command('Emulation.setDeviceMetricsOverride', {width:360,height:237,deviceScaleFactor:1,mobile:false});
                const reflow = await evaluate(`(() => { const m=document.querySelector('#${id} .modal-content'); const r=m.getBoundingClientRect();return {overflow:m.scrollWidth>m.clientWidth+1,height:r.height,bottom:r.bottom,viewport:innerHeight};})()`);
                assert.ok(!reflow.overflow && reflow.height<=reflow.viewport && reflow.bottom<=reflow.viewport+1,JSON.stringify(reflow));
                await key('Escape', 27);
                await until(`!document.getElementById('${id}').classList.contains('show')`);
                await until(kind === 'po' ? "document.activeElement?.getAttribute('data-bs-target') === '#restorePoModal'" : "document.activeElement?.hasAttribute('data-gr-restore') || document.activeElement?.id === 'poGrStatusFilter'");
                findings.push({ locale, theme, kind, keyboard: true, focusReturn: true, invalidBlocked: true, contrast,reflow400:true });
                console.log(`PASS ${locale} ${theme} ${kind}`);
            }
        }
        await command('Page.navigate', { url: `${origin}/fixtures/${locale}-invoice.html` });
        await until("Boolean(document.getElementById('invoiceRecoveryReason')) && Boolean(window.Alpine)");
        assert.equal(await evaluate("document.getElementById('invoiceRecoveryReason').required"), true);
        assert.equal(await evaluate("document.getElementById('invoiceBusinessDocumentValid').required"), true);
        assert.equal(await evaluate("document.querySelectorAll('#localInvoiceForm input[name=\"kept_document_ids[]\"]').length"), 2);
        await evaluate("document.getElementById('invoiceRecoveryReason').dispatchEvent(new CustomEvent('adasi:reveal-field',{bubbles:true,detail:{element:document.getElementById('invoiceRecoveryReason'),key:'recovery_reason'}}))");
        await until("document.getElementById('invoiceRecoveryReason').getBoundingClientRect().height>0");
        findings.push({ locale, kind: 'invoice', recoveryReason: true, confirmation: true, asyncReveal: true });
        console.log(`PASS ${locale} invoice fields`);
    }
    if (errors.length) console.log(await evaluate("JSON.stringify([...document.querySelectorAll('template')].filter(t=>!t.content.firstElementChild).map(t=>({for:t.getAttribute('x-for'),if:t.getAttribute('x-if'),html:t.outerHTML.slice(0,300)})))"));
    assert.equal(errors.length, 0, JSON.stringify(errors));
    await writeFile(path.join(output, 'result.json'), JSON.stringify({ findings }, null, 2));
    console.log(`${findings.length} recovery browser scenarios passed`);
} finally {
    socket?.close(); chrome.kill(); server.close(); await sleep(500);
    assert.ok(path.resolve(profile).startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(profile).startsWith('adasi-recovery-browser-'));
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }).catch(() => {});
}
