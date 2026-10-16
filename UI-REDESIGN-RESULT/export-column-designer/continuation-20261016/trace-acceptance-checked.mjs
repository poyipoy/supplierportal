// Actual renderer Paint boundaries, not a two-rAF surrogate. Preserve the
// old probe series separately. Freeze baseline B+J before measuring designer.
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {launchOwnedChrome,navigate,evaluate,waitFor} from './cdp-browser-probe.mjs';
const output=path.dirname(fileURLToPath(import.meta.url));
const original=fs.readFileSync(path.resolve(output,'../../../tests/Support/export-column-designer-browser-measure.js'),'utf8');
const replace=(source,anchor,value)=>{if(!source.includes(anchor))throw new Error('Changed measurement anchor: '+anchor);return source.replace(anchor,value);};
let measure=replace(original,'const start = performance.now();',`await frame();
            const start = performance.now();
            performance.mark('column-'+iteration+'-start');
            const readyNotice = new Promise((resolve, reject) => {
                const timeout = setTimeout(() => { observer.disconnect(); reject(new Error('True readiness condition timed out')); }, 8000);
                const observer = new MutationObserver(() => {
                    if (!root.querySelector('[data-export-submit]').disabled && root.querySelectorAll('[data-export-column]').length === expectedHeaders) {
                        performance.mark('column-'+iteration+'-dataReady');
                        observer.disconnect(); clearTimeout(timeout); resolve();
                    }
                });
                observer.observe(root, { attributes: true, childList: true, subtree: true });
            });`);
measure=replace(measure,'await shown;','await shown; await readyNotice;');
measure=replace(measure,'if (modern) header.click();',`performance.mark('column-'+iteration+'-selectionStart');
            if (modern) header.click();
            performance.mark('column-'+iteration+'-selectionEnd');
            await paint(); await frame();`);
