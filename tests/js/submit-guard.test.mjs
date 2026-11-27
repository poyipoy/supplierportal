import test from 'node:test';
import assert from 'node:assert/strict';

function makeClassList(initial = []) {
    const names = new Set(initial);
    return {
        add: (...items) => items.forEach((n) => names.add(n)),
        remove: (...items) => items.forEach((n) => names.delete(n)),
        contains: (name) => names.has(name),
    };
}

class FakeNode {
    constructor(tag = 'div', attrs = {}, classList = []) {
        this.tagName = tag.toUpperCase();
        this.attrs = new Map(Object.entries(attrs));
        this._className = classList.join(' ');
        this.classList = makeClassList(classList);
        this.style = {};
        this.dataset = {};
        this.children = [];
        this.parentNode = null;
        this.disabled = false;
        this._text = '';
    }
    set className(val) {
        this._className = String(val ?? '');
        String(val ?? '').split(/\s+/).filter(Boolean).forEach((n) => this.classList.add(n));
    }
    get className() {
        return this._className ?? '';
    }
    get childNodes() {
        return this.children;
    }
    get textContent() {
        if (this.children.length === 0) return this._text;
        return this._text + this.children.map((c) => c.textContent).join('');
    }
    set textContent(v) {
        this._text = String(v ?? '');
        this.children = [];
    }
    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }
    prepend(child) {
        child.parentNode = this;
        this.children.unshift(child);
        return child;
    }
    insertBefore(newNode, refNode) {
        newNode.parentNode = this;
        const idx = this.children.indexOf(refNode);
        if (idx === -1) this.children.push(newNode);
        else this.children.splice(idx, 0, newNode);
        return newNode;
    }
    replaceChildren(...nodes) {
        this.children = [];
        nodes.forEach((n) => this.appendChild(n));
    }
    remove() {
        if (!this.parentNode) return;
        const idx = this.parentNode.children.indexOf(this);
        if (idx !== -1) this.parentNode.children.splice(idx, 1);
        this.parentNode = null;
    }
    cloneNode(deep = false) {
        const clone = new FakeNode(this.tagName, Object.fromEntries(this.attrs), []);
        clone.className = this.className;
        clone.style = { ...this.style };
        clone.dataset = { ...this.dataset };
        clone.disabled = this.disabled;
        clone._text = this._text;
        if (deep) {
            this.children.forEach((c) => clone.appendChild(c.cloneNode(true)));
        }
        return clone;
    }
    setAttribute(k, v) { this.attrs.set(k, String(v)); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    removeAttribute(k) { this.attrs.delete(k); }
    matches(selector) {
        if (selector.includes('[data-loading-active="true"]') && this.dataset.loadingActive === 'true') return true;
        if (selector.includes('.is-loading') && this.classList.contains('is-loading')) return true;
        return false;
    }
    closest(selector) {
        let cur = this;
        while (cur) {
            if (selector === 'span' && cur.tagName === 'SPAN') return cur;
            cur = cur.parentNode;
        }
        return null;
    }
    querySelector(selector) {
        const all = this.querySelectorAll(selector);
        return all[0] ?? null;
    }
    querySelectorAll(selector) {
        const results = [];
        const walk = (node, depth = 0) => {
            for (const child of node.children) {
                if (selector === ':scope > span' && depth === 0 && child.tagName === 'SPAN') results.push(child);
                else if (selector === '.ui-icon' && child.classList.contains('ui-icon')) results.push(child);
                else if (selector === '.ui-spinner' && child.classList.contains('ui-spinner')) results.push(child);
                else if (selector === 'span:not(.ui-spinner):not(.ui-icon)' && child.tagName === 'SPAN' && !child.classList.contains('ui-spinner') && !child.classList.contains('ui-icon')) results.push(child);
                else if (selector.includes('.is-loading') && child.matches(selector)) results.push(child);
                else if (selector.includes('[data-was-disabled-by-submit-guard="true"]') && child.dataset.wasDisabledBySubmitGuard === 'true') results.push(child);
                else if (selector.includes('button') && child.tagName === 'BUTTON') results.push(child);
                walk(child, depth + 1);
            }
        };
        walk(this);
        return results;
    }
}

class FakeForm extends FakeNode {
    constructor(id = 'testForm') {
        super('form', { id });
        this.id = id;
    }
}

globalThis.HTMLElement = FakeNode;
globalThis.HTMLFormElement = FakeForm;
globalThis.window = globalThis;
globalThis.window.addEventListener = () => {};
globalThis.document = {
    readyState: 'complete',
    createElement: (tag) => new FakeNode(tag),
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener: () => {},
};

await import('../../resources/js/submit-guard.js');

