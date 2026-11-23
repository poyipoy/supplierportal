import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const css = readFileSync(path.join(root, 'resources/css/app.css'), 'utf8');
const blade = readFileSync(path.join(root, 'resources/views/local-supplier/supplier-audits/edit.blade.php'), 'utf8');

const ACCENTS = ['brand', 'slate', 'indigo', 'teal', 'violet'];

const channel = (value) => [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16) / 255);
const linear = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const luminance = (value) => channel(value).map(linear).reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

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

test('Supplier Audit Yes/No token contrast passes WCAG 2.2 AA across all 5 accents x 2 modes', () => {
    for (const mode of ['light', 'dark']) {
        for (const accent of ACCENTS) {
            const t = themeResolver(mode, accent);
            const label = `${mode}/${accent}`;

            const noFillToken = mode === 'dark' ? '--md-surface-container-highest' : '--md-surface';
            const noBorderToken = mode === 'dark' ? '--md-secondary' : '--md-outline-strong';

            // 1. Track border vs card surface (>= 3.0)
            assert.ok(contrast(t('--ui-input-border'), t('--md-surface')) >= 3.0, `${label} track border vs surface`);
            // 2. Track border vs focused row surface (>= 3.0)
            assert.ok(contrast(t('--ui-input-border'), t('--md-surface-container')) >= 3.0, `${label} track border vs focus row`);
            // 3. Unselected text vs track (>= 4.5)
            assert.ok(contrast(t('--md-on-surface-variant'), t('--md-surface-container')) >= 4.5, `${label} unselected text vs track`);
            // 4. Yes active text/icon vs Yes fill (>= 4.5)
            assert.ok(contrast(t('--md-on-success-container'), t('--md-success-container')) >= 4.5, `${label} Yes active text vs fill`);
            // 5. Yes active border vs track (>= 3.0)
            assert.ok(contrast(t('--ui-success-border'), t('--md-surface-container')) >= 3.0, `${label} Yes border vs track`);
            // 6. Yes active border vs Yes fill (>= 3.0)
            assert.ok(contrast(t('--ui-success-border'), t('--md-success-container')) >= 3.0, `${label} Yes border vs fill`);
            // 7. No active text/icon vs No fill (>= 4.5)
            assert.ok(contrast(t('--md-on-surface'), t(noFillToken)) >= 4.5, `${label} No active text vs fill`);
            // 8. No active border vs track (>= 3.0)
            assert.ok(contrast(t(noBorderToken), t('--md-surface-container')) >= 3.0, `${label} No border vs track`);
            // 9. No active border vs No fill (>= 3.0)
            assert.ok(contrast(t(noBorderToken), t(noFillToken)) >= 3.0, `${label} No border vs fill`);
            // 10. Invalid border vs error row (>= 3.0)
            assert.ok(contrast(t('--md-error'), t('--md-error-container')) >= 3.0, `${label} error border vs error row`);
            // 11. Invalid border vs track (>= 3.0)
            assert.ok(contrast(t('--md-error'), t('--md-surface-container')) >= 3.0, `${label} error border vs track`);
        }
    }
});

test('Supplier Audit Yes/No CSS lives before Tailwind utilities and enforces motion, touch, and forced-colors guards', () => {
    const utilitiesIdx = css.indexOf('@import "tailwindcss/utilities.css"');
    const componentStart = css.indexOf('/* Supplier Audit: Yes/No segmented control');
    const componentEnd = css.indexOf('.ui-swatch-option {', componentStart);

    assert.ok(utilitiesIdx > 0, 'Expected Tailwind utilities import');
    assert.ok(componentStart > 0 && componentEnd > componentStart, 'Expected Supplier Audit Yes/No CSS section');
    assert.ok(componentEnd < utilitiesIdx, '.ui-audit-* rules must precede @import "tailwindcss/utilities.css"');

    const section = css.slice(componentStart, componentEnd);
    assert.match(section, /\bscale:\s*1;/);
    assert.match(section, /@starting-style\s*\{/);
    assert.match(section, /text-box:\s*trim-both cap alphabetic;/);
    assert.match(section, /@media\s*\(prefers-reduced-motion:\s*no-preference\)/);
    assert.match(section, /@media\s*\(pointer:\s*coarse\)/);
    assert.match(section, /min-block-size:\s*2\.75rem;/);
    assert.match(section, /@media\s*\(forced-colors:\s*active\)/);
    assert.match(section, /@supports\s*\(container-type:\s*inline-size\)/);
    assert.match(section, /@container\s+audit-card\b/);
    assert.match(section, /cursor:\s*pointer;/);
    assert.match(section, /cursor:\s*not-allowed;/);
    assert.doesNotMatch(section, /transition:\s*all\b/i);
    assert.doesNotMatch(section, /#[0-9a-fA-F]{3,8}\b/, 'No raw hex values allowed in component rules');
});

test('Supplier Audit edit view uses accessible Yes/No segmented control without Bootstrap success/secondary buttons', () => {
    assert.match(blade, /\bui-audit-list-card\b/);
    assert.match(blade, /\bui-audit-row\b/);
    assert.match(blade, /\bui-audit-controls\b/);
    assert.match(blade, /\bui-audit-controls__score\b/);
    assert.match(blade, /class="ui-audit-yn"[^>]*role="radiogroup"/);
    assert.match(blade, /aria-invalid="false"/);
    assert.match(blade, /:aria-invalid="/);
    assert.match(blade, /:aria-describedby="/);
    assert.match(blade, /name="answers\[\{\{\s*\$cid\s*\}\}\]\[answer\]"/);
    assert.match(blade, /for="a\{\{\s*\$cid\s*\}\}-yes"/);
    assert.match(blade, /for="a\{\{\s*\$cid\s*\}\}-no"/);
    assert.match(blade, /<x-ui\.icon\s+name="check"\s+size="xs"/);
    assert.match(blade, /<x-ui\.icon\s+name="x"\s+size="xs"/);
    assert.doesNotMatch(blade, /\bbtn-outline-success\b/);
    assert.doesNotMatch(blade, /\bbtn-outline-secondary\b/);
});

