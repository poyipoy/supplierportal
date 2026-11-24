const bindings = new WeakMap();

const resolveContainer = (containerOrSelector) => {
    if (!containerOrSelector) return null;
    if (typeof containerOrSelector === 'string') {
        return typeof document !== 'undefined' ? document.querySelector(containerOrSelector) : null;
    }
    return containerOrSelector;
};

const queryWithin = (container, selector) => {
    if (!selector) return null;
    return container.querySelector(selector) || (typeof document !== 'undefined' ? document.querySelector(selector) : null);
};

const clearChildren = (node) => {
    if (!node) return;
    if (typeof node.replaceChildren === 'function') {
        node.replaceChildren();
        return;
    }
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
};

const normalizeFieldValue = (field, el) => {
    if (!el) return '';
    if (field.type === 'checkbox') {
        return el.checked ? (el.value || '1') : '';
    }
    if (field.type === 'search' || field.type === 'text') {
        return String(el.value ?? '').trim();
    }
    return String(el.value ?? '');
};

const formatSingleValue = (field, el, rawValue) => {
    if (typeof field.formatValue === 'function') {
        return String(field.formatValue(rawValue, el) ?? '');
    }
    if (field.type === 'select' && el.options && typeof el.selectedIndex === 'number' && el.selectedIndex >= 0) {
        return String(el.options[el.selectedIndex]?.textContent ?? rawValue).trim();
    }
    if (field.type === 'checkbox') {
        return String(field.activeText || field.label || rawValue);
    }
    return rawValue;
};

const isAdvancedField = (field, targetEl) => {
    if (typeof field.advanced === 'boolean') {
        return field.advanced;
    }
    return Boolean(targetEl?.closest?.('[data-filter-bar-tray]'));
};

export function sync(containerOrSelector) {
    const container = resolveContainer(containerOrSelector);
    const instance = container ? bindings.get(container) : null;
    instance?.syncState?.();
}

export function reset(containerOrSelector) {
    const container = resolveContainer(containerOrSelector);
    const instance = container ? bindings.get(container) : null;
    instance?.resetAll?.();
}

