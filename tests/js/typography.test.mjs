import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';
import postcss from 'postcss';

// Portal typography contract: one self-hosted Source Sans Pro family behind --ui-font-sans, shared by
// Bootstrap, Tailwind tw:font-sans, alerts and Chart.js; monospace only where characters must stay distinct.

const root = fileURLToPath(new URL('../..', import.meta.url));
const read = (file) => readFileSync(path.join(root, file), 'utf8');
const TOKEN = '"Source Sans Pro", ui-sans-serif, system-ui, sans-serif';

const bundles = await build({ root, logLevel: 'silent', build: { write: false, minify: false, cssMinify: false } });
const output = (Array.isArray(bundles) ? bundles : [bundles]).flatMap((bundle) => bundle.output);
const assetNames = new Set(output.map((asset) => asset.fileName));
const css = output.filter((asset) => asset.type === 'asset' && asset.fileName.endsWith('.css')).map((asset) => String(asset.source)).join('\n');
const ast = postcss.parse(css);

test('Source Sans Pro is pinned and self-hosted through the Vite build', () => {
    const pkg = JSON.parse(read('package.json'));
    assert.match(pkg.devDependencies['@fontsource/source-sans-pro'] || '', /^\d+\.\d+\.\d+$/, 'exact version, no range');

    const faces = [];
    ast.walkAtRules('font-face', (rule) => {
        const decl = (prop) => rule.nodes.find((node) => node.prop === prop)?.value;
        faces.push({ family: decl('font-family')?.replace(/["']/g, ''), weight: decl('font-weight'), style: decl('font-style'), src: decl('src') });
    });
    const ours = faces.filter((face) => face.family === 'Source Sans Pro');
    for (const [weight, style] of [['400', 'normal'], ['400', 'italic'], ['600', 'normal'], ['700', 'normal']]) {
        assert.ok(ours.some((face) => face.weight === weight && face.style === style), `face ${weight} ${style} is bundled`);
    }
    for (const face of ours) {
        assert.doesNotMatch(face.src, /https?:\/\//, 'font files come from the app origin, not a CDN');
        const files = [...face.src.matchAll(/url\(["']?([^"')]+)["']?\)/g)].map((match) => match[1].replace(/^\/?build\//, '').replace(/^\//, ''));
        assert.ok(files.length > 0 && files.every((file) => assetNames.has(file)), 'every src url is an emitted asset: ' + files.join(', '));
    }
    assert.deepEqual([...new Set(faces.map((face) => face.family))], ['Source Sans Pro'], 'no other web font family is bundled');
});

test('Bootstrap, Tailwind and the root share one font token', () => {
    let token = null;
    const declarations = {};
    ast.walkDecls((decl) => {
        if (decl.prop === '--ui-font-sans' && decl.parent.selector === ':root') token = decl.value;
        if (['--bs-body-font-family', '--bs-font-sans-serif'].includes(decl.prop)) declarations[decl.prop] = decl.value;
    });
    assert.match(token || '', /^"Source Sans Pro",/, 'Source Sans Pro leads the stack');
    assert.equal(declarations['--bs-body-font-family'], 'var(--ui-font-sans)');
    assert.equal(declarations['--bs-font-sans-serif'], 'var(--ui-font-sans)');

    const fontSans = [];
    ast.walkRules((rule) => { if (rule.selector === '.tw\\:font-sans') fontSans.push(rule.toString()); });
    assert.equal(fontSans.length, 1);
    assert.match(fontSans[0], /font-family:\s*var\(--ui-font-sans\)/);

    const families = [];
    ast.walkDecls('font-family', (decl) => families.push(decl.value));
    assert.equal(families.filter((value) => /\b(Inter|Figtree|Instrument Sans)\b/.test(value)).length, 0, 'no retired UI family is referenced');
});

test('layouts, alerts and charts no longer load or name retired families', () => {
    for (const layout of ['app', 'auth', 'guest', 'public-document']) {
        assert.doesNotMatch(read(`resources/views/layouts/${layout}.blade.php`), /@fontsource\/(inter|figtree)|fonts\.googleapis/, layout + ' layout');
    }
    for (const file of ['public/assets/css/adasi-alert.css', 'resources/js/chart-theme.js', 'resources/views/purchasing/comparison/_scripts.blade.php', 'resources/views/purchasing/comparison/_historical_pr_scripts.blade.php']) {
        assert.doesNotMatch(read(file), /['"]Inter['"]|\bInter,|family:\s*'Inter'/, file);
    }
    assert.match(read('public/assets/css/adasi-alert.css'), /font-family: var\(--ui-font-sans/);
});

test('monospace stays only where characters must remain distinguishable', () => {
    // Mixed-case random codes the user copies or types: recovery codes, 2FA codes, registration reference and access key.
    // Audit-log metadata is raw JSON. PDFs keep their own embedded fonts; welcome.blade.php is an unrouted scaffold.
    const allowed = new Map([
        ['resources/views/profile/two-factor-recovery-codes.blade.php', 1],
        ['resources/views/profile/partials/two-factor-authentication-form.blade.php', 1],
        ['resources/views/auth/two-factor-challenge.blade.php', 1],
        ['resources/views/auth/supplier-register-success.blade.php', 2],
        ['resources/views/auth/supplier-registration-access.blade.php', 2],
        ['resources/views/admin/auth-audit-logs/index.blade.php', 1],
    ]);
    const files = (directory) => readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const filename = path.join(directory, entry.name);
        return entry.isDirectory() ? files(filename) : entry.name.endsWith('.php') ? [filename] : [];
    });
    const found = new Map();
    for (const filename of [...files(path.join(root, 'resources/views')), ...files(path.join(root, 'app'))]) {
        const relative = path.relative(root, filename).split(path.sep).join('/');
        if (relative.startsWith('resources/views/pdf/') || relative === 'resources/views/welcome.blade.php') continue;
        const count = (readFileSync(filename, 'utf8').match(/font-mono(?:space)?\b/g) || []).length;
        if (count) found.set(relative, count);
    }
    assert.deepEqual(Object.fromEntries(found), Object.fromEntries(allowed));
});

test('charts take the font token, leave family out of per-chart fonts, and repaint once the face loads', async () => {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    let releaseFonts;
    const fontsLoaded = new Promise((resolve) => { releaseFonts = resolve; });
    const loads = [];
    const Chart = { defaults: { font: {}, plugins: { tooltip: {} } }, register() {}, instances: {} };
    globalThis.window = { addEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {} }), Chart };
    globalThis.document = {
        documentElement: {},
        readyState: 'complete',
        body: null,
        querySelector: () => null,
        fonts: { load: (font) => { loads.push(font); return fontsLoaded.then(() => []); } },
    };
    globalThis.getComputedStyle = () => ({ getPropertyValue: (name) => (name === '--ui-font-sans' ? ' ' + TOKEN + ' ' : '') });
    try {
        const module = await import('../../resources/js/chart-theme.js?test=typography');
        assert.equal(module.chartFontFamily(), TOKEN);
        assert.equal(Chart.defaults.font.family, TOKEN);
        assert.deepEqual(loads, [`400 12px ${TOKEN}`, `600 12px ${TOKEN}`]);

        const scales = module.getChartScales();
        for (const axis of Object.values(scales)) {
            assert.equal(axis.ticks?.font?.family, undefined, 'tick font inherits the default family');
        }
        const tooltip = module.getChartTooltip();
        assert.equal(tooltip.titleFont.family, undefined);
        assert.equal(tooltip.bodyFont.family, undefined);

        Chart.defaults.font.family = 'stale';
        releaseFonts();
        await fontsLoaded;
        await new Promise((resolve) => setTimeout(resolve, 0));
        assert.equal(Chart.defaults.font.family, TOKEN, 'charts are refreshed after the web font loads');
    } finally {
        Object.entries(saved).forEach(([key, value]) => (value === undefined ? delete globalThis[key] : (globalThis[key] = value)));
    }
});
