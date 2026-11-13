/**
 * ADASI Searchable Select Component for Alpine.js
 * Supports single-select with search popover, initial options, creatable custom options,
 * and asynchronous suggestions.
 */
export function searchableSelect(config = {}) {
    return {
        open: false,
        search: '',
        selectedValue: String(config.selectedValue ?? ''),
        options: Array.isArray(config.options) ? config.options : [],
        highlightedIndex: -1,
        allowCustom: Boolean(config.allowCustom),
        suggestionsUrl: config.suggestionsUrl || null,
        remoteSearch: Boolean(config.remoteSearch),
        isDisabled: Boolean(config.disabled),
        hasAsyncError: false,
        loadingSuggestions: false,
        debounceTimer: null,
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
                if ((this.allowCustom || this.remoteSearch) && !this.options.some((opt) => String(opt.value) === String(this.selectedValue))) {
                    this.options.unshift({
                        value: String(this.selectedValue),
                        label: String(this.selectedValue),
                        sublabel: null,
                        badge: null,
                        badgeTone: 'neutral',
                        searchKeywords: String(this.selectedValue).toLowerCase(),
                    });
                }
                this.syncNativeSelect(this.selectedValue);
            }
            if ((this.allowCustom || this.remoteSearch) && this.suggestionsUrl) {
                this.$watch('search', (val) => {
                    this.fetchSuggestions(val);
                });
            }
        },

        fetchSuggestions(query) {
            if (!this.suggestionsUrl || !(this.allowCustom || this.remoteSearch)) return;
            const q = query ? query.trim() : '';
            if (this.debounceTimer) {
                clearTimeout(this.debounceTimer);
            }
            if (q.length < 2) {
                this.latestRequestId++;
                this.loadingSuggestions = false;
                return;
            }

            this.debounceTimer = setTimeout(async () => {
                const requestId = ++this.latestRequestId;
                this.loadingSuggestions = true;
                try {
                    const origin = globalThis.location?.origin || 'http://localhost';
                    const url = new URL(this.suggestionsUrl, origin);
                    url.searchParams.set('q', q);
                    const res = await fetch(url.toString(), {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (res.ok) {
                        const json = await res.json();
                        if (requestId !== this.latestRequestId) return;
                        (json.data || []).forEach((item) => this.addSuggestion(item));
                    }
                } catch (e) {
                    // Graceful degradation on network/offline
                } finally {
                    if (requestId === this.latestRequestId) this.loadingSuggestions = false;
                }
            }, 300);
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
                this.$nextTick?.(() => {
                    this.$refs?.searchInput?.focus();
                });
            }
        },

        close(focusTrigger = false) {
            this.open = false;
            this.highlightedIndex = -1;
            if (focusTrigger) {
                this.$nextTick?.(() => {
                    this.$refs?.triggerButton?.focus();
                });
            }
        },

        syncNativeSelect(val) {
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
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
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

        selectHighlighted() {
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
