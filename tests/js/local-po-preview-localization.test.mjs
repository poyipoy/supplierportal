import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';

const source = readFileSync(new URL('../../resources/js/local-procurement-import.js', import.meta.url), 'utf8')
    .replace("import { t as globalT } from './i18n.js';", "const globalT = (...args) => window.AdasiI18n.t(...args);")
    .replace('export function bootLocalProcurementImports', 'function bootLocalProcurementImports');
class Element {
    children = []; dataset = {}; listeners = {}; value = ''; textContent = ''; disabled = false;
    classes = new Set();
    classList = {
        add: (...names) => names.forEach(name => this.classes.add(name)),
        remove: (...names) => names.forEach(name => this.classes.delete(name)),
        toggle: (name, force) => { if (force) this.classes.add(name); else this.classes.delete(name); },
        contains: name => this.classes.has(name),
    };
    setAttribute(name, value) { this[name] = value; }
    append(...children) { this.children.push(...children); }
    prepend(...children) { this.children.unshift(...children); }
    replaceChildren(...children) { this.children = children; }
    addEventListener(name, listener, options = {}) { (this.listeners[name] ??= []).push({ listener, once: options.once }); }
    cloneNode() { const clone = new Element(); clone.textContent = this.textContent; return clone; }
    scrollIntoView(options) { this.scrolledIntoView = options ?? true; }
    focus() { Element.focused = this; }
    // Template clones expose named slots (e.g. [data-chip-label]) as child elements.
    querySelector(selector) { if (!(this.parts ??= new Map()).has(selector)) this.parts.set(selector, new Element()); return this.parts.get(selector); }
    async fire(name, event = { preventDefault() {} }) {
        const listeners = [...(this.listeners[name] ?? [])];
        this.listeners[name] = listeners.filter(entry => !entry.once);
        for (const entry of listeners) await entry.listener(event);
    }
    dispatchEvent(event) { return this.fire(event.type, event); }
}
function boot(locale, kind = 'PO', jobs = null, bothKinds = false, { storage = new Map(), storageBlocked = false, confirmStatus = 'IMPORTING' } = {}) {
    const name = kind === 'PO' ? 'Po' : 'Gr';
    const elements = new Map();
    const created = [];
    const body = new Element();
    const requests = [];
    const timers = [];
    const responseWaits = new Map();
    const failures = new Set();
    let status = 'QUEUED';
    let summary = { source_rows: 70000, new_po: 3, existing_po: 2, new_gr: 277, existing_gr: 4 };
    let failure = null;
    let workerProgress = null;
    let validationAttempt = 1;
    let reloads = 0;
    const get = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
    const modal = get(`local${name}ImportModal`);
    Element.focused = null;
    const recovery = { panel: new Element(), list: new Element(), processingList: new Element(), followUpList: new Element(), processingGroup: new Element(), followUpGroup: new Element(), slots: new Element(), error: new Element(), announcer: new Element(), refresh: new Element() };
    recovery.panel.hidden = true; recovery.panel.dataset.slotLimit = '2'; recovery.error.hidden = true;
    const recoverySelectors = { '[data-import-recovery]': recovery.panel, '[data-recovery-list]': recovery.list, '[data-recovery-slots]': recovery.slots,
        '[data-recovery-error]': recovery.error, '[data-recovery-announcer]': recovery.announcer, '[data-recovery-refresh]': recovery.refresh,
        '[data-recovery-processing-list]': recovery.processingList, '[data-recovery-follow-up-list]': recovery.followUpList,
        '[data-recovery-processing-group]': recovery.processingGroup, '[data-recovery-follow-up-group]': recovery.followUpGroup };
    const template = selector => ({ content: { firstElementChild: { cloneNode: () => Object.assign(new Element(), { template: selector }) } } });
    modal.querySelector = selector => recoverySelectors[selector] ?? (selector.startsWith('[data-recovery-') ? template(selector) : body);
    modal.querySelectorAll = () => [...elements.entries()].filter(([id]) => id.startsWith(`kpi${name}`)).map(([, element]) => element);
    const form = get(`local${name}ImportConfirmForm`); form.querySelector = () => ({ value: 'csrf-token' });
    get(`local${name}ImportFile`).files = [{ name: 'sample.xlsx' }];
    const dropzone = { stagedFiles: ['sample.xlsx'], clientError: 'old error', clearAll() {
        this.stagedFiles = []; this.clientError = '';
        get(`local${name}ImportFile`).files = []; get(`local${name}ImportFile`).value = '';
    } };
    get(`local${name}ImportFile`).closest = () => dropzone;
    const config = new Element();
    config.dataset = { localImport: kind, modalId: `local${name}ImportModal`, importPreview: '/preview', importIndex: '/imports', previous: 'Previous', next: 'Next', cancel: 'Cancel import' };
    const configs = [config];
    if (bothKinds) {
        const otherName = name === 'Po' ? 'Gr' : 'Po';
        const otherConfig = new Element();
        otherConfig.dataset = { ...config.dataset, localImport: kind === 'PO' ? 'GR' : 'PO', modalId: `local${otherName}ImportModal` };
        configs.push(otherConfig);
        const otherModal = get(`local${otherName}ImportModal`);
        otherModal.querySelector = () => new Element();
        otherModal.querySelectorAll = () => [];
        get(`local${otherName}ImportConfirmForm`).querySelector = () => ({ value: 'csrf-token' });
        get(`local${otherName}ImportFile`).closest = () => null;
    }
    const document = { readyState: 'loading', hidden: false, addEventListener() {}, querySelectorAll: () => configs, getElementById: get,
        get activeElement() { return Element.focused; },
        createElement: tag => { const element = new Element(); element.tag = tag; created.push(element); return element; },
        createTextNode: text => ({ textContent: text }) };
    const window = installI18n({ location: { origin: 'https://portal.example', reload() { reloads++; }, assign() { reloads++; } } }, locale);
    window.Alpine = { $data: element => element };
    const toasts = [];
    const recordToast = type => (message, options = {}) => toasts.push({ type, message, ...(options.actions ? { actions: Array.from(options.actions, action => action.label) } : {}) });
    window.AdasiToast = { success: recordToast('success'), error: recordToast('error') };
    const dialogs = [];
    let confirmAnswer = true;
    const sharedI18n = window.AdasiI18n;
    const scopedMessages = Object.fromEntries([...readFileSync(new URL(`../../lang/${locale}/js.php`, import.meta.url), 'utf8').matchAll(/^\s*'([^']+)'\s*=>\s*'((?:\\.|[^'])*)',/gm)]
        .filter(match => match[1].startsWith('local_import.')).map(match => [`js.${match[1]}`, match[2].replace(/\\'/g, "'").replace(/\\\\/g, '\\')]));
    get('local-import-i18n').textContent = JSON.stringify(scopedMessages);
    window.AdasiI18n = { ...sharedI18n, t: (key, replacements) => key.startsWith('js.local_import.') ? key : sharedI18n.t(key, replacements) };
    window.AdasiAlert = { confirmDanger: async options => { dialogs.push(options); return { isConfirmed: confirmAnswer, isDismissed: !confirmAnswer }; } };
    window.sessionStorage = {
        getItem: key => (storage.has(key) ? storage.get(key) : null),
        setItem: (key, value) => { if (storageBlocked) throw new Error('QuotaExceededError'); storage.set(key, String(value)); },
        removeItem: key => { storage.delete(key); },
    };
    let hides = 0;
    const modalActions = [];
    window.bootstrap = { Modal: { getOrCreateInstance: element => ({
        hide() { hides++; modalActions.push(element.fire('hidden.bs.modal')); },
        show() { modalActions.push((async () => { await element.fire('show.bs.modal'); await element.fire('shown.bs.modal'); })()); },
    }) } };
    const malicious = '<img src=x onerror=alert(1)>';
    const job = entry => ({ created_at: '12 Oct 2026 17:14', processed_rows: 544, ...entry });
    let recentJobs = (jobs ?? [
        { kind: kind === 'PO' ? 'GR' : 'PO', filename: 'Other kind.xlsx', status: 'READY', status_url: '/imports/other-kind' },
        { kind, filename: malicious, status: 'READY', status_url: '/imports/current' },
        { kind, filename: 'Older file.xlsx', status: 'READY', status_url: '/imports/older' },
    ]).map(job);
    const row = { _row: 2, po_number: malicious, supplier_name: malicious, po_date: '2026-10-01', po_amount: '1000.00',
        gr_number: malicious, description: 'x" onmouseover="alert(1)', qty: '10.125', uom: 'kg', source_rows_count: 2, action: 'NEW',
        action_label: locale === 'en' ? `New ${kind}` : `${kind} Baru` };
    const payload = () => ({ progress: workerProgress, status, token: status === 'READY' ? (validationAttempt === 1 ? 'opaque-token' : `opaque-token-${validationAttempt}`) : null, processed_rows: 70000,
        validation_attempt: validationAttempt, can_revalidate: ['READY', 'VALIDATION_FAILED', 'FAILED'].includes(status), revalidate_url: '/imports/current/revalidate',
        failure: failure ?? (status === 'VALIDATION_FAILED' ? 'PO is closed.' : null),
        confirm_url: '/confirm', records_url: '/records', records_total: 70000, errors_total: status === 'VALIDATION_FAILED' ? 1 : 0, errors_url: '/errors',
        warnings: [{ message: malicious }], preview: { summary: { ...summary, ...(status === 'COMPLETED' ? { newPo: summary.newPo ?? 3, existingPo: summary.existingPo ?? 2, filledPoPr: summary.filledPoPr ?? 0, newGr: 277, existingGr: 4 } : {}) }, rows: Array.from({ length: 101 }, () => row), errors: status === 'VALIDATION_FAILED' ? [{ row: 8, column: 'qty', message: malicious }] : [] } });
    const context = { window, document, Set, URL, URLSearchParams, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } }, setTimeout: (fn, delay) => { timers.push(Object.assign(() => fn(), { delay, recovery: String(fn).includes('loadActiveImports') })); return timers.length; }, clearTimeout() {},
        FormData: class { values = new Map(); constructor(value) { if (value) { this.values.set('token', get(`local${name}ImportToken`).value); this.values.set('_token', 'csrf-token'); } } append(k, v) { this.values.set(k, v); } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (failures.delete(url)) return { ok: false, json: async () => ({ message: 'Server unavailable' }) };
            const wait = responseWaits.get(url);
            if (wait) { responseWaits.delete(url); await wait; }
            const response = url === '/preview' ? { status_url: '/imports/current' }
                : url === '/confirm' ? (status = confirmStatus, { status_url: '/imports/current' })
                    : url.startsWith('/imports?active=1&include_recoverable=1') ? recentJobs
                        : url.endsWith('/revalidate') ? (status = 'QUEUED', validationAttempt++, { status_url: '/imports/current', status: 'QUEUED', validation_attempt: validationAttempt })
                        : url.endsWith('/cancel') ? (recentJobs = recentJobs.filter(entry => `${entry.status_url}/cancel` !== url), { status: 'CANCELLED' })
                        : payload();
            return { ok: true, json: async () => response };
        } };
    vm.runInNewContext(source, context);
    context.bootLocalProcurementImports(document);
    // Status polling and the background watcher; the 5 s recovery-list refresh is tracked separately.
    const lastTimer = () => timers.filter(timer => !timer.recovery).at(-1);
    const recoveryRows = () => [...recovery.followUpList.children, ...recovery.processingList.children].map(item => {
        const [statusCell, text, actions] = item.children;
        const [chip] = statusCell.children;
        const [primary, secondary] = actions.children;
        return { url: item.dataset.importUrl, item, tone: chip.template, status: chip.querySelector('[data-chip-label]').textContent,
            file: text.children[0].textContent, meta: text.children[1].textContent, primary, primaryLabel: primary.querySelector('[data-button-label]').textContent,
            cancel: secondary?.template ? secondary : null, note: secondary?.template ? null : secondary };
    });
    return { setProgress: value => { workerProgress = value; }, get, created, requests, modal, form, row, timers, malicious, dropzone, toasts, storage, recovery, dialogs, recoveryRows,
        recoveryRow: url => recoveryRows().find(entry => entry.url === url), setConfirmAnswer: value => { confirmAnswer = value; },
        focused: () => Element.focused, failNext: url => { failures.add(url); },
        setSummary: value => { summary = value; }, setFailure: value => { failure = value; },
        lastTimer, tick: () => lastTimer()(), recoveryTimers: () => timers.filter(timer => timer.recovery),
        hides: () => hides, finishModalActions: async () => { for (const action of modalActions) await action; }, setStatus: value => { status = value; }, setAttempt: value => { validationAttempt = value; }, setJobs: value => { recentJobs = value.map(job); }, reloads: () => reloads,
        holdNextResponse: url => { let release; responseWaits.set(url, new Promise(resolve => { release = resolve; })); return release; } };
}
for (const locale of ['en', 'id']) for (const kind of ['PO', 'GR']) {
    test(`${locale} ${kind}: queue progress, safe text, bounded preview and explicit confirmation`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.get(`btnParseLocal${name}Import`).fire('click');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportToken`).value, '');
        app.setStatus('READY'); await app.tick();
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, false);
        assert.equal(app.get(`local${name}ImportToken`).value, 'opaque-token');
        const rows = app.get(`local${name}ImportPreviewBody`).children;
        assert.equal(rows.length, 100);
        assert.equal(rows[0].children[1].textContent, app.malicious);
        assert.equal(rows[0].children.at(-1).textContent, locale === 'en' ? `New ${kind}` : `${kind} Baru`);
        assert.ok(rows[0].children.every(cell => !Object.hasOwn(cell, 'innerHTML')));
        assert.equal(app.get(`local${name}ImportErrorsList`).children.length, 0);
        assert.equal(app.row.action, 'NEW'); assert.equal(app.row.qty, '10.125');
        await app.form.fire('submit');
        const confirmation = app.requests.find(request => request.url === '/confirm');
        assert.equal(confirmation.options.body.values.get('token'), 'opaque-token');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        app.setStatus('COMPLETED'); await app.tick(); await app.finishModalActions();
        assert.equal(app.hides(), 0);
        assert.equal(app.reloads(), 0);
        const reload = app.created.find(element => element.textContent === (locale === 'en' ? 'View import results' : 'Lihat hasil impor'));
        assert.equal(reload.hidden, false); await reload.fire('click'); assert.equal(app.reloads(), 1);
    });

    test(`${locale} ${kind}: opening lists unfinished imports without restoring a preview automatically`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        app.setStatus('READY');
        await app.modal.fire('show.bs.modal');
        assert.equal(app.created.some(element => element.tag === 'select' || element.tag === 'option'), false);
        assert.deepEqual(app.requests.map(request => request.url), ['/imports?active=1&include_recoverable=1']);
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportToken`).value, '');
        assert.equal(app.dropzone.clientError, '');
        assert.deepEqual(app.get(`local${name}ImportFile`).files, []);
    });

    test(`${locale} ${kind}: reopening discards the previous active UI without cancelling the backend batch`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.get(`btnParseLocal${name}Import`).fire('click');
        app.setJobs([{ kind, filename: 'Newer.xlsx', status_url: '/imports/newer' }]);
        const requestCount = app.requests.length;
        await app.modal.fire('hidden.bs.modal');
        await app.modal.fire('show.bs.modal');
        await app.tick();
        assert.equal(app.requests.length, requestCount + 1);
        assert.equal(app.requests.some(request => request.url.endsWith('/cancel')), false);
        assert.equal(app.get(`btnParseLocal${name}Import`).disabled, false);
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportResult`).classList.contains('d-none'), true);
    });

    test(`${locale} ${kind}: no recent batch leaves new upload available without a dropdown`, async () => {
        const app = boot(locale, kind, []);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.modal.fire('show.bs.modal');
        assert.equal(app.created.some(element => element.tag === 'select'), false);
        assert.deepEqual(app.requests.map(request => request.url), ['/imports?active=1&include_recoverable=1']);
        assert.equal(app.get(`btnParseLocal${name}Import`).disabled, false);
        app.get(`local${name}ImportFile`).files = [{ name: 'new.xlsx' }];
        await app.get(`btnParseLocal${name}Import`).fire('click');
        assert.ok(app.requests.some(request => request.url === '/preview'));
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
    });
}

test('closing and reopening clears a READY preview, token, messages, counters and selected file', async () => {
    const app = boot('en');
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY'); await app.tick();
    assert.equal(app.get('localPoImportToken').value, 'opaque-token');
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    assert.equal(app.get('localPoImportToken').value, '');
    assert.equal(app.get('localPoImportPreviewBody').children.length, 0);
    assert.equal(app.get('localPoImportErrorsList').children.length, 0);
    assert.equal(app.get('kpiPoTotalRows').textContent, '0');
    assert.equal(app.created.find(element => element.role === 'status').textContent, '');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    const requests = app.requests.length;
    await app.form.fire('submit');
    assert.equal(app.requests.length, requests);
});

test('a stale poll response cannot restore the old preview after the modal is reset', async () => {
    const app = boot('en');
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY');
    const release = app.holdNextResponse('/imports/current');
    const pending = app.tick();
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    release(); await pending;
    assert.equal(app.get('localPoImportToken').value, '');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    assert.equal(app.get('localPoImportPreviewBody').children.length, 0);
});

test('choosing another file releases the pending run and re-enables validation', async () => {
    const app = boot('en');
    await app.get('btnParseLocalPoImport').fire('click');
    assert.equal(app.get('btnParseLocalPoImport').disabled, true);
    app.get('localPoImportFile').files = [{ name: 'second.xlsx' }];
    await app.get('localPoImportFile').fire('change');
    assert.ok(app.requests.some(request => request.url === '/imports/current/cancel'));
    assert.equal(app.get('btnParseLocalPoImport').disabled, false);
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    assert.equal(app.get('localPoImportToken').value, '');
    assert.equal(app.get('localPoImportResult').classList.contains('d-none'), true);
    assert.deepEqual(app.get('localPoImportFile').files, [{ name: 'second.xlsx' }]);
    await app.get('btnParseLocalPoImport').fire('click');
    assert.equal(app.requests.filter(request => request.url === '/preview').length, 2);
});

test('choosing another file while records are being saved keeps the running import', async () => {
    const app = boot('id', 'GR');
    await app.get('btnParseLocalGrImport').fire('click');
    app.setStatus('READY'); await app.tick();
    await app.form.fire('submit');
    const requestCount = app.requests.length;
    await app.get('localGrImportFile').fire('change');
    assert.equal(app.requests.length, requestCount);
    assert.equal(app.requests.some(request => request.url.endsWith('/cancel')), false);
    assert.equal(app.get('localGrImportResult').classList.contains('d-none'), false);
    assert.match(app.created.find(element => element.role === 'status').textContent, /masih disimpan/);
});

test('a stale upload response cannot attach its batch to a newly opened modal', async () => {
    const app = boot('id', 'GR');
    const release = app.holdNextResponse('/preview');
    const pending = app.get('btnParseLocalGrImport').fire('click');
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    release(); await pending;
    assert.deepEqual(app.requests.map(request => request.url), ['/preview', '/imports?active=1&include_recoverable=1']);
    assert.equal(app.get('btnParseLocalGrImport').disabled, false);
    assert.equal(app.get('btnConfirmLocalGrImport').disabled, true);
});

test('a READY preview can be resumed after reopening and only confirms on explicit submit', async () => {
    const app = boot('en');
    app.setStatus('READY');
    await app.modal.fire('show.bs.modal');
    const resume = app.recoveryRow('/imports/current');
    assert.equal(resume.primaryLabel, 'Review file');
    assert.equal(app.get('localPoImportToken').value, '');
    await resume.primary.fire('click');
    assert.equal(app.get('localPoImportToken').value, 'opaque-token');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, false);
    assert.equal(app.requests.some(request => request.url === '/confirm'), false);
    assert.ok(app.created.some(element => element.textContent.includes(app.malicious)));
    await app.form.fire('submit');
    assert.ok(app.requests.some(request => request.url === '/confirm'));
});

test('an import of the other kind can be cancelled after confirming, freeing the shared slot without uploading', async () => {
    const app = boot('en', 'GR', [{ kind: 'PO', filename: 'Unfinished PO.xlsx', status: 'READY', status_url: '/imports/po' }]);
    await app.modal.fire('show.bs.modal');
    await app.recoveryRow('/imports/po').cancel.fire('click');
    assert.equal(app.dialogs.length, 1);
    assert.match(app.dialogs[0].text, /Unfinished PO\.xlsx/);
    assert.equal(app.dialogs[0].confirmText, 'Cancel import process');
    const request = app.requests.find(request => request.url === '/imports/po/cancel');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body.get('_token'), 'csrf-token');
    assert.equal(app.requests.some(request => request.url === '/preview'), false);
    assert.equal(app.recovery.panel.hidden, true);
});

test('declining the cancel confirmation keeps the import untouched', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Keep.xlsx', status: 'READY', status_url: '/imports/keep' }]);
    await app.modal.fire('show.bs.modal');
    app.setConfirmAnswer(false);
    await app.recoveryRow('/imports/keep').cancel.fire('click');
    assert.equal(app.dialogs.length, 1);
    assert.equal(app.requests.some(request => request.url.endsWith('/cancel')), false);
    assert.equal(app.recovery.panel.hidden, false);
    assert.ok(app.focused() === app.recoveryRow('/imports/keep').cancel, 'focus returns to the cancel trigger, not the page body');
});

test('after a confirmed cancel, focus lands on a stable control instead of the removed row', async () => {
    const app = boot('en', 'GR', [
        { kind: 'GR', filename: 'Drop.xlsx', status: 'READY', status_url: '/imports/drop' },
        { kind: 'PO', filename: 'Stay.xlsx', status: 'READY', status_url: '/imports/stay' },
    ]);
    await app.modal.fire('show.bs.modal');
    await app.recoveryRow('/imports/drop').cancel.fire('click');
    assert.ok(app.focused() === app.recovery.refresh, 'remaining list keeps focus on Refresh');
    await app.recoveryRow('/imports/stay').cancel.fire('click');
    assert.equal(app.recovery.panel.hidden, true);
    assert.ok(app.focused() === app.modal, 'an emptied list returns focus to the dialog');
});

test('a stale unfinished import response cannot repopulate a closed modal', async () => {
    const app = boot('en');
    const release = app.holdNextResponse('/imports?active=1&include_recoverable=1');
    const pending = app.modal.fire('show.bs.modal');
    await app.modal.fire('hidden.bs.modal');
    release(); await pending;
    assert.deepEqual(app.recovery.list.children, []);
    assert.equal(app.recovery.panel.hidden, true);
});

test('continuing a PO import from the GR modal opens its PO preview without automatic confirmation', async () => {
    const app = boot('en', 'GR', [{ kind: 'PO', filename: 'Pending PO.xlsx', status: 'READY', status_url: '/imports/po' }], true);
    app.setStatus('READY');
    await app.modal.fire('show.bs.modal');
    await app.recoveryRow('/imports/po').primary.fire('click');
    await app.finishModalActions();
    assert.equal(app.get('localPoImportToken').value, 'opaque-token');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, false);
    assert.equal(app.get('localGrImportToken').value, '');
    assert.equal(app.requests.some(request => request.url === '/confirm'), false);
});

test('imports being saved show a note instead of a cancel action', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Saving.xlsx', status: 'IMPORTING', status_url: '/imports/saving' }]);
    await app.modal.fire('show.bs.modal');
    const row = app.recoveryRow('/imports/saving');
    assert.equal(row.cancel, null);
    assert.equal(row.note.textContent, 'Saving — cannot be cancelled');
    assert.equal(row.primaryLabel, 'View progress');
});

test('the recovery panel stays hidden when there are no unfinished imports', async () => {
    const app = boot('en', 'GR', []);
    await app.modal.fire('show.bs.modal');
    assert.equal(app.recovery.panel.hidden, true);
    assert.deepEqual(app.recovery.list.children, []);
    assert.deepEqual(app.recoveryTimers(), []);
});

for (const locale of ['en', 'id']) {
    test(`${locale}: each unfinished import shows status, file, metadata and a status-specific action`, async () => {
        const app = boot(locale, 'GR', [
            { kind: 'GR', filename: 'Ready.xlsx', status: 'READY', status_url: '/imports/ready' },
            { kind: 'PO', filename: 'Queued.csv', status: 'QUEUED', status_url: '/imports/queued', processed_rows: 1204 },
        ]);
        await app.modal.fire('show.bs.modal');
        assert.equal(app.recovery.panel.hidden, false);
        assert.equal(app.recovery.slots.textContent, locale === 'en' ? '2 of 2 import slots in use' : '2 dari 2 slot import terpakai');
        const [ready, queued] = app.recoveryRows();
        assert.equal(ready.tone, '[data-recovery-chip="ready"]');
        assert.equal(queued.tone, '[data-recovery-chip="queued"]');
        assert.equal(ready.status, locale === 'en' ? 'Ready to save' : 'Siap disimpan');
        assert.equal(ready.file, 'Ready.xlsx');
        assert.equal(queued.meta, locale === 'en' ? 'PO · Uploaded 12 Oct 2026 17:14 · Rows: 1204' : 'PO · Diunggah 12 Oct 2026 17:14 · Baris: 1204');
        assert.equal(ready.primaryLabel, locale === 'en' ? 'Review file' : 'Tinjau file');
        assert.equal(queued.primaryLabel, locale === 'en' ? 'View progress' : 'Lihat progres');
        assert.ok(ready.cancel && queued.cancel);
    });
}

test('the list refreshes itself only while an import is still processing and announces readiness once', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Sept.xls', status: 'QUEUED', status_url: '/imports/sept' }]);
    await app.modal.fire('show.bs.modal');
    assert.equal(app.recoveryTimers().length, 1);
    app.setJobs([{ kind: 'GR', filename: 'Sept.xls', status: 'READY', status_url: '/imports/sept' }]);
    await app.recoveryTimers().at(-1)();
    assert.equal(app.recovery.announcer.textContent, 'Sept.xls is ready to confirm.');
    assert.equal(app.recoveryTimers().length, 1, 'no further refresh once every import is ready');
    await app.modal.fire('hidden.bs.modal');
    const requestCount = app.requests.length;
    await app.recoveryTimers().at(-1)();
    assert.equal(app.requests.length, requestCount, 'a closed modal never refreshes the list');
});

test('a refresh keeps keyboard focus on the same action', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Sept.xls', status: 'VALIDATING', status_url: '/imports/sept' }]);
    await app.modal.fire('show.bs.modal');
    app.recoveryRow('/imports/sept').cancel.focus();
    await app.recoveryTimers().at(-1)();
    assert.equal(app.recoveryRow('/imports/sept').cancel.querySelector('[data-button-label]').textContent, 'Cancel import');
    assert.ok(app.recoveryRow('/imports/sept').cancel === app.focused(), 'focus moves to the re-rendered cancel button');
});

test('a list that fails to load reveals the panel with an alert and keeps refresh available', async () => {
    const app = boot('en', 'GR');
    app.failNext('/imports?active=1&include_recoverable=1');
    await app.modal.fire('show.bs.modal');
    assert.equal(app.recovery.panel.hidden, false);
    assert.equal(app.recovery.error.hidden, false);
    assert.equal(app.recovery.error.textContent, 'Server unavailable');
    assert.equal(app.recovery.refresh.disabled, false);
});

const TOAST_KEY = 'adasi:local-import-toast';
const completedText = (locale, kind) => kind === 'PO' ? (locale === 'en'
    ? 'PO import completed: 3 new POs created; PR filled on 0 existing POs; 2 registered POs skipped.'
    : 'Impor PO selesai: 3 PO baru dibuat; PR diisi pada 0 PO terdaftar; 2 PO terdaftar dilewati.') : (locale === 'en'
    ? `${kind} import completed: ${kind === 'PO' ? 3 : 277} new ${kind} records created; ${kind === 'PO' ? 2 : 4} registered ${kind} records skipped.`
    : `Import ${kind} selesai: ${kind === 'PO' ? 3 : 277} ${kind} baru dibuat; ${kind === 'PO' ? 2 : 4} ${kind} terdaftar dilewati.`);
async function confirmUntil(app, kind, terminal) {
    const name = kind === 'PO' ? 'Po' : 'Gr';
    await app.get(`btnParseLocal${name}Import`).fire('click');
    app.setStatus('READY'); await app.tick();
    await app.form.fire('submit');
    app.setStatus(terminal); await app.tick();
    await app.finishModalActions();
}

for (const locale of ['en', 'id']) for (const kind of ['PO', 'GR']) {
    test(`${locale} ${kind}: a successful confirmation displays its result until explicit navigation`, async () => {
        const app = boot(locale, kind);
        await confirmUntil(app, kind, 'COMPLETED');
        assert.deepEqual(app.toasts, []);
        assert.equal(app.hides(), 0);
        assert.equal(app.reloads(), 0);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        assert.equal(app.get(`local${name}ImportSummaryMessage`).textContent, completedText(locale, kind));
        const action = app.created.find(element => element.textContent === (locale === 'en' ? 'View import results' : 'Lihat hasil impor'));
        assert.equal(action.hidden, false);
        await action.fire('click');
        assert.equal(app.reloads(), 1);
        assert.equal(app.storage.has(TOAST_KEY), false);
    });
}

for (const locale of ['en', 'id']) for (const kind of ['PO', 'GR']) {
    const name = kind === 'PO' ? 'Po' : 'Gr';
    const duplicateMessage = locale === 'en'
        ? 'All data is already registered. There is no new data to import.'
        : 'Seluruh data sudah terdaftar. Tidak ada data baru untuk diimpor.';
    test(`${locale} ${kind}: zero-new and incomplete READY previews cannot submit or resume into confirmation`, async () => {
        const app = boot(locale, kind);
        await app.get(`btnParseLocal${name}Import`).fire('click');
        app.setStatus('READY');
        for (const value of [0, undefined, null, '1', -1]) {
            app.setSummary({ [`new_${kind.toLowerCase()}`]: value, [`existing_${kind.toLowerCase()}`]: 10 });
            await app.tick();
            assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
            // Even a manually fired submit must not send a confirm request.
            await app.form.fire('submit');
            assert.equal(app.requests.some(request => request.url === '/confirm'), false);
        }
        app.setSummary({ [`new_${kind.toLowerCase()}`]: 0, [`existing_${kind.toLowerCase()}`]: 10 });
        await app.modal.fire('show.bs.modal');
        await app.recoveryRow('/imports/current').primary.fire('click');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        const progress = app.created.find(element => element.role === 'status');
        assert.equal(progress.textContent.split(duplicateMessage).length - 1, 1);
        assert.equal(app.get(`local${name}ImportPreviewBody`).children.length, 100);
        await app.form.fire('submit');
        assert.equal(app.requests.some(request => request.url === '/confirm'), false);
        assert.equal(app.reloads(), 0);
    });
    test(`${locale} ${kind}: duplicate preview failure is shown once and keeps the detail visible`, async () => {
        const app = boot(locale, kind);
        await app.get(`btnParseLocal${name}Import`).fire('click');
        app.setStatus('VALIDATION_FAILED');
        app.setFailure(duplicateMessage);
        app.setSummary({ [`new_${kind.toLowerCase()}`]: 0, [`existing_${kind.toLowerCase()}`]: 10 });
        await app.tick();
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportToken`).value, '');
        assert.equal(app.created.find(element => element.role === 'status').textContent.split(duplicateMessage).length - 1, 1);
        assert.equal(app.get(`local${name}ImportPreviewBody`).children.length, 100);
        assert.deepEqual(app.toasts, []);
        assert.equal(app.reloads(), 0);
    });
    test(`${locale} ${kind}: a failed confirm request restores availability only for a valid mixed preview`, async () => {
        const app = boot(locale, kind);
        await app.get(`btnParseLocal${name}Import`).fire('click');
        app.setStatus('READY'); await app.tick();
        app.failNext('/confirm');
        await app.form.fire('submit');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, false);
        app.setSummary({ [`new_${kind.toLowerCase()}`]: 0 });
        await app.tick();
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        await app.form.fire('submit');
        assert.equal(app.requests.filter(request => request.url === '/confirm').length, 1);
    });
    test(`${locale} ${kind}: a mixed import displays created and skipped counts without an automatic reload`, async () => {
        const app = boot(locale, kind);
        await confirmUntil(app, kind, 'COMPLETED');
        const name = kind === 'PO' ? 'Po' : 'Gr';
        assert.equal(app.get(`local${name}ImportSummaryMessage`).textContent, completedText(locale, kind));
        await app.modal.fire('hidden.bs.modal');
        assert.equal(app.reloads(), 0);
    });
}

