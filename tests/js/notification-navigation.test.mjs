import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { navigateInWorkspaceOrTop } from '../../resources/js/workspace-navigation.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

function createFakeWindow({ href = 'https://portal.adasi.test/purchasing/dashboard', shell = null } = {}) {
    const parsed = new URL(href);
    const assigned = [];
    const opened = [];

    const win = {
        location: {
            href: parsed.href,
            origin: parsed.origin,
            assign(nextUrl) {
                assigned.push(nextUrl);
                this.href = nextUrl;
            },
        },
        AdasiWorkspaceShell: shell === true
            ? {
                openUrl(nextUrl) {
                    opened.push(nextUrl);
                },
            }
            : shell,
    };

    return { win, assigned, opened };
}

test('navigateInWorkspaceOrTop opens same-origin notification targets inside WorkspaceShell without refreshing top window', async () => {
    const { win, assigned, opened } = createFakeWindow({ shell: true });

    const relativeResult = await navigateInWorkspaceOrTop('/purchasing/purchase-orders/abc123?tab=docs#timeline', { win });
    assert.deepEqual(relativeResult, {
        navigated: true,
        inShell: true,
        url: 'https://portal.adasi.test/purchasing/purchase-orders/abc123?tab=docs#timeline',
    });
    assert.deepEqual(opened, ['https://portal.adasi.test/purchasing/purchase-orders/abc123?tab=docs#timeline']);
    assert.deepEqual(assigned, []);

    const absoluteResult = await navigateInWorkspaceOrTop('https://portal.adasi.test/supplier/shipments/xyz789', { win });
    assert.deepEqual(absoluteResult, {
        navigated: true,
        inShell: true,
        url: 'https://portal.adasi.test/supplier/shipments/xyz789',
    });
    assert.deepEqual(opened, [
        'https://portal.adasi.test/purchasing/purchase-orders/abc123?tab=docs#timeline',
        'https://portal.adasi.test/supplier/shipments/xyz789',
    ]);
    assert.deepEqual(assigned, []);
});

test('navigateInWorkspaceOrTop awaits ensureShell when WorkspaceShell is still booting', async () => {
    const { win, assigned } = createFakeWindow({ shell: null });
    const opened = [];

    const result = await navigateInWorkspaceOrTop('/finance/invoices/invHash', {
        win,
        ensureShell: async () => ({
            openUrl(nextUrl) {
                opened.push(nextUrl);
            },
        }),
    });

    assert.deepEqual(result, {
        navigated: true,
        inShell: true,
        url: 'https://portal.adasi.test/finance/invoices/invHash',
    });
    assert.deepEqual(opened, ['https://portal.adasi.test/finance/invoices/invHash']);
    assert.deepEqual(assigned, []);
});

test('navigateInWorkspaceOrTop falls back to top-level navigation when WorkspaceShell is unavailable', async () => {
    const { win, assigned, opened } = createFakeWindow({ shell: null });

    const result = await navigateInWorkspaceOrTop('/purchasing/quotations/qHash', {
        win,
        ensureShell: async () => null,
    });

    assert.deepEqual(result, {
        navigated: true,
        inShell: false,
        url: 'https://portal.adasi.test/purchasing/quotations/qHash',
    });
    assert.deepEqual(opened, []);
    assert.deepEqual(assigned, ['https://portal.adasi.test/purchasing/quotations/qHash']);
});

test('navigateInWorkspaceOrTop never routes cross-origin or unsafe URLs into WorkspaceShell', async () => {
    const { win, assigned, opened } = createFakeWindow({ shell: true });

    const jsResult = await navigateInWorkspaceOrTop('javascript:alert(1)', { win });
    assert.deepEqual(jsResult, { navigated: false, inShell: false, url: null });
    assert.deepEqual(opened, []);
    assert.deepEqual(assigned, []);

    const emptyResult = await navigateInWorkspaceOrTop('', { win });
    assert.deepEqual(emptyResult, { navigated: false, inShell: false, url: null });

    const crossOriginResult = await navigateInWorkspaceOrTop('https://external.example.com/notice', { win });
    assert.deepEqual(crossOriginResult, {
        navigated: true,
        inShell: false,
        url: 'https://external.example.com/notice',
    });
    assert.deepEqual(opened, []);
    assert.deepEqual(assigned, ['https://external.example.com/notice']);
});

test('realtime toast and navbar notification handlers route through WorkspaceShell instead of refreshing the browser', () => {
    const appLayout = read('resources/views/layouts/app.blade.php');
    const navbar = read('resources/views/partials/navbar.blade.php');
    const appJs = read('resources/js/app.js');

    assert.doesNotMatch(appLayout, /window\.location\.href\s*=\s*data\.redirect/);
    assert.match(appLayout, /markReadAndRedirect\s*\(\s*readUrl,\s*notification\.id\s*\)/);
    assert.match(appLayout, /window\.AdasiNavigateUrl/);
    assert.match(appLayout, /window\.syncNotificationReadState/);

    assert.doesNotMatch(navbar, /window\.location\.href\s*=\s*data\.redirect/);
    assert.match(navbar, /openNotificationTarget\(data\.redirect\)/);
    assert.match(navbar, /syncNotificationReadState\(data,\s*item\.dataset\.notificationId/);
    assert.match(navbar, /closeNotificationDropdown\(item\)/);
    assert.match(navbar, /if\s*\(!inWorkspaceShell\)\s*\{[\s\S]*window\.location\.reload\(\);/);

    assert.match(appJs, /window\.AdasiNavigateUrl\s*=\s*\(url\)\s*=>\s*navigateInWorkspaceOrTop/);
    assert.match(appJs, /ensureWorkspaceShell/);
});

