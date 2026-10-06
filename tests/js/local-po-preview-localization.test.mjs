import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(new URL('../../resources/views/finance/local-procurement/_import_po_modal.blade.php', import.meta.url), 'utf8');
function functionSource(name, viewSource = source) {
    const start = viewSource.indexOf(`function ${name}(`);
    assert.notEqual(start, -1);
    let depth = 0;
    for (let index = viewSource.indexOf('{', start); index < viewSource.length; index++) {
        if (viewSource[index] === '{') depth++;
        if (viewSource[index] === '}' && --depth === 0) return viewSource.slice(start, index + 1);
    }
    throw new Error(`Incomplete ${name} function`);
}
// A browser text node's innerHTML escapes markup, but does not escape quotes.
const escape = value => String(value).replace(/[&<>]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
class Element {
    children = [];
    classList = { add() {}, remove() {} };
    _text = '';
    _html = '';
    set textContent(value) { this._text = String(value); this._html = escape(value); }
    get textContent() { return this._text; }
    set innerHTML(value) { this._html = String(value); this.children = []; }
    get innerHTML() { return this._html; }
    get outerHTML() { return `<span class="${this.className || ''}">${this._html}</span>`; }
    appendChild(element) { this.children.push(element); }
}

for (const locale of ['en', 'id']) {
    test(`${locale} PO preview translates actions while escaping business values and retaining token/row cap`, () => {
        const pattern = /@js\(__\('([a-z0-9_.]+)'\)\)/g;
        const renderer = ['escapeHtml', 'formatRupiah', 'renderPreview'].map(name => functionSource(name)).join('\n');
        const keys = [...new Set([...renderer.matchAll(pattern)].map(m => m[1]))];
        const php = "require 'vendor/autoload.php'; $result=[]; foreach(json_decode($argv[1],true) as $key){[$domain,$item]=explode('.',$key,2);$lines=require 'lang/'.$argv[2].'/'.$domain.'.php';$result[$key]=Illuminate\\Support\\Arr::get($lines,$item,$key);}echo json_encode($result,JSON_THROW_ON_ERROR);";
        const copy = JSON.parse(execFileSync('php', ['-r', php, JSON.stringify(keys), locale], {cwd: root, encoding: 'utf8'}));
        const elements = Object.fromEntries(['resultPanel', 'errorsPanel', 'errorsList', 'previewPanel', 'previewBody', 'rowCountLabel', 'tokenInput', 'confirmBtn'].map(name => [name, new Element()]));
        const document = {createElement: () => new Element(), getElementById: () => new Element()};
        const context = {...elements, document};
        vm.runInNewContext(renderer.replace(pattern, (_, key) => JSON.stringify(copy[key])), context);
        const raw = '<img src=x onerror=alert(1)>';
        const row = {_row: 7, action: 'NEW', po_number: raw, supplier_name: raw, po_date: '2026-10-05', po_amount: '1000.00'};
        context.renderPreview({success: true, rows: Array.from({length: 101}, () => row)}, 'opaque-import-token');
        assert.equal(elements.previewBody.children.length, 100);
        assert.equal(elements.tokenInput.value, 'opaque-import-token');
        assert.equal(elements.confirmBtn.disabled, false);
        const html = elements.previewBody.children[0].innerHTML;
        assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
        assert.ok(!html.includes(raw));
        assert.ok(html.includes(locale === 'en' ? 'New PO' : 'PO Baru'));
        assert.ok(html.includes('2026-10-05'));
        assert.ok(html.includes('Rp 1.000'));
        assert.equal(row.po_number, raw);
        assert.equal(row.action, 'NEW');
        context.renderPreview({success: false, errors: [{row: 8, column: 'Order', message: raw}], rows: []}, null);
        assert.equal(elements.confirmBtn.disabled, true);
        assert.equal(elements.errorsList.children[0].textContent, `${locale === 'en' ? 'Row' : 'Baris'} 8 [Order]: ${raw}`);
    });

    test(`${locale} GR preview protects description attributes and date/qty text while retaining action and token`, () => {
        const grSource = readFileSync(new URL('../../resources/views/finance/local-procurement/_import_gr_modal.blade.php', import.meta.url), 'utf8');
        const renderer = ['escapeHtml', 'formatQty', 'renderPreview'].map(name => functionSource(name, grSource)).join('\n');
        const pattern = /@js\(__\('([a-z0-9_.]+)'\)\)/g;
        const keys = [...new Set([...renderer.matchAll(pattern)].map(m => m[1]))];
        const php = "require 'vendor/autoload.php';$result=[];foreach(json_decode($argv[1],true) as $key){[$domain,$item]=explode('.',$key,2);$lines=require 'lang/'.$argv[2].'/'.$domain.'.php';$result[$key]=Illuminate\\Support\\Arr::get($lines,$item,$key);}echo json_encode($result,JSON_THROW_ON_ERROR);";
        const copy = JSON.parse(execFileSync('php', ['-r', php, JSON.stringify(keys), locale], {cwd: root, encoding: 'utf8'}));
        const elements = Object.fromEntries(['resultPanel', 'errorsPanel', 'errorsList', 'previewPanel', 'previewBody', 'rowCountLabel', 'tokenInput', 'confirmBtn'].map(name => [name, new Element()]));
        const context = {...elements, document: {createElement: () => new Element(), getElementById: () => new Element()}};
        vm.runInNewContext(renderer.replace(pattern, (_, key) => JSON.stringify(copy[key])), context);
        const raw = '<img src=x onerror=alert(1)>';
        const description = 'x" onmouseover="alert(1)';
        const row = {_row: raw, action: 'NEW', gr_number: raw, po_number: raw, description, gr_date: raw, qty: raw, source_rows_count: 2};
        context.renderPreview({success: true, source_row_count: 2, rows: [row]}, 'opaque-gr-token');
        const html = elements.previewBody.children[0].innerHTML;
        assert.ok(!html.includes(raw));
        assert.ok(!html.includes('title="x" onmouseover='));
        assert.ok(html.includes('title="x&quot; onmouseover=&quot;alert(1)"'));
        assert.ok(html.includes(locale === 'en' ? 'New GR' : 'GR Baru'));
        assert.equal(elements.tokenInput.value, 'opaque-gr-token');
        assert.equal(elements.confirmBtn.disabled, false);
        assert.equal(row.description, description);
        assert.equal(row.action, 'NEW');
        assert.equal(row.qty, raw);
    });
}
