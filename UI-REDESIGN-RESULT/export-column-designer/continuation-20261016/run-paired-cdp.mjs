// Run only after frozen builds and serve-frozen.mjs are ready. Both variants
// use the same fresh owned Chrome, viewport, metadata, cadence and warm-up.
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import {launchOwnedChrome,createPage,navigate,evaluate,waitFor} from './cdp-browser-probe.mjs';
const output=path.dirname(fileURLToPath(import.meta.url));
const source=fs.readFileSync(path.resolve(output,'../../../tests/Support/export-column-designer-browser-measure.js'),'utf8');
const owned=await launchOwnedChrome({windowSize:[1440,1000],label:'paired-performance'});
const pages={legacy:owned.page,designer:await createPage(owned.client)};
const runs=[];
const cpu=()=>os.cpus().map(core=>({...core.times}));
const started=cpu();
try {
    for(const [variant,page] of Object.entries(pages)) {
        await page.send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
        await navigate(page,'http://127.0.0.1:8103/'+variant);
        await waitFor(page,()=>Boolean(window.bootstrap?.Modal&&document.querySelector('[data-advanced-export]')));
        await evaluate(page,async()=>{window.__designerMeasure={po:await(await fetch('/exports/definitions/purchasing.po')).json(),quotes:await(await fetch('/exports/definitions/purchasing.quotations')).json(),runs:[]};});
    }
    for(let batch=0;batch<3;batch++)for(const size of [12,24,64])for(const variant of batch%2?['designer','legacy']:['legacy','designer']) {
        const page=pages[variant];
        await page.send('Page.bringToFront');
        await evaluate(page,`window.__designerMeasureSize=${size};window.__designerMeasureBatch=${batch};`);
        const result={variant,...await evaluate(page,`(${source})()`,{timeout:60000})};
        runs.push(result);
        fs.writeFileSync(path.join(output,`cdp-paired-${variant}-${size}-${batch}.json`),JSON.stringify(result,null,2)+'\n');
        console.log(JSON.stringify({variant,size,batch,p95:result.p95,animations:result.animations}));
    }
    const values=[];
    for(const size of [12,24,64])for(const metric of ['ready','paint','command','commandPaint']) {
        const baseline=runs.filter(r=>r.variant==='legacy'&&r.size===size).map(r=>r.p95[metric]).sort((a,b)=>a-b);
        const modern=runs.filter(r=>r.variant==='designer'&&r.size===size).map(r=>r.p95[metric]).sort((a,b)=>a-b);
        const B=baseline[1],J=baseline[2]-baseline[0],actual=modern[1];
        values.push({size,metric,B,J,limit:B+J,actual,status:actual<=B+J?'PASS':'FAIL'});
    }
    fs.writeFileSync(path.join(output,'cdp-paired-comparison.json'),JSON.stringify({status:values.every(v=>v.status==='PASS')?'PASS':'FAIL',scope:'Frozen production snapshots in same owned headless Chrome155, viewport1440x900, three33-iteration batches per catalog, counterbalanced order. Excludes both Bootstrap dialog/backdrop animations and fake metadata latency.',version:owned.version,args:owned.args,created:new Date().toISOString(),cpuBefore:started,cpuAfter:cpu(),values},null,2)+'\n');
} finally {await owned.close();}
