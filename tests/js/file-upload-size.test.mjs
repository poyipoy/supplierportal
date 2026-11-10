import test from 'node:test';
import assert from 'node:assert/strict';
import { adasiFileUploadComponent } from '../../resources/js/file-upload.js';

function uploadState(maxSizeMb = 50) {
    const state = adasiFileUploadComponent({ maxSizeMb });
    const input = { files: [], value: '', addEventListener() {} };
    state.$refs = { fileInput: input };
    return state;
}

test('a 70 MiB ZIP is rejected with a persistent error instead of silently clearing the message', () => {
    const state = uploadState();
    state.handleFiles([{ name: 'documents.zip', size: 70 * 1024 * 1024 }]);
    assert.equal(state.clientError, 'js.upload.size');
    assert.equal(state.stagedFiles.length, 0);
    assert.equal(state.$refs.fileInput.value, '');
});

test('an oversized dropped ZIP displays an error and clears its rejected selection', () => {
    const state = uploadState();
    state.isDragging = true;
    state.handleDrop({ dataTransfer: { files: [{ name: 'documents.zip', size: 70 * 1024 * 1024 }] } });
    assert.equal(state.clientError, 'js.upload.size');
    assert.equal(state.isDragging, false);
    assert.equal(state.hasFiles, false);
});

test('a ZIP exactly at the limit can replace a rejected ZIP and clear its error', () => {
    const state = uploadState();
    const previousTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: (file) => this.files.push(file) };
    };
    try {
        state.handleFiles([{ name: 'too-large.zip', size: 70 * 1024 * 1024 }]);
        const valid = { name: 'accepted.zip', size: 50 * 1024 * 1024 };
        state.handleFiles([valid]);
        assert.equal(state.clientError, '');
        assert.deepEqual(state.stagedFiles, [valid]);
        assert.deepEqual(state.$refs.fileInput.files, [valid]);
    } finally {
        globalThis.DataTransfer = previousTransfer;
    }
});

test('an 11 MiB spreadsheet is rejected by 10 MiB dropzone while 10 MiB is accepted', () => {
    const state = uploadState(10);
    state.isDragging = true;
    state.handleDrop({ dataTransfer: { files: [{ name: 'import.xlsx', size: 11 * 1024 * 1024 }] } });
    assert.equal(state.clientError, 'js.upload.size');
    assert.equal(state.hasFiles, false);

    const previousTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: (file) => this.files.push(file) };
    };
    try {
        const valid = { name: 'import.xlsx', size: 10 * 1024 * 1024 };
        state.handleFiles([valid]);
        assert.equal(state.clientError, '');
        assert.deepEqual(state.stagedFiles, [valid]);
    } finally {
        globalThis.DataTransfer = previousTransfer;
    }
});

test('a 21 MiB PO document is rejected by 20 MB dropzone while 20 MB is accepted', () => {
    const state = uploadState(20);
    state.isDragging = true;
    state.handleDrop({ dataTransfer: { files: [{ name: 'po-bundle.zip', size: 21 * 1024 * 1024 }] } });
    assert.equal(state.clientError, 'js.upload.size');
    assert.equal(state.hasFiles, false);

    const previousTransfer = globalThis.DataTransfer;
    globalThis.DataTransfer = class {
        files = [];
        items = { add: (file) => this.files.push(file) };
    };
    try {
        const valid = { name: 'po-bundle.zip', size: 20 * 1024 * 1024 };
        state.handleFiles([valid]);
        assert.equal(state.clientError, '');
        assert.deepEqual(state.stagedFiles, [valid]);
    } finally {
        globalThis.DataTransfer = previousTransfer;
    }
});