test('startLoading hides only .ui-icon inside x-ui.button slot wrapper so Filter label remains visible', () => {
    const btn = new FakeNode('button');
    const slotWrap = new FakeNode('span', {}, ['tw:inline-flex', 'tw:items-center']);
    const svg = new FakeNode('svg', {}, ['ui-icon']);
    const labelSpan = new FakeNode('span');
    labelSpan.textContent = 'Filter';

    slotWrap.appendChild(svg);
    slotWrap.appendChild(labelSpan);
    btn.appendChild(slotWrap);

    window.AdasiButton.startLoading(btn);

    assert.equal(btn.disabled, true);
    assert.equal(btn.getAttribute('aria-busy'), 'true');
    assert.equal(slotWrap.style.display, undefined, 'outer slot wrapper containing label must NOT be hidden');
    assert.equal(svg.style.display, 'none', 'leading .ui-icon svg must be hidden');
    assert.equal(slotWrap.children[0].classList.contains('ui-spinner'), true, 'spinner inserted next to label');
    assert.equal(labelSpan.textContent, 'Filter', 'button label text stays visible');

    window.AdasiButton.stopLoading(btn);
    assert.equal(btn.disabled, false);
    assert.equal(btn.getAttribute('aria-busy'), null);
    assert.equal(btn.textContent, 'Filter');
    assert.equal(btn.querySelector('.ui-spinner'), null);
    assert.equal(btn.querySelector('.ui-icon').style.display, undefined);
});

test('startLoading hides dedicated icon-only span wrapper when icon span contains no label text', () => {
    const btn = new FakeNode('button');
    const iconOnlySpan = new FakeNode('span', {}, ['tw:inline-flex']);
    const svg = new FakeNode('svg', {}, ['ui-icon']);
    const labelSpan = new FakeNode('span');
    labelSpan.textContent = 'Save';

    iconOnlySpan.appendChild(svg);
    btn.appendChild(iconOnlySpan);
    btn.appendChild(labelSpan);

    window.AdasiButton.startLoading(btn);

    assert.equal(iconOnlySpan.style.display, 'none', 'icon-only wrapper should be swapped cleanly');
    assert.equal(btn.children[0].classList.contains('ui-spinner'), true);
    assert.equal(labelSpan.textContent, 'Save');

    window.AdasiButton.stopLoading(btn);
    assert.equal(btn.querySelector('.ui-spinner'), null);
    assert.equal(btn.textContent, 'Save');
});

test('resetForm clears submitting state, re-enables sibling buttons, and stops active button spinner', () => {
    const form = new FakeForm('filterForm');
    form.dataset.submitting = 'true';
    const submitBtn = new FakeNode('button', { type: 'submit' });
    const wrap = new FakeNode('span');
    wrap.appendChild(new FakeNode('svg', {}, ['ui-icon']));
    const label = new FakeNode('span');
    label.textContent = 'Apply';
    wrap.appendChild(label);
    submitBtn.appendChild(wrap);

    const resetBtn = new FakeNode('button', { type: 'button' });
    form.appendChild(submitBtn);
    form.appendChild(resetBtn);

    window.AdasiButton.startLoading(submitBtn);
    window.AdasiButton.disableSiblingButtons(form, submitBtn);
    assert.equal(resetBtn.disabled, true);

    window.AdasiButton.resetForm(form);
    assert.equal(form.dataset.submitting, 'false');
    assert.equal(submitBtn.disabled, false);
    assert.equal(resetBtn.disabled, false);
    assert.equal(submitBtn.getAttribute('aria-busy'), null);
    assert.equal(submitBtn.textContent, 'Apply');
});

test('submitForm locks against double-submit, activates button spinner, disables siblings, and invokes native submit once', () => {
    const form = new FakeForm('confirmForm');
    let nativeSubmitCount = 0;
    FakeForm.prototype.submit = function () { nativeSubmitCount += 1; };

    const submitBtn = new FakeNode('button', { type: 'submit' });
    submitBtn.textContent = 'Cancel Invoice';
    const siblingBtn = new FakeNode('button', { type: 'button' });
    siblingBtn.textContent = 'Back';
    form.appendChild(submitBtn);
    form.appendChild(siblingBtn);

    const first = window.AdasiButton.submitForm(form, submitBtn);
    const second = window.AdasiButton.submitForm(form, submitBtn);

    assert.equal(first, true);
    assert.equal(second, false, 'second submitForm call while locked must be ignored');
    assert.equal(nativeSubmitCount, 1);
    assert.equal(form.dataset.submitting, 'true');
    assert.equal(submitBtn.disabled, true);
    assert.equal(submitBtn.getAttribute('aria-busy'), 'true');
    assert.equal(siblingBtn.disabled, true);

    window.AdasiButton.resetForm(form);
    assert.equal(form.dataset.submitting, 'false');
    assert.equal(submitBtn.disabled, false);
    assert.equal(siblingBtn.disabled, false);
});
