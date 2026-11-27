/**
 * ADASI Custom Select (AdasiSelect)
 * Progressive enhancement over native <select> using Top-Layer Popover API,
 * CSS Anchor Positioning with viewport flip/clamp fallback, full WAI-ARIA
 * Combobox/Listbox keyboard navigation, A-Z typeahead, Smart Auto-Search
 * (> 10 options), optgroup support, and bidirectional native value/DOM sync.
 */

const SEARCH_THRESHOLD = 10;
const TYPEAHEAD_TIMEOUT_MS = 500;
const VIEWPORT_PADDING = 8;
const ANCHOR_GAP = 4;
const PAGE_STEP = 8;
const SVG_NS = 'http://www.w3.org/2000/svg';

const LAYOUT_CLASS_PATTERN = /^tw:(?:[a-z0-9-]+:)*(?:w-|min-w-|max-w-|flex(?:$|-)|grow(?:$|-)|shrink(?:$|-)|basis-|col-span-|self-|justify-self-|order-|m[trblxyse]?-|hidden$)/;

const instances = new WeakMap();
const activeInstances = new Set();
const bootedDocuments = new WeakSet();
const docObservers = new WeakMap();
let uidCounter = 0;

function getDoc(target) {
    if (target?.nodeType === 9) return target;
    if (target?.ownerDocument) return target.ownerDocument;
    return typeof document !== 'undefined' ? document : null;
}

function getWindow(node, doc) {
    return node?.ownerDocument?.defaultView || doc?.defaultView || (typeof window !== 'undefined' ? window : null);
}

function escapeCss(value, win) {
    if (win?.CSS?.escape) return win.CSS.escape(String(value));
    if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(String(value));
    return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}

function tSelect(key, fallback, win) {
    const i18n = win?.AdasiI18n || (typeof window !== 'undefined' ? window.AdasiI18n : null);
    if (typeof i18n?.t === 'function') {
        const translated = i18n.t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }
    return fallback;
}

function safeFocus(el) {
    if (!el || el.isConnected === false || typeof el.focus !== 'function') return;
    try {
        el.focus({ preventScroll: true });
    } catch {
        el.focus();
    }
}

function supportsAnchorPositioning(win) {
    return Boolean(win?.CSS?.supports?.('position-area', 'block-end'));
}

function createLucideSvg(iconName, doc) {
    const createEl = (tag, attrs = {}) => {
        const node = typeof doc.createElementNS === 'function'
            ? doc.createElementNS(SVG_NS, tag)
            : doc.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, String(v)));
        return node;
    };

    const svg = createEl('svg', {
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        'stroke-width': '2',
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        'aria-hidden': 'true',
        focusable: 'false',
    });

    if (iconName === 'chevron-down') {
        svg.appendChild(createEl('path', { d: 'm6 9 6 6 6-6' }));
    } else if (iconName === 'check') {
        svg.appendChild(createEl('path', { d: 'M20 6 9 17l-5-5' }));
    } else if (iconName === 'search') {
        svg.appendChild(createEl('circle', { cx: '11', cy: '11', r: '8' }));
        svg.appendChild(createEl('path', { d: 'm21 21-4.3-4.3' }));
    } else if (iconName === 'x') {
        svg.appendChild(createEl('path', { d: 'M18 6 6 18' }));
        svg.appendChild(createEl('path', { d: 'm6 6 12 12' }));
    }

    return svg;
}

function isEligibleSelect(el) {
    if (!el || el.tagName !== 'SELECT') return false;
    if (instances.has(el)) return false;
    if (el.multiple || el.hasAttribute?.('multiple')) return false;
    const sizeAttr = Number(el.getAttribute?.('size') || 0);
    if (Number.isFinite(sizeAttr) && sizeAttr > 1) return false;
    if (el.getAttribute?.('data-native-select') === 'true') return false;
    if (el.getAttribute?.('data-adasi-select-native') === 'true') return false;
    const xRef = el.getAttribute?.('x-ref');
    if (xRef === 'nativeSelect' || xRef === 'nativeMultiSelect') return false;
    if (el.closest?.('[data-adasi-select], [x-data^="searchableSelect"]')) return false;
    if (!el.parentNode) return false;
    return true;
}

function getNativeSelectDescriptor(win, prop) {
    const proto = win?.HTMLSelectElement?.prototype || (typeof HTMLSelectElement !== 'undefined' ? HTMLSelectElement.prototype : null);
    return proto ? Object.getOwnPropertyDescriptor(proto, prop) : null;
}

function collectSelectModel(select) {
    const groups = [];
    const flatOptions = [];
    let optIdx = 0;

    const pushOption = (opt, groupDisabled = false) => {
        if (opt.hidden || opt.hasAttribute?.('hidden')) return null;
        const item = {
            index: optIdx++,
            optionEl: opt,
            value: String(opt.value ?? ''),
            label: String(opt.textContent ?? '').trim(),
            disabled: Boolean(groupDisabled || opt.disabled || opt.hasAttribute?.('disabled')),
            selected: Boolean(opt.selected),
            isPlaceholder: String(opt.value ?? '') === '',
        };
        flatOptions.push(item);
        return item;
    };

    const children = Array.from(select.children || select.options || []);
    for (const child of children) {
        if (child.tagName === 'OPTGROUP') {
            if (child.hidden || child.hasAttribute?.('hidden')) continue;
            const groupDisabled = Boolean(child.disabled || child.hasAttribute?.('disabled'));
            const groupItems = [];
            for (const opt of Array.from(child.children || [])) {
                if (opt.tagName !== 'OPTION') continue;
                const item = pushOption(opt, groupDisabled);
                if (item) groupItems.push(item);
            }
            if (groupItems.length > 0) {
                groups.push({
                    type: 'group',
                    label: String(child.label ?? child.getAttribute?.('label') ?? '').trim(),
                    disabled: groupDisabled,
                    items: groupItems,
                });
            }
        } else if (child.tagName === 'OPTION') {
            const item = pushOption(child, false);
            if (item) {
                groups.push({ type: 'option', item });
            }
        }
    }

    return { groups, flatOptions };
}

