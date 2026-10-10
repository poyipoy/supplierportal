import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { computeDateTimeSample, computeNumberSample, computeRegionalSample } from '../../resources/js/regional-sample.js';

// Parity harness: boots the exact early-head bootstrap script from layouts/app.blade.php
// (the same script preferences.test.mjs already exercises) so this file's pure
// reimplementation can be checked against the real, currently-shipped formatter.
const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

function headBootstrap() {
    const marker = 'const preferences = @js($preferenceFrontendPayload);';
    const content = layout.indexOf(marker);
    assert.notEqual(content, -1, 'the early preference bootstrap is present in the application head');
    const start = layout.lastIndexOf('<script>', content) + '<script>'.length;
    const end = layout.indexOf('</script>', content);

    return layout.slice(start, end);
}

function boot(payload, lang = 'en') {
    const root = { dataset: {}, lang };
    const media = { matches: false, addEventListener: () => {} };
    const windowRef = {
        matchMedia: () => media,
        dispatchEvent: () => {},
        localStorage: { getItem: () => null, setItem: () => {} },
    };
    const script = headBootstrap().replace('@js($preferenceFrontendPayload)', JSON.stringify(payload));
    vm.runInNewContext(script, {
        window: windowRef,
        document: { documentElement: root },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
    });

    return windowRef;
}

// Mirrors config/regional_display.php exactly so this harness stays representative.
const REGISTRY = {
    date_formats: ['system', 'human', 'dmy', 'iso'],
    number_profiles: {
        international: { decimal: '.', group: ',' },
        indonesian: { decimal: ',', group: '.' },
        plain: { decimal: '.', group: '' },
        decimal: { decimal: '.', group: '' },
    },
    months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

const SAMPLE_ISO = '2026-10-08T07:30:00Z';
const SAMPLE_NUMBER = '1,250,000.50';

const regionalCombinations = [
    { timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'system' },
    { timezone: 'system', date_format: 'iso', time_format: '24h', number_format: 'international' },
    { timezone: 'system', date_format: 'dmy', time_format: '12h', number_format: 'indonesian' },
    { timezone: 'system', date_format: 'human', time_format: 'system', number_format: 'system' },
    { timezone: 'Asia/Jakarta', date_format: 'iso', time_format: '24h', number_format: 'international' },
    { timezone: 'Asia/Jakarta', date_format: 'human', time_format: '12h', number_format: 'indonesian' },
    { timezone: 'Asia/Jakarta', date_format: 'system', time_format: 'system', number_format: 'international' },
];

test('computeDateTimeSample matches the layout bootstrap displayTimestamp for every explicit combination', () => {
    for (const regional of regionalCombinations) {
        const explicit = regional.timezone !== 'system' || regional.date_format !== 'system' || regional.time_format !== 'system';
        const windowRef = boot({
            theme: 'system', density: 'comfortable', sidebarState: 'expanded', pageSize: 25,
            sidebarRevision: '0:0', accountId: '1', regional, regionalRegistry: REGISTRY,
        });

        const ours = computeDateTimeSample(SAMPLE_ISO, regional, REGISTRY.date_formats, 'en', REGISTRY.months);

        if (!explicit) {
            assert.equal(ours, null, `expected null (system fallback) for ${JSON.stringify(regional)}`);
            continue;
        }

        const theirs = windowRef.AdasiPreferences.displayTimestamp(SAMPLE_ISO, () => '-');
        assert.equal(ours, theirs, `mismatch for ${JSON.stringify(regional)}`);
    }
});

test('computeNumberSample matches the layout bootstrap displayNumber for every number_format', () => {
    for (const numberFormat of ['system', 'international', 'indonesian']) {
        const regional = { timezone: 'system', date_format: 'system', time_format: 'system', number_format: numberFormat };
        const windowRef = boot({
            theme: 'system', density: 'comfortable', sidebarState: 'expanded', pageSize: 25,
            sidebarRevision: '0:0', accountId: '1', regional, regionalRegistry: REGISTRY,
        });

        const ours = computeNumberSample(SAMPLE_NUMBER, numberFormat, REGISTRY.number_profiles);
        const theirs = windowRef.AdasiPreferences.displayNumber(SAMPLE_NUMBER);
        assert.equal(ours, theirs, `mismatch for number_format=${numberFormat}`);
    }
});

test('computeRegionalSample shows the system notice only when timezone, date, and time are all system', () => {
    const regional = { timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'indonesian' };
    const sample = computeRegionalSample({
        sampleIso: SAMPLE_ISO, sampleNumber: SAMPLE_NUMBER, regional, registry: REGISTRY,
        locale: 'en', systemNotice: 'Follows your current display',
    });
    assert.ok(sample.startsWith('Follows your current display ·'));
    assert.ok(sample.endsWith('1.250.000,50'));
});

test('computeRegionalSample computes the date/time part once any of the three fields is explicit', () => {
    const regional = { timezone: 'system', date_format: 'iso', time_format: 'system', number_format: 'international' };
    const sample = computeRegionalSample({
        sampleIso: SAMPLE_ISO, sampleNumber: SAMPLE_NUMBER, regional, registry: REGISTRY,
        locale: 'en', systemNotice: 'Follows your current display',
    });
    assert.ok(!sample.startsWith('Follows your current display'));
    assert.ok(sample.startsWith('2026-10-08'));
});
