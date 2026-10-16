import test from 'node:test';
import assert from 'node:assert/strict';
import {
    assertColumnState,
    columnLetter,
    createColumnState,
    serializeColumns,
    transitionColumns,
} from '../../resources/js/export-column-designer.js';

const catalog = (keys = ['a', 'b', 'c', 'd'], required = ['a']) => keys.map((key) => ({
    key, label: `Column ${key.toUpperCase()}`, default: true, required: required.includes(key),
}));

// These checks deliberately use public state, independently of assertColumnState().
function invariant(state, context = '') {
    const keys = state.catalog.map((column) => column.key);
    assert.deepEqual([...state.order].sort(), [...keys].sort(), `complete authorized order: ${context}`);
    assert.equal(new Set(state.order).size, keys.length, `unique order: ${context}`);
    assert.equal(new Set(state.visible).size, state.visible.length, `unique visible keys: ${context}`);
    assert.ok(state.visible.length >= 1 && state.visible.length <= 64, `nonempty visible keys: ${context}`);
    assert.ok(state.visible.every((key) => keys.includes(key)), `authorized visibility: ${context}`);
    assert.ok(state.catalog.filter((column) => column.required).every((column) => state.visible.includes(column.key)), `required visible: ${context}`);
    assert.ok(state.visible.includes(state.activeKey), `visible active key: ${context}`);
    assert.deepEqual(state.defaults, state.catalog.filter((column) => column.default).map((column) => column.key), `canonical defaults: ${context}`);
    assert.deepEqual(serializeColumns(state), state.order.filter((key) => state.visible.includes(key)), `worksheet equals export: ${context}`);
    assert.ok(Number.isSafeInteger(state.revision) && state.revision >= 0, `valid revision: ${context}`);
    assert.equal(assertColumnState(state), true, `built-in assertion: ${context}`);
}

function apply(state, command, context = '') {
    const snapshot = JSON.stringify(state);
    const next = transitionColumns(state, command);
    invariant(next, `${context} ${JSON.stringify(command)}`);
    assert.equal(JSON.stringify(state), snapshot, `previous state stays immutable: ${context}`);
    const changed = JSON.stringify(state.order) !== JSON.stringify(next.order)
        || JSON.stringify([...state.visible].sort()) !== JSON.stringify([...next.visible].sort());
    assert.equal(next.revision, state.revision + Number(changed), `revision follows configuration: ${context}`);
    return next;
}

test('initialization imports safely without DOM and builds defaults plus canonical hidden suffix', () => {
    assert.equal(typeof globalThis.document, 'undefined');
    const input = [
        { key: 'port', label: 'Port', default: false, required: false },
        { key: 'po_number', label: 'PO number', default: true, required: true },
        { key: 'incoterm', label: 'Incoterm', default: false, required: false },
        { key: 'status', label: 'Status', default: true, required: false },
    ];
    const state = createColumnState(input);
    invariant(state);
    assert.deepEqual(state.order, ['po_number', 'status', 'port', 'incoterm']);
    assert.deepEqual(serializeColumns(state), ['po_number', 'status']);
    assert.equal(state.activeKey, 'po_number');
    assert.equal(state.revision, 0);
    input[1].label = 'Changed outside';
    input.reverse();
    assert.equal(state.catalog[1].label, 'PO number');
});

test('catalog, metadata, and state arrays are immutable and serialized arrays are detached', () => {
    const state = createColumnState(catalog());
    for (const value of [state, state.catalog, ...state.catalog, state.defaults, state.order, state.visible]) {
        assert.equal(Object.isFrozen(value), true);
    }
    assert.throws(() => state.order.reverse(), TypeError);
    assert.throws(() => { state.catalog[0].label = 'Mutated'; }, TypeError);
    assert.throws(() => { state.visible.push('forged'); }, TypeError);
    const exported = serializeColumns(state);
    exported.reverse();
    exported.push('forged');
    assert.deepEqual(serializeColumns(state), ['a', 'b', 'c', 'd']);
});

