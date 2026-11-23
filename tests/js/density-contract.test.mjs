import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const workspaceCss = await readFile(new URL('../../resources/css/workspace-shell.css', import.meta.url), 'utf8');
const previewBlade = await readFile(new URL('../../resources/views/profile/partials/density-preview.blade.php', import.meta.url), 'utf8');
const customizationBlade = await readFile(new URL('../../resources/views/profile/customization.blade.php', import.meta.url), 'utf8');
const levels = ['spacious', 'comfortable', 'balanced', 'compact', 'dense'];

function extractPxSteps(tokenName) {
    return levels.map((level) => {
        const block = css.match(new RegExp(`:root\\[data-density="${level}"\\]\\s*\\{([^}]+)`))?.[1];
        assert.ok(block, level);
        const rem = block.match(new RegExp(`${tokenName}:\\s*([\\d.]+)rem`))?.[1];
        assert.ok(rem, `${tokenName} in ${level}`);
        return Math.round(Number(rem) * 16 * 100) / 100;
    });
}

test('semantic table spacing has five monotonic steps with the comfortable baseline', () => {
    const values = extractPxSteps('--ui-density-table-cell-block');
    assert.deepEqual(values, [12, 10, 8, 6, 4]);
    values.slice(1).forEach((value, index) => assert.ok(values[index] - value >= 1));
});

test('table headers, row actions, status chips, sidebar headings, and workspace sessionbar scale monotonically across five density levels', () => {
    assert.deepEqual(extractPxSteps('--ui-density-table-head-block'), [9, 7, 6, 4, 3]);
    assert.deepEqual(extractPxSteps('--ui-density-table-head-min'), [38, 34, 32, 30, 28]);
    assert.deepEqual(extractPxSteps('--ui-density-table-action-min'), [36, 34, 30, 28, 24]);
    assert.deepEqual(extractPxSteps('--ui-density-table-chip-min'), [26, 24, 22, 20, 18]);
    assert.deepEqual(extractPxSteps('--ui-density-sidebar-heading-min'), [44, 42, 38, 34, 30]);
    assert.deepEqual(extractPxSteps('--ui-density-sessionbar-min'), [48, 46, 44, 42, 40]);
    assert.deepEqual(extractPxSteps('--ui-density-session-min'), [43, 41, 39, 37, 35]);

    assert.match(css, /\.table thead th\s*\{[^}]*var\(--ui-density-table-head-block,\s*0\.4375rem\)/s);
    assert.match(css, /\.ui-data-table table thead th\s*\{[^}]*var\(--ui-density-table-head-block,\s*0\.4375rem\)/s);
    assert.match(css, /--ui-density-table-action-min,\s*var\(--ui-control-height-sm\)/);
    assert.match(css, /--ui-density-table-chip-min,\s*1\.5rem/);
    assert.match(css, /\.sidebar-heading\s*\{[^}]*var\(--ui-density-sidebar-heading-min,\s*2\.625rem\)/s);
    assert.match(css, /\.ui-section\s*\{[^}]*var\(--ui-density-stack-sm/s);
    assert.match(workspaceCss, /\.workspace-sessionbar\s*\{[^}]*min-height:\s*var\(--ui-density-sessionbar-min,\s*2\.875rem\)/s);
    assert.match(workspaceCss, /\.workspace-session__trigger\s*\{[^}]*min-height:\s*var\(--ui-density-session-min,\s*2\.5625rem\)/s);
});

test('density preview frame has no hidden spacious reserve clone and renders realistic ERP operational components', () => {
    assert.equal(customizationBlade.includes('ui-density-preview__reserve'), false);
    assert.equal(customizationBlade.includes('data-density-stop='), true);
    assert.equal(customizationBlade.includes('data-density-spec'), true);
    assert.equal(previewBlade.includes('x-ui.status-chip'), true);
    assert.equal(previewBlade.includes('ui-data-action'), true);
    assert.equal(previewBlade.includes('ui-tabular-nums'), true);
});

test('density changes spacing without reducing typography or global controls', () => {
    for (const level of levels) {
        const block = css.match(new RegExp(`:root\\[data-density="${level}"\\]\\s*\\{([^}]+)`))?.[1] || '';
        assert.doesNotMatch(block, /--(?:ui-font|ui-control-height|md-)/);
    }
    assert.doesNotMatch(css, /\[data-density="compact"\] \.ui-switch/);
});
