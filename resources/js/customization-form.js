/**
 * ADASI Portal Supplier — Customization page interaction
 *
 * Registered globally as the Alpine component `customizationForm` (see app.js),
 * matching the adasiShell/adasiToastCenter precedent in this project: keeping
 * this logic in a bundled module (rather than inline in the Blade view, as
 * notificationPreferencesForm does) avoids leaking CSS-selector string
 * literals into the page's raw HTML, which previously tripped
 * UserDashboardUiTest's assertDontSee('data-dashboard-controls') assertion
 * for suppliers without an operational dashboard.
 */

import { syncWireframe } from './dashboard-wireframe.js';

export function sameOrder(a, b) {
    return a.length === b.length && a.every((value, index) => value === b[index]);
}

export function sameSet(a, b) {
    if (a.length !== b.length) return false;
    const sortedA = [...a].sort();
    const sortedB = [...b].sort();
    return sortedA.every((value, index) => value === sortedB[index]);
}

const SIMPLE_FIELDS = ['theme', 'shell_style', 'accent', 'density', 'sidebar_state', 'quick_flow', 'page_size', 'locale', 'timezone', 'date_format', 'time_format', 'number_format', 'country_code', 'import_timezone'];

const DENSITY_SPECS = {
    spacious: { row: 44, cell: 12 },
    comfortable: { row: 40, cell: 10 },
    balanced: { row: 36, cell: 8 },
    compact: { row: 32, cell: 6 },
    dense: { row: 28, cell: 4 },
};

function normalizeComparableValue(raw, reference) {
    if (typeof reference === 'boolean') {
        return raw === true || raw === 'true' || raw === '1' || raw === 1 ? '1' : '0';
    }
    return String(raw ?? '');
}