test('invalid catalogs fail closed instead of repairing missing defaults or required columns', () => {
    const invalid = [
        null, {}, [], Array.from({ length: 65 }, (_, i) => ({ key: `column_${i}`, label: 'Column', default: true, required: false })),
        [...catalog(), catalog()[0]],
        [{ key: 'UPPER', label: 'Wrong key', default: true, required: false }],
        [{ key: '1leading', label: 'Wrong key', default: true, required: false }],
        [{ key: `a${'b'.repeat(64)}`, label: 'Too long', default: true, required: false }],
        [{ key: 'a\n', label: 'Trailing newline', default: true, required: false }],
        [{ key: 'a', label: '', default: true, required: false }],
        [{ key: 'a', label: 'A', default: 'true', required: false }],
        [{ key: 'a', label: 'A', default: true, required: 0 }],
        [{ key: 'a', label: 'A', default: false, required: false }],
        [{ key: 'a', label: 'A', default: false, required: true }, ...catalog(['b'], [])],
    ];
    invalid.forEach((input, index) => assert.throws(() => createColumnState(input), /catalog|column|default|required/i, `invalid catalog ${index}`));
});

test('presented preset order is exact, including a required key away from the first position', () => {
    const state = createColumnState(catalog(), ['d', 'a']);
    invariant(state);
    assert.deepEqual(state.order, ['d', 'a', 'b', 'c']);
    assert.deepEqual(serializeColumns(state), ['d', 'a']);
    assert.equal(state.activeKey, 'd');
});

test('invalid presented presets throw before replacing any valid state', () => {
    const state = createColumnState(catalog());
    const before = JSON.stringify(state);
    for (const selected of [null, {}, [], ['a', 'a'], ['a', 'foreign'], ['b'], ['a', 1], ['a', , 'c']]) {
        assert.throws(() => createColumnState(catalog(), selected), /preset|column|required/i);
        assert.throws(() => transitionColumns(state, { type: 'preset', columns: selected }), /preset|column|required/i);
        assert.equal(JSON.stringify(state), before);
    }
});

test('Hide -> Move -> Unhide keeps hidden order slots while required columns may move', () => {
    let state = createColumnState(catalog());
    state = apply(state, { type: 'hide', key: 'b' });
    assert.deepEqual(state.order, ['a', 'b', 'c', 'd']);
    assert.deepEqual(serializeColumns(state), ['a', 'c', 'd']);
    assert.equal(state.activeKey, 'c');
    state = apply(state, { type: 'move-left', key: 'c' });
    assert.deepEqual(state.order, ['c', 'a', 'b', 'd']);
    assert.deepEqual(serializeColumns(state), ['c', 'a', 'd']);
    assert.equal(state.activeKey, 'c');
    state = apply(state, { type: 'unhide', key: 'b' });
    assert.deepEqual(serializeColumns(state), ['c', 'a', 'b', 'd']);
    assert.equal(state.activeKey, 'b');
});

test('Apply Preset -> Reorder -> Reset restores server defaults rather than all available keys', () => {
    const input = [...catalog(), { key: 'opt_in', label: 'Opt-in', default: false, required: false }];
    let state = createColumnState(input);
    state = apply(state, { type: 'preset', columns: ['d', 'a', 'opt_in'] });
    assert.deepEqual(state.order, ['d', 'a', 'opt_in', 'b', 'c']);
    state = apply(state, { type: 'move-left', key: 'a' });
    assert.deepEqual(serializeColumns(state), ['a', 'd', 'opt_in']);
    state = apply(state, { type: 'reset' });
    assert.deepEqual(state.order, ['a', 'b', 'c', 'd', 'opt_in']);
    assert.deepEqual(serializeColumns(state), ['a', 'b', 'c', 'd']);
    assert.equal(state.activeKey, 'a');
});

