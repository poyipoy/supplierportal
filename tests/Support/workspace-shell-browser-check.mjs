// Serial headless Chrome CDP verification for Persistent Workspace Shell v2 (Infor LN Tab Mechanics).
// Runs against isolated adasi_portal_test MySQL database with a disposable purchasing user; .env is never modified.
import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { copyFile, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';

const project = process.cwd();
const artifacts = path.join(project, 'storage/framework/testing/workspace-shell');
const brainArtifacts = process.env.WS_BRAIN_ARTIFACTS_DIR || '';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const runId = randomBytes(6).toString('hex');

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
    AUTH_SPLASH_ENABLED: 'true',
    WORKSPACE_SHELL_ENABLED: 'true',
    BCRYPT_ROUNDS: '4',
};

const phpBootstrap = `require ${JSON.stringify(path.join(project, 'vendor/autoload.php').replaceAll('\\', '/'))};
$app=require ${JSON.stringify(path.join(project, 'bootstrap/app.php').replaceAll('\\', '/'))};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    throw new RuntimeException('Unsafe browser test database');
}
`;

function php(code, extraEnv = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn('php', [], {
            cwd: project,
            env: { ...env, ...extraEnv },
            windowsHide: true,
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        let out = '';
        let error = '';
        child.stdout.on('data', chunk => { out += chunk; });
        child.stderr.on('data', chunk => { error += chunk; });
        child.on('error', reject);
        child.on('exit', status => (
            status === 0
                ? resolve(out.trim())
                : reject(new Error(`PHP QA helper failed (${status}): ${error || out}`))
        ));
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
if ($file && str_starts_with($file, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
    $mime = 'application/octet-stream';
    if (str_ends_with($file, '.js')) $mime = 'application/javascript';
    elseif (str_ends_with($file, '.css')) $mime = 'text/css';
    elseif (str_ends_with($file, '.png')) $mime = 'image/png';
    elseif (str_ends_with($file, '.gif')) $mime = 'image/gif';
    elseif (str_ends_with($file, '.woff2')) $mime = 'font/woff2';
    elseif (str_ends_with($file, '.woff')) $mime = 'font/woff';
    header('Content-Type: ' . $mime);
    readfile($file);
    return;
}
require $project.'/vendor/autoload.php';
$app=require $project.'/bootstrap/app.php';
$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
if (Illuminate\\Support\\Facades\\DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    throw new RuntimeException('Unsafe browser test database');
}
config([
    'app.url' => getenv('APP_URL'),
    'ui.auth_splash.enabled' => true,
    'ui.workspace_shell.enabled' => true,
    'portal_session.cross_tab_logout' => true,
    'portal_session.scoped_storage_cleanup' => true,
    'portal_session.logout_all_devices' => true,
    'session.driver' => 'file',
    'session.files' => __DIR__.'/sessions',
    'session.secure' => false,
    'cache.default' => 'array',
    'auth_security.turnstile.site_key' => null,
    'auth_security.turnstile.secret_key' => null,
    'auth_security.login.tarpit.enabled' => false,
    'mail.default' => 'array',
    'broadcasting.default' => 'null',
]);
$request=Illuminate\\Http\\Request::capture();
$response=$kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
`;
await writeFile(router, routerSource);

const portServer = createServer();
await new Promise(resolve => portServer.listen(0, '127.0.0.1', resolve));
const httpPort = portServer.address().port;
await new Promise(resolve => portServer.close(resolve));
const origin = `http://127.0.0.1:${httpPort}`;
env.APP_URL = origin;

const server = spawn('php', ['-S', `127.0.0.1:${httpPort}`, '-t', path.join(project, 'public'), router], {
    cwd: project,
    env,
    windowsHide: true,
    stdio: 'ignore',
});

const profile = await mkdtemp(path.join(tmpdir(), 'adasi-ws-shell-browser-'));
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
let userId = null;
let periodId = null;
const pending = new Map();
const vendor = new Map();
const browserErrors = [];
const capturedRequests = [];
const responseHeaders = new Map();
const findings = [];

function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`CDP timeout: ${method}`));
        }, 25000);
        pending.set(id, {
            resolve: value => { clearTimeout(timeout); resolve(value); },
            reject: error => { clearTimeout(timeout); reject(error); },
        });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function evaluate(expression) {
    const response = await command('Runtime.evaluate', {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });
    if (response.exceptionDetails) {
        throw new Error(response.exceptionDetails.text + ': ' + (response.exceptionDetails.exception?.description || ''));
    }
    return response.result.value;
}

async function until(expression, timeout = 15000, pollMs = 50) {
    const expires = Date.now() + timeout;
    while (Date.now() < expires) {
        if (await evaluate(expression)) return;
        await sleep(pollMs);
    }
    const diag = await evaluate(`({href:location.href,ready:document.readyState,tabs:Array.from(document.querySelectorAll("[data-workspace-session]")).map(t=>t.dataset.workspaceSession),frames:Array.from(document.querySelectorAll("[data-workspace-frame]")).map(f=>{try{return{key:f.dataset.workspaceFrame,src:f.src,ready:f.contentDocument?.readyState,docHref:f.contentWindow?.location?.href,title:f.contentDocument?.title};}catch(e){return{key:f.dataset.workspaceFrame,src:f.src,err:String(e)};}})})`).catch(() => ({}));
    throw new Error(`Condition timed out: ${expression}; diag=${JSON.stringify(diag)}`);
}

async function recordFinding(name, data = {}) {
    findings.push({ name, passed: true, ...data });
    console.log(`PASS ${name}`);
}