function resolveSelectedOption(select, flatOptions) {
    const currentVal = String(select.value ?? '');
    const selectedIdx = typeof select.selectedIndex === 'number' ? select.selectedIndex : -1;
    if (selectedIdx >= 0 && select.options?.[selectedIdx]) {
        const optEl = select.options[selectedIdx];
        const byRef = flatOptions.find((item) => item.optionEl === optEl);
        if (byRef) return byRef;
        return {
            index: -1,
            optionEl: optEl,
            value: String(optEl.value ?? ''),
            label: String(optEl.textContent ?? '').trim(),
            disabled: Boolean(optEl.disabled),
            selected: true,
            isPlaceholder: String(optEl.value ?? '') === '',
        };
    }
    return flatOptions.find((item) => item.selected) || flatOptions.find((item) => item.value === currentVal) || null;
}

function shouldShowSearch(select, flatOptions) {
    const explicit = select.getAttribute?.('data-select-search');
    if (explicit === 'true') return true;
    if (explicit === 'false') return false;
    return flatOptions.length > SEARCH_THRESHOLD;
}

function syncWrapperLayout(select, wrapper, popover) {
    const classList = Array.from(select.classList || []);
    const isDt = classList.includes('ui-dt-length-select');
    const isLg = classList.includes('tw:h-11') || classList.includes('tw:min-h-11');
    const isSm = !isDt && !isLg && (
        classList.includes('ui-select--sm')
        || classList.includes('ui-input--sm')
        || classList.some((c) => c.includes('ui-control-height-sm') || c === 'tw:min-h-[36px]')
    );
    const isTabular = isDt || classList.includes('ui-tabular-nums');
    const isBold = classList.includes('tw:font-bold');

    const prevMirrored = wrapper._mirroredClasses || [];
    prevMirrored.forEach((cls) => wrapper.classList.remove(cls));

    const nextMirrored = classList.filter((cls) => LAYOUT_CLASS_PATTERN.test(cls));
    nextMirrored.forEach((cls) => wrapper.classList.add(cls));
    wrapper._mirroredClasses = nextMirrored;

    wrapper.classList.toggle('ui-custom-select--dt', isDt);
    wrapper.classList.toggle('ui-custom-select--sm', isSm);
    wrapper.classList.toggle('ui-custom-select--lg', isLg);
    wrapper.classList.toggle('ui-custom-select--md', !isDt && !isSm && !isLg);
    wrapper.classList.toggle('ui-custom-select--tabular', isTabular);
    wrapper.classList.toggle('ui-custom-select--bold', isBold);
    popover.classList.toggle('ui-custom-select__popover--compact', isDt);

    const inlineWidth = select.style?.width || '';
    const inlineMinWidth = select.style?.minWidth || '';
    const inlineMaxWidth = select.style?.maxWidth || '';
    wrapper.style.width = inlineWidth;
    wrapper.style.minWidth = inlineMinWidth;
    wrapper.style.maxWidth = inlineMaxWidth;
    wrapper.classList.toggle('ui-custom-select--inline', Boolean(inlineWidth || classList.includes('tw:w-auto')));

    const isHidden = Boolean(select.hidden || select.hasAttribute?.('hidden') || select.style?.display === 'none');
    wrapper.hidden = isHidden;
}

function findAssociatedLabel(select, doc, win) {
    if (select.labels && select.labels.length > 0) {
        return select.labels[0];
    }
    if (select.id && doc?.querySelector) {
        return doc.querySelector(`label[for="${escapeCss(select.id, win)}"]`);
    }
    return select.closest?.('label') || null;
}

function syncTriggerAria(select, trigger, valueSpan, baseId, doc, win) {
    const isDisabled = Boolean(select.disabled || select.hasAttribute?.('disabled'));
    trigger.disabled = isDisabled;

    if (select.required || select.hasAttribute?.('required')) {
        trigger.setAttribute('aria-required', 'true');
    } else {
        trigger.removeAttribute('aria-required');
    }

    let userInvalid = false;
    try {
        userInvalid = Boolean(select.matches?.(':user-invalid'));
    } catch {
        userInvalid = false;
    }

    const isInvalid = Boolean(
        select.getAttribute?.('aria-invalid') === 'true'
        || select.classList?.contains?.('is-invalid')
        || select.hasAttribute?.('data-async-invalid')
        || select.classList?.contains?.('user-invalid-fallback')
        || userInvalid
    );
    if (isInvalid) {
        trigger.setAttribute('aria-invalid', 'true');
        trigger.setAttribute('data-invalid', 'true');
    } else {
        trigger.removeAttribute('aria-invalid');
        trigger.removeAttribute('data-invalid');
    }

    const describedBy = select.getAttribute?.('aria-describedby');
    if (describedBy) {
        trigger.setAttribute('aria-describedby', describedBy);
    } else {
        trigger.removeAttribute('aria-describedby');
    }

    const explicitLabelledBy = select.getAttribute?.('aria-labelledby');
    const explicitAriaLabel = select.getAttribute?.('aria-label');
    const labelEl = findAssociatedLabel(select, doc, win);

    if (explicitLabelledBy) {
        const ids = explicitLabelledBy.split(/\s+/).filter(Boolean);
        if (!ids.includes(valueSpan.id)) ids.push(valueSpan.id);
        trigger.setAttribute('aria-labelledby', ids.join(' '));
        trigger.removeAttribute('aria-label');
    } else if (labelEl) {
        if (!labelEl.id) {
            labelEl.id = `${baseId}-label`;
        }
        trigger.setAttribute('aria-labelledby', `${labelEl.id} ${valueSpan.id}`);
        trigger.removeAttribute('aria-label');
    } else if (explicitAriaLabel) {
        trigger.setAttribute('aria-label', explicitAriaLabel);
        trigger.removeAttribute('aria-labelledby');
    } else {
        trigger.removeAttribute('aria-labelledby');
        trigger.removeAttribute('aria-label');
    }
}

