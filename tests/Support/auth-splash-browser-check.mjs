// Dependency-free browser QA. Run serially after the PHP test runner releases its DB slot.
// All data writes are exact disposable IDs in adasi_portal_test; .env is never modified.
import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:net';
import { gzipSync } from 'node:zlib';

const project = process.cwd();
const artifacts = path.join(project, 'storage/framework/testing/auth-splash');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const runId = randomBytes(6).toString('hex');
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'mysql', DB_DATABASE: 'adasi_portal_test',
    DB_USERNAME: 'root', DB_PASSWORD: '', DB_URL: '', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null',
    HASHIDS_SALT: 'adasi-isolated-test-salt-never-use-in-production', AUTH_TARPIT_ENABLED: 'false',
    APP_CONFIG_CACHE: path.join(artifacts, 'absent-config.php'), APP_ROUTES_CACHE: path.join(artifacts, 'absent-routes.php'),
    TURNSTILE_SITE_KEY: '', TURNSTILE_SECRET_KEY: '', AUTH_SPLASH_ENABLED: 'true', BCRYPT_ROUNDS: '4' };
const phpBootstrap = `require ${JSON.stringify(path.join(project, 'vendor/autoload.php').replaceAll('\\', '/'))};
$app=require ${JSON.stringify(path.join(project, 'bootstrap/app.php').replaceAll('\\', '/'))};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') throw new RuntimeException('Unsafe browser test database');
`;
function php(code, extraEnv = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn('php', [], { cwd: project, env: { ...env, ...extraEnv }, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
        let out = '', error = '';
        child.stdout.on('data', chunk => out += chunk);
        child.stderr.on('data', chunk => error += chunk);
        child.on('error', reject);
        child.on('exit', status => status === 0 ? resolve(out.trim()) : reject(new Error(`PHP QA helper failed (${status}): ${error || out}`)));
        child.stdin.end('<?php\n' + phpBootstrap + code);
    });
}

// Check physical connection before creating any fixture artifacts or disposable data.
assert.equal(await php("echo Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db;"), 'adasi_portal_test');
await mkdir(artifacts, { recursive: true });
await mkdir(path.join(artifacts, 'sessions'), { recursive: true });
const router = path.join(artifacts, 'router.php');
const coreFixture = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.auth-splash-bootstrap', ['authSplashLayout'=>'app','authSplashReadiness'=>'core'])
@include('partials.i18n-bootstrap') @include('partials.loader-logo') @vite(['resources/css/app.css','resources/js/app.js'])
</head><body><x-ui.auth-splash /><main id="main-content" tabindex="-1" aria-busy="false"><h1>Browser fixture</h1><input id="fixture-input" aria-label="Fixture input"><button id="fixture-button">Continue</button></main><aside id="preexisting-inert" inert aria-busy="false">Unavailable before splash</aside>
@if (!request()->boolean('without_sentinel'))<template data-auth-splash-layout-ready="app" data-readiness="core"></template>@endif
</body></html>`;
const routerSource = `<?php
$project=${JSON.stringify(project.replaceAll('\\', '/'))};
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath($project.'/public'.$path);
if($file && str_starts_with($file,realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
 header('Content-Type: '.(str_ends_with($file,'.js')?'application/javascript':(str_ends_with($file,'.css')?'text/css':(str_ends_with($file,'.png')?'image/png':'application/octet-stream')))); readfile($file); return;
}
if($path==='/__splash-prototype') { header('Content-Type: text/html'); readfile($project.'/docs/auth/references/adasi-post-login-splash-loop.html'); return; }
require $project.'/vendor/autoload.php';
$app=require $project.'/bootstrap/app.php';
$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class); $kernel->bootstrap();
if(Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db!=='adasi_portal_test') throw new RuntimeException('Unsafe browser test database');
config(['app.url'=>getenv('APP_URL'),'ui.auth_splash.enabled'=>!isset($_GET['splash_disabled']) && !isset($_SERVER['HTTP_X_SPLASH_QA_DISABLED']),'session.driver'=>'file','session.files'=>__DIR__.'/sessions','session.secure'=>false,'cache.default'=>'array','auth_security.turnstile.site_key'=>null,'auth_security.turnstile.secret_key'=>null,'auth_security.login.tarpit.enabled'=>false,'mail.default'=>'array','broadcasting.default'=>'null']);
Illuminate\\Support\\Facades\\View::composer('layouts.app',function(){if(request()->boolean('force_splash'))request()->attributes->set('ui.auth_splash',['reason'=>'post-login']);});
Illuminate\\Support\\Facades\\Route::middleware('web')->get('/__splash-fixture/{mode}',function($mode){
 config(['ui.auth_splash.enabled'=>!request()->boolean('disabled')]);
 request()->attributes->set('ui.auth_splash',['reason'=>'post-login']);
 if($mode==='auth'){ request()->route()->name('login'); return view('auth.login',['turnstileRequired'=>false]); }
 return Illuminate\\Support\\Facades\\Blade::render(${JSON.stringify(coreFixture)});
});
$request=Illuminate\\Http\\Request::capture();
$response=$kernel->handle($request);
if($path==='/finance/dashboard' && $request->boolean('without_sentinel')) $response->setContent(preg_replace('~<template data-auth-splash-layout-ready="app"[^>]*></template>~','',$response->getContent()));
if(str_starts_with($path,'/__splash-fixture') && $request->boolean('cacheable')) {
 $response->headers->set('Cache-Control','private, max-age=60'); $response->headers->remove('Pragma');
}
if(str_starts_with($path,'/__splash-fixture') && $request->query('csp')){
 $policy=match($request->query('csp')){'script'=>"script-src 'none'; style-src 'self' 'unsafe-inline' https:",'style'=>"script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'none'",default=>"script-src 'none'; style-src 'none'"};
 $response->headers->set('Content-Security-Policy',$policy);
}
$response->send(); $kernel->terminate($request,$response);
`;
await writeFile(router, routerSource);
const portServer = createServer();
await new Promise(resolve => portServer.listen(0, '127.0.0.1', resolve));
const httpPort = portServer.address().port;
await new Promise(resolve => portServer.close(resolve));
const origin = `http://127.0.0.1:${httpPort}`;
env.APP_URL = origin;
const server = spawn('php', ['-S', `127.0.0.1:${httpPort}`, '-t', path.join(project, 'public'), router],
    { cwd: project, env, windowsHide: true, stdio: 'ignore' });
