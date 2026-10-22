import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const app = read('resources/js/app.js');

test('page-specific modules are not part of the entry bundle', () => {
    for (const module of ['calendar', 'advanced-export', 'dashboard-editor', 'customization-form', 'notification-preferences', 'supplier-audit-form', 'regional-sample']) {
        assert.doesNotMatch(app, new RegExp(`^import[^\\n]*'\\./${module}(\\.js)?'`, 'm'), `${module} must stay a dynamic import`);
        assert.match(app, new RegExp(`import\\('\\./${module}'\\)`), `${module} must still be loaded where its markup is present`);
    }
});

test('gated Alpine components are registered before Alpine starts', () => {
    const awaited = app.indexOf('await Promise.allSettled(pageComponents)');
    const start = app.indexOf('Alpine.start();');
    assert.ok(awaited > 0 && start > awaited, 'the gated imports must be awaited before Alpine.start()');
    for (const name of ['customizationForm', 'notificationPreferencesForm', 'supplierAuditForm']) {
        assert.match(app, new RegExp(`Alpine\\.data\\('${name}', module\\.${name}\\)`));
    }
});

test('each marker selector matches the markup that needs the module', () => {
    const markers = [
        ['[x-data^="customizationForm"]', 'resources/views/profile/customization.blade.php', 'x-data="customizationForm('],
        ['[x-data^="notificationPreferencesForm"]', 'resources/views/profile/notifications.blade.php', 'x-data="notificationPreferencesForm('],
        ['[x-data^="supplierAuditForm"]', 'resources/views/local-supplier/supplier-audits/edit.blade.php', 'x-data="supplierAuditForm('],
        ['[data-advanced-export], [data-format-export-form]', 'resources/views/components/export/advanced-modal.blade.php', 'data-advanced-export'],
        ['[data-dashboard-audience] [data-editor-config]', 'resources/views/components/ui/dashboard-layout.blade.php', 'data-editor-config'],
        ["'[data-adasi-date-picker], [data-adasi-date-range]'", 'resources/views/components/ui/date-picker.blade.php', 'data-adasi-date-picker'],
    ];
    for (const [selector, view, markup] of markers) {
        assert.ok(app.includes(selector), `app.js gates on ${selector}`);
        assert.ok(read(view).includes(markup), `${view} still renders ${markup}`);
    }
    assert.ok(read('resources/views/components/ui/date-range-picker.blade.php').includes('data-adasi-date-range'));
});

test('the calendar stand-in keeps the AdasiCalendar contract until calendar.js replaces it', () => {
    assert.match(app, /window\.AdasiCalendar = Object\.freeze\(\{[\s\S]*initialize:[\s\S]*reset:[\s\S]*closeActive:/);
    assert.match(read('resources/js/calendar.js'), /window\.AdasiCalendar = Object\.freeze\(\{\s*initialize: initializeAdasiCalendars,\s*reset: resetAdasiCalendar,\s*closeActive: closeActiveCalendar,/);
    assert.ok(app.indexOf('window.AdasiCalendar.initialize(document)') > app.indexOf('Alpine.start();'), 'calendars boot after Alpine renders');
});
