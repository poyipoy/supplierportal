import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const viewPaths = [
    '../../resources/views/purchasing/po/show.blade.php',
    '../../resources/views/supplier/po/show.blade.php',
];

function extractRenderer(source) {
    const marker = 'function renderProgressHistoryRows(history) {';
    const start = source.indexOf(marker);
    assert.notEqual(start, -1, 'the modal must define its safe history renderer');

    let depth = 0;
    let end = -1;
    for (let index = source.indexOf('{', start); index < source.length; index++) {
        if (source[index] === '{') depth++;
        if (source[index] === '}') {
            depth--;
            if (depth === 0) {
                end = index + 1;
                break;
            }
        }
    }

    assert.notEqual(end, -1, 'the safe history renderer must be a complete function');
    return source.slice(start, end);
}

function createDocument() {
    const created = [];
    class Element {
        constructor(tagName) {
            this.tagName = tagName.toLowerCase();
            this.children = [];
            this.attributes = {};
            this.className = '';
            this._textContent = '';
            Object.defineProperty(this, 'innerHTML', {
                get: () => '',
                set: () => { throw new Error('dynamic modal content must not use innerHTML'); },
            });
            created.push(this);
        }

        set textContent(value) {
            this._textContent = String(value ?? '');
            this.children = [];
        }

        get textContent() {
            return this._textContent + this.children.map(child => child.textContent).join('');
        }

        appendChild(child) {
            this.children.push(child);
            return child;
        }

        append(...children) {
            children.forEach(child => this.appendChild(child));
        }

        setAttribute(name, value) {
            this.attributes[name] = String(value);
        }
    }

    return {
        created,
        document: {createElement: tag => new Element(tag)},
    };
}

for (const path of viewPaths) {
    test(`progress history renderer treats every response string as text: ${path}`, async () => {
        const source = await readFile(new URL(path, import.meta.url), 'utf8');
        const rendererSource = extractRenderer(source);
        const {created, document} = createDocument();
        const render = vm.runInNewContext(`(${rendererSource})`, {document});
        const payload = {
            status: 'ready_to_ship',
            status_label: '<img src=x onerror=alert(1)>',
            status_tone: 'info\" onclick=\"alert(1)',
            created_at_display: '</span><svg onload=alert(1)>',
            supplier_controlled_qty_snapshot: 20,
            estimated_ready_date_display: '<b>2026-09-28</b>',
            updated_by: '<b>Actor Name</b>',
            note: '<script>alert(1)</script> & "quoted"',
        };

        const tree = render([payload]);
        const visibleText = tree.textContent;

        assert.ok(visibleText.includes(payload.status_label));
        assert.ok(visibleText.includes(payload.created_at_display));
        assert.ok(visibleText.includes(payload.estimated_ready_date_display));
        assert.ok(visibleText.includes(payload.updated_by));
        assert.ok(visibleText.includes(payload.note));
        assert.equal(created.some(element => ['script', 'img', 'svg'].includes(element.tagName)), false);
        assert.equal(created.some(element => Object.keys(element.attributes).length > 0), false);
        assert.ok(created.some(element => element.className.includes('ui-status-chip--neutral')));
        assert.ok(created.every(element => !element.className.includes('onclick')));
    });

    test(`progress history renderer accepts only literal status tones: ${path}`, async () => {
        const source = await readFile(new URL(path, import.meta.url), 'utf8');
        const rendererSource = extractRenderer(source);

        for (const tone of ['success', 'info', 'warning', 'neutral']) {
            const {created, document} = createDocument();
            const render = vm.runInNewContext(`(${rendererSource})`, {document});
            const tree = render([{status_label: 'Status', status_tone: tone}]);
            const chip = created.find(element => element.className.startsWith('ui-status-chip '));

            assert.ok(tree);
            assert.equal(chip.className, `ui-status-chip ui-status-chip--${tone} fw-bold`);
        }
        for (const tone of ['success extra', 'neutral\" data-x=\"1', '<img>', '']) {
            const {created, document} = createDocument();
            const render = vm.runInNewContext(`(${rendererSource})`, {document});
            const tree = render([{status_label: 'Status', status_tone: tone}]);
            const chip = created.find(element => element.className.startsWith('ui-status-chip '));

            assert.ok(tree);
            assert.equal(chip.className, 'ui-status-chip ui-status-chip--neutral fw-bold');
            assert.equal(created.some(element => ['img', 'script'].includes(element.tagName)), false);
            assert.equal(created.some(element => Object.keys(element.attributes).length > 0), false);
        }
    });

    test(`progress history renderer preserves optional date and note behavior: ${path}`, async () => {
        const source = await readFile(new URL(path, import.meta.url), 'utf8');
        const rendererSource = extractRenderer(source);
        const {created, document} = createDocument();
        const render = vm.runInNewContext(`(${rendererSource})`, {document});
        const tree = render([{status_label: 'Ready', status_tone: 'success', created_at_display: null, estimated_ready_date_display: null, note: null}]);

        assert.ok(tree.textContent.includes('-'));
        assert.equal(tree.textContent.includes('Estimated Ready:'), false);
        assert.equal(tree.textContent.includes('Updated by:'), true);
        assert.ok(created.some(element => element.tagName === 'div'));
    });
}
