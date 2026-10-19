import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { notificationPreferencesForm } from '../../resources/js/notification-preferences.js';
import { supplierAuditForm } from '../../resources/js/supplier-audit-form.js';

const guardSource = readFileSync(new URL('../../resources/js/unsaved-changes.js', import.meta.url), 'utf8');

function fixture(t, forms = []) {
    class Form extends EventTarget {
        method = 'post';
        value = 'initial';
        matches(selector) { return selector.includes('data-track-unsaved="true"'); }
        getAttribute() { return '/save'; }
        querySelectorAll(selector) {
            return selector === 'input, select, textarea' ? [{ tagName: 'INPUT', type: 'text', name: 'field', value: this.value, disabled: false, hasAttribute: () => false, closest: () => null }] : [];
        }
    }
    const form = new Form();
    const document = Object.assign(new EventTarget(), {
        title: 'Unsaved state fixture', readyState: 'complete',
        querySelectorAll: () => forms.length ? forms : [form], querySelector: () => null,
    });
    const window = new EventTarget();
    const calls = { dialogs: 0, reloads: 0, native: [] };
    let answer = { isConfirmed: false };
    const timers = new Map();
    let timerId = 0;
    const setTimer = (callback) => { timers.set(++timerId, callback); return timerId; };
    Object.assign(window, {
        document, AdasiI18n: { t: (key) => key },
        history: { pushState() {}, back() {} },
        location: { href: 'http://localhost/profile/notifications', reload() {
            calls.reloads += 1;
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            calls.native.push(event.defaultPrevented);
        } },
        AdasiAlert: { confirmDanger: () => { calls.dialogs += 1; return Promise.resolve(answer); } },
    });
    vm.runInNewContext(guardSource, { window, document, HTMLFormElement: Form, setTimeout: setTimer, clearTimeout: (id) => timers.delete(id) }, {
        filename: fileURLToPath(new URL('../../resources/js/unsaved-changes.js', import.meta.url)),
    });
    const original = { window: globalThis.window, document: globalThis.document, setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout };
    Object.assign(globalThis, { window, document, setTimeout: setTimer, clearTimeout: (id) => timers.delete(id) });
    const cleanups = [];
    t.after(() => {
        try { cleanups.splice(0).forEach((cleanup) => cleanup()); }
        finally { Object.assign(globalThis, original); }
    });
    const shortcut = (key = 'F5', modifiers = {}) => {
        const event = new Event('keydown', { cancelable: true });
        Object.assign(event, { key, ...modifiers });
        window.dispatchEvent(event);
        return event;
    };
    return { window, document, form, calls, guard: window.AdasiUnsaved, shortcut, cleanup: (callback) => cleanups.push(callback), setAnswer: (value) => { answer = value; } };
}

function notifications(f) {
    const component = notificationPreferencesForm({
        meta: { item: { search: 'item', scope: 'general', action: false }, off: { search: 'off', scope: 'general', action: false } },
        categories: { main: ['item', 'off'] }, state: { item: { inbox: true, popup: true }, off: { inbox: false, popup: true } },
    });
    component.$root = f.form;
    component.init();
    return component;
}

function audit(f) {
    const component = supplierAuditForm({ answers: { 1: { answer: null, score: null } }, sections: [], labels: {} });
    component.$root = { querySelectorAll: () => [] };
    component.$refs = { form: f.form };
    component.$watch = () => {};
    component.init();
    return component;
}

test('component dirty sources remain independent and unregister without hiding other changes', (t) => {
    const f = fixture(t);
    let first = true;
    const unregisterFirst = f.guard.registerDirtySource(() => first);
    const unregisterSecond = f.guard.registerDirtySource(() => true);
    assert.equal(f.guard.isDirty(), true);
    first = false;
    unregisterFirst();
    assert.equal(f.guard.isDirty(), true);
    unregisterSecond();
    assert.equal(f.guard.isDirty(), false);
    f.guard.markDirty();
    const unregister = f.guard.registerDirtySource(() => false);
    unregister();
    assert.equal(f.guard.isDirty(), true, 'manual dirty state survives provider cleanup');
});

