import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Grey text and control boundaries (DESIGN.md §9): text never goes lighter than on-surface-variant
// (WCAG 1.4.3, 4.5:1) and field borders use --ui-input-border (WCAG 1.4.11, 3:1), in light and dark.

const root = fileURLToPath(new URL('../..', import.meta.url));
const css = readFileSync(path.join(root, 'resources/css/app.css'), 'utf8');
const darkStart = css.indexOf(':root[data-theme="dark"] {');
const lightBlock = css.slice(css.indexOf(':root {'), darkStart);
const darkBlock = css.slice(darkStart, css.indexOf('}', darkStart));
const hex = (block, name) => block.match(new RegExp(name.replace(/[-]/g, '\\-') + ':\\s*(#[0-9a-fA-F]{6});'))?.[1];
const tokens = (block) => (name) => hex(block, name) || hex(lightBlock, name);

const channel = (value) => [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16) / 255);
const linear = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const luminance = (value) => channel(value).map(linear).reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

const SURFACES = ['--md-surface', '--md-background', '--md-surface-container-low', '--md-surface-container', '--md-surface-container-high', '--ui-workspace-bg'];
const BUTTON_SURFACES = ['--md-surface', '--ui-workspace-bg', '--md-surface-container'];
const ACCENTS = ['brand', 'slate', 'indigo', 'teal', 'violet'];

const selectorBlock = (selector) => {
    const start = css.indexOf(`${selector} {`);
    assert.ok(start !== -1, `Missing CSS block for ${selector}`);
    const end = css.indexOf('}', start);
    assert.ok(end !== -1, `Unclosed CSS block for ${selector}`);
    return css.slice(start, end + 1);
};

const rawDecl = (block, name) => {
    const escaped = name.replace(/[-]/g, '\\-');
    return block.match(new RegExp(`${escaped}:\\s*([^;]+);`))?.[1]?.trim() ?? null;
};