const profile = await mkdtemp(path.join(tmpdir(), 'adasi-auth-splash-browser-'));
const browserPath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(browserPath, 'Installed Chrome/Edge required');
const browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'],
    { windowsHide: true, stdio: 'ignore' });
let socket, sequence = 0, credentials;
const pending = new Map(), findings = [], vendor = new Map(), held = new Set();
let policy = {}, requests = [], browserErrors = [];
const result = { findings, limitations: ['Manual screen-reader verification is not available in this headless run.'],
    sourceBytes: {}, database: 'adasi_portal_test', disposableUsersCleaned: false };
function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const response = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.text + ': ' + (response.exceptionDetails.exception?.description || ''));
    return response.result.value;
}
async function until(expression, timeout = 15000) {
    const expires = Date.now() + timeout;
    while (Date.now() < expires) { if (await evaluate(expression)) return; await sleep(50); }
    const diagnostic = await evaluate('({path:location.pathname,query:location.search,dom:document.readyState,alpine:typeof Alpine,splash:window.AdasiAuthSplash?.state,ready:window.AdasiAuthSplash?.ready,sentinel:!!document.querySelector("[data-auth-splash-layout-ready]"),main:!!document.querySelector("#main-content")})');
    throw new Error('Browser condition timed out: ' + expression + '; ' + JSON.stringify(diagnostic));
}
async function key(key, code) {
    for (const type of ['keyDown', 'keyUp']) await command('Input.dispatchKeyEvent', { type, key, code: key, windowsVirtualKeyCode: code });
}
async function navigate(url, options = {}) {
    for (const requestId of held) await command('Fetch.failRequest', { requestId, errorReason: 'Aborted' }).catch(() => {});
    held.clear(); policy = options; requests = []; browserErrors = [];
    const previousOrigin = await evaluate('performance.timeOrigin');
    await command('Page.navigate', { url: origin + url });
    await until('performance.timeOrigin !== ' + JSON.stringify(previousOrigin) + ' && Boolean(document.querySelector("#email, #main-content, #adasiSplash"))');
}
async function snapshot() {
    return evaluate(`({state:window.AdasiAuthSplash?.state,ready:window.AdasiAuthSplash?.ready,dom:document.readyState,
        overlayVisible:Boolean(document.querySelector('[data-auth-splash]:not([hidden])')),inert:document.querySelector('#main-content,.auth-shell')?.inert,
        bodyBusy:document.body.getAttribute('aria-busy'),focus:document.activeElement?.id||document.activeElement?.className,
        marks:performance.getEntriesByType('mark').filter(m=>m.name.startsWith('adasi-auth-splash-')).map(m=>({name:m.name,time:m.startTime})),
        measurements:window.__splashQA,requests:performance.getEntriesByType('resource').map(r=>new URL(r.name).pathname)})`);
}
function assertMinimum(actual) {
    const at = name => actual.marks.find(mark => mark.name === 'adasi-auth-splash-' + name)?.time;
    const visible = at('visible-start'), ready = at('render-ready'), released = at('interaction-released');
    assert.ok(Number.isFinite(visible) && Number.isFinite(ready) && Number.isFinite(released));
    // Browser scheduling is asserted with tolerance; exact boundaries live in Node tests.
    assert.ok(released - visible >= 1995, JSON.stringify({ visible, ready, released }));
    assert.ok(released >= ready);
    return { visible, ready, released, heldMs: released - visible };
}
async function passed(name, data = {}) { findings.push({ name, passed: true, ...data }); console.log('PASS ' + name); }
const wordmarkStyles = selector => `(()=>{const e=document.querySelector(${JSON.stringify(selector)}),s=getComputedStyle(e);return Object.fromEntries(['fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','height','paddingTop','paddingRight','color','overflow','whiteSpace'].map(k=>[k,s[k]]))})()`;

