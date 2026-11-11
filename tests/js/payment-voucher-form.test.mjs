import test from 'node:test';
import assert from 'node:assert/strict';
import {
    parseDecimalToCents,
    formatIdrCents,
    computeVoucherTotals,
    shouldRequireBankFields,
} from '../../resources/js/payment-voucher-form.js';

test('parseDecimalToCents converts valid decimal strings into integer cents safely', () => {
    assert.equal(parseDecimalToCents('10000000'), 1000000000);
    assert.equal(parseDecimalToCents('12500.75'), 1250075);
    assert.equal(parseDecimalToCents('0.10') + parseDecimalToCents('0.20'), 30);
    assert.equal(parseDecimalToCents(''), 0);
    assert.equal(parseDecimalToCents('invalid'), 0);
    assert.equal(parseDecimalToCents('-500'), 0);
});

test('computeVoucherTotals computes exact net cents without floating point drift', () => {
    const result = computeVoucherTotals({
        dpp: '10000000.10',
        ppn: '1100000.20',
        pph: '200000.05',
        bankFee: '2500.00',
    });

    assert.equal(result.dppCents, 1000000010);
    assert.equal(result.ppnCents, 110000020);
    assert.equal(result.pphCents, 20000005);
    assert.equal(result.bankFeeCents, 250000);
    assert.equal(result.netCents, 1089750025);
    assert.equal(result.isPositive, true);
    assert.equal(formatIdrCents(result.netCents), '10.897.500,25');
});

test('computeVoucherTotals flags zero or negative net totals', () => {
    const zeroResult = computeVoucherTotals({
        dpp: '100000',
        ppn: '0',
        pph: '100000',
        bankFee: '0',
    });
    assert.equal(zeroResult.netCents, 0);
    assert.equal(zeroResult.isPositive, false);
});

test('shouldRequireBankFields only returns true when paymentMethod is BANK', () => {
    assert.equal(shouldRequireBankFields('BANK'), true);
    assert.equal(shouldRequireBankFields('KAS'), false);
});