const themeResolver = (mode, accent) => {
    const layers = [selectorBlock(':root')];
    if (mode === 'dark') layers.push(selectorBlock(':root[data-theme="dark"]'));
    if (accent !== 'brand') layers.push(selectorBlock(`:root[data-accent="${accent}"]`));
    if (mode === 'dark' && accent !== 'brand') layers.push(selectorBlock(`:root[data-theme="dark"][data-accent="${accent}"]`));

    const lookup = (name, depth = 0) => {
        assert.ok(depth < 8, `Cyclic CSS variable lookup for ${name}`);
        let raw = null;
        for (const layer of layers) {
            const candidate = rawDecl(layer, name);
            if (candidate !== null) raw = candidate;
        }
        assert.ok(raw !== null, `Missing token ${name} for ${mode}/${accent}`);
        if (/^#[0-9a-fA-F]{6}$/.test(raw)) return raw;
        const varMatch = raw.match(/^var\((--[a-z0-9-]+)\)$/i);
        if (varMatch) return lookup(varMatch[1], depth + 1);
        assert.fail(`Unsupported CSS value "${raw}" for ${name} in ${mode}/${accent}`);
    };
    return lookup;
};

test('secondary text stays at 4.5:1 on every surface it sits on, light and dark', () => {
    for (const [mode, block] of [['light', lightBlock], ['dark', darkBlock]]) {
        const token = tokens(block);
        const text = token('--md-on-surface-variant');
        for (const surface of SURFACES) {
            const ratio = contrast(text, token(surface));
            assert.ok(ratio >= 4.5, `${mode} on-surface-variant ${text} on ${surface} ${token(surface)} is ${ratio.toFixed(2)}:1`);
        }
    }
});

test('all action button and icon-button variants pass WCAG 2.2 AA across all 10 themes and 3 surfaces', () => {
    for (const mode of ['light', 'dark']) {
        for (const accent of ACCENTS) {
            const t = themeResolver(mode, accent);
            const label = `${mode}/${accent}`;

            // Primary solid fill
            const primaryRatio = contrast(t('--md-on-primary'), t('--md-primary'));
            assert.ok(primaryRatio >= 4.5, `${label} primary text contrast is ${primaryRatio.toFixed(2)}:1`);

            // Outline / .ui-data-action: --md-on-surface text + --ui-input-border (--md-outline-strong) on --md-surface
            const outlineTextRatio = contrast(t('--md-on-surface'), t('--md-surface'));
            const outlineHoverRatio = contrast(t('--md-on-surface'), t('--md-surface-container'));
            assert.ok(outlineTextRatio >= 4.5, `${label} outline text is ${outlineTextRatio.toFixed(2)}:1`);
            assert.ok(outlineHoverRatio >= 4.5, `${label} outline hover text is ${outlineHoverRatio.toFixed(2)}:1`);

            // Outline-Primary / .ui-data-action--primary: --ui-primary-text on --md-surface + container hover
            const outlinePrimaryTextRatio = contrast(t('--ui-primary-text'), t('--md-surface'));
            const outlinePrimaryHoverRatio = contrast(t('--md-on-primary-container'), t('--md-primary-container'));
            assert.ok(outlinePrimaryTextRatio >= 4.5, `${label} outline-primary text on surface is ${outlinePrimaryTextRatio.toFixed(2)}:1`);
            assert.ok(outlinePrimaryHoverRatio >= 4.5, `${label} outline-primary hover text is ${outlinePrimaryHoverRatio.toFixed(2)}:1`);

            // Danger-Outline / .ui-data-action--danger: --md-error on --md-surface + container hover
            const dangerTextRatio = contrast(t('--md-error'), t('--md-surface'));
            const dangerHoverRatio = contrast(t('--md-on-error-container'), t('--md-error-container'));
            assert.ok(dangerTextRatio >= 4.5, `${label} danger-outline text on surface is ${dangerTextRatio.toFixed(2)}:1`);
            assert.ok(dangerHoverRatio >= 4.5, `${label} danger-outline hover text is ${dangerHoverRatio.toFixed(2)}:1`);

            // Warning-Outline & Success-Outline (.ui-data-action--* & icon-button): container-foreground on surface + container
            const warnTextRatio = contrast(t('--md-on-warning-container'), t('--md-surface'));
            const warnHoverRatio = contrast(t('--md-on-warning-container'), t('--md-warning-container'));
            assert.ok(warnTextRatio >= 4.5, `${label} warning-outline text on surface is ${warnTextRatio.toFixed(2)}:1`);
            assert.ok(warnHoverRatio >= 4.5, `${label} warning-outline hover text is ${warnHoverRatio.toFixed(2)}:1`);

            const okTextRatio = contrast(t('--md-on-success-container'), t('--md-surface'));
            const okHoverRatio = contrast(t('--md-on-success-container'), t('--md-success-container'));
            assert.ok(okTextRatio >= 4.5, `${label} success-outline text on surface is ${okTextRatio.toFixed(2)}:1`);
            assert.ok(okHoverRatio >= 4.5, `${label} success-outline hover text is ${okHoverRatio.toFixed(2)}:1`);

            // Borders (>= 3.0:1) and Ghost text (>= 4.5:1) across all 3 surfaces
            for (const surfaceKey of BUTTON_SURFACES) {
                const bg = t(surfaceKey);

                const outlineBorderRatio = contrast(t('--ui-input-border'), bg);
                assert.ok(outlineBorderRatio >= 3.0, `${label} outline border on ${surfaceKey} is ${outlineBorderRatio.toFixed(2)}:1`);

                const primaryBorderRatio = contrast(t('--ui-primary-text'), bg);
                assert.ok(primaryBorderRatio >= 3.0, `${label} outline-primary border on ${surfaceKey} is ${primaryBorderRatio.toFixed(2)}:1`);

                const dangerBorderRatio = contrast(t('--md-error'), bg);
                assert.ok(dangerBorderRatio >= 3.0, `${label} danger-outline border on ${surfaceKey} is ${dangerBorderRatio.toFixed(2)}:1`);

                const warnBorderRatio = contrast(t('--md-on-warning-container'), bg);
                assert.ok(warnBorderRatio >= 3.0, `${label} warning-outline border on ${surfaceKey} is ${warnBorderRatio.toFixed(2)}:1`);

                const okBorderRatio = contrast(t('--md-on-success-container'), bg);
                assert.ok(okBorderRatio >= 3.0, `${label} success-outline border on ${surfaceKey} is ${okBorderRatio.toFixed(2)}:1`);

                const ghostTextRatio = contrast(t('--md-on-surface-variant'), bg);
                assert.ok(ghostTextRatio >= 4.5, `${label} ghost text on ${surfaceKey} is ${ghostTextRatio.toFixed(2)}:1`);
            }
        }
    }

    assert.match(css, /\.ui-data-action\s*\{[^}]*background-color:\s*var\(--md-surface\);/s);
    assert.match(css, /\.ui-data-action\s*\{[^}]*border:\s*1px solid var\(--md-outline-strong\);/s);
    assert.match(css, /\.ui-data-action--primary\s*\{[^}]*background-color:\s*var\(--md-surface\);[^}]*border-color:\s*var\(--ui-primary-text\);[^}]*color:\s*var\(--ui-primary-text\);/s);
    assert.match(css, /:root\[data-theme="dark"\]\s*:is\(\.ui-button,\s*\.ui-icon-button\)\.tw\\:hover\\:text-error-container-foreground:hover\s*\{\s*color:\s*var\(--md-on-error-container\)\s*!important;/s);
});

