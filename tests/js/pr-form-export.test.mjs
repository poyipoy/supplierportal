import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { hasEnteredItems } from '../../resources/js/pr-form-export.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('hasEnteredItems returns false for null or empty form', () => {
    assert.equal(hasEnteredItems(null), false);

    const emptyForm = {
        querySelectorAll: () => [],
    };
    assert.equal(hasEnteredItems(emptyForm), false);
});

test('hasEnteredItems returns false when all inputs are whitespace', () => {
    const formWithBlanks = {
        querySelectorAll: () => [
            { value: '' },
            { value: '   ' },
            { value: null },
        ],
    };
    assert.equal(hasEnteredItems(formWithBlanks), false);
});

test('hasEnteredItems returns true when material name is entered', () => {
    const formWithItem = {
        querySelectorAll: () => [
            { value: '' },
            { value: 'SKD11' },
        ],
    };
    assert.equal(hasEnteredItems(formWithItem), true);
});

test('hasEnteredItems returns true when shape is selected', () => {
    const formWithShape = {
        querySelectorAll: () => [
            { value: 'Round' },
        ],
    };
    assert.equal(hasEnteredItems(formWithShape), true);
});

test('the PR form wires the Download Template item through a lazy DOM marker', () => {
    const view = read('resources/views/purchasing/pr/_import_controls.blade.php');
    assert.match(view, /data-pr-form-export/);
    assert.match(view, /route\('purchasing\.requisitions\.export-form'\)/);
    assert.match(view, /data-fallback-url="\{\{\s*route\('purchasing\.requisitions\.import-template'\)\s*\}\}"/);

    const app = read('resources/js/app.js');
    assert.match(app, /hasMarker\('\[data-pr-form-export\]'\)\) import\('\.\/pr-form-export'\)/);
    assert.doesNotMatch(app, /^import .*pr-form-export/m);
});