export function customizationForm(config) {
    return {
        saved: config.saved,
        defaults: config.defaults,
        densityKeys: config.densityKeys || [],
        densityLabels: config.densityLabels || {},
        otherContextCount: config.otherContextCount,
        quickAccessLimit: config.quickAccessLimit,
        hasServerErrors: config.hasServerErrors,
        sampleIso: config.sampleIso,
        sampleNumber: config.sampleNumber,
        registry: config.registry,
        importTimezone: config.importTimezone || null,
        importAutoNote: '',
        docLocale: config.locale,
        copy: config.copy,
        dirtyCount: 0,
        quickAccessSelectedCount: 0,
        regionalSample: '',
        isSubmitting: false,
        initialized: false,
        cleanups: [],
        navigationDirty: null,

        root() {
            return this.$root;
        },

        init() {
            if (this.initialized) return;
            this.initialized = true;
            this.navigationDirty = null;
            this.syncDashboardVisibilityControls();
            for (const [name, method] of Object.entries({ theme: 'previewTheme', shell_style: 'previewShellStyle', accent: 'previewAccent', density: 'previewDensity' })) {
                if (!this.hasField(name)) continue;
                const value = this.val(name);
                if (value !== this.saved[name] && (name !== 'density' || this.densityKeys.includes(value))) {
                    window.AdasiPreferences?.[method]?.(value);
                }
            }
            this.recompute();
            this.observeSections();
            this.listen(this.root(), 'input', (event) => {
                if (event.target.hasAttribute?.('data-density-slider')) this.previewDensityPosition(event.target.value);
            });
            this.listen(this.root(), 'click', (event) => {
                const stopBtn = event.target.closest?.('[data-density-stop]');
                if (stopBtn && !this.isSubmitting) {
                    this.previewDensity(stopBtn.dataset.densityStop);
                }
            });
            this.listen(this.root(), 'change', (event) => this.changed(event.target));
            this.listen(this.root(), 'adasi:dashboard-order-change', () => this.recompute());
            this.listen(this.root(), 'submit', (event) => {
                // Other form handlers may cancel after this listener has run.
                queueMicrotask(() => {
                    if (!this.initialized) return;
                    if (event.defaultPrevented) this.recoverSubmission();
                    else {
                        this.isSubmitting = true;
                        this.syncDensityControl();
                    }
                });
            });
            this.listen(this.root(), 'invalid', () => this.recoverSubmission(), true);
            this.listen(this.root(), 'adasi:form-settled', () => this.recoverSubmission());
            this.listen(window, 'adasi:preferences-restored', () => {
                ['theme', 'shell_style', 'accent', 'density'].forEach((name) => this.setVal(name, this.saved[name]));
                this.recoverSubmission();
            });
            this.listen(window, 'adasi:locale-changed', (event) => {
                const nextLocale = event.detail?.locale;
                if (nextLocale === 'id' || nextLocale === 'en') {
                    this.docLocale = nextLocale;
                    if (this.saved) this.saved.locale = nextLocale;
                    this.setVal('locale', nextLocale);
                    this.recompute();
                }
            });
            this.listen(window, 'beforeunload', (event) => {
                const needsConfirmation = window.AdasiUnsaved?.isDirty() ?? this.dirtyCount > 0;
                if (needsConfirmation && this.dirtyCount > 0 && !this.isSubmitting) {
                    event.preventDefault();
                    try {
                        event.returnValue = '';
                    } catch (e) {
                        // Synthetic Event instances may expose returnValue as getter-only.
                    }
                }
            });
        },

        listen(target, name, handler, options) {
            target.addEventListener(name, handler, options);
            this.cleanups.push(() => target.removeEventListener(name, handler, options));
        },

        destroy() {
            this.cleanups.forEach((cleanup) => cleanup());
            this.cleanups = [];
            this.sectionObserver?.disconnect();
            this.initialized = false;
        },

        changed(field) {
            if (field.hasAttribute?.('data-density-slider')) return;
            const toggle = field.closest?.('[data-dashboard-visibility-toggle]');
            if (toggle) {
                const hidden = field.closest('[data-dashboard-choice]')?.querySelector('input[name="dashboard[hidden][]"]');
                if (hidden) hidden.checked = !field.checked;
            }
            if (['theme', 'shell_style', 'accent', 'density'].includes(field.name)) {
                if (field.type === 'radio' && !field.checked) return;
                const method = { theme: 'previewTheme', shell_style: 'previewShellStyle', accent: 'previewAccent', density: 'previewDensity' }[field.name];
                this[method](field.value);
            } else {
                this.recompute();
            }
        },

        recoverSubmission() {
            this.isSubmitting = false;
            window.AdasiUnsaved?.setSubmitting(false);
            this.navigationDirty = null;
            this.recompute();
        },

        hasField(name) {
            const root = this.root();
            if (!root) return false;
            return Boolean(root.querySelector(`input[name="${name}"]:checked`) || root.querySelector(`[name="${name}"]`));
        },

        val(name) {
            const checkbox = this.root().querySelector(`input[type="checkbox"][name="${name}"]`);
            if (checkbox && checkbox.type === 'checkbox') {
                return checkbox.checked ? '1' : '0';
            }
            const checked = this.root().querySelector(`input[name="${name}"]:checked`);
            if (checked) return checked.value;
            const field = this.root().querySelector(`[name="${name}"]`);
            return field ? field.value : null;
        },
        setVal(name, value) {
            const checkbox = this.root().querySelector(`input[type="checkbox"][name="${name}"]`);
            if (checkbox && checkbox.type === 'checkbox') {
                checkbox.checked = value === true || value === 'true' || value === '1' || value === 1;
                return;
            }
            const radios = this.root().querySelectorAll(`input[type="radio"][name="${name}"]`);
            if (radios.length) {
                radios.forEach((el) => { el.checked = (el.value === String(value)); });
                return;
            }
            const field = this.root().querySelector(`[name="${name}"]`);
            if (field) field.value = String(value);
            // Searchable selects mirror the native field; keep their visible trigger in step.
            if (field?.matches?.('[x-ref="nativeSelect"]')) {
                window.dispatchEvent(new CustomEvent('set-select-value', { detail: { name, value: String(value) } }));
            }
        },
        dashboardOrder() {
            return Array.from(this.root().querySelectorAll('[data-dashboard-order-input]')).map((el) => el.value);
        },
        dashboardHidden() {
            return Array.from(this.root().querySelectorAll('input[name="dashboard[hidden][]"]:checked')).map((el) => el.value);
        },
        quickAccessSelected() {
            return Array.from(this.root().querySelectorAll('input[name="quick_access[]"]:checked')).map((el) => el.value);
        },

        recompute() {
            // Order, visibility toggles, reset and discard all end here, so the wireframe widths follow them.
            syncWireframe(this.root().querySelector?.('[data-dashboard-controls]'));
            let count = 0;
            SIMPLE_FIELDS.forEach((field) => {
                if (!this.hasField(field)) return;
                if (normalizeComparableValue(this.val(field), this.saved[field]) !== normalizeComparableValue(this.saved[field], this.saved[field])) count++;
            });
            if (!sameOrder(this.dashboardOrder(), this.saved.dashboardOrder) || !sameSet(this.dashboardHidden(), this.saved.dashboardHidden)) {
                count++;
            }
            if (!sameSet(this.quickAccessSelected(), this.saved.quickAccess)) {
                count++;
            }
            this.dirtyCount = count;
            this.quickAccessSelectedCount = this.quickAccessSelected().length + this.otherContextCount;
            this.applyQuickAccessLimit();
            this.updateRegionalSample();
            this.syncDensityControl();
            const dirty = count > 0;
            if (!this.isSubmitting && this.navigationDirty !== dirty) {
                if (dirty) window.AdasiUnsaved?.markDirty();
                else window.AdasiUnsaved?.markClean();
                this.navigationDirty = dirty;
            }
        },

        get saveDisabled() {
            return this.isSubmitting || (this.dirtyCount === 0 && !this.hasServerErrors);
        },

        applyQuickAccessLimit() {
            const atLimit = this.quickAccessSelectedCount >= this.quickAccessLimit;
            this.root().querySelectorAll('input[name="quick_access[]"]').forEach((el) => {
                el.disabled = atLimit && !el.checked;
            });
        },

        previewTheme(value) { this.setVal('theme', value); window.AdasiPreferences?.previewTheme(value); this.recompute(); },
        previewShellStyle(value) { this.setVal('shell_style', value); window.AdasiPreferences?.previewShellStyle?.(value); this.recompute(); },
        previewAccent(value) { this.setVal('accent', value); window.AdasiPreferences?.previewAccent(value); this.recompute(); },
        previewDensity(value) {
            if (!this.densityKeys.includes(value)) return;
            this.setVal('density', value);
            window.AdasiPreferences?.previewDensity(value);
            this.recompute();
        },

        previewDensityPosition(value) {
            if (!['string', 'number'].includes(typeof value) || String(value).trim() === '') return;
            const position = Number(value);
            if (!Number.isInteger(position) || position < 1 || position > this.densityKeys.length) return;
            this.previewDensity(this.densityKeys[position - 1]);
        },

        get densityLabel() {
            const density = this.val('density');
            return this.densityLabels[density] || density;
        },

        get densityDirty() {
            return this.val('density') !== this.saved.density;
        },

        syncDensityControl() {
            const slider = this.root().querySelector('[data-density-slider]');
            if (!slider) return;
            const currentDensity = this.val('density');
            const position = this.densityKeys.indexOf(currentDensity) + 1;
            if (position < 1) return;
            slider.value = String(position);
            slider.hidden = false;
            slider.disabled = this.isSubmitting;
            slider.setAttribute('aria-valuetext', (this.copy.densityValue || ':label, :position/:count')
                .replace(':label', this.densityLabel).replace(':position', position).replace(':count', this.densityKeys.length));
            const canonical = this.root().querySelector('[name="density"]');
            if (canonical?.type === 'select-one') canonical.hidden = true;
            const label = this.root().querySelector('[data-density-label]');
            if (label) label.textContent = this.densityLabel;
            const specEl = this.root().querySelector('[data-density-spec]');
            const spec = DENSITY_SPECS[currentDensity];
            if (specEl && spec) {
                specEl.textContent = (this.copy.densitySpec || ':rowpx row · :cellpx cell')
                    .replace(':row', String(spec.row))
                    .replace(':cell', String(spec.cell));
            }
            this.root().querySelectorAll?.('[data-density-stop]')?.forEach((stop) => {
                const stopDensity = stop.dataset?.densityStop;
                const isActive = stopDensity === currentDensity;
                const isSaved = stopDensity === this.saved?.density;
                stop.dataset.active = isActive ? 'true' : 'false';
                stop.dataset.saved = isSaved ? 'true' : 'false';
                stop.disabled = this.isSubmitting;
                stop.setAttribute?.('aria-pressed', isActive ? 'true' : 'false');
            });
            const unsaved = this.root().querySelector('[data-density-unsaved]');
            if (unsaved) unsaved.hidden = !this.densityDirty;
        },

        updateRegionalSample() {
            if (!window.AdasiRegionalSample) return;
            let timezone = this.val('timezone');
            if (this.importTimezone && window.AdasiRegionalSample.resolveImportTimezone) {
                const countryCode = this.val('country_code');
                const importTimezone = this.val('import_timezone');
                timezone = window.AdasiRegionalSample.resolveImportTimezone({
                    importContext: true, timezone, countryCode, importTimezone,
                    countryDefaults: this.importTimezone.countryDefaults,
                });
                const autoZone = this.importTimezone.countryDefaults?.[countryCode];
                this.importAutoNote = !importTimezone && autoZone ? this.copy.importAutoWithZone.replace(':zone', autoZone) : '';
            }
            this.regionalSample = window.AdasiRegionalSample.computeRegionalSample({
                sampleIso: this.sampleIso,
                sampleNumber: this.sampleNumber,
                regional: {
                    timezone,
                    date_format: this.val('date_format'),
                    time_format: this.val('time_format'),
                    number_format: this.val('number_format'),
                },
                registry: this.registry,
                locale: this.docLocale,
                systemNotice: this.copy.followsCurrentDisplay,
            });
        },

        syncDashboardVisibilityControls() {
            this.root().querySelectorAll('[data-dashboard-choice]').forEach((row) => {
                const checkbox = row.querySelector('input[name="dashboard[hidden][]"]');
                const toggleWrap = row.querySelector('[data-dashboard-visibility-toggle]');
                if (!checkbox || !toggleWrap) return;
                const fallback = row.querySelector('[data-dashboard-hide-fallback]');
                const switchEl = toggleWrap.querySelector('input[type="checkbox"]');
                fallback?.classList.add('tw:hidden');
                toggleWrap.classList.remove('tw:hidden');
                toggleWrap.classList.add('tw:flex');
                switchEl.checked = !checkbox.checked;
            });
        },

        applyDashboardOrder(order) {
            const list = this.root().querySelector('[data-dashboard-controls]');
            if (!list) return;
            order.forEach((key) => {
                const row = list.querySelector(`[data-widget-key="${key}"]`);
                if (row) list.appendChild(row);
            });
        },

        resetSection(section) {
            if (section === 'appearance') {
                this.setVal('theme', this.defaults.theme);
                if (this.hasField('shell_style')) this.setVal('shell_style', this.defaults.shell_style);
                this.setVal('accent', this.defaults.accent);
                this.setVal('density', this.defaults.density);
                this.setVal('sidebar_state', this.defaults.sidebar_state);
                if (this.hasField('quick_flow')) this.setVal('quick_flow', this.defaults.quick_flow ?? false);
                this.setVal('page_size', this.defaults.page_size);
                window.AdasiPreferences?.previewTheme(this.defaults.theme);
                if (this.defaults.shell_style) window.AdasiPreferences?.previewShellStyle?.(this.defaults.shell_style);
                window.AdasiPreferences?.previewAccent(this.defaults.accent);
                window.AdasiPreferences?.previewDensity(this.defaults.density);
            } else if (section === 'region') {
                this.setVal('timezone', this.defaults.timezone);
                this.setVal('date_format', this.defaults.date_format);
                this.setVal('time_format', this.defaults.time_format);
                this.setVal('number_format', this.defaults.number_format);
                if (this.importTimezone) {
                    this.setVal('country_code', this.defaults.country_code);
                    this.setVal('import_timezone', this.defaults.import_timezone);
                }
            } else if (section === 'dashboard') {
                this.applyDashboardOrder(this.defaults.dashboardOrder);
                this.root().querySelectorAll('input[name="dashboard[hidden][]"]').forEach((el) => { el.checked = false; });
                this.syncDashboardVisibilityControls();
            } else if (section === 'quick_access') {
                this.root().querySelectorAll('input[name="quick_access[]"]').forEach((el) => { el.checked = false; });
            }
            this.recompute();
            this.announce(this.copy.sectionReset);
        },

        resetAll() {
            ['appearance', 'region', 'dashboard', 'quick_access'].forEach((section) => this.resetSection(section));
        },

        discard() {
            SIMPLE_FIELDS.forEach((field) => {
                if (this.hasField(field)) this.setVal(field, this.saved[field]);
            });
            this.applyDashboardOrder(this.saved.dashboardOrder);
            this.root().querySelectorAll('input[name="dashboard[hidden][]"]').forEach((el) => {
                el.checked = this.saved.dashboardHidden.includes(el.value);
            });
            this.syncDashboardVisibilityControls();
            const selected = new Set(this.saved.quickAccess);
            this.root().querySelectorAll('input[name="quick_access[]"]').forEach((el) => { el.checked = selected.has(el.value); });
            window.AdasiPreferences?.restoreSaved();
            this.recompute();
            this.announce(this.copy.discarded);
        },

        announce(message) {
            const status = this.root().querySelector('[data-settings-status]');
            if (status) status.textContent = message;
        },

        observeSections() {
            const sectionEls = Array.from(document.querySelectorAll('[data-settings-section]'));
            const navLinks = Array.from(document.querySelectorAll('[data-section-nav-link]'));
            if (!sectionEls.length || !navLinks.length || !('IntersectionObserver' in window)) return;

            const setActive = (id) => {
                navLinks.forEach((link) => {
                    if (link.getAttribute('href') === `#${id}`) {
                        link.setAttribute('aria-current', 'location');
                    } else {
                        link.removeAttribute('aria-current');
                    }
                });
            };

            const observer = new IntersectionObserver((entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                if (visible.length) setActive(visible[0].target.id);
            }, { rootMargin: '-35% 0px -55% 0px', threshold: 0 });

            sectionEls.forEach((el) => observer.observe(el));
            this.sectionObserver = observer;
        },
    };
}
