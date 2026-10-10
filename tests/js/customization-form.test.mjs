import test from 'node:test';
import assert from 'node:assert/strict';
import { sameOrder, sameSet } from '../../resources/js/customization-form.js';

test('sameOrder requires identical length and position', () => {
    assert.equal(sameOrder(['a', 'b', 'c'], ['a', 'b', 'c']), true);
    assert.equal(sameOrder(['a', 'b', 'c'], ['a', 'c', 'b']), false);
    assert.equal(sameOrder(['a', 'b'], ['a', 'b', 'c']), false);
    assert.equal(sameOrder([], []), true);
});

test('sameSet ignores order but requires the same membership', () => {
    assert.equal(sameSet(['a', 'b', 'c'], ['c', 'a', 'b']), true);
    assert.equal(sameSet(['a', 'b'], ['a', 'b', 'c']), false);
    assert.equal(sameSet(['a', 'b'], ['a', 'c']), false);
    assert.equal(sameSet([], []), true);
});
