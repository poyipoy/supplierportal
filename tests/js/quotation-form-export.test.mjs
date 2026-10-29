import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { exportEntries, errorMessages, fileNameFrom, runExport } from '../../resources/js/quotation-form-export.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const formData = (pairs) => {
    const data = new FormData();
    for (const [key, value] of pairs) data.append(key, value);
    return data;
};

test('export entries drop files and the save action but keep every entered field', () => {
    const entries = exportEntries(formData([
        ['_token', 'csrf'],
        ['action', 'submitted'],
        ['items[0][pr_item_id]', '7'],
        ['items[0][available_length_input]', '2300-2500'],
        ['items[0][mtc_file]', new File(['x'], 'mtc.pdf', { type: 'application/pdf' })],
        ['items[0][notes]', ''],
    ]));
    assert.deepEqual([...entries.entries()], [
        ['_token', 'csrf'],
        ['items[0][pr_item_id]', '7'],
        ['items[0][available_length_input]', '2300-2500'],
        ['items[0][notes]', ''],
    ]);
});

test('error messages are flattened, de-duplicated and bounded', () => {
    assert.deepEqual(errorMessages({ 'items.0.price_per_kg': ['Bad price'], 'items.1.price_per_kg': ['Bad price', 'Other'] }), ['Bad price', 'Other']);
    assert.equal(errorMessages(Object.fromEntries(Array.from({ length: 9 }, (_, i) => [`f${i}`, [`m${i}`]]))).length, 5);
    assert.deepEqual(errorMessages(null), []);
});

test('the download name comes from Content-Disposition, else the fallback', () => {
    assert.equal(fileNameFrom('attachment; filename=quotation_form_REQ_10_2026_900.xlsx', 'x.xlsx'), 'quotation_form_REQ_10_2026_900.xlsx');
    assert.equal(fileNameFrom('attachment; filename="a b.xlsx"', 'x.xlsx'), 'a b.xlsx');
    assert.equal(fileNameFrom("attachment; filename*=UTF-8''q%20f.xlsx", 'x.xlsx'), 'q f.xlsx');
    assert.equal(fileNameFrom(null, 'x.xlsx'), 'x.xlsx');
});

const fakeForm = () => ({ values: { 'items[0][price_per_kg]': 'abc' }, submitted: false, reset() { this.values = {}; }, submit() { this.submitted = true; } });

test('a 422 reports the errors and leaves the form untouched', async () => {
    const form = fakeForm();
    const before = JSON.stringify(form.values);
    const notices = [];
    const result = await runExport({
        form,
        url: '/supplier/quotations/x/export-form',
        formDataFactory: () => formData([['items[0][price_per_kg]', 'abc']]),
        fetchImpl: async () => new Response(JSON.stringify({ message: 'Invalid', errors: { 'items.0.price_per_kg': ['The price must be a number.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } }),
        notify: (type, message) => notices.push([type, message]),
        download: () => assert.fail('no file on 422'),
    });
    assert.equal(result.ok, false);
    assert.deepEqual(result.errors, ['The price must be a number.']);
    assert.equal(notices[0][0], 'error');
    assert.match(notices[0][1], /The price must be a number\./);
    assert.equal(JSON.stringify(form.values), before);
    assert.equal(form.submitted, false);
});

test('a 200 downloads the blob without submitting or resetting the form', async () => {
    const form = fakeForm();
    let request;
    const downloads = [];
    const result = await runExport({
        form,
        url: '/export-form',
        csrfToken: 'csrf',
        formDataFactory: () => formData([['_token', 'csrf'], ['action', 'draft'], ['items[0][pr_item_id]', '7']]),
        fetchImpl: async (url, init) => { request = { url, init }; return new Response(new Blob(['xlsx']), { status: 200, headers: { 'Content-Disposition': 'attachment; filename=f.xlsx' } }); },
        notify: () => {},
        download: (blob, name) => downloads.push(name),
    });
    assert.equal(result.ok, true);
    assert.deepEqual(downloads, ['f.xlsx']);
    assert.equal(request.init.method, 'POST');
    assert.equal(request.init.headers.Accept, 'application/json');
    assert.equal(request.init.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(request.init.body.has('action'), false);
    assert.equal(form.submitted, false);
    assert.equal(JSON.stringify(form.values), JSON.stringify(fakeForm().values));
});

test('the quotation form wires the button through a lazy DOM marker', () => {
    const view = read('resources/views/supplier/quotations/_import_controls.blade.php');
    assert.match(view, /data-quotation-form-export/);
    assert.match(view, /route\('supplier\.quotations\.export-form', \$pr\)/);
    const app = read('resources/js/app.js');
    assert.match(app, /hasMarker\('\[data-quotation-form-export\]'\)\) import\('\.\/quotation-form-export'\)/);
    assert.doesNotMatch(app, /^import .*quotation-form-export/m);
});
