import test from 'node:test';
import assert from 'node:assert/strict';
import { adasiFileUploadComponent } from '../../resources/js/file-upload.js';

function uploadState(options = {}) {
    const state = adasiFileUploadComponent({ multiple: true, allowedExtensions: ['pdf'], ...options });
    state.$refs = { fileInput: { files: [], value: '', addEventListener() {} } };
    return state;
}

const previousTransfer = globalThis.DataTransfer;
globalThis.DataTransfer = class {
    files = [];
    items = { add: file => this.files.push(file) };
};
test.after(() => { globalThis.DataTransfer = previousTransfer; });

test('picker and drop reject non-PDF files while preserving existing and staged documents', () => {
    for (const drop of [false, true]) {
        const state = uploadState({ existingFiles: [{ id: 1, name: 'old.jpg' }] });
        const valid = { name: 'invoice.pdf', size: 1024 };
        state.handleFiles([valid]);
        const incoming = [{ name: 'new.jpg', size: 1024 }, { name: 'new.png', size: 1024 }];
        if (drop) state.handleDrop({ dataTransfer: { files: incoming } });
        else state.handleFiles(incoming);
        assert.equal(state.clientError, 'js.upload.extension');
        assert.deepEqual(state.stagedFiles, [valid]);
        assert.deepEqual(state.$refs.fileInput.files, [valid]);
        assert.equal(state.existingFiles[0].name, 'old.jpg');
    }
});

test('mixed batches accept PDFs including uppercase extensions and skip other formats', () => {
    const state = uploadState();
    const first = { name: 'invoice.PDF', size: 1024, type: '' };
    const second = { name: 'tax.pdf', size: 1024 };
    state.handleDrop({ dataTransfer: { files: [first, { name: 'image.jpeg', size: 1024 }, second] } });
    assert.deepEqual(state.stagedFiles, [first, second]);
    assert.deepEqual(state.$refs.fileInput.files, [first, second]);
    assert.equal(state.clientError, 'js.upload.extension');
    state.handleFiles([{ name: 'dn.pdf', size: 1024 }]);
    assert.equal(state.clientError, '');
});

test('invalid single-file replacement preserves the previously selected PDF', () => {
    const state = uploadState({ multiple: false });
    const valid = { name: 'invoice.pdf', size: 1024 };
    state.handleFiles([valid]);
    state.handleFiles([{ name: 'invoice.png', size: 1024 }]);
    assert.deepEqual(state.stagedFiles, [valid]);
    assert.deepEqual(state.$refs.fileInput.files, [valid]);
    assert.equal(state.clientError, 'js.upload.extension');
});

test('extensionless and double-extension files are rejected', () => {
    const state = uploadState();
    state.handleFiles([{ name: 'pdf', size: 1024 }, { name: 'invoice.pdf.exe', size: 1024 }]);
    assert.equal(state.stagedFiles.length, 0);
    assert.equal(state.clientError, 'js.upload.extension');
});

test('components without the new option keep accepting other extensions', () => {
    const state = uploadState({ allowedExtensions: [] });
    const files = [{ name: 'image.png', size: 1024 }, { name: 'document.zip', size: 1024 }];
    state.handleFiles(files);
    assert.deepEqual(state.stagedFiles, files);
    assert.equal(state.clientError, '');
});

test('422 errors preserve files and clear on the next submission', () => {
    const handlers = {};
    const state = uploadState({ existingFiles: [{ id: 1, name: 'old.png' }], serverError: 'Previous error' });
    const file = { name: 'invoice.pdf', size: 1024 };
    state.handleFiles([file]);
    state.bindAsyncFormErrors({ name: 'invoice[]', form: { addEventListener: (name, handler) => { handlers[name] = handler; } } });
    handlers['adasi:form-errors']({ detail: { errors: { 'invoice.0': ['PDF required'] } } });
    assert.equal(state.clientError, 'PDF required');
    assert.deepEqual(state.stagedFiles, [file]);
    assert.equal(state.existingFiles.length, 1);
    handlers['adasi:form-submitting']();
    assert.equal(state.clientError, '');
    assert.equal(state.serverError, '');
});

test('PDF-only uploads keep the size and combined file-count limits', () => {
    const state = uploadState({ existingFiles: [{ id: 1, name: 'old.png' }] });
    state.handleFiles([{ name: 'oversized.pdf', size: 5 * 1024 * 1024 + 1 }]);
    assert.equal(state.clientError, 'js.upload.skipped');
    assert.equal(state.stagedFiles.length, 0);
    const files = Array.from({ length: 5 }, (_, i) => ({ name: `page-${i}.pdf`, size: 5 * 1024 * 1024 }));
    state.handleFiles(files);
    assert.equal(state.stagedFiles.length, 4);
    assert.equal(state.totalFilesCount, 5);
    assert.equal(state.clientError, 'js.upload.limit');
});