for (const terminal of ['COMPLETED', 'VALIDATION_FAILED']) {
    test(`a worker that finishes before the first status poll is still announced (${terminal})`, async () => {
        const app = boot('en', 'GR', null, false, { confirmStatus: terminal });
        await app.get('btnParseLocalGrImport').fire('click');
        app.setStatus('READY'); await app.tick();
        await app.form.fire('submit');
        await app.finishModalActions();
        if (terminal === 'COMPLETED') {
            assert.equal(app.hides(), 0);
            assert.equal(app.reloads(), 0);
            assert.equal(app.get('localGrImportSummaryMessage').textContent, completedText('en', 'GR'));
        } else {
            assert.equal(app.hides(), 0);
            assert.deepEqual(app.toasts, [{ type: 'error', message: 'GR import was not saved: PO is closed.' }]);
        }
    });
}

test('expired or malformed handed-off toasts are discarded without showing anything', () => {
    for (const value of [JSON.stringify({ message: 'old', expires: Date.now() - 1 }), '{not json', JSON.stringify({ expires: Date.now() + 60000 })]) {
        const storage = new Map([[TOAST_KEY, value]]);
        assert.deepEqual(boot('en', 'GR', null, false, { storage }).toasts, []);
        assert.equal(storage.has(TOAST_KEY), false);
    }
});

