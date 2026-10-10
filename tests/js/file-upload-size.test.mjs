import test from 'node:test';
import assert from 'node:assert/strict';
import { adasiFileUploadComponent } from '../../resources/js/file-upload.js';

function uploadState() {
    const state = adasiFileUploadComponent({ maxSizeMb: 50 });
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
