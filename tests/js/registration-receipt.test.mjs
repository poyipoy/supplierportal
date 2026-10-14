import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/registration-receipt.js', import.meta.url), 'utf8')
    .replace('export function bootRegistrationReceipt', 'function bootRegistrationReceipt');

function setup(copyFails = false) {
    const elements = new Map();
    const copied = [];
    const downloads = [];
    const blobs = [];
    const revoked = [];
    const timers = [];
    const element = selector => {
        if (!elements.has(selector)) elements.set(selector, { value: '', textContent: '', listeners: {},
            addEventListener(name, callback) { this.listeners[name] = callback; },
            focus() { this.focused = true; }, select() { this.selected = true; } });
        return elements.get(selector);
    };
    element('[data-receipt-reference]').value = 'REG-2026-TEST';
    element('[data-receipt-key]').value = 'private-access-code';
    element('[data-receipt-label-reference]').textContent = 'Nomor Registrasi';
    element('[data-receipt-label-key]').textContent = 'Kode Akses';
    element('[data-receipt-copied]').textContent = 'Disalin';
    element('[data-receipt-copy-failed]').textContent = 'Salin secara manual';
    const receipt = { dataset: {}, querySelector: element };
    const document = { readyState: 'complete', querySelector: () => receipt,
        body: { append(link) { downloads.push(link); } },
        createElement() { return { click() { this.clicked = true; }, remove() { this.removed = true; } }; } };
    const context = { document,
        navigator: { clipboard: { async writeText(value) { if (copyFails) throw new Error('Denied'); copied.push(value); } } },
        Blob,
        URL: { createObjectURL(blob) { blobs.push(blob); return 'blob:receipt'; }, revokeObjectURL(url) { revoked.push(url); } },
        setTimeout(callback) { timers.push(callback); },
    };
    vm.runInNewContext(source, context);
    return { element, copied, downloads, blobs, revoked, timers };
}

const expected = 'Nomor Registrasi: REG-2026-TEST\nKode Akses: private-access-code\n';

test('copy and text download use the same reference and key shown on the receipt', async () => {
    const app = setup();
    await app.element('[data-receipt-copy]').listeners.click();
    assert.equal(app.copied[0], expected);
    assert.equal(app.element('[data-receipt-message]').textContent, 'Disalin');
    app.element('[data-receipt-download]').listeners.click();
    assert.equal(await app.blobs[0].text(), expected);
    assert.equal(app.downloads[0].download, 'REG-2026-TEST.txt');
    assert.equal(app.downloads[0].clicked, true);
    assert.equal(app.downloads[0].removed, true);
    app.timers[0]();
    assert.deepEqual(app.revoked, ['blob:receipt']);
});

test('clipboard denial leaves the code selectable with manual copy guidance', async () => {
    const app = setup(true);
    await app.element('[data-receipt-copy]').listeners.click();
    assert.equal(app.element('[data-receipt-message]').textContent, 'Salin secara manual');
    assert.equal(app.element('[data-receipt-key]').focused, true);
    assert.equal(app.element('[data-receipt-key]').selected, true);
});
