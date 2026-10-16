// DevTools function on the real rendered Purchasing PO page. Browser-only
// preset/SDK adapters prevent writes to the shared DB; the report names that seam.
async () => {
    const root = document.querySelector('[data-advanced-export][data-export-key="purchasing.po"]');
    const modal = root.querySelector('.modal');
    const cases = [];
    const assert = (name, ok, actual) => { cases.push({ name, status: ok ? 'PASS' : 'FAIL', actual }); if (!ok) throw new Error(name + ': ' + JSON.stringify(actual)); };
    const keys = () => [...root.querySelectorAll('[data-export-column]')].map((el) => el.dataset.key);
    const expect = (name, expected) => assert(name, JSON.stringify(keys()) === JSON.stringify(expected), keys());
    const button = (command) => root.querySelector(`[data-column-command="${command}"]`);
    const pick = (key) => root.querySelector(`[data-export-column][data-key="${key}"]`).click();
    const wait = async (predicate) => {
        const deadline = performance.now() + 8000;
        while (!predicate()) { if (performance.now() > deadline) throw new Error('Browser readiness timeout.'); await new Promise(requestAnimationFrame); }
    };
    const visible = (el) => !el.hidden && el.getBoundingClientRect().width > 0;
    const defaults = ['po_number', 'pr_number', 'supplier', 'material', 'currency', 'total_amount', 'total_idr', 'est_arrival', 'remark', 'status'];
    const fetchOriginal = window.fetch;
    const sdkOriginal = window.AdasiAsyncExport;
    const posts = []; const exports = []; let presets = [];
    window.fetch = async (url, init = {}) => {
        if (String(url).startsWith(root.dataset.presetsUrl)) {
            const method = init.method || 'GET';
            if (method === 'GET') return new Response(JSON.stringify({ data: presets }), { status: 200 });
            const input = JSON.parse(init.body);
            posts.push({ url: String(url), method, columns: input.columns, format: input.format, fields: Object.keys(input), csrf: Boolean(init.headers['X-CSRF-TOKEN']), credentials: init.credentials });
            presets = [{ ...input, id: 'browser-proof-preset', warnings: [] }];
            return new Response(JSON.stringify(presets[0]), { status: 201 });
        }
        return fetchOriginal(url, init);
    };
    window.AdasiAsyncExport = { ...sdkOriginal, startExport: async (form, options) => { exports.push(structuredClone(options.body)); return false; } };
    try {
        if (!modal.classList.contains('show')) root.querySelector('[data-export-open]').click();
        await wait(() => !root.querySelector('[data-export-submit]').disabled);
        button('reset').click(); expect('Reset is exact server defaults (10 of 12)', defaults);
        const moveButtons = [...root.querySelectorAll('[data-column-command="move-left"],[data-column-command="move-right"]')];
        assert('Visible action labels are included in accessible names', moveButtons.every((node) => node.getAttribute('aria-label').toLocaleLowerCase().includes(node.textContent.replace(/\s+/g, ' ').trim().toLocaleLowerCase())), moveButtons.map((node) => ({ visible: node.textContent.trim(), name: node.getAttribute('aria-label') })));
        assert('Three decorative empty rows and no inputs', root.querySelector('[data-column-empty-rows]').children.length === 3 && !root.querySelector('[data-column-empty-rows] input'), 3);
        assert('Required Hide disabled; required is still selectable', button('hide').disabled, button('hide').title);
        pick('currency'); button('hide').click(); pick('remark'); button('move-left').click();
        button('unhide').click();
        const select = root.querySelector('[data-column-hidden-select]'); select.value = 'currency'; select.dispatchEvent(new Event('change', { bubbles: true }));
        assert('Unhide predicts retained slot', root.querySelector('[data-column-restore-position]').textContent.includes('Total Amount'), root.querySelector('[data-column-restore-position]').textContent);
        button('restore').click(); expect('Hide Move Unhide order', ['po_number', 'pr_number', 'supplier', 'material', 'currency', 'total_amount', 'total_idr', 'remark', 'est_arrival', 'status']);
        assert('Restored column focused', document.activeElement?.dataset.key === 'currency', document.activeElement?.dataset.key);
        button('reset').click();
        for (const key of defaults.filter((key) => key !== 'po_number')) { pick(key); button('hide').click(); }
        expect('All optional hidden', ['po_number']); assert('Last required cannot hide', button('hide').disabled, button('hide').title);
        button('unhide').click(); select.value = 'status'; select.dispatchEvent(new Event('change', { bubbles: true })); button('restore').click(); button('move-left').click(); expect('Required may be reordered', ['status', 'po_number']);
        button('reset').click();
        for (const key of defaults.filter((key) => !['po_number', 'remark', 'currency'].includes(key))) { pick(key); button('hide').click(); }
        pick('remark'); button('move-left').click(); button('move-left').click(); expect('Output bridge ordered subset', ['remark', 'po_number', 'currency']);
        root.querySelector('[data-export-preset-name]').value = 'Browser proof'; root.querySelector('[data-export-default]').checked = true; root.querySelector('[data-export-format]').value = 'csv';
        root.querySelector('[data-export-save]').click(); await wait(() => root.querySelector('[data-export-status]').textContent === 'Preset saved.' && !root.querySelector('[data-export-save]').disabled);
        expect('Save Reload Preset retains visible order', ['remark', 'po_number', 'currency']);
        root.querySelector('[data-advanced-export-form]').requestSubmit(); await wait(() => exports.length === 1 && !root.querySelector('[data-export-submit]').disabled);
        assert('Normal Export serializer and Save match', JSON.stringify(posts[0].columns) === JSON.stringify(exports[0].options.columns) && exports[0].options.format === 'csv', { post: posts[0], export: exports[0] });
        assert('Save retains same-origin credentials and the form CSRF header', posts[0].credentials === 'same-origin' && posts[0].csrf, posts[0]);
        button('menu').click(); const menu = root.querySelector('[data-column-menu]');
        const mr = menu.getBoundingClientRect(); assert('Menu in modal trap and viewport', modal.contains(menu) && visible(menu) && mr.left >= 0 && mr.right <= innerWidth && mr.top >= 0 && mr.bottom <= innerHeight && menu.contains(document.activeElement), { x: mr.x, y: mr.y, width: mr.width, height: mr.height });
        const menuHeight = menu.style.maxHeight;
        try {
            menu.style.maxHeight = '70px';
            menu.scrollTop = menu.scrollHeight;
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            assert('Overflowing menu scroll keeps commands reachable', !menu.hidden && menu.scrollTop > 0, { hidden: menu.hidden, scrollTop: menu.scrollTop });
            menu.querySelector('[data-column-command="reset"]').focus();
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            assert('Keyboard focus reaches the last overflowed menu command', !menu.hidden && document.activeElement?.dataset.columnCommand === 'reset', document.activeElement?.dataset.columnCommand);
        } finally { menu.style.maxHeight = menuHeight; }
        root.querySelector('.modal-body').dispatchEvent(new Event('scroll'));
        assert('Ancestor scroll closes menu and restores invoker focus', menu.hidden && document.activeElement === button('menu'), { hidden: menu.hidden, focus: document.activeElement?.dataset.columnCommand });
        button('menu').click();
        window.dispatchEvent(new Event('resize'));
        assert('Resize closes menu and restores invoker focus', menu.hidden && document.activeElement === button('menu'), { hidden: menu.hidden, focus: document.activeElement?.dataset.columnCommand });
        button('menu').click();
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        assert('First Escape closes menu only', menu.hidden && modal.classList.contains('show'), { menuHidden: menu.hidden, modalOpen: modal.classList.contains('show') });
        pick('po_number'); document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true }));
        assert('Keyboard End selects last visible header', document.activeElement?.dataset.key === 'currency', document.activeElement?.dataset.key);
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true, cancelable: true }));
        assert('Keyboard Home selects first visible header', document.activeElement?.dataset.key === 'remark', document.activeElement?.dataset.key);
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true, cancelable: true }));
        assert('Keyboard context menu focuses a command', !menu.hidden && menu.contains(document.activeElement), document.activeElement?.dataset.columnCommand);
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        bootstrap.Modal.getOrCreateInstance(modal).hide(); await wait(() => !modal.classList.contains('show') && !document.querySelector('.modal-backdrop'));
        assert('Close restores actual opener', document.activeElement === root.querySelector('[data-export-open]'), document.activeElement?.id);
        root.querySelector('[data-export-quick]').click(); await wait(() => exports.length === 2 && modal.classList.contains('show') && !root.querySelector('[data-export-submit]').disabled);
        assert('Quick uses identical serialized personal default', JSON.stringify(exports[1].options) === JSON.stringify(exports[0].options), exports[1].options);
        button('reset').click(); expect('Reset after preset restores defaults only', defaults);
        root.querySelector('.modal-body').scrollTop = 0;
        return { status: 'PASS', scope: 'Real rendered DOM and module/controller; preset HTTP and SDK dispatch replaced with browser-memory adapters; no database writes.', cases, posts, exports, headersForOutputBridge: ['Remark', 'PO Number', 'Currency'], viewport: [innerWidth, innerHeight], theme: document.documentElement.dataset.theme, locale: document.documentElement.lang };
    } catch (error) {
        return { status: 'FAIL', error: error.message, cases, posts, exports, keys: keys() };
    } finally { window.fetch = fetchOriginal; window.AdasiAsyncExport = sdkOriginal; }
}
