import test from 'node:test';
import assert from 'node:assert/strict';
import {
    applyPreset, channelState, cloneState, computeVisible, dirtyKeys, effectiveMode, fill, filterCounts,
    matchesPreset, notificationPreferencesForm, rowMatches, setChannel, summarize,
} from '../../resources/js/notification-preferences.js';

const ALL = ['quotation.submitted', 'quotation.revision', 'invoice.approved', 'invoice.revision', 'chat.message'];

const meta = {
    'quotation.submitted': { search: 'quotation submitted by supplier', scope: 'import', action: true },
    'quotation.revision': { search: 'quotation revision requested', scope: 'import', action: false },
    'invoice.approved': { search: 'invoice approved ready to pay', scope: 'local', action: false },
    'invoice.revision': { search: 'invoice needs revision', scope: 'local', action: true },
    'chat.message': { search: 'new chat message', scope: 'general', action: false },
};
const categories = { commercial: ['quotation.submitted', 'quotation.revision', 'chat.message'], invoice: ['invoice.approved', 'invoice.revision'] };
const copy = {
    summary: ':enabled of :total on · :silent inbox only · :off off',
    unsaved: 'Unsaved changes: :count',
    categorySummary: ':on of :total on',
    shown: ':shown of :total notifications shown',
};

// normal, silent, off (popup remembered), normal, silent
const baseState = () => ({
    'quotation.submitted': { inbox: true, popup: true },
    'quotation.revision': { inbox: true, popup: false },
    'invoice.approved': { inbox: false, popup: true },
    'invoice.revision': { inbox: true, popup: true },
    'chat.message': { inbox: true, popup: false },
});
const visibleKeys = (visible) => Object.keys(visible).filter((key) => visible[key]);
const component = (extra = {}) => notificationPreferencesForm({ meta, categories, state: baseState(), copy, ...extra });

test('effectiveMode derives off / silent / normal and ignores a popup without inbox', () => {
    assert.equal(effectiveMode({ inbox: false, popup: false }), 'off');
    assert.equal(effectiveMode({ inbox: false, popup: true }), 'off');
    assert.equal(effectiveMode({ inbox: true, popup: false }), 'silent');
    assert.equal(effectiveMode({ inbox: true, popup: true }), 'normal');
});

test('summarize counts on, inbox-only and off, treating popup-without-inbox as off', () => {
    assert.deepEqual(summarize(baseState()), { total: 5, on: 4, inboxOnly: 2, off: 1 });
    assert.deepEqual(summarize({}), { total: 0, on: 0, inboxOnly: 0, off: 0 });
});

test('dirtyKeys compares effective delivery, not raw flags', () => {
    const initial = baseState();
    const state = cloneState(initial);

    state['invoice.approved'].popup = false; // inbox off in both states: invisible change
    assert.deepEqual(dirtyKeys(state, initial), []);

    state['quotation.revision'].inbox = false;
    assert.deepEqual(dirtyKeys(state, initial), ['quotation.revision']);
    state['quotation.revision'].inbox = true; // on -> off -> on is clean again
    assert.deepEqual(dirtyKeys(state, initial), []);

    state['quotation.submitted'].popup = false; // popup-only change on an inbox-on row
    assert.deepEqual(dirtyKeys(state, initial), ['quotation.submitted']);

    delete state['chat.message']; // rows missing from the current state are ignored
    assert.deepEqual(dirtyKeys(state, initial), ['quotation.submitted']);
});

test('rowMatches searches the lowercased meta.search and supports the off / inbox filters', () => {
    const silent = { inbox: true, popup: false };
    const normal = { inbox: true, popup: true };
    const off = { inbox: false, popup: true };
    const m = meta['quotation.revision'];

    assert.equal(rowMatches(silent, m, { query: '  REVISION ', filter: 'all', scope: 'all' }), true);
    assert.equal(rowMatches(silent, m, { query: 'invoice', filter: 'all', scope: 'all' }), false);
    assert.equal(rowMatches(off, m, { filter: 'off', scope: 'all' }), true);
    assert.equal(rowMatches(silent, m, { filter: 'off', scope: 'all' }), false);
    assert.equal(rowMatches(silent, m, { filter: 'inbox', scope: 'all' }), true);
    assert.equal(rowMatches(normal, m, { filter: 'inbox', scope: 'all' }), false, 'inbox filter means silent only');
    assert.equal(rowMatches(off, m, { filter: 'inbox', scope: 'all' }), false);
});

