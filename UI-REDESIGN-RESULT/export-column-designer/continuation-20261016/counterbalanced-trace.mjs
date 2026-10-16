// FINAL bounded counterbalanced capture. Import/--prepare never launches Chrome.
// Run only after the root agent confirms that other timing/CPU work has stopped.
// No product edits, loader reset, DB access, or existing-browser attachment.
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { launchOwnedChrome, navigate, evaluate, waitFor } from './cdp-browser-probe.mjs';

const output = path.dirname(fileURLToPath(import.meta.url));
const project = path.resolve(output, '../../..');
const protocolFile = path.join(output, 'counterbalanced-trace-protocol.json');
const checkedFile = path.join(output, 'trace-acceptance-checked.mjs');
const fixedFile = path.join(output, 'checked-trace-thresholds-frozen.json');
const metrics = ['ready', 'paint', 'command', 'commandPaint', 'openPaint'];
const sha = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');
const p95 = (values) => [...values].sort((a, b) => a - b)[Math.ceil(values.length * .95) - 1];
const median = (values) => [...values].sort((a, b) => a - b)[1];
const relative = (filename) => path.relative(project, filename).replaceAll('\\', '/');
const writeNew = (filename, value) => fs.writeFileSync(path.join(output, filename), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });

function checkedMeasurement() {
    // Reuse exactly the checked script's pure measurement construction, stopping
    // before its first browser-launch statement. This executes no capture code.
    const checked = fs.readFileSync(checkedFile, 'utf8');
    const begin = checked.indexOf('const original=');
    const end = checked.indexOf('const owned=');
    if (begin < 0 || end <= begin) throw new Error('Checked measurement construction anchors changed.');
    return new Function('fs', 'path', 'output', checked.slice(begin, end) + '\nreturn measure;')(fs, path, output);
}

function inputs() {
    const files = new Set([
        fileURLToPath(import.meta.url), checkedFile, fixedFile,
        path.join(output, 'cdp-browser-probe.mjs'), path.join(output, 'serve-frozen.mjs'),
        path.join(project, 'tests/Support/export-column-designer-browser-measure.js'),
        path.join(project, 'resources/js/advanced-export.js'), path.join(project, 'resources/js/export-column-designer.js'),
        path.join(project, 'resources/css/app.css'), path.join(project, 'resources/views/components/export/advanced-modal.blade.php'),
        path.join(project, 'lang/en/exports.php'), path.join(project, 'lang/id/exports.php'),
        path.join(output, 'legacy-en.html'), path.join(output, '../fixtures/purchasing.po-en.html'),
        path.join(output, '../fixtures/purchasing.po-en.json'), path.join(output, '../fixtures/purchasing.quotations-en.json'),
        path.join(output, 'vendor/bootstrap.css'), path.join(output, 'vendor/bootstrap.js'),
    ]);
    for (const variant of ['legacy', 'designer']) {
        const base = path.join(output, 'frozen', variant, 'public/build');
        const manifestFile = path.join(base, 'manifest.json'); files.add(manifestFile);
        const manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
        for (const asset of Object.values(manifest)) {
            files.add(path.join(base, asset.file));
            for (const css of asset.css || []) files.add(path.join(base, css));
        }
    }
    return [...files].sort().map((filename) => {
        const bytes = fs.readFileSync(filename);
        return { file: relative(filename), bytes: bytes.length, sha256: sha(bytes) };
    });
}

function schedule() {
    const trials = [];
    for (let batch = 0; batch < 3; batch++) for (const size of [12, 24, 64]) {
        for (const variant of batch === 1 ? ['designer', 'legacy'] : ['legacy', 'designer']) {
            trials.push({ index: trials.length, pair: batch * 3 + [12, 24, 64].indexOf(size), batch, size, variant });
        }
    }
    return trials;
}

