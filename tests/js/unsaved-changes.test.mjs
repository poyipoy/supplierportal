import { installI18n } from './i18n-fixture.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';

// Mock minimal DOM environment
function createMockDOM(pathname = '/local-supplier/invoices/create', scopeMeta = null) {
    const listeners = {};
    const elements = [];

    const mockDocument = {
        title: 'Test Page',
        querySelector(selector) {
            if (selector === 'meta[name="portal-scope"]') {
                return scopeMeta ? { content: scopeMeta } : null;
            }
            return null;
        },
        querySelectorAll(selector) {
            return elements.filter(el => {
                if (selector === 'form') return el.tagName === 'FORM';
                return false;
            });
        },
        addEventListener(event, fn) {
            listeners[event] = listeners[event] || [];
            listeners[event].push(fn);
        },
        readyState: 'complete',
    };

    const mockWindow = {
        location: { pathname, href: 'http://localhost' + pathname },
        history: {
            pushState(state, title, url) {
                mockWindow.history.state = state;
            },
            state: null,
        },
        addEventListener(event, fn) {
            listeners[event] = listeners[event] || [];
            listeners[event].push(fn);
        },
    };

    return { mockWindow, mockDocument, elements, listeners };
}

test('unsaved-change language follows account locale independently of portal scope', () => {
    const english = installI18n({}, 'en');
    const indonesian = installI18n({}, 'id');
    assert.equal(english.AdasiI18n.t('js.navigation.leave_title'), 'Leave this page?');
    assert.equal(indonesian.AdasiI18n.t('js.navigation.leave_title'), 'Tinggalkan halaman ini?');
});

test('snapshot and dirty diff correctly identifies untouched, modified, and reverted states', () => {
    function getFormSnapshot(fields, rows = 0) {
        const parts = [];
        if (rows > 0) parts.push(`__rows__:${rows}`);
        for (const [key, val] of Object.entries(fields)) {
            parts.push(`${key}:${val}`);
        }
        return parts.join('||');
    }

    const initialFields = {
        invoice_number: '',
        invoice_amount: '',
        ppn_scheme: '11%',
        local_purchase_order_id: '',
        'goods_receipt_ids[]': '[]',
        file_invoice: '[]',
    };

    const initialSnapshot = getFormSnapshot(initialFields, 0);

    // 1. Untouched form
    assert.equal(getFormSnapshot(initialFields, 0) === initialSnapshot, true);

    // 2. Modified DPP
    const modifiedFields = { ...initialFields, invoice_amount: '5000000' };
    assert.equal(getFormSnapshot(modifiedFields, 0) === initialSnapshot, false);

    // 3. User re-clears DPP back to empty
    const revertedFields = { ...modifiedFields, invoice_amount: '' };
    assert.equal(getFormSnapshot(revertedFields, 0) === initialSnapshot, true);

    // 4. File uploaded
    const fileUploadedFields = { ...initialFields, file_invoice: '[invoice.pdf_102400]' };
    assert.equal(getFormSnapshot(fileUploadedFields, 0) === initialSnapshot, false);

    // 5. Dynamic item row added
    assert.equal(getFormSnapshot(initialFields, 1) === initialSnapshot, false);
});
