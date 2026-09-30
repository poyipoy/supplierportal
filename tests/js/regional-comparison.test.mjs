import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
const scriptsView = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');

const registry = {
    number_profiles: {
        international: { decimal: '.', group: ',' },
        indonesian: { decimal: ',', group: '.' },
        plain: { decimal: '.', group: '' },
        decimal: { decimal: '.', group: '' },
    },
    date_formats: ['system', 'human', 'dmy', 'iso'],
    months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

function bootAdasiPreferences(overrides = {}) {
    const marker = 'const preferences = @js($preferenceFrontendPayload);';
    const index = layout.indexOf(marker);
    const start = layout.lastIndexOf('<script>', index) + 8;
    const end = layout.indexOf('</script>', index);
    const payload = {
        theme: 'system', density: 'comfortable', accent: 'brand', accountId: '42',
        sidebarState: 'expanded', sidebarRevision: 'sidebar-v2:1', pageSize: 25,
        regional: { timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'system', ...overrides },
        regionalRegistry: registry,
    };
    const windowRef = {
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        dispatchEvent() {},
        localStorage: { getItem: () => null, setItem() {} },
    };
    vm.runInNewContext(layout.slice(start, end).replace(marker, `const preferences = ${JSON.stringify(payload)};`), {
        window: windowRef, document: { documentElement: { dataset: {} } }, CustomEvent: class {},
        Intl, Date, Number, String, Object, Array, Math,
    });
    return windowRef.AdasiPreferences;
}

test('comparison scripts formatters transcode numbers with user regional number preference', () => {
    // Test Indonesian preference
    const indonesianPrefs = bootAdasiPreferences({ number_format: 'indonesian' });
    const intlPrefs = bootAdasiPreferences({ number_format: 'international' });
    const systemPrefs = bootAdasiPreferences({ number_format: 'system' });

    // Verify AdasiPreferences.displayNumber behavior
    assert.equal(indonesianPrefs.displayNumber('1,250,000.50', 'international'), '1.250.000,50');
    assert.equal(intlPrefs.displayNumber('1.250.000,50', 'indonesian'), '1,250,000.50');
    assert.equal(systemPrefs.displayNumber('1.250.000,50', 'indonesian'), '1.250.000,50');
});

test('scripts view uses window.AdasiPreferences.displayNumber in formatters', () => {
    assert.match(scriptsView, /window\.AdasiPreferences\?\.displayNumber/);
});
