// Standalone, dependency-free probes of a task-owned Chrome process.
// Importing this file never launches a browser. CLI: node <this-file> --run.
// All profiles and probe artifacts stay beside this file. No application DB,
// existing browser, or product source is changed by this helper.
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync, openSync, writeSync, closeSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const artifactDirectory = path.dirname(fileURLToPath(import.meta.url));
const chromeExecutable = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function ownedPath(candidate) {
    const absolute = path.resolve(candidate);
    const relative = path.relative(artifactDirectory, absolute);
    if (!relative || relative.startsWith(`..${path.sep}`) || relative === '..' || path.isAbsolute(relative)) {
        throw new Error(`Artifact/profile must be a child of ${artifactDirectory}`);
    }
    return absolute;
}

export async function connectCdp(webSocketUrl) {
    const socket = new WebSocket(webSocketUrl);
    const pending = new Map();
    const subscriptions = new Set();
    let sequence = 0;
    const terminate = (error) => {
        for (const request of pending.values()) { clearTimeout(request.timer); request.reject(error); }
        pending.clear();
    };
    socket.addEventListener('close', () => terminate(new Error('Owned CDP connection closed.')));
    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const request = pending.get(message.id);
            if (!request) return;
            pending.delete(message.id); clearTimeout(request.timer);
            if (message.error) request.reject(new Error(`${request.method}: ${JSON.stringify(message.error)}`));
            else request.resolve(message.result || {});
        } else {
            for (const entry of subscriptions) {
                if (entry.method === message.method && (entry.sessionId === undefined || entry.sessionId === message.sessionId)) entry.callback(message.params || {});
            }
        }
    });
    await new Promise((resolve, reject) => {
        const timer = setTimeout(() => { socket.close(); reject(new Error('Owned Chrome WebSocket opening timeout.')); }, 15000);
        socket.addEventListener('open', () => { clearTimeout(timer); resolve(); }, { once: true });
        socket.addEventListener('error', (event) => { clearTimeout(timer); reject(new Error(`Owned Chrome WebSocket error: ${event.message || 'connection failed'}`)); }, { once: true });
    });
    return {
        send(method, params = {}, sessionId, timeout = 30000) {
            const id = ++sequence;
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP observation timeout: ${method}`)); }, timeout);
                pending.set(id, { resolve, reject, timer, method });
                socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
            });
        },
        on(method, callback, sessionId) {
            const entry = { method, callback, sessionId }; subscriptions.add(entry);
            return () => subscriptions.delete(entry);
        },
        close() { subscriptions.clear(); socket.close(); },
    };
}

export async function createPage(client, url = 'about:blank') {
    const { targetId } = await client.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await client.send('Target.attachToTarget', { targetId, flatten: true });
    const page = {
        targetId, sessionId,
        send: (method, params = {}, timeout) => client.send(method, params, sessionId, timeout),
        on: (method, callback) => client.on(method, callback, sessionId),
    };
    await page.send('Page.enable');
    await page.send('Runtime.enable');
    if (url !== 'about:blank') await navigate(page, url);
    return page;
}

export async function evaluate(page, expression, { timeout = 30000, objectGroup = 'column-probe' } = {}) {
    const source = typeof expression === 'function' ? `(${expression.toString()})()` : expression;
    const result = await page.send('Runtime.evaluate', { expression: source, awaitPromise: true, returnByValue: true, objectGroup }, timeout);
    if (result.exceptionDetails) throw new Error(`Page evaluation failed: ${result.exceptionDetails.exception?.description || result.exceptionDetails.text}`);
    return result.result?.value;
}

export async function waitFor(page, expression, { timeout = 20000, interval = 50 } = {}) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        if (await evaluate(page, expression)) return;
        await delay(interval);
    }
    throw new Error(`Fixture condition observation timeout: ${String(expression).slice(0, 220)}`);
}

export async function navigate(page, url) {
    const result = await page.send('Page.navigate', { url });
    if (result.errorText) throw new Error(`Fixture navigation failed: ${result.errorText}`);
    await waitFor(page, () => document.readyState === 'complete');
}

export async function launchOwnedChrome({ profileDir, zoomFactor = 1, executable = chromeExecutable, windowSize = [1280, 900], label = 'probe' } = {}) {
    if (!Number.isFinite(zoomFactor) || zoomFactor <= 0) throw new Error('Invalid browser zoom factor.');
    const directory = ownedPath(profileDir || path.join(artifactDirectory, 'owned-chrome-profiles', `${label}-${Date.now()}-${randomUUID()}`));
    if (existsSync(directory)) throw new Error(`Refusing a pre-existing browser profile: ${directory}`);
    mkdirSync(path.join(directory, 'Default'), { recursive: true });
    // Chromium ChromeZoomLevelPrefs reads the default storage partition key x.
    // This is a real browser zoom preference, never a page/pinch scale override.
    // Primary source: https://chromium.googlesource.com/chromium/src/+/lkgr/chrome/browser/ui/zoom/chrome_zoom_level_prefs.cc
    const zoomLevel = Math.log(zoomFactor) / Math.log(1.2);
    writeFileSync(path.join(directory, 'Default', 'Preferences'), JSON.stringify({ partition: { default_zoom_level: { x: zoomLevel } } }));
    const args = [
        '--headless=new', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1',
        `--user-data-dir=${directory}`, `--window-size=${windowSize.join(',')}`,
        '--force-device-scale-factor=1', '--no-first-run', '--no-default-browser-check',
        '--disable-extensions', '--disable-background-networking', '--disable-component-update',
        '--disable-sync', '--metrics-recording-only', 'about:blank',
    ];
    const stderr = openSync(path.join(directory, 'chrome-stderr.log'), 'w');
    const process = spawn(executable, args, { windowsHide: true, stdio: ['ignore', 'ignore', stderr] });
    closeSync(stderr);
    let spawnError;
    process.on('error', (error) => { spawnError = error; });
    let client;
    const close = async () => {
        try { if (client) await client.send('Browser.close', {}, undefined, 5000); } catch { /* Browser.close normally closes the socket first. */ }
        const deadline = Date.now() + 5000;
        while (process.exitCode === null && process.signalCode === null && Date.now() < deadline) await delay(50);
        // Only the child created by this function is eligible for this fallback.
        if (process.exitCode === null && process.signalCode === null) process.kill();
        client?.close();
    };
    try {
        const activePortFile = path.join(directory, 'DevToolsActivePort');
        const deadline = Date.now() + 20000;
        while (!existsSync(activePortFile)) {
            if (spawnError) throw spawnError;
            if (process.exitCode !== null) throw new Error(`Owned Chrome exited before CDP was ready: ${process.exitCode}`);
            if (Date.now() >= deadline) throw new Error('Owned Chrome DevToolsActivePort observation timeout.');
            await delay(50);
        }
        const [port, browserPath] = readFileSync(activePortFile, 'utf8').trim().split(/\r?\n/);
        client = await connectCdp(`ws://127.0.0.1:${port}${browserPath}`);
        const version = await client.send('Browser.getVersion');
        const page = await createPage(client);
        return { process, pid: process.pid, profileDir: directory, zoomFactor, zoomLevel, args, client, page, version, close };
    } catch (error) { await close(); throw error; }
}

