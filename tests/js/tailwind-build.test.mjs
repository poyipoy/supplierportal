import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';
import { compile } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';
import postcss from 'postcss';

const root = fileURLToPath(new URL('../..', import.meta.url));
const bundles = await build({ root, logLevel: 'silent', build: { write: false, minify: false, cssMinify: false } });
const output = (Array.isArray(bundles) ? bundles : [bundles]).flatMap(bundle => bundle.output);
const cssAssets = output.filter(asset => asset.type === 'asset' && asset.fileName.endsWith('.css'));
const appCssAsset = cssAssets.find(asset => /(?:^|\/)app-[^/]+\.css$/.test(asset.fileName));
assert.ok(appCssAsset, 'Compiled app CSS asset must exist');
const css = String(appCssAsset.source);
const allCss = cssAssets.map(asset => String(asset.source)).join('\n');
const ast = postcss.parse(css);
const rules = [];
ast.walkRules(rule => rules.push(rule));
const allRules = [];
postcss.parse(allCss).walkRules(rule => allRules.push(rule));
const escapeClass = name => '.' + name.replace(/([^\w-])/g, '\\$1');

test('every compilable application utility is present in the real build, including PHP and public JS sources', async () => {
    const files = directory => readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const filename = path.join(directory, entry.name);
        return entry.isDirectory() ? files(filename) : /\.(?:php|js|css)$/.test(entry.name) ? [filename] : [];
    });
    const sources = ['app', 'resources', 'public/assets/js'].flatMap(directory => files(path.join(root, directory)));
    const candidates = new Scanner({ sources: [] }).scanFiles(sources.map(filename => ({
        content: readFileSync(filename, 'utf8'),
        extension: path.extname(filename).slice(1),
    }))).filter(candidate => candidate.startsWith('tw:'));
    assert.ok(candidates.length > 500, 'Source scanning must not silently accept an empty inventory');
    const compiler = await compile('@import "tailwindcss/theme.css" prefix(tw); @import "tailwindcss/utilities.css" prefix(tw) source(none); @config "../../tailwind.config.js";', { base: path.join(root, 'resources/css'), onDependency() {} });
    const reference = compiler.build(candidates);
    const expected = candidates.filter(candidate => reference.includes(escapeClass(candidate)));
    assert.ok(expected.length > 500, 'The reference compiler must recognize the application utilities');
    const missing = expected.filter(candidate => !css.includes(escapeClass(candidate)));
    assert.deepEqual(missing, []);
});

test('the real Vite pipeline emits responsive, state, arbitrary and negative utilities', () => {
    for (const name of ['tw:hidden', 'tw:lg:col-span-8', 'tw:aria-[current=page]:bg-primary', 'tw:disabled:opacity-50', 'tw:-translate-x-full', 'tw:min-h-[40px]']) {
        assert.ok(rules.some(rule => rule.selector.includes(escapeClass(name))), 'Missing compiled selector: ' + name);
    }
    const responsive = rules.find(rule => rule.selector.includes(escapeClass('tw:lg:col-span-8')));
    assert.equal(responsive.parent.name, 'media');
    assert.match(responsive.parent.params, /64rem|1024px/);
});