function positionPopoverFallback(trigger, popover, listEl, isCompact, win) {
    if (!trigger || !popover || !win || typeof trigger.getBoundingClientRect !== 'function') return;

    popover.style.position = 'fixed';
    popover.style.margin = '0';
    popover.style.inset = 'auto';

    const triggerRect = trigger.getBoundingClientRect();
    const vw = win.innerWidth || 1024;
    const vh = win.innerHeight || 768;

    const floorWidth = isCompact ? 88 : 136;
    const ceilingWidth = isCompact ? 224 : 416;
    const minWidth = Math.min(Math.max(Math.round(triggerRect.width), floorWidth), Math.max(120, vw - VIEWPORT_PADDING * 2));
    const maxWidth = Math.min(Math.max(Math.round(triggerRect.width), ceilingWidth), Math.max(120, vw - VIEWPORT_PADDING * 2));
    popover.style.minWidth = `${minWidth}px`;
    popover.style.maxWidth = `${maxWidth}px`;

    const popRect = typeof popover.getBoundingClientRect === 'function'
        ? popover.getBoundingClientRect()
        : { width: minWidth, height: 220 };
    const popWidth = popRect.width || popover.offsetWidth || minWidth;
    const popHeight = popRect.height || popover.offsetHeight || 220;

    let left = triggerRect.left;
    if (left + popWidth > vw - VIEWPORT_PADDING) {
        left = Math.max(VIEWPORT_PADDING, triggerRect.right - popWidth);
    }
    if (left + popWidth > vw - VIEWPORT_PADDING) {
        left = Math.max(VIEWPORT_PADDING, vw - popWidth - VIEWPORT_PADDING);
    }
    if (left < VIEWPORT_PADDING) {
        left = VIEWPORT_PADDING;
    }

    const spaceBelow = Math.max(0, vh - triggerRect.bottom - ANCHOR_GAP - VIEWPORT_PADDING);
    const spaceAbove = Math.max(0, triggerRect.top - ANCHOR_GAP - VIEWPORT_PADDING);
    const openUp = popHeight > spaceBelow && spaceAbove > spaceBelow;

    const availableHeight = Math.max(96, openUp ? spaceAbove : spaceBelow);
    popover.style.maxHeight = `${Math.min(352, Math.floor(availableHeight))}px`;

    const finalRect = typeof popover.getBoundingClientRect === 'function'
        ? popover.getBoundingClientRect()
        : { height: Math.min(popHeight, availableHeight) };
    const finalHeight = finalRect.height || Math.min(popHeight, availableHeight);

    let top = openUp
        ? triggerRect.top - ANCHOR_GAP - finalHeight
        : triggerRect.bottom + ANCHOR_GAP;
    if (top < VIEWPORT_PADDING) top = VIEWPORT_PADDING;
    if (top + finalHeight > vh - VIEWPORT_PADDING) {
        top = Math.max(VIEWPORT_PADDING, vh - finalHeight - VIEWPORT_PADDING);
    }

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
    if (listEl) {
        listEl.style.maxHeight = `${Math.max(80, Math.floor(availableHeight) - 56)}px`;
    }
}