test('Hide All Optional -> Restore preserves required visibility and deterministic active fallback', () => {
    let state = createColumnState(catalog());
    for (const key of ['b', 'c', 'd']) state = apply(state, { type: 'hide', key });
    assert.deepEqual(serializeColumns(state), ['a']);
    assert.equal(state.activeKey, 'a');
    assert.equal(apply(state, { type: 'hide', key: 'a' }), state);
    state = apply(state, { type: 'unhide', key: 'c' });
    assert.deepEqual(serializeColumns(state), ['a', 'c']);
    assert.equal(state.activeKey, 'c');
});

test('the last optional column cannot be hidden even when no required column exists', () => {
    let state = createColumnState(catalog(['a', 'b'], []));
    state = apply(state, { type: 'hide', key: 'a' });
    assert.deepEqual(serializeColumns(state), ['b']);
    assert.equal(apply(state, { type: 'hide', key: 'b' }), state);
});

test('hiding the last visible position selects its previous visible neighbor', () => {
    let state = createColumnState(catalog());
    state = apply(state, { type: 'select', key: 'd' });
    state = apply(state, { type: 'hide', key: 'd' });
    assert.equal(state.activeKey, 'c');
    assert.deepEqual(serializeColumns(state), ['a', 'b', 'c']);
});

test('selection changes no configuration and cannot activate hidden or unauthorized keys', () => {
    let state = createColumnState(catalog(), ['a', 'c']);
    const order = serializeColumns(state);
    state = apply(state, { type: 'select', key: 'c' });
    assert.equal(state.activeKey, 'c');
    assert.equal(state.revision, 0);
    assert.deepEqual(serializeColumns(state), order);
    for (const key of ['b', 'foreign', null, 0]) assert.equal(apply(state, { type: 'select', key }), state);
});

test('Move Left and Right cross hidden slots by visible neighbor without changing hidden relative order', () => {
    let state = createColumnState(catalog(['a', 'b', 'c', 'd', 'e', 'f']), ['a', 'd', 'f']);
    // Preset suffix is canonical. Hide commands below create slots between visible keys.
    state = apply(state, { type: 'reset' });
    for (const key of ['b', 'c', 'e']) state = apply(state, { type: 'hide', key });
    state = apply(state, { type: 'move-right', key: 'a' });
    assert.deepEqual(state.order, ['b', 'c', 'd', 'a', 'e', 'f']);
    assert.deepEqual(serializeColumns(state), ['d', 'a', 'f']);
    state = apply(state, { type: 'move-left', key: 'f' });
    assert.deepEqual(state.order, ['b', 'c', 'd', 'f', 'a', 'e']);
    assert.deepEqual(serializeColumns(state), ['d', 'f', 'a']);
    assert.deepEqual(state.order.filter((key) => !state.visible.includes(key)), ['b', 'c', 'e']);
});

test('Drop before-first and after-last commits exactly once, retaining visibility', () => {
    let state = createColumnState(catalog(), ['a', 'b', 'd']);
    const visible = [...state.visible].sort();
    state = apply(state, { type: 'drop', key: 'd', target: 'a', side: 'before' });
    assert.deepEqual(serializeColumns(state), ['d', 'a', 'b']);
    state = apply(state, { type: 'drop', key: 'd', target: 'b', side: 'after' });
    assert.deepEqual(serializeColumns(state), ['a', 'b', 'd']);
    assert.deepEqual([...state.visible].sort(), visible);
    assert.equal(apply(state, { type: 'drop', key: 'd', target: 'b', side: 'after' }), state);
});

test('drop matches the equivalent visible-neighbor Move for every visible source and direction', () => {
    const state = createColumnState(catalog(), ['d', 'a', 'c']);
    const visible = serializeColumns(state);
    visible.forEach((key, i) => {
        if (i > 0) assert.deepEqual(
            apply(state, { type: 'drop', key, target: visible[i - 1], side: 'before' }),
            apply(state, { type: 'move-left', key }),
        );
        if (i < visible.length - 1) assert.deepEqual(
            apply(state, { type: 'drop', key, target: visible[i + 1], side: 'after' }),
            apply(state, { type: 'move-right', key }),
        );
    });
});

