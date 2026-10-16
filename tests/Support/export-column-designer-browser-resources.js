// DevTools initScript, installed before application code on the metadata-only
// fixture. WeakMap instrumentation never keeps detached event targets alive.
(() => {
    const registry = new WeakMap();
    const add = EventTarget.prototype.addEventListener;
    const remove = EventTarget.prototype.removeEventListener;
    EventTarget.prototype.addEventListener = function (type, listener, options) {
        if (!listener) return add.call(this, type, listener, options);
        const capture = typeof options === 'boolean' ? options : Boolean(options?.capture);
        const key = type + ':' + capture;
        if (!registry.has(this)) registry.set(this, new Map());
        const target = registry.get(this);
        if (!target.has(key)) target.set(key, new Map());
        const entries = target.get(key);
        if (entries.has(listener)) return;
        const wrapped = function (event) {
            if (options?.once) entries.delete(listener);
            if (typeof listener === 'function') return listener.call(this, event);
            return listener.handleEvent(event);
        };
        entries.set(listener, wrapped);
        return add.call(this, type, wrapped, options);
    };
    EventTarget.prototype.removeEventListener = function (type, listener, options) {
        const capture = typeof options === 'boolean' ? options : Boolean(options?.capture);
        const entries = registry.get(this)?.get(type + ':' + capture);
        const wrapped = entries?.get(listener);
        entries?.delete(listener);
        return remove.call(this, type, wrapped || listener, options);
    };
    const request = window.requestAnimationFrame;
    const cancel = window.cancelAnimationFrame;
    const frames = new Set();
    window.requestAnimationFrame = (callback) => {
        const id = request.call(window, (time) => { frames.delete(id); callback(time); });
        frames.add(id);
        return id;
    };
    window.cancelAnimationFrame = (id) => { frames.delete(id); cancel.call(window, id); };
    window.__columnResources = { listeners: (target) => [...(registry.get(target)?.values() || [])].reduce((sum, entries) => sum + entries.size, 0), pendingFrames: () => frames.size };
})();