test('a committed result does not depend on session storage', async () => {
    const app = boot('en', 'GR', null, false, { storageBlocked: true });
    await confirmUntil(app, 'GR', 'COMPLETED');
    assert.equal(app.reloads(), 0);
    assert.deepEqual(app.toasts, []);
    assert.equal(app.get('localGrImportSummaryMessage').textContent, completedText('en', 'GR'));
});

test('a confirmation rejected by the worker keeps the modal open and reports the reason', async () => {
    const app = boot('id', 'GR');
    await confirmUntil(app, 'GR', 'VALIDATION_FAILED');
    assert.deepEqual(app.toasts, [{ type: 'error', message: 'Import GR tidak tersimpan: PO is closed.' }]);
    assert.equal(app.hides(), 0);
    assert.equal(app.reloads(), 0);
    assert.ok(app.created.find(element => element.role === 'status' && element['aria-live'] === 'polite').scrolledIntoView);
    assert.equal(app.get('btnParseLocalGrImport').disabled, false);
    assert.equal(app.get('btnConfirmLocalGrImport').disabled, true);
});

test('resuming an import that is still being saved displays the result when it completes', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Saving.xlsx', status: 'IMPORTING', status_url: '/imports/current' }]);
    app.setStatus('IMPORTING');
    await app.modal.fire('show.bs.modal');
    await app.recoveryRow('/imports/current').primary.fire('click');
    app.setStatus('COMPLETED'); await app.tick(); await app.finishModalActions();
    assert.equal(app.reloads(), 0);
    assert.equal(app.get('localGrImportSummaryMessage').textContent, completedText('en', 'GR'));
});

