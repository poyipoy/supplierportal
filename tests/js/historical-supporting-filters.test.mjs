import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const scriptsPath = path.resolve('resources/views/purchasing/comparison/_scripts.blade.php');
const viewPath = path.resolve('resources/views/purchasing/comparison/_historical_content.blade.php');
const scriptsSource = fs.readFileSync(scriptsPath, 'utf8');
const viewSource = fs.readFileSync(viewPath, 'utf8');

function loadSupportingFiltersHelper() {
    const start = scriptsSource.indexOf('// <historical-supporting-filters>');
    const end = scriptsSource.indexOf('// </historical-supporting-filters>');
    assert.ok(start >= 0 && end > start, 'historical-supporting-filters marker block must exist');
    const block = scriptsSource.slice(start, end);
    const sandbox = { window: {} };
    vm.runInNewContext(block, sandbox);
    return sandbox.window.AdasiHistoricalSupportingFilters;
}

const helper = loadSupportingFiltersHelper();

test('normalize deduplicates arrays, uppercases currencies, strips invalid values, and swaps inverted bounds', () => {
    const normalized = helper.normalize({
        categories: [' 1-15 ', '16-50', '1-15', ''],
        doc_search: '  REQ/11/2026  ',
        source_currencies: ['cny', 'USD', 'cny', ''],
        start_date: '2026-11-26',
        end_date: '2026-10-01',
        price_min: '80000',
        price_max: '25000',
        change_direction: 'DECREASED',
    });

    assert.deepEqual(Array.from(normalized.categories), ['1-15', '16-50']);
    assert.equal(normalized.doc_search, 'REQ/11/2026');
    assert.deepEqual(Array.from(normalized.source_currencies), ['CNY', 'USD']);
    assert.equal(normalized.start_date, '2026-10-01');
    assert.equal(normalized.end_date, '2026-11-26');
    assert.equal(normalized.price_min, '25000');
    assert.equal(normalized.price_max, '80000');
    assert.equal(normalized.change_direction, 'decreased');

    const invalid = helper.normalize({
        start_date: 'not-a-date',
        price_min: '-5',
        change_direction: 'bogus',
    });
    assert.equal(invalid.start_date, null);
    assert.equal(invalid.price_min, null);
    assert.equal(invalid.change_direction, null);
});

test('countActive, buildChips, clearKey, and appendToParams stay consistent', () => {
    const state = helper.normalize({
        categories: ['16-50'],
        doc_search: 'REQ/11',
        source_currencies: ['CNY', 'USD'],
        start_date: '2026-10-01',
        end_date: '2026-11-26',
        price_min: '20000',
        price_max: '60000',
        change_direction: 'increased',
    });

    assert.equal(helper.countActive(state), 6);

    const chips = helper.buildChips(state, {
        chips: {
            categories: 'Category',
            doc_search: 'PR / PO',
            source_currencies: 'Currency',
            date_range: 'PO Date',
            price_range: 'Price/kg',
            change_direction: 'Change',
        },
        categoryLabels: { '16-50': '16-50 (16-50 mm)' },
        changeLabels: { increased: 'Increased' },
        currency: 'IDR',
    });

    assert.equal(chips.length, 6);
    assert.deepEqual(Array.from(chips, (c) => c.key), [
        'categories',
        'doc_search',
        'source_currencies',
        'date_range',
        'price_range',
        'change_direction',
    ]);
    assert.equal(chips[0].value, '16-50 (16-50 mm)');
    assert.equal(chips[4].value, 'IDR 20000 - 60000');

    // clearKey is immutable
    const withoutDate = helper.clearKey(state, 'date_range');
    assert.equal(state.start_date, '2026-10-01');
    assert.equal(withoutDate.start_date, null);
    assert.equal(withoutDate.end_date, null);
    assert.equal(helper.countActive(withoutDate), 5);

    const params = new URLSearchParams();
    helper.appendToParams(params, withoutDate);
    assert.deepEqual(params.getAll('categories[]'), ['16-50']);
    assert.equal(params.get('doc_search'), 'REQ/11');
    assert.deepEqual(params.getAll('source_currencies[]'), ['CNY', 'USD']);
    assert.equal(params.get('start_date'), null);
    assert.equal(params.get('price_min'), '20000');
    assert.equal(params.get('price_max'), '60000');
    assert.equal(params.get('change_direction'), 'increased');
});

test('hasOpenInnerPopover detects visible multi-select popover inside native dialog', () => {
    const hiddenDialog = {
        querySelectorAll: () => [
            { hidden: true, style: { display: 'none' }, offsetParent: null },
        ],
    };
    const openDialog = {
        querySelectorAll: () => [
            { hidden: false, style: { display: '' }, offsetParent: {} },
        ],
    };

    assert.equal(helper.hasOpenInnerPopover(hiddenDialog), false);
    assert.equal(helper.hasOpenInnerPopover(openDialog), true);
});