export async function screenshot(page, filename) {
    const result = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false, fromSurface: true });
    const output = ownedPath(filename); writeFileSync(output, Buffer.from(result.data, 'base64')); return output;
}

async function prepareFixture(page, url) {
    await navigate(page, url);
    await waitFor(page, () => Boolean(window.__fixtureController && window.bootstrap?.Modal));
    await evaluate(page, () => document.querySelector('[data-export-open]').click());
    await waitFor(page, () => document.querySelector('.modal.show') && !document.querySelector('[data-export-submit]').disabled);
}

async function inspectLayout(page) {
    return evaluate(page, async () => {
        const root = document.querySelector('[data-advanced-export]');
        const modal = root.querySelector('.modal');
        const rect = (node) => { const r = node.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom }; };
        const frame = () => new Promise(requestAnimationFrame);
        const inViewport = (node) => { const r = node.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.left >= -1 && r.top >= -1 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1; };
        const actions = [];
        for (const button of root.querySelectorAll('.ui-column-ribbon [data-column-command]')) {
            button.scrollIntoView({ block: 'nearest', inline: 'nearest' }); await frame();
            actions.push({ command: button.dataset.columnCommand, label: button.textContent.trim(), disabled: button.disabled, rect: rect(button), reachable: inViewport(button) });
        }
        const menuButton = root.querySelector('[data-column-command="menu"]');
        menuButton.scrollIntoView({ block: 'nearest' }); menuButton.click(); await frame();
        const menu = root.querySelector('[data-column-menu]');
        const menuOpen = !menu.hidden && inViewport(menu) && menu.contains(document.activeElement);
        const menuRect = rect(menu);
        const menuCommands = [];
        for (const button of menu.querySelectorAll('[role="menuitem"]')) {
            button.scrollIntoView({ block: 'nearest' }); await frame();
            menuCommands.push({ command: button.dataset.columnCommand, disabled: button.disabled, reachable: !menu.hidden && inViewport(button) });
        }
        if (!menu.hidden) document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        const escapeRestoresInvoker = menu.hidden && document.activeElement === menuButton && modal.classList.contains('show');
        const controls = [];
        for (const selector of ['[data-export-format]', '[data-export-preset]', '[data-export-preset-name]', '[data-export-save]', '[data-export-submit]', '.modal-footer [data-bs-dismiss]']) {
            const node = root.querySelector(selector); node.scrollIntoView({ block: 'nearest', inline: 'nearest' }); await frame();
            controls.push({ selector, rect: rect(node), reachable: inViewport(node) });
        }
        return {
            innerWidth, innerHeight, outerWidth, outerHeight, devicePixelRatio,
            visualViewport: visualViewport ? { width: visualViewport.width, height: visualViewport.height, scale: visualViewport.scale, offsetLeft: visualViewport.offsetLeft, offsetTop: visualViewport.offsetTop } : null,
            coarsePointer: matchMedia('(pointer: coarse)').matches,
            modal: rect(modal), modalContent: rect(root.querySelector('.modal-content')),
            documentWidth: document.documentElement.scrollWidth, bodyWidth: document.body.scrollWidth,
            headings: [...root.querySelectorAll('[data-export-column]')].map((node) => ({ key: node.dataset.key, label: node.querySelector('[data-column-label]').textContent })),
            emptyRows: root.querySelector('[data-column-empty-rows]').children.length,
            actions, menu: { openedAndFocused: menuOpen, rect: menuRect, commands: menuCommands, escapeRestoresInvoker }, controls,
            reachable: actions.every((item) => item.reachable) && menuOpen && menuCommands.every((item) => item.reachable) && escapeRestoresInvoker && controls.every((item) => item.reachable),
        };
    });
}