for (const [terminal, expectReload] of [['COMPLETED', true], ['FAILED', false]]) {
    test(`closing the modal while saving keeps watching in the background (${terminal})`, async () => {
        const app = boot('en', 'GR');
        await app.get('btnParseLocalGrImport').fire('click');
        app.setStatus('READY'); await app.tick();
        await app.form.fire('submit');
        await app.modal.fire('hidden.bs.modal');
        const before = app.requests.filter(request => request.url === '/imports/current').length;
        app.setStatus(terminal); await app.tick();
        assert.equal(app.requests.filter(request => request.url === '/imports/current').length, before + 1);
        assert.equal(app.reloads(), 0);
        if (expectReload) assert.deepEqual(app.toasts, [{type:'success',message:completedText('en','GR'),actions:['View import results']}]);
        else assert.deepEqual(app.toasts, [{ type: 'error', message: 'GR import was not saved: Processing failed' }]);
    });
}

test('a background completion never reloads over a new preview in progress', async () => {
    const app = boot('en', 'GR');
    await app.get('btnParseLocalGrImport').fire('click');
    app.setStatus('READY'); await app.tick();
    await app.form.fire('submit');
    await app.modal.fire('hidden.bs.modal');
    const watcherTick = app.lastTimer();
    await app.modal.fire('show.bs.modal');
    app.get('localGrImportFile').files = [{ name: 'next.xlsx' }];
    await app.get('btnParseLocalGrImport').fire('click');
    app.setStatus('COMPLETED'); await watcherTick();
    assert.equal(app.reloads(), 0);
    assert.deepEqual(app.toasts, [{ type: 'success', message: completedText('en', 'GR'), actions: ['View import results'] }]);
});

