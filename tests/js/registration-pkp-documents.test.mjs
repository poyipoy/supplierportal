import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/views/auth/supplier-register.blade.php', import.meta.url), 'utf8');
const start = source.indexOf('validateStep(stepNumber) {');
const end = source.indexOf('updateDocsSummary() {', start);
const method = source.slice(start, end).replace(/@js\(__\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key));

test('PKP wizard requires SPPKP and checkbox changes preserve the selected files', () => {
    const inputs = Object.fromEntries(['nib_file', 'npwp_file', 'sknr_file', 'sppkp_file'].map(id => [id, { files: id === 'sppkp_file' ? [] : [{ name: id + '.pdf' }] }]));
    const state = vm.runInNewContext(`({${method}})`, { document: { getElementById: id => inputs[id] } });
    state.formData = { is_pkp: false };
    state.setStepError = () => {};
    const focus = [];
    state.focusField = id => focus.push(id);
    assert.equal(state.validateStep(4), true);
    state.formData.is_pkp = true;
    assert.equal(state.validateStep(4), false);
    assert.equal(focus.at(-1), 'sppkp_file');
    const file = { name: 'sppkp.pdf' };
    inputs.sppkp_file.files = [file];
    assert.equal(state.validateStep(4), true);
    state.formData.is_pkp = false;
    assert.equal(state.validateStep(4), true);
    state.formData.is_pkp = true;
    assert.equal(state.validateStep(4), true);
    assert.equal(inputs.sppkp_file.files[0], file);
    inputs.sppkp_file.files = [];
    assert.equal(state.validateStep(4), false);
});
