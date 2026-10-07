import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';
import { rangePresets } from '../../resources/js/calendar-core.js';
import { adasiFileUploadComponent } from '../../resources/js/file-upload.js';
import { bootPasswordAssistance } from '../../resources/js/password-assistance.js';

test('Indonesian calendar labels preserve ISO preset boundaries', () => {
    globalThis.window = installI18n({}, 'id');
    const preset = rangePresets('day', new Date(2026, 0, 2))[1];
    assert.equal(preset.label, '7 hari terakhir');
    assert.equal(preset.start, '2025-12-27');
    assert.equal(preset.end, '2026-01-02');
});

test('file validation treats malicious filenames as plain replacements', () => {
    globalThis.window = installI18n({}, 'id');
    const upload = adasiFileUploadComponent({ multiple: true, maxSizeMb: 1 });
    upload.$refs = {};
    upload.handleFiles([{ name: '<img src=x onerror=alert(1)>', size: 2000000 }]);
    assert.equal(upload.stagedFiles.length, 0);
    assert.match(upload.clientError, /Berkas "<img src=x onerror=alert\(1\)>" melebihi 1 MB/);
});

test('Indonesian copy feedback uses a live text node and copies exact business content', async () => {
    globalThis.window = installI18n({}, 'id');
    let listener;
    const status = { textContent: '' };
    const button = { dataset: { passwordAssistanceCopy: 'email', copyLabel: 'Alamat email' }, addEventListener: (_, callback) => { listener = callback; } };
    const root = { querySelectorAll: () => [{ querySelector: () => status, querySelectorAll: () => [button] }], getElementById: () => ({ textContent: 'support@example.test' }) };
    const copied = [];
    bootPasswordAssistance(root, { isSecureContext: true, navigator: { clipboard: { writeText: async text => copied.push(text) } } });
    await listener();
    assert.deepEqual(copied, ['support@example.test']);
    assert.equal(status.textContent, 'Alamat email disalin.');
});


test('chat receipt copy follows locale and escapes the full timestamp replacement', () => {
    const view = readFileSync(new URL('../../resources/views/partials/chat-drawer.blade.php', import.meta.url), 'utf8');
    const start = view.indexOf('const readReceiptHtml = (message) => {');
    const end = view.indexOf('const renderMessageAttachments', start);
    assert.ok(start >= 0 && end > start);
    const source = view.slice(start, end);
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
    for (const locale of ['en', 'id']) {
        const window = installI18n({}, locale);
        const render = vm.runInNewContext(source + '\nreadReceiptHtml;', { window, escapeHtml });
        assert.equal(render({ is_me: false }), '');
        assert.match(render({ id: 7, is_me: true, is_read: false }), locale === 'id' ? /Terkirim, belum dibaca/ : /Sent, unread/);
        const read = render({ id: 7, is_me: true, is_read: true, read_at_display: '10:30 <img src=x onerror=alert(1)>' });
        assert.match(read, locale === 'id' ? /Dibaca 10:30/ : /Read 10:30/);
        assert.match(read, /&lt;img src=x onerror=alert\(1\)&gt;/);
        assert.doesNotMatch(read, /<img/);
        assert.match(read, /data-read-receipt-id="7"/);
    }
});

test('chat link accessible name uses whole zero, one and plural messages', () => {
    for (const locale of ['en', 'id']) {
        const i18n = installI18n({}, locale).AdasiI18n;
        assert.equal(i18n.choice('js.shell.chat_link', 0), locale === 'id' ? 'Negosiasi dan Chat' : 'Negotiation and Chat');
        assert.equal(i18n.choice('js.shell.chat_link', 1), locale === 'id' ? 'Negosiasi dan Chat, 1 percakapan belum dibaca' : 'Negotiation and Chat, 1 unread conversation');
        assert.equal(i18n.choice('js.shell.chat_link', 4), locale === 'id' ? 'Negosiasi dan Chat, 4 percakapan belum dibaca' : 'Negotiation and Chat, 4 unread conversations');
    }
});

test('native alert fallback localizes default titles and preserves plain business text', async () => {
    const runtime = readFileSync(new URL('../../public/assets/js/adasi-alert.js', import.meta.url), 'utf8');
    for (const locale of ['en', 'id']) {
        const prompts = [];
        const alerts = [];
        const window = installI18n({ prompt: (...args) => { prompts.push(args); return 'business value'; }, alert: message => alerts.push(message) }, locale);
        vm.runInNewContext(runtime, { window, document: { addEventListener() {} } });
        const prompt = await window.AdasiAlert.prompt({ initialValue: 'existing value' });
        assert.equal(prompts[0][0], locale === 'id' ? 'Masukan' : 'Input');
        assert.equal(prompts[0][1], 'existing value');
        assert.equal(prompt.value, 'business value');
        await window.AdasiAlert.success({ text: '<img src=x> user content' });
        assert.equal(alerts[0], (locale === 'id' ? 'Operasi selesai' : 'Operation completed') + '\n\n<img src=x> user content');
    }
});