async function browserZoomProbe(url) {
    const cases = [];
    for (const zoomFactor of [1, 2, 4]) {
        const owned = await launchOwnedChrome({ zoomFactor, label: `zoom-${zoomFactor}` });
        try {
            await prepareFixture(owned.page, url);
            const layout = await inspectLayout(owned.page);
            const metrics = await owned.page.send('Page.getLayoutMetrics');
            const shot = await screenshot(owned.page, path.join(artifactDirectory, `final-cdp-zoom-${zoomFactor * 100}.png`));
            cases.push({ requestedZoomFactor: zoomFactor, defaultZoomLevel: owned.zoomLevel, profileDir: owned.profileDir, ownedPid: owned.pid, version: owned.version, args: owned.args, layout, layoutMetrics: metrics, screenshot: path.basename(shot) });
        } finally { await owned.close(); }
    }
    const baseline = cases[0].layout;
    for (const item of cases) {
        const factor = item.requestedZoomFactor;
        item.actualLayoutZoomVerified = Math.abs(item.layout.devicePixelRatio / baseline.devicePixelRatio - factor) < 0.02
            && Math.abs(item.layout.innerWidth - baseline.innerWidth / factor) <= 1
            && item.layout.outerWidth === baseline.outerWidth
            && Math.abs(item.layout.visualViewport.scale - 1) < 0.001;
        item.status = item.actualLayoutZoomVerified && item.layout.reachable ? 'PASS' : 'FAIL';
    }
    return { status: cases.every((item) => item.status === 'PASS') ? 'PASS' : 'FAIL', scope: 'Actual default browser layout zoom preference in fresh task-owned headless Chrome; same outer window size, no page scale or device metrics override. Synthetic DOM clicks are functional probes, not trusted physical-input evidence.', cases };
}

export async function heapSnapshot(page, filename) {
    const output = ownedPath(filename);
    const descriptor = openSync(output, 'w'); let bytes = 0;
    const remove = page.on('HeapProfiler.addHeapSnapshotChunk', ({ chunk }) => { bytes += Buffer.byteLength(chunk); writeSync(descriptor, chunk); });
    try {
        await page.send('HeapProfiler.takeHeapSnapshot', { reportProgress: false }, 120000);
    } finally { remove(); closeSync(descriptor); }
    return { path: output, bytes };
}

