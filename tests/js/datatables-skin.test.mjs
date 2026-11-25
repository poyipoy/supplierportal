import test from 'node:test';
import assert from 'node:assert/strict';
import { applyAdasiDataTablesSkin } from '../../resources/js/datatables-skin.js';

function createDomBuilderStub() {
    function makeNode(tag) {
        const el = {
            tagName: tag.toUpperCase(),
            classList: [],
            attributes: {},
            children: [],
            innerHTML: '',
        };
        const api = {
            classAdd(cls) {
                el.classList.push(...String(cls).split(/\s+/).filter(Boolean));
                return api;
            },
            attr(name, value) {
                el.attributes[name] = value;
                return api;
            },
            html(content) {
                el.innerHTML = String(content);
                return api;
            },
            append(nodes) {
                if (Array.isArray(nodes)) {
                    el.children.push(...nodes);
                } else if (nodes) {
                    el.children.push(nodes);
                }
                return api;
            },
            appendTo(parent) {
                parent.append(el);
                return api;
            },
            get(index = 0) {
                return index === 0 ? el : null;
            },
        };
        return api;
    }

    return { c: makeNode };
}

test('applyAdasiDataTablesSkin no-ops safely when DataTable is absent', () => {
    assert.equal(applyAdasiDataTablesSkin(undefined), false);
    assert.equal(applyAdasiDataTablesSkin(null), false);
    assert.equal(applyAdasiDataTablesSkin({}), false);
});

test('applyAdasiDataTablesSkin configures pure-token ADASI classes and pagination renderers', () => {
    const DataTable = {
        defaults: {},
        ext: {
            classes: {},
            renderer: {
                pagingButton: {},
                pagingContainer: {},
            },
        },
        Dom: createDomBuilderStub(),
    };

    assert.equal(applyAdasiDataTablesSkin(DataTable), true);
    assert.equal(DataTable.defaults.renderer, 'adasi');

    const serializedClasses = JSON.stringify(DataTable.ext.classes);
    assert.equal(serializedClasses.includes('form-control'), false);
    assert.equal(serializedClasses.includes('form-select'), false);
    assert.equal(serializedClasses.includes('dt-bootstrap5'), false);
    assert.equal(DataTable.ext.classes.search.input, 'ui-dt-search-input');
    assert.equal(DataTable.ext.classes.length.select, 'ui-dt-length-select');
    assert.equal(DataTable.ext.classes.layout.row, 'dt-layout-row ui-dt-layout-row');

    const activeBtn = DataTable.ext.renderer.pagingButton.adasi({}, 1, '1', true, false);
    assert.equal(activeBtn.display.tagName, 'LI');
    assert.deepEqual(activeBtn.display.classList, ['dt-paging-button', 'page-item', 'active']);
    assert.equal(activeBtn.clicker.tagName, 'BUTTON');
    assert.deepEqual(activeBtn.clicker.classList, ['page-link']);
    assert.equal(activeBtn.clicker.attributes.type, 'button');
    assert.equal(activeBtn.clicker.innerHTML, '1');

    const container = DataTable.ext.renderer.pagingContainer.adasi({}, [activeBtn.display]);
    assert.equal(container.tagName, 'UL');
    assert.deepEqual(container.classList, ['pagination']);
    assert.equal(container.children.length, 1);
});
