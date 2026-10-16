// The complete catalog order and visibility membership are the only column
// configuration. Worksheet letters and the DOM are projections of this state.
function catalogKeys(catalog) {
    if (!Array.isArray(catalog) || catalog.length < 1 || catalog.length > 64) {
        throw new Error('Invalid column catalog size.');
    }
    for (const column of catalog) {
        if (!column || typeof column.key !== 'string'
            || !/^[a-z][a-z0-9_]{0,63}$/.test(column.key) || column.key.trim() !== column.key
            || typeof column.label !== 'string' || !column.label.trim()
            || typeof column.default !== 'boolean' || typeof column.required !== 'boolean') {
            throw new Error('Invalid column catalog metadata.');
        }
        if (column.required && !column.default) throw new Error('Required columns must be defaults.');
    }
    const keys = catalog.map((column) => column.key);
    if (new Set(keys).size !== keys.length) throw new Error('Duplicate column catalog keys.');
    if (!catalog.some((column) => column.default)) throw new Error('Column defaults must not be empty.');
    return keys;
}

function validatePreset(catalog, selected) {
    const keys = catalog.map((column) => column.key);
    if (!Array.isArray(selected) || selected.length < 1
        || new Set(selected).size !== selected.length
        || [...selected].some((key) => !keys.includes(key))
        || catalog.some((column) => column.required && !selected.includes(column.key))) {
        throw new Error('Invalid presented preset columns.');
    }
}

const sameOrder = (left, right) => left.length === right.length && right.every((key, index) => key === left[index]);

export function assertColumnState(state) {
    if (!state || typeof state !== 'object') throw new Error('Missing column state.');
    const keys = catalogKeys(state.catalog);
    const defaults = state.catalog.filter((column) => column.default).map((column) => column.key);
    if (!Array.isArray(state.defaults) || !sameOrder(state.defaults, defaults)
        || !Array.isArray(state.order) || state.order.length !== keys.length
        || new Set(state.order).size !== keys.length || keys.some((key) => !state.order.includes(key))) {
        throw new Error('Invalid complete column order or defaults.');
    }
    validatePreset(state.catalog, state.visible);
    if (!state.visible.includes(state.activeKey) || !Number.isSafeInteger(state.revision) || state.revision < 0) {
        throw new Error('Invalid active column or revision.');
    }
    return true;
}

export function createColumnState(catalog, selected) {
    const keys = catalogKeys(catalog);
    const snapshot = Object.freeze(catalog.map(({ key, label, default: initial, required }) => Object.freeze({
        key, label, default: initial, required,
    })));
    const defaults = Object.freeze(snapshot.filter((column) => column.default).map((column) => column.key));
    const columns = selected === undefined ? defaults : selected;
    validatePreset(snapshot, columns);
    const state = Object.freeze({
        catalog: snapshot,
        defaults,
        order: Object.freeze([...columns, ...keys.filter((key) => !columns.includes(key))]),
        // A frozen array expresses set membership without exposing mutable Set.add().
        visible: Object.freeze([...columns]),
        activeKey: columns[0],
        revision: 0,
    });
    assertColumnState(state);
    return state;
}

export function serializeColumns(state) {
    assertColumnState(state);
    return state.order.filter((key) => state.visible.includes(key));
}

function commit(state, order, visible, activeKey) {
    const changed = !sameOrder(state.order, order) || state.visible.length !== visible.length
        || state.visible.some((key) => !visible.includes(key));
    if (!changed && activeKey === state.activeKey) return state;
    const next = Object.freeze({
        ...state,
        order: order === state.order ? order : Object.freeze(order),
        visible: visible === state.visible ? visible : Object.freeze(visible),
        activeKey,
        revision: state.revision + Number(changed),
    });
    assertColumnState(next);
    return next;
}