test('invalid interaction commands are identity no-ops without partial state changes', () => {
    const state = createColumnState(catalog(), ['a', 'd']);
    const commands = [
        null, {}, { type: 'unknown' }, { type: 'hide', key: 'a' },
        { type: 'hide', key: 'b' }, { type: 'unhide', key: 'a' },
        { type: 'move-left', key: 'a' }, { type: 'move-right', key: 'd' },
        { type: 'move-right', key: 'b' }, { type: 'drop', key: 'a', target: 'b', side: 'before' },
        { type: 'drop', key: 'b', target: 'a', side: 'after' },
        { type: 'drop', key: 'a', target: 'd', side: 'invalid' },
        { type: 'drop', key: 'a', target: 'a', side: 'before' },
        { type: 'drop', key: 'a', target: 'forged', side: 'after' },
        { type: 'hide', key: 'forged' }, { type: 'unhide', key: 'forged' },
    ];
    for (const command of commands) assert.equal(apply(state, command), state);
});

test('Save -> Reload Preset -> Export preserves visible order while canonicalizing hidden positions', () => {
    let state = createColumnState(catalog());
    state = apply(state, { type: 'hide', key: 'b' });
    state = apply(state, { type: 'move-left', key: 'c' });
    assert.deepEqual(state.order, ['c', 'a', 'b', 'd']);
    const savedColumns = serializeColumns(state);
    assert.deepEqual(savedColumns, ['c', 'a', 'd']);
    let reloaded = createColumnState(state.catalog, savedColumns);
    invariant(reloaded);
    assert.deepEqual(serializeColumns(reloaded), savedColumns);
    assert.deepEqual(reloaded.order, ['c', 'a', 'd', 'b']);
    reloaded = apply(reloaded, { type: 'unhide', key: 'b' });
    assert.deepEqual(serializeColumns(reloaded), ['c', 'a', 'd', 'b']);
    assert.deepEqual(savedColumns, ['c', 'a', 'd']);
});

test('transient drag preview/cancel commands cannot commit; recreating state starts with server defaults', () => {
    const original = createColumnState(catalog());
    const before = serializeColumns(original);
    for (const type of ['dragstart', 'dragover', 'dragcancel', 'menu-cancel']) {
        assert.equal(apply(original, { type, key: 'd', target: 'a', side: 'before' }), original);
    }
    const reopened = createColumnState(catalog());
    invariant(reopened);
    assert.deepEqual(serializeColumns(reopened), before);
    assert.notEqual(reopened, original);
});

test('independent roots and preset inputs share no mutable column configuration', () => {
    const input = catalog();
    const selected = ['a', 'd'];
    const first = createColumnState(input, selected);
    const second = createColumnState(input);
    selected.reverse();
    const changed = apply(first, { type: 'unhide', key: 'b' });
    assert.deepEqual(serializeColumns(changed), ['a', 'd', 'b']);
    assert.deepEqual(serializeColumns(first), ['a', 'd']);
    assert.deepEqual(serializeColumns(second), ['a', 'b', 'c', 'd']);
    assert.notEqual(first.catalog, second.catalog);
});

test('assertion and serialization reject tampered states at the boundary', () => {
    const state = createColumnState(catalog());
    const corruptions = [
        { ...state, order: ['a', 'b', 'c'] },
        { ...state, order: ['a', 'a', 'c', 'd'] },
        { ...state, order: ['a', 'b', 'c', 'forged'] },
        { ...state, order: ['a', 'b', 'c', ,] },
        { ...state, visible: [] },
        { ...state, visible: ['b'] },
        { ...state, visible: ['a', 'a'] },
        { ...state, visible: ['a', 'forged'] },
        { ...state, visible: ['a', ,] },
        { ...state, activeKey: 'forged' },
        { ...state, defaults: ['b'] },
        { ...state, defaults: [, , , ,] },
        { ...state, revision: -1 },
        { ...state, revision: NaN },
    ];
    for (const invalid of corruptions) {
        assert.throws(() => assertColumnState(invalid));
        assert.throws(() => serializeColumns(invalid));
        assert.throws(() => transitionColumns(invalid, { type: 'reset' }));
    }
});