test('design tokens and server-rendered action classes survive source discovery', () => {
    assert.match(css, /--md-primary:\s*#1[fF]5[fF][aA]6/);
    assert.ok(rules.some(rule => rule.selector.includes('.ui-data-action--danger')));
    assert.ok(rules.some(rule => rule.selector.includes(escapeClass('tw:bg-primary')) && rule.toString().includes('var(--md-primary)')));
    assert.doesNotMatch(css, /<alpha-value>|@tailwind\s+utilities/);
});

test('sibling spacing, dividers and static hidden priority preserve the v3 behavior', () => {
    const spacing = rules.find(rule => rule.selector.includes(escapeClass('tw:space-y-4')));
    assert.match(spacing.selector, />\s*:not\(\[hidden\]\)\s*~\s*:not\(\[hidden\]\)/);
    assert.ok(spacing.nodes.some(node => node.prop === 'margin-top' && node.value.includes('1 - var(--tw-space-y-reverse)')));
    const divider = rules.find(rule => rule.selector.includes(escapeClass('tw:divide-y')));
    assert.ok(divider.nodes.some(node => node.prop === 'border-top-width' && node.value.includes('1 - var(--tw-divide-y-reverse)')));
    const hidden = rules.findIndex(rule => rule.selector === escapeClass('tw:hidden'));
    const inlineFlex = rules.findIndex(rule => rule.selector === escapeClass('tw:inline-flex'));
    const responsive = rules.findIndex(rule => rule.selector.includes(escapeClass('tw:sm:inline')));
    assert.ok(hidden > inlineFlex && responsive > hidden, 'Static hidden must win without blocking responsive display');
});

test('Preflight and global forms resets stay absent from the compiled application', () => {
    for (const file of ['../../resources/css/app.css', '../../resources/css/auth.css']) {
        const source = readFileSync(new URL(file, import.meta.url), 'utf8');
        assert.doesNotMatch(source, /@import\s+['"]tailwindcss(?:\/preflight\.css)?['"]/);
        assert.match(source, /tailwindcss\/theme\.css/);
        assert.match(source, /tailwindcss\/utilities\.css/);
    }
    assert.equal(allRules.some(rule => rule.selector.includes('*') && rule.nodes.some(node => node.prop === 'padding' && node.value === '0')), false);
    assert.equal(allRules.some(rule => /^(input|textarea|select)(?:,|$)/.test(rule.selector) && rule.nodes.some(node => node.prop === 'appearance' && node.value === 'none')), false);
    assert.equal(allRules.some(rule => rule.nodes.some(node => node.prop?.startsWith('border') && node.prop.endsWith('-style') && node.value === 'var(--tw-border-style)')), false, 'Fallbackless var(--tw-border-style) must not be emitted without Preflight');
    const divideRule = rules.find(rule => rule.selector.includes(escapeClass('tw:divide-y')));
    assert.ok(divideRule?.nodes.some(node => node.prop === 'border-bottom-style' || node.prop === 'border-top-style' || node.prop === 'border-style'), 'tw:divide-y must carry a border-style fallback');
    assert.ok(divideRule.nodes.some(node => node.prop.endsWith('-style') && node.value === 'var(--tw-border-style, solid)'), 'Non-zero divider must use var(--tw-border-style, solid)');
    const appSource = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8').trimEnd();
    const authSource = readFileSync(new URL('../../resources/css/auth.css', import.meta.url), 'utf8');
    assert.ok(appSource.endsWith('@import "tailwindcss/utilities.css" prefix(tw) source(none);'), 'app.css must import tailwindcss/utilities.css last so utilities win over component/shell rules');
    assert.match(appSource, /@custom-variant\s+dark\s+\(&:where\(\[data-theme="dark"\],\s*\[data-theme="dark"\]\s*\*\)\);/, 'app.css must map dark variant to [data-theme="dark"]');
    assert.match(authSource, /@custom-variant\s+dark\s+\(&:where\(\[data-theme="dark"\],\s*\[data-theme="dark"\]\s*\*\)\);/, 'auth.css must map dark variant to [data-theme="dark"]');
    const darkRules = rules.filter(rule => rule.selector.includes('tw\\:dark\\:'));
    assert.ok(darkRules.every(rule => rule.selector.includes('[data-theme="dark"]') && rule.parent?.name !== 'media'), 'tw:dark:* utilities must compile to [data-theme="dark"] rather than OS prefers-color-scheme media queries');
});

test('DOM selectors target prefixed classes without treating the colon as a pseudo-class', () => {
    for (const file of ['../../resources/js/server-tabs.js', '../../resources/views/components/ui/image-lightbox.blade.php', '../../resources/views/supplier/quotations/create.blade.php']) {
        const source = readFileSync(new URL(file, import.meta.url), 'utf8');
        assert.doesNotMatch(source, /(?:querySelector|closest|find)\([^\n]*\.tw:/, file);
    }
});

test('app and auth bundles enforce [hidden] display:none !important so author display classes like .ui-spinner stay hidden', () => {
    for (const pattern of [/(?:^|\/)app-[^/]+\.css$/, /(?:^|\/)auth-[^/]+\.css$/]) {
        const cssAsset = cssAssets.find(asset => pattern.test(asset.fileName));
        assert.ok(cssAsset, `Compiled CSS asset matching ${pattern} must exist`);
        const assetAst = postcss.parse(String(cssAsset.source));
        let hasHiddenOverride = false;
        assetAst.walkRules(rule => {
            if (rule.selector.includes('[hidden]') && rule.nodes.some(node => node.prop === 'display' && node.value === 'none' && node.important)) {
                hasHiddenOverride = true;
            }
        });
        assert.equal(hasHiddenOverride, true, `${cssAsset.fileName} must enforce [hidden] { display: none !important } without Bootstrap Reboot`);
    }
});

test('table base cell selectors stay at single-class specificity so tw:text-center, tw:text-end, and tw:py-* win on empty states and aligned cells', () => {
    const uiTableCellIdx = rules.findIndex(rule => rule.selector === '.ui-table > :where(:not(caption)) > * > *');
    const dataTableCellIdx = rules.findIndex(rule => rule.selector === '.ui-data-table :where(table > :not(caption)) > * > *');
    const textCenterIdx = rules.findIndex(rule => rule.selector === escapeClass('tw:text-center'));
    const textEndIdx = rules.findIndex(rule => rule.selector === escapeClass('tw:text-end'));
    const py8Idx = rules.findIndex(rule => rule.selector === escapeClass('tw:py-8'));

    assert.ok(uiTableCellIdx >= 0, 'Compiled CSS must keep .ui-table > :where(:not(caption)) > * > *');
    assert.ok(dataTableCellIdx >= 0, 'Compiled CSS must keep .ui-data-table :where(table > :not(caption)) > * > *');
    assert.equal(rules.some(rule => /(?:^|,)\s*\.ui-table(?:--[a-z]+)?\s*>\s*:not\(caption\)/.test(rule.selector)), false, 'Raw :not(caption) on .ui-table outranks tw:* utilities');
    assert.equal(rules.some(rule => /(?:^|,)\s*\.ui-data-table\s+table\s*>\s*:not\(caption\)/.test(rule.selector)), false, 'Raw table > :not(caption) on .ui-data-table outranks tw:* utilities');
    assert.ok(textCenterIdx > uiTableCellIdx && textCenterIdx > dataTableCellIdx, 'tw:text-center must follow table base cell rules');
    assert.ok(textEndIdx > uiTableCellIdx && textEndIdx > dataTableCellIdx, 'tw:text-end must follow table base cell rules');
    assert.ok(py8Idx > uiTableCellIdx && py8Idx > dataTableCellIdx, 'tw:py-8 must follow table base cell rules');
});
