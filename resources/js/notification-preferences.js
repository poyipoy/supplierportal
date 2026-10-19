/**
 * ADASI Portal Supplier: Notification preferences page.
 *
 * Registered globally as the Alpine component `notificationPreferencesForm`
 * (see app.js), following the customizationForm precedent. State is a plain
 * map of event key to { inbox, popup }; the DOM is only ever a projection of
 * it, so presets, bulk toggles and Discard never have to patch text or styles
 * by hand.
 *
 * Channel model (mirrors App\Listeners\ApplyNotificationPreferences):
 *   off     inbox = false                  no database, no broadcast
 *   silent  inbox = true,  popup = false   database only
 *   normal  inbox = true,  popup = true    database + broadcast
 * A popup cannot exist without an inbox entry, so the popup value of an "off"
 * row is remembered in memory but never counts towards anything.
 *
 * The pure helpers below carry the logic and are covered by
 * tests/js/notification-preferences.test.mjs.
 */

export const FILTERS = ['all', 'off', 'inbox'];

export function cloneState(state) {
    const copy = {};
    for (const key of Object.keys(state)) {
        copy[key] = { inbox: Boolean(state[key].inbox), popup: Boolean(state[key].popup) };
    }
    return copy;
}

export function effectiveMode(row) {
    if (!row.inbox) return 'off';
    return row.popup ? 'normal' : 'silent';
}

export function summarize(state) {
    const keys = Object.keys(state);
    let on = 0;
    let inboxOnly = 0;
    let off = 0;
    for (const key of keys) {
        const mode = effectiveMode(state[key]);
        if (mode === 'off') {
            off += 1;
        } else {
            on += 1;
            if (mode === 'silent') inboxOnly += 1;
        }
    }
    return { total: keys.length, on, inboxOnly, off };
}

/** Rows whose delivery actually differs from the initial state. */
export function dirtyKeys(state, initial) {
    return Object.keys(initial).filter((key) => state[key] && effectiveMode(state[key]) !== effectiveMode(initial[key]));
}

export function rowMatches(row, meta, criteria) {
    const query = String(criteria.query || '').trim().toLowerCase();
    if (query && !String(meta.search || '').includes(query)) return false;

    const mode = effectiveMode(row);
    if (criteria.filter === 'off' && mode !== 'off') return false;
    if (criteria.filter === 'inbox' && mode !== 'silent') return false;

    const scope = criteria.scope;
    if (scope && scope !== 'all' && meta.scope !== scope && meta.scope !== 'general') return false;

    return true;
}

export function computeVisible(state, metaByKey, criteria) {
    const visible = {};
    for (const key of Object.keys(metaByKey)) {
        visible[key] = state[key] ? rowMatches(state[key], metaByKey[key], criteria) : false;
    }
    return visible;
}

/**
 * Counts shown on the filter segments. They follow the active scope tab, so the
 * number on a segment equals the rows a click on it would reveal.
 */
export function filterCounts(state, metaByKey, scope) {
    const counts = { all: 0, off: 0, inbox: 0 };
    for (const key of Object.keys(metaByKey)) {
        if (!state[key] || !rowMatches(state[key], metaByKey[key], { query: '', filter: 'all', scope })) continue;
        counts.all += 1;
        const mode = effectiveMode(state[key]);
        if (mode === 'off') counts.off += 1;
        else if (mode === 'silent') counts.inbox += 1;
    }
    return counts;
}

export const PRESETS = ['everything', 'action_needed', 'quiet'];

/**
 * A preset describes the complete end state, so it lands on the same result whatever was chosen before.
 * (Presets that only touched part of the state did nothing after another preset: "everything" after "quiet"
 * changed no row because every inbox was already on.)
 *   everything     inbox and popup on for every row
 *   action_needed  inbox and popup on for action-required rows, every other row off
 *   quiet          inbox on for every row, popup only for action-required rows
 */
export function applyPreset(state, metaByKey, preset) {
    const next = cloneState(state);
    for (const key of Object.keys(next)) {
        const action = Boolean(metaByKey[key] && metaByKey[key].action);
        if (preset === 'quiet') {
            next[key].inbox = true;
            next[key].popup = action;
        } else if (preset === 'action_needed') {
            next[key].inbox = action;
            next[key].popup = true;
        } else if (preset === 'everything') {
            next[key].inbox = true;
            next[key].popup = true;
        }
    }
    return next;
}

/** True when the state already equals what the preset would produce; drives the check mark in the menu. */
export function matchesPreset(state, metaByKey, preset) {
    return PRESETS.includes(preset) && dirtyKeys(applyPreset(state, metaByKey, preset), state).length === 0;
}

/** 'all' | 'some' | 'none' for one channel over a set of rows. Popups only count rows with an inbox entry. */
export function channelState(state, keys, channel) {
    const considered = channel === 'popup' ? keys.filter((key) => state[key].inbox) : keys;
    if (considered.length === 0) return 'none';
    const on = considered.filter((key) => state[key][channel]).length;
    if (on === 0) return 'none';
    return on === considered.length ? 'all' : 'some';
}

export function setChannel(state, keys, channel, value) {
    const next = cloneState(state);
    for (const key of keys) {
        if (channel === 'popup' && !next[key].inbox) continue;
        next[key][channel] = Boolean(value);
    }
    return next;
}