export function bindLive(containerOrSelector, options = {}) {
    const container = resolveContainer(containerOrSelector);
    if (!container) return null;

    if (bindings.has(container)) {
        return bindings.get(container);
    }

    const fields = Array.isArray(options.fields) ? options.fields : [];
    const debounceMs = Number.isFinite(Number(options.debounceMs)) ? Number(options.debounceMs) : 300;
    const onFilterChange = typeof options.onFilterChange === 'function' ? options.onFilterChange : () => {};
    const onReset = typeof options.onReset === 'function' ? options.onReset : null;

    const resetBtn = queryWithin(container, options.resetSelector || '[data-filter-bar-reset]');
    const chipsEl = queryWithin(container, options.chipsSelector || '[data-filter-bar-chips]');
    const advancedCountEl = queryWithin(container, options.advancedCountSelector || '[data-filter-bar-advanced-count]');
    const timers = new Map();
    const lastValues = new Map();

    const readField = (field) => {
        if (field.type === 'date-range') {
            const pickerEl = queryWithin(container, field.picker);
            const startEl = queryWithin(container, field.start);
            const endEl = queryWithin(container, field.end);
            const startVal = String(startEl?.value ?? '').trim();
            const endVal = String(endEl?.value ?? '').trim();
            const active = Boolean(startVal || endVal);
            let displayValue = '';
            if (active) {
                if (typeof field.formatValue === 'function') {
                    displayValue = String(field.formatValue({ start: startVal, end: endVal }, { pickerEl, startEl, endEl }) ?? '');
                } else if (startVal && endVal) {
                    displayValue = `${startVal} - ${endVal}`;
                } else {
                    displayValue = startVal || endVal;
                }
            }
            return {
                active,
                advanced: isAdvancedField(field, pickerEl || startEl || endEl),
                displayValue,
                signature: `${startVal}|${endVal}`,
                clear: () => {
                    if (startEl) startEl.value = '';
                    if (endEl) endEl.value = '';
                    if (pickerEl && typeof CustomEvent === 'function') {
                        pickerEl.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
                    }
                },
            };
        }

        const el = queryWithin(container, field.selector);
        const pickerEl = field.type === 'date' ? queryWithin(container, field.picker) : null;
        const defaultValue = String(field.defaultValue ?? '');
        const rawValue = normalizeFieldValue(field, el);
        const active = rawValue !== '' && rawValue !== defaultValue;

        return {
            active,
            advanced: isAdvancedField(field, pickerEl || el),
            displayValue: active ? formatSingleValue(field, el, rawValue) : '',
            signature: rawValue,
            clear: () => {
                if (!el) return;
                if (field.type === 'checkbox') {
                    el.checked = false;
                } else {
                    el.value = defaultValue;
                }
                if (field.type === 'date' && pickerEl && typeof CustomEvent === 'function') {
                    pickerEl.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
                }
            },
        };
    };

    const renderChips = (activeItems) => {
        if (!chipsEl || typeof document === 'undefined') return;

        clearChildren(chipsEl);

        if (activeItems.length === 0) {
            chipsEl.classList.add('tw:hidden');
            chipsEl.classList.remove('d-flex');
            return;
        }

        const activeLabel = chipsEl.dataset?.activeLabel || '';
        const clearLabel = chipsEl.dataset?.clearLabel || '';
        const removeTemplate = chipsEl.dataset?.removeTemplate || ':label';

        chipsEl.classList.remove('tw:hidden', 'd-none');

        if (activeLabel) {
            const labelSpan = document.createElement('span');
            labelSpan.className = 'ui-filter-chips__label';
            labelSpan.textContent = activeLabel;
            chipsEl.appendChild(labelSpan);
        }

        for (const item of activeItems) {
            const chip = document.createElement('span');
            chip.className = 'ui-filter-chip';
            if (chip.dataset) chip.dataset.filterChipKey = item.key;

            const keySpan = document.createElement('span');
            keySpan.className = 'ui-filter-chip__key';
            keySpan.textContent = `${item.label}:`;

            const valSpan = document.createElement('span');
            valSpan.className = 'ui-filter-chip__value';
            valSpan.textContent = item.displayValue;

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'ui-filter-chip__remove ui-focus-ring';
            removeBtn.setAttribute('aria-label', removeTemplate.replace(':label', `${item.label}: ${item.displayValue}`));
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', () => {
                clearPendingTimers();
                item.clear();
                lastValues.set(item.key, readField(item.field).signature);
                const state = syncState();
                onFilterChange({ reason: 'remove', key: item.key, ...state });
            });

            chip.appendChild(keySpan);
            chip.appendChild(valSpan);
            chip.appendChild(removeBtn);
            chipsEl.appendChild(chip);
        }

        if (clearLabel) {
            const clearAllBtn = document.createElement('button');
            clearAllBtn.type = 'button';
            clearAllBtn.className = 'ui-filter-chips__clear ui-focus-ring';
            clearAllBtn.textContent = clearLabel;
            clearAllBtn.addEventListener('click', () => resetAll());
            chipsEl.appendChild(clearAllBtn);
        }
    };

    const syncState = () => {
        const activeItems = [];
        let advancedCount = 0;

        for (const field of fields) {
            const snapshot = readField(field);
            lastValues.set(field.key, snapshot.signature);
            if (snapshot.active) {
                if (snapshot.advanced) advancedCount += 1;
                activeItems.push({
                    key: field.key,
                    label: field.label || field.key,
                    displayValue: snapshot.displayValue,
                    clear: snapshot.clear,
                    field,
                });
            }
        }

        if (advancedCountEl) {
            advancedCountEl.textContent = String(advancedCount);
            advancedCountEl.classList.toggle('tw:hidden', advancedCount === 0);
        }

        if (resetBtn) {
            const hasActive = activeItems.length > 0;
            resetBtn.classList.toggle('ui-filter-bar__reset--active', hasActive);
            if (resetBtn.dataset) resetBtn.dataset.hasActive = hasActive ? 'true' : 'false';
        }

        renderChips(activeItems);

        return { activeCount: activeItems.length, advancedCount };
    };

    const clearPendingTimers = () => {
        for (const handle of timers.values()) {
            clearTimeout(handle);
        }
        timers.clear();
    };

    const emitChange = (reason, key) => {
        const state = syncState();
        onFilterChange({ reason, key, ...state });
    };

    const resetAll = () => {
        clearPendingTimers();
        for (const field of fields) {
            readField(field).clear();
        }
        const state = syncState();
        if (onReset) {
            onReset({ reason: 'reset', key: null, ...state });
        } else {
            onFilterChange({ reason: 'reset', key: null, ...state });
        }
    };

    for (const field of fields) {
        if (field.type === 'date-range') {
            const pickerEl = queryWithin(container, field.picker);
            const startEl = queryWithin(container, field.start);
            const endEl = queryWithin(container, field.end);

            pickerEl?.addEventListener('adasi:date-range-commit', () => {
                emitChange('change', field.key);
            });

            const onNativeRangeChange = (el) => {
                if (el?.closest?.('[data-calendar-enhanced="true"]')) return;
                emitChange('change', field.key);
            };
            startEl?.addEventListener('change', () => onNativeRangeChange(startEl));
            endEl?.addEventListener('change', () => onNativeRangeChange(endEl));
            continue;
        }

        const el = queryWithin(container, field.selector);
        if (!el) continue;

        if (field.type === 'search' || field.type === 'text') {
            el.addEventListener('input', () => {
                const existing = timers.get(field.key);
                if (existing) clearTimeout(existing);
                const handle = setTimeout(() => {
                    timers.delete(field.key);
                    emitChange('input', field.key);
                }, debounceMs);
                timers.set(field.key, handle);
            });

            el.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                if (event.isComposing || event.keyCode === 229) return;
                event.preventDefault();
                const existing = timers.get(field.key);
                if (existing) {
                    clearTimeout(existing);
                    timers.delete(field.key);
                }
                emitChange('enter', field.key);
            });

            el.addEventListener('search', () => {
                const existing = timers.get(field.key);
                if (existing) {
                    clearTimeout(existing);
                    timers.delete(field.key);
                }
                const currentSignature = readField(field).signature;
                if (currentSignature !== lastValues.get(field.key)) {
                    emitChange('search', field.key);
                }
            });
        } else {
            el.addEventListener('change', () => {
                emitChange('change', field.key);
            });
        }
    }

    resetBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        resetAll();
    });

    const initialState = syncState();
    const api = Object.freeze({
        syncState,
        resetAll,
        getState: () => initialState,
    });
    bindings.set(container, api);

    return api;
}

if (typeof window !== 'undefined') {
    window.AdasiFilterBar = Object.freeze({
        bindLive,
        sync,
        reset,
    });
}
