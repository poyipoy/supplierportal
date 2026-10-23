import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
    ALLOWED_SPANS, SPAN_CLASSES, TILE_SPAN_CLASSES, computeEffectiveSpans, normalizeSpan, spanClass, tileSpanClass,
} from '../../resources/js/dashboard-layout-core.js';

const cases = JSON.parse(await readFile(new URL('../Fixtures/dashboard-smart-span-cases.json', import.meta.url), 'utf8'));

for (const { name, items, expected } of cases) {
    test(`smart span: ${name}`, () => {
        assert.deepEqual(computeEffectiveSpans(items), expected);
    });
}

test('smart span never overfills a row and always fills the rows it closes', () => {
    // Exhaustive over every ordered triple of allowed spans, so no combination can leave a gap.
    for (const a of ALLOWED_SPANS) for (const b of ALLOWED_SPANS) for (const c of ALLOWED_SPANS) {
        const items = [{ key: 'a', span: a, visible: true }, { key: 'b', span: b, visible: true }, { key: 'c', span: c, visible: true }];
        const spans = computeEffectiveSpans(items);
        const widths = items.map(({ key }) => spans[key]);
        assert.ok(widths.every((width) => ALLOWED_SPANS.includes(width)), `${a},${b},${c} -> ${widths}`);

        // Walk the effective widths: rows must close at exactly 12 and never exceed it.
        let used = 0;
        for (const width of widths) {
            used += width;
            assert.ok(used <= 12, `${a},${b},${c}: a row must not exceed 12 (${widths})`);
            if (used === 12) used = 0;
        }
        assert.equal(used, 0, `${a},${b},${c}: the final row must total 12 (${widths})`);
    }
});

test('spans outside the supported set are treated as full width', () => {
    for (const span of [0, 1, 2, 10, 11, 13, -3, 5.5, '5', null, undefined, NaN]) {
        assert.equal(normalizeSpan(span), 12, String(span));
    }
    for (const span of ALLOWED_SPANS) assert.equal(normalizeSpan(span), span);
});

test('the Blade component and this module declare the same literal col-span classes', async () => {
    const blade = await readFile(new URL('../../resources/views/components/ui/dashboard-layout.blade.php', import.meta.url), 'utf8');
    const fromBlade = Object.fromEntries([...blade.matchAll(/(\d+)\s*=>\s*'(tw:lg:col-span-\d+)'/g)].map(([, span, name]) => [span, name]));
    assert.deepEqual(fromBlade, SPAN_CLASSES);
});

test('the Customization wireframe declares the same unprefixed tile classes as this module', async () => {
    const blade = await readFile(new URL('../../resources/views/profile/customization.blade.php', import.meta.url), 'utf8');
    const fromBlade = Object.fromEntries([...blade.matchAll(/(\d+)\s*=>\s*'(tw:col-span-\d+)'/g)].map(([, span, name]) => [span, name]));
    assert.deepEqual(fromBlade, TILE_SPAN_CLASSES);
    for (const span of ALLOWED_SPANS) assert.equal(tileSpanClass(span), `tw:col-span-${span}`);
    assert.equal(tileSpanClass(10), 'tw:col-span-12');
});

test('every allowed span has a literal col-span class and unknown spans fall back to full width', () => {
    assert.deepEqual(Object.keys(SPAN_CLASSES).map(Number), [...ALLOWED_SPANS]);
    for (const span of ALLOWED_SPANS) assert.equal(spanClass(span), `tw:lg:col-span-${span}`);
    assert.equal(spanClass(10), 'tw:lg:col-span-12');
});