test('row-action CSS ordering and 5-accent x 2-mode contrast guarantees hold', () => {
    const utilitiesImportIndex = css.indexOf('@import "tailwindcss/utilities.css"');
    const rowActionIndex = css.search(/\.ui-data-action\s*\{/);

    assert.ok(utilitiesImportIndex > 0, 'Expected @import "tailwindcss/utilities.css" in app.css');
    assert.ok(rowActionIndex > 0, 'Expected .ui-data-action rule in app.css');
    assert.ok(
        rowActionIndex < utilitiesImportIndex,
        '.ui-data-action must be declared before @import "tailwindcss/utilities.css" so utilities override base styles cleanly'
    );

    assert.match(css, /\.ui-data-action\s*\{[^}]*border:\s*1px solid\b/s);
    assert.doesNotMatch(css, /:root\[data-theme="dark"\]\s*\.ui-data-action--primary\s*\{/s);

    for (const mode of ['light', 'dark']) {
        for (const accent of ACCENTS) {
            const t = themeResolver(mode, accent);
            const primaryTextRatio = contrast(t('--ui-primary-text'), t('--md-surface'));
            const outlineSurfaceRatio = contrast(t('--md-outline-strong'), t('--md-surface'));
            const outlineContainerRatio = contrast(t('--md-outline-strong'), t('--md-surface-container'));

            assert.ok(
                primaryTextRatio >= 4.5,
                `[${mode}/${accent}] --ui-primary-text on --md-surface contrast ${primaryTextRatio.toFixed(2)} < 4.5`
            );
            assert.ok(
                outlineSurfaceRatio >= 3.0,
                `[${mode}/${accent}] --md-outline-strong on --md-surface contrast ${outlineSurfaceRatio.toFixed(2)} < 3.0`
            );
            assert.ok(
                outlineContainerRatio >= 3.0,
                `[${mode}/${accent}] --md-outline-strong on --md-surface-container contrast ${outlineContainerRatio.toFixed(2)} < 3.0`
            );
        }
    }
});

test('Infor LN data-name column tokens pass WCAG AA across 5 accents x 2 modes and preserve CSS invariants', () => {
    const utilitiesImportIndex = css.indexOf('@import "tailwindcss/utilities.css"');
    const colNameIndex = css.search(/:is\(td,\s*th\)\.ui-col-data-name\b/);
    assert.ok(utilitiesImportIndex > 0, 'Expected @import "tailwindcss/utilities.css" in app.css');
    assert.ok(colNameIndex > 0 && colNameIndex < utilitiesImportIndex, '.ui-col-data-name rules must be declared before @import "tailwindcss/utilities.css"');

    for (const tokenName of ['--ui-table-row-stripe-bg', '--ui-table-col-name-bg', '--ui-table-row-hover-bg']) {
        assert.ok(hex(lightBlock, tokenName), `Expected 6-digit hex for ${tokenName} in :root`);
        assert.ok(hex(darkBlock, tokenName), `Expected 6-digit hex for ${tokenName} in :root[data-theme="dark"]`);
    }

    assert.match(css, /:where\(\.ui-data-table table,\s*\.table\):where\(:not\(\.ui-quotation-readonly\):not\(\.table-striped\):not\(:has\(\.ui-col-data-name\)\)\)\s*>\s*tbody\s*>\s*tr:where\(:not\(:has\(>\s*td\[colspan\]:only-child\)\):not\(:has\(>\s*td\.dataTables_empty\)\):not\(\.bg-primary-subtle\):not\(\.table-primary\):not\(\.table-light\)\):nth-of-type\(even\)\s*>\s*:is\(td,\s*th\)/);
    assert.match(css, /:where\(\.ui-data-table table,\s*\.table\)\s*>\s*tbody\s*>\s*tr:where\(:not\(:has\(>\s*td\[colspan\]:only-child\)\):not\(:has\(>\s*td\.dataTables_empty\)\):not\(\.bg-primary-subtle\):not\(\.table-primary\):not\(\.table-light\)\)\s*>\s*:is\(td,\s*th\)\.ui-col-data-name/);
    assert.match(css, /\.ui-table-sticky \.ui-sticky-col\s*\{[^}]*background-color:\s*var\(--ui-table-cell-bg,\s*var\(--md-surface\)\);/s);
    assert.match(css, /\.ui-table-sticky-col\s*\{[^}]*background-color:\s*var\(--ui-table-cell-bg,\s*var\(--md-surface\)\);/s);
    assert.match(css, /\.ui-sticky-col-right\s*\{[^}]*background-color:\s*var\(--ui-table-cell-bg,\s*var\(--md-surface\)\);/s);
    assert.match(css, /:is\(\.pr-items-table,\s*\.quotation-items-table,\s*\.qc-dimension-grid,\s*\[data-annual-rates-matrix\],\s*\.ui-export-designer-modal,\s*\.table-borderless,\s*\.local-import-modal\)\s*\{[^}]*--ui-table-row-stripe-bg:\s*var\(--md-surface\);[^}]*--ui-table-col-name-bg:\s*var\(--md-surface\);/s);
    assert.match(css, /@media print\s*\{[^}]*--ui-table-row-stripe-bg:\s*var\(--md-surface\);[^}]*--ui-table-col-name-bg:\s*var\(--md-surface\);/s);

    const fgTokens = ['--md-on-surface', '--md-on-surface-variant', '--ui-primary-text', '--md-error', '--md-on-warning-container', '--md-on-success-container'];
    for (const mode of ['light', 'dark']) {
        for (const accent of ACCENTS) {
            const t = themeResolver(mode, accent);
            for (const bgToken of ['--ui-table-row-stripe-bg', '--ui-table-col-name-bg', '--ui-table-row-hover-bg']) {
                const bg = t(bgToken);
                for (const fgToken of fgTokens) {
                    const ratio = contrast(t(fgToken), bg);
                    assert.ok(ratio >= 4.5, `[${mode}/${accent}] ${fgToken} on ${bgToken} contrast ${ratio.toFixed(2)} < 4.5`);
                }
                const borderRatio = contrast(t('--md-outline-strong'), bg);
                assert.ok(borderRatio >= 3.0, `[${mode}/${accent}] --md-outline-strong on ${bgToken} contrast ${borderRatio.toFixed(2)} < 3.0`);
            }
        }
    }
});

