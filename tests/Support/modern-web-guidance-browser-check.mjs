// Dependency-free Chromium CDP + local axe-core WCAG 2.2 AA audit for Modern Web Guidance (Oracle 1 axe + Oracle 5).
// Strictly isolated to adasi_portal_test; run serially after PHPUnit releases its DB slot.
import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:net';

const project = process.cwd();
const artifacts = path.join(project, 'storage/framework/testing/modern-web-guidance');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const runId = randomBytes(6).toString('hex');

const axeSourcePath = path.join(project, 'node_modules/axe-core/axe.min.js');
assert.ok(existsSync(axeSourcePath), 'axe-core must be installed in node_modules/axe-core/axe.min.js');
const axeSource = await readFile(axeSourcePath, 'utf8');

const env = {
    ...process.env,
    APP_ENV: 'testing',
    DB_CONNECTION: 'mysql',
    DB_DATABASE: 'adasi_portal_test',
    DB_USERNAME: 'root',
    DB_PASSWORD: '',
    DB_URL: '',
    SESSION_DRIVER: 'file',
    SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BROADCAST_CONNECTION: 'null',
    HASHIDS_SALT: 'adasi-isolated-test-salt-never-use-in-production',
    AUTH_TARPIT_ENABLED: 'false',
    APP_CONFIG_CACHE: path.join(artifacts, 'absent-config.php'),
    APP_ROUTES_CACHE: path.join(artifacts, 'absent-routes.php'),
    TURNSTILE_SITE_KEY: '',
    TURNSTILE_SECRET_KEY: '',
    AUTH_SPLASH_ENABLED: 'false',
    WORKSPACE_SHELL_ENABLED: 'true',
    BCRYPT_ROUNDS: '4',
};

const phpBootstrap = `require ${JSON.stringify(path.join(project, 'vendor/autoload.php').replaceAll('\\', '/'))};
$app=require ${JSON.stringify(path.join(project, 'bootstrap/app.php').replaceAll('\\', '/'))};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') throw new RuntimeException('Unsafe browser test database');
`;

function php(code, extraEnv = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn('php', [], { cwd: project, env: { ...env, ...extraEnv }, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
        let out = '';
        let error = '';
        child.stdout.on('data', (chunk) => { out += chunk; });
        child.stderr.on('data', (chunk) => { error += chunk; });
        child.on('error', reject);
        child.on('exit', (status) => (status === 0 ? resolve(out.trim()) : reject(new Error(`PHP QA helper failed (${status}): ${error || out}`))));
        child.stdin.end('<?php\n' + phpBootstrap + code);
    });
}

assert.equal(await php("echo Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db;"), 'adasi_portal_test');
await mkdir(artifacts, { recursive: true });
await mkdir(path.join(artifacts, 'sessions'), { recursive: true });

const router = path.join(artifacts, 'router.php');
const routerSource = `<?php
$project=${JSON.stringify(project.replaceAll('\\', '/'))};
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath($project.'/public'.$path);
if($file && str_starts_with($file,realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
    $mime=match($ext){'js'=>'application/javascript','css'=>'text/css','png'=>'image/png','jpg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','woff2'=>'font/woff2','woff'=>'font/woff',default=>'application/octet-stream'};
    header('Content-Type: '.$mime);
    readfile($file);
    return;
}
require $project.'/vendor/autoload.php';
$app=require $project.'/bootstrap/app.php';
$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
if(Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db!=='adasi_portal_test') throw new RuntimeException('Unsafe browser test database');
$shellEnabled=!isset($_GET['_mwg_shell']) || $_GET['_mwg_shell']!=='off';
config([
    'app.url'=>getenv('APP_URL'),
    'ui.auth_splash.enabled'=>false,
    'ui.workspace_shell.enabled'=>$shellEnabled,
    'session.driver'=>'file',
    'session.files'=>__DIR__.'/sessions',
    'session.secure'=>false,
    'cache.default'=>'array',
    'auth_security.turnstile.site_key'=>null,
    'auth_security.turnstile.secret_key'=>null,
    'auth_security.login.tarpit.enabled'=>false,
    'mail.default'=>'array',
    'broadcasting.default'=>'null',
]);
$request=Illuminate\\Http\\Request::capture();
$response=$kernel->handle($request);
$response->send();
$kernel->terminate($request,$response);
`;
await writeFile(router, routerSource);

const portServer = createServer();
await new Promise((resolve) => portServer.listen(0, '127.0.0.1', resolve));
const httpPort = portServer.address().port;
await new Promise((resolve) => portServer.close(resolve));
const origin = `http://127.0.0.1:${httpPort}`;
env.APP_URL = origin;

const routes = JSON.parse(await php(`
echo json_encode([
    'login' => parse_url(route('login'), PHP_URL_PATH),
    'forgotPassword' => parse_url(route('password.request'), PHP_URL_PATH),
    'dashboard' => parse_url(route('finance.dashboard'), PHP_URL_PATH),
    'drpSupplier' => parse_url(route('finance.drp.supplier'), PHP_URL_PATH),
    'adminUsers' => parse_url(route('admin.users.index'), PHP_URL_PATH),
]);
`));

const server = spawn('php', ['-S', `127.0.0.1:${httpPort}`, '-t', path.join(project, 'public'), router], {
    cwd: project,
    env,
    windowsHide: true,
    stdio: 'ignore',
});

const profile = await mkdtemp(path.join(tmpdir(), 'adasi-mwg-browser-'));
const browserPath = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(existsSync);
assert.ok(browserPath, 'Installed Chrome/Edge required');

