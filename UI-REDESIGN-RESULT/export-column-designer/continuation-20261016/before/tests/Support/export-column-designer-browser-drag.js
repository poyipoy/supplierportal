// Deterministic DragEvent edge/cancel tests in a real renderer. These complement
// native trusted mouse-drag evidence; they do not claim native touch dragging.
async () => {
    if (document.visibilityState !== 'visible') throw new Error('Bring the browser test tab to the foreground first.');
    const root = document.querySelector('[data-advanced-export]');
    const modal = root.querySelector('.modal');
    const wait = async (condition) => { const end = performance.now() + 7000; while (!condition()) { if (performance.now() > end) throw new Error('Not ready'); await new Promise((r) => setTimeout(r, 16)); } };
    if (!modal.classList.contains('show')) root.querySelector('[data-export-open]').click();
    await wait(() => !root.querySelector('[data-export-submit]').disabled);
    root.querySelector('[data-column-command="reset"]').click();
    root.querySelector('[data-column-designer]').scrollIntoView({ block: 'start' });
    const scroll = root.querySelector('[data-column-scroll]');
    const host = root.querySelector('[data-export-columns]');
    const keys = () => [...host.children].map((n) => n.dataset.key);
    const cases = [];
    const check = (name, condition, actual) => { cases.push({ name, status: condition ? 'PASS' : 'FAIL', actual }); if (!condition) throw new Error(name); };
    const start = (key) => {
        const node = [...host.children].find((n) => n.dataset.key === key);
        const rect = node.getBoundingClientRect();
        const dataTransfer = new DataTransfer();
        node.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + 8, dataTransfer }));
        return dataTransfer;
    };
    try {
        const original = keys(); scroll.scrollLeft = 0;
        const dataTransfer = start(original[0]);
        const bounds = scroll.getBoundingClientRect(); const band = host.getBoundingClientRect();
        scroll.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, clientX: bounds.right - 3, clientY: band.top + 12, dataTransfer }));
        await new Promise((r) => setTimeout(r, 180));
        check('Edge hover auto-scrolls actual worksheet without committing preview', scroll.scrollLeft > 0 && JSON.stringify(keys()) === JSON.stringify(original), { scrollLeft: scroll.scrollLeft, keys: keys() });
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        const stopped = scroll.scrollLeft;
        await new Promise((r) => setTimeout(r, 100));
        check('Escape cancels drag, stops rAF and keeps modal/order', scroll.scrollLeft === stopped && modal.classList.contains('show') && !root.querySelector('.is-drag-source,.is-drop-before,.is-drop-after') && JSON.stringify(keys()) === JSON.stringify(original), { scrollLeft: scroll.scrollLeft, keys: keys() });
        const move = start(original[3]); const first = host.children[0].getBoundingClientRect();
        host.children[0].dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, clientX: first.left + 2, clientY: first.top + 10, dataTransfer: move }));
        const expected = [original[3], ...original.filter((k) => k !== original[3])];
        check('Drop before first uses correct insertion point', JSON.stringify(keys()) === JSON.stringify(expected), keys());
        scroll.scrollLeft = scroll.scrollWidth;
        const lastKey = expected[5]; const after = start(lastKey); const last = host.children[host.children.length - 1].getBoundingClientRect();
        host.children[host.children.length - 1].dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, clientX: last.right - 2, clientY: last.top + 10, dataTransfer: after }));
        check('Drop after last while scrolled preserves other order/visibility', JSON.stringify(keys()) === JSON.stringify([...expected.filter((k) => k !== lastKey), lastKey]), keys());
        root.querySelector('[data-column-command="reset"]').click();
        const resetOrder = keys(); const obsolete = start(resetOrder[0]);
        root.querySelector('[data-column-command="reset"]').click();
        const staleTarget = host.children[2].getBoundingClientRect();
        host.children[2].dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, clientX: staleTarget.left + 2, clientY: staleTarget.top + 10, dataTransfer: obsolete }));
        check('A no-op Reset invalidates an obsolete drag drop', JSON.stringify(keys()) === JSON.stringify(resetOrder), keys());
        const cancelled = start(resetOrder[0]);
        const outside = root.querySelector('[data-filter-name="po_number"]');
        const outsideEvent = new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: cancelled });
        outside.dispatchEvent(outsideEvent);
        check('Cancelled outside drop prevents native text insertion into filters', outsideEvent.defaultPrevented && JSON.stringify(keys()) === JSON.stringify(resetOrder), { defaultPrevented: outsideEvent.defaultPrevented, keys: keys() });
        return { status: 'PASS', cases, input: 'Synthetic DragEvent/KeyboardEvent in real Chrome; separate native drag artifacts prove trusted mouse path.' };
    } catch (error) { return { status: 'FAIL', error: error.message, cases, keys: keys() }; }
}