function createSelectInstance(select) {
    const doc = getDoc(select);
    const win = getWindow(select, doc);
    if (!doc || !select.parentNode) return null;

    const seq = ++uidCounter;
    const baseId = select.id ? `${select.id}` : `adasi-select-${seq}`;
    const triggerId = `${baseId}-trigger`;
    const valueId = `${baseId}-value`;
    const popoverId = `${baseId}-popover`;
    const listboxId = `${baseId}-listbox`;
    const searchId = `${baseId}-search`;

    const wrapper = doc.createElement('span');
    wrapper.className = 'ui-custom-select';
    wrapper.setAttribute('data-adasi-select', '');
    wrapper.setAttribute('data-async-error-anchor', '');

    const trigger = doc.createElement('button');
    trigger.type = 'button';
    trigger.id = triggerId;
    trigger.className = 'ui-custom-select__trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', listboxId);

    const valueSpan = doc.createElement('span');
    valueSpan.id = valueId;
    valueSpan.className = 'ui-custom-select__value';

    const chevronSpan = doc.createElement('span');
    chevronSpan.className = 'ui-custom-select__chevron';
    chevronSpan.setAttribute('aria-hidden', 'true');
    chevronSpan.appendChild(createLucideSvg('chevron-down', doc));

    trigger.appendChild(valueSpan);
    trigger.appendChild(chevronSpan);

    const popover = doc.createElement('div');
    popover.id = popoverId;
    popover.className = 'ui-custom-select__popover';
    popover.setAttribute('popover', 'auto');
    popover.setAttribute('data-adasi-select-popover', '');

    const searchWrap = doc.createElement('div');
    searchWrap.className = 'ui-custom-select__search-wrap';
    searchWrap.hidden = true;

    const searchBox = doc.createElement('div');
    searchBox.className = 'ui-custom-select__search-box';

    const searchIcon = doc.createElement('span');
    searchIcon.className = 'ui-custom-select__search-icon';
    searchIcon.setAttribute('aria-hidden', 'true');
    searchIcon.appendChild(createLucideSvg('search', doc));

    const searchInput = doc.createElement('input');
    searchInput.id = searchId;
    searchInput.type = 'text';
    searchInput.className = 'ui-custom-select__search-input';
    searchInput.setAttribute('autocomplete', 'off');
    searchInput.setAttribute('autocorrect', 'off');
    searchInput.setAttribute('autocapitalize', 'none');
    searchInput.setAttribute('spellcheck', 'false');
    searchInput.setAttribute('data-ignore-dirty', 'true');
    searchInput.setAttribute('aria-controls', listboxId);
    searchInput.setAttribute('placeholder', tSelect('js.select.search_placeholder', 'Search options...', win));
    searchInput.setAttribute('aria-label', tSelect('js.select.search_placeholder', 'Search options...', win));

    const searchClear = doc.createElement('button');
    searchClear.type = 'button';
    searchClear.className = 'ui-custom-select__search-clear';
    searchClear.hidden = true;
    searchClear.setAttribute('tabindex', '-1');
    searchClear.setAttribute('aria-label', tSelect('js.select.clear_search', 'Clear search', win));
    searchClear.appendChild(createLucideSvg('x', doc));

    searchBox.appendChild(searchIcon);
    searchBox.appendChild(searchInput);
    searchBox.appendChild(searchClear);
    searchWrap.appendChild(searchBox);

    const listEl = doc.createElement('div');
    listEl.id = listboxId;
    listEl.className = 'ui-custom-select__list';
    listEl.setAttribute('role', 'listbox');

    popover.appendChild(searchWrap);
    popover.appendChild(listEl);

    // Preserve native <select> attributes & position it cleanly inside wrapper.
    const origTabIndex = select.getAttribute?.('tabindex');
    const origAriaHidden = select.getAttribute?.('aria-hidden');
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    wrapper.appendChild(trigger);
    wrapper.appendChild(popover);

    select.classList.add('ui-custom-select__native');
    select.setAttribute('data-adasi-select-native', 'true');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');

    let isOpen = false;
    let searchQuery = '';
    let model = { groups: [], flatOptions: [] };
    let visibleOptionNodes = [];
    let activeIndex = -1;
    let typeaheadBuffer = '';
    let typeaheadTimer = null;
    let fallbackCleanup = null;
    let syncScheduled = false;
    let internalMutating = false;
    let destroyed = false;

    const valueDesc = getNativeSelectDescriptor(win, 'value');
    const selectedIndexDesc = getNativeSelectDescriptor(win, 'selectedIndex');

    const cleanupFallbackPosition = () => {
        if (fallbackCleanup) {
            fallbackCleanup();
            fallbackCleanup = null;
        }
        if (popover?.style) {
            popover.style.position = '';
            popover.style.margin = '';
            popover.style.inset = '';
            popover.style.left = '';
            popover.style.top = '';
            popover.style.minWidth = '';
            popover.style.maxWidth = '';
            popover.style.maxHeight = '';
        }
        if (listEl?.style) {
            listEl.style.maxHeight = '';
        }
    };

    const bindFallbackPosition = () => {
        cleanupFallbackPosition();
        if (!win?.addEventListener) return;
        const isCompact = popover.classList.contains('ui-custom-select__popover--compact');
        const reposition = () => {
            if (!isOpen || destroyed || trigger.isConnected === false) {
                cleanupFallbackPosition();
                return;
            }
            positionPopoverFallback(trigger, popover, listEl, isCompact, win);
        };
        reposition();
        win.addEventListener('scroll', reposition, { capture: true, passive: true });
        win.addEventListener('resize', reposition, { passive: true });
        fallbackCleanup = () => {
            win.removeEventListener('scroll', reposition, { capture: true });
            win.removeEventListener('resize', reposition);
        };
    };

    const setActiveOptionIndex = (nextIdx, { scroll = true } = {}) => {
        if (activeIndex >= 0 && visibleOptionNodes[activeIndex]) {
            visibleOptionNodes[activeIndex].classList.remove('ui-custom-select__option--active');
        }

        if (nextIdx < 0 || nextIdx >= visibleOptionNodes.length) {
            activeIndex = -1;
            trigger.removeAttribute('aria-activedescendant');
            searchInput.removeAttribute('aria-activedescendant');
            return;
        }

        activeIndex = nextIdx;
        const activeNode = visibleOptionNodes[activeIndex];
        activeNode.classList.add('ui-custom-select__option--active');
        const activeHost = !searchWrap.hidden ? searchInput : trigger;
        activeHost.setAttribute('aria-activedescendant', activeNode.id);

        if (scroll && typeof activeNode.scrollIntoView === 'function') {
            activeNode.scrollIntoView({ block: 'nearest' });
        }
    };

    const findEnabledIndex = (startIdx, direction) => {
        const len = visibleOptionNodes.length;
        if (len === 0) return -1;
        let idx = startIdx;
        for (let tries = 0; tries < len; tries += 1) {
            if (idx < 0) idx = len - 1;
            if (idx >= len) idx = 0;
            if (visibleOptionNodes[idx].getAttribute('aria-disabled') !== 'true') {
                return idx;
            }
            idx += direction;
        }
        return -1;
    };

    const moveActive = (delta) => {
        if (!visibleOptionNodes.length) return;
        const start = activeIndex === -1
            ? (delta > 0 ? 0 : visibleOptionNodes.length - 1)
            : activeIndex + delta;
        const target = findEnabledIndex(start, delta >= 0 ? 1 : -1);
        if (target >= 0) setActiveOptionIndex(target);
    };

    const moveActiveClamped = (targetIndex) => {
        if (!visibleOptionNodes.length) return;
        const clamped = Math.max(0, Math.min(targetIndex, visibleOptionNodes.length - 1));
        const direction = clamped >= activeIndex ? 1 : -1;
        const resolved = findEnabledIndex(clamped, direction);
        if (resolved >= 0) setActiveOptionIndex(resolved);
    };

    const updateTriggerDisplay = () => {
        const selected = resolveSelectedOption(select, model.flatOptions);
        const fallbackLabel = select.getAttribute?.('data-placeholder')
            || tSelect('js.select.placeholder', 'Select an option', win);
        const text = selected ? selected.label : fallbackLabel;
        const isPlaceholder = !selected || selected.isPlaceholder;

        valueSpan.textContent = text;
        valueSpan.classList.toggle('ui-custom-select__value--placeholder', isPlaceholder);
        trigger.title = text;
    };

    const renderOptions = () => {
        const q = searchQuery.trim().toLowerCase();
        const selected = resolveSelectedOption(select, model.flatOptions);
        visibleOptionNodes = [];
        activeIndex = -1;

        const frag = typeof doc.createDocumentFragment === 'function' ? doc.createDocumentFragment() : null;
        const container = frag || listEl;
        if (!frag) listEl.replaceChildren();

        let renderedCount = 0;
        let selectedVisibleIdx = -1;

        const buildOptionNode = (item) => {
            if (q && !item.label.toLowerCase().includes(q) && !item.value.toLowerCase().includes(q)) {
                return null;
            }
            const optNode = doc.createElement('div');
            const nodeIdx = visibleOptionNodes.length;
            optNode.id = `${baseId}-opt-${item.index}`;
            optNode.className = 'ui-custom-select__option';
            optNode.setAttribute('role', 'option');
            optNode.setAttribute('tabindex', '-1');
            optNode.dataset.optionIndex = String(item.index);
            if (item.isPlaceholder) {
                optNode.setAttribute('data-placeholder', 'true');
            }

            const isSelected = Boolean(selected && (selected.optionEl === item.optionEl || (selected.value === item.value && selected.label === item.label)));
            optNode.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            if (item.disabled) {
                optNode.setAttribute('aria-disabled', 'true');
            }

            const labelNode = doc.createElement('span');
            labelNode.className = 'ui-custom-select__option-label';
            labelNode.textContent = item.label;
            optNode.appendChild(labelNode);

            if (isSelected) {
                const checkNode = doc.createElement('span');
                checkNode.className = 'ui-custom-select__check';
                checkNode.setAttribute('aria-hidden', 'true');
                checkNode.appendChild(createLucideSvg('check', doc));
                optNode.appendChild(checkNode);
                if (!item.disabled && selectedVisibleIdx === -1) {
                    selectedVisibleIdx = nodeIdx;
                }
            }

            optNode._adasiItem = item;
            visibleOptionNodes.push(optNode);
            renderedCount += 1;
            return optNode;
        };

        let groupIdx = 0;
        for (const entry of model.groups) {
            if (entry.type === 'group') {
                const matchingNodes = [];
                for (const item of entry.items) {
                    const node = buildOptionNode(item);
                    if (node) matchingNodes.push(node);
                }
                if (matchingNodes.length === 0) continue;

                const groupEl = doc.createElement('div');
                groupEl.className = 'ui-custom-select__group';
                groupEl.setAttribute('role', 'group');

                const labelId = `${baseId}-grp-${groupIdx++}`;
                const groupLabelEl = doc.createElement('div');
                groupLabelEl.id = labelId;
                groupLabelEl.className = 'ui-custom-select__group-label';
                groupLabelEl.setAttribute('role', 'presentation');
                groupLabelEl.textContent = entry.label;
                groupEl.setAttribute('aria-labelledby', labelId);

                groupEl.appendChild(groupLabelEl);
                matchingNodes.forEach((n) => groupEl.appendChild(n));
                container.appendChild(groupEl);
            } else if (entry.type === 'option') {
                const node = buildOptionNode(entry.item);
                if (node) container.appendChild(node);
            }
        }

        if (renderedCount === 0) {
            const emptyEl = doc.createElement('div');
            emptyEl.className = 'ui-custom-select__empty';
            emptyEl.setAttribute('role', 'status');
            emptyEl.setAttribute('aria-live', 'polite');
            emptyEl.textContent = tSelect('js.select.empty', 'No matching options', win);
            container.appendChild(emptyEl);
        }

        if (frag) {
            listEl.replaceChildren(frag);
        }

        if (isOpen) {
            if (selectedVisibleIdx >= 0) {
                setActiveOptionIndex(selectedVisibleIdx, { scroll: true });
            } else {
                const firstEnabled = findEnabledIndex(0, 1);
                setActiveOptionIndex(firstEnabled, { scroll: false });
            }
        }
    };

    const syncAll = () => {
        if (destroyed) return;
        model = collectSelectModel(select);
        syncWrapperLayout(select, wrapper, popover);
        syncTriggerAria(select, trigger, valueSpan, baseId, doc, win);
        updateTriggerDisplay();

        const showSearch = shouldShowSearch(select, model.flatOptions);
        searchWrap.hidden = !showSearch;
        if (!showSearch && searchQuery !== '') {
            searchQuery = '';
            searchInput.value = '';
            searchClear.hidden = true;
        }

        if (isOpen) {
            if (trigger.disabled || wrapper.hidden) {
                close(false);
            } else {
                renderOptions();
                if (!supportsAnchorPositioning(win)) {
                    const isCompact = popover.classList.contains('ui-custom-select__popover--compact');
                    positionPopoverFallback(trigger, popover, listEl, isCompact, win);
                }
            }
        }
    };

    const scheduleSync = () => {
        if (syncScheduled || destroyed) return;
        syncScheduled = true;
        queueMicrotask(() => {
            syncScheduled = false;
            syncAll();
        });
    };

    const finishClosedState = (restoreFocus) => {
        if (!isOpen) return;
        isOpen = false;
        cleanupFallbackPosition();
        popover.classList.remove('is-open');
        popover.removeAttribute('data-ui-select-open');
        trigger.removeAttribute('data-ui-select-open');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.removeAttribute('aria-activedescendant');
        searchInput.removeAttribute('aria-activedescendant');
        searchQuery = '';
        searchInput.value = '';
        searchClear.hidden = true;
        activeIndex = -1;
        if (restoreFocus) {
            safeFocus(trigger);
        }
    };

    const close = (restoreFocus = false) => {
        if (!isOpen) return;
        if (typeof popover.hidePopover === 'function' && popover.matches?.(':popover-open')) {
            try {
                popover.hidePopover();
            } catch {
                // Fall through to cleanup below.
            }
        }
        finishClosedState(restoreFocus);
    };

    const open = ({ highlight = 'selected' } = {}) => {
        if (destroyed || isOpen || trigger.disabled || wrapper.hidden) return;

        closeAll(select, doc);
        win?.AdasiDropdown?.dismissAll?.(null, doc);

        model = collectSelectModel(select);
        const showSearch = shouldShowSearch(select, model.flatOptions);
        searchWrap.hidden = !showSearch;
        searchQuery = '';
        searchInput.value = '';
        searchClear.hidden = true;

        isOpen = true;
        trigger.setAttribute('data-ui-select-open', 'true');
        trigger.setAttribute('aria-expanded', 'true');
        popover.setAttribute('data-ui-select-open', 'true');

        renderOptions();

        if (typeof popover.showPopover === 'function') {
            try {
                if (!popover.matches?.(':popover-open')) {
                    popover.showPopover();
                }
            } catch {
                popover.classList.add('is-open');
            }
        } else {
            popover.classList.add('is-open');
        }

        if (!supportsAnchorPositioning(win)) {
            bindFallbackPosition();
        }

        if (highlight === 'first') {
            setActiveOptionIndex(findEnabledIndex(0, 1));
        } else if (highlight === 'last') {
            setActiveOptionIndex(findEnabledIndex(visibleOptionNodes.length - 1, -1));
        }

        if (!searchWrap.hidden) {
            safeFocus(searchInput);
        } else {
            safeFocus(trigger);
        }
    };

    const commitItem = (item, { restoreFocus = true } = {}) => {
        if (!item || item.disabled) return;
        const prevValue = valueDesc?.get ? valueDesc.get.call(select) : select.value;
        const prevIndex = selectedIndexDesc?.get ? selectedIndexDesc.get.call(select) : select.selectedIndex;

        const targetOptionIndex = Array.from(select.options || []).indexOf(item.optionEl);
        const changed = prevValue !== item.value || (targetOptionIndex >= 0 && prevIndex !== targetOptionIndex);

        internalMutating = true;
        try {
            if (targetOptionIndex >= 0 && selectedIndexDesc?.set) {
                selectedIndexDesc.set.call(select, targetOptionIndex);
            } else if (valueDesc?.set) {
                valueDesc.set.call(select, item.value);
            } else {
                select.value = item.value;
            }
        } finally {
            internalMutating = false;
        }

        updateTriggerDisplay();
        close(restoreFocus);

        if (changed) {
            const Evt = win?.Event || Event;
            select.dispatchEvent(new Evt('input', { bubbles: true }));
            select.dispatchEvent(new Evt('change', { bubbles: true }));
        }
    };

    const handleTypeahead = (char) => {
        if (typeaheadTimer) clearTimeout(typeaheadTimer);
        typeaheadBuffer += char.toLowerCase();
        typeaheadTimer = setTimeout(() => {
            typeaheadBuffer = '';
            typeaheadTimer = null;
        }, TYPEAHEAD_TIMEOUT_MS);

        if (isOpen) {
            const start = activeIndex >= 0 ? (activeIndex + 1) % Math.max(1, visibleOptionNodes.length) : 0;
            for (let i = 0; i < visibleOptionNodes.length; i += 1) {
                const idx = (start + i) % visibleOptionNodes.length;
                const node = visibleOptionNodes[idx];
                if (node.getAttribute('aria-disabled') === 'true') continue;
                const label = (node._adasiItem?.label || '').toLowerCase();
                if (label.startsWith(typeaheadBuffer)) {
                    setActiveOptionIndex(idx);
                    return;
                }
            }
            return;
        }

        model = collectSelectModel(select);
        const enabled = model.flatOptions.filter((opt) => !opt.disabled);
        if (!enabled.length) return;
        const currentSelected = resolveSelectedOption(select, model.flatOptions);
        const currentPos = currentSelected ? enabled.findIndex((o) => o.optionEl === currentSelected.optionEl) : -1;
        const start = currentPos >= 0 ? (currentPos + 1) % enabled.length : 0;

        for (let i = 0; i < enabled.length; i += 1) {
            const opt = enabled[(start + i) % enabled.length];
            if (opt.label.toLowerCase().startsWith(typeaheadBuffer)) {
                commitItem(opt, { restoreFocus: false });
                return;
            }
        }
    };

    // --- Event Listeners ---
    let closedOnPointerDown = false;
    const onTriggerPointerDown = (event) => {
        if (event.button !== undefined && event.button !== 0) return;
        if (isOpen) {
            closedOnPointerDown = true;
            close(true);
            const resetFlag = () => {
                setTimeout(() => {
                    closedOnPointerDown = false;
                }, 0);
            };
            win?.addEventListener?.('pointerup', resetFlag, { once: true, capture: true });
            win?.addEventListener?.('pointercancel', resetFlag, { once: true, capture: true });
        }
    };

    const onTriggerClick = (event) => {
        event.preventDefault();
        if (closedOnPointerDown) {
            closedOnPointerDown = false;
            return;
        }
        if (isOpen) {
            close(true);
        } else {
            open();
        }
    };

    const onPopoverKeydown = (event) => {
        if (event.key === 'Escape' && isOpen) {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        }
    };

    const onTriggerKeydown = (event) => {
        if (event.isComposing || event.keyCode === 229) return;
        if (trigger.disabled) return;

        if (!isOpen) {
            if (event.key === 'ArrowDown' || (event.altKey && event.key === 'ArrowDown')) {
                event.preventDefault();
                open({ highlight: 'selected' });
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                open({ highlight: 'selected' });
            } else if (event.key === 'Home') {
                event.preventDefault();
                open({ highlight: 'first' });
            } else if (event.key === 'End') {
                event.preventDefault();
                open({ highlight: 'last' });
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open({ highlight: 'selected' });
            } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                handleTypeahead(event.key);
            }
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActive(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);
        } else if (event.key === 'Home') {
            event.preventDefault();
            moveActiveClamped(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            moveActiveClamped(visibleOptionNodes.length - 1);
        } else if (event.key === 'PageDown') {
            event.preventDefault();
            moveActiveClamped((activeIndex < 0 ? 0 : activeIndex) + PAGE_STEP);
        } else if (event.key === 'PageUp') {
            event.preventDefault();
            moveActiveClamped((activeIndex < 0 ? 0 : activeIndex) - PAGE_STEP);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (activeIndex >= 0 && visibleOptionNodes[activeIndex]?._adasiItem) {
                commitItem(visibleOptionNodes[activeIndex]._adasiItem, { restoreFocus: true });
            } else {
                close(true);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') {
            close(false);
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            handleTypeahead(event.key);
        }
    };

    const onSearchInput = () => {
        searchQuery = searchInput.value;
        searchClear.hidden = searchQuery.length === 0;
        renderOptions();
        if (!supportsAnchorPositioning(win)) {
            const isCompact = popover.classList.contains('ui-custom-select__popover--compact');
            positionPopoverFallback(trigger, popover, listEl, isCompact, win);
        }
    };

    const onSearchKeydown = (event) => {
        if (event.key === 'Enter' && (event.isComposing || event.keyCode === 229)) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActive(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);
        } else if (event.key === 'Home' && (event.ctrlKey || searchInput.selectionStart === 0)) {
            event.preventDefault();
            moveActiveClamped(0);
        } else if (event.key === 'End' && (event.ctrlKey || searchInput.selectionEnd === searchInput.value.length)) {
            event.preventDefault();
            moveActiveClamped(visibleOptionNodes.length - 1);
        } else if (event.key === 'PageDown') {
            event.preventDefault();
            moveActiveClamped((activeIndex < 0 ? 0 : activeIndex) + PAGE_STEP);
        } else if (event.key === 'PageUp') {
            event.preventDefault();
            moveActiveClamped((activeIndex < 0 ? 0 : activeIndex) - PAGE_STEP);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeIndex >= 0 && visibleOptionNodes[activeIndex]?._adasiItem) {
                commitItem(visibleOptionNodes[activeIndex]._adasiItem, { restoreFocus: true });
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') {
            close(false);
        }
    };

    const onSearchClearClick = (event) => {
        event.preventDefault();
        searchQuery = '';
        searchInput.value = '';
        searchClear.hidden = true;
        renderOptions();
        safeFocus(searchInput);
    };

    const onListPointerDown = (event) => {
        // Keep focus on trigger/search so blur does not fire before selection commits.
        if (event.target?.closest?.('.ui-custom-select__option')) {
            event.preventDefault();
        }
    };

    const onListClick = (event) => {
        const optNode = event.target?.closest?.('.ui-custom-select__option');
        if (!optNode || !optNode._adasiItem) return;
        commitItem(optNode._adasiItem, { restoreFocus: true });
    };

    const onListMouseMove = (event) => {
        const optNode = event.target?.closest?.('.ui-custom-select__option');
        if (!optNode || optNode.getAttribute('aria-disabled') === 'true') return;
        const idx = visibleOptionNodes.indexOf(optNode);
        if (idx >= 0 && idx !== activeIndex) {
            setActiveOptionIndex(idx, { scroll: false });
        }
    };

    const onPopoverToggle = (event) => {
        if (event.newState === 'closed' && isOpen) {
            finishClosedState(false);
        }
    };

    const onNativeFocus = () => {
        if (!destroyed && !isOpen) {
            safeFocus(trigger);
        }
    };

    const onNativeInvalid = () => {
        if (destroyed) return;
        syncTriggerAria(select, trigger, valueSpan, baseId, doc, win);
    };

    const onNativeChange = () => {
        if (!internalMutating) {
            scheduleSync();
        }
    };

    const onFormReset = () => {
        setTimeout(() => {
            if (!destroyed) syncAll();
        }, 0);
    };

    trigger.addEventListener('pointerdown', onTriggerPointerDown);
    trigger.addEventListener('click', onTriggerClick);
    trigger.addEventListener('keydown', onTriggerKeydown);
    searchInput.addEventListener('input', onSearchInput);
    searchInput.addEventListener('keydown', onSearchKeydown);
    searchClear.addEventListener('mousedown', (e) => e.preventDefault());
    searchClear.addEventListener('click', onSearchClearClick);
    listEl.addEventListener('mousedown', onListPointerDown);
    listEl.addEventListener('click', onListClick);
    listEl.addEventListener('mousemove', onListMouseMove);
    popover.addEventListener('keydown', onPopoverKeydown);
    popover.addEventListener('toggle', onPopoverToggle);
    select.addEventListener('focus', onNativeFocus);
    select.addEventListener('invalid', onNativeInvalid);
    select.addEventListener('change', onNativeChange);
    select.addEventListener('input', onNativeChange);
    select.form?.addEventListener('reset', onFormReset);

    // Instance-level value & selectedIndex hooks keep programmatic mutations in immediate sync.
    let installedValueHook = false;
    let installedIndexHook = false;
    if (valueDesc?.get && valueDesc?.set) {
        try {
            Object.defineProperty(select, 'value', {
                configurable: true,
                enumerable: true,
                get() {
                    return valueDesc.get.call(this);
                },
                set(nextVal) {
                    valueDesc.set.call(this, nextVal);
                    if (!internalMutating && !destroyed) {
                        updateTriggerDisplay();
                        if (isOpen) renderOptions();
                    }
                },
            });
            installedValueHook = true;
        } catch {
            installedValueHook = false;
        }
    }
    if (selectedIndexDesc?.get && selectedIndexDesc?.set) {
        try {
            Object.defineProperty(select, 'selectedIndex', {
                configurable: true,
                enumerable: true,
                get() {
                    return selectedIndexDesc.get.call(this);
                },
                set(nextIdx) {
                    selectedIndexDesc.set.call(this, nextIdx);
                    if (!internalMutating && !destroyed) {
                        updateTriggerDisplay();
                        if (isOpen) renderOptions();
                    }
                },
            });
            installedIndexHook = true;
        } catch {
            installedIndexHook = false;
        }
    }

    // Per-instance MutationObserver mirrors dynamic <option>/<optgroup> and attribute mutations.
    let observer = null;
    const MutationObs = win?.MutationObserver || (typeof MutationObserver !== 'undefined' ? MutationObserver : null);
    if (MutationObs) {
        observer = new MutationObs(() => {
            if (!internalMutating && !destroyed) {
                scheduleSync();
            }
        });
        observer.observe(select, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: [
                'disabled',
                'hidden',
                'style',
                'class',
                'aria-invalid',
                'aria-describedby',
                'aria-label',
                'aria-labelledby',
                'required',
                'data-async-invalid',
                'data-select-search',
                'data-placeholder',
                'selected',
                'label',
                'value',
            ],
        });
    }

    const destroy = () => {
        if (destroyed) return;
        destroyed = true;
        if (typeaheadTimer) clearTimeout(typeaheadTimer);
        close(false);
        observer?.disconnect();
        activeInstances.delete(api);
        instances.delete(select);

        trigger.removeEventListener('pointerdown', onTriggerPointerDown);
        trigger.removeEventListener('click', onTriggerClick);
        trigger.removeEventListener('keydown', onTriggerKeydown);
        searchInput.removeEventListener('input', onSearchInput);
        searchInput.removeEventListener('keydown', onSearchKeydown);
        searchClear.removeEventListener('click', onSearchClearClick);
        listEl.removeEventListener('mousedown', onListPointerDown);
        listEl.removeEventListener('click', onListClick);
        listEl.removeEventListener('mousemove', onListMouseMove);
        popover.removeEventListener('keydown', onPopoverKeydown);
        popover.removeEventListener('toggle', onPopoverToggle);
        select.removeEventListener('focus', onNativeFocus);
        select.removeEventListener('invalid', onNativeInvalid);
        select.removeEventListener('change', onNativeChange);
        select.removeEventListener('input', onNativeChange);
        select.form?.removeEventListener('reset', onFormReset);

        if (installedValueHook) {
            try { delete select.value; } catch {}
        }
        if (installedIndexHook) {
            try { delete select.selectedIndex; } catch {}
        }

        select.classList.remove('ui-custom-select__native');
        select.removeAttribute('data-adasi-select-native');
        if (origTabIndex !== null && origTabIndex !== undefined) select.setAttribute('tabindex', origTabIndex);
        else select.removeAttribute('tabindex');
        if (origAriaHidden !== null && origAriaHidden !== undefined) select.setAttribute('aria-hidden', origAriaHidden);
        else select.removeAttribute('aria-hidden');

        if (wrapper.parentNode) {
            wrapper.parentNode.insertBefore(select, wrapper);
            wrapper.remove();
        }
    };

    const api = {
        select,
        wrapper,
        trigger,
        popover,
        listEl,
        searchInput,
        isOpen: () => isOpen,
        open,
        close,
        sync: syncAll,
        destroy,
    };

    instances.set(select, api);
    activeInstances.add(api);
    syncAll();

    return api;
}