const browser = spawn(browserPath, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-background-networking',
    '--remote-debugging-port=0',
    `--user-data-dir=${profile}`,
    'about:blank',
], { windowsHide: true, stdio: 'ignore' });

let socket;
let sequence = 0;
let createdUsers = null;
const pending = new Map();
const vendor = new Map();
const checks = [];
const axeReports = [];
let browserErrors = [];
let cspViolations = [];
const responseHeadersByPath = new Map();

const result = {
    database: 'adasi_portal_test',
    routes,
    checks,
    axeReports,
    cspViolations: [],
    browserErrors: [],
    disposableUsersCleaned: false,
    limitations: [
        'Manual assistive-technology (NVDA/JAWS/VoiceOver) screen-reader speech verification is not automated in headless Chromium.',
    ],
};

function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`CDP timeout: ${method}`));
        }, 25000);
        pending.set(id, {
            resolve: (value) => { clearTimeout(timeout); resolve(value); },
            reject: (error) => { clearTimeout(timeout); reject(error); },
        });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function evaluate(expression) {
    const response = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (response.exceptionDetails) {
        throw new Error(response.exceptionDetails.text + ': ' + (response.exceptionDetails.exception?.description || ''));
    }
    return response.result.value;
}

async function runAxeAudit(label, { targetIframeSelector = null } = {}) {
    const settleExpr = `const settle = async (d) => {
        if (typeof d.getAnimations !== 'function') return;
        const finite = d.getAnimations().filter((a) => {
            const timing = a.effect?.getComputedTiming?.();
            return timing && Number.isFinite(timing.endTime) && timing.endTime > 0 && timing.endTime <= 2000;
        });
        await Promise.race([
            Promise.allSettled(finite.map((a) => a.finished)),
            new Promise((r) => setTimeout(r, 1200)),
        ]);
    };`;

    if (targetIframeSelector) {
        const report = await evaluate(`(async () => {
            ${settleExpr}
            const iframe = document.querySelector(${JSON.stringify(targetIframeSelector)});
            const iwin = iframe.contentWindow;
            const idoc = iframe.contentDocument;
            await settle(idoc);
            if (!iwin.axe) {
                iwin.eval(${JSON.stringify(axeSource)});
            }
            const res = await iwin.axe.run(idoc, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
            });
            return {
                label: ${JSON.stringify(label)},
                passes: res.passes.length,
                violations: res.violations.map((v) => ({
                    id: v.id,
                    impact: v.impact,
                    help: v.help,
                    nodes: v.nodes.slice(0, 5).map((n) => ({ target: n.target, html: (n.html || '').slice(0, 220), summary: n.failureSummary })),
                })),
            };
        })()`);
        axeReports.push(report);
        return report;
    }

    const report = await evaluate(`(async () => {
        ${settleExpr}
        await settle(document);
        if (!window.axe) {
            window.eval(${JSON.stringify(axeSource)});
        }
        const res = await window.axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
        });
        return {
            label: ${JSON.stringify(label)},
            passes: res.passes.length,
            violations: res.violations.map((v) => ({
                id: v.id,
                impact: v.impact,
                help: v.help,
                nodes: v.nodes.slice(0, 5).map((n) => ({ target: n.target, html: (n.html || '').slice(0, 220), summary: n.failureSummary })),
            })),
        };
    })()`);
    axeReports.push(report);
    return report;
}

async function until(expression, timeout = 20000) {
    const expires = Date.now() + timeout;
    while (Date.now() < expires) {
        if (await evaluate(expression)) return;
        await sleep(60);
    }
    const diag = await evaluate('({path:location.pathname,readyState:document.readyState,title:document.title,h1:document.querySelector("h1")?.textContent?.trim(),main:Boolean(document.querySelector("#main-content")),alpine:typeof window.Alpine,dt:Boolean(window.DataTable),wrappers:document.querySelectorAll(".dt-container").length,tables:document.querySelectorAll("table").length,shell:document.documentElement.dataset.workspaceShell})');
    throw new Error(`Browser condition timed out: ${expression}; ${JSON.stringify(diag)}`);
}

async function pressKey(key, code, keyCode) {
    const text = key === 'Enter' ? '\r' : (key === ' ' ? ' ' : undefined);
    await command('Input.dispatchKeyEvent', {
        type: 'keyDown',
        key,
        code,
        windowsVirtualKeyCode: keyCode,
        ...(text ? { text, unmodifiedText: text } : {}),
    });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode });
}

async function navigate(routePath) {
    const prevOrigin = await evaluate('performance.timeOrigin');
    await command('Page.navigate', { url: origin + routePath });
    await until(`performance.timeOrigin !== ${JSON.stringify(prevOrigin)} && document.readyState !== 'loading'`);
    await until('Boolean(document.querySelector("#main-content, #email"))');
}

function recordPass(name, details = {}) {
    checks.push({ name, passed: true, ...details });
    console.log(`PASS ${name}`);
}

