// GET-only, immutable source/build replay. No Laravel kernel, session or DB.
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import crypto from 'node:crypto';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
const evidence = path.dirname(fileURLToPath(import.meta.url));
const fixtures = path.resolve(evidence, '../fixtures');
const manifests = Object.fromEntries(['legacy','designer'].map(variant => [variant, JSON.parse(fs.readFileSync(path.join(evidence,'frozen',variant,'public/build/manifest.json'),'utf8'))]));
const assets = [];
for (const [variant, manifest] of Object.entries(manifests)) for (const [entry, asset] of Object.entries(manifest)) {
    const bytes = fs.readFileSync(path.join(evidence,'frozen',variant,'public/build',asset.file));
    assets.push({variant,entry,file:asset.file,bytes:bytes.length,gzip:zlib.gzipSync(bytes).length,sha256:crypto.createHash('sha256').update(bytes).digest('hex')});
}
fs.writeFileSync(path.join(evidence,'paired-assets.json'),JSON.stringify({created:new Date().toISOString(),assets},null,2));
const vendorDir=path.join(evidence,'vendor');fs.mkdirSync(vendorDir,{recursive:true});
for(const [name,url] of [['bootstrap.css','https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'],['bootstrap.js','https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js']]) {
    const file=path.join(vendorDir,name);
    if(!fs.existsSync(file)){ const response=await fetch(url);if(!response.ok)throw new Error('Bootstrap fetch failed');fs.writeFileSync(file,Buffer.from(await response.arrayBuffer())); }
}
fs.writeFileSync(path.join(evidence,'vendor-assets.json'),JSON.stringify(fs.readdirSync(vendorDir).map(file=>({file,sha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(vendorDir,file))).digest('hex')})),null,2));
const server=http.createServer((req,res)=>{
    const url=new URL(req.url,'http://127.0.0.1:8103');
    res.setHeader('Cache-Control','no-store');
    res.setHeader('Content-Security-Policy',"default-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'");
    if(req.method!=='GET'){res.writeHead(405);res.end();return;}
    const send=(file,type)=>{if(!fs.existsSync(file)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',type);res.end(fs.readFileSync(file));};
    const variant=url.pathname.slice(1);
    const locale=url.searchParams.get('locale')==='id'?'id':'en';
    if(['legacy','designer'].includes(variant)) {
        let html=fs.readFileSync(variant==='legacy'?path.join(evidence,`legacy-${locale}.html`):path.join(fixtures,`purchasing.po-${locale}.html`),'utf8');
        html=html.replaceAll('http://127.0.0.1:8101','http://127.0.0.1:8103').replaceAll('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css','/vendor/bootstrap.css').replaceAll('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js','/vendor/bootstrap.js').replace(/\/build\/assets\/[^" ]+\.css/g,'/assets/designer/app.css').replace(/\/build\/assets\/[^" ]+\.js/g,'/assets/designer/app.js');
        res.setHeader('Content-Type','text/html; charset=UTF-8');res.end(html);return;
    }
    if(url.pathname==='/export-presets'){res.setHeader('Content-Type','application/json');res.end('{"data":[]}');return;}
    if(url.pathname.startsWith('/exports/definitions/')) {
        const key=url.pathname.split('/').at(-1);
        if(!/^[a-z]+\.[a-z-]+$/.test(key)){res.writeHead(404);res.end();return;}
        send(path.join(fixtures,`${key}-${locale}.json`),'application/json');return;
    }
    const asset=url.pathname.match(/^\/assets\/(legacy|designer)\/([A-Za-z0-9_.-]+)$/);
    if(asset){const [,kind,name]=asset;const mapped=name==='app.js'?manifests[kind]['resources/js/app.js'].file:name==='app.css'?manifests[kind]['resources/css/app.css'].file:'assets/'+name;send(path.join(evidence,'frozen',kind,'public/build',mapped),name.endsWith('.css')?'text/css':'text/javascript');return;}
    const vendor=url.pathname.match(/^\/vendor\/(bootstrap\.(css|js))$/);
    if(vendor){send(path.join(vendorDir,vendor[1]),vendor[2]==='css'?'text/css':'text/javascript');return;}
    res.writeHead(404);res.end();
});
server.listen(8103,'127.0.0.1',()=>console.log('Owned frozen replay server: http://127.0.0.1:8103 (GET only).'));
process.on('SIGINT',()=>server.close(()=>process.exit(0)));