test('Excel letters derive from zero-based visible positions through the 64-key limit', () => {
    for (const [index, letter] of [[0, 'A'], [25, 'Z'], [26, 'AA'], [51, 'AZ'], [52, 'BA'], [63, 'BL']]) {
        assert.equal(columnLetter(index), letter);
    }
    for (const invalid of [-1, 1.5, NaN, Infinity, '0', null]) assert.throws(() => columnLetter(invalid));
    let state = createColumnState(catalog());
    state = apply(state, { type: 'hide', key: 'b' });
    assert.deepEqual(serializeColumns(state).map((key, index) => [key, columnLetter(index)]), [['a', 'A'], ['c', 'B'], ['d', 'C']]);
});

test('all command prefixes through depth 3 remain valid on a small catalog', (t) => {
    const initial = createColumnState(catalog(['a', 'b', 'c']));
    const commands = initial.order.flatMap((key) => ['select', 'hide', 'unhide', 'move-left', 'move-right'].map((type) => ({ type, key })));
    commands.push({ type: 'reset' }, { type: 'drop', key: 'a', target: 'c', side: 'after' }, { type: 'drop', key: 'c', target: 'a', side: 'before' });
    let count = 0;
    function visit(state, path, depth) {
        if (depth === 0) return;
        for (const command of commands) {
            const replay = [...path, command];
            const next = apply(state, command, `replay=${JSON.stringify(replay)}`);
            count += 1;
            visit(next, replay, depth - 1);
        }
    }
    visit(initial, [], 3);
    t.diagnostic(`Checked ${count} command prefixes (all sequences of length 1-3).`);
});

function random(seed) {
    let value = seed >>> 0;
    return (limit) => {
        value = (Math.imul(1664525, value) + 1013904223) >>> 0;
        return Math.floor(value / 0x100000000 * limit);
    };
}

for (const size of [24, 64]) {
    for (const seed of [0x5eed, 0xc0ffee, 0xadc015]) {
        test(`generated ${size}-column operations preserve invariants; replay seed=${seed}`, (t) => {
            const pick = random(seed);
            const input = Array.from({ length: size }, (_, i) => ({
                key: `column_${i}`, label: `Column ${i}`, default: i < size - 2, required: i === 0 || i === 5,
            }));
            let state = createColumnState(input);
            const replay = [];
            invariant(state, `seed=${seed}`);
            for (let step = 0; step < 800; step += 1) {
                const type = ['select', 'hide', 'unhide', 'move-left', 'move-right', 'drop', 'reset', 'preset'][pick(8)];
                let command = { type, key: pick(15) === 0 ? 'foreign' : input[pick(size)].key };
                if (type === 'drop') command = { ...command, target: input[pick(size)].key, side: pick(2) ? 'before' : 'after' };
                if (type === 'preset') {
                    const selected = input.filter((column) => column.required || pick(2)).map((column) => column.key);
                    for (let i = selected.length - 1; i > 0; i -= 1) {
                        const j = pick(i + 1); [selected[i], selected[j]] = [selected[j], selected[i]];
                    }
                    command = { type, columns: selected };
                }
                replay.push(command);
                state = apply(state, command, `seed=${seed} step=${step} replay=${JSON.stringify(replay)}`);
            }
            t.diagnostic(`seed=${seed}; catalog=${size}; transitions=${replay.length}; all prefixes asserted.`);
        });
    }
}
