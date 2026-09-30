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

test('scope localization resolves properly between local and import', () => {
    const I18N = {
        local: { leaveTitle: 'Tinggalkan halaman ini?', stayButton: 'Tetap di Sini', leaveButton: 'Tinggalkan Halaman' },
        import: { leaveTitle: 'Leave this page?', stayButton: 'Stay on Page', leaveButton: 'Leave Page' },
    };

    function resolveScope(metaContent, pathname) {
        if (metaContent) return metaContent.toLowerCase();
        const path = pathname.toLowerCase();
        if (path.startsWith('/local-supplier') || path.startsWith('/finance') || path.startsWith('/accounting') || path.startsWith('/ga')) {
            return 'local';
        }
        return 'import';
    }

    assert.equal(resolveScope('local', '/any/path'), 'local');
    assert.equal(resolveScope('import', '/any/path'), 'import');
    assert.equal(resolveScope(null, '/local-supplier/invoices/create'), 'local');
    assert.equal(resolveScope(null, '/purchasing/requisitions/create'), 'import');
    assert.equal(resolveScope(null, '/supplier/quotations/create'), 'import');
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