export function transitionColumns(state, command) {
    assertColumnState(state);
    if (!command || typeof command !== 'object') return state;
    const { type, key } = command;
    if (type === 'reset' || type === 'preset') {
        const selected = type === 'reset' ? state.defaults : command.columns;
        validatePreset(state.catalog, selected);
        const order = [...selected, ...state.catalog.map((column) => column.key).filter((candidate) => !selected.includes(candidate))];
        return commit(state, order, [...selected], selected[0]);
    }
    const worksheet = state.order.filter((candidate) => state.visible.includes(candidate));
    const index = worksheet.indexOf(key);
    if (type === 'unhide') {
        if (index !== -1 || !state.order.includes(key)) return state;
        return commit(state, state.order, [...state.visible, key], key);
    }
    if (index === -1) return state;
    if (type === 'select') return commit(state, state.order, state.visible, key);
    if (type === 'hide') {
        if (worksheet.length === 1 || state.catalog.find((column) => column.key === key).required) return state;
        return commit(state, state.order, state.visible.filter((candidate) => candidate !== key), worksheet[index + 1] ?? worksheet[index - 1]);
    }
    let target = command.target;
    let side = command.side;
    if (type === 'move-left') { target = worksheet[index - 1]; side = 'before'; }
    else if (type === 'move-right') { target = worksheet[index + 1]; side = 'after'; }
    else if (type !== 'drop') return state;
    if (key === target || !worksheet.includes(target) || !['before', 'after'].includes(side)) return state;
    const order = state.order.filter((candidate) => candidate !== key);
    order.splice(order.indexOf(target) + Number(side === 'after'), 0, key);
    return commit(state, order, state.visible, key);
}

export function columnLetter(index) {
    if (!Number.isSafeInteger(index) || index < 0) throw new Error('Invalid worksheet column index.');
    let value = index + 1;
    let letter = '';
    while (value > 0) {
        value -= 1;
        letter = String.fromCharCode(65 + value % 26) + letter;
        value = Math.floor(value / 26);
    }
    return letter;
}

const designers = new WeakMap();

