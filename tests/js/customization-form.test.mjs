import test from 'node:test';
import assert from 'node:assert/strict';
import { customizationForm, sameOrder, sameSet } from '../../resources/js/customization-form.js';

test('sameOrder requires identical length and position', () => {
    assert.equal(sameOrder(['a', 'b', 'c'], ['a', 'b', 'c']), true);
    assert.equal(sameOrder(['a', 'b', 'c'], ['a', 'c', 'b']), false);
    assert.equal(sameOrder(['a', 'b'], ['a', 'b', 'c']), false);
    assert.equal(sameOrder([], []), true);
});

test('sameSet ignores order but requires the same membership', () => {
    assert.equal(sameSet(['a', 'b', 'c'], ['c', 'a', 'b']), true);
    assert.equal(sameSet(['a', 'b'], ['a', 'b', 'c']), false);
    assert.equal(sameSet(['a', 'b'], ['a', 'c']), false);
    assert.equal(sameSet([], []), true);
});

const densityKeys = ['spacious', 'comfortable', 'balanced', 'compact', 'dense'];

function settingsFixture(overrides = {}, draft = {}) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const saved = {
        theme: 'light', accent: 'brand', density: 'comfortable', sidebar_state: 'expanded', page_size: '25',
        locale: 'en', timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'system',
        dashboardOrder: [], dashboardHidden: [], quickAccess: [], ...overrides,
    };
    const fields = Object.fromEntries(Object.entries({ ...saved, ...draft }).filter(([, value]) => !Array.isArray(value)).map(([name, value]) => [name, {
        name, value, type: 'select-one', disabled: false, hidden: false,
        hasAttribute: () => false,
        closest: () => null,
    }]));
    const slider = {
        name: '', type: 'range', value: '2', disabled: true, hidden: true, attributes: {},
        hasAttribute: (name) => name === 'data-density-slider',
        setAttribute(name, value) { this.attributes[name] = value; },
    };
    const label = { textContent: '' };
    const densityUnsaved = { hidden: true };
    const status = { textContent: '' };
    const root = new EventTarget();
    root.querySelectorAll = () => [];
    root.querySelector = (selector) => {
        if (selector === '[data-density-slider]') return slider;
        if (selector === '[data-density-label]') return label;
        if (selector === '[data-density-unsaved]') return densityUnsaved;
        if (selector === '[data-settings-status]') return status;
        if (selector.endsWith(':checked')) return null;
        const name = selector.match(/\[name="([^"]+)"\]/)?.[1];
        return fields[name] || null;
    };
    const browser = new EventTarget();
    const calls = [];
    const effective = { theme: saved.theme, accent: saved.accent, density: saved.density };
    const navigation = { dirty: false, submitting: false };
    browser.AdasiPreferences = {
        ...effective,
        previewTheme(value) { effective.theme = value; calls.push(['theme', value]); },
        previewAccent(value) { effective.accent = value; calls.push(['accent', value]); },
        previewDensity(value) { effective.density = value; calls.push(['density', value]); },
        restoreSaved() { Object.assign(effective, { theme: saved.theme, accent: saved.accent, density: saved.density }); },
    };
    browser.AdasiUnsaved = {
        markDirty() { navigation.dirty = true; },
        markClean() { navigation.dirty = false; },
        setSubmitting(value) { navigation.submitting = value; if (value) navigation.dirty = false; },
        isDirty() { return navigation.dirty && !navigation.submitting; },
    };
    globalThis.window = browser;
    globalThis.document = { querySelectorAll: () => [] };
    const form = customizationForm({
        saved,
        defaults: { ...saved, theme: 'system', accent: 'brand', density: 'comfortable', sidebar_state: 'expanded', page_size: '25' },
        densityKeys,
        densityLabels: Object.fromEntries(densityKeys.map(key => [key, key[0].toUpperCase() + key.slice(1)])),
        otherContextCount: 0, quickAccessLimit: 6, hasServerErrors: false,
        copy: { densityValue: ':label, :position of :count', sectionReset: 'Defaults loaded', discarded: 'Discarded' },
    });
    form.$root = root;
    const emit = (target, type) => {
        const event = new Event(type, { cancelable: true });
        Object.defineProperty(event, 'target', { value: target });
        root.dispatchEvent(event);
        return event;
    };
    return {
        form, fields, slider, label, densityUnsaved, status, browser, calls, effective, navigation, emit,
        cleanup() {
            form.destroy?.();
            globalThis.window = previousWindow;
            globalThis.document = previousDocument;
        },
    };
}

