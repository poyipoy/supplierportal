import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const bladeSource = readFileSync(new URL('../../resources/views/components/ui/multi-select.blade.php', import.meta.url), 'utf8');

function extractMultiSelectState({ selectedValues = [], options = [], disabled = false, placeholder = 'Choose...', disabledPlaceholder = 'Choose PO first...' } = {}) {
    const startMarker = 'x-data="';
    const start = bladeSource.indexOf(startMarker);
    assert.notEqual(start, -1, 'multi-select Blade view must define x-data');
    const exprStart = start + startMarker.length;
    const end = bladeSource.indexOf('"\n    @if($eventName)', exprStart);
    assert.notEqual(end, -1, 'multi-select x-data attribute must terminate cleanly');

    const expr = bladeSource
        .slice(exprStart, end)
        .replace('@js($resolvedValues)', JSON.stringify(selectedValues))
        .replace('@js($normalizedOptions)', JSON.stringify(options))
        .replace('@js((bool) $disabled)', JSON.stringify(disabled))
        .replace('@js($placeholder)', JSON.stringify(placeholder))
        .replace('@js($disabledPlaceholder)', JSON.stringify(disabledPlaceholder))
        .replace("@js(__('common.select.selected_state'))", JSON.stringify('Selected'));

    let triggerFocused = 0;
    let searchFocused = 0;
    const nativeOptions = [];
    const dispatchedEvents = [];

    const state = vm.runInNewContext(`(${expr})`, {
        window: {
            AdasiI18n: { t: (_key, params) => `Date: ${params?.date ?? ''}` },
        },
        document: {
            createElement: (tag) => ({ tag, value: '', textContent: '', selected: false, attributes: {}, setAttribute(k, v) { this.attributes[k] = v; } }),
        },
        CustomEvent: class CustomEvent {
            constructor(type, init = {}) {
                this.type = type;
                this.detail = init.detail;
            }
        },
        Event: class Event {
            constructor(type, init = {}) {
                this.type = type;
                this.bubbles = Boolean(init.bubbles);
            }
        },
        Number,
        String,
        Boolean,
        Array,
        parseFloat,
    });

    const listChildren = [];
    state.$refs = {
        triggerButton: { focus() { triggerFocused++; } },
        searchInput: { focus() { searchFocused++; } },
        optionsList: { children: listChildren },
        nativeMultiSelect: {
            set innerHTML(v) { if (v === '') nativeOptions.length = 0; },
            appendChild(el) { nativeOptions.push(el); },
            dispatchEvent(ev) { dispatchedEvents.push(ev); },
        },
    };
    state.$watch = () => {};
    state.$nextTick = (fn) => fn();
    state.init();

    return {
        state,
        get triggerFocused() { return triggerFocused; },
        get searchFocused() { return searchFocused; },
        listChildren,
        dispatchedEvents,
    };
}

test('multiSelect keyboard navigation and toggleHighlighted operate within filtered options', () => {
    const ctx = extractMultiSelectState({
        options: [
            { value: '1', label: 'GR-001', description: 'Steel Plate', amount: 1250000000, receipt_amount: 1250000000, quantityLabel: '10.000', uom: 'PCS', searchKeywords: 'gr-001 1250000000' },
            { value: '2', label: 'GR-002', description: 'Aluminum Bar', amount: 50000000, receipt_amount: 50000000, quantityLabel: '250', uom: 'KG', searchKeywords: 'gr-002 50000000' },
        ],
    });
    const scrolled = [];
    ctx.listChildren.push(
        { scrollIntoView(opts) { scrolled.push({ idx: 0, opts }); } },
        { scrollIntoView(opts) { scrolled.push({ idx: 1, opts }); } },
    );

    ctx.state.open = true;
    ctx.state.navigate(1);
    assert.equal(ctx.state.highlightedIndex, 0);
    ctx.state.navigate(-1);
    assert.equal(ctx.state.highlightedIndex, 1);
    assert.equal(scrolled.length, 2);

    ctx.state.toggleHighlighted();
    assert.deepEqual([...ctx.state.selectedValues], ['2']);

    ctx.state.navigateTo(0);
    assert.equal(ctx.state.highlightedIndex, 0);
    ctx.state.toggleHighlighted();
    assert.deepEqual([...ctx.state.selectedValues], ['2', '1']);
    assert.equal(ctx.state.totalAmount, 1300000000);
});

test('multiSelect close restores focus to trigger only when requested and handles long amount formatting', () => {
    const ctx = extractMultiSelectState({
        selectedValues: ['1'],
        options: [
            { value: '1', label: 'GR-3WAY-2026-000123456', amount: 1250000000.5, receipt_amount: 1250000000.5, quantityLabel: '10.000', uom: 'PCS', searchKeywords: 'gr-3way' },
        ],
    });

    assert.equal(ctx.state.summaryDisplay.badgeText, '1 GR');
    assert.equal(ctx.state.summaryDisplay.text, 'GR-3WAY-2026-000123456');
    assert.match(ctx.state.summaryDisplay.totalFormatted, /^Rp\s1\.250\.000\.000,50$/);

    ctx.state.open = true;
    ctx.state.close(false);
    assert.equal(ctx.state.open, false);
    assert.equal(ctx.triggerFocused, 0);

    ctx.state.open = true;
    ctx.state.close(true);
    assert.equal(ctx.state.open, false);
    assert.equal(ctx.triggerFocused, 1);
});
