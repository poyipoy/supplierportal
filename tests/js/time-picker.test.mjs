import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { formatTime, nowInZone, parseTime } from '../../resources/js/time-picker.js';

test('typed times are parsed in 24-hour form', () => {
    assert.deepEqual(parseTime('09:30'), { hour: 9, minute: 30 });
    assert.deepEqual(parseTime('9:05'), { hour: 9, minute: 5 });
    assert.deepEqual(parseTime('17.45'), { hour: 17, minute: 45 });
    assert.deepEqual(parseTime('930'), { hour: 9, minute: 30 });
    assert.deepEqual(parseTime('2359'), { hour: 23, minute: 59 });
    assert.deepEqual(parseTime('7'), { hour: 7, minute: 0 });
    assert.deepEqual(parseTime(' 08:00 '), { hour: 8, minute: 0 });
});

test('invalid or out-of-range text is rejected instead of guessed', () => {
    for (const value of ['', '24:00', '12:60', '1:2', 'ab:cd', '12345', '9:30 pm', '-1:00']) {
        assert.equal(parseTime(value), null, value);
    }
});

test('times are formatted with leading zeros', () => {
    assert.equal(formatTime({ hour: 7, minute: 5 }), '07:05');
    assert.equal(formatTime({ hour: 23, minute: 59 }), '23:59');
});

test('now uses the business timezone and floors minutes to the step', () => {
    const instant = new Date('2026-11-06T02:07:40Z');
    assert.deepEqual(nowInZone('Asia/Jakarta', 5, instant), { hour: 9, minute: 5 });
    assert.deepEqual(nowInZone('Asia/Jakarta', 15, instant), { hour: 9, minute: 0 });
    assert.deepEqual(nowInZone('UTC', 1, instant), { hour: 2, minute: 7 });
});

test('the component is a typed combobox, never a native time input', () => {
    const view = readFileSync(new URL('../../resources/views/components/ui/time-picker.blade.php', import.meta.url), 'utf8');
    assert.match(view, /type="text"/);
    assert.match(view, /role="combobox"/);
    assert.match(view, /role="listbox"/);
    assert.doesNotMatch(view, /type="time"/);
    assert.match(view, /ui-time-picker__icon" aria-hidden="true"/);
});
