import { installI18n } from './i18n-fixture.mjs';
globalThis.window = installI18n(globalThis.window || {});
import test from 'node:test';
import assert from 'node:assert/strict';
import { bindDashboardReorder, bindDashboardDragAndDrop } from '../../resources/js/preferences.js';

function createMockElement(tagName, attributes = {}) {
    const classList = new Set();
    const children = [];
    const listeners = {};
    let parentNode = null;

    const el = {
        tagName: tagName.toUpperCase(),
        dataset: {},
        attributes,
        style: {},
        children,
        get classList() {
            return {
                add(...cls) { cls.forEach(c => classList.add(c)); },
                remove(...cls) { cls.forEach(c => classList.delete(c)); },
                contains(c) { return classList.has(c); },
                toggle(c) { classList.has(c) ? classList.delete(c) : classList.add(c); },
            };
        },
        get parentNode() { return parentNode; },
        set parentNode(p) { parentNode = p; },
        _listeners: listeners,
        addEventListener(event, fn) {
            listeners[event] = listeners[event] || [];
            listeners[event].push(fn);
        },
        dispatchEvent(event) {
            event.target = event.target || el;
            let current = el;
            while (current) {
                const handlers = current._listeners[event.type] || [];
                handlers.forEach(h => h(event));
                current = current.parentNode;
            }
        },
        appendChild(child) {
            child.parentNode = el;
            children.push(child);
            return child;
        },
        insertBefore(newNode, refNode) {
            const existingIndex = children.indexOf(newNode);
            if (existingIndex !== -1) {
                children.splice(existingIndex, 1);
            }
            newNode.parentNode = el;
            if (!refNode) {
                children.push(newNode);
            } else {
                const targetIndex = children.indexOf(refNode);
                if (targetIndex === -1) {
                    children.push(newNode);
                } else {
                    children.splice(targetIndex, 0, newNode);
                }
            }
            return newNode;
        },
        after(newNode) {
            if (!parentNode) return;
            const myIndex = parentNode.children.indexOf(el);
            const existingIndex = parentNode.children.indexOf(newNode);
            if (existingIndex !== -1) {
                parentNode.children.splice(existingIndex, 1);
            }
            newNode.parentNode = parentNode;
            const updatedIndex = parentNode.children.indexOf(el);
            parentNode.children.splice(updatedIndex + 1, 0, newNode);
        },
        before(newNode) {
            if (!parentNode) return;
            const existingIndex = parentNode.children.indexOf(newNode);
            if (existingIndex !== -1) {
                parentNode.children.splice(existingIndex, 1);
            }
            newNode.parentNode = parentNode;
            const updatedIndex = parentNode.children.indexOf(el);
            parentNode.children.splice(updatedIndex, 0, newNode);
        },
        closest(selector) {
            let current = el;
            while (current) {
                if (selector === '[data-dashboard-choice]' && current.dataset && current.dataset.dashboardChoice !== undefined) return current;
                if (selector === '[data-dashboard-controls]' && current.dataset && current.dataset.dashboardControls !== undefined) return current;
                if (selector === '[data-dashboard-section]' && current.dataset && current.dataset.dashboardSection !== undefined) return current;
                if (selector === '[data-dashboard-move]' && current.dataset && current.dataset.dashboardMove !== undefined) return current;
                current = current.parentNode;
            }
            return null;
        },
        contains(node) {
            let cur = node;
            while (cur) {
                if (cur === el) return true;
                cur = cur.parentNode;
            }
            return false;
        },
        querySelectorAll(selector) {
            const matches = [];
            function walk(node) {
                for (const child of node.children) {
                    if (selector === '[data-dashboard-controls]' && child.dataset?.dashboardControls !== undefined) matches.push(child);
                    if (selector === '[data-dashboard-choice]' && child.dataset?.dashboardChoice !== undefined) matches.push(child);
                    if (selector === '[data-dashboard-status]' && child.dataset?.dashboardStatus !== undefined) matches.push(child);
                    if (selector === 'input[name="dashboard[order][]"]' && child.attributes?.name === 'dashboard[order][]') matches.push(child);
                    walk(child);
                }
            }
            walk(el);
            return matches;
        },
        querySelector(selector) {
            const all = el.querySelectorAll(selector);
            return all.length > 0 ? all[0] : null;
        },
        getBoundingClientRect() {
            return { top: 0, height: 40, bottom: 40, left: 0, right: 100, width: 100 };
        },
        focus() {},
    };

    return el;
}