// This view has no transport or persistence. All column commands go through
// transitionColumns(); drag candidates, layers, and focus are transient only.
export function createColumnDesigner(root, { text }) {
    if (designers.has(root)) return designers.get(root);
    const document = root.ownerDocument || globalThis.document;
    const window = document.defaultView || globalThis.window;
    const host = root.querySelector('[data-export-columns]');
    const scroll = root.querySelector('[data-column-scroll]');
    const emptyRows = root.querySelector('[data-column-empty-rows]');
    const template = root.querySelector('[data-export-column-template]');
    const fieldset = root.querySelector('[data-column-designer]');
    const menu = root.querySelector('[data-column-menu]');
    const unhide = root.querySelector('[data-column-unhide]');
    const hiddenSelect = root.querySelector('[data-column-hidden-select]');
    const feedback = root.querySelector('[data-column-feedback]');
    const modal = root.querySelector('.modal');
    if (!host || !scroll || !emptyRows || !template?.content?.firstElementChild || !fieldset || !menu || !unhide || !hiddenSelect || !modal) {
        throw new Error('Column designer markup is incomplete.');
    }
    let state;
    let enabled = false;
    let generation = 0;
    let layer = null;
    let invoker = null;
    let drag = null;
    let frame = 0;
    let typeBuffer = '';
    let typeTimer;
    const listeners = [];
    const listen = (target, name, handler, options) => {
        target.addEventListener(name, handler, options);
        listeners.push(() => target.removeEventListener(name, handler, options));
    };
    const headers = () => Array.from(host.querySelectorAll('[data-export-column]'));
    const headerFor = (key) => headers().find((node) => node.dataset.key === key);
    const labelFor = (key) => state.catalog.find((column) => column.key === key)?.label || '';
    // Committed states are already validated and deeply frozen by the model.
    // Revalidate at the public serialization boundary, not on each focus/paint.
    const worksheet = () => state.order.filter((key) => state.visible.includes(key));
    const hiddenKeys = () => state.order.filter((key) => !state.visible.includes(key));
    const announce = (message = '') => { if (feedback) feedback.textContent = message; };
    const focusHeader = () => {
        if (!enabled || !state) return;
        const node = headerFor(state.activeKey);
        node?.focus({ preventScroll: true });
        node?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    };
    const clearType = () => { typeBuffer = ''; window.clearTimeout(typeTimer); typeTimer = undefined; };
    const restoreFocus = (origin) => {
        if (!enabled || !state) return;
        if (origin?.isConnected && !origin.closest('[hidden]') && !origin.disabled) origin.focus({ preventScroll: true });
        else focusHeader();
    };
    const outsideLayer = (event) => { if (layer && !layer.contains(event.target)) closeLayer(false); };
    const layerMoved = (event) => {
        // Scrolling a constrained layer must not dismiss its own commands.
        if (event.target?.nodeType && layer?.contains(event.target)) return;
        closeLayer(Boolean(layer?.contains(document.activeElement)));
    };
    function closeLayer(restore = false) {
        const origin = invoker;
        menu.hidden = true; unhide.hidden = true;
        layer = null; invoker = null;
        root.querySelectorAll('[data-column-command="menu"], [data-column-command="unhide"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
        document.removeEventListener('pointerdown', outsideLayer, true);
        document.removeEventListener('scroll', layerMoved, true);
        window.removeEventListener('resize', layerMoved);
        window.visualViewport?.removeEventListener('resize', layerMoved);
        window.visualViewport?.removeEventListener('scroll', layerMoved);
        if (restore) restoreFocus(origin);
    }
    const clearIndicators = () => host.querySelectorAll('.is-drag-source, .is-drop-before, .is-drop-after').forEach((node) => node.classList.remove('is-drag-source', 'is-drop-before', 'is-drop-after'));
    const outsideDrop = (event) => { if (!scroll.contains(event.target)) { event.preventDefault(); cancelDrag(true); } };
    const endDrag = () => cancelDrag(true);
    function cancelDrag(notify = false) {
        const hadDrag = Boolean(drag);
        drag = null;
        if (frame) window.cancelAnimationFrame(frame);
        frame = 0;
        clearIndicators();
        document.removeEventListener('keydown', onKeyDown, true);
        document.removeEventListener('drop', outsideDrop, true);
        document.removeEventListener('dragend', endDrag, true);
        window.removeEventListener('blur', endDrag);
        if (hadDrag && notify) announce(text('drag_cancelled'));
    }
    const cancelTransient = () => { closeLayer(); cancelDrag(); clearType(); generation += 1; };
    const guard = () => state?.catalog.find((column) => column.key === state.activeKey)?.required
        ? text('hide_required') : state?.visible.length === 1 ? text('hide_last') : '';
    function updateControls() {
        fieldset.disabled = !enabled;
        host.setAttribute('aria-disabled', String(!enabled));
        const visible = state ? worksheet() : [];
        const index = visible.indexOf(state?.activeKey);
        const hidden = state ? hiddenKeys() : [];
        root.querySelectorAll('[data-column-command]').forEach((button) => {
            const command = button.dataset.columnCommand;
            button.disabled = !enabled || !state || (command === 'hide' && Boolean(guard()))
                || (command === 'move-left' && index <= 0) || (command === 'move-right' && index >= visible.length - 1)
                || (command === 'unhide' && hidden.length === 0) || (command === 'restore' && !hidden.includes(hiddenSelect.value));
            if (command === 'hide') button.title = state ? guard() : '';
            if (state && ['move-left', 'move-right'].includes(command)) {
                button.setAttribute('aria-label', text(command.replace('-', '_'), { column: labelFor(state.activeKey) }));
            }
            if (command === 'unhide') button.title = hidden.length ? '' : text('hidden_none');
        });
        headers().forEach((node) => {
            const active = node.dataset.key === state?.activeKey;
            if (node.getAttribute('aria-selected') !== String(active)) node.setAttribute('aria-selected', String(active));
            const tabIndex = enabled && active ? 0 : -1;
            if (node.tabIndex !== tabIndex) node.tabIndex = tabIndex;
            if (node.draggable !== enabled) node.draggable = enabled;
        });
        const activeLabel = root.querySelector('[data-column-selected-label]');
        if (activeLabel) activeLabel.textContent = state ? text('active_column', { column: labelFor(state.activeKey) }) : '';
        const summary = root.querySelector('[data-column-summary]');
        if (summary) summary.textContent = state ? text('visible_summary', { count: visible.length, hidden: hidden.length }) : '';
    }
    function restorePosition() {
        const preview = root.querySelector('[data-column-restore-position]');
        const key = hiddenSelect.value;
        if (preview && state && hiddenKeys().includes(key)) {
            const next = state.order.slice(state.order.indexOf(key) + 1).find((candidate) => state.visible.includes(candidate));
            preview.textContent = next ? text('restore_before', { column: labelFor(key), before: labelFor(next) }) : text('restore_end', { column: labelFor(key) });
        } else if (preview) preview.textContent = text('hidden_none');
        updateControls();
    }
    function render() {
        const visible = worksheet();
        // Reuse DOM by stable key. Configuration still comes only from state;
        // node order is compared solely to avoid unnecessary DOM mutations.
        const existing = new Map(headers().map((node) => [node.dataset.key, node]));
        headers().filter((node) => !visible.includes(node.dataset.key)).forEach((node) => node.remove());
        visible.forEach((key, index) => {
            const column = state.catalog.find((candidate) => candidate.key === key);
            const node = existing.get(key) || template.content.firstElementChild.cloneNode(true);
            node.dataset.key = key;
            const letter = node.querySelector('[data-column-letter]');
            if (letter.textContent !== columnLetter(index)) letter.textContent = columnLetter(index);
            const label = node.querySelector('[data-column-label]');
            if (label.textContent !== column.label) label.textContent = column.label;
            const required = node.querySelector('[data-column-required]');
            if (required.hidden === column.required) required.hidden = !column.required;
            if (required.textContent !== text('required')) required.textContent = text('required');
            if (host.children[index] !== node) host.insertBefore(node, host.children[index] || null);
        });
        scroll.style.setProperty('--column-count', String(visible.length));
        if (emptyRows.children.length !== 3 || Array.from(emptyRows.children).some((row) => row.children.length !== visible.length)) {
            emptyRows.replaceChildren();
            for (let row = 0; row < 3; row += 1) {
                const line = document.createElement('div'); line.className = 'ui-column-sheet__row';
                visible.forEach(() => { const cell = document.createElement('div'); cell.className = 'ui-column-sheet__cell'; line.append(cell); });
                emptyRows.append(line);
            }
        }
        const hidden = hiddenKeys();
        if (hiddenSelect.children.length !== hidden.length || Array.from(hiddenSelect.children).some((option, index) => option.value !== hidden[index])) {
            hiddenSelect.replaceChildren();
            hidden.forEach((key) => {
                const option = document.createElement('option'); option.value = key; hiddenSelect.append(option);
            });
        }
        hidden.forEach((key, index) => {
            const option = hiddenSelect.children[index];
            if (option.textContent !== labelFor(key)) option.textContent = labelFor(key);
        });
        restorePosition();
    }
    function apply(command, focus = false) {
        const next = transitionColumns(state, command);
        // Reset is a new interaction intent even when defaults already match.
        if (command.type === 'reset' && (drag || layer || typeBuffer)) cancelTransient();
        if (next === state) {
            if (command.type === 'reset') { announce(text('columns_reset')); if (focus) focusHeader(); }
            return;
        }
        const changed = next.revision !== state.revision;
        if (changed) cancelTransient();
        else if (drag && next.activeKey !== drag.key) cancelDrag(true);
        state = next;
        if (changed) render(); else updateControls();
        const column = labelFor(state.activeKey);
        if (command.type === 'hide') announce(text('column_hidden', { column: labelFor(command.key) }));
        else if (command.type === 'unhide') announce(text('hidden_restored', { column }));
        else if (command.type === 'reset') announce(text('columns_reset'));
        else if (command.type === 'select') announce(guard());
        else announce(text('column_position', { column, position: worksheet().indexOf(state.activeKey) + 1, total: state.visible.length }));
        if (focus) focusHeader();
    }
    function openLayer(next, origin, point) {
        closeLayer(); cancelDrag(); clearType();
        layer = next; invoker = origin;
        layer.hidden = false;
        root.querySelectorAll(`[data-column-command="${next === menu ? 'menu' : 'unhide'}"]`).forEach((button) => button.setAttribute('aria-expanded', 'true'));
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft || 0; const top = viewport?.offsetTop || 0;
        const width = viewport?.width || window.innerWidth; const height = viewport?.height || window.innerHeight;
        layer.style.maxWidth = `${Math.max(0, width - 16)}px`;
        layer.style.maxHeight = `${Math.max(0, height - 16)}px`;
        const anchorNode = origin && !origin.closest('[hidden]') ? origin : headerFor(state.activeKey);
        const anchor = anchorNode.getBoundingClientRect();
        const rect = layer.getBoundingClientRect();
        layer.style.left = `${Math.max(left + 8, Math.min(point?.x ?? anchor.left, left + width - rect.width - 8))}px`;
        layer.style.top = `${Math.max(top + 8, Math.min(point?.y ?? anchor.bottom, top + height - rect.height - 8))}px`;
        document.addEventListener('pointerdown', outsideLayer, true);
        document.addEventListener('scroll', layerMoved, true);
        window.addEventListener('resize', layerMoved);
        viewport?.addEventListener('resize', layerMoved);
        viewport?.addEventListener('scroll', layerMoved);
        if (next === menu) menu.querySelector('[role="menuitem"]:not(:disabled)')?.focus();
        else hiddenSelect.focus();
    }
    const menuItems = () => Array.from(menu.querySelectorAll('[role="menuitem"]')).filter((item) => !item.disabled);
    function tabOut(event) {
        const origin = invoker?.isConnected && !invoker.closest('[hidden]') ? invoker : headerFor(state.activeKey);
        const targets = Array.from(modal.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
            .filter((node) => !node.disabled && node.tabIndex >= 0 && !node.closest('[data-column-layer], [hidden]'));
        const index = targets.indexOf(origin);
        const next = targets[(index + (event.shiftKey ? -1 : 1) + targets.length) % targets.length];
        closeLayer(); event.preventDefault(); next?.focus();
    }
    function onKeyDown(event) {
        if (event.key === 'Escape' && (layer || drag)) {
            event.preventDefault(); event.stopImmediatePropagation();
            if (drag) { cancelDrag(true); focusHeader(); } else closeLayer(true);
            return;
        }
        if (!enabled || !state) return;
        if (layer === menu) {
            const items = menuItems(); const index = items.indexOf(document.activeElement);
            let target;
            if (event.key === 'ArrowDown') target = items[(index + 1) % items.length];
            if (event.key === 'ArrowUp') target = items[(index - 1 + items.length) % items.length];
            if (event.key === 'Home') target = items[0];
            if (event.key === 'End') target = items.at(-1);
            if (target) { event.preventDefault(); target.focus(); }
            if (event.key === 'Tab') tabOut(event);
            return;
        }
        const header = event.target.closest?.('[data-export-column]');
        if (!header || !host.contains(header)) return;
        if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
            event.preventDefault(); openLayer(menu, header); return;
        }
        const visible = worksheet(); const index = visible.indexOf(state.activeKey);
        let key;
        if (event.key === 'ArrowLeft') key = visible[Math.max(0, index - 1)];
        if (event.key === 'ArrowRight') key = visible[Math.min(visible.length - 1, index + 1)];
        if (event.key === 'Home') key = visible[0];
        if (event.key === 'End') key = visible.at(-1);
        if (event.key === ' ' || event.key === 'Enter') key = header.dataset.key;
        if (!key && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            const character = event.key.toLocaleLowerCase();
            typeBuffer = typeBuffer && [...typeBuffer].every((letter) => letter === character) ? character : typeBuffer + character;
            window.clearTimeout(typeTimer); typeTimer = window.setTimeout(clearType, 700);
            const candidates = [...visible.slice(index + 1), ...visible.slice(0, index + 1)];
            key = candidates.find((candidate) => labelFor(candidate).toLocaleLowerCase().startsWith(typeBuffer));
            event.preventDefault();
        }
        if (key) { event.preventDefault(); apply({ type: 'select', key }, true); }
    }
    function onClick(event) {
        if (!enabled || !state) return;
        const button = event.target.closest?.('[data-column-command]');
        if (button && root.contains(button)) {
            if (button.disabled) return;
            const type = button.dataset.columnCommand;
            if (type === 'close-layer') closeLayer(true);
            else if (type === 'menu') openLayer(menu, button);
            else if (type === 'unhide') { restorePosition(); openLayer(unhide, button); }
            else if (type === 'restore') apply({ type: 'unhide', key: hiddenSelect.value }, true);
            else { closeLayer(); apply({ type, key: state.activeKey }, true); }
            return;
        }
        const header = event.target.closest?.('[data-export-column]');
        if (header && host.contains(header)) { closeLayer(); apply({ type: 'select', key: header.dataset.key }, true); }
    }
    function onContextMenu(event) {
        const header = event.target.closest?.('[data-export-column]');
        if (!enabled || !state || !header || !host.contains(header)) return;
        event.preventDefault(); apply({ type: 'select', key: header.dataset.key });
        openLayer(menu, header, { x: event.clientX, y: event.clientY });
    }
    const currentDrag = () => enabled && drag && state && drag.generation === generation && drag.revision === state.revision;
    function hitTest(x, y) {
        const bounds = scroll.getBoundingClientRect(); const band = host.getBoundingClientRect();
        if (x < bounds.left || x > bounds.right || y < band.top || y > band.bottom) return null;
        const nodes = headers();
        for (const node of nodes) {
            const rect = node.getBoundingClientRect();
            if (x <= rect.right) return { target: node.dataset.key, side: x < rect.left + rect.width / 2 ? 'before' : 'after' };
        }
        return nodes.length ? { target: nodes.at(-1).dataset.key, side: 'after' } : null;
    }
    function updateCandidate() {
        if (!currentDrag()) { cancelDrag(); return; }
        const candidate = hitTest(drag.x, drag.y);
        if (candidate?.target === drag.key) { candidate.target = null; }
        headers().forEach((node) => node.classList.remove('is-drop-before', 'is-drop-after'));
        if (candidate?.target) {
            headerFor(candidate.target)?.classList.add(`is-drop-${candidate.side}`);
            if (drag.candidate?.target !== candidate.target || drag.candidate?.side !== candidate.side) {
                const order = worksheet().filter((key) => key !== drag.key);
                const position = order.indexOf(candidate.target) + Number(candidate.side === 'after') + 1;
                announce(text('drag_position', { column: labelFor(drag.key), position, total: state.visible.length }));
            }
        }
        drag.candidate = candidate;
    }
    function autoScroll() {
        frame = 0;
        if (!currentDrag()) return;
        const rect = scroll.getBoundingClientRect(); const band = host.getBoundingClientRect();
        if (drag.y < band.top || drag.y > band.bottom || drag.x < rect.left || drag.x > rect.right) return;
        const delta = drag.x < rect.left + 32 ? -12 : drag.x > rect.right - 32 ? 12 : 0;
        const before = scroll.scrollLeft;
        scroll.scrollLeft = Math.max(0, Math.min(scroll.scrollWidth - scroll.clientWidth, before + delta));
        if (scroll.scrollLeft !== before) { updateCandidate(); frame = window.requestAnimationFrame(autoScroll); }
    }
    function onDragStart(event) {
        const header = event.target.closest?.('[data-export-column]');
        if (!header || !host.contains(header)) return;
        if (!enabled || !state) { event.preventDefault(); return; }
        closeLayer(); cancelDrag(); clearType();
        apply({ type: 'select', key: header.dataset.key });
        header.focus({ preventScroll: true });
        drag = { key: header.dataset.key, generation, revision: state.revision, x: event.clientX, y: event.clientY, candidate: null };
        header.classList.add('is-drag-source');
        if (event.dataTransfer) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', drag.key); }
        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('drop', outsideDrop, true);
        document.addEventListener('dragend', endDrag, true);
        window.addEventListener('blur', endDrag);
    }
    function onDragOver(event) {
        if (!currentDrag() || !scroll.contains(event.target)) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        drag.x = event.clientX; drag.y = event.clientY;
        updateCandidate();
        if (!frame) frame = window.requestAnimationFrame(autoScroll);
    }
    function onDrop(event) {
        if (!currentDrag() || !scroll.contains(event.target)) return;
        event.preventDefault();
        const candidate = hitTest(event.clientX, event.clientY); const key = drag.key;
        cancelDrag();
        if (candidate?.target && candidate.target !== key) apply({ type: 'drop', key, ...candidate }, true);
        else { announce(text('drag_cancelled')); focusHeader(); }
    }
    listen(root, 'click', onClick);
    listen(root, 'keydown', onKeyDown, true);
    listen(root, 'contextmenu', onContextMenu);
    listen(root, 'dragstart', onDragStart);
    listen(root, 'dragover', onDragOver);
    listen(root, 'drop', onDrop);
    listen(hiddenSelect, 'change', restorePosition);
    const api = {
        configure(catalog, selected) {
            const next = createColumnState(catalog, selected);
            cancelTransient(); state = next; render(); announce(guard());
        },
        setColumns(columns) {
            const next = transitionColumns(state, { type: 'preset', columns });
            cancelTransient(); state = next; render(); announce(guard());
        },
        reset() { apply({ type: 'reset' }); },
        serialize() { return serializeColumns(state); },
        setEnabled(value) {
            const next = Boolean(value);
            if (enabled === next) return;
            enabled = next; if (!enabled) cancelTransient(); updateControls();
        },
        suspend() { enabled = false; cancelTransient(); updateControls(); },
        focus: focusHeader,
        getState() { return state; },
        destroy() {
            enabled = false; cancelTransient(); listeners.splice(0).forEach((remove) => remove());
            host.replaceChildren(); emptyRows.replaceChildren(); hiddenSelect.replaceChildren();
            scroll.style.removeProperty('--column-count'); state = undefined; designers.delete(root);
        },
    };
    updateControls();
    designers.set(root, api);
    return api;
}
