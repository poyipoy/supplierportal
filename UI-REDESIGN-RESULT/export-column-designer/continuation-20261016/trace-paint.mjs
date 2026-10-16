// Discriminating trace: compare two-rAF timing with actual renderer work.
// Instrument only this measurement harness; never rewrite application code.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchOwnedChrome,navigate,evaluate,waitFor} from './cdp-browser-probe.mjs';
const output=path.dirname(fileURLToPath(import.meta.url));
const original=fs.readFileSync(path.resolve(output,'../../../tests/Support/export-column-designer-browser-measure.js'),'utf8');
const annotate=(source,before,after)=>{if(!source.includes(before))throw new Error('Measurement anchor changed');return source.replace(before,after);};
let measure=annotate(original,'iteration < 30','iteration < 6');
for(const [anchor,mark] of [['const start = performance.now();','start'],['const ready = performance.now();','ready'],['const painted = performance.now();','painted'],['const commandStart = performance.now();','commandStart'],['const computed = performance.now();','computed'],['const commandPainted = performance.now();','commandPainted']]) measure=annotate(measure,anchor,anchor+` performance.mark('column-'+iteration+'-${mark}');`);
const owned=await launchOwnedChrome({label:'paint-trace',windowSize:[1440,1000]});
const summaries=[];
try {
    await owned.page.send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
    for(const variant of ['legacy','designer']) {
        await navigate(owned.page,'http://127.0.0.1:8103/'+variant);
        await waitFor(owned.page,()=>Boolean(window.bootstrap?.Modal));
        await evaluate(owned.page,async()=>{window.__designerMeasure={po:await(await fetch('/exports/definitions/purchasing.po')).json(),quotes:await(await fetch('/exports/definitions/purchasing.quotations')).json(),runs:[]};window.__designerMeasureSize=12;});
        const complete=new Promise(resolve=>{const off=owned.client.on('Tracing.tracingComplete',value=>{off();resolve(value);});});
        await owned.client.send('Tracing.start',{categories:'devtools.timeline,blink.user_timing,disabled-by-default-devtools.timeline.frame',transferMode:'ReturnAsStream'});
        const result=await evaluate(owned.page,`(${measure})()`,{timeout:60000});
        await owned.client.send('Tracing.end');
        const {stream}=await complete;
        let raw='';
        for(;;){const part=await owned.client.send('IO.read',{handle:stream});raw+=part.base64Encoded?Buffer.from(part.data,'base64').toString('utf8'):part.data;if(part.eof)break;}
        await owned.client.send('IO.close',{handle:stream});
        fs.writeFileSync(path.join(output,`paint-trace-${variant}.json`),raw);
        const trace=JSON.parse(raw).traceEvents;
        const rows=[];
        for(let iteration=0;iteration<6;iteration++) {
            const marks=Object.fromEntries(['start','ready','painted','commandStart','computed','commandPainted'].map(name=>[name,trace.find(event=>event.name===`column-${iteration}-${name}`)]));
            if(Object.values(marks).some(value=>!value))throw new Error('Trace marks missing');
            const segment=(from,to)=>{
                const events=trace.filter(event=>event.pid===marks.start.pid && event.ph==='X' && event.ts>=from && event.ts<=to && ['Layout','UpdateLayoutTree','Paint','PrePaint','Layerize'].includes(event.name));
                const paints=events.filter(event=>event.name==='Paint');
                return {elapsed:(to-from)/1000,paintCount:paints.length,firstPaintEnd:paints.length?(paints[0].ts+(paints[0].dur||0)-from)/1000:null,lastPaintEnd:paints.length?(Math.max(...paints.map(event=>event.ts+(event.dur||0)))-from)/1000:null,durations:Object.fromEntries(['Layout','UpdateLayoutTree','Paint','PrePaint','Layerize'].map(name=>[name,events.filter(event=>event.name===name).reduce((sum,event)=>sum+(event.dur||0),0)/1000]))};
            };
            rows.push({iteration,readyToDoubleRaf:segment(marks.ready.ts,marks.painted.ts),commandToDoubleRaf:segment(marks.commandStart.ts,marks.commandPainted.ts),readyFromStart:(marks.ready.ts-marks.start.ts)/1000});
        }
        summaries.push({variant,p95:result.p95,rows});
    }
    fs.writeFileSync(path.join(output,'paint-trace-comparison.json'),JSON.stringify({scope:'Six measured iterations after three warmups; diagnostic trace, not the30-run acceptance series.',version:owned.version,summaries},null,2)+'\n');
    console.log(JSON.stringify(summaries));
} finally {await owned.close();}
