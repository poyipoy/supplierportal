import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createQuotationAvailabilityController } from '../../resources/js/quotation-availability-guard.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

function createEventTarget() {
    const listeners = new Map();
    return {
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, new Set());
            listeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            listeners.get(type)?.delete(fn);
        },
        dispatchEvent(event) {
            for (const fn of [...(listeners.get(event.type) || [])]) {
                fn(event);
            }
            return !event.defaultPrevented;
        },
    };
}

function makeFixture(overrides = {}) {
    const windowTarget = createEventTarget();
    const documentTarget = createEventTarget();
    const formTarget = createEventTarget();

    const hiddenInput = { type: 'hidden', disabled: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
    const priceInput = { type: 'number', disabled: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
    const submitBtn = { type: 'button', disabled: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
    const exportAnchor = { attrs: {}, tabIndex: 0, classList: { classes: new Set(), add(c) { this.classes.add(c); } }, setAttribute(k, v) { this.attrs[k] = v; } };

    const messageEl = { textContent: '' };
    const linkEl = { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
    let bannerFocused = false;
    let bannerScrolled = false;
    const banner = {
        hidden: true,
        classList: { removed: [], remove(c) { this.removed.push(c); } },
        contains: () => false,
        focus() { bannerFocused = true; },
        scrollIntoView() { bannerScrolled = true; },
    };

    const form = Object.assign(formTarget, {
        dataset: {},
        querySelectorAll(selector) {
            if (selector.includes('button')) return [hiddenInput, priceInput, submitBtn];
            return [exportAnchor];
        },
    });

    const clearedIntervals = [];
    const windowRef = Object.assign(windowTarget, {
        location: { origin: 'https://portal.test' },
        matchMedia: () => ({ matches: false }),
        setInterval: () => 42,
        clearInterval: (id) => clearedIntervals.push(id),
    });

    const documentRef = Object.assign(documentTarget, {
        visibilityState: 'visible',
        getElementById: () => null,
    });

    const removedStorageKeys = [];
    const storage = { removeItem: (key) => removedStorageKeys.push(key) };

    let markedClean = 0;
    const notices = [];
    const cancelledEvents = [];
    form.addEventListener('adasi:quotation-cancelled', (e) => cancelledEvents.push(e.detail));

    const controller = createQuotationAvailabilityController({
        form,
        banner,
        messageEl,
        linkEl,
        prHash: 'hashPr123',
        availabilityUrl: 'https://portal.test/supplier/quotations/hashPr123/availability',
        detailUrl: 'https://portal.test/supplier/requests/hashPr123',
        draftKey: 'quotation_draft_7_hashPr123',
        prCancelledMessage: 'PR was cancelled.',
        invitationCancelledMessage: 'Invitation was cancelled.',
        fallbackCancelledMessage: 'Fallback cancelled.',
        windowRef,
        documentRef,
        storage,
        markClean: () => { markedClean += 1; },
        notify: (type, msg) => notices.push([type, msg]),
        fetchImpl: overrides.fetchImpl || (async () => ({ ok: true, status: 200, json: async () => ({ available: true }) })),
        ...overrides,
    });

    return {
        controller,
        form,
        banner,
        messageEl,
        linkEl,
        hiddenInput,
        priceInput,
        submitBtn,
        exportAnchor,
        windowRef,
        documentRef,
        removedStorageKeys,
        clearedIntervals,
        notices,
        cancelledEvents,
        getBannerFocused: () => bannerFocused,
        getBannerScrolled: () => bannerScrolled,
        getMarkedClean: () => markedClean,
    };
}

test('realtime cancellation notification matching pr_hash locks the form, clears draft, marks clean, and focuses alert banner', () => {
    const f = makeFixture();
    f.controller.start();

    // Unrelated PR hash must be ignored
    f.windowRef.dispatchEvent(new CustomEvent('adasi:realtime-notification', {
        detail: { event: 'pr.supplier_removed', pr_hash: 'otherHash999' },
    }));
    assert.equal(f.controller.isLocked(), false);
    assert.equal(f.banner.hidden, true);

    // Matching PR hash immediately locks form
    f.windowRef.dispatchEvent(new CustomEvent('adasi:realtime-notification', {
        detail: { event: 'pr.supplier_removed', pr_hash: 'hashPr123' },
    }));

    assert.equal(f.controller.isLocked(), true);
    assert.equal(f.banner.hidden, false);
    assert.equal(f.messageEl.textContent, 'Invitation was cancelled.');
    assert.equal(f.linkEl.attrs.href, 'https://portal.test/supplier/requests/hashPr123');
    assert.equal(f.hiddenInput.disabled, false);
    assert.equal(f.priceInput.disabled, true);
    assert.equal(f.submitBtn.disabled, true);
    assert.equal(f.exportAnchor.attrs['aria-disabled'], 'true');
    assert.deepEqual(f.removedStorageKeys, ['quotation_draft_7_hashPr123']);
    assert.equal(f.getMarkedClean(), 1);
    assert.equal(f.getBannerFocused(), true);
    assert.equal(f.getBannerScrolled(), true);
    assert.deepEqual(f.clearedIntervals, [42]);
    assert.equal(f.cancelledEvents.length, 1);
});

test('passive availability poll locks form when backend returns available=false and rejects cross-origin redirect URLs', async () => {
    const f = makeFixture({
        fetchImpl: async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                available: false,
                status: 'cancelled',
                code: 'pr_cancelled',
                message: 'Server confirmed cancellation.',
                redirect_url: 'https://evil.example/phish',
            }),
        }),
    });
    f.controller.start();
    await f.controller.checkAvailability();

    assert.equal(f.controller.isLocked(), true);
    assert.equal(f.messageEl.textContent, 'Server confirmed cancellation.');
    // Cross-origin redirect must fall back to trusted same-origin detailUrl
    assert.equal(f.linkEl.attrs.href, 'https://portal.test/supplier/requests/hashPr123');
});

test('adasi:form-conflict 409 event prevents default redirect and locks form cleanly', () => {
    const f = makeFixture();
    f.controller.start();

    const conflictEvent = new CustomEvent('adasi:form-conflict', {
        bubbles: true,
        cancelable: true,
        detail: {
            status: 409,
            data: {
                code: 'pr_cancelled',
                message: 'Cancelled while submitting.',
                redirect: 'https://portal.test/supplier/requests/hashPr123',
            },
        },
    });
    f.form.dispatchEvent(conflictEvent);

    assert.equal(conflictEvent.defaultPrevented, true);
    assert.equal(f.controller.isLocked(), true);
    assert.equal(f.messageEl.textContent, 'Cancelled while submitting.');
});

test('create.blade.php does not delete sessionStorage draft before server confirmation and wires availability guard', () => {
    const blade = read('resources/views/supplier/quotations/create.blade.php');
    assert.match(blade, /data-quotation-availability-guard/);
    assert.match(blade, /data-quotation-cancelled-banner/);
    assert.match(blade, /role="alert"/);
    assert.match(blade, /sessionStorage\.setItem\(draftKey/);
    assert.doesNotMatch(blade, /if\s*\(result\.isConfirmed\)\s*\{\s*try\s*\{\s*(?:localStorage|sessionStorage)\.removeItem\(draftKey\)/);
});

