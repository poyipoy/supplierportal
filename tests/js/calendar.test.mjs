import test from 'node:test';
import assert from 'node:assert/strict';
import {
    addLocalDays,
    addLocalMonths,
    formatDateDisplay,
    formatMonthDisplay,
    isIsoDate,
    isIsoMonth,
    rangePresets,
} from '../../resources/js/calendar-core.js';

test('calendar core validates ISO day and month values without accepting invalid local dates', () => {
    assert.equal(isIsoDate('2026-02-28'), true);
    assert.equal(isIsoDate('2026-02-30'), false);
    assert.equal(isIsoMonth('2026-12'), true);
    assert.equal(isIsoMonth('2026-13'), false);
});

test('calendar core uses local arithmetic across a month and year boundary', () => {
    assert.equal(addLocalDays('2026-01-01', -1), '2025-12-31');
    assert.equal(addLocalMonths('2026-01', -1), '2025-12');
    assert.equal(addLocalMonths('2026-12', 1), '2027-01');
});

test('calendar core formats display values independently from submitted ISO values', () => {
    assert.match(formatDateDisplay('2026-08-14'), /14 Aug 2026/);
    assert.match(formatMonthDisplay('2026-08'), /Aug 2026/);
});

test('calendar presets remain correct across month and year boundaries', () => {
    const date = new Date(2026, 0, 2);
    const dayPresets = rangePresets('day', date);
    const monthPresets = rangePresets('month', date);

    assert.deepEqual(dayPresets.find((preset) => preset.id === 'last-7-days'), {
        id: 'last-7-days', label: 'Last 7 days', start: '2025-12-27', end: '2026-01-02',
    });
    assert.deepEqual(monthPresets.find((preset) => preset.id === 'last-3-months'), {
        id: 'last-3-months', label: 'Last 3 months', start: '2025-11', end: '2026-01',
    });
});