/** Replace `:name` placeholders, longest names first so one never clobbers another. */
export function fill(template, values) {
    return Object.keys(values)
        .sort((left, right) => right.length - left.length)
        .reduce((text, name) => text.split(`:${name}`).join(String(values[name])), String(template || ''));
}

export function notificationPreferencesForm(config) {
    const meta = config.meta || {};
    const categories = config.categories || {};
    const initial = cloneState(config.state || {});
    const startScope = config.scopeTab || 'all';

    return {
        meta,
        categories,
        copy: config.copy || {},
        total: Object.keys(meta).length,
        initial,
        state: cloneState(initial),
        query: '',
        filter: 'all',
        scopeTab: startScope,
        visible: computeVisible(initial, meta, { query: '', filter: 'all', scope: startScope }),
        shownMessage: '',
        isSubmitting: false,
        cleanups: [],
        initialized: false,

        init() {
            if (this.initialized) return;
            this.initialized = true;
            this.applyFilters();
            const unregister = window.AdasiUnsaved?.registerDirtySource?.(
                () => !this.isSubmitting && this.dirtyCount > 0, this.$root
            );
            if (unregister) this.cleanups.push(unregister);
            this.listen(window, 'beforeunload', (event) => {
                if (this.dirtyCount > 0 && !this.isSubmitting) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            // Any form on the page (save or the reset dialog) is a deliberate departure.
            this.listen(document, 'submit', (event) => {
                this.isSubmitting = true;
                if (event) queueMicrotask(() => {
                    if (event.defaultPrevented) this.recoverSubmission();
                });
            }, true);
            if (this.$root) {
                this.listen(this.$root, 'invalid', () => this.recoverSubmission(), true);
                this.listen(this.$root, 'adasi:form-settled', () => this.recoverSubmission());
            }
        },

        destroy() {
            this.cleanups.splice(0).forEach((cleanup) => cleanup());
            this.initialized = false;
        },

        listen(target, type, handler, options) {
            target.addEventListener(type, handler, options);
            this.cleanups.push(() => target.removeEventListener(type, handler, options));
        },

        recoverSubmission() {
            this.isSubmitting = false;
            window.AdasiUnsaved?.setSubmitting(false);
        },

        // --- derived state -------------------------------------------------

        get dirtyCount() {
            return dirtyKeys(this.state, this.initial).length;
        },

        get visibleCount() {
            return Object.values(this.visible).filter(Boolean).length;
        },

        isFiltering() {
            return this.query.trim() !== '' || this.filter !== 'all';
        },

        filterCount(filter) {
            return filterCounts(this.state, this.meta, this.scopeTab)[filter] ?? 0;
        },

        summaryText() {
            const { total, on, inboxOnly, off } = summarize(this.state);
            return fill(this.copy.summary, { enabled: on, total, silent: inboxOnly, off });
        },

        unsavedText() {
            return fill(this.copy.unsaved, { count: this.dirtyCount });
        },

        categoryCountText(categoryId) {
            const keys = this.categories[categoryId] || [];
            const on = keys.filter((key) => this.state[key].inbox).length;
            return fill(this.copy.categorySummary, { on, total: keys.length });
        },

        categoryVisible(categoryId) {
            return (this.categories[categoryId] || []).some((key) => this.visible[key]);
        },

        visibleKeys(categoryId) {
            return (this.categories[categoryId] || []).filter((key) => this.visible[key]);
        },

        // --- single rows ---------------------------------------------------

        setInbox(key, value) {
            this.state[key].inbox = Boolean(value);
        },

        setPopup(key, value) {
            this.state[key].popup = Boolean(value);
        },

        // --- category header checkboxes (act on visible rows only) ---------

        bulkState(categoryId, channel) {
            return channelState(this.state, this.visibleKeys(categoryId), channel);
        },

        bulkDisabled(categoryId, channel) {
            const keys = this.visibleKeys(categoryId);
            if (channel === 'popup') return !keys.some((key) => this.state[key].inbox);
            return keys.length === 0;
        },

        setBulk(categoryId, channel, value) {
            this.state = setChannel(this.state, this.visibleKeys(categoryId), channel, value);
        },

        // --- toolbar -------------------------------------------------------

        applyFilters() {
            const criteria = { query: this.query, filter: this.filter, scope: this.scopeTab };
            this.visible = computeVisible(this.state, this.meta, criteria);

            if (!this.isFiltering()) {
                this.shownMessage = '';
                return;
            }
            const inScope = computeVisible(this.state, this.meta, { query: '', filter: 'all', scope: this.scopeTab });
            this.shownMessage = fill(this.copy.shown, {
                shown: this.visibleCount,
                total: Object.values(inScope).filter(Boolean).length,
            });
        },

        setFilter(filter) {
            this.filter = FILTERS.includes(filter) ? filter : 'all';
            this.applyFilters();
        },

        setScope(scope) {
            this.scopeTab = scope;
            this.applyFilters();
        },

        clearFilters() {
            this.query = '';
            this.filter = 'all';
            this.applyFilters();
        },

        applyPreset(preset) {
            this.state = applyPreset(this.state, this.meta, preset);
            this.applyFilters();
        },

        presetActive(preset) {
            return matchesPreset(this.state, this.meta, preset);
        },

        discard() {
            this.state = cloneState(this.initial);
            this.applyFilters();
        },
    };
}
