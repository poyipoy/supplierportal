import { installI18n } from './i18n-fixture.mjs';
globalThis.window = installI18n(globalThis.window || {});
import test from 'node:test';
import assert from 'node:assert/strict';
import { copyAssistanceText, bootPasswordAssistance } from '../../resources/js/password-assistance.js';

function environment({ clipboard, fallback = true } = {}) {
    const focus = { restored: 0, focus() { this.restored++; } };
    const area = { style: {}, setAttribute() {}, focus() {}, select() {}, remove() { this.removed = true; } };
    const document = {
        activeElement: focus,
        body: { appendChild() {} },
        createElement: () => area,
        execCommand: () => fallback,
    };
    return { document, navigator: { clipboard }, isSecureContext: true, focus, area };
}

test('clipboard success copies the exact value without changing focus', async () => {
    const values = [];
    const env = environment({ clipboard: { writeText: async text => values.push(text) } });
    assert.equal(await copyAssistanceText('Subject & template', env), true);
    assert.deepEqual(values, ['Subject & template']);
    assert.equal(env.focus.restored, 0);
});

test('unavailable or rejected Clipboard API falls back and restores focus', async () => {
    for (const clipboard of [undefined, { writeText: async () => { throw new Error('Denied'); } }]) {
        const env = environment({ clipboard });
        assert.equal(await copyAssistanceText('Dear Support Team,\n\nTemplate', env), true);
        assert.equal(env.area.value, 'Dear Support Team,\n\nTemplate');
        assert.equal(env.area.removed, true);
        assert.equal(env.focus.restored, 1);
    }
});

test('false or throwing fallback reports failure and restores focus', async () => {
    const env = environment({ fallback: false });
    assert.equal(await copyAssistanceText('Email', env), false);
    env.document.execCommand = () => { throw new Error('Unavailable'); };
    assert.equal(await copyAssistanceText('Email', env), false);
    assert.equal(env.focus.restored, 2);
});

test('all three copy actions announce success through the live status', async () => {
    const values = { email: 'support@example.test', subject: 'Supplier Portal - Password Assistance Request', template: 'Dear Support Team,\n\nBody' };
    for (const [id, value] of Object.entries(values)) {
        let listener;
        const status = { textContent: '' };
        const button = { dataset: { passwordAssistanceCopy: id, copyLabel: id }, addEventListener: (_, handler) => { listener = handler; } };
        const container = { querySelectorAll: () => [button], querySelector: () => status };
        const root = { querySelectorAll: () => [container], getElementById: () => ({ textContent: value }) };
        const copied = [];
        const env = environment({ clipboard: { writeText: async text => copied.push(text) } });
        bootPasswordAssistance(root, env);
        await listener();
        assert.deepEqual(copied, [value]);
        assert.equal(status.textContent, `${id} copied.`);
    }
});

test('failed copy announces manual selection guidance without throwing', async () => {
    let listener;
    const status = { textContent: '' };
    const button = { dataset: { passwordAssistanceCopy: 'email', copyLabel: 'Email address' }, addEventListener: (_, handler) => { listener = handler; } };
    const container = { querySelectorAll: () => [button], querySelector: () => status };
    const root = { querySelectorAll: () => [container], getElementById: () => ({ textContent: 'support@example.test' }) };
    bootPasswordAssistance(root, environment({ fallback: false }));
    await listener();
    assert.match(status.textContent, /Could not copy.*select.*manually/i);
});
