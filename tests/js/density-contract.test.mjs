import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const levels = ['spacious', 'comfortable', 'balanced', 'compact', 'dense'];
test('semantic table spacing has five monotonic steps with the comfortable baseline', () => {
    const values = levels.map(level => {
        const block = css.match(new RegExp(`:root\\[data-density="${level}"\\]\\s*\\{([^}]+)`))?.[1];
        assert.ok(block, level);
        return Number(block.match(/--ui-density-table-cell-block:\s*([\d.]+)rem/)?.[1]) * 16;
    });
    assert.deepEqual(values, [12, 10, 8, 6, 4]);
    values.slice(1).forEach((value, index) => assert.ok(values[index] - value >= 1));
});
test('density changes spacing without reducing typography or global controls', () => {
    for (const level of levels) {
        const block = css.match(new RegExp(`:root\\[data-density="${level}"\\]\\s*\\{([^}]+)`))?.[1] || '';
        assert.doesNotMatch(block, /--(?:ui-font|ui-control-height|md-)/);
    }
    assert.doesNotMatch(css, /\[data-density="compact"\] \.ui-switch/);
});