test('resuming the import being watched stops the background watcher', async () => {
    const app = boot('en', 'GR', [{ kind: 'GR', filename: 'Saving.xlsx', status: 'IMPORTING', status_url: '/imports/current' }]);
    await app.get('btnParseLocalGrImport').fire('click');
    app.setStatus('READY'); await app.tick();
    await app.form.fire('submit');
    await app.modal.fire('hidden.bs.modal');
    const watcherTick = app.lastTimer();
    await app.modal.fire('show.bs.modal');
    await app.recoveryRow('/imports/current').primary.fire('click');
    const before = app.requests.length;
    await watcherTick();
    assert.equal(app.requests.length, before);
});

test('import confirmation forms opt out of the global submit guard that re-enables buttons after 10 seconds', () => {
    for (const [file, name] of [['po', 'Po'], ['gr', 'Gr']]) {
        const blade = readFileSync(new URL(`../../resources/views/finance/local-procurement/_import_${file}_modal.blade.php`, import.meta.url), 'utf8');
        assert.match(blade, new RegExp(`<form id="local${name}ImportConfirmForm"[^>]*\\bdata-managed-submit\\b`));
    }
});

for (const locale of ['en', 'id']) {
    test(`${locale}: queued progress has no false percentage, then saving uses worker record counts`, async () => {
        const app = boot(locale, 'GR', []);
        await app.get('btnParseLocalGrImport').fire('click');
        let meter = app.created.filter(element => element.role === 'progressbar').at(-1);
        assert.equal(meter['aria-valuenow'], undefined);
        app.setStatus('IMPORTING');
        app.setProgress({ phase: 'SAVING', mode: 'determinate', current: 500, total: 1200, percent: 41, execution: 'worker-a' });
        await app.tick();
        meter = app.created.filter(element => element.role === 'progressbar').at(-1);
        assert.equal(meter['aria-valuenow'], '41');
        assert.equal(app.created.find(element => element.role === 'status').textContent, locale === 'en' ? 'Saving records' : 'Menyimpan record');
        assert.ok(app.created.some(element => /500.*1[.,]?200 GR/.test(element.textContent)));
        assert.ok(app.requests.some(request => request.url.endsWith('?progress_only=1')));
        app.setProgress({ phase: 'FINALIZING', mode: 'indeterminate', current: 1200, total: 1200, percent: null, execution: 'worker-a' });
        await app.tick();
        assert.equal(app.created.filter(element => element.role === 'progressbar').at(-1)['aria-valuenow'], undefined);
        assert.equal(app.hides(), 0);
        assert.equal(app.created.find(element => element.role === 'status').textContent, locale === 'en' ? 'Finalizing the import' : 'Menyelesaikan penyimpanan');
    });
}

