const nativeRegistry = new WeakMap();

function resolveWindow(target, win) {
    return win || target?.ownerDocument?.defaultView || target?.defaultView || globalThis.window;
}

function splitEventTokens(eventNames) {
    return String(eventNames || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
}

export function onDataTableEvent(target, eventNames, handler, win = globalThis.window) {
    if (!target || typeof eventNames !== 'string' || !eventNames.trim() || typeof handler !== 'function') {
        return;
    }

    const resolvedWin = resolveWindow(target, win);
    if (typeof resolvedWin?.DataTable?.Dom?.s === 'function') {
        resolvedWin.DataTable.Dom.s(target).on(eventNames, handler);
        return;
    }

    if (typeof target.addEventListener !== 'function') {
        return;
    }

    let entries = nativeRegistry.get(target);
    if (!entries) {
        entries = [];
        nativeRegistry.set(target, entries);
    }

    for (const token of splitEventTokens(eventNames)) {
        const [baseName, ...namespaces] = token.split('.');
        if (!baseName) continue;

        const wrapped = function adasiDtNativeListener(event) {
            if (namespaces.includes('dt') && 'namespace' in event && !String(event.namespace || '').split('.').includes('dt')) {
                return undefined;
            }
            const extraArgs = Array.isArray(event?._args) ? event._args : [];
            return handler.call(target, event, ...extraArgs);
        };

        entries.push({ baseName, namespaces, original: handler, wrapped });
        target.addEventListener(baseName, wrapped);
    }
}

export function offDataTableEvent(target, eventNames, handler, win = globalThis.window) {
    if (!target || typeof eventNames !== 'string' || !eventNames.trim()) {
        return;
    }

    const resolvedWin = resolveWindow(target, win);
    if (typeof resolvedWin?.DataTable?.Dom?.s === 'function') {
        const domTarget = resolvedWin.DataTable.Dom.s(target);
        if (handler) {
            domTarget.off(eventNames, handler);
        } else {
            domTarget.off(eventNames);
        }
        return;
    }

    if (typeof target.removeEventListener !== 'function') {
        return;
    }

    const entries = nativeRegistry.get(target);
    if (!entries?.length) return;

    const nextEntries = [...entries];
    for (const token of splitEventTokens(eventNames)) {
        const [baseName, ...namespaces] = token.split('.');
        for (let i = nextEntries.length - 1; i >= 0; i -= 1) {
            const entry = nextEntries[i];
            const matchesBase = !baseName || entry.baseName === baseName;
            const matchesNs = namespaces.every((ns) => entry.namespaces.includes(ns));
            const matchesFn = !handler || entry.original === handler;
            if (matchesBase && matchesNs && matchesFn) {
                target.removeEventListener(entry.baseName, entry.wrapped);
                nextEntries.splice(i, 1);
            }
        }
    }

    if (nextEntries.length) {
        nativeRegistry.set(target, nextEntries);
    } else {
        nativeRegistry.delete(target);
    }
}
