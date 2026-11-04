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