test('form range input projects one canonical draft and one preview per stop', () => {
    const fixture = settingsFixture();
    const { form, fields, slider, label, calls, effective, emit } = fixture;
    try {
        form.init();
        assert.equal(form.saveDisabled, true);
        assert.equal(fixture.densityUnsaved.hidden, true);
        calls.length = 0;
        densityKeys.forEach((density, index) => {
            slider.value = String(index + 1);
            emit(slider, 'input');
            emit(slider, 'change');
            assert.equal(fields.density.value, density);
            assert.equal(effective.density, density);
            assert.equal(label.textContent, density[0].toUpperCase() + density.slice(1));
            assert.equal(fixture.densityUnsaved.hidden, density === 'comfortable');
            assert.equal(slider.attributes['aria-valuetext'], `${label.textContent}, ${index + 1} of 5`);
        });
        assert.deepEqual(calls, densityKeys.map(density => ['density', density]));
        assert.equal(form.saved.density, 'comfortable');
        assert.equal(form.dirtyCount, 1);
        assert.equal(fields.density.disabled, false, 'canonical form value must still be submitted');
    } finally { fixture.cleanup(); }
});

test('returning to saved density clears only its own dirty contribution', () => {
    const fixture = settingsFixture();
    const { form, fields, slider, navigation, emit } = fixture;
    try {
        form.init();
        slider.value = '5'; emit(slider, 'input');
        fields.page_size.value = '50'; emit(fields.page_size, 'change');
        assert.equal(form.dirtyCount, 2);
        slider.value = '2'; emit(slider, 'input');
        assert.equal(form.dirtyCount, 1);
        assert.equal(navigation.dirty, true);
        fields.page_size.value = '25'; emit(fields.page_size, 'change');
        assert.equal(form.dirtyCount, 0);
        assert.equal(navigation.dirty, false);
    } finally { fixture.cleanup(); }
});

test('reset and discard restore range, canonical value and independent appearance axes', () => {
    const fixture = settingsFixture({ density: 'compact', theme: 'dark', accent: 'slate' });
    const { form, fields, slider, effective } = fixture;
    try {
        form.init();
        form.previewDensityPosition('5');
        fields.theme.value = 'light'; form.previewTheme('light');
        assert.equal(effective.density, 'dense');
        assert.equal(effective.accent, 'slate');
        form.resetSection('appearance');
        assert.equal(effective.density, 'comfortable');
        assert.equal(slider.value, '2');
        assert.equal(fields.density.value, 'comfortable');
        assert.ok(form.dirtyCount > 0);
        form.discard();
        assert.equal(effective.density, 'compact');
        assert.equal(effective.theme, 'dark');
        assert.equal(effective.accent, 'slate');
        assert.equal(slider.value, '4');
        assert.equal(fields.density.value, 'compact');
        assert.equal(form.dirtyCount, 0);
    } finally { fixture.cleanup(); }
});

test('invalid slider positions cannot become canonical density values', () => {
    const fixture = settingsFixture();
    try {
        fixture.form.init();
        fixture.calls.length = 0;
        for (const value of [0, 6, 1.5, '', 'tiny', null, undefined, [], ['3'], {}]) {
            fixture.form.previewDensityPosition(value);
            assert.equal(fixture.fields.density.value, 'comfortable');
        }
        assert.deepEqual(fixture.calls, []);
    } finally { fixture.cleanup(); }
});