test('computeVisible applies scope rules and combines criteria with AND', () => {
    const state = baseState();
    const visibleFor = (criteria) => visibleKeys(computeVisible(state, meta, { query: '', filter: 'all', scope: 'all', ...criteria }));

    assert.deepEqual(visibleFor({}), ALL);
    assert.deepEqual(visibleFor({ scope: 'import' }), ['quotation.submitted', 'quotation.revision', 'chat.message']);
    assert.deepEqual(visibleFor({ scope: 'local' }), ['invoice.approved', 'invoice.revision', 'chat.message']);

    assert.deepEqual(visibleFor({ query: 'revision' }), ['quotation.revision', 'invoice.revision']);
    assert.deepEqual(visibleFor({ query: 'revision', scope: 'import' }), ['quotation.revision']);
    assert.deepEqual(visibleFor({ query: 'revision', scope: 'local', filter: 'inbox' }), []);
    assert.deepEqual(visibleFor({ filter: 'inbox', scope: 'import' }), ['quotation.revision', 'chat.message']);

    assert.equal(computeVisible({}, meta, { scope: 'all' })['chat.message'], false, 'meta without state is hidden');
});

test('applyPreset lands on one complete end state and never mutates its input', () => {
    const state = baseState();
    const snapshot = cloneState(state);

    const everything = applyPreset(state, meta, 'everything');
    assert.ok(ALL.every((key) => everything[key].inbox && everything[key].popup), 'inbox and popup on for every row');

    const actionNeeded = applyPreset(state, meta, 'action_needed');
    assert.deepEqual(ALL.map((key) => actionNeeded[key].inbox), ALL.map((key) => meta[key].action));
    assert.ok(ALL.filter((key) => meta[key].action).every((key) => actionNeeded[key].popup), 'action rows get a popup too');

    const quiet = applyPreset(state, meta, 'quiet');
    assert.ok(ALL.every((key) => quiet[key].inbox === true));
    assert.deepEqual(ALL.map((key) => quiet[key].popup), ALL.map((key) => meta[key].action));

    assert.deepEqual(applyPreset(state, meta, 'unknown'), snapshot, 'unknown preset changes nothing');
    assert.deepEqual(state, snapshot);
    assert.notEqual(applyPreset(state, meta, 'everything'), state);
});

test('the end state depends only on the preset chosen last (regression: Everything did nothing after Quiet mode)', () => {
    const presets = ['everything', 'action_needed', 'quiet'];
    for (const first of presets) {
        for (const second of presets) {
            const afterFirst = applyPreset(baseState(), meta, first);
            assert.deepEqual(applyPreset(afterFirst, meta, second), applyPreset(baseState(), meta, second), `${first} then ${second}`);
        }
    }
    const quiet = applyPreset(baseState(), meta, 'quiet');
    assert.ok(dirtyKeys(applyPreset(quiet, meta, 'everything'), quiet).length > 0, 'Everything after Quiet must change rows');
    const actionOnly = applyPreset(baseState(), meta, 'action_needed');
    assert.ok(dirtyKeys(applyPreset(actionOnly, meta, 'everything'), actionOnly).length > 0, 'Everything after Action needed only must change rows');
});