export function taggedHeapObjects(filename, markers) {
    const snapshot = JSON.parse(readFileSync(filename, 'utf8'));
    const nodeFields = snapshot.snapshot.meta.node_fields;
    const edgeFields = snapshot.snapshot.meta.edge_fields;
    const nodeStride = nodeFields.length; const edgeStride = edgeFields.length;
    const edgeCountIndex = nodeFields.indexOf('edge_count');
    const nodeTypeIndex = nodeFields.indexOf('type'); const nameIndex = nodeFields.indexOf('name'); const idIndex = nodeFields.indexOf('id');
    const edgeTypeIndex = edgeFields.indexOf('type'); const edgeNameIndex = edgeFields.indexOf('name_or_index'); const edgeTargetIndex = edgeFields.indexOf('to_node');
    const edgeTypes = snapshot.snapshot.meta.edge_types[edgeTypeIndex]; const nodeTypes = snapshot.snapshot.meta.node_types[nodeTypeIndex];
    const found = Object.fromEntries(markers.map((marker) => [marker, []]));
    let edge = 0;
    for (let node = 0; node < snapshot.nodes.length; node += nodeStride) {
        const edges = snapshot.nodes[node + edgeCountIndex];
        for (let index = 0; index < edges; index++, edge += edgeStride) {
            if (edgeTypes[snapshot.edges[edge + edgeTypeIndex]] !== 'property' || snapshot.strings[snapshot.edges[edge + edgeNameIndex]] !== '__columnDesignerHeapProbe') continue;
            const target = snapshot.edges[edge + edgeTargetIndex];
            const value = snapshot.strings[snapshot.nodes[target + nameIndex]];
            if (Object.hasOwn(found, value)) found[value].push({ id: snapshot.nodes[node + idIndex], type: nodeTypes[snapshot.nodes[node + nodeTypeIndex]], name: snapshot.strings[snapshot.nodes[node + nameIndex]] });
        }
    }
    return { taggedObjects: found, count: Object.values(found).reduce((sum, objects) => sum + objects.length, 0), totalNodes: snapshot.nodes.length / nodeStride };
}

async function retainedRootProbe(url) {
    const owned = await launchOwnedChrome({ label: 'retained-roots' });
    const markers = ['root', 'menu', 'unhide'].map((kind) => `CDP_COLUMN_${kind.toUpperCase()}_${randomUUID().replaceAll('-', '')}`);
    try {
        await prepareFixture(owned.page, url);
        await owned.page.send('HeapProfiler.enable');
        const before = await evaluate(owned.page, `(() => {
            const root = document.querySelector('[data-advanced-export]');
            const nodes = [root, root.querySelector('[data-column-menu]'), root.querySelector('[data-column-unhide]')];
            const markers = ${JSON.stringify(markers)};
            nodes.forEach((node, index) => { node.__columnDesignerHeapProbe = markers[index]; });
            window.__columnProbeWeakRefs = nodes.map((node) => new WeakRef(node));
            return { connected: nodes.map((node) => node.isConnected), weakRefsLive: window.__columnProbeWeakRefs.map((reference) => Boolean(reference.deref())) };
        })()`);
        await owned.page.send('Runtime.releaseObjectGroup', { objectGroup: 'column-probe' });
        await owned.page.send('HeapProfiler.collectGarbage');
        const liveSnapshot = await heapSnapshot(owned.page, path.join(artifactDirectory, 'cdp-heap-live.heapsnapshot'));
        const liveTagged = taggedHeapObjects(liveSnapshot.path, markers);
        // Finite repeatability probe; Bootstrap transitions are awaited each time.
        const cycles = await evaluate(owned.page, async () => {
            const root = document.querySelector('[data-advanced-export]'); const modal = root.querySelector('.modal');
            const wait = async (condition) => { const deadline = performance.now() + 10000; while (!condition()) { if (performance.now() > deadline) throw new Error('Owned cleanup cycle observation timeout.'); await new Promise((resolve) => setTimeout(resolve, 10)); } };
            bootstrap.Modal.getOrCreateInstance(modal).hide();
            await wait(() => !document.querySelector('.modal-backdrop') && !document.body.classList.contains('modal-open'));
            for (let cycle = 0; cycle < 60; cycle++) {
                root.querySelector('[data-export-open]').click();
                await wait(() => modal.classList.contains('show') && !root.querySelector('[data-export-submit]').disabled);
                root.querySelector('[data-column-command="menu"]').click();
                document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
                bootstrap.Modal.getOrCreateInstance(modal).hide();
                await wait(() => !document.querySelector('.modal-backdrop') && !document.body.classList.contains('modal-open'));
            }
            return { count: 60, layers: root.querySelectorAll('[data-column-layer]:not([hidden])').length, backdrop: Boolean(document.querySelector('.modal-backdrop')), bodyLocked: document.body.classList.contains('modal-open') };
        }, { timeout: 90000 });
        const destroyed = await evaluate(owned.page, () => {
            const root = document.querySelector('[data-advanced-export]');
            window.__fixtureController.destroy();
            delete window.__fixtureController;
            root.remove();
            // Drop focus from removed controls; no test global stores root/menu.
            document.body.setAttribute('tabindex', '-1'); document.body.focus();
            return { remainingRoots: document.querySelectorAll('[data-advanced-export]').length, remainingLayers: document.querySelectorAll('[data-column-layer]').length, backdrops: document.querySelectorAll('.modal-backdrop').length, controllerGlobalExists: Object.hasOwn(window, '__fixtureController'), bodyLocked: document.body.classList.contains('modal-open') };
        });
        await owned.page.send('Runtime.releaseObjectGroup', { objectGroup: 'column-probe' });
        await owned.page.send('Runtime.discardConsoleEntries');
        await owned.page.send('HeapProfiler.collectGarbage');
        await delay(100);
        await owned.page.send('HeapProfiler.collectGarbage');
        const deadSnapshot = await heapSnapshot(owned.page, path.join(artifactDirectory, 'cdp-heap-destroyed.heapsnapshot'));
        const deadTagged = taggedHeapObjects(deadSnapshot.path, markers);
        const weakRefsLive = await evaluate(owned.page, () => window.__columnProbeWeakRefs.map((reference) => Boolean(reference.deref())));
        const calibrated = liveTagged.count === markers.length && Object.values(liveTagged.taggedObjects).every((objects) => objects.length === 1);
        const status = calibrated && deadTagged.count === 0 && weakRefsLive.every((live) => !live) && destroyed.remainingRoots === 0 && destroyed.remainingLayers === 0 && destroyed.backdrops === 0 && !destroyed.controllerGlobalExists && !destroyed.bodyLocked && cycles.layers === 0 ? 'PASS' : 'FAIL';
        return { status, scope: 'Three uniquely tagged DOM wrappers, independently checked by WeakRef and actual V8 heap property edges before/after destroy+remove+two forced GCs, following 60 open/close cycles. No remote DOM handles are returned or retained. This is one finite owned-browser probe, not a universal no-leak claim.', version: owned.version, ownedPid: owned.pid, profileDir: owned.profileDir, before, cycles, destroyed, markers, calibrated, weakRefsLive, liveSnapshot: { ...liveSnapshot, ...liveTagged }, destroyedSnapshot: { ...deadSnapshot, ...deadTagged } };
    } finally { await owned.close(); }
}

