import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

function readRepo(relativePath) {
    return readFileSync(resolve(process.cwd(), relativePath), 'utf8');
}

test('DataTables 3.x sorting styles match div.dt-column-order, enforce contrast >= 3:1, 24px targets, and focus-visible rings', () => {
    const css = readRepo('resources/css/app.css');

    assert.equal(css.includes('span.dt-column-order'), false, 'DataTables 3.x uses div.dt-column-order, not obsolete span.dt-column-order');
    assert.match(css, /table\.dataTable\s+thead\s*>\s*tr\s*>\s*:is\(th,\s*td\)\s+\.dt-column-order\s*\{[^}]*inset:\s*0;[^}]*min-inline-size:\s*24px;[^}]*min-block-size:\s*24px;/);
    assert.doesNotMatch(css, /\.dt-column-order\s*\{[^}]*pointer-events:\s*none/);
    assert.doesNotMatch(css, /\.dt-column-order::(?:before|after)\s*\{[^}]*opacity:\s*0\.55/);
    assert.doesNotMatch(css, /\.dt-column-order:focus-visible\s*\{[^}]*outline:\s*(?:none|0)/);
    assert.match(css, /\.dt-column-order:focus-visible/);
    assert.match(css, /@media\s*\(forced-colors:\s*active\)[\s\S]*?\.ui-table-sort-btn:focus-visible[\s\S]*?\.dt-column-order:focus-visible/);
    assert.match(css, /\\\.tw\\:text-end\)\.dt-orderable-asc|tw\\:text-end\):is\(\.dt-orderable-asc/);
    assert.match(css, /\.ui-table-sort-btn\s*\{[\s\S]*?text-decoration:\s*none;/);
    assert.match(css, /\.ui-table-sort-icon--neutral\s*\{[\s\S]*?color:\s*var\(--md-outline-strong\);/);
});

test('DataTables global accessibility hook handles Space key activation, aria-sort="none" cleanup, and polite live announcements', () => {
    const layout = readRepo('resources/views/layouts/app.blade.php');

    assert.match(layout, /wrapper\.querySelectorAll\('th\[aria-sort\]'\)\.forEach/);
    assert.match(layout, /th\.getAttribute\('aria-sort'\)\s*===\s*'none'/);
    assert.match(layout, /\.dt-column-order\[role="button"\]/);
    assert.match(layout, /event\.key !== ' '/);
    assert.match(layout, /data-adasi-dt-sort-status/);
    assert.match(layout, /js\.tables\.sorted_status/);
    assert.match(layout, /js\.tables\.sort_cleared/);
    assert.match(layout, /on\('init\.dt'/);
});

test('Finance DRP Supplier table sorting uses accessible .ui-table-sort-btn, neutral affordance, Label in Name, and polite live status', () => {
    const blade = readRepo('resources/views/finance/drp/supplier.blade.php');

    assert.equal(blade.includes('aria-sort="none"') || blade.includes(": 'none'"), false);
    assert.match(blade, /id="drpCandidatesSortStatus"[^>]*role="status"[^>]*aria-live="polite"/);
    assert.match(blade, /class="ui-table-sort-btn"/);
    assert.match(blade, /ui-table-sort-icon--neutral/);
    assert.match(blade, /name="arrow-up-down"/);
    assert.match(blade, /common\.table\.sort_action/);
    assert.match(blade, /adasi:drp-supplier-sort-focus/);
});