test('matchesPreset marks the preset the state already equals and nothing else', () => {
    for (const preset of ['everything', 'action_needed', 'quiet']) {
        assert.equal(matchesPreset(applyPreset(baseState(), meta, preset), meta, preset), true, preset);
    }
    const quiet = applyPreset(baseState(), meta, 'quiet');
    assert.equal(matchesPreset(quiet, meta, 'everything'), false);
    assert.equal(matchesPreset(quiet, meta, 'bogus'), false, 'unknown names never match');

    const custom = applyPreset(baseState(), meta, 'everything');
    custom['chat.message'].inbox = false;
    assert.ok(['everything', 'action_needed', 'quiet'].every((preset) => !matchesPreset(custom, meta, preset)), 'a manual tweak leaves no preset active');
});

test('presetActive follows the live state so the menu can show the current preset', () => {
    const form = component();
    assert.equal(form.presetActive('everything'), false);
    form.applyPreset('quiet');
    assert.equal(form.presetActive('quiet'), true);
    assert.equal(form.presetActive('everything'), false);
    form.applyPreset('everything');
    assert.equal(form.presetActive('everything'), true);
    form.setPopup('chat.message', false);
    assert.equal(form.presetActive('everything'), false);
});

test('channelState reports all / some / none and popup only considers rows with inbox on', () => {
    const state = baseState();

    assert.equal(channelState(state, ALL, 'inbox'), 'some');
    assert.equal(channelState(state, ['quotation.submitted', 'invoice.revision'], 'inbox'), 'all');
    assert.equal(channelState(state, ['invoice.approved'], 'inbox'), 'none');

    assert.equal(channelState(state, ALL, 'popup'), 'some');
    assert.equal(channelState(state, ['quotation.submitted', 'invoice.revision'], 'popup'), 'all');
    assert.equal(channelState(state, ['quotation.submitted', 'invoice.approved'], 'popup'), 'all', 'off row ignored');
    assert.equal(channelState(state, ['invoice.approved'], 'popup'), 'none', 'only an off row: nothing considered');
    assert.equal(channelState(state, ['quotation.revision', 'chat.message'], 'popup'), 'none');

    assert.equal(channelState(state, [], 'inbox'), 'none');
    assert.equal(channelState(state, [], 'popup'), 'none');
});

test('setChannel skips popups on inbox-off rows and leaves the input untouched', () => {
    const state = baseState();
    state['invoice.approved'].popup = false;
    const snapshot = cloneState(state);

    const popupOn = setChannel(state, ALL, 'popup', true);
    assert.equal(popupOn['invoice.approved'].popup, false, 'inbox-off row is skipped');
    assert.ok(ALL.filter((key) => key !== 'invoice.approved').every((key) => popupOn[key].popup));

    const inboxOff = setChannel(state, ['quotation.submitted'], 'inbox', false);
    assert.equal(inboxOff['quotation.submitted'].inbox, false);
    assert.equal(inboxOff['quotation.revision'].inbox, true, 'rows outside the key list are untouched');

    assert.deepEqual(state, snapshot);
});

test('fill replaces placeholders, longest name first, and leaves unknown ones alone', () => {
    assert.equal(fill(':on of :total on', { on: 3, total: 5 }), '3 of 5 on');
    assert.equal(fill(':one / :on', { on: 'A', one: 'B' }), 'B / A');
    assert.equal(fill(':one / :on', { one: 'B', on: 'A' }), 'B / A', 'independent of key insertion order');
    assert.equal(fill(':foo and :on', { on: 0 }), ':foo and 0');
    assert.equal(fill(undefined, { on: 1 }), '');
});

test('component starts on the configured scope tab and does not mutate the config state', () => {
    const config = { meta, categories, state: baseState(), copy, scopeTab: 'import' };
    const form = notificationPreferencesForm(config);

    assert.deepEqual(visibleKeys(form.visible), ['quotation.submitted', 'quotation.revision', 'chat.message']);
    assert.equal(form.visibleCount, 3);
    assert.equal(component().visibleCount, 5, 'default scope shows everything');

    form.setInbox('quotation.submitted', false);
    assert.deepEqual(config.state, baseState());
    assert.equal(form.initial['quotation.submitted'].inbox, true);
});