async function main() {
    const args = new Map(process.argv.slice(2).map((argument) => { const [key, ...value] = argument.replace(/^--/, '').split('='); return [key, value.length ? value.join('=') : true]; }));
    if (!args.has('run')) {
        process.stdout.write('Prepared only. Run explicitly: node cdp-browser-probe.mjs --run --probe=all|zoom|heap [--url=http://127.0.0.1:8101/?api=1]\n');
        return;
    }
    const url = args.get('url') || 'http://127.0.0.1:8101/?api=1';
    const parsed = new URL(url);
    if (parsed.hostname !== '127.0.0.1' || parsed.protocol !== 'http:') throw new Error('Probe only permits a local GET-only fixture.');
    const probe = args.get('probe') || 'all';
    if (!['all', 'zoom', 'heap'].includes(probe)) throw new Error('Unknown probe.');
    const result = { generatedAt: new Date().toISOString(), url, node: process.version };
    if (probe !== 'heap') { result.zoom = await browserZoomProbe(url); writeFileSync(path.join(artifactDirectory, 'final-cdp-browser-zoom.json'), JSON.stringify(result.zoom, null, 2)); }
    if (probe !== 'zoom') { result.heap = await retainedRootProbe(url); writeFileSync(path.join(artifactDirectory, 'cdp-retained-roots.json'), JSON.stringify(result.heap, null, 2)); }
    result.status = [result.zoom, result.heap].filter(Boolean).every((entry) => entry.status === 'PASS') ? 'PASS' : 'FAIL';
    writeFileSync(path.join(artifactDirectory, `final-cdp-probe-${probe}.json`), JSON.stringify(result, null, 2));
    process.stdout.write(JSON.stringify({ status: result.status, zoom: result.zoom?.status, heap: result.heap?.status, artifactDirectory }) + '\n');
    if (result.status !== 'PASS') process.exitCode = 1;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => { process.stderr.write(`${error.stack}\n`); process.exitCode = 1; });
}
