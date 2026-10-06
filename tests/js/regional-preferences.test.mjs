import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
const cases = JSON.parse(await readFile(new URL('../Fixtures/regional-display-cases.json', import.meta.url), 'utf8'));
const dateCases = JSON.parse(await readFile(new URL('../Fixtures/regional-date-cases.json', import.meta.url), 'utf8'));
const registry = {
    number_profiles: {
        international: {decimal: '.', group: ','},
        indonesian: {decimal: ',', group: '.'},
        plain: {decimal: '.', group: ''},
        decimal: {decimal: '.', group: ''},
    },
    date_formats: ['system', 'human', 'dmy', 'iso'],
    months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

function boot(overrides = {}) {
    const marker = 'const preferences = @js($preferenceFrontendPayload);';
    const index = layout.indexOf(marker);
    const start = layout.lastIndexOf('<script>', index) + 8;
    const end = layout.indexOf('</script>', index);
    const store = new Map();
    const locale = overrides.locale === 'id' ? 'id' : 'en';
    const regionalOverrides = {...overrides};
    delete regionalOverrides.locale;
    const payload = {
        theme:'system',density:'comfortable',accent:'brand',accountId:'42',
        sidebarState:'expanded',sidebarRevision:'sidebar-v2:1',pageSize:25,
        regional:{timezone:'system',date_format:'system',time_format:'system',number_format:'system',...regionalOverrides},
        regionalRegistry:registry,
    };
    const windowRef = {
        matchMedia: () => ({matches:false,addEventListener(){}}),
        dispatchEvent(){},
        localStorage:{getItem:key=>store.get(key)||null,setItem:(key,value)=>store.set(key,value)},
    };
    vm.runInNewContext(layout.slice(start,end).replace('@js($preferenceFrontendPayload)', JSON.stringify(payload)), {
        window:windowRef,document:{documentElement:{dataset:{},lang:locale}},CustomEvent:class{},
    });
    return {preferences:windowRef.AdasiPreferences,store,windowRef};
}

test('helpers are available before deferred Vite and inline callbacks preserve numeric scale', () => {
    const state = boot({number_format:'international'});
    assert.equal(typeof state.preferences.displayNumber,'function');
    assert.equal(typeof state.preferences.displayDate,'function');
    const callback = value => 'Rp ' + state.preferences.displayNumber(Number(value).toLocaleString('id-ID'),'indonesian');
    assert.equal(callback(1250000.5),'Rp 1,250,000.5');
    const compact = value => 'Rp ' + state.preferences.displayNumber((value/1e9).toFixed(1),'decimal') + 'M';
    assert.equal(compact(1250000000),'Rp 1.3M');
});

for (const sample of cases) {
    test('numeric golden: '+JSON.stringify(sample), () => {
        const state=boot({number_format:sample.target});
        assert.equal(state.preferences.displayNumber(sample.text,sample.profile),sample.expected);
    });
}

test('date-only helpers use ISO components and ignore Jakarta/browser instant conversion', () => {
    for (const [key,expected] of [['system','2026-09-28'],['human','28 Sep 2026'],['dmy','28/09/2026'],['iso','2026-09-28']]) {
        const state=boot({timezone:'Asia/Jakarta',date_format:key});
        assert.equal(state.preferences.displayDate('2026-09-28','iso'),expected);
    }
    assert.equal(boot().preferences.displayDate('2026-09-28','human'),'28 Sep 2026');
    assert.equal(boot({date_format:'human'}).preferences.displayDate('2026-02-30','iso'),'2026-02-30');
    assert.equal(boot({date_format:'human'}).preferences.displayDate('2024-02-29','iso'),'29 Feb 2024');
});

test('human date month abbreviations follow the account language', () => {
    assert.equal(boot({date_format:'human',locale:'en'}).preferences.displayDate('2026-10-28','iso'),'28 Oct 2026');
    assert.equal(boot({date_format:'human',locale:'id'}).preferences.displayDate('2026-10-28','iso'),'28 Okt 2026');
    assert.equal(
        boot({date_format:'human',locale:'id',timezone:'Asia/Jakarta',time_format:'24h'}).preferences.displayTimestamp('2026-10-28T23:35:00Z'),
        '29 Okt 2026 06:35 WIB',
    );
});

test('formatting never writes regional state to LocalStorage or changes raw data', () => {
    const state=boot({number_format:'indonesian',date_format:'dmy'});
    const before=JSON.stringify(Array.from(state.store));
    const row={start:'2026-09-28',end:'2026-09-30',period_amount:1250000.5,key:'2026-09'};
    const snapshot=JSON.stringify(row);
    state.preferences.displayDate(row.start,'iso');
    state.preferences.displayNumber(row.period_amount.toLocaleString('id-ID'),'indonesian');
    assert.equal(JSON.stringify(row),snapshot);
    assert.equal(JSON.stringify(Array.from(state.store)),before);
    assert.equal(Array.from(state.store.keys()).filter(key=>!key.startsWith('adasi.sidebar.')).length,0);
});

for (const sample of dateCases) {
    test('date golden: '+JSON.stringify(sample), () => {
        const state=boot({timezone:'Asia/Jakarta',date_format:sample.target});
        assert.equal(state.preferences.displayDate(sample.value,sample.profile),sample.expected);
    });
}