function enhanceWithin(root = typeof document !== 'undefined' ? document : null) {
    if (!root) return 0;
    let count = 0;

    if (root.tagName === 'SELECT') {
        if (isEligibleSelect(root)) {
            createSelectInstance(root);
            count += 1;
        }
        return count;
    }

    if (typeof root.querySelectorAll !== 'function') return 0;
    const candidates = root.querySelectorAll('select');
    candidates.forEach((select) => {
        if (isEligibleSelect(select)) {
            createSelectInstance(select);
            count += 1;
        }
    });
    return count;
}

function closeAll(exceptSelect = null, doc = null) {
    for (const inst of [...activeInstances]) {
        if (inst.select.isConnected === false) {
            inst.destroy();
            continue;
        }
        if (doc && inst.select.ownerDocument && inst.select.ownerDocument !== doc) continue;
        if (exceptSelect && inst.select === exceptSelect) continue;
        if (inst.isOpen()) {
            inst.close(false);
        }
    }
}

export const AdasiSelect = Object.freeze({
    initialize(root = typeof document !== 'undefined' ? document : null) {
        return enhanceWithin(root);
    },
    enhance(selectEl) {
        if (!isEligibleSelect(selectEl)) {
            return instances.get(selectEl) || null;
        }
        return createSelectInstance(selectEl);
    },
    getInstance(selectEl) {
        return instances.get(selectEl) || null;
    },
    sync(selectEl) {
        instances.get(selectEl)?.sync?.();
    },
    destroy(selectEl) {
        instances.get(selectEl)?.destroy?.();
    },
    closeAll(exceptSelect = null, doc = null) {
        closeAll(exceptSelect, doc);
    },
});

