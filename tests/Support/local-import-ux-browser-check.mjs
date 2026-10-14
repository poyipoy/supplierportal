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
const fixtures = path.join(project, 'storage/framework/testing/import-ui');
const output = path.join(fixtures, 'ux-browser');
await mkdir(output, { recursive: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let locale = 'en';
let kind='GR', status='QUEUED', attempt=1, rechecks=0, saves=0;
const payload=()=>{const failed=status==='VALIDATION_FAILED';const ready=status==='READY';const completed=status==='COMPLETED';const summary={source_rows:2,consolidated_gr:2,unique_pos:2,new_po:1,new_gr:1,existing_po:1,existing_gr:1,invalid:failed?1:0,newPo:1,newGr:1,existingPo:1,existingGr:1};return {kind,status,filename:'Infor Test.xlsx',validation_attempt:attempt,can_revalidate:failed||ready,revalidate_url:'/fixture/revalidate',expires_at:'2026-10-14T00:00:00Z',processed_rows:2,source_rows:2,token:ready?'token-'+attempt:null,confirm_url:'/fixture/confirm',results_url:completed?'/fixture/results':null,progress:{phase:status==='IMPORTING'?'SAVING':status,mode:status==='IMPORTING'?'determinate':['QUEUED','READING','VALIDATING'].includes(status)?'indeterminate':'none',percent:status==='IMPORTING'?50:null,current:1,total:status==='IMPORTING'?2:null},failure:null,records_url:'/fixture/records',records_total:2,errors_url:'/fixture/errors',errors_total:failed?1:0,preview:{summary,rows:[{_row:2,gr_number:'GR-001',po_number:'PNR26091',supplier_name:'Test Vendor',po_date:'2026-10-01',gr_date:'2026-10-13',qty:'5',uom:'rol',source_rows_count:1,action_label:failed?'Unmatched PO':'New '+kind}],errors:failed?[{row:3,column:'po_number',message:locale==='id'?'PO PNR26091 belum terdaftar.':'PO PNR26091 is not registered.',code:'PO_NOT_FOUND',context:{po_number:'PNR26091'},action:{url:'/fixture/master',label:locale==='id'?'Buka daftar PO':'Open PO register'}}]:[]}};};
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://127.0.0.1');
        const send=data=>{response.setHeader('Content-Type','application/json');response.end(JSON.stringify(data));};
        if (url.pathname.endsWith('/imports')) {send([{kind,filename:'Infor Test.xlsx',status,status_url:'/fixture/status',can_revalidate:payload().can_revalidate,validation_attempt:attempt,created_at:'13 Oct 2026 06:39',processed_rows:2},{kind,filename:'Needs review.xlsx',status:'VALIDATION_FAILED',can_revalidate:true,status_url:'/fixture/other',validation_attempt:1,created_at:'13 Oct 2026 06:38',processed_rows:1}]);return;}
        if (url.pathname==='/fixture/status') {const data=payload();if(url.searchParams.has('progress_only'))delete data.preview;send(data);return;}
        if (url.pathname==='/fixture/revalidate') {const parts=[];for await(const part of request)parts.push(part);const form=new URLSearchParams(Buffer.concat(parts).toString());assert.equal(form.get('expected_attempt'),String(attempt));rechecks++;attempt++;status='QUEUED';send({status_url:'/fixture/status',status,validation_attempt:attempt});return;}
        if (url.pathname==='/fixture/confirm') {saves++;status='IMPORTING';send({status_url:'/fixture/status',status});return;}
        if (url.pathname==='/fixture/errors') {send({data:payload().preview.errors,total:1,current_page:1,last_page:1});return;}
        if (url.pathname==='/fixture/records') {send({data:payload().preview.rows,total:2,current_page:1,last_page:1});return;}
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
            response.end(html.replace(/\/build\/assets\/app-[\w-]+\.js/g, '/build/'+manifest['resources/js/app.js'].file)
                .replace(/\/build\/assets\/app-[\w-]+\.css/g,'/build/'+manifest['resources/css/app.css'].file)); return;
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
const profile = await mkdtemp(path.join(tmpdir(), 'adasi-import-ux-browser-'));
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
async function contrast(selector) {
    return evaluate(`(()=>{const node=document.querySelector(${JSON.stringify(selector)});const lum=c=>{const a=c.match(/[0-9.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return a[0]*.2126+a[1]*.7152+a[2]*.0722};let bg=node;while(bg&&getComputedStyle(bg).backgroundColor==='rgba(0, 0, 0, 0)')bg=bg.parentElement;const f=lum(getComputedStyle(node).color),b=lum(getComputedStyle(bg).backgroundColor);return (Math.max(f,b)+.05)/(Math.min(f,b)+.05)})()`);
}
try {
    const fixtureHtml = await readFile(path.join(fixtures, 'en-imports.html'), 'utf8');
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
    for (locale of ['en','id']) for (const theme of ['light','dark']) for (const width of (process.env.IMPORT_UX_QA_COMPACT==='1'?[375]:[375,1440])) for (kind of ['PO','GR']) {
        status='QUEUED';attempt=1;rechecks=saves=0;
        const name=kind==='PO'?'Po':'Gr',id='local'+name+'ImportModal';
        await command('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:false});
        await command('Page.navigate',{url:`${origin}/fixtures/${locale}-imports.html`});
        await until(`document.querySelector('[data-local-import=${kind}]')?.dataset.initialized === 'true' && Boolean(window.bootstrap?.Modal)`);
        await evaluate(`document.documentElement.dataset.theme=${JSON.stringify(theme)};window.bootstrap.Modal.getOrCreateInstance(document.getElementById('${id}')).show()`);
        await until(`document.querySelector('#${id} [data-recovery-processing-list] button')`);
        assert.equal(await evaluate(`document.querySelector('#${id} [data-recovery-slots]').textContent`),locale==='en'?'1 of 2 import slots in use':'1 dari 2 slot import terpakai');
        await evaluate(`document.querySelector('#${id} [data-recovery-processing-list] button').click()`);
        await until(`document.querySelector('#${id} [role=progressbar]')`);
        assert.equal(await evaluate(`document.querySelector('#${id} [role=progressbar]').hasAttribute('aria-valuenow')`),false);
        status='VALIDATION_FAILED';
        await until(`document.getElementById('local${name}ImportSummaryTitle').textContent === ${JSON.stringify(locale==='en'?'Data needs correction':'Data perlu diperbaiki')}`);
        await until(`document.activeElement.id==='local${name}ImportReviewSummary'`);
        assert.equal(await evaluate(`document.querySelector('#${id} .modal-footer').textContent.includes(${JSON.stringify(locale==='en'?'Recheck stored file':'Periksa ulang file tersimpan')})`),true);
        assert.equal(await evaluate(`document.getElementById('btnConfirmLocal${name}Import').disabled`),true);
        const link=await evaluate(`(()=>{const a=document.querySelector('#local${name}ImportErrorsList a');return {url:a.href,target:a.target,rel:a.rel,text:a.textContent}})()`);
        assert.equal(link.target,'_blank');assert.ok(link.rel.includes('noopener'));assert.ok(link.text.includes(locale==='en'?'new tab':'tab baru'));
        const layout=await evaluate(`(()=>{const m=document.querySelector('#${id} .modal-content');const r=m.getBoundingClientRect();return {left:r.left,right:r.right,viewport:innerWidth,overflow:m.scrollWidth>m.clientWidth+1}})()`);
        assert.ok(layout.left>=0&&layout.right<=layout.viewport+1&&!layout.overflow,JSON.stringify(layout));
        const ax=await command('Accessibility.getFullAXTree');assert.ok(ax.nodes.some(n=>n.role?.value==='dialog'&&n.name?.value));
        for(let i=0;i<8;i++){await key('Tab',9);assert.ok(await evaluate(`document.getElementById('${id}').contains(document.activeElement)`));}
        await evaluate(`document.getElementById('local${name}ImportReviewSummary').scrollIntoView({block:'start'})`);
        const textContrast=await contrast('#local'+name+'ImportSummaryMessage');
        const tableContrast=await contrast('#local'+name+'ImportPreviewBody td');
        assert.ok(textContrast>=4.5&&tableContrast>=4.5,JSON.stringify({theme,textContrast,tableContrast}));
        const shot=await command('Page.captureScreenshot',{format:'png'});await writeFile(path.join(output,`${locale}-${theme}-${width}-${kind}-error.png`),Buffer.from(shot.data,'base64'));
        await command('Emulation.setDeviceMetricsOverride',{width:360,height:237,deviceScaleFactor:1,mobile:false});
        assert.equal(await evaluate(`(()=>{const m=document.querySelector('#${id} .modal-content');return m.scrollWidth>m.clientWidth+1})()`),false);
        await command('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:false});
        await evaluate(`document.getElementById('btnRevalidateLocal${name}Import').click()`);
        await until(`document.getElementById('local${name}ImportToken').value==='' && document.getElementById('btnRevalidateLocal${name}Import').disabled`);
        assert.equal(rechecks,1);assert.equal(saves,0);
        status='READY';
        await until(`document.getElementById('local${name}ImportToken').value==='token-2'`);
        await until(`!document.getElementById('btnRevalidateLocal${name}Import').disabled && !document.getElementById('btnConfirmLocal${name}Import').disabled`);
        assert.equal(await evaluate(`document.getElementById('btnConfirmLocal${name}Import').disabled`),false);
        await evaluate(`document.getElementById('local${name}ImportConfirmForm').requestSubmit()`);
        await until(`document.querySelector('#${id} [role=progressbar]')?.getAttribute('aria-valuenow')==='50'`);
        assert.equal(saves,1);
        await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('#${id} .local-import-progress-fill')).transitionDuration`),'0s');
        status='COMPLETED';
        await until(`document.getElementById('local${name}ImportSummaryTitle').textContent===${JSON.stringify(locale==='en'?'Completed':'Selesai')}`);
        assert.equal(await evaluate(`document.getElementById('${id}').classList.contains('show')`),true);
        assert.ok(await evaluate(`document.querySelector('#${id} .modal-footer').textContent.includes(${JSON.stringify(locale==='en'?'View import results':'Lihat hasil impor')})`));
        await command('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        await key('Escape',27);await until(`!document.getElementById('${id}').classList.contains('show')`);
        findings.push({locale,theme,width,kind,recheck:true,errorLink:true,slots:true,keyboard:true,reflow400:true,completedNoReload:true,textContrast,tableContrast,reducedMotion:true});console.log(`PASS ${locale} ${theme} ${width} ${kind}`);
    }
    if (errors.length) console.log(await evaluate("JSON.stringify([...document.querySelectorAll('template')].filter(t=>!t.content.firstElementChild).map(t=>({for:t.getAttribute('x-for'),if:t.getAttribute('x-if'),html:t.outerHTML.slice(0,300)})))"));
    assert.equal(errors.length, 0, JSON.stringify(errors));
    await writeFile(path.join(output, 'result.json'), JSON.stringify({ findings }, null, 2));
    console.log(`${findings.length} import UX browser scenarios passed`);
} finally {
    socket?.close(); chrome.kill(); server.close(); await sleep(500);
    assert.ok(path.resolve(profile).startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(profile).startsWith('adasi-import-ux-browser-'));
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }).catch(() => {});
}
