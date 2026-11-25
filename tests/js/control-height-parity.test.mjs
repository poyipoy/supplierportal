import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

test('content-area control touch targets depend on primary pointer rather than any-pointer', () => {
    assert.doesNotMatch(css, /@media\s*\(\s*any-pointer:\s*coarse\s*\)/);
    assert.match(css, /@media\s*\(\s*pointer:\s*coarse\s*\),\s*\(\s*max-width:\s*991\.98px\s*\)\s*\{[\s\S]*?\.content-area:not\(:has\(\.ui-account-shell\)\)\s*:is\(/);
});

test('fine-pointer non-table controls match --ui-control-height-sm and --ui-control-height-md', () => {
    assert.match(css, /\.content-area\s+:is\(\.ui-input--sm,\s*\.ui-select--sm,\s*\.ui-input-group--sm\s*>\s*:is\(\.ui-input,\s*\.ui-select,\s*\.ui-button\)\):not\(:is\(table,\s*\.ui-table,\s*\.ui-export-designer-modal\)\s*\*,\s*\[class\*="tw:min-h-"\]\)\s*\{\s*min-height:\s*var\(--ui-control-height-sm\);/m);
    assert.match(css, /\.content-area\s+:is\(\.ui-input,\s*\.ui-select\):not\([\s\S]*?:is\(table,\s*\.ui-table,\s*\.ui-export-designer-modal\)\s*\*,\s*\[class\*="tw:min-h-"\]\)\s*\{\s*min-height:\s*var\(--ui-control-height-md\);/m);
    assert.match(css, /\.ui-calendar-trigger--sm,\s*\.ui-calendar--sm\s+\.ui-calendar-trigger\s*\{\s*min-height:\s*var\(--ui-control-height-sm\);\s*height:\s*var\(--ui-control-height-sm\);/m);
    assert.match(css, /(?:^|\n)\.ui-date-range-picker--compact\s+\.ui-date-range-trigger\s*\{\s*min-height:\s*var\(--ui-control-height-sm\);/m);
});

test('coarse pointer and mobile breakpoint co-scale buttons and adjacent fields to 44px', () => {
    const coarseMatch = css.match(/@media\s*\(\s*pointer:\s*coarse\s*\),\s*\(\s*max-width:\s*991\.98px\s*\)\s*\{\s*\.sidebar-menu\s+\.sidebar-link\s*\{\s*min-height:\s*44px;\s*\}([\s\S]*?)\n\s*\}\n\}/);
    assert.ok(coarseMatch, 'Expected @media (pointer: coarse), (max-width: 991.98px) content-area block');
    const block = coarseMatch[1];

    for (const expectedSelector of [
        '.ui-button',
        '.ui-data-action',
        '.ui-input--sm',
        '.ui-select--sm',
        '.ui-input-group--sm > :is(.ui-input, .ui-select, .ui-button)',
        '.ui-date-range-trigger',
        '.ui-calendar-trigger',
        '.ui-time-picker__input',
        '[role="combobox"][id$="-trigger"]',
        '.ui-searchable-free-input__input',
        ':is(table, .ui-table, .ui-export-designer-modal) *',
    ]) {
        assert.ok(block.includes(expectedSelector), `Expected coarse/mobile co-scaling rule to include ${expectedSelector}`);
    }

    assert.match(block, /\.ui-calendar-trigger:not\(:is\(table,\s*\.ui-table,\s*\.ui-export-designer-modal\)\s*\*\)\s*\{\s*height:\s*auto;\s*\}/);
});