export function bootAdasiSelects(root = typeof document !== 'undefined' ? document : null) {
    const doc = getDoc(root);
    if (!doc) return 0;

    const initialCount = enhanceWithin(root || doc);
    if (bootedDocuments.has(doc)) {
        return initialCount;
    }
    bootedDocuments.add(doc);

    const win = getWindow(doc.body || doc.documentElement, doc);

    // Light-dismiss fallback when Popover API is unavailable or when clicking outside in non-popover mode.
    doc.addEventListener('pointerdown', (event) => {
        const target = event.target;
        for (const inst of [...activeInstances]) {
            if (!inst.isOpen()) continue;
            if (inst.wrapper.contains?.(target) || inst.popover.contains?.(target)) continue;
            if (typeof inst.popover.hidePopover !== 'function' || !inst.popover.matches?.(':popover-open')) {
                inst.close(false);
            }
        }
    }, true);

    doc.addEventListener('adasi:modal-hide', (event) => {
        const dialog = event.target;
        for (const inst of [...activeInstances]) {
            if (inst.isOpen() && (!dialog || dialog.contains?.(inst.wrapper))) {
                inst.close(false);
            }
        }
    }, true);

    win?.addEventListener?.('adasi:dismiss-overlays', () => closeAll(null, doc));

    // Coalesced MutationObserver automatically upgrades dynamically added selects (DataTables, PR rows, modals).
    const MutationObs = win?.MutationObserver || (typeof MutationObserver !== 'undefined' ? MutationObserver : null);
    if (MutationObs && doc.body) {
        const pendingNodes = new Set();
        let flushScheduled = false;

        const flushPending = () => {
            flushScheduled = false;
            for (const node of [...pendingNodes]) {
                pendingNodes.delete(node);
                if (node.isConnected !== false) {
                    enhanceWithin(node);
                }
            }
        };

        const observer = new MutationObs((records) => {
            let hasNew = false;
            for (const record of records) {
                for (const node of record.addedNodes || []) {
                    if (node.nodeType !== 1) continue;
                    if (node.hasAttribute?.('data-adasi-select') || node.hasAttribute?.('data-adasi-select-popover')) continue;
                    if (node.tagName === 'SELECT' || node.querySelector?.('select')) {
                        pendingNodes.add(node);
                        hasNew = true;
                    }
                }
            }
            if (hasNew && !flushScheduled) {
                flushScheduled = true;
                queueMicrotask(flushPending);
            }
        });

        observer.observe(doc.body, { childList: true, subtree: true });
        docObservers.set(doc, observer);
    }

    return initialCount;
}
