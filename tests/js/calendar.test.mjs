import { installI18n } from './i18n-fixture.mjs';
globalThis.window = installI18n(globalThis.window || {});
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import {
    addLocalDays,
    addLocalMonths,
    formatDateDisplay,
    formatMonthDisplay,
    isIsoDate,
    isIsoMonth,
    rangePresets,
} from '../../resources/js/calendar-core.js';

test('day range year selection clamps leap day without changing the selected range', () => {
    const source = readFileSync(new URL('../../resources/js/calendar.js', import.meta.url), 'utf8');
    const classSource = source.slice(source.indexOf('class CalendarController'), source.indexOf('export function initializeAdasiCalendars'));
    const Controller = runInNewContext(`${classSource}; CalendarController`, { Date, t: () => '' });
    const controller = Object.create(Controller.prototype);
    const label = {};
    controller.dayGrid = { focusedDate: '2024-02-29', querySelector: (selector) => selector === '[data-calendar-year-label]' ? label : null };
    controller.draft = { start: '2024-02-29', end: '2024-03-02' };
    controller.toggleDayYearPanel = () => {};
    controller.announce = () => {};

    controller.selectDayYear(2025);
    assert.equal(controller.dayGrid.focusedDate, '2025-02-28');
    assert.equal(label.textContent, '2025');
    assert.deepEqual(controller.draft, { start: '2024-02-29', end: '2024-03-02' });

    controller.dayGrid.focusedDate = '2024-02-29';
    controller.selectDayYear(2028);
    assert.equal(controller.dayGrid.focusedDate, '2028-02-29');
});

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

test('every caller of bootAdasiCalendars waits for the single in-flight engine load', async () => {
    // Regression: app.js starts the engine import at startup, then the price comparison page calls
    // AdasiCalendar.initialize() on DOMContentLoaded while that import is still pending. initializeAdasiCalendars()
    // retries through `bootAdasiCalendars().then(...)`, so a second boot call must not settle before the first.
    // It used to return an already-resolved promise, so the retry re-entered itself on every microtask, the import
    // could never complete, and the page loaded forever.
    const { spawnSync } = await import('node:child_process');
    const calendarUrl = new URL('../../resources/js/calendar.js', import.meta.url).href;
    // calendar.js uses extensionless Vite-style imports, which plain Node cannot resolve.
    const hook = "export async function resolve(specifier, context, next) { try { return await next(specifier, context); } catch (error) { if (specifier.startsWith('.') && !/\\.[a-z]+$/.test(specifier)) return next(specifier + '.js', context); throw error; } }";
    const script = `
        import { register } from 'node:module';
        register('data:text/javascript,' + encodeURIComponent(${JSON.stringify(hook)}));
        globalThis.window = globalThis;
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        globalThis.document = { querySelectorAll: () => [] };
        const { bootAdasiCalendars } = await import(${JSON.stringify(calendarUrl)});
        const order = [];
        const first = bootAdasiCalendars();
        const second = bootAdasiCalendars();
        first.then(() => order.push('first'));
        second.then(() => order.push('second'));
        await Promise.all([first, second]);
        console.log(order.join(','));
    `;
    const result = spawnSync(process.execPath, ['--input-type=module', '--eval', script], { encoding: 'utf8', timeout: 5000, killSignal: 'SIGKILL' });

    assert.equal(result.error?.code, undefined, 'boot must not spin forever: ' + (result.error?.message ?? ''));
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout.trim(), 'first,second');
});
