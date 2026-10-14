import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/local-invoices/form.blade.php', import.meta.url), 'utf8');
const section = view.slice(view.indexOf('// Filename Autofill for Invoice Number'), view.indexOf('// Submit button loading state'))
    .replace("@js(__('local_invoice.interaction.different_filenames'))", "'Different files: :files'");

function boot() {
    const fields = new Map();
    function element(id) {
        if (!fields.has(id)) {
            const classes = new Set(['tw-hidden']);
            const listeners = new Map();
            fields.set(id, { value: '', files: [], classList: {
                add: value => classes.add(value), remove: value => classes.delete(value),
                toggle: (value, enabled) => enabled ? classes.add(value) : classes.delete(value),
                contains: value => classes.has(value),
            }, hasAttribute: () => false, querySelector: () => ({}), replaceChildren() {},
            closest: () => null, removeAttribute() {}, setSelectionRange() {},
            addEventListener: (name, fn) => listeners.set(name, fn),
            dispatchEvent(event) { listeners.get(event.type)?.call(this, event); },
            });
        }
        return fields.get(id);
    }
    vm.runInNewContext(section, { document: { getElementById: element, createElement: () => ({}) },
        Event: class { constructor(type) { this.type = type; } } });
    const change = (id, names) => { const field = element(id); field.files = names.map(name => ({ name })); field.dispatchEvent({ type: 'change' }); };
    const type = (id, value) => { const field = element(id); field.value = value; field.dispatchEvent({ type: 'input' }); };

    return { element, change, type };
}

test('a manually edited Tax Invoice number is no longer labelled as coming from the filename', () => {
    const app = boot();
    app.change('file_tax_invoice', ['12345678765432875.pdf']);
    assert.equal(app.element('tax_invoice_number').value, '12.34.56.78765432875');
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), false);
    app.type('tax_invoice_number', '12.34.56.78765432876');
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), true);
    app.change('file_tax_invoice', ['12345678765432876.pdf']);
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), false);
});

test('format-only edits still match the filename and clearing the file clears its provenance badge', () => {
    const app = boot();
    app.change('file_tax_invoice', ['0100002612345678.pdf']);
    app.type('tax_invoice_number', '0100002612345678');
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), false);
    app.change('file_tax_invoice', []);
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), true);
});

test('invoice number provenance also follows the current field instead of the old attachment', () => {
    const app = boot();
    app.change('file_invoice', ['INV-001.pdf']);
    assert.equal(app.element('invoice-autofill-badge').classList.contains('tw-hidden'), false);
    app.type('invoice-number', 'INV-002');
    assert.equal(app.element('invoice-autofill-badge').classList.contains('tw-hidden'), true);
    app.change('file_invoice', []);
    assert.equal(app.element('invoice-autofill-badge').classList.contains('tw-hidden'), true);
});

test('editing an invoice identity removes the stale field error while retaining the attachment', () => {
    const app = boot();
    app.change('file_tax_invoice', ['12345678765432875.pdf']);
    let removed = false;
    app.element('tax_invoice_number').nextElementSibling = { matches: selector => selector === '[data-async-error]', remove: () => { removed = true; } };
    app.type('tax_invoice_number', '12.34.56.78765432876');
    assert.equal(removed, true);
    assert.equal(app.element('file_tax_invoice').files[0].name, '12345678765432875.pdf');
});

test('pasting a different tax number clears its stale error and filename badge too', () => {
    const app = boot();
    app.change('file_tax_invoice', ['12345678765432875.pdf']);
    let removed = false;
    const input = app.element('tax_invoice_number');
    input.nextElementSibling = { matches: () => true, remove: () => { removed = true; } };
    const match = view.match(/onPaste\(e\) \{([\s\S]*?)\n                            \}\n                        \}"/);
    assert.ok(match);
    const handler = vm.runInNewContext(`({ onPaste(e) { ${match[1]} } })`, {
        window: {}, Event: class { constructor(type) { this.type = type; } },
    });
    handler.onPaste.call({ $refs: { taxInput: input }, $nextTick: fn => fn(),
        formatDigits: digits => `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4, 6)}.${digits.slice(6)}`,
    }, { preventDefault() {}, clipboardData: { getData: () => '12345678765432876' } });
    assert.equal(input.value, '12.34.56.78765432876');
    assert.equal(removed, true);
    assert.equal(app.element('tax-autofill-badge').classList.contains('tw-hidden'), true);
    assert.equal(app.element('file_tax_invoice').files[0].name, '12345678765432875.pdf');
});