test('registered component form is authoritative while unrelated form snapshot changes still count', (t) => {
    const f = fixture(t);
    const unregister = f.guard.registerDirtySource(() => false, f.form);
    f.form.value = 'component projection changed';
    assert.equal(f.guard.isDirty(), false, 'component effective state overrides its raw DOM projection');
    const other = new f.form.constructor();
    f.document.querySelectorAll = () => [f.form, other];
    f.guard.resetSnapshot(other);
    other.value = 'unrelated edit';
    assert.equal(f.guard.isDirty(), true, 'unregistered form remains protected independently');
    other.value = 'initial';
    assert.equal(f.guard.isDirty(), false);
    unregister();
    f.form.value = 'another user edit';
    assert.equal(f.guard.isDirty(), true);
});

for (const [key, modifiers] of [['F5', {}], ['r', { ctrlKey: true }], ['R', { metaKey: true }]]) {
    test(`notification preset routes ${key} to custom confirmation and Discard/filter remain clean`, async (t) => {
        const f = fixture(t);
        const component = notifications(f);
        f.cleanup(() => component.destroy());
        component.query = 'item';
        component.applyFilters();
        assert.equal(f.guard.isDirty(), false);
        component.applyPreset('quiet');
        assert.equal(f.shortcut(key, modifiers).defaultPrevented, true);
        await Promise.resolve();
        assert.equal(f.calls.dialogs, 1);
        assert.equal(f.calls.reloads, 0, 'Cancel preserves edited state');
        assert.equal(component.dirtyCount, 2);
        component.discard();
        assert.equal(f.guard.isDirty(), false);
        assert.equal(f.shortcut(key, modifiers).defaultPrevented, false);
    });
}

test('notification bulk state and effective popup semantics use component truth; destroy releases ownership', (t) => {
    const f = fixture(t);
    const component = notifications(f);
    component.setPopup('off', false);
    assert.equal(f.guard.isDirty(), false, 'popup change on disabled inbox changes no effective delivery');
    component.setBulk('main', 'inbox', false);
    assert.equal(f.guard.isDirty(), true);
    component.discard();
    assert.equal(f.guard.isDirty(), false);
    component.setInbox('item', false);
    component.destroy();
    assert.equal(f.guard.isDirty(), false, 'destroy removes component source');
});

test('audit pending, inflight, failed and expired saves protect shortcuts; successful save clears guard', async (t) => {
    const f = fixture(t);
    const component = audit(f);
    f.cleanup(() => component.destroy());
    component.setAnswer('1', 'NO');
    assert.equal(f.shortcut().defaultPrevented, true);
    component.pending = {};
    component.inflight = true;
    assert.equal(f.guard.isDirty(), true, 'inflight save still owns unsaved state');
    component.inflight = false;
    component.saveState = 'saved';
    assert.equal(f.guard.isDirty(), false);
    for (const state of ['failed', 'expired']) {
        component.pending = { 1: { answer: 'NO', score: null } };
        component.saveState = state;
        assert.equal(f.guard.isDirty(), true, `${state} pending answer must remain protected`);
    }
    component.pending = {};
    assert.equal(f.guard.isDirty(), false);
    await Promise.resolve();
});

test('confirmed state-only reload runs once without a second native prompt', async (t) => {
    const f = fixture(t);
    const component = audit(f);
    f.cleanup(() => component.destroy());
    component.pending = { 1: { answer: 'NO', score: null } };
    f.setAnswer({ isConfirmed: true });
    assert.equal(f.shortcut().defaultPrevented, true);
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(f.calls.dialogs, 1);
    assert.equal(f.calls.reloads, 1);
    assert.deepEqual(f.calls.native, [false]);
});

test('actual audit autosave lifecycle retains failures and releases successfully persisted answers', async (t) => {
    const f = fixture(t);
    const component = audit(f);
    f.cleanup(() => component.destroy());
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    let resolveSave;
    globalThis.fetch = () => new Promise((resolve) => { resolveSave = resolve; });
    component.setAnswer('1', 'NO');
    const saving = component.flush();
    assert.equal(component.inflight, true);
    assert.equal(f.guard.isDirty(), true);
    resolveSave({ ok: true, json: async () => ({ saved_label: '12:00' }) });
    await saving;
    assert.equal(f.guard.isDirty(), false);
    for (const status of [500, 419]) {
        globalThis.fetch = async () => ({ ok: false, status });
        component.setAnswer('1', status === 500 ? 'YES' : 'NO');
        await component.flush();
        assert.equal(component.saveState, status === 500 ? 'failed' : 'expired');
        assert.equal(f.guard.isDirty(), true, 'unsaved answer survives failed/expired autosave');
    }
});