test('a failed status poll retains locked confirmation and exposes a retry action', async () => {
    const app = boot('en', 'PO', []);
    await app.get('btnParseLocalPoImport').fire('click');
    app.failNext('/imports/current?progress_only=1');
    await app.tick();
    assert.equal(app.get('btnParseLocalPoImport').disabled, true);
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    const retry = app.created.find(element => element.textContent === 'Retry status');
    assert.equal(retry.hidden, false);
    await retry.fire('click');
    assert.equal(retry.hidden, true);
});

test('rechecking a stored file does not need a File object and clears the old token', async () => {
    const app = boot('en', 'GR', []);
    await app.get('btnParseLocalGrImport').fire('click');
    app.setStatus('VALIDATION_FAILED'); await app.tick();
    app.get('localGrImportFile').files = [];
    await app.get('btnRevalidateLocalGrImport').fire('click');
    const request = app.requests.find(entry => entry.url.endsWith('/revalidate'));
    assert.ok(request);
    assert.equal(request.options.body.get('expected_attempt'), '1');
    assert.equal(app.get('localGrImportToken').value, '');
    assert.equal(app.get('btnConfirmLocalGrImport').disabled, true);
    app.setStatus('READY'); await app.tick();
    assert.equal(app.get('localGrImportToken').value, 'opaque-token-2');
});

