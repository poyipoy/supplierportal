import test from 'node:test';
import assert from 'node:assert/strict';
import { syncWireframe } from '../../resources/js/dashboard-wireframe.js';

function tile(key, span, { hidden = false, required = false, classes = [] } = {}) {
    const set = new Set(classes);
    const checkbox = { checked: hidden };

    return {
        dataset: { widgetKey: key, widgetSpan: String(span) },
        classes: set,
        classList: {
            add: (name) => set.add(name),
            remove: (name) => set.delete(name),
            toggle: (name, force) => (force ? set.add(name) : set.delete(name)),
        },
        // A required panel has no hide checkbox.
        querySelector: (selector) => (selector === 'input[name="dashboard[hidden][]"]' && !required ? checkbox : null),
        checkbox,
    };
}

const list = (...tiles) => ({ querySelectorAll: (selector) => (selector === '[data-dashboard-choice]' ? tiles : []) });
const spanOf = (element) => [...element.classes].find((name) => name.startsWith('tw:col-span-'));
const adminTiles = () => [
    tile('summary', 12),
    tile('rates', 8, { required: true }),
    tile('shortcuts', 4),
    tile('notifications', 12, { hidden: true }),
];

test('the default layout keeps every panel at its natural width and dims a hidden one', () => {
    const tiles = adminTiles();
    syncWireframe(list(...tiles));

    assert.deepEqual(tiles.map(spanOf), ['tw:col-span-12', 'tw:col-span-8', 'tw:col-span-4', 'tw:col-span-12']);
    assert.equal(tiles[3].classes.has('tw:border-dashed'), true);
    assert.equal(tiles[3].classes.has('tw:opacity-70'), true);
    assert.equal(tiles[0].classes.has('tw:border-dashed'), false);
});

test('hiding a panel widens its row partner while the hidden tile keeps its natural width', () => {
    const tiles = adminTiles();
    tiles[2].checkbox.checked = true;
    syncWireframe(list(...tiles));

    assert.equal(spanOf(tiles[1]), 'tw:col-span-12', 'the survivor fills the row');
    assert.equal(spanOf(tiles[2]), 'tw:col-span-4');
    assert.equal(tiles[2].classes.has('tw:border-dashed'), true);

    tiles[2].checkbox.checked = false;
    syncWireframe(list(...tiles));
    assert.equal(spanOf(tiles[1]), 'tw:col-span-8');
    assert.equal(tiles[2].classes.has('tw:border-dashed'), false, 'showing it again clears the dimming');
});

test('widths follow the tile order in the DOM', () => {
    const [summary, rates, shortcuts, notifications] = adminTiles();
    syncWireframe(list(rates, summary, shortcuts, notifications));

    assert.equal(spanOf(rates), 'tw:col-span-12', 'rates no longer shares a row with shortcuts');
    assert.equal(spanOf(shortcuts), 'tw:col-span-12');
});

test('stale width classes are replaced, not accumulated', () => {
    const tiles = adminTiles();
    tiles[1].classes.add('tw:col-span-5');
    tiles[1].classes.add('tw:col-span-12');
    syncWireframe(list(...tiles));

    assert.deepEqual([...tiles[1].classes].filter((name) => name.startsWith('tw:col-span-')), ['tw:col-span-8']);
});

test('a missing or unusable list is ignored', () => {
    assert.doesNotThrow(() => syncWireframe(null));
    assert.doesNotThrow(() => syncWireframe(undefined));
    assert.doesNotThrow(() => syncWireframe({}));
    assert.doesNotThrow(() => syncWireframe(list()));
});

test('a tile with an unsupported natural width falls back to a full row', () => {
    const odd = tile('odd', 10);
    syncWireframe(list(odd));
    assert.equal(spanOf(odd), 'tw:col-span-12');
});