function prepare() {
    if (fs.existsSync(protocolFile)) throw new Error('Declared protocol already exists; do not overwrite or tune it after observing a run.');
    const fixed = JSON.parse(fs.readFileSync(fixedFile, 'utf8'));
    if (fixed.values.length !== 15 || fixed.values.some((entry) => !Number.isFinite(entry.limit))) throw new Error('Incomplete checked frozen thresholds.');
    const protocol = {
        declaredAt: new Date().toISOString(), status: 'DECLARED BEFORE BROWSER LAUNCH',
        objective: 'One final bounded counterbalanced renderer-Paint comparison; strict nonregression remains open if evidence is incomplete or either strict comparison fails.',
        priorEvidencePreserved: ['paired-v2-comparison.json', 'cdp-paired-comparison.json', 'trace-acceptance-comparison.json', 'checked-trace-acceptance-comparison.json', 'checked-legacy-drift-replay.json'],
        measurement: {
            source: 'trace-acceptance-checked.mjs exact generated measure string, unchanged', sha256: sha(checkedMeasurement()),
            metrics, iterationsPerTrial: 30, discardedWarmupsPerTrial: 3, totalTrials: 18, totalMeasuredSamples: 540,
            readiness: 'One-shot MutationObserver sees submit enabled and complete expected header count; current catalog and UI lifecycle verified before timing.',
            framePhase: 'Same checked rAF alignment before open and command; selection measured separately and allowed to paint before command.',
            paint: 'Main-thread renderer Paint X-event end inside the checked two-rAF observation window. Not physical presentation or all transitions settled.',
            traceCategories: 'devtools.timeline,blink.user_timing,disabled-by-default-devtools.timeline.frame',
            missingPaint: 'FAIL; never substitute zero latency', dataLoss: 'Must be explicitly false for every trial',
        },
        environment: {
            browser: 'One fresh task-owned headless Chrome process and profile; same page navigated between variants',
            outerWindow: [1440, 1000], exactViewport: [1440, 900], deviceScaleFactor: 1, mobile: false,
            baseUrl: 'http://127.0.0.1:8103', fixtures: 'GET-only immutable frozen production source/build replay; no business records or DB',
            loaderGate: 'Before every trial require the actual window.AdasiLoader API, isActive()===false and pendingCount()===0 for three consecutive rAF observations. Never call reset().',
            moduleGate: 'Exact navigation, document complete, expected variant/root, Bootstrap, real loader API, fonts ready, and expected frozen app script/stylesheets; checked three discarded warmups then follow.',
            concurrency: 'Root must explicitly allow the run after its tests/builds/other performance captures finish. No concurrent intentional CPU/browser probes.',
        },
        schedule: schedule(),
        fixedThresholdComparison: { source: relative(fixedFile), sourceSha256: sha(fs.readFileSync(fixedFile)), originalCreated: fixed.created, method: fixed.method, values: fixed.values },
        pairedValidation: {
            method: 'Diagnostic validation only: paired legacy B=median of its three batch p95s and J=range; designer median p95 compared with B+J. This never replaces or relaxes the checked frozen limits.',
            significance: 'If either fixed thresholds or paired diagnostic fails, report performance gate open; preserve all individual samples and failed rows.',
            drift: 'Report paired legacy change from checked B and whether absolute change exceeds checked J, without recomputing checked thresholds.',
        },
        stopPolicy: 'Exactly this 18-trial schedule once. No automatic retry, threshold tuning, product edit, or repeated run until green. Any observation/trace/source/loader failure stops and records incomplete evidence.',
        inputs: inputs(),
    };
    fs.writeFileSync(protocolFile, JSON.stringify(protocol, null, 2) + '\n', { flag: 'wx' });
    console.log(JSON.stringify({ prepared: relative(protocolFile), trials: protocol.schedule.length, measurementSha256: protocol.measurement.sha256, launched: false }));
}

function verifyProtocol(protocol) {
    if (protocol.measurement.sha256 !== sha(checkedMeasurement())) throw new Error('Generated measurement changed after declaration.');
    const current = new Map(inputs().map((entry) => [entry.file, entry]));
    if (current.size !== protocol.inputs.length) throw new Error('Input inventory changed after declaration.');
    for (const entry of protocol.inputs) {
        const live = current.get(entry.file);
        if (!live || live.bytes !== entry.bytes || live.sha256 !== entry.sha256) throw new Error('Declared input changed: ' + entry.file);
    }
    if (sha(fs.readFileSync(fixedFile)) !== protocol.fixedThresholdComparison.sourceSha256) throw new Error('Checked frozen threshold file changed.');
    if (JSON.stringify(protocol.schedule) !== JSON.stringify(schedule())) throw new Error('Declared trial schedule changed.');
}