test('a newer validation attempt forces a full status read even when READY remains READY', async () => {
    const app = boot('en', 'PO', []);
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY'); await app.tick();
    const before = app.requests.filter(entry => entry.url === '/imports/current').length;
    app.setAttempt(2);
    const retry = app.created.find(element => element.textContent === 'Retry status');
    await retry.fire('click');
    assert.equal(app.get('localPoImportToken').value, 'opaque-token-2');
    assert.ok(app.requests.filter(entry => entry.url === '/imports/current').length > before);
});

test('recoverable validation failures do not occupy active import slots', async () => {
    const app = boot('en', 'PO', [
        { kind: 'PO', filename: 'Running.xlsx', status: 'QUEUED', status_url: '/imports/running' },
        { kind: 'GR', filename: 'Fix.xlsx', status: 'VALIDATION_FAILED', can_revalidate: true, status_url: '/imports/fix' },
    ]);
    await app.modal.fire('show.bs.modal');
    assert.equal(app.recovery.slots.textContent, '1 of 2 import slots in use');
    assert.ok(app.recoveryRows().some(row => row.file === 'Fix.xlsx'));
});

test('successful save stays visible until the user chooses the result action', async () => {
    const app = boot('en', 'GR', []);
    await confirmUntil(app, 'GR', 'COMPLETED');
    assert.equal(app.reloads(), 0);
    assert.equal(app.hides(), 0);
    assert.equal(app.get('btnConfirmLocalGrImport').hidden, true);
    assert.ok(app.created.some(element => element.textContent === 'View import results' && !element.hidden));
});

