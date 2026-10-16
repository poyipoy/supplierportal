import test from 'node:test';
import assert from 'node:assert/strict';
import { searchableSelect } from '../../resources/js/searchable-select.js';

test('searchableSelect initializes with options and selected value', () => {
    const comp = searchableSelect({
        selectedValue: 'PR-001',
        options: [
            { value: 'PR-001', label: 'PR-001', searchKeywords: 'pr-001' },
            { value: 'PR-002', label: 'PR-002', searchKeywords: 'pr-002' },
        ],
        allowCustom: true,
    });

    assert.equal(comp.selectedValue, 'PR-001');
    assert.equal(comp.options.length, 2);
    assert.equal(comp.selectedOption.label, 'PR-001');
});

test('filteredOptions filters by query matching label or keywords', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100 vendor a' },
            { value: 'PR-200', label: 'PR-200', searchKeywords: 'pr-200 vendor b' },
        ],
    });

    comp.search = 'vendor b';
    assert.equal(comp.filteredOptions.length, 1);
    assert.equal(comp.filteredOptions[0].value, 'PR-200');

    comp.search = 'xyz';
    assert.equal(comp.filteredOptions.length, 0);
});

test('showCustomOption is true only when allowCustom is true, search is not empty, and no exact match', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
        allowCustom: true,
    });

    comp.search = '';
    assert.equal(comp.showCustomOption, false);

    comp.search = 'PR-100';
    assert.equal(comp.hasExactMatch, true);
    assert.equal(comp.showCustomOption, false);

    comp.search = 'PR-999';
    assert.equal(comp.hasExactMatch, false);
    assert.equal(comp.showCustomOption, true);
    assert.equal(comp.totalSelectableCount, 1);
});

test('selectCustom prepends new option and selects it', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
        allowCustom: true,
    });

    comp.selectCustom('PR-NEW-99');
    assert.equal(comp.selectedValue, 'PR-NEW-99');
    assert.equal(comp.options[0].value, 'PR-NEW-99');
    assert.equal(comp.open, false);
});

test('clear resets selection', () => {
    const comp = searchableSelect({
        selectedValue: 'PR-100',
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
    });

    comp.clear();
    assert.equal(comp.selectedValue, '');
    assert.equal(comp.selectedOption, null);
});