async function preparePage(owned, trial) {
    const expected = '/'+trial.variant;
    await navigate(owned.page, 'http://127.0.0.1:8103'+expected);
    await owned.page.send('Page.bringToFront');
    await waitFor(owned.page, `location.pathname === ${JSON.stringify(expected)} && document.readyState === 'complete' && Boolean(window.bootstrap?.Modal && window.AdasiLoader && typeof window.AdasiLoader.isActive === 'function' && typeof window.AdasiLoader.pendingCount === 'function' && document.querySelector('[data-advanced-export]'))`);
    const page = await evaluate(owned.page, async () => {
        await document.fonts.ready;
        const root = document.querySelector('[data-advanced-export]');
        return { href: location.href, documentReady: document.readyState, visibility: document.visibilityState, viewport: [innerWidth, innerHeight], dpr: devicePixelRatio, fonts: document.fonts.status, modern: Boolean(root.querySelector('[data-column-designer]')), exportKey: root.dataset.exportKey, scripts: [...document.scripts].map((node) => node.src).filter(Boolean), stylesheets: [...document.querySelectorAll('link[rel="stylesheet"]')].map((node) => node.href) };
    });
    if (page.visibility !== 'visible' || page.viewport[0] !== 1440 || page.viewport[1] !== 900 || page.dpr !== 1 || page.modern !== (trial.variant === 'designer')) throw new Error('Mismatched frozen page or viewport: ' + JSON.stringify(page));
    const requiredScript = '/assets/'+trial.variant+'/app.js';
    const requiredCss = '/assets/'+trial.variant+'/app.css';
    if (!page.scripts.some((url) => new URL(url).pathname === requiredScript) || !page.stylesheets.some((url) => new URL(url).pathname === requiredCss)) throw new Error('Expected frozen build resources missing.');
    await evaluate(owned.page, async () => {
        window.__designerMeasure = { po: await (await fetch('/exports/definitions/purchasing.po')).json(), quotes: await (await fetch('/exports/definitions/purchasing.quotations')).json(), runs: [] };
    });
    await evaluate(owned.page, `window.__designerMeasureSize=${trial.size};window.__designerMeasureBatch=${trial.batch};`);
    const loader = await evaluate(owned.page, async () => {
        const started = performance.now(); const samples = []; let consecutive = 0;
        while (consecutive < 3) {
            if (performance.now() - started > 15000) throw new Error('Real loader never became idle. Do not reset or bypass it.');
            await new Promise(requestAnimationFrame);
            const active = window.AdasiLoader.isActive(); const pending = window.AdasiLoader.pendingCount();
            const sample = { elapsed: performance.now() - started, active, pending }; samples.push(sample);
            consecutive = active === false && pending === 0 ? consecutive + 1 : 0;
        }
        return { waitedMs: performance.now() - started, samples, active: window.AdasiLoader.isActive(), pending: window.AdasiLoader.pendingCount(), resetCalled: false };
    });
    return { page, loader };
}

function summarize(events, trial, terminal, proxy, readiness, trace) {
    const rows = [];
    for (let iteration = 0; iteration < 30; iteration++) {
        const names = ['start', 'dataReady', 'painted', 'selectionStart', 'selectionEnd', 'commandStart', 'computed', 'commandPainted'];
        const marks = Object.fromEntries(names.map((name) => [name, events.find((event) => event.name === `column-${iteration}-${name}`)]));
        if (Object.values(marks).some((value) => !value)) throw new Error('Missing trace mark.');
        if (names.some((name, index) => index && marks[name].ts < marks[names[index - 1]].ts)) throw new Error('Out-of-order trace phase.');
        const paints = (from, to) => events.filter((event) => event.pid === marks.start.pid && event.tid === marks.start.tid && event.name === 'Paint' && event.ph === 'X' && event.ts >= from && event.ts + (event.dur || 0) <= to);
        const initial = paints(marks.dataReady.ts, marks.painted.ts);
        const command = paints(marks.commandStart.ts, marks.commandPainted.ts);
        if (!initial.length || !command.length) throw new Error('Paint boundary missing; never treat missing evidence as zero latency.');
        const openEnd = Math.max(...initial.map((event) => event.ts + (event.dur || 0)));
        const commandEnd = Math.max(...command.map((event) => event.ts + (event.dur || 0)));
        const drawAfter = (end) => events.filter((event) => event.name === 'DrawFrame' && event.ts >= end).sort((a, b) => a.ts - b.ts)[0];
        const openDraw = drawAfter(openEnd); const commandDraw = drawAfter(commandEnd);
        rows.push({ iteration, ready: (marks.dataReady.ts - marks.start.ts) / 1000, paint: (openEnd - marks.dataReady.ts) / 1000, command: (marks.computed.ts - marks.commandStart.ts) / 1000, commandPaint: (commandEnd - marks.commandStart.ts) / 1000, openPaint: (openEnd - marks.start.ts) / 1000, selection: (marks.selectionEnd.ts - marks.selectionStart.ts) / 1000, paintCounts: { initial: initial.length, command: command.length }, frameIds: [...new Set([...initial, ...command].map((event) => event.args?.data?.frame))], paintNodeNames: { initial: [...new Set(initial.map((event) => event.args?.data?.nodeName))], command: [...new Set(command.map((event) => event.args?.data?.nodeName))] }, uncorrelatedNextDrawFrameDiagnosticOnly: { open: openDraw ? { afterPaintMs: (openDraw.ts - openEnd) / 1000, frameSeqId: openDraw.args?.frameSeqId, layerTreeId: openDraw.args?.layerTreeId } : null, command: commandDraw ? { afterPaintMs: (commandDraw.ts - commandEnd) / 1000, frameSeqId: commandDraw.args?.frameSeqId, layerTreeId: commandDraw.args?.layerTreeId } : null } });
    }
    const result = { ...trial, source: 'declared frozen production replay', readiness, viewport: proxy.viewport, animations: proxy.animations, metadataRequests: proxy.metadataRequests, closed: proxy.closed, backdrops: proxy.backdrops, openLayers: proxy.openLayers, trace: { ...trace, dataLossOccurred: terminal.dataLossOccurred }, rows, p95: Object.fromEntries(metrics.map((metric) => [metric, p95(rows.map((row) => row[metric]))])) };
    if (!result.closed || result.backdrops || result.openLayers || result.metadataRequests !== 66 || result.animations.dialog || result.animations.backdrop) throw new Error('Fixture/lifecycle/request/animation check failed.');
    return result;
}