test('grouped imports use distinct file descriptions and failed files are in the follow-up group', async () => {
    const app=boot('en','GR',[
        {kind:'GR',filename:'Queued.xlsx',status:'QUEUED',status_url:'/imports/q'},
        {kind:'GR',filename:'Fix.xlsx',status:'VALIDATION_FAILED',can_revalidate:true,status_url:'/imports/f'},
    ]);
    await app.modal.fire('show.bs.modal');
    assert.equal(app.recovery.processingList.children.length,1);
    assert.equal(app.recovery.followUpList.children.length,1);
    assert.equal(app.recovery.processingGroup.hidden,false);
    assert.equal(app.recovery.followUpGroup.hidden,false);
    const descriptions=app.recoveryRows().map(row=>row.primary['aria-describedby']);
    assert.equal(new Set(descriptions).size,2);
});

test('a late page response from an earlier attempt cannot replace the new review', async () => {
    const app=boot('en','GR',[]);
    await app.get('btnParseLocalGrImport').fire('click');app.setStatus('READY');await app.tick();
    const release=app.holdNextResponse('/records?page=2');
    const next=app.created.find(e=>e.textContent==='Next');
    const pending=next.fire('click');
    app.setAttempt(2);
    const retry=app.created.find(e=>e.textContent==='Retry status');await retry.fire('click');
    release();await pending;
    assert.equal(app.get('localGrImportToken').value,'opaque-token-2');
    assert.equal(app.get('localGrImportPreviewBody').children.length,100);
});

test('a rejected recheck restores server state instead of reusing a client token', async () => {
    const app=boot('en','GR',[]);
    await app.get('btnParseLocalGrImport').fire('click');app.setStatus('READY');await app.tick();
    app.failNext('/imports/current/revalidate');
    await app.get('btnRevalidateLocalGrImport').fire('click');
    assert.equal(app.get('localGrImportToken').value,'opaque-token');
    assert.equal(app.get('btnRevalidateLocalGrImport').disabled,false);
    assert.ok(app.requests.filter(r=>r.url==='/imports/current').length>=2);
});

for (const locale of ['en', 'id']) {
    test(`${locale} PO: filling an empty PR is actionable without creating another PO`, async () => {
        const app = boot(locale, 'PO', []);
        app.setSummary({ source_rows: 1, new_po: 0, po_pr_filled: 1, existing_po: 1, invalid: 0,
            newPo: 0, filledPoPr: 1, existingPo: 1 });
        app.row.action = 'FILL_PR';
        app.row.pr_number = 'PR-001';
        app.row.action_label = locale === 'en' ? 'Fill PR number' : 'Isi nomor PR';
        await app.get('btnParseLocalPoImport').fire('click');
        app.setStatus('READY'); await app.tick();
        assert.equal(app.get('btnConfirmLocalPoImport').disabled, false);
        assert.equal(app.get('kpiPoNew').textContent, '0');
        assert.equal(app.get('kpiPoPrFilled').textContent, '1');
        assert.match(app.get('localPoImportSummaryMessage').textContent, locale === 'en' ? /0 new POs and 1 existing POs/ : /0 PO baru dan 1 PO terdaftar/);
        const row = app.get('localPoImportPreviewBody').children[0];
        assert.equal(row.children[2].textContent, 'PR-001');
        assert.equal(row.children.at(-1).textContent, app.row.action_label);
        await app.form.fire('submit');
        app.setStatus('COMPLETED'); await app.tick(); await app.finishModalActions();
        assert.equal(app.get('localPoImportSummaryMessage').textContent, locale === 'en'
            ? 'PO import completed: 0 new POs created; PR filled on 1 existing POs; 0 registered POs skipped.'
            : 'Impor PO selesai: 0 PO baru dibuat; PR diisi pada 1 PO terdaftar; 0 PO terdaftar dilewati.');
        assert.equal(app.reloads(), 0);
    });

    test(`${locale} PO: PR text stays escaped and legacy previews show a translated empty value`, async () => {
        const app = boot(locale, 'PO', []);
        await app.get('btnParseLocalPoImport').fire('click');
        app.setStatus('READY'); await app.tick();
        assert.equal(app.get('localPoImportPreviewBody').children[0].children[2].textContent, '-');
        assert.equal(app.get('btnConfirmLocalPoImport').disabled, false, 'legacy summaries omit the PR fill count');
        app.row.pr_number = app.malicious;
        const retry = app.created.find(element => element.textContent === (locale === 'en' ? 'Retry status' : 'Coba ambil status lagi'));
        app.setAttempt(2); await retry.fire('click');
        const cell = app.get('localPoImportPreviewBody').children[0].children[2];
        assert.equal(cell.textContent, app.malicious);
        assert.equal(Object.hasOwn(cell, 'innerHTML'), false);
        assert.equal(cell.style, 'max-width: 16rem; overflow-wrap: anywhere;');
    });
}

test('PO: malformed or negative PR fill counts never enable confirmation', async () => {
    const app = boot('en', 'PO', []);
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY');
    for (const count of ['1', -1, 0.5]) {
        app.setSummary({ new_po: 3, po_pr_filled: count, existing_po: 1, invalid: 0 });
        await app.tick();
        assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
        assert.equal(app.get('localPoImportToken').value, '');
    }
});
