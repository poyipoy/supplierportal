// Run on the api=1 fixture with browser-resources.js installed as initScript.
async () => {
    const root = document.querySelector('[data-advanced-export]');
    const modal = root.querySelector('.modal');
    const wait = async (condition) => {
        const deadline = performance.now() + 5000;
        while (!condition()) {
            if (performance.now() > deadline) throw new Error('Cleanup fixture did not settle.');
            await new Promise((resolve) => setTimeout(resolve, 16));
        }
    };
    const counts = () => ({ root: __columnResources.listeners(root), modal: __columnResources.listeners(modal), document: __columnResources.listeners(document), window: __columnResources.listeners(window), viewport: __columnResources.listeners(visualViewport), frames: __columnResources.pendingFrames(), nodes: root.querySelectorAll('*').length, layers: root.querySelectorAll('[data-column-layer]:not([hidden])').length });
    const cycle = async () => {
        root.querySelector('[data-export-open]').click();
        await wait(() => !root.querySelector('[data-export-submit]').disabled);
        root.querySelector('[data-column-command="menu"]').click();
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        bootstrap.Modal.getOrCreateInstance(modal).hide();
        await wait(() => !document.querySelector('.modal-backdrop'));
    };
    await wait(() => Boolean(window.__fixtureController));
    modal.classList.remove('fade'); // Same lifecycle, without animation wait time.
    await cycle(); // Bootstrap's one instance per root is initialized once.
    const baseline = counts(); const samples = [];
    for (let i = 0; i < 60; i++) { await cycle(); if (i === 29 || i === 59) samples.push({ cycle: i + 1, ...counts() }); }
    window.__fixtureController.destroy();
    const afterDestroy = counts();
    const stable = samples.every((sample) => ['root', 'modal', 'document', 'window', 'viewport', 'nodes'].every((key) => sample[key] === baseline[key]) && sample.frames === 0 && sample.layers === 0);
    return { status: stable && afterDestroy.root === 0 && afterDestroy.modal === 0 && afterDestroy.window === baseline.window - 1 && afterDestroy.viewport === baseline.viewport ? 'PASS' : 'FAIL', baseline, samples, afterDestroy, scope: 'Real source modules/Blade; initialized Bootstrap baseline, exact listener/rAF/DOM counts; fade disabled for the loop, normal transitions tested separately; no forced-GC heap proof.' };
}