test('history restoration resets appearance but keeps other drafts dirty and respects confirmed leave', () => {
    const fixture = settingsFixture();
    const { form, fields, slider, browser, emit } = fixture;
    try {
        form.init();
        slider.value = '5'; emit(slider, 'input');
        fields.page_size.value = '50'; emit(fields.page_size, 'change');
        browser.AdasiPreferences.restoreSaved();
        browser.dispatchEvent(new Event('adasi:preferences-restored'));
        assert.equal(fields.density.value, 'comfortable');
        assert.equal(slider.value, '2');
        assert.equal(fields.page_size.value, '50');
        assert.equal(form.dirtyCount, 1);
        const blocked = new Event('beforeunload', { cancelable: true });
        browser.dispatchEvent(blocked);
        assert.equal(blocked.defaultPrevented, true);
        browser.AdasiUnsaved.markClean();
        const confirmedLeave = new Event('beforeunload', { cancelable: true });
        browser.dispatchEvent(confirmedLeave);
        assert.equal(confirmedLeave.defaultPrevented, false, 'do not show a second native confirmation');
    } finally { fixture.cleanup(); }
});

test('cancelled submit and validation recovery preserve the unsaved preview', async () => {
    const fixture = settingsFixture();
    const { form, root, slider, browser, navigation, emit } = { ...fixture, root: fixture.form.$root };
    try {
        form.init();
        slider.value = '5'; emit(slider, 'input');
        browser.AdasiUnsaved.setSubmitting(true);
        const cancelled = new Event('submit', { cancelable: true });
        root.dispatchEvent(cancelled);
        cancelled.preventDefault();
        await Promise.resolve();
        assert.equal(form.isSubmitting, false);
        assert.equal(slider.disabled, false);
        assert.equal(navigation.dirty, true);
        root.dispatchEvent(new Event('submit', { cancelable: true }));
        await Promise.resolve();
        assert.equal(form.isSubmitting, true);
        assert.equal(slider.disabled, true);
        assert.equal(form.saveDisabled, true, 'pending submission must not expose another Save');
        root.dispatchEvent(new Event('adasi:form-settled'));
        assert.equal(form.isSubmitting, false);
        assert.equal(slider.disabled, false);
        assert.equal(form.saved.density, 'comfortable');
        assert.equal(fixture.fields.density.value, 'dense');
        assert.equal(form.saveDisabled, false);
    } finally { fixture.cleanup(); }
});

test('form init is idempotent and destroy releases its preview handlers', () => {
    const fixture = settingsFixture();
    try {
        fixture.form.init();
        fixture.form.init();
        fixture.calls.length = 0;
        fixture.slider.value = '5'; fixture.emit(fixture.slider, 'input');
        assert.deepEqual(fixture.calls, [['density', 'dense']]);
        fixture.form.destroy();
        fixture.slider.value = '1'; fixture.emit(fixture.slider, 'input');
        assert.deepEqual(fixture.calls, [['density', 'dense']]);
    } finally { fixture.cleanup(); }
});

test('a valid rejected draft starts dirty and uses the same effective preview as the form', () => {
    const fixture = settingsFixture({}, { density: 'balanced', theme: 'dark', accent: 'slate' });
    try {
        fixture.form.hasServerErrors = true;
        fixture.form.init();
        assert.equal(fixture.effective.density, 'balanced');
        assert.equal(fixture.effective.theme, 'dark');
        assert.equal(fixture.effective.accent, 'slate');
        assert.equal(fixture.form.saved.density, 'comfortable');
        assert.equal(fixture.form.dirtyCount, 3);
        fixture.form.discard();
        assert.equal(fixture.form.dirtyCount, 0);
        assert.equal(fixture.form.saveDisabled, false, 'server errors keep retry available');
    } finally { fixture.cleanup(); }
});