test('repeated reload shortcut while confirmation is pending opens one dialog', async (t) => {
    const f = fixture(t);
    let resolve;
    f.guard.markDirty();
    f.setAnswer(new Promise((done) => { resolve = done; }));
    assert.equal(f.shortcut().defaultPrevented, true);
    assert.equal(f.shortcut().defaultPrevented, true);
    await Promise.resolve();
    assert.equal(f.calls.dialogs, 1);
    resolve({ isConfirmed: false });
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(f.calls.reloads, 0);
    assert.equal(f.guard.isDirty(), true);
});

test('audit rejected answers remain protected after 422 drains queue until correction is saved', async (t) => {
    const f = fixture(t);
    const component = audit(f);
    f.cleanup(() => component.destroy());
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    globalThis.fetch = async () => ({
        ok: false, status: 422,
        json: async () => ({ errors: { 'answers.1.score': ['Score is required.'] } }),
    });
    component.setAnswer('1', 'YES');
    await component.flush();
    assert.equal(component.hasUnsaved(), false, 'rejected row is deliberately removed from retry queue');
    assert.equal(component.serverErrors['1'], 'Score is required.');
    assert.equal(f.guard.isDirty(), true, 'displayed rejected answer is still unpersisted');
    const native = new Event('beforeunload', { cancelable: true });
    f.window.dispatchEvent(native);
    assert.equal(native.defaultPrevented, true);
    assert.equal(f.shortcut().defaultPrevented, true);
    await Promise.resolve();
    assert.equal(f.calls.dialogs, 1);
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ saved_label: '12:01' }) });
    component.setScore('1', 3);
    assert.equal(f.guard.isDirty(), true, 'corrected answer remains pending until saved');
    await component.flush();
    assert.equal(f.guard.isDirty(), false);
});

test('audit submission failure/invalid recovery restores custom and native reload protection', (t) => {
    const f = fixture(t);
    const component = audit(f);
    f.cleanup(() => component.destroy());
    component.pending = { 1: { answer: 'NO', score: null } };
    for (const recovery of ['adasi:form-settled', 'invalid']) {
        component.submitting = true;
        f.guard.setSubmitting(true);
        assert.equal(f.guard.isDirty(), false, 'deliberate submission proceeds without a prompt');
        f.form.dispatchEvent(new Event(recovery));
        assert.equal(component.submitting, false);
        assert.equal(f.guard.isDirty(), true, 'failed submission restores unsaved-source protection');
        const native = new Event('beforeunload', { cancelable: true });
        f.window.dispatchEvent(native);
        assert.equal(native.defaultPrevented, true, 'toolbar/close still has native fallback');
    }
});

test('notification cancelled submit and invalid/settled recovery restore component protection', async (t) => {
    const f = fixture(t);
    const component = notifications(f);
    f.cleanup(() => component.destroy());
    component.applyPreset('quiet');
    f.document.addEventListener('submit', (event) => event.preventDefault());
    const submit = new Event('submit', { cancelable: true });
    f.document.dispatchEvent(submit);
    assert.equal(component.isSubmitting, true);
    assert.equal(f.guard.isDirty(), false);
    await Promise.resolve();
    assert.equal(component.isSubmitting, false, 'cancelled form submission re-arms protection');
    assert.equal(f.guard.isDirty(), true);
    for (const recovery of ['invalid', 'adasi:form-settled']) {
        component.isSubmitting = true;
        f.guard.setSubmitting(true);
        f.form.dispatchEvent(new Event(recovery));
        assert.equal(component.isSubmitting, false);
        assert.equal(f.guard.isDirty(), true);
    }
});

test('notification and audit repeated init/destroy keep one owned dirty source and allow remount', (t) => {
    const f = fixture(t);
    const notification = notifications(f);
    const auditForm = audit(f);
    f.cleanup(() => notification.destroy());
    f.cleanup(() => auditForm.destroy());
    const notificationCleanups = notification.cleanups.length;
    const auditCleanups = auditForm.cleanups.length;
    notification.init();
    auditForm.init();
    assert.equal(notification.cleanups.length, notificationCleanups);
    assert.equal(auditForm.cleanups.length, auditCleanups);
    notification.applyPreset('quiet');
    auditForm.pending = { 1: { answer: 'NO', score: null } };
    notification.destroy();
    notification.destroy();
    assert.equal(f.guard.isDirty(), true, 'other mounted component remains protected');
    auditForm.destroy();
    auditForm.destroy();
    assert.equal(f.guard.isDirty(), false);
    notification.init();
    assert.equal(f.guard.isDirty(), true, 'remount registers current component state');
});