test('dirtyCount tracks delivery changes and discard restores the initial state', () => {
    const form = component();
    assert.equal(form.dirtyCount, 0);

    form.setInbox('quotation.revision', false);
    form.setPopup('quotation.submitted', false);
    assert.equal(form.dirtyCount, 2);
    form.setPopup('invoice.approved', false); // inbox is off: not a delivery change
    assert.equal(form.dirtyCount, 2);
    assert.equal(form.unsavedText(), 'Unsaved changes: 2');

    form.query = 'zzz';
    form.discard();
    assert.deepEqual(form.state, baseState());
    assert.equal(form.dirtyCount, 0);
    assert.equal(form.visibleCount, 0, 'discard re-applies the active filters');
    form.state['chat.message'].inbox = false;
    assert.equal(form.initial['chat.message'].inbox, true, 'state is a copy, initial stays intact');
});

test('rows toggled while a filter is active stay put until filters are re-applied', () => {
    const form = component();
    form.setFilter('off');
    assert.deepEqual(visibleKeys(form.visible), ['invoice.approved']);

    form.setInbox('invoice.approved', true);
    assert.equal(form.visible['invoice.approved'], true, 'row must not vanish from under the cursor');
    assert.equal(form.visibleCount, 1);

    form.applyFilters();
    assert.equal(form.visible['invoice.approved'], false);
    assert.equal(form.shownMessage, '0 of 5 notifications shown');
});

test('applyPreset on the component re-applies the filters and setFilter rejects unknown values', () => {
    const form = component();
    form.setFilter('off');
    assert.deepEqual(visibleKeys(form.visible), ['invoice.approved']);

    form.applyPreset('everything');
    assert.ok(ALL.every((key) => form.state[key].inbox));
    assert.equal(form.visibleCount, 0, 'no row is off any more');

    form.setFilter('bogus');
    assert.equal(form.filter, 'all');
    assert.equal(form.visibleCount, 5);
    assert.equal(form.shownMessage, '');
});

test('bulk helpers act on visible rows only', () => {
    const form = component();
    form.setFilter('inbox'); // silent rows: quotation.revision, chat.message
    assert.deepEqual(form.visibleKeys('commercial'), ['quotation.revision', 'chat.message']);
    assert.equal(form.bulkState('commercial', 'inbox'), 'all');
    assert.equal(form.bulkState('commercial', 'popup'), 'none');
    assert.equal(form.bulkDisabled('commercial', 'popup'), false);

    form.setBulk('commercial', 'popup', true);
    assert.equal(form.bulkState('commercial', 'popup'), 'all');
    assert.equal(form.state['invoice.revision'].popup, true, 'other categories untouched');

    form.setBulk('commercial', 'inbox', false);
    assert.equal(form.state['quotation.revision'].inbox, false);
    assert.equal(form.state['chat.message'].inbox, false);
    assert.equal(form.state['quotation.submitted'].inbox, true, 'hidden row untouched');
    assert.equal(form.bulkDisabled('commercial', 'popup'), true, 'popup needs a visible row with inbox on');

    form.setFilter('all');
    form.setScope('import');
    assert.equal(form.bulkDisabled('invoice', 'inbox'), true, 'no visible rows in that category');
    assert.equal(form.bulkDisabled('invoice', 'popup'), true);
    assert.equal(form.bulkState('invoice', 'inbox'), 'none');
    const before = cloneState(form.state);
    form.setBulk('invoice', 'inbox', false);
    assert.deepEqual(form.state, before);
});

test('filterCounts follow the scope tab so a segment number equals the rows a click reveals', () => {
    const state = baseState();
    assert.deepEqual(filterCounts(state, meta, 'all'), { all: 5, off: 1, inbox: 2 });
    assert.deepEqual(filterCounts(state, meta, 'import'), { all: 3, off: 0, inbox: 2 });
    assert.deepEqual(filterCounts(state, meta, 'local'), { all: 3, off: 1, inbox: 1 });

    // The numbers must agree with computeVisible for the same filter and scope.
    for (const scope of ['all', 'import', 'local']) {
        const counts = filterCounts(state, meta, scope);
        assert.equal(visibleKeys(computeVisible(state, meta, { query: '', filter: 'all', scope })).length, counts.all);
        assert.equal(visibleKeys(computeVisible(state, meta, { query: '', filter: 'off', scope })).length, counts.off);
        assert.equal(visibleKeys(computeVisible(state, meta, { query: '', filter: 'inbox', scope })).length, counts.inbox);
    }
});