test('control boundaries reach 3:1 and every field border uses the input token', () => {
    for (const [mode, block] of [['light', lightBlock], ['dark', darkBlock]]) {
        const token = tokens(block);
        const border = token('--md-outline-strong');
        for (const surface of ['--md-surface', '--ui-workspace-bg', '--md-surface-container-low']) {
            const ratio = contrast(border, token(surface));
            assert.ok(ratio >= 3, `${mode} outline-strong ${border} on ${surface} is ${ratio.toFixed(2)}:1`);
        }
        assert.match(block, /--ui-input-border:\s*var\(--md-outline-strong\);/, mode + ' input border token');
    }
    const rule = css.match(/\.form-control,\s*\.form-select,\s*\.form-check-input,\s*\.input-group-text\s*\{\s*border-color:\s*var\(--ui-input-border\);/);
    assert.ok(rule, 'Bootstrap fields use --ui-input-border instead of the --bs-border-color hairline');
    assert.ok(rule.index < css.indexOf('@import "tailwindcss/utilities.css"'), 'declared before utilities so tw:border-* classes still win');
    assert.match(css, /\n\.btn-outline-secondary \{\s*--bs-btn-color: var\(--md-on-surface-variant\);/, 'light outline-secondary text uses the secondary text token');
});

test('templates never color text with outline tokens or fade it with opacity', () => {
    const files = (directory) => readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const filename = path.join(directory, entry.name);
        return entry.isDirectory() ? files(filename) : /\.(php|js|mjs)$/.test(entry.name) ? [filename] : [];
    });
    const offenders = [];
    for (const directory of ['resources/views', 'resources/js', 'public/assets/js', 'app']) {
        for (const filename of files(path.join(root, directory))) {
            const relative = path.relative(root, filename).split(path.sep).join('/');
            if (relative === 'resources/views/welcome.blade.php' || relative.startsWith('resources/views/pdf/')) continue;
            const source = readFileSync(filename, 'utf8');
            for (const match of source.matchAll(/\btext-outline(?:-[a-z]+)?(?:\/\d+)?\b|\btext-on-surface(?:-variant)?\/\d+/g)) {
                const tagStart = source.lastIndexOf('<', match.index);
                const tagEnd = source.indexOf('>', match.index);
                const tag = source.slice(tagStart, tagEnd + 1);
                if (tag.startsWith('<x-ui.icon')) continue;
                if (/class="vr\b/.test(tag)) continue;
                if (/input-group-text/.test(tag)) {
                    const content = source.slice(tagEnd + 1, source.indexOf('</span>', tagEnd));
                    if (/^\s*<x-ui\.icon\b[^>]*\/>\s*$/.test(content)) continue;
                }
                const line = source.slice(0, match.index).split('\n').length;
                offenders.push(`${relative}:${line} ${match[0]}`);
            }
        }
    }
    assert.deepEqual(offenders, []);
});
