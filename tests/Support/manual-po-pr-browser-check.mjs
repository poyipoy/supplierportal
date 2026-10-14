// Real Blade fixtures with built assets; persistence is verified by HTTP/MySQL tests.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const root=process.cwd(), fixtures=path.join(root,'storage/framework/testing/manual-pr-ui');
const output=path.join(fixtures,'browser');
await mkdir(output,{recursive:true});
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let locale='en', offline=false, requests=0;
const summary={new_po:0,po_pr_filled:1,existing_po:1,invalid:0,source_rows:1,unique_pos:1};
const payload=()=>({kind:'PO',status:'READY',filename:'PR.xlsx',validation_attempt:1,token:'pr-token',confirm_url:'/fixture/confirm',
    records_url:'/fixture/records',errors_url:'/fixture/errors',errors_total:0,records_total:1,processed_rows:1,source_rows:1,
    progress:{phase:'READY',mode:'none'},preview:{summary,rows:[{_row:2,po_number:'UI-PO',pr_number:'PR-TEST-01',supplier_name:'Test Vendor',po_date:'2026-10-01',po_amount:'1000.00',action_label:locale==='id'?'Isi PR':'Fill PR'}],errors:[]}});
const server=createServer(async(req,res)=>{
    try{
        const url=new URL(req.url,'http://localhost');
        const send=value=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(value));};
        if(url.pathname.endsWith('/pr-suggestions')){requests++;if(offline){res.writeHead(503).end();return;}return send({data:[{pr_number:'PR-REGISTERED'},{pr_number:'PR-'+ 'x'.repeat(97)}]});}
        if(url.pathname.endsWith('/imports'))return send([{kind:'PO',filename:'PR.xlsx',status:'READY',status_url:'/fixture/status',validation_attempt:1,processed_rows:1,summary}]);
        if(url.pathname==='/fixture/status')return send(payload());
        if(url.pathname.endsWith('/goods-receipts/data'))return send({draw:Number(url.searchParams.get('draw')||1),recordsTotal:0,recordsFiltered:0,data:[]});
        if(url.pathname.endsWith('unread-count'))return send({count:0});
        if(url.pathname.startsWith('/fixtures/')){
            const name=path.basename(url.pathname);
            assert.match(name,/^(en|id)-(create|edit)\.html$/);
            const manifest=JSON.parse(await readFile(path.join(root,'public/build/manifest.json'),'utf8'));
            let html=await readFile(path.join(fixtures,name),'utf8');
            html=html.replace(/\/build\/assets\/app-[\w-]+\.js/g,'/build/'+manifest['resources/js/app.js'].file)
                .replace(/\/build\/assets\/app-[\w-]+\.css/g,'/build/'+manifest['resources/css/app.css'].file);
            res.setHeader('Content-Type','text/html');return res.end(html);
        }
        const file=path.resolve(root,'public',url.pathname.slice(1));
        if(file.startsWith(path.join(root,'public')+path.sep)&&existsSync(file)){
            res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'image/png');
            return res.end(await readFile(file));
        }
        res.writeHead(404).end();
    }catch(error){res.writeHead(500).end(String(error));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
const profile=await mkdtemp(path.join(tmpdir(),'adasi-manual-pr-browser-'));
const executable=['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(executable);
const chrome=spawn(executable,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore',windowsHide:true});
let socket,id=0;
const pending=new Map(), errors=[],findings=[],vendor=new Map();
const command=(method,params={})=>new Promise((resolve,reject)=>{const request=++id;pending.set(request,{resolve,reject});socket.send(JSON.stringify({id:request,method,params}));});
async function evaluate(expression){const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
async function until(expression){for(let n=0;n<250;n++){if(await evaluate(expression))return;await delay(100);}console.log(await evaluate(`JSON.stringify({value:document.getElementById('pr_number')?.value,status:document.getElementById('pr_number-status')?.textContent,resources:performance.getEntriesByType('resource').map(r=>r.name),state:Alpine.$data(document.getElementById('pr_number')?.closest('[x-data]'))})`));throw Error('Timeout: '+expression+' '+JSON.stringify(errors));}
async function key(key,code){for(const type of ['keyDown','keyUp'])await command('Input.dispatchKeyEvent',{type,key,windowsVirtualKeyCode:code});}
try{
    for(const file of ['en-create.html','en-edit.html']){
        const html=await readFile(path.join(fixtures,file),'utf8');
        for(const match of html.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="(https:\/\/[^" ]+)"[^>]*integrity="(sha384-[^"]+)"[^>]*>/g)){
            const [,url,integrity]=match;
            if(vendor.has(url))continue;
            const cache=path.join(root,'storage/framework/testing/import-ui/vendor',createHash('sha256').update(url).digest('hex'));
            if(!existsSync(cache)){
                const response=await fetch(url,{signal:AbortSignal.timeout(20000)});
                assert.ok(response.ok,url);
                await mkdir(path.dirname(cache),{recursive:true});
                await writeFile(cache,Buffer.from(await response.arrayBuffer()));
            }
            const body=await readFile(cache);
            assert.equal('sha384-'+createHash('sha384').update(body).digest('base64'),integrity,url);
            vendor.set(url,{body,type:url.includes('.css')?'text/css':'application/javascript'});
        }
    }
    const core=await readFile(path.join(root,'storage/framework/testing/jquery.dataTables-1.13.6.min.js'));
    assert.equal('sha384-'+createHash('sha384').update(core).digest('base64'),'sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK');
    vendor.set('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',{body:core,type:'application/javascript'});
    let port;
    for(let n=0;n<100;n++){try{port=Number((await readFile(path.join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0]);break;}catch{await delay(100);}}
    assert.ok(port);
    const target=await(await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
    socket=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
    socket.addEventListener('message',event=>{
        const data=JSON.parse(event.data);
        if(data.method==='Fetch.requestPaused'){
            const asset=vendor.get(data.params.request.url);
            if(!asset){command('Fetch.continueRequest',{requestId:data.params.requestId});return;}
            command('Fetch.fulfillRequest',{requestId:data.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:asset.type},{name:'Access-Control-Allow-Origin',value:'*'}],body:asset.body.toString('base64')}).catch(e=>errors.push(String(e)));return;
        }
        if(data.method==='Runtime.exceptionThrown')errors.push(data.params.exceptionDetails.exception?.description??data.params.exceptionDetails.text);
        if(pending.has(data.id)){const task=pending.get(data.id);pending.delete(data.id);data.error?task.reject(Error(data.error.message)):task.resolve(data.result);}
    });
    await command('Fetch.enable',{patterns:[...vendor.keys()].map(url=>({urlPattern:url}))});
    await command('Page.enable');await command('Runtime.enable');await command('Accessibility.enable');
    for(locale of ['en','id'])for(const theme of ['light','dark'])for(const width of [375,1440]){
        offline=false;requests=0;
        await command('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:false});
        await command('Page.navigate',{url:`${origin}/fixtures/${locale}-create.html`});
        await until(`document.readyState==='complete'&&Boolean(document.getElementById('pr_number')?.closest('[x-data]')?._x_dataStack)`);
        await delay(500);
        if(width===1440){
            const alignment=await evaluate(`(()=>{const po=document.getElementById('po_number').getBoundingClientRect(),pr=document.getElementById('pr_number').getBoundingClientRect();return {top:Math.abs(po.top-pr.top),height:Math.abs(po.height-pr.height)}})()`);
            assert.ok(alignment.top<=1&&alignment.height<=1,JSON.stringify(alignment));
        }
        assert.equal(await evaluate(`document.querySelector('label[for=pr_number]').textContent.trim()`),'PR Number');
        await evaluate(`document.documentElement.dataset.theme=${JSON.stringify(theme)};document.getElementById('pr_number').focus()`);
        const type=async value=>evaluate(`(()=>{const n=document.getElementById('pr_number');n.value=${JSON.stringify(value)};n.dispatchEvent(new Event('input',{bubbles:true}))})()`);
        await type('P');await delay(350);assert.equal(requests,0);
        await type('PR');await until(`document.querySelectorAll('#pr_number-suggestions [role=option]').length===2`);
        assert.equal(requests,1);
        await key('ArrowDown',40);
        assert.equal(await evaluate(`document.querySelector('#pr_number-suggestions [role=option]').classList.contains('is-highlighted')`),true);
        await evaluate(`document.getElementById('pr_number').scrollIntoView({block:'center'})`);
        const menuShot=await command('Page.captureScreenshot',{format:'png'});await writeFile(path.join(output,`${locale}-${theme}-${width}-suggestions.png`),Buffer.from(menuShot.data,'base64'));
        await key('Enter',13);
        await until(`document.getElementById('pr_number').value==='PR-REGISTERED'&&document.getElementById('pr_number').getAttribute('aria-expanded')==='false'`);
        assert.equal(await evaluate(`new FormData(document.getElementById('localPoForm')).get('pr_number')`),'PR-REGISTERED');
        await type('000PR-MANUAL');await key('Escape',27);
        assert.equal(await evaluate(`new FormData(document.getElementById('localPoForm')).get('pr_number')`),'000PR-MANUAL');
        offline=true;await type('PR-OFFLINE');
        await until(`document.getElementById('pr_number-status').textContent.includes(${JSON.stringify(locale==='en'?'could not':'tidak dapat')})`);
        assert.equal(await evaluate(`document.getElementById('pr_number').value`),'PR-OFFLINE');
        offline=false;await type('PR-LONG');await until(`document.querySelectorAll('#pr_number-suggestions [role=option]').length===2`);
        await key('ArrowDown',40);await key('ArrowDown',40);await key('Enter',13);
        await until(`document.getElementById('pr_number').value.length===100`);
        await evaluate(`document.getElementById('pr_number').scrollIntoView({block:'center'})`);
        assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth+1`),false);
        const ax=await command('Accessibility.getFullAXTree');assert.ok(ax.nodes.some(n=>n.role?.value==='combobox'&&n.name?.value==='PR Number'));
        const shot=await command('Page.captureScreenshot',{format:'png'});await writeFile(path.join(output,`${locale}-${theme}-${width}-create.png`),Buffer.from(shot.data,'base64'));
        await command('Page.navigate',{url:`${origin}/fixtures/${locale}-edit.html`});
        await until(`document.getElementById('pr_number')?.readOnly===true`);
        assert.equal(await evaluate(`document.getElementById('pr_number').value`),'PR-REGISTERED');
        assert.equal(await evaluate(`document.getElementById('pr_number').getAttribute('role')`),null);
        findings.push({locale,theme,width,keyboard:true,manualPreserved:true,offline:true,readonly:true,noOverflow:true});
        console.log(`PASS ${locale} ${theme} ${width}`);
    }
    assert.equal(errors.length,0,JSON.stringify(errors));
    await writeFile(path.join(output,'result.json'),JSON.stringify({findings},null,2));
    console.log(`${findings.length} manual PR browser scenarios passed`);
}finally{
    socket?.close();chrome.kill();server.close();await delay(500);
    assert.ok(path.resolve(profile).startsWith(path.resolve(tmpdir())+path.sep)&&path.basename(profile).startsWith('adasi-manual-pr-browser-'));
    await rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200}).catch(()=>{});
}