test('component filterCount reacts to toggles and the scope tab but ignores search text', () => {
    const form = component();
    assert.equal(form.filterCount('all'), 5);
    assert.equal(form.filterCount('off'), 1);
    assert.equal(form.filterCount('inbox'), 2);

    form.setInbox('quotation.submitted', false);
    assert.equal(form.filterCount('off'), 2);

    form.setScope('import');
    assert.equal(form.filterCount('all'), 3);
    assert.equal(form.filterCount('off'), 1);

    form.query = 'invoice';
    form.applyFilters();
    assert.equal(form.filterCount('all'), 3, 'search narrows the rows, not the segment numbers');
    assert.equal(form.filterCount('unknown'), 0);
});

test('count and message texts use the supplied copy', () => {
    const form = component();
    assert.equal(form.summaryText(), '4 of 5 on · 2 inbox only · 1 off');
    assert.equal(form.categoryCountText('commercial'), '3 of 3 on');
    assert.equal(form.categoryCountText('invoice'), '1 of 2 on');
    assert.equal(form.categoryCountText('missing'), '0 of 0 on');
    assert.equal(form.unsavedText(), 'Unsaved changes: 0');
    assert.equal(form.shownMessage, '');

    form.query = 'revision';
    form.applyFilters();
    assert.equal(form.shownMessage, '2 of 5 notifications shown');

    form.setScope('import');
    assert.equal(form.shownMessage, '1 of 3 notifications shown', 'total is limited to the active scope');

    form.clearFilters();
    assert.equal(form.shownMessage, '');
    assert.equal(form.isFiltering(), false);
});

test('init registers window/document listeners and destroy removes exactly those', () => {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const calls = [];
    const target = (name) => ({
        addEventListener: (type, handler, options) => calls.push(['add', name, type, handler, options]),
        removeEventListener: (type, handler, options) => calls.push(['remove', name, type, handler, options]),
    });
    globalThis.window = target('window');
    globalThis.document = target('document');

    try {
        const form = component({ scopeTab: 'local' });
        form.init();
        assert.deepEqual(visibleKeys(form.visible), ['invoice.approved', 'invoice.revision', 'chat.message']);

        const added = calls.filter(([kind]) => kind === 'add');
        assert.deepEqual(added.map(([, name, type]) => [name, type]), [['window', 'beforeunload'], ['document', 'submit']]);
        const beforeUnload = added[0][3];
        const onSubmit = added[1][3];

        const clean = { prevented: false, preventDefault() { this.prevented = true; } };
        beforeUnload(clean);
        assert.equal(clean.prevented, false, 'no warning without unsaved changes');

        form.setInbox('chat.message', false);
        const dirty = { prevented: false, returnValue: undefined, preventDefault() { this.prevented = true; } };
        beforeUnload(dirty);
        assert.equal(dirty.prevented, true);
        assert.equal(dirty.returnValue, '');

        onSubmit();
        const submitting = { prevented: false, preventDefault() { this.prevented = true; } };
        beforeUnload(submitting);
        assert.equal(submitting.prevented, false, 'a deliberate submit is not warned about');

        form.destroy();
        const removed = calls.filter(([kind]) => kind === 'remove');
        assert.deepEqual(removed.map(([, name, type, handler, options]) => [name, type, handler, options]), [
            ['window', 'beforeunload', beforeUnload, added[0][4]],
            ['document', 'submit', onSubmit, true],
        ]);
        assert.equal(form.cleanups.length, 0);
    } finally {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    }
});