for(const [anchor,mark] of [['const painted = performance.now();','painted'],['const commandStart = performance.now();','commandStart'],['const computed = performance.now();','computed'],['const commandPainted = performance.now();','commandPainted']]) measure=replace(measure,anchor,anchor+` performance.mark('column-'+iteration+'-${mark}');`);
const owned=await launchOwnedChrome({label:'trace-acceptance',windowSize:[1440,1000]});
const trials=[];
const metrics=['ready','paint','command','commandPaint','openPaint'];
const p95=values=>[...values].sort((a,b)=>a-b)[Math.ceil(values.length*.95)-1];
const budget=()=>[12,24,64].flatMap(size=>metrics.map(metric=>{
    const values=trials.filter(trial=>trial.variant==='legacy'&&trial.size===size).map(trial=>trial.p95[metric]).sort((a,b)=>a-b);
    if(values.length!==3)throw new Error('Three baseline batches required');
    const B=values[1],J=values[2]-values[0];return {size,metric,B,J,limit:B+J};
}));
async function capture(variant,size,batch) {
    await navigate(owned.page,'http://127.0.0.1:8103/'+variant);
    await owned.page.send('Page.bringToFront');
    await waitFor(owned.page,()=>Boolean(window.bootstrap?.Modal));
    await evaluate(owned.page,async()=>{window.__designerMeasure={po:await(await fetch('/exports/definitions/purchasing.po')).json(),quotes:await(await fetch('/exports/definitions/purchasing.quotations')).json(),runs:[]};});
    await evaluate(owned.page,`window.__designerMeasureSize=${size};window.__designerMeasureBatch=${batch};`);
    const complete=new Promise(resolve=>{const off=owned.client.on('Tracing.tracingComplete',result=>{off();resolve(result);});});
    await owned.client.send('Tracing.start',{categories:'devtools.timeline,blink.user_timing,disabled-by-default-devtools.timeline.frame',transferMode:'ReturnAsStream'});
    const proxy=await evaluate(owned.page,`(${measure})()`,{timeout:60000});
    await owned.client.send('Tracing.end');
    const terminal=await complete;if(terminal.dataLossOccurred!==false)throw new Error('Trace loss status was not false: '+JSON.stringify(terminal));const {stream}=terminal;let raw='';
    for(;;){const part=await owned.client.send('IO.read',{handle:stream});raw+=part.base64Encoded?Buffer.from(part.data,'base64').toString('utf8'):part.data;if(part.eof)break;}
    await owned.client.send('IO.close',{handle:stream});
    const filename=`checked-acceptance-trace-${variant}-${size}-${batch}.json.gz`;
    const bytes=zlib.gzipSync(raw);fs.writeFileSync(path.join(output,filename),bytes);
    const events=JSON.parse(raw).traceEvents;
    const rows=[];
    for(let iteration=0;iteration<30;iteration++) {
        const marks=Object.fromEntries(['start','dataReady','painted','selectionStart','selectionEnd','commandStart','computed','commandPainted'].map(name=>[name,events.find(event=>event.name===`column-${iteration}-${name}`)]));
        if(Object.values(marks).some(value=>!value))throw new Error('Missing trace mark');
        const paints=(from,to)=>events.filter(event=>event.pid===marks.start.pid&&event.tid===marks.start.tid&&event.name==='Paint'&&event.ph==='X'&&event.ts>=from&&event.ts+(event.dur||0)<=to);
        const initial=paints(marks.dataReady.ts,marks.painted.ts),command=paints(marks.commandStart.ts,marks.commandPainted.ts);
        if(!initial.length||!command.length)throw new Error('Paint boundary missing; do not treat missing evidence as zero latency');
        const openEnd=Math.max(...initial.map(event=>event.ts+(event.dur||0)));
        const commandEnd=Math.max(...command.map(event=>event.ts+(event.dur||0)));
        const drawAfter=end=>events.filter(event=>event.name==='DrawFrame'&&event.ts>=end).sort((a,b)=>a.ts-b.ts)[0];
        const openDraw=drawAfter(openEnd),commandDraw=drawAfter(commandEnd);
        rows.push({iteration,ready:(marks.dataReady.ts-marks.start.ts)/1000,paint:(openEnd-marks.dataReady.ts)/1000,command:(marks.computed.ts-marks.commandStart.ts)/1000,commandPaint:(commandEnd-marks.commandStart.ts)/1000,openPaint:(openEnd-marks.start.ts)/1000,selection:(marks.selectionEnd.ts-marks.selectionStart.ts)/1000,paintCounts:{initial:initial.length,command:command.length},uncorrelatedNextDrawFrameDiagnosticOnly:{open:openDraw?{afterPaintMs:(openDraw.ts-openEnd)/1000,frameSeqId:openDraw.args?.frameSeqId,layerTreeId:openDraw.args?.layerTreeId}:null,command:commandDraw?{afterPaintMs:(commandDraw.ts-commandEnd)/1000,frameSeqId:commandDraw.args?.frameSeqId,layerTreeId:commandDraw.args?.layerTreeId}:null}});
    }
    const trial={variant,size,batch,source:'frozen production build',viewport:proxy.viewport,animations:proxy.animations,metadataRequests:proxy.metadataRequests,closed:proxy.closed,backdrops:proxy.backdrops,openLayers:proxy.openLayers,trace:{file:filename,dataLossOccurred:terminal.dataLossOccurred,gzipBytes:bytes.length,sha256:crypto.createHash('sha256').update(bytes).digest('hex')},rows,p95:Object.fromEntries(metrics.map(metric=>[metric,p95(rows.map(row=>row[metric]))]))};
    if(!trial.closed||trial.backdrops||trial.openLayers||trial.metadataRequests!==66)throw new Error('Fixture/lifecycle/request check failed');
    trials.push(trial);fs.writeFileSync(path.join(output,filename.replace('.json.gz','-summary.json')),JSON.stringify(trial,null,2)+'\n');
    console.log(JSON.stringify({variant,size,batch,p95:trial.p95,paintEvidence:rows.length}));
}
try {
    await owned.page.send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
    for(let batch=0;batch<3;batch++)for(const size of [12,24,64])await capture('legacy',size,batch);
    const frozen={created:new Date().toISOString(),method:'B=median p95 of three30-iteration baseline batches; J=range of baseline p95; upper bound B+J. Actual true-readiness mutation and renderer Paint end; start/command phase aligned by rAF. No physical presentation claim.',version:owned.version,values:budget()};
    fs.writeFileSync(path.join(output,'checked-trace-thresholds-frozen.json'),JSON.stringify(frozen,null,2)+'\n');
    console.log('BASELINE THRESHOLDS FROZEN BEFORE DESIGNER TRACE ACCEPTANCE RUNS');
    for(let batch=0;batch<3;batch++)for(const size of [12,24,64])await capture('designer',size,batch);
    for(const size of [12,24,64])await capture('legacy',size,3);
    const controls=trials.filter(trial=>trial.variant==='legacy'&&trial.batch===3).flatMap(trial=>metrics.map(metric=>{const threshold=frozen.values.find(row=>row.size===trial.size&&row.metric===metric);const delta=trial.p95[metric]-threshold.B;return {size:trial.size,metric,baselineB:threshold.B,J:threshold.J,postDesignerLegacyP95:trial.p95[metric],delta,absoluteDeltaExceedsJ:Math.abs(delta)>threshold.J};}));
    fs.writeFileSync(path.join(output,'checked-legacy-drift-replay.json'),JSON.stringify({created:new Date().toISOString(),unchangedBudget:true,controls},null,2)+'\n');
    const values=frozen.values.map(row=>{const samples=trials.filter(trial=>trial.variant==='designer'&&trial.size===row.size).map(trial=>trial.p95[row.metric]).sort((a,b)=>a-b);const actual=samples[1];return {...row,actual,status:actual<=row.limit?'PASS':'FAIL'};});
    fs.writeFileSync(path.join(output,'checked-trace-acceptance-comparison.json'),JSON.stringify({status:values.every(row=>row.status==='PASS')?'PASS':'FAIL',created:new Date().toISOString(),scope:frozen.method,version:owned.version,baselineThresholds:'checked-trace-thresholds-frozen.json',earlierProxyFailuresPreserved:true,traceLossChecked:true,unchangedBudgetLegacyReplay:'checked-legacy-drift-replay.json',values},null,2)+'\n');
} finally {await owned.close();}
