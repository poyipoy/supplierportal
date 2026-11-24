/**
 * ADASI Searchable Select Component for Alpine.js
 * Supports single-select with search popover, initial options, creatable custom options,
 * and asynchronous contextual suggestions.
 */
export function searchableSelect(config = {}) {
    return {
        open: false,
        openUpwards: false,
        search: '',
        selectedValue: String(config.selectedValue ?? ''),
        options: Array.isArray(config.options) ? config.options : [],
        highlightedIndex: -1,
        allowCustom: Boolean(config.allowCustom),
        suggestionsUrl: config.suggestionsUrl || null,
        remoteSearch: Boolean(config.remoteSearch),
        preloadOnOpen: Boolean(config.preloadOnOpen),
        exportFilterContext: Boolean(config.exportFilterContext),
        isDisabled: Boolean(config.disabled),
        hasAsyncError: false,
        loadingSuggestions: false,
        debounceTimer: null,
        abortController: null,
        latestRequestId: 0,

        init() {
            this.$watch('selectedValue', (val) => {
                if (this.$refs?.nativeSelect && this.$refs.nativeSelect.value !== String(val ?? '')) {
                    this.syncNativeSelect(val);
                }
            });
            const selectEl = this.$refs?.nativeSelect;
            const form = selectEl?.form;
            if (form && typeof form.addEventListener === 'function') {
                const validationKey = String(selectEl.name || '')
                    .replace(/\[([^\]]*)\]/g, '.$1')
                    .replace(/^\.+|\.+$/g, '');
                form.addEventListener('adasi:form-submitting', () => {
                    this.hasAsyncError = false;
                });
                form.addEventListener('adasi:form-errors', (event) => {
                    const errors = event.detail?.errors || {};
                    this.hasAsyncError = Boolean(validationKey && errors[validationKey]);
                });
            }
            if (this.selectedValue) {
                this.ensureCustomOptionPresent(this.selectedValue);
                this.syncNativeSelect(this.selectedValue, false);
            }
            if ((this.allowCustom || this.remoteSearch) && this.suggestionsUrl) {
                this.$watch('search', (val) => {
                    if (this.preloadOnOpen && !this.open) return;
                    this.fetchSuggestions(val, { replaceOptions: this.preloadOnOpen });
                });
            }
        },

        ensureCustomOptionPresent(val) {
            const normalized = String(val ?? '').trim();
            if (!normalized || !(this.allowCustom || this.remoteSearch)) return;
            if (!this.options.some((opt) => String(opt.value) === normalized)) {
                this.options.unshift({
                    value: normalized,
                    label: normalized,
                    sublabel: null,
                    badge: null,
                    badgeTone: 'neutral',
                    searchKeywords: normalized.toLowerCase(),
                });
            }
        },

        setExternalValue(val, silent = false) {
            const nextVal = String(val ?? '');
            if (nextVal) {
                this.ensureCustomOptionPresent(nextVal);
            }
            this.selectedValue = nextVal;
            this.syncNativeSelect(nextVal, !silent);
        },

        buildSuggestionsUrl(query) {
            const origin = globalThis.location?.origin || 'http://localhost';
            const url = new URL(this.suggestionsUrl, origin);
            if (query !== '') {
                url.searchParams.set('q', query);
            }
            if (this.exportFilterContext) {
                const form = this.$el?.closest?.('[data-advanced-export-form]') || this.$refs?.nativeSelect?.form;
                if (form && typeof form.querySelectorAll === 'function') {
                    const selfName = this.$refs?.nativeSelect?.dataset?.filterName || this.$refs?.nativeSelect?.name || '';
                    form.querySelectorAll('[data-filter-name]').forEach((input) => {
                        const filterName = input.dataset?.filterName;
                        if (!filterName || filterName === selfName || filterName === 'search' || filterName === 'q') return;
                        if (!input.disabled && input.value !== undefined && String(input.value) !== '') {
                            url.searchParams.set(filterName, String(input.value));
                        }
                    });
                    ['date_from', 'date_to'].forEach((name) => {
                        const input = form.querySelector?.(`[name="${name}"]`);
                        if (input && !input.disabled && input.value) {
                            url.searchParams.set(name, String(input.value));
                        }
                    });
                    const relativeBox = form.querySelector?.('[data-export-relative]');
                    const daysBox = form.querySelector?.('[data-export-days]');
                    if (relativeBox?.checked && daysBox?.value) {
                        url.searchParams.set('date_range[mode]', 'relative');
                        url.searchParams.set('date_range[days]', String(daysBox.value));
                    }
                }
            }
            return url.toString();
        },

        fetchSuggestions(query, { immediate = false, replaceOptions = false } = {}) {
            if (!this.suggestionsUrl || !(this.allowCustom || this.remoteSearch)) return;
            const q = query ? query.trim() : '';
            if (this.debounceTimer) {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = null;
            }
            this.abortController?.abort();
            this.abortController = null;

            if (!this.preloadOnOpen && q.length < 2) {
                this.latestRequestId++;
                this.loadingSuggestions = false;
                return;
            }

            const execute = async () => {
                this.debounceTimer = null;
                const requestId = ++this.latestRequestId;
                this.loadingSuggestions = true;
                const AbortCtrl = globalThis.AbortController;
                const controller = AbortCtrl ? new AbortCtrl() : null;
                this.abortController = controller;

                try {
                    const targetUrl = this.buildSuggestionsUrl(q);
                    const res = await fetch(targetUrl, {
                        credentials: 'same-origin',
                        priority: 'low',
                        ...(controller ? { signal: controller.signal } : {}),
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (res.ok) {
                        const json = await res.json();
                        if (requestId !== this.latestRequestId) return;
                        const incoming = Array.isArray(json.data) ? json.data : [];
                        if (replaceOptions) {
                            const preservedSelected = this.selectedValue
                                ? this.options.find((opt) => String(opt.value) === String(this.selectedValue))
                                : null;
                            this.options = [];
                            incoming.forEach((item) => this.addSuggestion(item));
                            if (this.selectedValue && !this.options.some((opt) => String(opt.value) === String(this.selectedValue))) {
                                if (preservedSelected) {
                                    this.options.unshift(preservedSelected);
                                } else {
                                    this.ensureCustomOptionPresent(this.selectedValue);
                                }
                            }
                            this.syncNativeSelect(this.selectedValue, false);
                        } else {
                            incoming.forEach((item) => this.addSuggestion(item));
                        }
                    }
                } catch {
                    // Graceful degradation on abort/network/offline
                } finally {
                    if (requestId === this.latestRequestId) {
                        this.loadingSuggestions = false;
                        this.abortController = null;
                    }
                }
            };

            if (immediate) {
                execute();
            } else {
                this.debounceTimer = setTimeout(execute, 250);
            }
        },

        addSuggestion(item) {
            const val = String(item.pr_number || item.value || '');
            if (!val || this.options.some((o) => o.value.toLowerCase() === val.toLowerCase())) return;
            const label = item.label ? String(item.label) : val;
            const sublabel = item.sublabel ? String(item.sublabel) : null;
            const badge = item.badge ? String(item.badge) : null;
            const keywords = item.searchKeywords
                ? String(item.searchKeywords)
                : [label, sublabel, badge].filter(Boolean).join(' ');
            this.options.push({
                value: val,
                label,
                sublabel,
                badge,
                badgeTone: item.badgeTone ? String(item.badgeTone) : 'neutral',
                searchKeywords: keywords.toLowerCase(),
            });
        },

        get filteredOptions() {
            if (!this.search.trim()) return this.options;
            const q = this.search.toLowerCase().trim();
            return this.options.filter(
                (opt) =>
                    (opt.searchKeywords && opt.searchKeywords.includes(q)) ||
                    opt.label.toLowerCase().includes(q) ||
                    (opt.sublabel && opt.sublabel.toLowerCase().includes(q))
            );
        },

        get selectedOption() {
            return this.options.find((opt) => String(opt.value) === String(this.selectedValue)) || null;
        },

        get hasExactMatch() {
            const q = this.search.trim().toLowerCase();
            if (!q) return true;
            return this.options.some((opt) => opt.value.toLowerCase() === q || opt.label.toLowerCase() === q);
        },

        get showCustomOption() {
            return this.allowCustom && this.search.trim().length > 0 && !this.hasExactMatch;
        },

        get totalSelectableCount() {
            return this.filteredOptions.length + (this.showCustomOption ? 1 : 0);
        },

        select(option) {
            this.selectedValue = option ? String(option.value) : '';
            this.hasAsyncError = false;
            this.syncNativeSelect(this.selectedValue);
            this.open = false;
            this.search = '';
            this.highlightedIndex = -1;
            this.$dispatch?.('change', { value: this.selectedValue });
            this.$nextTick?.(() => {
                this.$refs?.triggerButton?.focus();
            });
        },

        selectCustom(val) {
            const trimmed = String(val).trim();
            if (!trimmed) return;
            let opt = this.options.find((o) => o.value.toLowerCase() === trimmed.toLowerCase());
            if (!opt) {
                opt = {
                    value: trimmed,
                    label: trimmed,
                    sublabel: null,
                    badge: null,
                    badgeTone: 'neutral',
                    searchKeywords: trimmed.toLowerCase(),
                };
                this.options.unshift(opt);
            }
            this.select(opt);
        },

        clear() {
            this.select(null);
        },

        toggle() {
            if (this.isDisabled || this.$refs?.nativeSelect?.disabled) return;
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.highlightedIndex = -1;
                if (this.preloadOnOpen && this.suggestionsUrl) {
                    this.fetchSuggestions('', { immediate: true, replaceOptions: true });
                }
                const triggerRect = this.$refs?.triggerButton?.getBoundingClientRect?.();
                const dialogBody = this.$refs?.triggerButton?.closest?.('[role="dialog"] .modal-body, [role="dialog"] .tw\\:overflow-y-auto, [role="dialog"]');
                const boundsBottom = dialogBody?.getBoundingClientRect?.().bottom ?? globalThis.innerHeight ?? 800;
                const boundsTop = dialogBody?.getBoundingClientRect?.().top ?? 0;
                if (triggerRect) {
                    const spaceBelow = boundsBottom - triggerRect.bottom;
                    const spaceAbove = triggerRect.top - boundsTop;
                    this.openUpwards = spaceBelow < 240 && spaceAbove > spaceBelow;
                } else {
                    this.openUpwards = false;
                }
                this.$nextTick?.(() => {
                    this.$refs?.popover?.scrollIntoView?.({ block: 'nearest' });
                    this.$refs?.searchInput?.focus();
                });
            } else {
                this.abortController?.abort();
                this.abortController = null;
            }
        },

        close(focusTrigger = false) {
            this.open = false;
            this.highlightedIndex = -1;
            this.abortController?.abort();
            this.abortController = null;
            if (focusTrigger) {
                this.$nextTick?.(() => {
                    this.$refs?.triggerButton?.focus();
                });
            }
        },

        syncNativeSelect(val, dispatchChange = true) {
            const selectEl = this.$refs?.nativeSelect;
            if (selectEl) {
                if ((this.allowCustom || this.remoteSearch) && val) {
                    const escaped = globalThis.CSS?.escape ? globalThis.CSS.escape(val) : String(val).replace(/["\\]/g, '\\$&');
                    let opt = selectEl.querySelector('option[value="' + escaped + '"]');
                    if (!opt) {
                        const known = this.options.find((o) => String(o.value) === String(val));
                        opt = new Option(known?.label ?? val, val, true, true);
                        selectEl.add(opt);
                    }
                }
                if (selectEl.value !== val) {
                    selectEl.value = val;
                }
                if (dispatchChange) {
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        },

        navigate(step) {
            const count = this.totalSelectableCount;
            if (!count) return;
            this.highlightedIndex = (this.highlightedIndex + step + count) % count;
            this.$nextTick?.(() => {
                const listEl = this.$refs?.optionsList;
                const itemEl = listEl?.children[this.highlightedIndex];
                itemEl?.scrollIntoView?.({ block: 'nearest' });
            });
        },

        selectHighlighted(event) {
            if (event && (event.isComposing || event.keyCode === 229)) return;
            if (this.showCustomOption && this.highlightedIndex === this.filteredOptions.length) {
                this.selectCustom(this.search.trim());
                return;
            }
            if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredOptions.length) {
                this.select(this.filteredOptions[this.highlightedIndex]);
            } else if (this.filteredOptions.length === 1 && !this.showCustomOption) {
                this.select(this.filteredOptions[0]);
            } else if (this.showCustomOption && this.filteredOptions.length === 0) {
                this.selectCustom(this.search.trim());
            }
        },
    };
}