async function run() {
    const protocolBytes = fs.readFileSync(protocolFile);
    const protocol = JSON.parse(protocolBytes); verifyProtocol(protocol);
    if (fs.existsSync(path.join(output, 'counterbalanced-trace-started.json'))) throw new Error('Final bounded run already started; refuse automatic rerun.');
    writeNew('counterbalanced-trace-started.json', { startedAt: new Date().toISOString(), protocolSha256: sha(protocolBytes), declaredAt: protocol.declaredAt, schedule: protocol.schedule, noAutomaticRetry: true });
    const measure = checkedMeasurement(); const trials = [];
    const owned = await launchOwnedChrome({ label: 'counterbalanced-final', windowSize: [1440, 1000] });
    try {
        await owned.page.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        for (const trial of protocol.schedule) {
            verifyProtocol(protocol);
            const readiness = await preparePage(owned, trial);
            const complete = new Promise((resolve) => { const off = owned.client.on('Tracing.tracingComplete', (result) => { off(); resolve(result); }); });
            await owned.client.send('Tracing.start', { categories: protocol.measurement.traceCategories, transferMode: 'ReturnAsStream' });
            const proxy = await evaluate(owned.page, `(${measure})()`, { timeout: 60000 });
            await owned.client.send('Tracing.end'); const terminal = await complete;
            if (terminal.dataLossOccurred !== false) throw new Error('Trace loss status not explicitly false: ' + JSON.stringify(terminal));
            let raw = '';
            for (;;) { const chunk = await owned.client.send('IO.read', { handle: terminal.stream }); raw += chunk.base64Encoded ? Buffer.from(chunk.data, 'base64').toString('utf8') : chunk.data; if (chunk.eof) break; }
            await owned.client.send('IO.close', { handle: terminal.stream });
            const file = `counterbalanced-trace-${trial.variant}-${trial.size}-${trial.batch}.json.gz`;
            const bytes = zlib.gzipSync(raw); fs.writeFileSync(path.join(output, file), bytes, { flag: 'wx' });
            const summary = summarize(JSON.parse(raw).traceEvents, trial, terminal, proxy, readiness, { file, gzipBytes: bytes.length, sha256: sha(bytes) });
            summary.loaderAfter = await evaluate(owned.page, () => ({ active: window.AdasiLoader.isActive(), pending: window.AdasiLoader.pendingCount(), resetCalled: false }));
            if (summary.loaderAfter.active !== false || summary.loaderAfter.pending !== 0) throw new Error('Loader not idle after checked trial.');
            trials.push(summary); writeNew(file.replace('.json.gz', '-summary.json'), summary);
            console.log(JSON.stringify({ ...trial, p95: summary.p95, loaderBefore: { active: readiness.loader.active, pending: readiness.loader.pending }, loaderAfter: summary.loaderAfter, dataLossOccurred: terminal.dataLossOccurred }));
        }
        verifyProtocol(protocol);
        const designerValue = (size, metric) => median(trials.filter((trial) => trial.variant === 'designer' && trial.size === size).map((trial) => trial.p95[metric]));
        const fixed = protocol.fixedThresholdComparison.values.map((entry) => ({ ...entry, actual: designerValue(entry.size, entry.metric), status: designerValue(entry.size, entry.metric) <= entry.limit ? 'PASS' : 'FAIL' }));
        const paired = []; const legacyDrift = [];
        for (const size of [12, 24, 64]) for (const metric of metrics) {
            const legacy = trials.filter((trial) => trial.variant === 'legacy' && trial.size === size).map((trial) => trial.p95[metric]).sort((a, b) => a - b);
            const B = legacy[1]; const J = legacy[2] - legacy[0]; const actual = designerValue(size, metric);
            paired.push({ size, metric, B, J, limit: B + J, actual, status: actual <= B + J ? 'PASS' : 'FAIL', purpose: 'paired diagnostic validation; never replaces fixed checked threshold' });
            const old = protocol.fixedThresholdComparison.values.find((entry) => entry.size === size && entry.metric === metric);
            legacyDrift.push({ size, metric, pairedLegacyB: B, checkedB: old.B, checkedJ: old.J, delta: B - old.B, absoluteDeltaExceedsCheckedJ: Math.abs(B - old.B) > old.J });
        }
        const pairRows = [];
        for (let pair = 0; pair < 9; pair++) {
            const pairTrials = trials.filter((trial) => trial.pair === pair);
            const baseline = pairTrials.find((trial) => trial.variant === 'legacy'); const designer = pairTrials.find((trial) => trial.variant === 'designer');
            pairRows.push({ pair, size: baseline.size, batch: baseline.batch, order: pairTrials.map((trial) => trial.variant), differences: Object.fromEntries(metrics.map((metric) => [metric, designer.p95[metric] - baseline.p95[metric]])) });
        }
        const fixedStatus = fixed.every((entry) => entry.status === 'PASS') ? 'PASS' : 'FAIL';
        const pairedStatus = paired.every((entry) => entry.status === 'PASS') ? 'PASS' : 'FAIL';
        writeNew('counterbalanced-trace-comparison.json', { created: new Date().toISOString(), protocol: path.basename(protocolFile), protocolSha256: sha(protocolBytes), version: owned.version, profileDir: owned.profileDir, ownedPid: owned.pid, args: owned.args, schedule: protocol.schedule, measuredSamples: 540, fixedThresholdComparison: { status: fixedStatus, unchangedSource: protocol.fixedThresholdComparison.source, sourceSha256: protocol.fixedThresholdComparison.sourceSha256, values: fixed }, pairedDiagnosticValidation: { status: pairedStatus, method: protocol.pairedValidation.method, values: paired }, pairs: pairRows, checkedBaselineDrift: legacyDrift, priorFailuresAndDriftPreserved: protocol.priorEvidencePreserved, outcome: fixedStatus === 'PASS' && pairedStatus === 'PASS' ? 'BOTH COMPARISONS PASS; INDEPENDENT REVIEW REQUIRED BEFORE ACCEPTANCE' : 'PERFORMANCE GATE OPEN; NO MORE CAPTURE ITERATION', limitations: ['Renderer Paint ends only, no physical presentation claim.', 'Last Paint is bounded by the declared two-rAF observation window.', 'Uncorrelated next DrawFrame values are diagnostics only.', 'Three batches provide finite local evidence, not production SLA.', 'Legacy drift from prior checked baseline is reported explicitly; thresholds remain unchanged.'] });
        console.log(JSON.stringify({ fixedStatus, pairedStatus, trials: trials.length, measuredSamples: 540, noFurtherCaptureIteration: true }));
    } catch (error) {
        writeNew('counterbalanced-trace-failure.json', { at: new Date().toISOString(), completedTrials: trials.length, error: error.stack, status: 'INCOMPLETE EVIDENCE; PERFORMANCE GATE OPEN; NO AUTOMATIC RETRY' });
        throw error;
    } finally { await owned.close(); }
}

const direct = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (direct) {
    const task = process.argv.includes('--prepare') ? prepare : process.argv.includes('--run') ? run : () => console.log('Prepared helper only. --prepare declares immutable protocol; --run requires root clearance and launches one final bounded owned-browser capture.');
    Promise.resolve().then(task).catch((error) => { console.error(error.stack); process.exitCode = 1; });
}