try {
    // Wait for PHP built-in server.
    for (let i = 0; i < 100; i += 1) {
        try {
            const res = await fetch(origin + routes.login);
            if (res.ok) break;
        } catch {}
        await sleep(100);
    }

    // Cache SRI-pinned CDN vendor assets locally so Chromium never flakes on external CDN.
    const loginHtml = await (await fetch(origin + routes.login)).text();
    const appLayout = await readFile(path.join(project, 'resources/views/layouts/app.blade.php'), 'utf8');
    const authLayout = await readFile(path.join(project, 'resources/views/layouts/auth.blade.php'), 'utf8');
    const vendorDir = path.join(artifacts, 'vendor');
    await mkdir(vendorDir, { recursive: true });
    for (const match of (loginHtml + appLayout + authLayout).matchAll(/<(?:script|link)\b[^>]*(?:src|href)="(https:\/\/[^" ]+)"[^>]*integrity="(sha384-[^"]+)"[^>]*>/g)) {
        const [, url, integrity] = match;
        if (vendor.has(url)) continue;
        const vendorFile = path.join(vendorDir, createHash('sha256').update(url).digest('hex'));
        let body = existsSync(vendorFile) ? await readFile(vendorFile) : null;
        for (let attempt = 0; !body && attempt < 3; attempt += 1) {
            try {
                const res = await fetch(url, { signal: AbortSignal.timeout(20000) });
                assert.ok(res.ok, `Pinned CDN asset available: ${url}`);
                body = Buffer.from(await res.arrayBuffer());
                await writeFile(vendorFile, body);
            } catch {
                if (attempt === 2) throw new Error(`Pinned CDN asset unavailable: ${url}`);
            }
        }
        assert.equal('sha384-' + createHash('sha384').update(body).digest('base64'), integrity, `SRI mismatch: ${url}`);
        vendor.set(url, { body, type: url.includes('.css') ? 'text/css' : 'application/javascript' });
    }

    let cdpPort;
    for (let i = 0; i < 100; i += 1) {
        try {
            cdpPort = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]);
            break;
        } catch {
            await sleep(100);
        }
    }
    assert.ok(cdpPort, 'Browser CDP available');

    const target = await (await fetch(`http://127.0.0.1:${cdpPort}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    socket.addEventListener('message', (event) => {
        const msg = JSON.parse(event.data);
        if (pending.has(msg.id)) {
            const task = pending.get(msg.id);
            pending.delete(msg.id);
            if (msg.error) task.reject(new Error(msg.error.message));
            else task.resolve(msg.result);
        }
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
            const text = (msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ');
            browserErrors.push({ type: 'console.error', text });
        }
        if (msg.method === 'Runtime.exceptionThrown') {
            const detail = msg.params.exceptionDetails;
            browserErrors.push({
                type: 'exception',
                text: detail.text,
                description: detail.exception?.description || '',
                url: detail.url || '',
            });
        }
        if (msg.method === 'Log.entryAdded') {
            const entry = msg.params.entry;
            if (/content security policy/i.test(entry.text || '')) {
                cspViolations.push({ source: entry.source, text: entry.text, url: entry.url });
            } else if (entry.level === 'error' && entry.source === 'security') {
                cspViolations.push({ source: entry.source, text: entry.text, url: entry.url });
            }
        }
        if (msg.method === 'Network.responseReceived' && msg.params.type === 'Document') {
            try {
                const parsedUrl = new URL(msg.params.response.url);
                const lowerHeaders = {};
                for (const [k, v] of Object.entries(msg.params.response.headers || {})) {
                    lowerHeaders[k.toLowerCase()] = v;
                }
                responseHeadersByPath.set(parsedUrl.pathname, lowerHeaders);
            } catch {}
        }
        if (msg.method === 'Fetch.requestPaused') {
            const { requestId, request } = msg.params;
            const asset = vendor.get(request.url);
            if (asset) {
                command('Fetch.fulfillRequest', {
                    requestId,
                    responseCode: 200,
                    responseHeaders: [
                        { name: 'Content-Type', value: asset.type },
                        { name: 'Access-Control-Allow-Origin', value: '*' },
                    ],
                    body: asset.body.toString('base64'),
                }).catch(() => {});
            } else {
                command('Fetch.continueRequest', { requestId }).catch(() => {});
            }
        }
    });

    await command('Page.enable');
    await command('Page.bringToFront');
    await command('Runtime.enable');
    await command('Network.enable');
    await command('Log.enable');
    await command('Accessibility.enable');
    await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await command('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });

    // Create disposable finance and admin users in adasi_portal_test (cleaned up in finally).
    createdUsers = JSON.parse(await php(`
$password = bin2hex(random_bytes(10)) . 'aA!1';
$finance = App\\Models\\User::factory()->create([
    'name' => 'MWG Browser Audit Finance',
    'email' => 'mwg-${runId}-finance@example.test',
    'password' => Illuminate\\Support\\Facades\\Hash::make($password),
    'role' => 'finance',
    'is_active' => true,
    'account_status' => 'ACTIVE',
    'must_change_password' => false,
]);
$admin = App\\Models\\User::factory()->create([
    'name' => 'MWG Browser Audit Admin',
    'email' => 'mwg-${runId}-admin@example.test',
    'password' => Illuminate\\Support\\Facades\\Hash::make($password),
    'role' => 'admin',
    'is_active' => true,
    'account_status' => 'ACTIVE',
    'must_change_password' => false,
]);
echo json_encode([
    'password' => $password,
    'finance' => ['id' => $finance->id, 'email' => $finance->email],
    'admin' => ['id' => $admin->id, 'email' => $admin->email],
]);
`));
    const createdUser = { ...createdUsers.finance, password: createdUsers.password };

    // -------------------------------------------------------------------------
    // 1. /login — Auth layout, meta tags, autofill, CSP, forced-colors, 200% text scale, REAL visible error + aria-invalid + axe-core
    // -------------------------------------------------------------------------
    await navigate(routes.login);
    await until('Boolean(document.querySelector("#email")) && Boolean(document.querySelector("#password")) && Boolean(window.Alpine)');

    const loginCsp = responseHeadersByPath.get(routes.login)?.['content-security-policy'] || '';
    assert.ok(loginCsp.includes('script-src') && loginCsp.includes('nonce-'), `Login CSP header must enforce nonce: ${loginCsp}`);
    assert.ok(loginCsp.includes("frame-ancestors 'self'") || loginCsp.includes("frame-ancestors 'none'"), `Login CSP frame-ancestors missing: ${loginCsp}`);

    const loginMeta = await evaluate(`({
        textScale: document.querySelector('meta[name="text-scale"]')?.content || null,
        colorSchemeMeta: document.querySelector('meta[name="color-scheme"]')?.content || null,
        hasSkipLink: Boolean(document.querySelector('a.ui-skip-link[href="#main-content"]')),
        hasMain: Boolean(document.querySelector('main#main-content[tabindex="-1"]')),
        emailAutocomplete: document.querySelector('#email')?.getAttribute('autocomplete') || null,
        emailSpellcheck: document.querySelector('#email')?.getAttribute('spellcheck') || null,
        emailDescribedByAtBoot: document.querySelector('#email')?.getAttribute('aria-describedby') || null,
        bgFetchPriority: document.querySelector('img[fetchpriority="high"]')?.getAttribute('fetchpriority') || null,
        imgsMissingDims: Array.from(document.querySelectorAll('img')).filter((i) => !i.getAttribute('width') || !i.getAttribute('height')).length,
    })`);
    assert.equal(loginMeta.textScale, 'scale');
    assert.equal(loginMeta.colorSchemeMeta, 'light dark');
    assert.equal(loginMeta.hasSkipLink, true);
    assert.equal(loginMeta.hasMain, true);
    assert.equal(loginMeta.emailAutocomplete, 'username');
    assert.equal(loginMeta.emailSpellcheck, 'false');
    assert.equal(loginMeta.emailDescribedByAtBoot, null, 'Hidden #email-error must NOT be linked via aria-describedby at boot');
    assert.equal(loginMeta.bgFetchPriority, 'high');
    assert.equal(loginMeta.imgsMissingDims, 0);

    const loginAxe = await runAxeAudit('login-light');

    // Forced-colors + reduced-motion + 200% text scale check on /login.
    await command('Emulation.setEmulatedMedia', {
        features: [
            { name: 'forced-colors', value: 'active' },
            { name: 'prefers-reduced-motion', value: 'reduce' },
        ],
    });
    await evaluate('document.querySelector("#email").focus()');
    await pressKey('Tab', 'Tab', 9);
    await pressKey('Tab', 'Tab', 9);
    const loginForcedFocus = await evaluate(`(() => {
        const el = document.activeElement || document.querySelector('#email');
        const cs = getComputedStyle(el);
        return {
            tag: el.tagName,
            id: el.id,
            forcedColors: matchMedia('(forced-colors: active)').matches,
            reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
            hasFocus: document.hasFocus(),
            focusVisible: el.matches(':focus-visible'),
            outlineStyle: cs.outlineStyle,
            outlineWidth: cs.outlineWidth,
            outlineColor: cs.outlineColor,
        };
    })()`);
    assert.equal(loginForcedFocus.forcedColors, true);
    assert.equal(loginForcedFocus.reducedMotion, true);
    assert.notEqual(loginForcedFocus.outlineStyle, 'none');

    await command('Emulation.setEmulatedMedia', { features: [] });
    const loginScale = await evaluate(`(() => {
        document.documentElement.style.fontSize = '200%';
        const overflow = document.documentElement.scrollWidth - window.innerWidth;
        const bodyFontPx = parseFloat(getComputedStyle(document.body).fontSize);
        document.documentElement.style.fontSize = '';
        return { overflow, bodyFontPx };
    })()`);
    assert.ok(loginScale.overflow <= 2, `/login 200% text scale horizontal overflow: ${loginScale.overflow}px`);
    assert.ok(loginScale.bodyFontPx >= 24, `/login body font did not scale at 200%: ${loginScale.bodyFontPx}px`);

    // Submit invalid login credentials -> wait for REAL visible error alert AND aria-invalid="true" on #email (anti-tautology!).
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(createdUser.email)};
        document.querySelector('#password').value = 'wrong-password-123';
        document.querySelector('form[method="POST"]').requestSubmit();
    })()`);
    await until(`(() => {
        const alertEl = document.querySelector('#adasi-login-error-alert');
        return Boolean(alertEl && !alertEl.hidden && alertEl.textContent.trim().length > 0 && document.querySelector('#email')?.getAttribute('aria-invalid') === 'true');
    })()`, 15000);
    const loginErr = await evaluate(`({
        alertVisible: !document.querySelector('#adasi-login-error-alert').hidden,
        alertTextLen: document.querySelector('#adasi-login-error-alert').textContent.trim().length,
        emailInvalid: document.querySelector('#email')?.getAttribute('aria-invalid'),
        emailDescribedBy: document.querySelector('#email')?.getAttribute('aria-describedby'),
    })`);
    assert.equal(loginErr.alertVisible, true);
    assert.ok(loginErr.alertTextLen > 0);
    assert.equal(loginErr.emailInvalid, 'true');
    assert.ok((loginErr.emailDescribedBy || '').includes('adasi-login-error-alert'));
    recordPass('login-a11y-csp-forced-colors-200scale-visible-error-and-axe', { loginMeta, loginForcedFocus, loginScale, loginErr, axePasses: loginAxe.passes, axeViolations: loginAxe.violations.length });

    // -------------------------------------------------------------------------
    // 2. /forgot-password — Nested <x-ui.dialog> inside #main-content MUST inert sibling background & restore focus (P0-2 live proof!)
    // -------------------------------------------------------------------------
    await navigate(routes.forgotPassword);
    await until('Boolean(document.querySelector("button[aria-haspopup=\\"dialog\\"]")) && Boolean(window.Alpine) && Boolean(window.AdasiOverlayModality)');
    const forgotAxe = await runAxeAudit('forgot-password-light');

    await evaluate(`(() => {
        const trigger = document.querySelector('button[aria-haspopup="dialog"]');
        window.__mwgDialogTrigger = trigger;
        trigger.click();
    })()`);
    await until(`(() => {
        const dialog = document.querySelector('[data-password-assistance] [role="dialog"]');
        return Boolean(dialog && dialog.contains(document.activeElement));
    })()`, 5000);
    const nestedDialogOpen = await evaluate(`(() => {
        const container = document.querySelector('[data-password-assistance]');
        const header = container.querySelector(':scope > header');
        const backLink = container.querySelector(':scope > a[href]');
        const dialog = container.querySelector('[role="dialog"]');
        return {
            headerInert: header?.inert === true,
            backLinkInert: backLink?.inert === true,
            dialogInert: dialog?.closest('[inert]') !== null,
            focusInsideDialog: dialog?.contains(document.activeElement) === true,
            activeTag: document.activeElement?.tagName,
            activeAriaLabel: document.activeElement?.getAttribute?.('aria-label'),
            activeCount: window.AdasiOverlayModality.activeCount(),
        };
    })()`);
    assert.equal(nestedDialogOpen.headerInert, true, `Nested <x-ui.dialog> MUST inert sibling <header> inside #main-content: ${JSON.stringify(nestedDialogOpen)}`);
    assert.equal(nestedDialogOpen.backLinkInert, true, `Nested <x-ui.dialog> MUST inert sibling back link inside #main-content: ${JSON.stringify(nestedDialogOpen)}`);
    assert.equal(nestedDialogOpen.dialogInert, false, `Open <x-ui.dialog> itself must stay interactive: ${JSON.stringify(nestedDialogOpen)}`);
    assert.equal(nestedDialogOpen.focusInsideDialog, true, `Focus must move inside open <x-ui.dialog>: ${JSON.stringify({ nestedDialogOpen, browserErrors })}`);
    assert.equal(nestedDialogOpen.activeCount, 1);

    await pressKey('Escape', 'Escape', 27);
    await until('window.AdasiOverlayModality.activeCount() === 0 && getComputedStyle(document.querySelector("[data-password-assistance] [role=\\"dialog\\"]")).display === "none"');
    const nestedDialogClose = await evaluate(`(() => {
        const container = document.querySelector('[data-password-assistance]');
        return {
            headerInert: container.querySelector(':scope > header')?.inert === true,
            backLinkInert: container.querySelector(':scope > a[href]')?.inert === true,
            bodyDialogOpen: document.body.classList.contains('ui-dialog-open'),
            focusRestored: document.activeElement === window.__mwgDialogTrigger,
        };
    })()`);
    assert.equal(nestedDialogClose.headerInert, false);
    assert.equal(nestedDialogClose.backLinkInert, false);
    assert.equal(nestedDialogClose.bodyDialogOpen, false);
    assert.equal(nestedDialogClose.focusRestored, true);

    // Re-open and close via header X button (regression for Alpine child this.$el vs this.$root bug).
    await evaluate(`window.__mwgDialogTrigger.click()`);
    await until('window.AdasiOverlayModality.activeCount() === 1 && getComputedStyle(document.querySelector("[data-password-assistance] [role=\\"dialog\\"]")).opacity === "1"');
    await evaluate(`document.querySelector('[data-password-assistance] [role="dialog"] header button')?.click()`);
    await until('window.AdasiOverlayModality.activeCount() === 0 && getComputedStyle(document.querySelector("[data-password-assistance] [role=\\"dialog\\"]")).display === "none"');
    const nestedDialogCloseX = await evaluate(`(() => {
        const container = document.querySelector('[data-password-assistance]');
        return {
            headerInert: container.querySelector(':scope > header')?.inert === true,
            backLinkInert: container.querySelector(':scope > a[href]')?.inert === true,
            bodyDialogOpen: document.body.classList.contains('ui-dialog-open'),
            focusRestored: document.activeElement === window.__mwgDialogTrigger,
        };
    })()`);
    assert.equal(nestedDialogCloseX.headerInert, false, `Closing <x-ui.dialog> via X button MUST release inert: ${JSON.stringify(nestedDialogCloseX)}`);
    assert.equal(nestedDialogCloseX.backLinkInert, false);
    assert.equal(nestedDialogCloseX.bodyDialogOpen, false);
    assert.equal(nestedDialogCloseX.focusRestored, true);

    // Re-open and close via backdrop scrim click.
    await evaluate(`window.__mwgDialogTrigger.click()`);
    await until('window.AdasiOverlayModality.activeCount() === 1 && getComputedStyle(document.querySelector("[data-password-assistance] [role=\\"dialog\\"]")).opacity === "1"');
    await evaluate(`document.querySelector('[data-password-assistance] [data-ui-dialog] .ui-scrim')?.click()`);
    await until('window.AdasiOverlayModality.activeCount() === 0 && getComputedStyle(document.querySelector("[data-password-assistance] [role=\\"dialog\\"]")).display === "none"');
    const nestedDialogCloseScrim = await evaluate(`(() => {
        const container = document.querySelector('[data-password-assistance]');
        return {
            headerInert: container.querySelector(':scope > header')?.inert === true,
            backLinkInert: container.querySelector(':scope > a[href]')?.inert === true,
            bodyDialogOpen: document.body.classList.contains('ui-dialog-open'),
            focusRestored: document.activeElement === window.__mwgDialogTrigger,
        };
    })()`);
    assert.equal(nestedDialogCloseScrim.headerInert, false, `Closing <x-ui.dialog> via backdrop scrim MUST release inert: ${JSON.stringify(nestedDialogCloseScrim)}`);
    assert.equal(nestedDialogCloseScrim.backLinkInert, false);
    assert.equal(nestedDialogCloseScrim.bodyDialogOpen, false);
    assert.equal(nestedDialogCloseScrim.focusRestored, true);

    recordPass('forgot-password-nested-dialog-inert-focus-restore-and-axe', {
        nestedDialogOpen,
        nestedDialogClose,
        nestedDialogCloseX,
        nestedDialogCloseScrim,
        axePasses: forgotAxe.passes,
        axeViolations: forgotAxe.violations.length,
    });

    // -------------------------------------------------------------------------
    // 3. Sign in -> WorkspaceShell iframe boundary + Toast top-layer, prior focus, modal suppression + Lightbox + axe-core
    // -------------------------------------------------------------------------
    await navigate(routes.login);
    await until('Boolean(document.querySelector("#email"))');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(createdUser.email)};
        document.querySelector('#password').value = ${JSON.stringify(createdUser.password)};
        document.querySelector('form[method="POST"]').requestSubmit();
    })()`);
    await until('Boolean(document.querySelector("iframe.workspace-frame")) && Boolean(window.Alpine) && Boolean(window.AdasiToast)', 25000);
    await until(`(() => {
        const f = document.querySelector('iframe.workspace-frame');
        try { return Boolean(f?.contentDocument?.readyState === 'complete' && f?.contentDocument?.querySelector('#main-content')); } catch { return false; }
    })()`, 25000);

    const shellAxeOuter = await runAxeAudit('workspace-shell-outer');
    const shellAxeIframe = await runAxeAudit('workspace-shell-iframe', { targetIframeSelector: 'iframe.workspace-frame' });

    const shellCheck = await evaluate(`(() => {
        const iframe = document.querySelector('iframe.workspace-frame');
        const idoc = iframe.contentDocument;
        return {
            isWorkspaceShell: document.documentElement.dataset.workspaceShell === 'true',
            hasOuterMain: Boolean(document.querySelector('main#main-content[tabindex="-1"]')),
            hasOuterSkipLink: Boolean(document.querySelector('a.ui-skip-link[href="#main-content"]')),
            hasIframeMain: Boolean(idoc.querySelector('main#main-content[tabindex="-1"]')),
            hasPreMountedToastAnnouncer: Boolean(document.getElementById('adasi-toast-announcer') && document.getElementById('adasi-toast-announcer-assertive')),
            rootColorSchemeLight: getComputedStyle(document.documentElement).colorScheme,
        };
    })()`);
    assert.equal(shellCheck.isWorkspaceShell, true);
    assert.equal(shellCheck.hasOuterMain, true);
    assert.equal(shellCheck.hasOuterSkipLink, true);
    assert.equal(shellCheck.hasIframeMain, true);
    assert.equal(shellCheck.hasPreMountedToastAnnouncer, true);

    // Trigger toast from INSIDE the embedded iframe -> delegates to TOP window's popover="manual" toast region.
    await evaluate(`(() => {
        const priorBtn = document.querySelector('#sidebar a, .top-navbar button');
        priorBtn.focus();
        window.__mwgPriorToastFocus = priorBtn;
        const iframeWin = document.querySelector('iframe.workspace-frame').contentWindow;
        iframeWin.AdasiToast.show({
            type: 'info',
            title: 'Iframe Delegated Toast',
            message: 'Must render in top viewport without iframe clipping',
            autoClose: 0,
            actions: [{ label: 'Acknowledge', onClick: () => {} }],
        });
    })()`);
    await until('document.querySelectorAll(".adasi-toast-region .adasi-toast").length > 0');
    const toastCheck = await evaluate(`(() => {
        const region = document.querySelector('.adasi-toast-region');
        const toast = region.querySelector('.adasi-toast');
        const rect = toast.getBoundingClientRect();
        const closeBtn = toast.querySelector('.adasi-toast__close');
        closeBtn.focus();
        return {
            popoverAttr: region.getAttribute('popover'),
            isPopoverOpen: typeof region.matches === 'function' ? region.matches(':popover-open') : null,
            articleAriaLive: toast.getAttribute('aria-live'),
            hasActionsCue: toast.textContent.includes('Actions available') || toast.textContent.includes('Tindakan tersedia'),
            inViewport: rect.top >= 0 && rect.left >= 0 && rect.right <= window.innerWidth + 1 && rect.bottom <= window.innerHeight + 1,
            focusedClose: document.activeElement === closeBtn,
        };
    })()`);
    assert.equal(toastCheck.popoverAttr, 'manual');
    assert.equal(toastCheck.isPopoverOpen, true);
    assert.equal(toastCheck.articleAriaLive, 'off', 'Toast article must delegate live announcement to ariaNotify / pre-mounted live region');
    assert.equal(toastCheck.hasActionsCue, true);
    assert.equal(toastCheck.inViewport, true);
    assert.equal(toastCheck.focusedClose, true);

    // Dismiss toast via keyboard Enter -> focus MUST return to prior focused control!
    await pressKey('Enter', 'Enter', 13);
    await until('document.querySelectorAll(".adasi-toast-region .adasi-toast").length === 0');
    const postToastFocusRestored = await evaluate('document.activeElement === window.__mwgPriorToastFocus');
    assert.equal(postToastFocusRestored, true, 'Dismissing the last toast must restore focus to prior control');

    // Lightbox overlay modality + toast suppression while modal is open!
    const lightboxOpenCheck = await evaluate(`(() => {
        const invoker = document.querySelector('#sidebar a, .top-navbar button');
        invoker.focus();
        window.__mwgInvoker = invoker;
        window.AdasiLightbox.open('/assets/images/logo-adasi.png', 'Audit Lightbox');
        window.AdasiToast.info('Deferred while lightbox open', { autoClose: 0 });
        return new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
            const modal = document.getElementById('adasiImageLightbox');
            const dl = document.getElementById('adasiImageLightboxDownload');
            resolve({
                labelledBy: modal.getAttribute('aria-labelledby'),
                downloadLabel: dl.getAttribute('aria-label'),
                mainInert: document.getElementById('mainWrapper').inert === true,
                bodyInlineOverflow: document.body.style.overflow,
                focusInsideLightbox: modal.contains(document.activeElement),
                visibleToastsDuringModal: document.querySelectorAll('.adasi-toast-region .adasi-toast').length,
            });
        })));
    })()`);
    assert.equal(lightboxOpenCheck.labelledBy, 'adasiImageLightboxTitle');
    assert.ok(lightboxOpenCheck.downloadLabel && lightboxOpenCheck.downloadLabel.length > 0);
    assert.equal(lightboxOpenCheck.mainInert, true);
    assert.equal(lightboxOpenCheck.bodyInlineOverflow, '');
    assert.equal(lightboxOpenCheck.focusInsideLightbox, true);
    assert.equal(lightboxOpenCheck.visibleToastsDuringModal, 0, 'New toasts must be suppressed while modal dialog is open');

    await pressKey('Escape', 'Escape', 27);
    await until('document.getElementById("adasiImageLightbox").getAttribute("aria-hidden") === "true"');
    await until('document.querySelectorAll(".adasi-toast-region .adasi-toast").length === 1');
    const lightboxCloseCheck = await evaluate(`(() => {
        const res = {
            mainInert: document.getElementById('mainWrapper').inert === true,
            focusRestored: document.activeElement === window.__mwgInvoker,
            deferredToastSurfaced: document.querySelector('.adasi-toast-region .adasi-toast')?.textContent?.includes('Deferred while lightbox open') === true,
        };
        window.AdasiToast.clear();
        return res;
    })()`);
    assert.equal(lightboxCloseCheck.mainInert, false);
    assert.equal(lightboxCloseCheck.focusRestored, true);
    assert.equal(lightboxCloseCheck.deferredToastSurfaced, true, 'Deferred toast must surface once modal closes');

    // Dark mode color-scheme + axe-core dark mode check.
    await evaluate('document.documentElement.dataset.theme = "dark"');
    const darkColorScheme = await evaluate('getComputedStyle(document.documentElement).colorScheme');
    assert.ok(darkColorScheme.includes('dark'), `Expected dark color-scheme, got: ${darkColorScheme}`);
    const shellAxeDark = await runAxeAudit('workspace-shell-outer-dark');
    await evaluate('document.documentElement.dataset.theme = "light"');

    recordPass('workspace-shell-iframe-toast-suppression-lightbox-and-axe', {
        shellCheck, toastCheck, postToastFocusRestored, lightboxOpenCheck, lightboxCloseCheck, darkColorScheme,
        axeOuterPasses: shellAxeOuter.passes, axeIframePasses: shellAxeIframe.passes, axeDarkPasses: shellAxeDark.passes,
    });

    // -------------------------------------------------------------------------
    // 4. Standalone Dashboard, Sortable Table (/finance/drp-supplier), and DataTables (/admin/users) + axe-core
    // -------------------------------------------------------------------------
    await navigate(routes.dashboard + '?_mwg_shell=off');
    await until('Boolean(document.querySelector("#main-content")) && Boolean(window.Alpine)');
    const dashAxe = await runAxeAudit('standalone-dashboard');
    const dashScale = await evaluate(`(() => {
        document.documentElement.style.fontSize = '200%';
        const overflow = document.documentElement.scrollWidth - window.innerWidth;
        document.documentElement.style.fontSize = '';
        window.AdasiLightbox.open('/assets/images/logo-adasi.png', 'Standalone Overflow Check');
        const bodyOverflowOpen = getComputedStyle(document.body).overflow;
        window.AdasiLightbox.close();
        return { overflow, bodyOverflowOpen };
    })()`);
    assert.ok(dashScale.overflow <= 2, `Dashboard 200% text scale overflow: ${dashScale.overflow}px`);
    assert.notEqual(dashScale.bodyOverflowOpen, 'hidden');
    recordPass('standalone-dashboard-200scale-and-axe', { ...dashScale, axePasses: dashAxe.passes });

    await navigate(routes.drpSupplier + '?_mwg_shell=off');
    await until('Boolean(document.querySelector("#main-content"))');
    const drpAxe = await runAxeAudit('drp-supplier');
    const drpCheck = await evaluate(`(() => {
        const ariaSortNone = document.querySelectorAll('th[aria-sort="none"]').length;
        const missingScope = Array.from(document.querySelectorAll('thead th')).filter((th) => !th.getAttribute('scope')).length;
        return { ariaSortNone, missingScope };
    })()`);
    assert.equal(drpCheck.ariaSortNone, 0);
    assert.equal(drpCheck.missingScope, 0);
    recordPass('drp-supplier-sortable-headers-scope-and-axe', { ...drpCheck, axePasses: drpAxe.passes });

    // Sign in as disposable admin user to audit live DataTables 3 page (/admin/users).
    await command('Network.clearBrowserCookies');
    await navigate(routes.login);
    await until('Boolean(document.querySelector("#email"))');
    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(createdUsers.admin.email)};
        document.querySelector('#password').value = ${JSON.stringify(createdUsers.password)};
        document.querySelector('form[method="POST"]').requestSubmit();
    })()`);
    await until('location.pathname !== ' + JSON.stringify(routes.login) + ' && Boolean(document.querySelector("#main-content"))', 25000);

    await navigate(routes.adminUsers + '?_mwg_shell=off');
    await until('Boolean(document.querySelector("#main-content")) && Boolean(window.DataTable) && document.querySelectorAll(".dt-container").length > 0', 25000);
    await sleep(500);
    const dtAxe = await runAxeAudit('admin-users-datatables');
    const dtCheck = await evaluate(`(() => {
        const ariaSortNone = document.querySelectorAll('th[aria-sort="none"]').length;
        const allScrollContainers = document.querySelectorAll('.ui-data-table__scroll').length;
        const scrollRegions = Array.from(document.querySelectorAll('.ui-data-table > .ui-data-table__scroll')).map((el) => ({
            role: el.getAttribute('role'),
            tabindex: el.getAttribute('tabindex'),
            label: el.getAttribute('aria-label'),
        }));
        const hasDtWrapper = document.querySelectorAll('.dt-container').length > 0;
        document.documentElement.style.fontSize = '200%';
        const overflow = document.documentElement.scrollWidth - window.innerWidth;
        document.documentElement.style.fontSize = '';
        return { hasDtWrapper, ariaSortNone, allScrollContainers, scrollRegions, overflow };
    })()`);
    assert.equal(dtCheck.hasDtWrapper, true);
    assert.equal(dtCheck.ariaSortNone, 0);
    assert.equal(dtCheck.allScrollContainers, 1, 'admin/users must have exactly 1 .ui-data-table__scroll wrapper (no nested scroll duplication)');
    assert.ok(dtCheck.scrollRegions.length === 1 && dtCheck.scrollRegions[0].role === 'region' && dtCheck.scrollRegions[0].tabindex === '0' && dtCheck.scrollRegions[0].label);
    assert.ok(dtCheck.overflow <= 2);
    recordPass('datatables-aria-sort-single-scroll-region-200scale-and-axe', { ...dtCheck, axePasses: dtAxe.passes });

    // Final axe-core WCAG 2.2 AA, CSP & runtime exception gate.
    const blockingAxe = axeReports
        .map((r) => ({ label: r.label, violations: r.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious') }))
        .filter((r) => r.violations.length > 0);
    assert.equal(blockingAxe.length, 0, `axe-core WCAG 2.2 AA critical/serious violations across pages: ${JSON.stringify(blockingAxe, null, 2)}`);

    result.cspViolations = cspViolations;
    result.browserErrors = browserErrors;
    assert.equal(cspViolations.length, 0, `Runtime CSP violations detected: ${JSON.stringify(cspViolations)}`);
    assert.equal(browserErrors.length, 0, `Uncaught browser runtime exceptions detected: ${JSON.stringify(browserErrors)}`);
    recordPass('zero-axe-blocking-zero-csp-and-zero-runtime-errors');
} catch (error) {
    result.failed = { message: error.message, stack: error.stack };
    console.error(`FAIL ${error.message}`);
    process.exitCode = 1;
} finally {
    if (createdUsers) {
        const ids = [createdUsers.finance.id, createdUsers.admin.id];
        try {
            await php(`
$ids = json_decode(getenv('QA_USER_IDS'), true);
foreach ($ids as $id) {
    $user = App\\Models\\User::find((int) $id);
    if ($user) {
        if (!str_starts_with($user->email, 'mwg-${runId}-')) throw new RuntimeException('Cleanup identity mismatch');
        $user->delete();
    }
}
echo 'cleaned';
`, { QA_USER_IDS: JSON.stringify(ids) });
            result.disposableUsersCleaned = true;
        } catch (cleanupError) {
            result.cleanupError = String(cleanupError?.message || cleanupError);
            console.error(`WARN cleanup failed: ${result.cleanupError}`);
        }
    }
    await writeFile(path.join(artifacts, 'result.json'), JSON.stringify(result, null, 2));
    socket?.close();
    browser.kill();
    server.kill();
    await sleep(300);
    await rm(path.join(artifacts, 'sessions'), { recursive: true, force: true, maxRetries: 3, retryDelay: 100 }).catch(() => {});
    await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }).catch(() => {});
    console.log(`${checks.length} browser audit checks passed (${axeReports.length} axe-core WCAG 2.2 AA scans); report at storage/framework/testing/modern-web-guidance/result.json`);
}
