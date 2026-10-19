import test, { mock } from 'node:test';
import assert from 'node:assert/strict';

// Loads the real guard (resources/js/unsaved-changes.js) against a minimal window/document, so the order of
// "custom confirmation, then page-level beforeunload" can be asserted without a browser. The real-browser behaviour
// (capture listeners on window run first) is covered by the manual Chrome check noted in the change report.
let instance = 0;

async function loadGuard() {
    const previous = { window: globalThis.window, document: globalThis.document };
    const win = new EventTarget();
    const leaveEvents = [];
    const leave = () => {
        // A browser raises beforeunload on the window when the page is about to go away.
        const event = new Event('beforeunload', { cancelable: true });
        leaveEvents.push(event);
        win.dispatchEvent(event);
    };
    Object.assign(win, {
        location: { pathname: '/profile/notifications', href: 'http://localhost/profile/notifications', reload: leave },
        history: { state: null, pushState(state) { this.state = state; }, back() {} },
        AdasiI18n: { t: (key) => key },
        AdasiAlert: { confirmDanger: async () => ({ isConfirmed: true }) },
    });
    const doc = Object.assign(new EventTarget(), { title: 'Test', readyState: 'complete', querySelectorAll: () => [], querySelector: () => null });
    globalThis.window = win;
    globalThis.document = doc;

    await import(`../../resources/js/unsaved-changes.js?case=${++instance}`);

    // Like a page component, register the handler AFTER the guard has loaded.
    const page = { ran: 0 };
    win.addEventListener('beforeunload', (event) => {
        page.ran += 1;
        event.preventDefault();
        event.returnValue = '';
    });

    return {
        win, leave, leaveEvents, page, guard: win.AdasiUnsaved,
        restore() { globalThis.window = previous.window; globalThis.document = previous.document; },
    };
}

test('closing the tab with unsaved changes still lets the page ask (nothing was confirmed in our dialog)', async () => {
    const fixture = await loadGuard();
    try {
        fixture.guard.markDirty();
        fixture.leave();
        assert.equal(fixture.page.ran, 1);
        assert.equal(fixture.leaveEvents[0].defaultPrevented, true, 'the native prompt stays available for a plain tab close');
    } finally { fixture.restore(); }
});

test('after confirming a reload in our dialog the page handler still runs but cannot raise a second prompt', async () => {
    const fixture = await loadGuard();
    try {
        fixture.guard.markDirty();
        await fixture.guard.showReloadConfirmation();
        assert.equal(fixture.leaveEvents.length, 1, 'the confirmed reload happened');
        assert.equal(fixture.page.ran, 1, 'handlers that do other work (draft persistence) are not silenced');
        assert.equal(fixture.leaveEvents[0].defaultPrevented, false, 'no native "Reload site?" on top of "Reload page?"');
    } finally { fixture.restore(); }
});

test('after confirming leaving through a link or Back the native prompt is not raised either', async () => {
    const fixture = await loadGuard();
    try {
        fixture.guard.markDirty();
        await fixture.guard.showLeaveConfirmation(() => fixture.leave());
        assert.equal(fixture.leaveEvents.length, 1);
        assert.equal(fixture.page.ran, 1);
        assert.equal(fixture.leaveEvents[0].defaultPrevented, false);
    } finally { fixture.restore(); }
});

test('cancelling the custom dialog changes nothing: a later tab close can still prompt', async () => {
    const fixture = await loadGuard();
    try {
        fixture.win.AdasiAlert.confirmDanger = async () => ({ isConfirmed: false });
        fixture.guard.markDirty();
        await fixture.guard.showReloadConfirmation();
        assert.equal(fixture.leaveEvents.length, 0, 'no reload when cancelled');
        fixture.leave();
        assert.equal(fixture.leaveEvents[0].defaultPrevented, true);
    } finally { fixture.restore(); }
});

test('the exemption expires, and a restored back/forward page starts clean', async (t) => {
    mock.timers.enable({ apis: ['setTimeout'] });
    t.after(() => mock.timers.reset());
    const fixture = await loadGuard();
    try {
        fixture.guard.markDirty();
        await fixture.guard.showReloadConfirmation();
        assert.equal(fixture.leaveEvents[0].defaultPrevented, false);

        mock.timers.tick(4100);
        fixture.leave();
        assert.equal(fixture.leaveEvents[1].defaultPrevented, true, 'after the window a prompt is allowed again');

        await fixture.guard.showReloadConfirmation();
        fixture.win.dispatchEvent(new Event('pageshow'));
        fixture.leave();
        assert.equal(fixture.leaveEvents[3].defaultPrevented, true, 'pageshow clears a pending confirmation');
    } finally { fixture.restore(); }
});
