import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/local-invoices/form.blade.php', import.meta.url), 'utf8');

// Extract the script section that sets up localPoGr and updateValidation
const startToken = 'const localPoGr =';
const endToken = '// Filename Autofill for Invoice Number';
const scriptSection = view.slice(view.indexOf(startToken), view.indexOf(endToken))
    .replace("@json($localPoGrMap)", JSON.stringify({
        '1': {
            po_id: 1,
            po_number: 'PO-001',
            pr_number: 'PR-001',
            total_amount: 1000000,
            remaining_ceiling: 1000000,
            grs: [],
        },
        '2': {
            po_id: 2,
            po_number: 'PO-002',
            pr_number: 'PR-002',
            total_amount: 500000,
            remaining_ceiling: 200000,
            grs: [],
        },
    }))
    .replace("@json($selectedGrs)", "[]")
    .replace(/@js\((__\('[^']+'(?:\s*,\s*\[[^\]]*\])?\))\)/g, '"$1"');

function boot() {
    const elements = new Map();

    function element(id) {
        if (!elements.has(id)) {
            const classes = new Set(['tw:hidden']);
            const listeners = new Map();
            const attrs = new Map();
            let innerHtml = '';
            const child = {
                textContent: '',
                append() {},
            };
            elements.set(id, {
                id,
                value: '',
                textContent: '',
                title: '',
                disabled: false,
                attributes: attrs,
                get innerHTML() {
                    return innerHtml;
                },
                set innerHTML(val) {
                    innerHtml = val;
                },
                get firstElementChild() {
                    return child;
                },
                setAttribute(name, val) {
                    attrs.set(name, String(val));
                    if (name === 'disabled') this.disabled = true;
                },
                removeAttribute(name) {
                    attrs.delete(name);
                    if (name === 'disabled') this.disabled = false;
                    if (name === 'title') this.title = '';
                },
                hasAttribute(name) {
                    return attrs.has(name);
                },
                getAttribute(name) {
                    return attrs.get(name) || null;
                },
                classList: {
                    add: val => classes.add(val),
                    remove: val => classes.delete(val),
                    toggle: (val, force) => force ? classes.add(val) : classes.delete(val),
                    contains: val => classes.has(val),
                },
                addEventListener: (name, fn) => listeners.set(name, fn),
                dispatchEvent(event) {
                    listeners.get(event.type)?.call(this, event);
                },
            });
        }
        return elements.get(id);
    }

    const context = {
        document: {
            getElementById: element,
            createElement: () => ({ setAttribute() {}, append() {}, classList: { add() {} } }),
            createTextNode: text => ({ text }),
        },
        window: {
            dispatchEvent() {},
        },
        CustomEvent: class {
            constructor(type, detail) {
                this.type = type;
                this.detail = detail;
            }
        },
        Event: class {
            constructor(type) {
                this.type = type;
            }
        },
    };

    vm.runInNewContext(scriptSection, context);

    return {
        element,
        setPo(val) {
            const el = element('local_purchase_order_id');
            el.value = val;
            el.dispatchEvent({ type: 'change' });
        },
        setDpp(val) {
            const el = element('invoice-amount');
            el.value = val;
            el.dispatchEvent({ type: 'input' });
        },
        updateValidation: context.updateValidation,
    };
}

test('submit button is disabled when invoice DPP exceeds PO remaining ceiling', () => {
    const app = boot();
    const submitBtn = app.element('btnSubmitInvoice');
    const dppInput = app.element('invoice-amount');
    const dppError = app.element('dpp-ceiling-error');

    // Select PO 1 with ceiling 1,000,000
    app.setPo('1');
    assert.equal(submitBtn.hasAttribute('disabled'), false);

    // Enter DPP within ceiling: 500,000
    app.setDpp('500000');
    assert.equal(submitBtn.hasAttribute('disabled'), false);
    assert.equal(dppInput.classList.contains('is-invalid'), false);
    assert.equal(dppError.classList.contains('tw:hidden'), true);

    // Enter DPP exceeding ceiling: 1,500,000
    app.setDpp('1500000');
    assert.equal(submitBtn.hasAttribute('disabled'), true);
    assert.equal(submitBtn.getAttribute('aria-disabled'), 'true');
    assert.equal(dppInput.classList.contains('is-invalid'), true);
    assert.equal(dppError.classList.contains('tw:hidden'), false);

    // Adjust DPP back to 800,000 (within ceiling)
    app.setDpp('800000');
    assert.equal(submitBtn.hasAttribute('disabled'), false);
    assert.equal(submitBtn.hasAttribute('aria-disabled'), false);
    assert.equal(dppInput.classList.contains('is-invalid'), false);
    assert.equal(dppError.classList.contains('tw:hidden'), true);
});

test('switching to a PO with lower ceiling disables the submit button if current DPP exceeds it', () => {
    const app = boot();
    const submitBtn = app.element('btnSubmitInvoice');

    // Select PO 1 (ceiling 1,000,000) and set DPP to 500,000 (valid for PO 1)
    app.setPo('1');
    app.setDpp('500000');
    assert.equal(submitBtn.hasAttribute('disabled'), false);

    // Switch to PO 2 (ceiling 200,000) -> 500,000 now exceeds PO 2 ceiling!
    app.setPo('2');
    assert.equal(submitBtn.hasAttribute('disabled'), true);
    assert.equal(submitBtn.getAttribute('aria-disabled'), 'true');
    assert.ok(submitBtn.title.length > 0);

    // Switch back to PO 1 -> valid again
    app.setPo('1');
    assert.equal(submitBtn.hasAttribute('disabled'), false);
    assert.equal(submitBtn.title, '');
});

test('PO with zero remaining ceiling disables submit button when any positive DPP is entered', () => {
    const app = boot();
    const submitBtn = app.element('btnSubmitInvoice');

    // PO with 0 ceiling (e.g. PO 2 already exhausted)
    app.setPo('2');
    app.setDpp('0');
    assert.equal(submitBtn.hasAttribute('disabled'), false);

    app.setDpp('250000'); // exceeds 200,000 ceiling
    assert.equal(submitBtn.hasAttribute('disabled'), true);
});
