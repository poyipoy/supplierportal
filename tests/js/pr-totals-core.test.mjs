import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { toScaled, quantityOf, rowTotal, grandTotals, toDecimalString } from '../../resources/js/pr-totals-core.js';

const cases = JSON.parse(readFileSync(new URL('../Fixtures/pr-totals-cases.json', import.meta.url), 'utf8'));
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('row totals match the shared PHP fixture exactly (Money::multiply at 4 dp)', () => {
    for (const { unit, quantity, total } of cases) {
        assert.equal(toDecimalString(rowTotal(quantity, unit)), total, `${unit} × ${quantity}`);
    }
});

test('blank or below-one quantities show no row total and contribute zero', () => {
    for (const quantity of ['', null, undefined, '0', '-2', 'abc', '0.9']) {
        assert.equal(quantityOf(quantity), null, String(quantity));
        assert.equal(rowTotal(quantity, '12.5'), null, String(quantity));
    }
    assert.equal(rowTotal('3', ''), null, 'a missing unit weight has no total');
    assert.equal(quantityOf('3.9'), 3, 'whole pieces like the form');
    const totals = grandTotals([
        { quantity: '3', unitKg: '12.5' },
        { quantity: '', unitKg: '99' },
        { quantity: '2', unitKg: '' },
        { quantity: '1', unitKg: '0.0001' },
    ]);
    assert.equal(totals.quantity, 6, 'a row with quantity but no unit weight still counts its pieces');
    assert.equal(toDecimalString(totals.weight), '37.5001');
});

test('parsing is exact and half-up at the fifth decimal', () => {
    assert.equal(toScaled('1.00005'), 10001n);
    assert.equal(toScaled('1.00004'), 10000n);
    assert.equal(toScaled('12,5'), 125000n);
    assert.equal(toScaled('.5'), 5000n);
    assert.equal(toScaled(''), null);
    assert.equal(toScaled('1e3'), null);
    assert.equal(toDecimalString(toScaled('12345678901234567890.1234') * 3n), '37037036703703703670.3702');
});

test('the PR form loads the totals helper statically and dispatches an explicit change event', () => {
    assert.match(read('resources/js/app.js'), /^import '\.\/pr-totals-core';$/m);
    const script = read('resources/views/purchasing/pr/_material_shape_script.blade.php');
    assert.match(script, /adasi:pr-items-changed/);
    assert.match(read('resources/views/purchasing/pr/_form_totals.blade.php'), /AdasiPrTotals/);
});