try {
    for (let i = 0; i < 100; i++) {
        try {
            const resp = await fetch(`${origin}/login`);
            if (resp.ok) break;
        } catch {}
        await sleep(100);
    }

    // Cache & integrity-verify pinned CDN scripts/CSS locally for deterministic headless runs.
    const appAssets = await readFile(path.join(project, 'resources/views/layouts/app.blade.php'), 'utf8');
    const dashboardAssets = await readFile(path.join(project, 'resources/views/purchasing/dashboard.blade.php'), 'utf8');
    const loginHtml = await (await fetch(`${origin}/login`)).text();
    const vendorDir = path.join(artifacts, 'vendor');
    await mkdir(vendorDir, { recursive: true });
    for (const match of (loginHtml + appAssets + dashboardAssets).matchAll(/<(?:script|link)\b[^>]*(?:src|href)="(https:\/\/[^" ]+)"[^>]*integrity="(sha384-[^"]+)"[^>]*>/g)) {
        const [, url, integrity] = match;
        if (vendor.has(url)) continue;
        const vendorFile = path.join(vendorDir, createHash('sha256').update(url).digest('hex'));
        let body = existsSync(vendorFile) ? await readFile(vendorFile) : null;
        for (let attempt = 0; !body && attempt < 3; attempt++) {
            const r = await fetch(url, { signal: AbortSignal.timeout(20000) });
            assert.ok(r.ok, `CDN fetch ok: ${url}`);
            body = Buffer.from(await r.arrayBuffer());
            await writeFile(vendorFile, body);
        }
        assert.equal('sha384-' + createHash('sha384').update(body).digest('base64'), integrity);
        vendor.set(url, { body, type: url.includes('.css') ? 'text/css' : 'application/javascript' });
    }

    let cdpPort;
    for (let i = 0; i < 100; i++) {
        try {
            cdpPort = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]);
            break;
        } catch {
            await sleep(100);
        }
    }
    assert.ok(Number.isFinite(cdpPort), 'Chrome CDP port available');
    const targets = await (await fetch(`http://127.0.0.1:${cdpPort}/json`)).json();
    const target = targets.find(item => item.type === 'page');
    assert.ok(target?.webSocketDebuggerUrl);

    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    socket.addEventListener('message', async event => {
        const msg = JSON.parse(event.data.toString());
        if (msg.id) {
            const entry = pending.get(msg.id);
            if (!entry) return;
            pending.delete(msg.id);
            if (msg.error) entry.reject(new Error(msg.error.message));
            else entry.resolve(msg.result);
            return;
        }
        if (msg.method === 'Runtime.exceptionThrown') {
            const det = msg.params.exceptionDetails;
            const detail = det?.exception?.description || det?.exception?.value || det?.text || 'Uncaught exception';
            const line = `${det?.text || 'Error'} @ ${det?.url || ''}:${det?.lineNumber ?? ''}: ${detail} (after step ${findings.length})`;
            console.error('[CDP EXCEPTION]', line);
            browserErrors.push(line);
        }
        if (msg.method === 'Fetch.requestPaused') {
            const { requestId, request } = msg.params;
            const clean = request.url.split('#')[0];
            if (vendor.has(clean)) {
                const hit = vendor.get(clean);
                await command('Fetch.fulfillRequest', {
                    requestId,
                    responseCode: 200,
                    responseHeaders: [
                        { name: 'Content-Type', value: hit.type },
                        { name: 'Access-Control-Allow-Origin', value: '*' },
                    ],
                    body: hit.body.toString('base64'),
                }).catch(() => {});
                return;
            }
            await command('Fetch.continueRequest', { requestId }).catch(() => {});
        }
        if (msg.method === 'Network.requestWillBeSent') {
            const { request } = msg.params;
            if (request.url.startsWith(origin)) {
                capturedRequests.push({
                    url: request.url,
                    type: msg.params.type,
                    method: request.method,
                });
            }
        }
        if (msg.method === 'Network.responseReceived') {
            const { response } = msg.params;
            if (response.url.startsWith(origin) && msg.params.type === 'Document') {
                responseHeaders.set(response.url, response.headers);
            }
        }
    });

    await command('Page.enable');
    await command('Runtime.enable');
    await command('Network.enable');
    await command('Fetch.enable', { patterns: [{ urlPattern: 'https://*', requestStage: 'Request' }] });
    await command('Emulation.setDeviceMetricsOverride', {
        width: 1366,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });

    // Create disposable purchasing account + open Period so PR Create route stays accessible
    const email = `ws-qa-${runId}@example.com`;
    const password = `WsShell!${runId}99`;
    const created = JSON.parse(await php(`
        $user = App\\Models\\User::factory()->create([
            'name' => 'Workspace QA ${runId}',
            'email' => ${JSON.stringify(email)},
            'password' => Illuminate\\Support\\Facades\\Hash::make(${JSON.stringify(password)}),
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $period = App\\Models\\Period::create([
            'name' => 'WS QA Period ${runId}',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $user->id,
        ]);
        echo json_encode(['id' => $user->id, 'periodId' => $period->id]);
    `));
    userId = created.id;
    periodId = created.periodId;

    // 1. Login -> AuthSplash -> Initial Dashboard Iframe + Shortcuts pinned leftmost
    await command('Page.navigate', { url: `${origin}/login` });
    await until('Boolean(document.querySelector("#email") && document.querySelector("[data-auth-splash]")?.dataset.state !== "waiting")');

    await evaluate(`(() => {
        document.querySelector('#email').value = ${JSON.stringify(email)};
        document.querySelector('#password').value = ${JSON.stringify(password)};
        document.querySelector('form[method="POST"]').submit();
    })()`);

    await until('Boolean(document.querySelector("html[data-workspace-shell]") && document.querySelector("[data-workspace-session]") && window.AdasiAuthSplash?.ready)', 25000);
    await until('!window.AdasiAuthSplash || window.AdasiAuthSplash.state === "REMOVED"', 25000);
    await until('document.querySelector("[data-workspace-frame]")?.contentDocument?.readyState === "complete"', 25000);

    const waitFrameSettled = async (frameSelector) => {
        try {
            await until(`(() => {
                try {
                    const f = document.querySelector(${JSON.stringify(frameSelector)});
                    const w = f?.contentWindow;
                    const d = f?.contentDocument;
                    return Boolean(
                        f
                        && w?.location?.href?.includes('_embed=1')
                        && d?.readyState === 'complete'
                        && d.querySelector('#main-content')
                        && !d.querySelector('#adasiLoader.active')
                        && !d.querySelector('.dt-container.is-loading')
                        && (!w?.jQuery || w.jQuery.active === 0)
                        && (!w?.AdasiEmbedReadiness || w.AdasiEmbedReadiness.isReady)
                    );
                } catch {
                    return false;
                }
            })()`);
        } catch (err) {
            const extra = await evaluate(`(() => {
                const panels = Array.from(document.querySelectorAll('.workspace-panel.is-active')).map(p => {
                    const f = p.querySelector('iframe');
                    let href = null;
                    let ready = null;
                    let hasMain = false;
                    let loaderActive = false;
                    let jqActive = null;
                    try {
                        href = f?.contentWindow?.location?.href || null;
                        ready = f?.contentDocument?.readyState || null;
                        hasMain = Boolean(f?.contentDocument?.querySelector('#main-content'));
                        loaderActive = Boolean(f?.contentDocument?.querySelector('#adasiLoader.active'));
                        jqActive = f?.contentWindow?.jQuery?.active ?? null;
                    } catch {}
                    return { id: p.dataset.workspacePanel, hidden: p.hidden, ariaBusy: p.getAttribute('aria-busy'), src: f?.src, ready, href, hasMain, loaderActive, jqActive };
                });
                const prGrp = document.querySelector('[data-workspace-module="requisitions"]')?.outerHTML?.slice(0, 300);
                return { panels, activeGroup: document.querySelector('.workspace-session-group.is-active')?.dataset.workspaceGroup, activeTab: document.querySelector('.workspace-session.is-active')?.dataset.workspaceSession, prGrp };
            })()`).catch(() => ({}));
            throw new Error(`${err.message}; activePanels=${JSON.stringify(extra)}`);
        }
    };

    const waitGroupActive = async (groupSelectorExpr) => {
        await until(`(() => {
            const g = ${groupSelectorExpr};
            const activeTab = g?.querySelector('[data-workspace-session].is-active');
            return Boolean(g?.classList.contains('is-active') && activeTab && activeTab.dataset.loading !== 'true' && activeTab.dataset.queued !== 'true');
        })()`, 20000);
        await waitFrameSettled('.workspace-panel.is-active iframe');
    };

    const initialState = await evaluate(`(() => {
        const html = document.documentElement;
        const shortcutsTab = document.querySelector('[data-workspace-session="shortcuts"]');
        const activeTab = document.querySelector('.workspace-session.is-active');
        const activeFrame = document.querySelector('.workspace-panel.is-active iframe');
        const refreshRect = document.querySelector('[data-workspace-refresh]')?.getBoundingClientRect();
        const brandRect = document.querySelector('.sidebar-brand')?.getBoundingClientRect();
        const topbarRect = document.querySelector('.top-navbar')?.getBoundingClientRect();
        const sidebarControlRect = document.querySelector('.sidebar-control')?.getBoundingClientRect();
        const tabbarRect = document.querySelector('[data-workspace-sessionbar]')?.getBoundingClientRect();
        const userTriggerRect = document.querySelector('.topbar-user-trigger')?.getBoundingClientRect();
        const langPillRect = document.querySelector('.top-navbar .lang-pill-btn')?.getBoundingClientRect();
        const iconBtnRect = document.querySelector('.top-navbar .topbar-icon-btn')?.getBoundingClientRect();
        return {
            isShell: html.dataset.workspaceShell === 'true',
            tabCount: document.querySelectorAll('[data-workspace-session]').length,
            shortcutsPinned: shortcutsTab?.dataset.pinned === 'true',
            shortcutsHasClose: Boolean(shortcutsTab?.querySelector('[data-workspace-session-close]')),
            activeModule: document.querySelector('.workspace-session-group.is-active')?.dataset.workspaceModule,
            initialHasClose: Boolean(activeTab?.querySelector('[data-workspace-session-close]')),
            embedAttrInFrame: activeFrame?.contentDocument?.documentElement?.dataset.workspaceEmbed,
            embedTopbarHeight: activeFrame?.contentWindow?.getComputedStyle(activeFrame.contentDocument.documentElement).getPropertyValue('--topbar-height')?.trim(),
            initialTabLabel: activeTab?.querySelector('.workspace-session__label')?.textContent?.trim(),
            duplicateSidebarInFrame: Boolean(activeFrame?.contentDocument?.querySelector('#sidebar')),
            topOverflowX: document.documentElement.scrollWidth > window.innerWidth,
            refreshBtnSize: refreshRect ? [refreshRect.width, refreshRect.height] : null,
            desktopBrandHeight: brandRect ? Math.round(brandRect.height) : 0,
            desktopTopbarHeight: topbarRect ? Math.round(topbarRect.height) : 0,
            desktopControlBottom: sidebarControlRect ? Math.round(sidebarControlRect.bottom) : 0,
            desktopTabbarBottom: tabbarRect ? Math.round(tabbarRect.bottom) : 0,
            userTriggerSize: userTriggerRect ? [Math.round(userTriggerRect.width), Math.round(userTriggerRect.height)] : null,
            userChipHasInlineEmail: Boolean(document.querySelector('.topbar-user-chip .ui-user-chip__meta')),
            userDropdownHasEmail: Boolean(document.querySelector('.top-navbar .dropdown-item-text span')?.textContent?.includes('@')),
            langPillSize: langPillRect ? [Math.round(langPillRect.width), Math.round(langPillRect.height)] : null,
            topbarIconSize: iconBtnRect ? [Math.round(iconBtnRect.width), Math.round(iconBtnRect.height)] : null,
        };
    })()`);
    assert.equal(initialState.isShell, true);
    assert.equal(initialState.tabCount, 2, 'Initial shell should show pinned Shortcuts + Dashboard');
    assert.equal(initialState.shortcutsPinned, true);
    assert.equal(initialState.shortcutsHasClose, false);
    assert.equal(initialState.activeModule, 'dashboard');
    assert.equal(initialState.initialTabLabel, 'Dashboard', 'Initial workspace tab must keep canonical menu name Dashboard');
    assert.equal(initialState.initialHasClose, true);
    assert.equal(initialState.embedAttrInFrame, 'true');
    assert.equal(initialState.embedTopbarHeight, '0px', 'Embedded iframe must zero --topbar-height for sticky headers & scroll spacing');
    assert.equal(initialState.duplicateSidebarInFrame, false);
    assert.equal(initialState.topOverflowX, false);
    assert.ok(initialState.refreshBtnSize[0] >= 24 && initialState.refreshBtnSize[1] >= 24);
    assert.equal(initialState.desktopBrandHeight, 40, 'Desktop workspace sidebar brand height must be calibrated to Infor LN 40px');
    assert.equal(initialState.desktopTopbarHeight, 40, 'Desktop workspace topbar height must be calibrated to Infor LN 40px');
    assert.ok(Math.abs(initialState.desktopControlBottom - initialState.desktopTabbarBottom) <= 1, 'Sidebar control divider must align cleanly with workspace tabbar divider');
    assert.equal(initialState.userChipHasInlineEmail, false, 'Topbar user chip must stay single-line without stacked inline email');
    assert.equal(initialState.userDropdownHasEmail, true, 'User dropdown must retain account email');
    assert.ok(initialState.userTriggerSize[1] >= 24 && initialState.userTriggerSize[1] <= 38, 'Single-line topbar user trigger stays compact within 44px header');
    assert.ok(initialState.langPillSize[0] >= 24 && initialState.langPillSize[1] >= 24, 'Desktop locale pill maintains >= 24x24px WCAG target size');
    assert.ok(initialState.topbarIconSize[0] >= 24 && initialState.topbarIconSize[1] >= 24, 'Desktop topbar icon button maintains >= 24x24px WCAG target size');
    await recordFinding('1_initial_shell_shortcuts_pinned_and_dashboard_closable', initialState);

    // 2. Multi-Instance Same Menu: click Purchase Requisition 3 times via Sidebar -> 3 independent workspaces
    for (let i = 1; i <= 3; i++) {
        await evaluate(`document.querySelector('#sidebar a.sidebar-link[href*="requisitions"]')?.click()`);
        await until(`document.querySelectorAll('[data-workspace-group][data-workspace-module="requisitions"]').length === ${i}`);
        await waitGroupActive(`document.querySelectorAll('[data-workspace-group][data-workspace-module="requisitions"]')[${i - 1}]`);
    }

    const prGroupIds = await evaluate(`Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="requisitions"]')).map(el => el.dataset.workspaceGroup)`);
    assert.equal(prGroupIds.length, 3, 'Clicking Requisitions 3x must open 3 independent workspace groups');
    const dupBadges = await evaluate(`Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="requisitions"] .workspace-session__dup-index')).map(el => el.textContent.trim())`);
    assert.deepEqual(dupBadges, ['(2)', '(3)']);
    const prTabLabels = await evaluate(`Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="requisitions"] .workspace-session__label')).map(el => el.textContent.trim())`);
    assert.deepEqual(prTabLabels, ['Purchase Requisition', 'Purchase Requisition', 'Purchase Requisition'], 'Requisitions tabs must retain exact sidebar menu name');

    // Switch back to PR instance #1 and seed DOM marker to verify 0ms keep-alive preservation
    await evaluate(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session-select]')?.click()`);
    await waitGroupActive(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"]')`);
    const seededMarker = await evaluate(`(() => {
        const frame = document.querySelector('.workspace-panel.is-active iframe');
        const input = frame?.contentDocument?.querySelector('.dt-search input[type="search"], #main-content input:not([type="hidden"])');
        if (!input) return null;
        input.value = 'INFOR-MULTI-PR-1';
        input.dataset.wsMarker = 'pr-1';
        return frame.dataset.workspaceFrame;
    })()`);
    assert.ok(seededMarker, 'PR #1 frame input marker must exist');
    await recordFinding('2_multi_instance_duplicate_tabs_same_menu', { prGroupIds, dupBadges });

    // 3. Joined Parent-Child Workspace ([PR List | Create PR x]) + Auto-Collapse Stacked Pill
    await evaluate(`(() => {
        const frameDoc = document.querySelector('.workspace-panel.is-active iframe')?.contentDocument;
        frameDoc?.querySelector('a[href*="requisitions/create"]')?.click();
    })()`);
    await until(`document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session]').length === 2`);
    await until(`(() => {
        const tabs = document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session]');
        return Boolean(tabs[1]?.classList.contains('is-active') && tabs[1].dataset.loading !== 'true' && tabs[1].dataset.queued !== 'true');
    })()`, 20000);
    await waitFrameSettled('.workspace-panel.is-active iframe');

    const expandedPrState = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const tabs = Array.from(grp?.querySelectorAll('[data-workspace-session]') || []);
        return {
            isExpanded: grp?.classList.contains('is-expanded'),
            subTabCount: tabs.length,
            parentHasClose: Boolean(tabs[0]?.querySelector('[data-workspace-session-close]')),
            childHasClose: Boolean(tabs[1]?.querySelector('[data-workspace-session-close]')),
            childActive: tabs[1]?.classList.contains('is-active'),
            parentInputStillAlive: document.querySelector('[data-workspace-frame="${seededMarker}"]')?.contentDocument?.querySelector('[data-ws-marker="pr-1"]')?.value || null,
        };
    })()`);
    assert.equal(expandedPrState.isExpanded, true);
    assert.equal(expandedPrState.subTabCount, 2);
    assert.equal(expandedPrState.parentHasClose, false);
    assert.equal(expandedPrState.childHasClose, true);
    assert.equal(expandedPrState.childActive, true);
    assert.equal(expandedPrState.parentInputStillAlive, 'INFOR-MULTI-PR-1', 'Parent frame must remain untouched in background');

    // Open Import Suppliers & drill to Create Import Supplier so we have both a collapsed stacked group and an expanded group simultaneously
    await evaluate(`document.querySelector('#sidebar a.sidebar-link[href*="import-suppliers"]')?.click()`);
    await until('document.querySelectorAll(\'[data-workspace-group][data-workspace-module="import-suppliers"]\').length === 1');
    await waitGroupActive(`document.querySelector('[data-workspace-group][data-workspace-module="import-suppliers"]')`);
    const supGroupId = await evaluate(`document.querySelector('[data-workspace-group][data-workspace-module="import-suppliers"]')?.dataset.workspaceGroup`);
    await evaluate(`document.querySelector('.workspace-panel.is-active iframe')?.contentDocument?.querySelector('a[href*="import-suppliers/create"]')?.click()`);
    await until(`document.querySelectorAll('[data-workspace-group="${supGroupId}"] [data-workspace-session]').length === 2`);
    await until(`(() => {
        const tabs = document.querySelectorAll('[data-workspace-group="${supGroupId}"] [data-workspace-session]');
        return Boolean(tabs[1]?.classList.contains('is-active') && tabs[1].dataset.loading !== 'true' && tabs[1].dataset.queued !== 'true');
    })()`, 20000);
    await waitFrameSettled('.workspace-panel.is-active iframe');

    const stackedAndExpandedState = await evaluate(`(() => {
        const prGrp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const supGrp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const supChildHref = document.querySelector('[data-workspace-panel].is-active iframe')?.contentWindow?.location?.href || '';
        const supReturnUrl = new URL(supChildHref).searchParams.get('return_url') || '';
        return {
            prCollapsedStack: prGrp?.classList.contains('is-collapsed-stack'),
            prStackBadge: prGrp?.querySelector('.workspace-session__stack-count')?.textContent?.trim(),
            prHasLayersIcon: Boolean(prGrp?.querySelector('.workspace-session__icon svg')),
            supExpanded: supGrp?.classList.contains('is-expanded'),
            supLabels: Array.from(supGrp?.querySelectorAll('.workspace-session__label') || []).map(el => el.textContent.trim()),
            topbarTitle: document.querySelector('.top-navbar .topbar-page-title')?.textContent?.trim(),
            supReturnClean: !supReturnUrl.includes('_embed='),
            switcherVisible: !document.querySelector('[data-workspace-switcher]')?.hidden,
        };
    })()`);
    assert.equal(stackedAndExpandedState.prCollapsedStack, true);
    assert.equal(stackedAndExpandedState.prStackBadge, '2');
    assert.equal(stackedAndExpandedState.prHasLayersIcon, true);
    assert.equal(stackedAndExpandedState.supExpanded, true);
    assert.deepEqual(stackedAndExpandedState.supLabels, ['Import Suppliers', 'Register Import Supplier']);
    assert.equal(stackedAndExpandedState.topbarTitle, 'Import Suppliers — Register Import Supplier');
    assert.equal(stackedAndExpandedState.supReturnClean, true, 'return_url passed to child drilldown must never leak _embed=1');
    assert.equal(stackedAndExpandedState.switcherVisible, true, 'Open Workspaces switcher should be visible when >= 2 groups open');

    await sleep(220);
    const shotLight = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(path.join(artifacts, 'ws-shell-v2-1366-light.png'), Buffer.from(shotLight.data, 'base64'));

    // Verify Open Workspaces switcher button opens all-workspaces popover cleanly and closes on Escape
    await evaluate(`document.querySelector('[data-workspace-switcher]')?.click()`);
    await until('!document.querySelector("[data-workspace-session-menu]")?.hidden');
    const switcherCount = await evaluate(`document.querySelectorAll('[data-workspace-session-menu] [role="menuitem"]').length`);
    assert.ok(switcherCount >= 6, 'Workspace switcher must list open workspace groups');
    await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); document.querySelector('[data-workspace-session-menu]')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));`);
    await until('document.querySelector("[data-workspace-session-menu]")?.hidden');

    // Click collapsed stacked PR group caret menu & jump straight back to PR Parent
    await evaluate(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-group-menu]')?.click()`);
    await until('!document.querySelector("[data-workspace-session-menu]")?.hidden');
    const menuItemsCount = await evaluate(`document.querySelectorAll('[data-workspace-session-menu] [role="menuitem"]').length`);
    assert.ok(menuItemsCount >= 6, 'Stack menu must list sub-frames + Duplicate + Close actions');

    await evaluate(`document.querySelector('[data-workspace-session-menu] [role="menuitem"]')?.click()`);
    await waitGroupActive(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"]')`);
    const backToPrParent = await evaluate(`(() => {
        const prGrp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const supGrp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const prFrame = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        const restored = prFrame?.contentDocument?.querySelector('[data-ws-marker="pr-1"]')?.value || null;
        return {
            prExpanded: prGrp?.classList.contains('is-expanded'),
            supCollapsedStack: supGrp?.classList.contains('is-collapsed-stack'),
            restored,
            diagFrames: Array.from(document.querySelectorAll('[data-workspace-frame]')).map(f => ({
                id: f.dataset.workspaceFrame,
                src: f.src,
                href: f.contentWindow?.location?.href,
                hasMarker: Boolean(f.contentDocument?.querySelector('[data-ws-marker="pr-1"]')),
            })),
            tabsDiag: Array.from(document.querySelectorAll('[data-workspace-session]')).map(t => ({
                id: t.dataset.workspaceSession,
                stale: t.dataset.stale,
                dirty: t.dataset.dirty,
                hibernated: t.dataset.hibernated,
            })),
        };
    })()`);
    if (backToPrParent.restored !== 'INFOR-MULTI-PR-1') {
        console.error('DIAG backToPrParent:', JSON.stringify(backToPrParent), 'NON_GET:', JSON.stringify(capturedRequests.filter(r => r.method !== 'GET')));
    }
    assert.equal(backToPrParent.prExpanded, true);
    assert.equal(backToPrParent.supCollapsedStack, true);
    assert.equal(backToPrParent.restored, 'INFOR-MULTI-PR-1');
    await recordFinding('3_parent_child_join_auto_collapse_and_stack_menu', { expandedPrState, stackedAndExpandedState, backToPrParent });

    await until("!document.querySelector('[data-workspace-group].is-morphing, [data-workspace-group].is-entering')");

    // Capture uncompressed single-tab width on prGroupIds[1] (Purchase Requisition #2), plus verify resize settle hides scroll buttons cleanly
    const fewTabsBaseCap = await evaluate(`(() => {
        const list = document.querySelector('[data-workspace-sessionlist]');
        const prTab = document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session');
        return {
            cap: list?.style.getPropertyValue('--workspace-session-cap') || '',
            prWidth: Math.round(prTab?.getBoundingClientRect().width || 0),
        };
    })()`);
    assert.ok(fewTabsBaseCap.prWidth >= 150, `Expected full uncompressed width on PR #2 with few tabs open, got ${fewTabsBaseCap.prWidth}`);

    // Narrow viewport so cap compresses (~125px) without overflowing 112px/136px floors, confirming scroll buttons stay hidden after max-width transition
    await command('Emulation.setDeviceMetricsOverride', {
        width: 1240,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await sleep(460);
    const narrowedState = await evaluate(`(() => {
        const list = document.querySelector('[data-workspace-sessionlist]');
        const groups = Array.from(list?.querySelectorAll('[data-workspace-group]:not(.workspace-session-group--ghost)') || [])
            .map(g => ({
                mod: g.dataset.workspaceModule,
                w: Math.round(g.getBoundingClientRect().width * 100) / 100,
                pills: Array.from(g.querySelectorAll('.workspace-session')).map(p => ({
                    w: Math.round(p.getBoundingClientRect().width * 100) / 100,
                    nw: p.dataset.naturalWidth || '',
                    maxW: getComputedStyle(p).maxWidth,
                    text: p.querySelector('.workspace-session__label')?.textContent?.trim(),
                })),
            }));
        return {
            capRequiresScroll: list?.dataset.capRequiresScroll,
            appliedCap: list?.style.getPropertyValue('--workspace-session-cap') || '',
            scrollWidth: list?.scrollWidth || 0,
            clientWidth: list?.clientWidth || 0,
            prevHidden: Boolean(document.querySelector('[data-workspace-scroll="prev"]')?.hidden),
            nextHidden: Boolean(document.querySelector('[data-workspace-scroll="next"]')?.hidden),
            groups,
        };
    })()`);
    assert.equal(narrowedState.capRequiresScroll, 'false');
    assert.equal(narrowedState.prevHidden, true, `Scroll prev arrow must stay hidden when narrowed tabs fit above 112px floor; diag=${JSON.stringify(narrowedState)}`);
    assert.equal(narrowedState.nextHidden, true, `Scroll next arrow must stay hidden when narrowed tabs fit above 112px floor; diag=${JSON.stringify(narrowedState)}`);
    await command('Emulation.setDeviceMetricsOverride', {
        width: 1366,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await sleep(340);

    // 4. Context Menu Duplicate + LRU Hibernation Pool (maxLiveFrames = 6) + Dirty Protection + Adaptive Shrinking with 112px/136px Floors
    await evaluate(`(() => {
        const f = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        f?.contentWindow?.AdasiUnsaved?.markDirty?.();
    })()`);
    await until(`document.querySelector('[data-workspace-session="${seededMarker}"]')?.dataset.dirty === "true"`);

    for (const seg of ['quotations', 'purchase-orders', 'shipments', 'reports']) {
        const beforeSegCount = await evaluate(`document.querySelectorAll('[data-workspace-group][data-workspace-module="${seg}"]').length`);
        await evaluate(`document.querySelector('#sidebar a.sidebar-link[href*="${seg}"]')?.click()`);
        await until(`document.querySelectorAll('[data-workspace-group][data-workspace-module="${seg}"]').length === ${beforeSegCount + 1}`);
        await waitGroupActive(`document.querySelectorAll('[data-workspace-group][data-workspace-module="${seg}"]')[${beforeSegCount}]`);
    }

    await until(`Math.round(document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session')?.getBoundingClientRect().width || 0) === 112`);

    const hoverMove = async (x, y) => {
        await command('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
    };
    const mouseClickAt = async (x, y) => {
        await hoverMove(x, y);
        await command('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
        await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
    };

    const compressedHoverCheck = await evaluate(`(() => {
        const t2 = document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session');
        t2?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        const t3 = document.querySelector('[data-workspace-group="${prGroupIds[2]}"] .workspace-session');
        const r2 = t2?.getBoundingClientRect();
        const r3 = t3?.getBoundingClientRect();
        return {
            x: r2 ? r2.left + r2.width / 2 : 0,
            y: r2 ? r2.top + r2.height / 2 : 0,
            t3LeftBefore: r3 ? Math.round(r3.left) : 0,
        };
    })()`);
    await hoverMove(compressedHoverCheck.x, compressedHoverCheck.y);
    await sleep(320);
    const compressedHoverAfter = await evaluate(`(() => {
        const closeBtn = document.querySelector('[data-workspace-group="${prGroupIds[1]}"] [data-workspace-session-close]');
        const t3 = document.querySelector('[data-workspace-group="${prGroupIds[2]}"] .workspace-session');
        return {
            closeW: Math.round(closeBtn?.getBoundingClientRect().width || 0),
            t3LeftAfter: Math.round(t3?.getBoundingClientRect().left || 0),
        };
    })()`);
    assert.ok(compressedHoverAfter.closeW >= 24, `Hover close button on 112px tab must reach >= 24px (got ${compressedHoverAfter.closeW})`);
    assert.equal(compressedHoverAfter.t3LeftAfter, compressedHoverCheck.t3LeftBefore, 'Hovering 112px tab to reveal close button must not shift adjacent siblings');

    // Click the compressed 112px tab (both hibernated and live) and verify it NEVER flashes full label width (>112px) on click or during transition frames
    const clickSampleImmediate = await evaluate(`(() => {
        const t2 = document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session');
        const btn = t2?.querySelector('button[role="tab"]');
        const beforeW = Math.round(t2?.getBoundingClientRect().width || 0);
        btn?.click();
        const immediateW = Math.round(t2?.getBoundingClientRect().width || 0);
        return { beforeW, immediateW };
    })()`);
    await sleep(45);
    const clickSampleMid = await evaluate(`Math.round(document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session')?.getBoundingClientRect().width || 0)`);
    await waitGroupActive(`document.querySelector('[data-workspace-group="${prGroupIds[1]}"]')`);
    const clickSampleSettled = await evaluate(`Math.round(document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session')?.getBoundingClientRect().width || 0)`);
    assert.equal(clickSampleImmediate.beforeW, 112);
    assert.equal(clickSampleImmediate.immediateW, 112, `Clicked compressed tab must not flash full label width immediately after click (got ${clickSampleImmediate.immediateW})`);
    assert.equal(clickSampleMid, 112, `Clicked compressed tab must stay at 112px mid-frame without shrinking animation from full width (got ${clickSampleMid})`);
    assert.equal(clickSampleSettled, 112, `Clicked compressed tab must remain 112px after frame settles (got ${clickSampleSettled})`);

    // Switch back to the last opened tab and verify live compressed tab click also stays locked at 112px
    const liveClickSample = await evaluate(`(() => {
        const groups = Array.from(document.querySelectorAll('[data-workspace-sessionlist] [data-workspace-group]:not(.workspace-session-group--ghost)'));
        const lastGrp = groups[groups.length - 1];
        const lastTab = lastGrp?.querySelector('.workspace-session');
        const btn = lastTab?.querySelector('button[role="tab"]');
        const beforeW = Math.round(lastTab?.getBoundingClientRect().width || 0);
        btn?.click();
        const immediateW = Math.round(lastTab?.getBoundingClientRect().width || 0);
        return { beforeW, immediateW, lastGid: lastGrp?.dataset.workspaceGroup };
    })()`);
    assert.equal(liveClickSample.immediateW, liveClickSample.beforeW, 'Clicking live compressed tab must not change its width');
    await waitGroupActive(`document.querySelector('[data-workspace-group="${liveClickSample.lastGid}"]')`);

    await hoverMove(10, 10);

    const hibernationState = await evaluate(`(() => {
        const mountedFrames = document.querySelectorAll('iframe[data-workspace-frame]').length;
        const openGroups = document.querySelectorAll('[data-workspace-group]:not([data-workspace-group="shortcuts"])').length;
        const hibernatedTabs = document.querySelectorAll('[data-workspace-session][data-hibernated="true"]').length;
        const dirtyPrTab = document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session="${prGroupIds[0]}"], [data-workspace-session="${seededMarker}"]');
        const tablist = document.querySelector('[data-workspace-sessionlist]');
        const shortcutsGroup = document.querySelector('[data-workspace-group="shortcuts"]');
        const shortcutsLeftBefore = Math.round(shortcutsGroup?.getBoundingClientRect().left || 0);
        if (tablist) tablist.scrollLeft = Math.max(0, tablist.scrollWidth - tablist.clientWidth);
        const shortcutsLeftAfter = Math.round(shortcutsGroup?.getBoundingClientRect().left || 0);
        const compressedPrTab = document.querySelector('[data-workspace-group="${prGroupIds[1]}"] .workspace-session');
        const compressedPrWidth = Math.round(compressedPrTab?.getBoundingClientRect().width || 0);
        const stackedSupTab = document.querySelector('[data-workspace-group="${supGroupId}"] .workspace-session--stacked');
        const stackedSupWidth = Math.round(stackedSupTab?.getBoundingClientRect().width || 0);
        const allTabWidths = Array.from(tablist?.querySelectorAll('.workspace-session:not(.workspace-session--ghost)') || [])
            .map(el => Math.round(el.getBoundingClientRect().width));
        return {
            mountedFrames,
            openGroups,
            hibernatedTabs,
            dirtyPrStillLive: dirtyPrTab?.dataset.dirty === 'true' && dirtyPrTab?.dataset.hibernated === 'false' && Boolean(document.querySelector('iframe[data-workspace-frame="${seededMarker}"]')),
            shortcutsFixedOutsideTablist: Boolean(shortcutsGroup && tablist && !tablist.contains(shortcutsGroup)),
            shortcutsLeftUnchangedOnScroll: shortcutsLeftBefore > 0 && shortcutsLeftBefore === shortcutsLeftAfter && (tablist?.scrollLeft || 0) > 0,
            appliedCap: tablist?.style.getPropertyValue('--workspace-session-cap') || '',
            compressedPrWidth,
            fewTabsPrWidth: ${fewTabsBaseCap.prWidth},
            stackedSupWidth,
            minPillWidthObserved: Math.min(...allTabWidths),
        };
    })()`);
    assert.ok(hibernationState.openGroups >= 8, `Expected >= 8 visible workspace groups, got ${hibernationState.openGroups}`);
    assert.ok(hibernationState.mountedFrames <= 6, `Live mounted iframes must cap at <= 6, got ${hibernationState.mountedFrames}`);
    assert.ok(hibernationState.hibernatedTabs >= 1, 'Clean oldest frames beyond pool limit should hibernate');
    assert.equal(hibernationState.dirtyPrStillLive, true, 'Dirty PR frame must never be hibernated');
    assert.equal(hibernationState.shortcutsFixedOutsideTablist, true, 'Shortcuts tab must sit fixed outside scrollable tablist');
    assert.equal(hibernationState.shortcutsLeftUnchangedOnScroll, true, 'Fixed Shortcuts tab must not move when dynamic tabs scroll horizontally');
    assert.ok(
        hibernationState.compressedPrWidth < hibernationState.fewTabsPrWidth && hibernationState.compressedPrWidth >= 112,
        `Tabs must shorten progressively when many tabs open while stopping at >= 112px floor (before=${hibernationState.fewTabsPrWidth}, compressed=${hibernationState.compressedPrWidth})`,
    );
    assert.ok(
        hibernationState.stackedSupWidth >= 136,
        `Collapsed stacked pill must retain >= 136px readable floor for title + stack badge (got ${hibernationState.stackedSupWidth})`,
    );
    await recordFinding('4_lru_iframe_hibernation_pool_and_dirty_safety', hibernationState);

    await evaluate(`document.querySelector('[data-workspace-frame="${seededMarker}"]')?.contentWindow?.AdasiUnsaved?.markClean?.()`);

    // Re-expand PR group for Dark screenshot capture
    await evaluate(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-group-select]')?.click()`);
    await waitGroupActive(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"]')`);

    // 5. Dark Mode Screenshot
    await evaluate(`(() => {
        document.documentElement.dataset.theme = 'dark';
        document.documentElement.dataset.bsTheme = 'dark';
        document.querySelectorAll('iframe[data-workspace-frame]').forEach(f => {
            try {
                f.contentDocument.documentElement.dataset.theme = 'dark';
                f.contentDocument.documentElement.dataset.bsTheme = 'dark';
            } catch {}
        });
    })()`);
    await sleep(220);
    const shotDark = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(path.join(artifacts, 'ws-shell-v2-1366-dark.png'), Buffer.from(shotDark.data, 'base64'));
    await evaluate(`(() => {
        document.documentElement.dataset.theme = 'light';
        document.documentElement.dataset.bsTheme = 'light';
        document.querySelectorAll('iframe[data-workspace-frame]').forEach(f => {
            try {
                f.contentDocument.documentElement.dataset.theme = 'light';
                f.contentDocument.documentElement.dataset.bsTheme = 'light';
            } catch {}
        });
    })()`);

    // 5b. Hover-only 'x' close icon, Non-Bouncy Lengthen/Shorten motion, Cross-Boundary Outside-Click Dismissal, & Full EN<->ID Locale Switching

    // Ensure expanded Child tab of prGroupIds[0] (which has a close button) is active and scrolled into view
    await evaluate(`(() => {
        const childTrigger = document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] .workspace-session')[1]?.querySelector('button[role="tab"]');
        childTrigger?.click();
        childTrigger?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
    })()`);
    await until(`(() => {
        const childTab = document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] .workspace-session')[1];
        return Boolean(childTab?.classList.contains('is-active') && childTab.dataset.loading !== 'true' && childTab.dataset.queued !== 'true');
    })()`, 20000);
    await waitFrameSettled('.workspace-panel.is-active iframe');

    // Park mouse away from tabstrip
    await hoverMove(12, 450);
    await sleep(340);

    const restCloseState = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        grp?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        const activeTab = document.querySelector('.workspace-session.is-active');
        const activeClose = activeTab?.querySelector('[data-workspace-session-close]');
        const activeCloseCs = activeClose ? getComputedStyle(activeClose) : null;
        const inactiveSingle = Array.from(document.querySelectorAll('.workspace-session:not(.is-active):not(.workspace-session--stacked)'))
            .find((el) => el.querySelector('[data-workspace-session-close]'));
        const inactiveClose = inactiveSingle?.querySelector('[data-workspace-session-close]');
        const tabs = Array.from(grp?.querySelectorAll('.workspace-session') || []);
        const parentRect = tabs[0]?.getBoundingClientRect();
        const childRect = tabs[1]?.getBoundingClientRect();
        return {
            activeCloseW: activeClose ? Math.round(activeClose.getBoundingClientRect().width) : null,
            activeCloseOpacity: activeCloseCs?.opacity || null,
            activeClosePointerEvents: activeCloseCs?.pointerEvents || null,
            inactiveCloseW: inactiveClose ? Math.round(inactiveClose.getBoundingClientRect().width) : null,
            parentCenter: parentRect ? { x: parentRect.left + parentRect.width / 2, y: parentRect.top + parentRect.height / 2 } : null,
            childCenter: childRect ? { x: childRect.left + childRect.width / 2, y: childRect.top + childRect.height / 2 } : null,
        };
    })()`);
    assert.equal(restCloseState.activeCloseW, 0, 'Active tab close x must be hidden at rest on fine-pointer desktop');
    assert.equal(restCloseState.activeCloseOpacity, '0');
    assert.equal(restCloseState.activeClosePointerEvents, 'none');
    assert.equal(restCloseState.inactiveCloseW, 0, 'Inactive tab close x must be hidden at rest');

    // Hovering Parent inside expanded [Parent | Child] must NOT reveal Child's close x
    await hoverMove(restCloseState.parentCenter.x, restCloseState.parentCenter.y);
    await sleep(340);
    const parentHoverChildClose = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const close = grp?.querySelectorAll('.workspace-session')[1]?.querySelector('[data-workspace-session-close]');
        return { w: close ? Math.round(close.getBoundingClientRect().width) : null, op: close ? getComputedStyle(close).opacity : null };
    })()`);
    assert.equal(parentHoverChildClose.w, 0, 'Hovering joined Parent must NOT reveal Child close button');
    assert.equal(parentHoverChildClose.op, '0');

    // Hovering Child directly MUST reveal Child's close x (>= 24x24px WCAG 2.2) while other tabs keep x hidden
    await hoverMove(restCloseState.childCenter.x, restCloseState.childCenter.y);
    await sleep(340);
    const childHoverState = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const close = grp?.querySelectorAll('.workspace-session')[1]?.querySelector('[data-workspace-session-close]');
        const rect = close?.getBoundingClientRect();
        const cs = close ? getComputedStyle(close) : null;
        const otherClose = Array.from(document.querySelectorAll('[data-workspace-session-close]')).find((c) => c !== close);
        return {
            w: rect ? Math.round(rect.width) : 0,
            h: rect ? Math.round(rect.height) : 0,
            op: cs?.opacity || null,
            pe: cs?.pointerEvents || null,
            otherW: otherClose ? Math.round(otherClose.getBoundingClientRect().width) : 0,
        };
    })()`);
    assert.ok(childHoverState.w >= 24 && childHoverState.h >= 24, `Hovered tab close x must be >=24x24px (got ${childHoverState.w}x${childHoverState.h})`);
    assert.equal(childHoverState.op, '1');
    assert.equal(childHoverState.pe, 'auto');
    assert.equal(childHoverState.otherW, 0);

    // Mouse-clicking the tab then moving away must NOT stick x open (:has(:focus-visible) vs :focus)
    await mouseClickAt(restCloseState.childCenter.x, restCloseState.childCenter.y);
    await hoverMove(12, 450);
    await sleep(340);
    const afterMouseClickHide = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const close = grp?.querySelectorAll('.workspace-session')[1]?.querySelector('[data-workspace-session-close]');
        return { w: close ? Math.round(close.getBoundingClientRect().width) : null, op: close ? getComputedStyle(close).opacity : null };
    })()`);
    assert.equal(afterMouseClickHide.w, 0, 'Close x must hide again once pointer leaves after mouse click');
    assert.equal(afterMouseClickHide.op, '0');
    await recordFinding('5b_hover_only_close_button_wcag_and_isolation', { restCloseState, childHoverState, afterMouseClickHide });

    // Cross-boundary outside-click dismissal + data-bs-auto-close="outside" preservation
    const userTriggerPt = await evaluate(`(() => {
        const r = document.querySelector('.topbar-user-trigger')?.getBoundingClientRect();
        return r ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null;
    })()`);
    await mouseClickAt(userTriggerPt.x, userTriggerPt.y);
    await until('document.querySelector(".topbar-user-trigger")?.getAttribute("aria-expanded") === "true"');

    const logoutAllCheckboxPt = await evaluate(`(() => {
        const el = document.querySelector('#logout-all-devices');
        const r = el?.getBoundingClientRect();
        return r && r.width > 0 ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null;
    })()`);
    if (logoutAllCheckboxPt) {
        await mouseClickAt(logoutAllCheckboxPt.x, logoutAllCheckboxPt.y);
        await sleep(100);
        const stillOpenAfterInsideClick = await evaluate('document.querySelector(".topbar-user-trigger")?.getAttribute("aria-expanded") === "true"');
        assert.equal(stillOpenAfterInsideClick, true, 'Inside #logout-all-devices click must keep user dropdown open');
        // Reset checkbox to unchecked so logout test runs cleanly
        await mouseClickAt(logoutAllCheckboxPt.x, logoutAllCheckboxPt.y);
    }

    const stagePoint = await evaluate(`(() => {
        const r = document.querySelector('.workspace-panel.is-active iframe')?.getBoundingClientRect();
        return r ? { x: Math.round(r.left + r.width * 0.5), y: Math.round(r.top + 120) } : null;
    })()`);
    await mouseClickAt(stagePoint.x, stagePoint.y);
    await until('document.querySelector(".topbar-user-trigger")?.getAttribute("aria-expanded") === "false"');

    // Open workspace switcher menu, then click blank header space outside -> closes immediately
    const switcherPt = await evaluate(`(() => {
        const r = document.querySelector('[data-workspace-switcher]')?.getBoundingClientRect();
        return r ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null;
    })()`);
    await mouseClickAt(switcherPt.x, switcherPt.y);
    await until('!document.querySelector("[data-workspace-session-menu]")?.hidden');
    const neutralHeaderPt = await evaluate(`(() => {
        const r = document.querySelector('.topbar-page-title')?.getBoundingClientRect();
        return r ? { x: r.left + 8, y: r.top + r.height / 2 } : { x: 400, y: 20 };
    })()`);
    await mouseClickAt(neutralHeaderPt.x, neutralHeaderPt.y);
    await until('document.querySelector("[data-workspace-session-menu]")?.hidden');
    await recordFinding('5c_outside_click_dismissal_across_iframe_and_shell', { stagePoint, neutralHeaderPt });

    // Switch back to PR #1 index tab (which holds live DataTable + seeded input) & test full EN <-> ID language switch
    await evaluate(`document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session="${seededMarker}"] button')?.click()`);
    await until(`(() => {
        const tab = document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session="${seededMarker}"]');
        return Boolean(tab?.classList.contains('is-active') && tab.dataset.loading !== 'true' && tab.dataset.queued !== 'true');
    })()`, 20000);
    await waitFrameSettled('.workspace-panel.is-active iframe');
    const enSeedCheck = await evaluate(`(() => {
        const f = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        const input = f?.contentDocument?.querySelector('.dt-search input[type="search"], #main-content input:not([type="hidden"])');
        if (input && input.value !== 'INFOR-MULTI-PR-1') {
            input.value = 'INFOR-MULTI-PR-1';
            input.dataset.wsMarker = 'pr-1';
        }
        const headers = Array.from(f?.contentDocument?.querySelectorAll('.dt-container thead th, #main-content table thead th') || []).map(th => th.textContent.trim());
        return { lang: f?.contentDocument?.documentElement?.lang, headers, inputVal: input?.value || null };
    })()`);
    assert.equal(enSeedCheck.inputVal, 'INFOR-MULTI-PR-1');

    const beforeLocaleOrigin = await evaluate('performance.timeOrigin');
    await evaluate(`document.querySelector('.lang-pill-btn[data-locale-option="id"]')?.click()`);
    await until(`(() => {
        const f = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        const freqTitle = document.querySelector('.workspace-shortcuts__title')?.textContent?.trim();
        const refreshLabel = document.querySelector('[data-workspace-refresh]')?.getAttribute('aria-label')?.trim();
        const prLbl = document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session="${seededMarker}"] .workspace-session__label')?.textContent?.trim();
        const childLbl = document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] .workspace-session__label')[1]?.textContent?.trim();
        return document.documentElement.lang === 'id'
            && freqTitle === 'Sering Digunakan'
            && refreshLabel === 'Muat ulang tab'
            && prLbl === 'Permintaan Pembelian'
            && childLbl === 'Buat PR'
            && f?.contentDocument?.documentElement?.lang === 'id';
    })()`, 25000);
    await sleep(250);

    const idTranslatedState = await evaluate(`(() => {
        const f = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        const freqTitle = document.querySelector('.workspace-shortcuts__title')?.textContent?.trim();
        const allModulesTitle = document.querySelector('.workspace-shortcuts__section-title')?.textContent?.trim();
        const refreshLabel = document.querySelector('[data-workspace-refresh]')?.getAttribute('aria-label')?.trim();
        const prParentLabels = ${JSON.stringify(prGroupIds)}.map((gid) => document.querySelector('[data-workspace-group="' + gid + '"] .workspace-session__label')?.textContent?.trim());
        const prChildLabel = document.querySelectorAll('[data-workspace-group="${prGroupIds[0]}"] .workspace-session__label')[1]?.textContent?.trim() || null;
        const supLabel = document.querySelector('[data-workspace-group="${supGroupId}"] .workspace-session__label')?.textContent?.trim();
        const topbarTitle = document.querySelector('.top-navbar .topbar-page-title')?.textContent?.trim();
        const frameHeaders = Array.from(f?.contentDocument?.querySelectorAll('.dt-container thead th, #main-content table thead th') || []).map(th => th.textContent.trim());
        const preservedInput = f?.contentDocument?.querySelector('[data-ws-marker="pr-1"]')?.value || null;
        return {
            sameOriginTime: performance.timeOrigin === ${JSON.stringify(beforeLocaleOrigin)},
            freqTitle,
            allModulesTitle,
            refreshLabel,
            prParentLabels,
            prChildLabel,
            supLabel,
            topbarTitle,
            frameHeaders,
            preservedInput,
        };
    })()`);
    assert.equal(idTranslatedState.sameOriginTime, true, 'Locale switch must translate shell + tabs + frames without full page reload');
    assert.equal(idTranslatedState.freqTitle, 'Sering Digunakan');
    assert.equal(idTranslatedState.allModulesTitle, 'Semua Modul');
    assert.equal(idTranslatedState.refreshLabel, 'Muat ulang tab');
    assert.ok(idTranslatedState.prParentLabels.every((l) => l === 'Permintaan Pembelian'), `Expected Permintaan Pembelian on PR parent tabs, got ${JSON.stringify(idTranslatedState.prParentLabels)}`);
    assert.equal(idTranslatedState.prChildLabel, 'Buat PR');
    assert.equal(idTranslatedState.supLabel, 'Supplier Impor');
    assert.equal(idTranslatedState.topbarTitle, 'Permintaan Pembelian');
    assert.equal(idTranslatedState.preservedInput, 'INFOR-MULTI-PR-1', 'Locale switch must preserve user-typed inputs inside active iframe');
    if (enSeedCheck.headers.length > 0 && idTranslatedState.frameHeaders.length > 0) {
        assert.notDeepEqual(idTranslatedState.frameHeaders, enSeedCheck.headers, 'DataTables column headers inside iframe must translate on locale switch');
    }

    const shotId = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(path.join(artifacts, 'ws-shell-v2-1366-id.png'), Buffer.from(shotId.data, 'base64'));

    // Switch back to EN cleanly & verify restoration
    await evaluate(`document.querySelector('.lang-pill-btn[data-locale-option="en"]')?.click()`);
    await until(`(() => {
        const f = document.querySelector('[data-workspace-frame="${seededMarker}"]');
        const freqTitle = document.querySelector('.workspace-shortcuts__title')?.textContent?.trim();
        const prLbl = document.querySelector('[data-workspace-group="${prGroupIds[0]}"] [data-workspace-session="${seededMarker}"] .workspace-session__label')?.textContent?.trim();
        return document.documentElement.lang === 'en'
            && freqTitle === 'Frequently Used'
            && prLbl === 'Purchase Requisition'
            && f?.contentDocument?.documentElement?.lang === 'en';
    })()`, 25000);
    await sleep(200);
    await recordFinding('5d_workspace_locale_switch_id_and_en_in_place', { idTranslatedState });

    // Smooth asymmetric accordion group morph (400ms expand / 340ms collapse + synchronized parent/child width & phased text fade)
    // First expand stacked [Import Suppliers (2)] -> [Import Suppliers | Register Import Supplier] (pronounced widen without divider jump)
    const supStackBefore = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        grp?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        return Math.round(grp?.getBoundingClientRect()?.width || 0);
    })()`);
    await evaluate(`document.querySelector('[data-workspace-group="${supGroupId}"] [data-workspace-group-select]')?.click()`);
    await until(`document.querySelector('[data-workspace-group="${supGroupId}"]')?.classList.contains('is-active')`, 20000, 15);
    const expandStart = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const pills = Array.from(grp?.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)') || []);
        const cs = grp ? getComputedStyle(grp) : null;
        return {
            isMorphing: Boolean(grp?.classList.contains('is-morphing')),
            isExpandingGroup: Boolean(grp?.classList.contains('is-expanding-group')),
            isExpanded: Boolean(grp?.classList.contains('is-expanded')),
            pill0Morph: Boolean(pills[0]?.classList.contains('is-morph-pill')),
            hasLipGhostAriaHidden: grp?.querySelector('.workspace-session-group__lip-ghost')?.getAttribute('aria-hidden') === 'true',
            hasMorphOverlayAriaHidden: pills[0]?.querySelector('.workspace-session__morph-overlay')?.getAttribute('aria-hidden') === 'true',
            childRevealing: Boolean(pills[1]?.classList.contains('is-revealing')),
            transitionDuration: cs?.transitionDuration || '',
        };
    })()`);
    assert.equal(expandStart.isMorphing, true, 'Expanding stacked workspace group must morph width smoothly');
    assert.equal(expandStart.isExpandingGroup, true);
    assert.equal(expandStart.isExpanded, true);
    assert.equal(expandStart.pill0Morph, true, 'Parent pill must smoothly transition its own width during stack expand');
    assert.equal(expandStart.hasLipGhostAriaHidden, true, 'Inert stack lip ghost must fade smoothly under aria-hidden');
    assert.equal(expandStart.hasMorphOverlayAriaHidden, true, 'Inert pill morph overlay must crossfade under aria-hidden');
    assert.equal(expandStart.childRevealing, true, 'Child pill must accordion-unfold smoothly on group expand');
    assert.ok(expandStart.transitionDuration.includes('0.4s'), `Expected 400ms group expand duration, got ${expandStart.transitionDuration}`);
    await sleep(95);
    const supMidExpand = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const pills = Array.from(grp?.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)') || []);
        return {
            groupW: Math.round(grp?.getBoundingClientRect()?.width || 0),
            childW: Math.round(pills[1]?.getBoundingClientRect()?.width || 0),
        };
    })()`);
    const supMidExpandW = supMidExpand.groupW;
    await until(`!document.querySelector('[data-workspace-group="${supGroupId}"]')?.classList.contains('is-morphing')`);
    await waitFrameSettled('.workspace-panel.is-active iframe');
    const supExpandedMetrics = await evaluate(`(() => {
        const grp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const pills = Array.from(grp?.querySelectorAll(':scope > .workspace-session:not(.workspace-session--ghost)') || []);
        return {
            groupW: Math.round(grp?.getBoundingClientRect()?.width || 0),
            childW: Math.round(pills[1]?.getBoundingClientRect()?.width || 0),
            leftoverTransients: document.querySelectorAll('[data-workspace-group="${supGroupId}"] .workspace-session__morph-overlay, [data-workspace-group="${supGroupId}"] .workspace-session-group__lip-ghost').length,
        };
    })()`);
    const supExpandedW = supExpandedMetrics.groupW;
    assert.ok(
        supExpandedW > supStackBefore + 20 && supMidExpandW > supStackBefore && supMidExpandW < supExpandedW,
        `Expanding stacked group must widen monotonically without overshoot (stack=${supStackBefore}, mid=${supMidExpandW}, expanded=${supExpandedW})`,
    );
    assert.ok(
        supMidExpand.childW > 0 && supMidExpand.childW < supExpandedMetrics.childW,
        `Child pill inside expanding group must unfold monotonically (mid=${supMidExpand.childW}, final=${supExpandedMetrics.childW})`,
    );
    assert.equal(supExpandedMetrics.leftoverTransients, 0, 'Morph transients must clean up after expand settles');

    // Open Claims -> tests BOTH new tab calm lengthen AND expanded supGroupId smooth 340ms accordion collapse once active
    await evaluate(`document.querySelector('#sidebar a.sidebar-link[href*="claims"]')?.click()`);
    const enterStart = await evaluate(`(() => {
        const claimsGrp = Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="claims"]')).pop();
        const claimsTab = claimsGrp?.querySelector('.workspace-session');
        const csGrp = claimsGrp ? getComputedStyle(claimsGrp) : null;
        const csTab = claimsTab ? getComputedStyle(claimsTab) : null;
        return {
            hasEntering: Boolean(claimsGrp?.classList.contains('is-entering')),
            transitionProp: csGrp?.transitionProperty || '',
            transitionDuration: csGrp?.transitionDuration || '',
            animName: csTab?.animationName || 'none',
        };
    })()`);
    assert.equal(enterStart.hasEntering, true, 'New workspace group must enter with measured-width .is-entering');
    assert.ok(enterStart.transitionProp.includes('width'), `Expected width transition, got ${enterStart.transitionProp}`);
    assert.ok(enterStart.transitionDuration.includes('0.4s'), `Expected calm 400ms enter transition duration, got ${enterStart.transitionDuration}`);
    assert.equal(enterStart.animName, 'none', 'No bounce/keyframe transform animation allowed on entering tabs');

    await sleep(85);
    const midClaimsEnterW = await evaluate(`Math.round(Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="claims"]')).pop()?.getBoundingClientRect()?.width || 0)`);

    await until(`Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="claims"]')).pop()?.classList.contains('is-active')`, 20000, 15);
    const collapseStart = await evaluate(`(() => {
        const supGrp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        const supTail = supGrp?.querySelector('.workspace-session-group__collapse-tail');
        return {
            supIsMorphingOnCollapse: Boolean(supGrp?.classList.contains('is-morphing')),
            supIsCollapsingGroup: Boolean(supGrp?.classList.contains('is-collapsing-group')),
            supCollapseDuration: supGrp ? getComputedStyle(supGrp).transitionDuration : '',
            supHasCollapseTail: Boolean(supTail),
            supCollapsedTailAriaHidden: supTail?.getAttribute('aria-hidden') || null,
            supTailWidthTransition: supTail ? getComputedStyle(supTail).transitionProperty : '',
        };
    })()`);
    Object.assign(enterStart, collapseStart);
    assert.equal(collapseStart.supIsMorphingOnCollapse, true, 'Collapsing expanded group to stack must morph container width smoothly');
    assert.equal(collapseStart.supIsCollapsingGroup, true);
    assert.ok(collapseStart.supCollapseDuration.includes('0.34s'), `Expected 340ms group collapse duration, got ${collapseStart.supCollapseDuration}`);
    assert.equal(collapseStart.supHasCollapseTail, true, 'Collapsing group must keep inert fold tail inside container');
    assert.equal(collapseStart.supCollapsedTailAriaHidden, 'true');
    assert.ok(collapseStart.supTailWidthTransition.includes('width'), 'Collapse tail must fold its own width inward synchronously');

    await sleep(85);
    const midEnterAndCollapse = await evaluate(`(() => {
        const supGrp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        return {
            claimsMidW: ${midClaimsEnterW},
            supMidW: Math.round(supGrp?.getBoundingClientRect()?.width || 0),
        };
    })()`);

    await until(`!document.querySelector('[data-workspace-group][data-workspace-module="claims"]')?.classList.contains('is-entering') && !document.querySelector('[data-workspace-group="${supGroupId}"]')?.classList.contains('is-morphing')`);
    await waitFrameSettled('.workspace-panel.is-active iframe');

    const settledWidths = await evaluate(`(() => {
        const claimsGrp = Array.from(document.querySelectorAll('[data-workspace-group][data-workspace-module="claims"]')).pop();
        const supGrp = document.querySelector('[data-workspace-group="${supGroupId}"]');
        return {
            claimsFinalW: Math.round(claimsGrp?.getBoundingClientRect()?.width || 0),
            supStackAfterW: Math.round(supGrp?.getBoundingClientRect()?.width || 0),
        };
    })()`);
    assert.ok(
        midEnterAndCollapse.claimsMidW > 0 && midEnterAndCollapse.claimsMidW < settledWidths.claimsFinalW,
        `Opening tab width must widen smoothly without overshoot (mid=${midEnterAndCollapse.claimsMidW}, final=${settledWidths.claimsFinalW})`,
    );
    assert.ok(
        midEnterAndCollapse.supMidW > settledWidths.supStackAfterW && midEnterAndCollapse.supMidW < supExpandedW,
        `Workspace group collapse must shrink smoothly (expanded=${supExpandedW}, mid=${midEnterAndCollapse.supMidW}, stack=${settledWidths.supStackAfterW})`,
    );

    // Hover active Claims tab to reveal close x (180ms), then close -> smooth ghost collapse with negative flex gap compensation
    const claimsHoverPt = await evaluate(`(() => {
        const tab = Array.from(document.querySelectorAll('[data-workspace-module="claims"] .workspace-session')).pop();
        tab?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        const r = tab?.getBoundingClientRect();
        return r ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null;
    })()`);
    await hoverMove(claimsHoverPt.x, claimsHoverPt.y);
    await sleep(340);
    const closeBtnPt = await evaluate(`(() => {
        const close = document.querySelector('[data-workspace-module="claims"] [data-workspace-session-close]');
        const r = close?.getBoundingClientRect();
        return r ? { x: r.left + r.width / 2, y: r.top + r.height / 2, w: Math.round(r.width) } : null;
    })()`);
    assert.ok(closeBtnPt && closeBtnPt.w >= 24);
    await mouseClickAt(closeBtnPt.x, closeBtnPt.y);
    const ghostObserved = await evaluate(`(() => {
        const ghost = document.querySelector('.workspace-session-group--ghost.is-closing');
        const cs = ghost ? getComputedStyle(ghost) : null;
        const activeClaims = document.querySelectorAll('[data-workspace-module="claims"]').length;
        return {
            hasGhost: Boolean(ghost),
            ghostAriaHidden: ghost?.getAttribute('aria-hidden'),
            ghostCollapsing: Boolean(ghost?.classList.contains('is-collapsing')),
            inlineMarginRight: ghost?.style.marginRight || '',
            transitionDuration: cs?.transitionDuration || '',
            liveClaimsCount: activeClaims,
        };
    })()`);
    assert.equal(ghostObserved.hasGhost, true, `Closing workspace group must spawn inert horizontal-shortening ghost; diag=${JSON.stringify(ghostObserved)}`);
    assert.equal(ghostObserved.ghostAriaHidden, 'true');
    assert.equal(ghostObserved.ghostCollapsing, true);
    assert.ok(parseFloat(ghostObserved.inlineMarginRight) < 0, `Closing ghost must compensate flex gap cleanly (got ${ghostObserved.inlineMarginRight})`);
    assert.ok(ghostObserved.transitionDuration.includes('0.34s'), `Expected 340ms ghost collapse duration, got ${ghostObserved.transitionDuration}`);
    assert.equal(ghostObserved.liveClaimsCount, 0, 'Closed workspace group must be removed immediately from ARIA tablist');
    await until('document.querySelectorAll(".workspace-session-group--ghost, .workspace-session--ghost").length === 0');

    // Close a NON-LAST middle group (prGroupIds[1]) and verify its closing ghost stays in place between left & right siblings
    const midCloseObserved = await evaluate(`(() => {
        const g1 = document.querySelector('[data-workspace-group="${prGroupIds[0]}"]');
        const g2 = document.querySelector('[data-workspace-group="${prGroupIds[1]}"]');
        const g3 = document.querySelector('[data-workspace-group="${prGroupIds[2]}"]');
        if (!g1 || !g2 || !g3) return null;
        g2.querySelector('[data-workspace-session-close]')?.click();
        const ghost = document.querySelector('.workspace-session-group--ghost.is-closing');
        return {
            ghostStaysBetweenSiblings: Boolean(ghost && ghost.previousElementSibling === g1 && ghost.nextElementSibling === g3),
            g2Removed: !document.querySelector('[data-workspace-group="${prGroupIds[1]}"]'),
        };
    })()`);
    assert.ok(midCloseObserved, 'Middle duplicate PR groups must exist for non-last close verification');
    assert.equal(midCloseObserved.ghostStaysBetweenSiblings, true, 'Closing non-last group ghost must remain in-place ahead of right-hand siblings');
    assert.equal(midCloseObserved.g2Removed, true);
    await until('document.querySelectorAll(".workspace-session-group--ghost, .workspace-session--ghost").length === 0');

    // Verify prefers-reduced-motion: reduce bypasses transitions immediately
    await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await evaluate(`document.querySelector('#sidebar a.sidebar-link[href*="claims"]')?.click()`);
    const reducedEnter = await evaluate(`({
        hasEntering: Boolean(document.querySelector('[data-workspace-module="claims"].is-entering')),
        count: document.querySelectorAll('[data-workspace-module="claims"]').length,
    })`);
    assert.equal(reducedEnter.hasEntering, false, 'prefers-reduced-motion must bypass .is-entering');
    assert.equal(reducedEnter.count, 1);
    await evaluate(`document.querySelector('[data-workspace-module="claims"] [data-workspace-session-close]')?.click()`);
    const reducedCloseGhosts = await evaluate('document.querySelectorAll(".workspace-session-group--ghost, .workspace-session--ghost").length');
    assert.equal(reducedCloseGhosts, 0, 'prefers-reduced-motion must bypass closing ghost');
    await command('Emulation.setEmulatedMedia', { features: [] });
    await hoverMove(12, 450);

    await recordFinding('5e_smooth_non_bouncy_tab_and_group_motion', { supStackBefore, supMidExpandW, supExpandedW, enterStart, midEnterAndCollapse, settledWidths, ghostObserved });

    // 6. Close All Tabs -> Fallback to Shortcuts ("Frequently Used") + Search + F5 Persistence + Launch
    const firstNonShortcutTrigger = '[data-workspace-group]:not([data-workspace-group="shortcuts"]) [data-workspace-session]';
    await evaluate(`(() => {
        const el = document.querySelector(${JSON.stringify(firstNonShortcutTrigger)});
        el?.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 220, clientY: 96 }));
    })()`);
    await until('!document.querySelector("[data-workspace-session-menu]")?.hidden');
    await evaluate(`(() => {
        const items = Array.from(document.querySelectorAll('[data-workspace-session-menu] [role="menuitem"]'));
        items[items.length - 1]?.click();
    })()`);

    await until('document.querySelectorAll("[data-workspace-group]").length === 1 && !document.querySelector("[data-workspace-panel=\\"shortcuts\\"]")?.hidden');

    // Verify F5 reload while sitting on Shortcuts keeps Shortcuts active
    const prevOrigin = await evaluate('performance.timeOrigin');
    await command('Page.reload');
    await until(`performance.timeOrigin !== ${JSON.stringify(prevOrigin)} && Boolean(document.querySelector("html[data-workspace-shell]"))`);
    await until('!document.querySelector("[data-workspace-panel=\\"shortcuts\\"]")?.hidden && document.querySelectorAll("[data-workspace-group]").length === 1');
    await until('getComputedStyle(document.querySelector("[data-workspace-session=\\"shortcuts\\"]"), "::after").opacity === "1"');

    await evaluate(`(() => {
        const input = document.querySelector('[data-shortcuts-search]');
        input.value = 'Shipment';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    })()`);
    const shortcutSearchState = await evaluate(`(() => {
        const scTab = document.querySelector('[data-workspace-session="shortcuts"]');
        const scStyle = scTab ? getComputedStyle(scTab) : null;
        const scAfter = scTab ? getComputedStyle(scTab, '::after') : null;
        return {
            frequentMatches: document.querySelectorAll('[data-shortcuts-frequent] button').length,
            hasIcons: Boolean(document.querySelector('[data-shortcuts-frequent] button svg')),
            urlHash: window.location.hash,
            shortcutsBorderTop: scStyle?.borderTopWidth || '',
            shortcutsBorderLeft: scStyle?.borderLeftWidth || '',
            shortcutsBorderRight: scStyle?.borderRightWidth || '',
            shortcutsBackgroundColor: scStyle?.backgroundColor || '',
            shortcutsUnderlineOpacity: scAfter?.opacity || '',
        };
    })()`);
    assert.ok(shortcutSearchState.frequentMatches >= 1);
    assert.equal(shortcutSearchState.hasIcons, true);
    assert.equal(shortcutSearchState.urlHash, '#shortcuts');
    assert.equal(shortcutSearchState.shortcutsBorderTop, '0px', 'Shortcuts tab must have no top border');
    assert.equal(shortcutSearchState.shortcutsBorderLeft, '0px', 'Shortcuts tab must have no left border');
    assert.equal(shortcutSearchState.shortcutsBorderRight, '0px', 'Shortcuts tab must have no right border');
    assert.equal(shortcutSearchState.shortcutsBackgroundColor, 'rgba(0, 0, 0, 0)', 'Active Shortcuts tab must keep transparent background with no card box fill');
    assert.equal(shortcutSearchState.shortcutsUnderlineOpacity, '1', 'Active Shortcuts tab must keep visible bottom underline');

    await evaluate(`document.querySelector('[data-shortcuts-frequent] button')?.click()`);
    await until('document.querySelectorAll("[data-workspace-group]").length === 2');
    await waitGroupActive(`document.querySelectorAll('[data-workspace-group]')[1]`);
    await recordFinding('5_close_all_shortcuts_fallback_f5_and_relaunch', shortcutSearchState);

    // 6b. Ordered Rapid-Click FIFO Queue + Purchase Order DataTables Readiness Gate
    const rapidQueueProbe = await evaluate(`(() => {
        const beforePanelId = document.querySelector('.workspace-panel.is-active')?.dataset.workspacePanel || null;
        const reqLink = document.querySelector('#sidebar a.sidebar-link[href*="requisitions"]');
        const quoLink = document.querySelector('#sidebar a.sidebar-link[href*="quotations"]');
        const poLink = document.querySelector('#sidebar a.sidebar-link[href*="purchase-orders"]');
        reqLink?.click();
        quoLink?.click();
        poLink?.click();
        const groups = Array.from(document.querySelectorAll('[data-workspace-sessionlist] [data-workspace-group]:not([data-workspace-group="shortcuts"]):not(.workspace-session-group--ghost)'));
        const tail3 = groups.slice(-3);
        const tabStates = tail3.map((g) => {
            const tab = g.querySelector('[data-workspace-session]');
            return {
                groupId: g.dataset.workspaceGroup,
                moduleKey: g.dataset.workspaceModule,
                loading: tab?.dataset.loading || null,
                queued: tab?.dataset.queued || null,
                isActive: g.classList.contains('is-active'),
            };
        });
        return {
            beforePanelId,
            activePanelAfterClicks: document.querySelector('.workspace-panel.is-active')?.dataset.workspacePanel || null,
            tailOrder: tabStates.map((s) => s.moduleKey),
            loadingCount: tabStates.filter((s) => s.loading === 'true').length,
            queuedCount: tabStates.filter((s) => s.queued === 'true').length,
            poGroupId: tail3[2]?.dataset.workspaceGroup || null,
        };
    })()`);
    assert.deepEqual(rapidQueueProbe.tailOrder, ['requisitions', 'quotations', 'purchase-orders'], 'Rapid sidebar clicks must open tabs in exact FIFO click order');
    assert.equal(rapidQueueProbe.activePanelAfterClicks, rapidQueueProbe.beforePanelId, 'Active workspace panel must stay put while queued/loading tabs render');
    assert.ok(rapidQueueProbe.loadingCount <= 2, `Queue must enforce maxConcurrent = 2 (loadingCount=${rapidQueueProbe.loadingCount})`);
    assert.equal(rapidQueueProbe.queuedCount, 1, 'Third rapid-click tab must start queued while 2 slots are active');

    await until(`(() => {
        const poGrp = document.querySelector('[data-workspace-group="${rapidQueueProbe.poGroupId}"]');
        return Boolean(poGrp?.classList.contains('is-active'));
    })()`, 25000);

    const poImmediateOnSwitch = await evaluate(`(() => {
        const iframe = document.querySelector('.workspace-panel.is-active iframe');
        const doc = iframe?.contentDocument;
        const win = iframe?.contentWindow;
        const poTable = doc?.querySelector('#poTable');
        const wrapper = poTable?.closest('.dt-container');
        return {
            href: win?.location?.href || '',
            hasWrapperLoading: Boolean(wrapper?.classList.contains('is-loading')),
            bodyRows: doc ? doc.querySelectorAll('#poTable tbody tr').length : 0,
            readinessReady: Boolean(win?.AdasiEmbedReadiness?.isReady),
        };
    })()`);
    assert.ok(poImmediateOnSwitch.href.includes('purchase-orders'), 'Final rapid-clicked target (Purchase Order) must become active');
    assert.equal(poImmediateOnSwitch.hasWrapperLoading, false, 'Purchase Order DataTable must NOT still be loading when tab switches');
    assert.ok(poImmediateOnSwitch.bodyRows >= 1, 'Purchase Order table rows (or empty-table row) must already be painted before tab switch');
    assert.equal(poImmediateOnSwitch.readinessReady, true, 'AdasiEmbedReadiness must be settled upon tab switch');
    await waitFrameSettled('.workspace-panel.is-active iframe');
    await recordFinding('6b_rapid_click_fifo_queue_and_datatables_readiness', { rapidQueueProbe, poImmediateOnSwitch });

    // 7. Mobile 375x900 responsiveness & touch targets
    await command('Emulation.setDeviceMetricsOverride', {
        width: 375,
        height: 900,
        deviceScaleFactor: 2,
        mobile: true,
    });
    await command('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    await sleep(200);
    const mobileMetrics = await evaluate(`(() => {
        const refreshRect = document.querySelector('[data-workspace-refresh]')?.getBoundingClientRect();
        const closeRect = document.querySelector('[data-workspace-session-close]')?.getBoundingClientRect();
        const topbarRect = document.querySelector('.top-navbar')?.getBoundingClientRect();
        const mobileToggleRect = document.querySelector('.sidebar-toggle--mobile')?.getBoundingClientRect();
        const userTriggerRect = document.querySelector('.topbar-user-trigger')?.getBoundingClientRect();
        const activeFrame = document.querySelector('.workspace-panel.is-active iframe');
        const frameRect = activeFrame?.getBoundingClientRect();
        const frameMain = activeFrame?.contentDocument?.querySelector('#main-content');
        return {
            topOverflowX: document.documentElement.scrollWidth > window.innerWidth,
            frameOverflowX: activeFrame?.contentDocument ? activeFrame.contentDocument.documentElement.scrollWidth > activeFrame.contentWindow.innerWidth : false,
            tabbarOverflowY: (document.querySelector('[data-workspace-sessionbar]')?.getBoundingClientRect().height || 0) > 68,
            refreshSize: refreshRect ? [Math.round(refreshRect.width), Math.round(refreshRect.height)] : null,
            closeSize: closeRect ? [Math.round(closeRect.width), Math.round(closeRect.height)] : null,
            mobileTopbarHeight: topbarRect ? Math.round(topbarRect.height) : 0,
            mobileToggleSize: mobileToggleRect ? [Math.round(mobileToggleRect.width), Math.round(mobileToggleRect.height)] : null,
            mobileUserTriggerSize: userTriggerRect ? [Math.round(userTriggerRect.width), Math.round(userTriggerRect.height)] : null,
            mobileEmbedTopbarHeight: activeFrame?.contentWindow?.getComputedStyle(activeFrame.contentDocument.documentElement).getPropertyValue('--topbar-height')?.trim(),
            frameHeight: frameRect ? Math.round(frameRect.height) : 0,
            frameHasContent: Boolean(frameMain && frameMain.innerHTML.trim().length > 100),
        };
    })()`);
    assert.equal(mobileMetrics.topOverflowX, false, 'Mobile 375px shell must have zero horizontal page overflow');
    assert.equal(mobileMetrics.frameOverflowX, false, 'Mobile 375px embedded iframe must have zero document horizontal overflow');
    assert.equal(mobileMetrics.tabbarOverflowY, false, 'Mobile tabbar must stay compact single-row');
    assert.equal(mobileMetrics.mobileTopbarHeight, 52, 'Mobile topbar height must stay comfortable 52px');
    assert.ok(mobileMetrics.mobileToggleSize[0] >= 44 && mobileMetrics.mobileToggleSize[1] >= 44, 'Mobile hamburger toggle stays >= 44x44');
    assert.ok(mobileMetrics.mobileUserTriggerSize[0] >= 40 && mobileMetrics.mobileUserTriggerSize[1] >= 40, 'Mobile user trigger stays >= 40x40');
    assert.equal(mobileMetrics.mobileEmbedTopbarHeight, '0px', 'Embedded iframe keeps --topbar-height: 0px even at mobile breakpoints');
    assert.ok(mobileMetrics.refreshSize[0] >= 40 && mobileMetrics.refreshSize[1] >= 44, 'Mobile refresh tap target >= 40x44');
    assert.ok(mobileMetrics.frameHeight >= 600);
    assert.equal(mobileMetrics.frameHasContent, true);
    const shotMobile = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(path.join(artifacts, 'ws-shell-v2-375-mobile.png'), Buffer.from(shotMobile.data, 'base64'));
    await recordFinding('6_mobile_375_responsive_and_touch_targets', mobileMetrics);

    // 8. Top-level security breakout: profile.security loads directly without shell
    await command('Emulation.setTouchEmulationEnabled', { enabled: false });
    await command('Emulation.setDeviceMetricsOverride', {
        width: 1366,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await evaluate(`document.querySelector('a[href*="profile/security"]')?.click()`);
    await until('window.location.pathname.includes("profile/security") && document.readyState === "complete"');
    const secState = await evaluate(`({
        isShell: Boolean(document.documentElement.dataset.workspaceShell),
        hasMain: Boolean(document.querySelector('#main-content')),
    })`);
    assert.equal(secState.isShell, false);
    assert.equal(secState.hasMain, true);
    await recordFinding('7_sensitive_security_route_top_breakout', secState);

    // 8) Workspace Shell UI Logout regression check (cross-tab + embedded iframe active)
    await command('Page.navigate', { url: `${origin}/purchasing/dashboard` });
    await until('Boolean(window.AdasiWorkspaceShell && window.AdasiSessionSync)');
    await until('Boolean(document.querySelector("iframe.workspace-frame")?.contentWindow?.AdasiSessionSync)');
    await sleep(300);

    const reqStartIndex = capturedRequests.length;
    const triggerCenter = await evaluate(`(() => {
        const trigger = document.querySelector('.topbar-user-trigger');
        trigger?.scrollIntoView?.({ block: 'center' });
        const rect = trigger?.getBoundingClientRect();
        return rect ? { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 } : null;
    })()`);
    assert.ok(triggerCenter, 'Topbar user menu trigger exists');
    await command('Input.dispatchMouseEvent', { type: 'mousePressed', x: triggerCenter.x, y: triggerCenter.y, button: 'left', clickCount: 1 });
    await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x: triggerCenter.x, y: triggerCenter.y, button: 'left', clickCount: 1 });
    await until('document.querySelector(".topbar-user-trigger")?.getAttribute("aria-expanded") === "true"');

    const logoutBtnCenter = await evaluate(`(() => {
        const btn = document.querySelector('form[data-logout-form] button[type="submit"]');
        const rect = btn?.getBoundingClientRect();
        return rect && rect.width > 0 ? { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 } : null;
    })()`);
    assert.ok(logoutBtnCenter, 'Logout submit button is visible in user dropdown');
    await command('Input.dispatchMouseEvent', { type: 'mousePressed', x: logoutBtnCenter.x, y: logoutBtnCenter.y, button: 'left', clickCount: 1 });
    await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x: logoutBtnCenter.x, y: logoutBtnCenter.y, button: 'left', clickCount: 1 });

    await until('Boolean(document.querySelector("input[name=email]")) && (!window.AdasiAuthSplash || window.AdasiAuthSplash.state === "REMOVED")', 20000);
    const logoutEntries = capturedRequests.slice(reqStartIndex);
    assert.ok(
        logoutEntries.some((entry) => entry.method === 'POST' && entry.url.endsWith('/logout')),
        'Workspace shell logout submits POST /logout instead of bouncing to dashboard',
    );

    // Confirm server session is actually terminated
    await command('Page.navigate', { url: `${origin}/purchasing/dashboard` });
    await until('Boolean(document.querySelector("input[name=email]")) && (!window.AdasiAuthSplash || window.AdasiAuthSplash.state === "REMOVED")', 20000);
    await recordFinding('8_workspace_shell_ui_logout', {
        landedOnLogin: true,
        guestRedirectConfirmed: true,
    });

    assert.equal(browserErrors.length, 0, `Uncaught browser errors: ${browserErrors.join('; ')}`);

    if (brainArtifacts) {
        await mkdir(brainArtifacts, { recursive: true });
        for (const file of ['ws-shell-v2-1366-light.png', 'ws-shell-v2-1366-dark.png', 'ws-shell-v2-1366-id.png', 'ws-shell-v2-375-mobile.png']) {
            await copyFile(path.join(artifacts, file), path.join(brainArtifacts, file));
        }
    }

    const evidence = {
        contract: 'Infor LN-style Workspace Shell v2 (Pinned Shortcuts fallback, Multi-Instance tabs, Joined Parent-Child Groups, Auto-Collapsed Stack pills, LRU Iframe Hibernation).',
        targetViewports: ['1366x900', '375x900'],
        accessibility: {
            status: 'pass',
            evidence: 'Verified role=tablist/role=tab/role=tabpanel/role=menu semantics, stack menu, roving tabindex, >=40x44 touch targets, and ARIA counts in Chromium.',
        },
        responsive: {
            status: 'pass',
            evidence: 'Verified 1366x900 desktop and 375x900 mobile shells with zero horizontal overflow and single-row horizontally scrollable tab strip.',
        },
        contentStates: {
            loading: { status: 'pass', evidence: 'AuthSplash readiness gate and hairline frame loading bar verified.' },
            empty: { status: 'pass', evidence: 'Close-all fallback renders native Shortcuts Frequently Used panel + search recovery.' },
            error: { status: 'pass', evidence: 'Zero uncaught console/runtime errors across all v2 workflows.' },
            partial: { status: 'pass', evidence: 'Dirty frame protection prevents LRU hibernation and prompts on close; clean stale revisits refresh.' },
            success: { status: 'pass', evidence: '3x duplicate tabs, joined [Parent | Child x], auto-collapsed stacked tabs, and F5 #shortcuts verified live.' },
            permission: { status: 'pass', evidence: '/profile/security breaks out to top window without shell framing.' },
        },
        renderedEvidence: [
            { viewport: '1366x900', artifact: 'ws-shell-v2-1366-light.png', observation: 'Desktop light shell showing pinned Shortcuts, collapsed stacked PR group, duplicate tabs, and joined active group.' },
            { viewport: '1366x900', artifact: 'ws-shell-v2-1366-dark.png', observation: 'Desktop dark theme parity across stacked tabs, joined group underline, and iframe panel.' },
            { viewport: '375x900', artifact: 'ws-shell-v2-375-mobile.png', observation: 'Mobile 375px shell with zero horizontal overflow and >=40x44 touch targets.' },
        ],
        findings,
    };

    await writeFile(path.join(artifacts, 'evidence.json'), JSON.stringify(evidence, null, 2));
    if (brainArtifacts) {
        await writeFile(path.join(brainArtifacts, 'evidence-v2.json'), JSON.stringify(evidence, null, 2));
    }
    console.log('ALL WORKSPACE SHELL V2 BROWSER CHECKS PASSED');
} finally {
    try { socket?.close(); } catch {}
    try { server.kill('SIGTERM'); } catch {}
    try { browser.kill('SIGTERM'); } catch {}
    if (periodId) {
        await php(`App\\Models\\Period::whereKey(${Number(periodId)})->delete();`).catch(() => {});
    }
    if (userId) {
        await php(`App\\Models\\User::whereKey(${Number(userId)})->delete();`).catch(() => {});
    }
    await rm(profile, { recursive: true, force: true }).catch(() => {});
}