test('Supporting Data Filter button, chips strip, and modal are mounted with anti-trap placement', () => {
    assert.match(viewSource, /id="historicalSupportingFilterBtn"[\s\S]*data-ui-modal-open="#historicalSupportingFilterModal"/);
    assert.match(viewSource, /id="historicalSupportingFilterCount"/);
    assert.match(viewSource, /id="historicalSupportingFilterChips"/);
    assert.match(viewSource, /id="historicalNoDataAdjustFiltersBtn"[\s\S]*data-ui-modal-open="#historicalSupportingFilterModal"/);
    assert.match(viewSource, /id="historicalNoDataResetFiltersBtn"/);

    // Filter button sits before Advanced Export inside Supporting Data actions
    const filterBtnIdx = viewSource.indexOf('id="historicalSupportingFilterBtn"');
    const exportIdx = viewSource.indexOf('export-key="purchasing.price-history"');
    assert.ok(filterBtnIdx > 0 && exportIdx > filterBtnIdx, 'Filter button must appear to the left of Advanced Export');

    // Modal is mounted outside #historicalDataWrapper so Adjust Filters works even on 0 results
    const noDataIdx = viewSource.indexOf('id="historicalNoDataAlert"');
    const modalIdx = viewSource.indexOf('id="historicalSupportingFilterModal"');
    assert.ok(modalIdx > noDataIdx, 'Filter modal dialog must be mounted outside #historicalDataWrapper');
    assert.match(viewSource, /id="historicalSupportingFilterModal"[\s\S]*class="btn-close" data-ui-modal-close[\s\S]*<x-ui\.button type="button" variant="ghost" size="sm" data-ui-modal-close>\{\{ __\('common\.actions\.cancel'\) \}\}<\/x-ui\.button>/);

    // Checked segmented radio controls must retain primary hover contrast instead of falling back to surface-container
    assert.match(viewSource, /tw:peer-checked:bg-primary[\s\S]*?tw:peer-checked:hover:bg-\[var\(--ui-primary-hover\)\]/);

    // Monthly Supporting Data table provides semantic caption and accessible sortable PO Date button with invoker command + CSS-driven icon
    assert.match(viewSource, /<table id="historicalSupportingTable"/);
    assert.match(viewSource, /<caption class="tw:sr-only">/);
    assert.match(viewSource, /<th scope="col" aria-sort="\{\{\s*\$poDateAriaSort\s*\}\}" class="tw:text-start tw:whitespace-nowrap">[\s\S]*?<button[\s\S]*?type="button"[\s\S]*?id="historicalSortPoDateBtn"[\s\S]*?class="ui-table-sort-btn"[\s\S]*?commandfor="historicalSupportingTable"[\s\S]*?command="--sort-po-date"[\s\S]*?purchasing\.copy\.po_date/);
    assert.match(scriptsSource, /id="historicalSortPoDateBtn" class="ui-table-sort-btn" commandfor="historicalSupportingTable" command="--sort-po-date"/);
    assert.match(scriptsSource, /if \(!\('commandForElement' in HTMLButtonElement\.prototype\)\)/);

    // Sticky scrolled table sorting must reorder synchronously without ViewTransition top-layer overlays
    const cssSource = fs.readFileSync(path.resolve('resources/css/app.css'), 'utf8');
    assert.equal(scriptsSource.includes('startViewTransition'), false, 'scrolled supporting table sort must not invoke startViewTransition');
    assert.equal(scriptsSource.includes('ui-table--sorting'), false, 'scrolled supporting table sort must not toggle .ui-table--sorting');
    assert.equal(cssSource.includes('view-transition-name: match-element'), false, 'table rows must not promote into top-layer view transitions over sticky headers');
});

test('AdasiHistoricalPoDateSort toggles asc/desc, maps aria-sort, checks single-page reorder, and moves existing rows via moveBefore/append', () => {
    const start = scriptsSource.indexOf('// <historical-po-date-sort>');
    const end = scriptsSource.indexOf('// </historical-po-date-sort>');
    assert.ok(start >= 0 && end > start, 'historical-po-date-sort marker block must exist');
    const sandbox = { window: {} };
    vm.runInNewContext(scriptsSource.slice(start, end), sandbox);
    const sortHelper = sandbox.window.AdasiHistoricalPoDateSort;

    assert.equal(sortHelper.normalizeDir(undefined), 'desc');
    assert.equal(sortHelper.normalizeDir('ASC'), 'asc');
    assert.equal(sortHelper.normalizeDir('invalid'), 'desc');
    assert.equal(sortHelper.toggleDir('desc'), 'asc');
    assert.equal(sortHelper.toggleDir('asc'), 'desc');
    assert.equal(sortHelper.ariaSortFor('desc'), 'descending');
    assert.equal(sortHelper.ariaSortFor('asc'), 'ascending');

    assert.equal(sortHelper.canReorderClientSide({ last_page: 1, total: 4 }, 4), true);
    assert.equal(sortHelper.canReorderClientSide({ last_page: 2, total: 51 }, 50), false);
    assert.equal(sortHelper.canReorderClientSide({ last_page: 1, total: 1 }, 1), false);

    // Prefers moveBefore when available
    const moved = [];
    const tbodyMoveBefore = { moveBefore: (node, ref) => moved.push([node, ref]) };
    sortHelper.reorderRows(tbodyMoveBefore, ['row3', 'row2', 'row1']);
    assert.deepEqual(moved, [['row3', null], ['row2', null], ['row1', null]]);

    // Falls back to append when moveBefore is absent
    const appended = [];
    const tbodyAppend = { append: (node) => appended.push(node) };
    sortHelper.reorderRows(tbodyAppend, ['row3', 'row2', 'row1']);
    assert.deepEqual(appended, ['row3', 'row2', 'row1']);

    const msg = sortHelper.formatAnnouncement('Sorted by :column, :direction, :count rows.', {
        column: 'PO Date',
        directionLabel: 'Oldest first',
        count: 8,
    });
    assert.equal(msg, 'Sorted by PO Date, Oldest first, 8 rows.');
});