function buildMockDashboardDom() {
    const section = createMockElement('section');
    section.dataset.dashboardSection = '';

    const status = createMockElement('p');
    status.dataset.dashboardStatus = '';
    section.appendChild(status);

    const list = createMockElement('ol');
    list.dataset.dashboardControls = '';
    section.appendChild(list);

    const rowA = createMockElement('li');
    rowA.dataset.dashboardChoice = '';
    rowA.dataset.widgetKey = 'admin.rates';
    rowA.dataset.widgetLabel = 'Rates Administration';
    const inputA = createMockElement('input', { name: 'dashboard[order][]', value: 'admin.rates' });
    inputA.value = 'admin.rates';
    rowA.appendChild(inputA);
    list.appendChild(rowA);

    const rowB = createMockElement('li');
    rowB.dataset.dashboardChoice = '';
    rowB.dataset.widgetKey = 'admin.summary';
    rowB.dataset.widgetLabel = 'Operational Summary';
    const inputB = createMockElement('input', { name: 'dashboard[order][]', value: 'admin.summary' });
    inputB.value = 'admin.summary';
    const moveUpB = createMockElement('button');
    moveUpB.dataset.dashboardMove = 'up';
    rowB.appendChild(moveUpB);
    rowB.appendChild(inputB);
    list.appendChild(rowB);

    const rowC = createMockElement('li');
    rowC.dataset.dashboardChoice = '';
    rowC.dataset.widgetKey = 'admin.notifications';
    rowC.dataset.widgetLabel = 'Recent Activity';
    const inputC = createMockElement('input', { name: 'dashboard[order][]', value: 'admin.notifications' });
    inputC.value = 'admin.notifications';
    rowC.appendChild(inputC);
    list.appendChild(rowC);

    const doc = {
        querySelectorAll(selector) {
            return section.querySelectorAll(selector);
        },
        querySelector(selector) {
            return section.querySelector(selector);
        }
    };

    return { section, list, rowA, rowB, rowC, moveUpB, status, doc };
}

test('keyboard Move Up reorders items and updates live announcement', () => {
    const { list, rowA, rowB, moveUpB, status, doc } = buildMockDashboardDom();
    bindDashboardReorder(doc);

    // Initial order: [rowA, rowB, rowC]
    assert.deepEqual(list.children, [rowA, rowB, list.children[2]]);

    // Click Move Up on rowB
    moveUpB.dispatchEvent({ type: 'click', target: moveUpB });

    // Order should now be: [rowB, rowA, rowC]
    assert.equal(list.children[0], rowB);
    assert.equal(list.children[1], rowA);
    assert.match(status.textContent, /Operational Summary is at position 1 of 3/);
});

test('bindDashboardDragAndDrop exists and enables reordering via native drag events', () => {
    assert.equal(typeof bindDashboardDragAndDrop, 'function', 'bindDashboardDragAndDrop must be exported');
    const { list, rowA, rowB, rowC, status, doc } = buildMockDashboardDom();
    bindDashboardDragAndDrop(doc);

    // Initial order: [rowA, rowB, rowC]
    assert.equal(list.children[0], rowA);
    assert.equal(list.children[1], rowB);
    assert.equal(list.children[2], rowC);

    const dataStore = {};
    const mockDataTransfer = {
        effectAllowed: '',
        dropEffect: '',
        setData(format, val) { dataStore[format] = val; },
        getData(format) { return dataStore[format]; },
    };

    // 1. dragstart on rowC
    const dragstartEvt = { type: 'dragstart', target: rowC, dataTransfer: mockDataTransfer };
    rowC.dispatchEvent(dragstartEvt);
    assert.equal(mockDataTransfer.setData.length, 2);

    // 2. dragover on rowA
    let prevented = false;
    const dragoverEvt = {
        type: 'dragover',
        target: rowA,
        dataTransfer: mockDataTransfer,
        clientY: 5, // Top half -> drop before rowA
        preventDefault() { prevented = true; }
    };
    list.dispatchEvent(dragoverEvt);
    assert.equal(prevented, true, 'dragover must prevent default to allow drop');

    // 3. drop on rowA
    let dropPrevented = false;
    const dropEvt = {
        type: 'drop',
        target: rowA,
        dataTransfer: mockDataTransfer,
        clientY: 5,
        preventDefault() { dropPrevented = true; }
    };
    list.dispatchEvent(dropEvt);
    assert.equal(dropPrevented, true);

    // Order should now be: [rowC, rowA, rowB]
    assert.equal(list.children[0], rowC);
    assert.equal(list.children[1], rowA);
    assert.equal(list.children[2], rowB);

    // Status announcement should mention new position
    assert.match(status.textContent, /Recent Activity is at position 1 of 3/);

    // 4. dragend cleans up classes
    const dragendEvt = { type: 'dragend', target: rowC };
    rowC.dispatchEvent(dragendEvt);
});
