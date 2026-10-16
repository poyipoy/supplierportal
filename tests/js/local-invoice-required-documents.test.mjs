import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { adasiFileUploadComponent } from '../../resources/js/file-upload.js';

const blade = readFileSync(new URL('../../resources/views/local-invoices/form.blade.php', import.meta.url), 'utf8');
const wizardSource = blade.match(/function localInvoiceWizard\(config\) \{[\s\S]*?\n\}/)[0]
    .replace(/@js\(__\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key));

function wizardFixture({ retainedPo = 0, retainedGr = 0, reducedMotion = false } = {}) {
    const focus = [];
    const scroll = [];
    const inputs = Object.fromEntries(['invoice', 'tax_invoice', 'purchase_order', 'goods_receipt', 'delivery_note', 'supporting'].map(type => {
        const id = `file_${type}`;
        const input = {
            id, files: type === 'invoice' ? [{}] : [], kept: 0,
            addEventListener() {}, removeAttribute() {},
            closest: () => ({ querySelectorAll: () => Array(input.kept).fill({}), querySelector: () => ({ focus: () => focus.push(id) }) }),
        };
        return [id, input];
    }));
    inputs.file_purchase_order.kept = retainedPo;
    inputs.file_goods_receipt.kept = retainedGr;
    const create = vm.runInNewContext(`(${wizardSource})`, {
        document: { getElementById: id => inputs[id] },
        window: { scrollTo: value => scroll.push(value), matchMedia: () => ({ matches: reducedMotion }) },
    });
    const state = create({ isPkp: false, requiresDeliveryNote: false, existingPoCount: retainedPo, existingGrCount: retainedGr });
    state.$nextTick = callback => callback();
    state.init();
    return { state, inputs, focus, scroll };
}

test('the wizard requires both PO and GR and focuses the first missing upload', () => {
    const { state, inputs, focus } = wizardFixture();
    state.goToStep(2);
    assert.equal(state.step, 1);
    assert.equal(focus.at(-1), 'file_purchase_order');
    inputs.file_purchase_order.files = [{}];
    state.goToStep(2);
    assert.equal(focus.at(-1), 'file_goods_receipt');
    inputs.file_goods_receipt.files = [{}, {}, {}, {}, {}];
    state.goToStep(2);
    assert.equal(state.step, 2);
    assert.equal(state.errorMessage, '');
});

test('removing saved references invalidates completeness instead of using stale initial counts', () => {
    const { state, inputs } = wizardFixture({ retainedPo: 1, retainedGr: 1 });
    assert.equal(state.isStep1Complete, true);
    inputs.file_purchase_order.kept = 0;
    state.goToStep(2);
    assert.equal(state.step, 1);
    assert.equal(state.hasPo, false);
    inputs.file_purchase_order.files = [{}];
    inputs.file_goods_receipt.kept = 0;
    state.checkFiles();
    assert.equal(state.hasPo, true);
    assert.equal(state.hasGr, false);
});

test('a combined retained and new GR selection cannot exceed five', () => {
    const { state, inputs } = wizardFixture({ retainedPo: 1, retainedGr: 4 });
    inputs.file_goods_receipt.files = [{}];
    state.checkFiles();
    assert.equal(state.isStep1Complete, true);
    inputs.file_goods_receipt.files.push({});
    state.goToStep(2);
    assert.equal(state.step, 1);
    assert.equal(state.hasGr, false);
});

test('wizard navigation respects reduced motion', () => {
    const { state, scroll } = wizardFixture({ retainedPo: 1, retainedGr: 1, reducedMotion: true });
    state.goToStep(2);
    assert.equal(scroll.at(-1).behavior, 'auto');
});

test('drop, replace and remove publish actual counts to the invoice wizard', () => {
    const previousTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: file => this.files.push(file) };
    };
    try {
        const { state: wizard } = wizardFixture({ retainedPo: 1, retainedGr: 1 });
        const uploader = adasiFileUploadComponent({ multiple: false, maxFiles: 1, allowedExtensions: ['pdf'], existingFiles: [{ id: 1, name: 'old.pdf' }] });
        uploader.$refs = { fileInput: { id: 'file_purchase_order', files: [] } };
        uploader.$dispatch = (_, detail) => wizard.updateFileCount(detail);
        uploader.removeExistingFile(0);
        assert.equal(wizard.hasPo, false);
        const file = { name: 'replacement.PDF', size: 1024 };
        uploader.handleDrop({ dataTransfer: { files: [file] } });
        assert.equal(wizard.hasPo, true);
        assert.deepEqual(uploader.$refs.fileInput.files, [file]);
        uploader.removeStagedFile(0);
        assert.equal(wizard.hasPo, false);
    } finally {
        globalThis.DataTransfer = previousTransfer;
    }
});

test('indexed GR validation errors preserve the selected PDFs', () => {
    const previousTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: file => this.files.push(file) };
    };
    try {
        const listeners = {};
        const uploader = adasiFileUploadComponent({ multiple: true, maxFiles: 5, allowedExtensions: ['pdf'] });
        uploader.$refs = { fileInput: { id: 'file_goods_receipt', name: 'goods_receipt[]', files: [], form: { addEventListener: (name, listener) => { listeners[name] = listener; } } } };
        uploader.bindAsyncFormErrors(uploader.$refs.fileInput);
        const files = [{ name: 'GR-1.pdf', size: 1024 }, { name: 'GR-2.pdf', size: 1024 }];
        uploader.handleFiles(files);
        listeners['adasi:form-errors']({ detail: { errors: { 'goods_receipt.1': ['Invalid PDF structure'] } } });
        assert.equal(uploader.clientError, 'Invalid PDF structure');
        assert.deepEqual(uploader.$refs.fileInput.files, files);
        assert.equal(uploader.totalFilesCount, 2);
    } finally {
        globalThis.DataTransfer = previousTransfer;
    }
});