try {
    for (let i = 0; i < 100; i++) {
        try { const response = await fetch(origin + '/login'); if (response.ok) break; } catch {}
        await sleep(100);
    }
    const login = await (await fetch(origin + '/login')).text();
    const appAssets = await readFile(path.join(project, 'resources/views/layouts/app.blade.php'), 'utf8');
    const financeAssets = await readFile(path.join(project, 'resources/views/finance/dashboard.blade.php'), 'utf8');
    const vendorDirectory = path.join(artifacts, 'vendor');
    await mkdir(vendorDirectory, { recursive: true });
    for (const match of (login + appAssets + financeAssets).matchAll(/<(?:script|link)\b[^>]*(?:src|href)="(https:\/\/[^" ]+)"[^>]*integrity="(sha384-[^"]+)"[^>]*>/g)) {
        const [, url, integrity] = match;
        if (vendor.has(url)) continue;
        const vendorFile = path.join(vendorDirectory, createHash('sha256').update(url).digest('hex'));
        let body = existsSync(vendorFile) ? await readFile(vendorFile) : null;
        for (let attempt = 0; !body && attempt < 3; attempt++) {
            try {
                const response = await fetch(url, { signal: AbortSignal.timeout(20000) });
                assert.ok(response.ok, 'Pinned CDN asset available');
                body = Buffer.from(await response.arrayBuffer());
                await writeFile(vendorFile, body);
            } catch (error) { if (attempt === 2) throw new Error('Pinned CDN asset unavailable: ' + url); }
        }
        assert.equal('sha384-' + createHash('sha384').update(body).digest('base64'), integrity, 'Pinned CDN integrity');
        vendor.set(url, { body, type: url.includes('.css') ? 'text/css' : 'application/javascript' });
    }
    let cdpPort;
    for (let i = 0; i < 100; i++) {
        try { cdpPort = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); break; } catch { await sleep(100); }
    }
    assert.ok(cdpPort, 'Browser CDP available');
    const target = await (await fetch(`http://127.0.0.1:${cdpPort}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (pending.has(message.id)) { const task = pending.get(message.id); pending.delete(message.id); message.error ? task.reject(new Error(message.error.message)) : task.resolve(message.result); }
        if (message.method === 'Runtime.exceptionThrown') {
            const detail = message.params.exceptionDetails;
            browserErrors.push({ text: detail.text, description: detail.exception?.description,
                url: detail.url, stack: detail.stackTrace?.callFrames?.map(frame => ({ function: frame.functionName, url: frame.url, line: frame.lineNumber })) });
        }
        if (message.method === 'Network.requestWillBeSent') requests.push(message.params.request.url);
        if (message.method === 'Fetch.requestPaused') {
            const { requestId, request } = message.params;
            const app = /\/build\/assets\/app-[^/]+\.js(?:\?|$)/.test(request.url);
            if ((policy.hangApp && app) || (policy.hangCdn && request.url.startsWith('https://'))) { held.add(requestId); return; }
            if ((policy.blockApp && app) || (policy.failImport && /\/build\/assets\/cally-/.test(request.url))) {
                command('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {}); return;
            }
            const asset = vendor.get(request.url);
            if (asset) command('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: asset.type }, { name: 'Access-Control-Allow-Origin', value: '*' }], body: asset.body.toString('base64') }).catch(() => {});
            else command('Fetch.continueRequest', { requestId }).catch(() => {});
        }
    });
    await command('Page.enable'); await command('Runtime.enable'); await command('Network.enable'); await command('Accessibility.enable');
    await command('Network.setCacheDisabled', { cacheDisabled: true });
    await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await command('Page.addScriptToEvaluateOnNewDocument', { source: `window.__splashQA={events:[],shifts:[],longTasks:[],pageshow:[]};
        for(const name of ['adasi:page-ready','adasi:auth-splash-released','adasi:auth-splash-hidden'])addEventListener(name,e=>__splashQA.events.push({name,at:performance.now(),state:window.AdasiAuthSplash?.state,detail:e.detail}));
        addEventListener('DOMContentLoaded',()=>__splashQA.domReadyAt=performance.now());addEventListener('pageshow',e=>__splashQA.pageshow.push(e.persisted));
        document.addEventListener('alpine:initialized',()=>window.Alpine.nextTick(()=>__splashQA.coreAt=performance.now()));
        try{new PerformanceObserver(l=>__splashQA.shifts.push(...l.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});}catch{}
        try{new PerformanceObserver(l=>__splashQA.longTasks.push(...l.getEntries().map(e=>({start:e.startTime,duration:e.duration})))).observe({type:'longtask',buffered:true});}catch{}` });
    await command('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });

    for (const cached of [false, true]) {
        await command('Network.setCacheDisabled', { cacheDisabled: !cached });
        await navigate('/login');
        await until('window.AdasiAuthSplash?.state === "REMOVED"');
        await passed('minimum-login-' + (cached ? 'cached' : 'cold'), assertMinimum(await snapshot()));
    }
    await command('Network.setCacheDisabled', { cacheDisabled: true });

    for (const [name, options] of [['module-blocked', { blockApp: true }], ['module-hung', { hangApp: true }], ['CDN-and-module-hung', { hangApp: true, hangCdn: true }]]) {
        if (name === 'CDN-and-module-hung' && process.env.QA_SKIP_HANG_CSS === '1') {
            result.limitations.push('Hanging stylesheet readiness scenario skipped for this diagnostic run.');
            continue;
        }
        await navigate('/__splash-fixture/auth', options);
        await until('window.AdasiAuthSplash?.state === "REMOVED"');
        const actual = await snapshot();
        assert.equal(actual.ready, name !== 'CDN-and-module-hung', JSON.stringify({ name, actual }));
        assert.equal(actual.inert, false);
        if (name === 'CDN-and-module-hung') {
            assert.ok(actual.measurements.events.some(e => e.detail.exit === 'hard-timeout'));
            assert.ok(!actual.measurements.events.some(e => e.name === 'adasi:page-ready'));
        } else assertMinimum(actual);
        assert.equal(await evaluate('document.querySelector("#password").type'), 'password');
        assert.equal(await evaluate('document.querySelector("button[type=button][aria-label]").disabled'), true);
        if (name !== 'module-blocked') assert.equal(actual.measurements.domReadyAt, undefined, 'Native sentinel releases while DOMContentLoaded remains pending');
        await passed('native-login-' + name, { state: actual.state, dom: actual.dom, healthyReady: actual.ready, coreAbsent: true, interactionRestored: true });
    }
    for (const csp of ['script', 'style', 'both']) {
        await navigate('/__splash-fixture/auth?csp=' + csp, { blockApp: true });
        await until('document.querySelector("#password")?.type === "password"');
        assert.equal(await evaluate('Boolean(document.querySelector("[data-auth-splash]:not([hidden])"))'), false);
        assert.equal(await evaluate('document.querySelector(".auth-shell").inert'), false);
        await passed('CSP-' + csp + '-fail-open');
    }
    await command('Emulation.setScriptExecutionDisabled', { value: true });
    await navigate('/__splash-fixture/auth', { blockApp: true });
    assert.equal(await evaluate('document.querySelector("#password").type'), 'password');
    assert.equal(await evaluate('document.querySelector("[data-auth-splash]").hidden'), true);
    assert.equal(await evaluate('document.querySelector(".auth-shell").inert'), false);
    await passed('JavaScript-disabled-native-login');
    await command('Emulation.setScriptExecutionDisabled', { value: false });

    await navigate('/__splash-fixture/core', { hangApp: true });
    await until('window.AdasiAuthSplash?.isWaiting()');
    assert.equal(await evaluate('document.querySelector("#main-content").inert'), true);
    assert.equal(await evaluate('document.body.hasAttribute("aria-busy")'), false);
    await until('window.AdasiAuthSplash?.state === "REMOVED"', 12500);
    const deadline = await snapshot();
    assert.equal(deadline.ready, false); assert.equal(deadline.inert, false);
    assert.ok(deadline.measurements.events.some(e => e.detail.exit === 'hard-timeout'));
    await passed('independent-core-deadline', { deadline: deadline.measurements.events.find(e => e.detail.exit === 'hard-timeout')?.at, interactionRestored: true });

    await navigate('/__splash-fixture/core', { blockApp: true });
    await until('!document.querySelector("[data-auth-splash-skip]").hidden');
    await key('Tab', 9);
    assert.equal(await evaluate('document.activeElement.hasAttribute("data-auth-splash-skip")'), true);
    await key('Escape', 27);
    assert.equal(await evaluate('window.AdasiAuthSplash.state'), 'REMOVED');
    assert.equal(await evaluate('document.activeElement.id'), 'main-content');
    assert.equal(await evaluate('document.querySelector("#main-content").getAttribute("aria-busy")'), 'false');
    assert.equal(await evaluate('document.querySelector("#preexisting-inert").inert'), true);
    await passed('keyboard-skip-Escape-focus-and-exact-state-restoration');

    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await navigate('/__splash-fixture/core?without_sentinel=1');
    await until('Boolean(window.Alpine) && window.AdasiAuthSplash?.isWaiting()');
    assert.equal(await evaluate('getComputedStyle(document.querySelector(".auth-splash__letter")).animationName'), 'none');
    const ax = await command('Accessibility.getFullAXTree');
    assert.ok(ax.nodes.some(n => n.role?.value === 'status'));
    assert.ok(!ax.nodes.some(n => n.role?.value === 'StaticText' && ['A', 'D', 'S', 'I'].includes(n.name?.value)), 'Decorative letters absent from accessibility tree');
    await evaluate('document.body.insertAdjacentHTML("beforeend",`<template data-auth-splash-layout-ready="app" data-readiness="core"></template>`)');
    await until('window.AdasiAuthSplash?.state === "REMOVED"');
    const reduced = await snapshot();
    const reducedReady = reduced.measurements.events.find(e => e.name === 'adasi:page-ready');
    const reducedRelease = reduced.measurements.events.find(e => e.name === 'adasi:auth-splash-released');
    assert.ok(reducedRelease.at - reducedReady.at < 50, 'Reduced motion bypasses the branding minimum');
    await passed('reduced-motion-and-accessibility-tree');
    for (const scale of [2, 4]) {
        const width = Math.floor(1366 / scale), height = Math.floor(900 / scale);
        await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await navigate('/__splash-fixture/core', { blockApp: true });
        await until('!document.querySelector("[data-auth-splash-skip]").hidden');
        assert.equal(await evaluate('getComputedStyle(document.querySelector("[data-auth-splash-skip]")).clipPath'), 'inset(50%)');
        await key('Tab', 9);
        const geometry = await evaluate('(()=>{const b=document.querySelector("[data-auth-splash-skip]"),w=document.querySelector(".auth-splash__wordmark"),r=b.getBoundingClientRect(),m=w.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:innerWidth,height:innerHeight,wordmarkWidth:m.width,controlWidth:r.width,controlHeight:r.height}})()');
        assert.ok(geometry.left >= 0 && geometry.right <= width && geometry.top >= 0 && geometry.bottom <= height, JSON.stringify(geometry));
        assert.ok(geometry.wordmarkWidth <= width && geometry.controlWidth >= 24 && geometry.controlHeight >= 24);
        await key('Tab', 9);
        assert.equal(await evaluate('document.activeElement.hasAttribute("data-auth-splash-skip")'), true);
        await key('Escape', 27);
        assert.equal(await evaluate('document.querySelector("#main-content").inert'), false);
        await passed('reflow-equivalent-' + (scale * 100) + '-percent', geometry);
    }
    await command('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

    await navigate('/__splash-fixture/core?without_sentinel=1');
    await until('Boolean(window.AdasiLoader) && window.AdasiAuthSplash?.isWaiting()');
    await evaluate('window.__qaToken=AdasiLoader.show()'); await sleep(180);
    assert.equal(await evaluate('document.querySelector("#adasiLoader").classList.contains("active")'), false);
    await evaluate('document.body.insertAdjacentHTML("beforeend",`<template data-auth-splash-layout-ready="app" data-readiness="core"></template>`)');
    await until('document.querySelector("#adasiLoader").classList.contains("active")');
    assert.equal(await evaluate('Boolean(document.querySelector("[data-auth-splash]"))'), false);
    await evaluate('AdasiLoader.hide(window.__qaToken,true)');
    assert.equal(await evaluate('document.querySelector("#main-content").inert'), false);
    await passed('foreground-loader-handoff-without-overlay-overlap');

    const styleComparisons = [];
    for (const width of [1366, 375]) {
        await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        await navigate('/__splash-prototype'); const reference = await evaluate(wordmarkStyles('.wordmark'));
        await navigate('/__splash-fixture/core', { blockApp: true });
        await until('window.AdasiAuthSplash?.isWaiting()');
        assert.deepEqual(await evaluate(wordmarkStyles('.auth-splash__wordmark')), reference);
        for (const theme of ['light', 'dark']) {
            await evaluate(`document.documentElement.dataset.theme=${JSON.stringify(theme)}`);
            assert.equal(await evaluate('getComputedStyle(document.querySelector("[data-auth-splash]")).backgroundColor'), 'rgb(255, 255, 255)');
            await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
            await until('getComputedStyle(document.querySelector(".auth-splash__letter")).transform === "matrix(1, 0, 0, 1, 0, 0)"');
            const shot = await command('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.join(artifacts, `wordmark-${width}-${theme}.png`), Buffer.from(shot.data, 'base64'));
        }
        await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
        styleComparisons.push({ width, computedStylesMatch: true });
        await key('Escape', 27);
    }
    await passed('prototype-desktop-mobile-dark-style-comparison', { styleComparisons });
    await command('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });

    await navigate('/__splash-fixture/core'); await until('window.AdasiAuthSplash?.state === "REMOVED"');
    const healthy = await snapshot();
    assert.ok(!requests.some(url => /\/auth-splash\.(?:js|css)/.test(url)));
    result.performance = { cls: healthy.measurements.shifts.reduce((a, b) => a + b, 0), longTasks: healthy.measurements.longTasks,
        events: healthy.measurements.events, standaloneNetworkRequests: 0, totalRequests: requests.length };
    await passed('healthy-core-and-no-standalone-asset-requests', { cls: result.performance.cls, ...assertMinimum(healthy) });
    await navigate('/__splash-fixture/core?disabled=1');
    assert.equal(await evaluate('Boolean(document.querySelector("[data-auth-splash],[data-auth-splash-bootstrap]"))'), false);
    await passed('feature-flag-disabled-fixture');

    const measurements = { enabled: [], disabled: [] };
    // Alternate warmed visits: controlled local lab evidence, not field data.
    for (let round = 0; round < 6; round++) for (const enabled of [false, true]) {
        await navigate('/__splash-fixture/core' + (enabled ? '' : '?disabled=1'));
        await until('Boolean(window.__splashQA.coreAt) && Boolean(window.__splashQA.domReadyAt)');
        if (enabled) await until('window.AdasiAuthSplash?.state === "REMOVED"');
        const measure = await evaluate('({core:Math.max(__splashQA.coreAt,__splashQA.domReadyAt),cls:__splashQA.shifts.reduce((a,b)=>a+b,0),longTasks:__splashQA.longTasks})');
        if (round) measurements[enabled ? 'enabled' : 'disabled'].push(measure);
    }
    const median = values => [...values].sort((a,b)=>a-b)[Math.floor(values.length/2)];
    const baseline = median(measurements.disabled.map(value => value.core));
    const enabled = median(measurements.enabled.map(value => value.core));
    result.comparison = { measurements, baselineCoreMedianMs: baseline, enabledCoreMedianMs: enabled,
        coreChangePercent: (enabled / baseline - 1) * 100 };
    assert.ok(measurements.enabled.every(value => value.cls === 0), 'No overlay layout shift in controlled comparison');
    await passed('paired-local-core-performance-comparison', { baselineCoreMedianMs: baseline, enabledCoreMedianMs: enabled, coreChangePercent: result.comparison.coreChangePercent });

    // Genuine history navigation, with explicit disclosure if Chrome does not use bfcache.
    await command('Network.setCacheDisabled', { cacheDisabled: false });
    await navigate('/__splash-fixture/core'); await until('window.AdasiAuthSplash?.state === "REMOVED"');
    const history = await command('Page.getNavigationHistory');
    const previous = history.entries[history.currentIndex].id;
    await navigate('/__splash-prototype');
    await command('Page.navigateToHistoryEntry', { entryId: previous });
    await until('Boolean(window.AdasiAuthSplash)');
    const persisted = await evaluate('window.__splashQA.pageshow.includes(true)');
    if (persisted) { assert.equal(await evaluate('window.AdasiAuthSplash.state'), 'REMOVED'); await passed('real-bfcache-no-replay'); }
    else result.limitations.push('History navigation executed, but Chrome did not restore this no-store fixture from bfcache. Unit coverage handles persisted pageshow.');

    // Exercise restoration on a test-only cacheable response without changing
    // the application's no-store policy.
    await navigate('/__splash-fixture/core?cacheable=1');
    await until('window.AdasiAuthSplash?.state === "REMOVED"');
    const cacheHistory = await command('Page.getNavigationHistory');
    const cacheEntry = cacheHistory.entries[cacheHistory.currentIndex].id;
    await navigate('/__splash-prototype');
    await command('Page.navigateToHistoryEntry', { entryId: cacheEntry });
    await until('Boolean(window.AdasiAuthSplash)');
    const cached = await evaluate('__splashQA.pageshow.includes(true)');
    await until('window.AdasiAuthSplash?.state === "REMOVED"');
    if (cached) await passed('cacheable-browser-bfcache-no-replay');
    else result.limitations.push('Cacheable test-only history also did not enter bfcache in this CDP session; persisted restoration remains unit-verified.');

    credentials = JSON.parse(await php(`
$payload=Illuminate\\Support\\Facades\\DB::transaction(function(){ $password=bin2hex(random_bytes(12)).'aA!1'; $users=[];
foreach([false,true] as $mfa){$user=App\\Models\\User::factory()->create(['name'=>'Auth Splash Browser QA','email'=>'splash-${runId}-'.($mfa?'mfa':'plain').'@example.test','password'=>Illuminate\\Support\\Facades\\Hash::make($password),'role'=>'finance','is_active'=>true,'account_status'=>'ACTIVE','must_change_password'=>false]);
if($mfa){$secret=app(App\\Services\\Auth\\TwoFactorService::class)->authenticator()->generateSecretKey(32);$user->forceFill(['two_factor_secret'=>$secret,'two_factor_recovery_codes'=>[],'two_factor_confirmed_at'=>now(),'two_factor_last_used_step'=>null])->saveQuietly();}
$users[]=['id'=>$user->id,'email'=>$user->email,'mfa'=>$mfa];}return ['password'=>$password,'users'=>$users];});echo json_encode($payload);`));
    for (const user of credentials.users) {
        await command('Network.clearBrowserCookies');
        await navigate('/login'); await until('window.AdasiAuthSplash?.state === "REMOVED"');
        if (!user.mfa) {
            assert.equal(await evaluate(`fetch('/login',{method:'POST',body:new URLSearchParams({email:'csrf-check@example.test',password:'invalid'})}).then(r=>r.status)`), 419);
            await passed('real-login-CSRF-enforced');
            await evaluate(`document.querySelector('#email').value=${JSON.stringify(user.email)};document.querySelector('#password').value='wrong-password';document.querySelector('form[method="POST"]').requestSubmit()`);
            await until('document.querySelector("#email")?.value && !window.AdasiAuthSplash');
            await passed('real-invalid-login-has-no-splash');
        }
        await evaluate(`document.querySelector('#email').value=${JSON.stringify(user.email)};document.querySelector('#password').value=${JSON.stringify(credentials.password)};document.querySelector('form[method="POST"]').requestSubmit()`);
        if (user.mfa) {
            await until('location.pathname === "/two-factor-challenge" && Boolean(document.querySelector("#code"))');
            assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
            await evaluate('document.querySelector("#code").value="INVALID-CODE";document.querySelector("form[method=POST]").requestSubmit()');
            await until('location.pathname === "/two-factor-challenge" && Boolean(document.querySelector("#code")) && Boolean(document.querySelector("[aria-invalid=true]"))');
            assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
            const otp = await php(`$user=App\\Models\\User::findOrFail((int)getenv('QA_USER_ID'));echo app(App\\Services\\Auth\\TwoFactorService::class)->authenticator()->getCurrentOtp($user->two_factor_secret);`, { QA_USER_ID: String(user.id) });
            await evaluate(`document.querySelector('#code').value=${JSON.stringify(otp)};document.querySelector('form[method=POST]').requestSubmit()`);
        }
        await until('location.pathname === "/finance/dashboard" && window.AdasiAuthSplash?.state === "REMOVED"', 25000);
        assert.ok((await snapshot()).measurements.events.some(e => e.detail.reason === 'post-login'));
        const minimum = assertMinimum(await snapshot());
        result.authenticationTimings ??= [];
        result.authenticationTimings.push(await evaluate('({method:' + JSON.stringify(user.mfa ? 'MFA' : 'password') + ',marks:performance.getEntriesByType("mark").filter(m=>m.name.startsWith("adasi-auth-splash-")).map(m=>({name:m.name,time:m.startTime})),events:__splashQA.events})'));
        await passed('real-' + (user.mfa ? 'MFA' : 'password') + '-login-success', minimum);
        if (!user.mfa) {
            assert.equal(await evaluate(`fetch('/logout',{method:'POST'}).then(r=>r.status)`), 419);
            await passed('real-logout-CSRF-enforced');
            for (const width of [1366, 375]) {
                await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
                await navigate('/finance/dashboard?force_splash=1&without_sentinel=1');
                await until('Boolean(window.Alpine) && window.AdasiAuthSplash?.isWaiting()');
                assert.equal(await evaluate('document.querySelector("#sidebar").inert'), true, 'Sidebar remains splash-owned while Alpine binds');
                await key('Escape', 27);
                assert.equal(await evaluate('document.querySelector("#sidebar").inert'), width < 992, 'Closed mobile sidebar remains natively inert after release');
                assert.equal(await evaluate('document.querySelector("#main-content").inert'), false);
                await passed('real-sidebar-inert-ownership-' + width);
                await evaluate('window.__splashLoaderToken=AdasiLoader.show()');
                await until('document.querySelector("#adasiLoader").classList.contains("active")');
                await evaluate('AdasiLoader.hide(window.__splashLoaderToken,true)');
                assert.equal(await evaluate('document.querySelector("#sidebar").inert'), width < 992, 'Foreground loader settlement preserves the sidebar state restored by splash');
                await passed('real-loader-settlement-restores-sidebar-' + width);
            }
            await command('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
            const realComparison = { enabled: [], disabled: [] };
            for (let round = 0; round < 6; round++) for (const enabled of [false, true]) {
                await navigate('/finance/dashboard?' + (enabled ? 'force_splash=1' : 'splash_disabled=1'));
                await until('Boolean(window.__splashQA.coreAt) && Boolean(window.__splashQA.domReadyAt)');
                if (enabled) await until('window.AdasiAuthSplash?.state === "REMOVED"');
                const measurement = await evaluate('({core:Math.max(__splashQA.coreAt,__splashQA.domReadyAt),cls:__splashQA.shifts.reduce((a,b)=>a+b,0),longTasks:__splashQA.longTasks})');
                measurement.browserErrors = browserErrors;
                if (round) realComparison[enabled ? 'enabled' : 'disabled'].push(measurement);
            }
            const realBaseline = median(realComparison.disabled.map(value => value.core));
            const realEnabled = median(realComparison.enabled.map(value => value.core));
            result.financeComparison = { measurements: realComparison, baselineCoreMedianMs: realBaseline,
                enabledCoreMedianMs: realEnabled, coreChangePercent: (realEnabled / realBaseline - 1) * 100 };
            await passed('paired-real-finance-core-performance', { baselineCoreMedianMs: realBaseline,
                enabledCoreMedianMs: realEnabled, coreChangePercent: result.financeComparison.coreChangePercent });
            await navigate('/finance/dashboard');
        }
        await navigate('/finance/dashboard');
        await until('location.pathname === "/finance/dashboard" && document.querySelector("#main-content") && Boolean(window.Alpine)');
        assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
        await until('Boolean(document.querySelector(\'a[href$="/profile/security"]\'))');
        await evaluate('document.querySelector(\'a[href$="/profile/security"]\').click()');
        await until('location.pathname === "/profile/security" && Boolean(document.querySelector("#main-content"))');
        assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
        await passed('real-menu-navigation-without-auth-splash-' + (user.mfa ? 'MFA' : 'password'));
        const logout = await evaluate(`Boolean(document.querySelector('form[action$="/logout"] input[name="_token"]'))`);
        assert.equal(logout, true);
        await evaluate(`document.querySelector('form[action$="/logout"]').requestSubmit()`);
        await until('location.pathname === "/login" && window.AdasiAuthSplash?.state === "REMOVED"');
        assert.ok((await snapshot()).measurements.events.some(e => e.detail.reason === 'post-logout'));
        await passed('real-' + (user.mfa ? 'MFA' : 'password') + '-logout-and-refresh-no-replay', assertMinimum(await snapshot()));
    }
    // Isolate browser-native view-transition diagnostics with the feature off.
    await command('Network.setExtraHTTPHeaders', { headers: { 'X-Splash-QA-Disabled': '1' } });
    await navigate('/login');
    await until('location.pathname === "/login" && Boolean(document.querySelector("#password"))');
    assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
    const baselineUser = credentials.users.find(user => !user.mfa);
    await evaluate(`document.querySelector('#email').value=${JSON.stringify(baselineUser.email)};document.querySelector('#password').value=${JSON.stringify(credentials.password)};document.querySelector('form[method="POST"]').requestSubmit()`);
    await until('location.pathname === "/finance/dashboard" && Boolean(document.querySelector("form[action$=\\"/logout\\"]"))');
    assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
    await evaluate('document.querySelector(\'form[action$="/logout"]\').requestSubmit()');
    await until('location.pathname === "/login" && Boolean(document.querySelector("#password"))');
    assert.equal(await evaluate('Boolean(window.AdasiAuthSplash)'), false);
    result.disabledAuthBrowserErrors = browserErrors;
    await passed('feature-disabled-real-login-and-logout');
    await command('Network.setExtraHTTPHeaders', { headers: {} });

    for (const filename of ['js/auth-splash.js', 'css/auth-splash.css']) {
        const source = await readFile(path.join(project, 'resources', filename));
        result.sourceBytes[filename] = { raw: source.length, gzip: gzipSync(source).length };
    }
    result.browserErrors = browserErrors;
} catch (error) {
    result.failed = { message: error.message, stack: error.stack };
    console.error('FAIL ' + error.message);
    process.exitCode = 1;
} finally {
    if (credentials) {
        const ids = credentials.users.map(user => user.id);
        await php(`$ids=json_decode(getenv('QA_USER_IDS'),true);foreach($ids as $id){$user=App\\Models\\User::find($id);if($user){if(!str_starts_with($user->email,'splash-${runId}-'))throw new RuntimeException('Cleanup identity mismatch');$user->delete();}}echo 'cleaned';`, { QA_USER_IDS: JSON.stringify(ids) });
        result.disposableUsersCleaned = true;
    }
    const resultFile = process.env.QA_RESULT_FILE || 'result.json';
    assert.match(resultFile, /^[a-z0-9-]+\.json$/);
    await writeFile(path.join(artifacts, resultFile), JSON.stringify(result, null, 2));
    socket?.close(); browser.kill(); server.kill(); await sleep(300);
    const sessionDirectory = path.join(artifacts, 'sessions');
    assert.ok(path.resolve(sessionDirectory).startsWith(path.resolve(artifacts) + path.sep));
    await rm(sessionDirectory, { recursive: true, force: true, maxRetries: 3, retryDelay: 100 });
    assert.ok(path.resolve(profile).startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(profile).startsWith('adasi-auth-splash-browser-'));
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }).catch(() => {});
    console.log(`${findings.length} browser scenarios passed; results in storage/framework/testing/auth-splash/result.json`);
}
