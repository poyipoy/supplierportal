import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function boot(payload) {
    const context = { window: {}, document: { getElementById: () => ({ textContent: JSON.stringify(payload) }) } };
    vm.runInNewContext(fs.readFileSync(new URL('../../public/assets/js/adasi-i18n.js', import.meta.url), 'utf8'), context);
    return context.window.AdasiI18n;
}

test('trusted locale and plain named interpolation work before module scripts', () => {
    const i18n = boot({ locale: 'id', messages: { greeting: 'Halo :name', rows: { zero: 'Tidak ada baris', one: ':count baris', other: ':count baris' } } });
    assert.equal(i18n.locale, 'id');
    assert.equal(i18n.t('greeting', { name: '<script>$&</script>' }), 'Halo <script>$&</script>');
    assert.equal(i18n.choice('rows', 0), 'Tidak ada baris');
    assert.equal(i18n.choice('rows', 1), '1 baris');
    assert.equal(i18n.choice('rows', 4), '4 baris');
    assert.equal(i18n.t('constructor'), 'constructor');
    assert.equal(i18n.t('missing'), 'missing');
    assert.equal(Object.isFrozen(i18n), true);
});

test('invalid payload locale falls back to English without remote lookup', () => {
    assert.equal(boot({ locale: '../../id', messages: {} }).locale, 'en');
});


test('actual Finance dictionary selects whole invoice messages for zero, one and many', async () => {
    const { installI18n } = await import('./i18n-fixture.mjs');
    for (const locale of ['en', 'id']) {
        const i18n = installI18n({}, locale).AdasiI18n;
        assert.equal(i18n.choice('js.finance.invoice_count', 1), '1 invoice');
        assert.equal(i18n.choice('js.finance.invoice_count', 0), locale === 'en' ? '0 invoices' : '0 invoice');
        assert.equal(i18n.choice('js.finance.invoice_count', 12), locale === 'en' ? '12 invoices' : '12 invoice');
        assert.equal(i18n.choice('js.finance.invoices_tooltip', 1), locale === 'en' ? 'Incoming invoices: 1 invoice' : 'Invoice masuk: 1 invoice');
    }
});
