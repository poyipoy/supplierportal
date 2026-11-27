import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    replaceWithSanitizedServerFragment,
    safeSetHtml,
    sanitizeHtmlToFragment,
} from '../../resources/js/dom-sanitize.js';
import { bindLive } from '../../resources/js/filter-bar.js';
import { isSameOriginHttpUrl, isTrustedSameOrigin } from '../../resources/js/origin-validator.js';
import {
    acquireOverlayModality,
    bindNativeDialogModality,
    getActiveOverlayCount,
    releaseOverlayModality,
    releaseOverlaysWithin,
    resetOverlayModality,
} from '../../resources/js/overlay-modality.js';
import { yieldToMain } from '../../resources/js/task-yield.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const readRepo = (relPath) => readFileSync(resolve(root, relPath), 'utf8');

function readCompiledAppCss() {
    const manifest = JSON.parse(readRepo('public/build/manifest.json'));
    const entry = manifest['resources/css/app.css'];
    assert.ok(entry?.file, 'public/build/manifest.json must contain resources/css/app.css');
    return readRepo(`public/build/${entry.file}`);
}

test('source and compiled CSS respect OS text scale with zero literal px font-sizes in app.css', () => {
    const sourceCss = readRepo('resources/css/app.css');
    const sourcePxFonts = [...sourceCss.matchAll(/font-size\s*:\s*\d+(?:\.\d+)?px\b/g)];
    assert.equal(
        sourcePxFonts.length,
        0,
        `resources/css/app.css must not contain literal px font-size declarations; found: ${sourcePxFonts.map((m) => m[0]).join(', ')}`
    );

    const smartPanelBlade = readRepo('resources/views/components/ui/smart-panel.blade.php');
    assert.equal(/tw:text-\[\d+px\]/.test(smartPanelBlade), false, 'smart-panel.blade.php must use rem token text classes');

    const scorecardBlade = readRepo('resources/views/components/ui/supplier-scorecard.blade.php');
    assert.equal(/tw:text-\[\d+px\]/.test(scorecardBlade), false, 'supplier-scorecard.blade.php must use rem token text classes');

    const compiledCss = readCompiledAppCss();
    const compiledPxFonts = [...compiledCss.matchAll(/font-size\s*:\s*\d+(?:\.\d+)?px\b/g)];
    assert.equal(
        compiledPxFonts.length,
        0,
        `compiled app CSS must contain zero custom px font-size declarations; found: ${compiledPxFonts.map((m) => m[0]).join(', ')}`
    );
});

test('source and compiled CSS preserve focus-visible outlines, token colors, and forced-colors Highlight fallback', () => {
    const appCss = readRepo('resources/css/app.css');
    const authSplashCss = readRepo('resources/css/auth-splash.css');
    const lightboxBlade = readRepo('resources/views/components/ui/image-lightbox.blade.php');
    const compiledCss = readCompiledAppCss();

    // Every outline: none in app.css must be scoped to :focus:not(:focus-visible) (never :focus-visible itself).
    const focusVisibleOutlineNone = appCss.match(/(?<!:not\():focus-visible[^{]*\{[^}]*outline\s*:\s*(?:none|0)\b/g) ?? [];
    assert.equal(focusVisibleOutlineNone.length, 0, 'app.css must never set outline:none/0 on :focus-visible');

    // Non-interactive programmatic splash anchor (.auth-splash__panel[tabindex="-1"]) must not draw a full-width focus box over the GIF,
    // while the interactive .auth-splash__skip button preserves its visible + forced-colors Highlight focus ring.
    assert.match(authSplashCss, /\.auth-splash__panel:focus\s*\{\s*outline\s*:\s*none;/);
    assert.equal(/\.auth-splash__panel:focus-visible\s*\{/.test(authSplashCss), false);
    assert.match(authSplashCss, /\.auth-splash__skip:focus-visible\s*\{\s*outline\s*:/);

    // image-lightbox.blade.php must use M3 token outline (zero raw hex) + forced-colors Highlight.
    assert.match(lightboxBlade, /outline\s*:\s*2px solid var\(--md-on-primary\)/);
    assert.equal(/outline\s*:\s*2px solid #ffffff/i.test(lightboxBlade), false);

    assert.match(appCss, /@media\s*\(\s*forced-colors\s*:\s*active\s*\)/);
    assert.match(authSplashCss, /@media\s*\(\s*forced-colors\s*:\s*active\s*\)/);
    assert.match(compiledCss, /forced-colors\s*:\s*active/i);
    assert.match(compiledCss, /\bHighlight\b/i);
    assert.match(compiledCss, /prefers-contrast\s*:\s*more/);
    assert.match(compiledCss, /prefers-reduced-motion\s*:\s*reduce/);
});

test('compiled CSS enforces color-scheme, scrollbar-gutter, overscroll-contain, :user-invalid/:user-valid, and split progress pseudo-elements', () => {
    const appCss = readRepo('resources/css/app.css');
    const compiledCss = readCompiledAppCss();

    assert.match(compiledCss, /color-scheme\s*:\s*light/);
    assert.match(compiledCss, /color-scheme\s*:\s*dark/);
    assert.match(compiledCss, /scrollbar-gutter\s*:\s*stable/);
    assert.match(compiledCss, /overscroll-behavior\s*:\s*contain/);
    assert.match(compiledCss, /:user-invalid/);
    assert.match(compiledCss, /:user-valid/);
    assert.match(compiledCss, /::-webkit-progress-value/);
    assert.match(compiledCss, /::-moz-progress-bar/);

    // Native <progress> pseudo-elements must never be grouped in the same selector list.
    assert.equal(
        /::-webkit-progress-(?:bar|value)[^{]*::-moz-progress-bar|::-moz-progress-bar[^{]*::-webkit-progress-(?:bar|value)/.test(appCss),
        false,
        '-webkit-progress-* and -moz-progress-bar must stay in separate CSS rules'
    );

    // Background scroll locking must not set overflow: hidden on .ui-dialog-open.
    const dialogOpenRule = appCss.match(/\.ui-dialog-open\s*\{([^}]*)\}/);
    assert.ok(dialogOpenRule, '.ui-dialog-open rule must exist');
    assert.equal(/overflow\s*:\s*hidden/.test(dialogOpenRule[1]), false, '.ui-dialog-open must not lock body with overflow: hidden');
});

test('dom-sanitize preserves real AdasiServerTabs JSON configs while stripping SVG scripts, foreignObject, srcset XSS, and <template>.content payloads', () => {
    const mkEl = (tag, attrs = {}, { text = '', ns = 'http://www.w3.org/1999/xhtml', closestMatch = null } = {}) => {
        const attrMap = new Map(Object.entries(attrs));
        const el = {
            tagName: tag.toUpperCase(),
            namespaceURI: ns,
            isConnected: true,
            textContent: text,
            childNodes: [],
            get attributes() {
                return [...attrMap.entries()].map(([name, value]) => ({ name, value }));
            },
            getAttribute: (k) => (attrMap.has(k) ? attrMap.get(k) : null),
            hasAttribute: (k) => attrMap.has(k),
            removeAttribute: (k) => attrMap.delete(k),
            closest: (sel) => (closestMatch && sel.includes(closestMatch) ? {} : null),
            remove() {
                this.isConnected = false;
                if (this._parent) {
                    const idx = this._parent.childNodes.indexOf(this);
                    if (idx >= 0) this._parent.childNodes.splice(idx, 1);
                }
            },
            append(...nodes) {
                for (const n of nodes) {
                    n._parent = this;
                    this.childNodes.push(n);
                }
            },
            querySelectorAll() {
                const acc = [];
                const walk = (parent) => {
                    for (const child of parent.childNodes) {
                        acc.push(child);
                        walk(child);
                    }
                };
                walk(this);
                return acc;
            },
        };
        return el;
    };

    const body = mkEl('body');
    const scriptBad = mkEl('script', {}, { text: 'alert(1)' });
    // Real Purchasing Comparison / Exchange Rates / Chart JSON scripts (WITHOUT data-json-config!)
    const historicalConfig = mkEl('script', { id: 'historicalConfig', type: 'application/json', onclick: 'evil()' }, { text: '{"rows":1,"x":"<tag>"}' });
    const exchangeConfig = mkEl('script', { type: 'application/json', 'data-exchange-rates-config': '', src: 'https://evil.test/x.js' }, { text: '{"ok":true}' });
    const svgJsonScript = mkEl('script', { id: 'svgSneak', type: 'application/json' }, { text: '{"sneak":1}', ns: 'http://www.w3.org/2000/svg', closestMatch: 'svg' });
    const svgEl = mkEl('svg', { viewBox: '0 0 24 24' });
    const foreignObj = mkEl('foreignObject', {}, { ns: 'http://www.w3.org/2000/svg' });
    const svgAnim = mkEl('animate', { attributeName: 'href', to: 'javascript:alert(1)' }, { ns: 'http://www.w3.org/2000/svg' });
    svgEl.append(foreignObj, svgAnim);

    const tpl = mkEl('template', { onclick: 'evil()' });
    const tplContent = mkEl('div');
    const tplScript = mkEl('script', {}, { text: 'alert("tpl")' });
    const tplImg = mkEl('img', { src: 'x', onerror: 'alert("tpl-img")', srcset: 'safe.png 1x, javascript:alert(9) 2x' });
    tplContent.append(tplScript, tplImg);
    tpl.content = tplContent;

    const iframe = mkEl('iframe', { src: 'https://evil.test' });
    const badJsLink = mkEl('a', { href: 'javascript:alert(3)' }, { text: 'BadJS' });
    const safeLink = mkEl('a', { href: '/dashboard', onclick: 'alert(5)' }, { text: 'SafeLink' });
    body.append(scriptBad, historicalConfig, exchangeConfig, svgJsonScript, svgEl, tpl, iframe, badJsLink, safeLink);

    const fakeDoc = {
        defaultView: {
            DOMParser: class {
                parseFromString() { return { body }; }
            },
        },
        createDocumentFragment: () => {
            const nodes = [];
            return { childNodes: nodes, append: (...n) => nodes.push(...n) };
        },
        adoptNode: (n) => n,
    };

    const target = {
        ownerDocument: fakeDoc,
        children: [],
        replaceChildren(frag) { this.children = [...frag.childNodes]; },
    };

    replaceWithSanitizedServerFragment(target, '<div>server-fragment</div>');
    const tags = target.children.map((n) => n.tagName.toLowerCase());
    assert.equal(tags.includes('iframe'), false, 'iframe must be stripped');
    assert.equal(scriptBad.isConnected, false, 'executable script must be stripped');
    assert.equal(historicalConfig.isConnected, true, 'Purchasing Comparison #historicalConfig JSON script MUST be preserved');
    assert.equal(historicalConfig.getAttribute('id'), 'historicalConfig');
    assert.equal(historicalConfig.hasAttribute('onclick'), false, 'onclick on JSON script must be stripped');
    assert.equal(historicalConfig.textContent.includes('<'), false, 'JSON script must escape < to \\u003c');
    assert.equal(exchangeConfig.isConnected, true, 'data-exchange-rates-config JSON script MUST be preserved');
    assert.equal(exchangeConfig.hasAttribute('data-exchange-rates-config'), true, 'safe data-* attribute must be preserved on JSON script');
    assert.equal(exchangeConfig.hasAttribute('src'), false, 'src attribute on JSON script must be stripped');
    assert.equal(svgJsonScript.isConnected, false, 'SVG-namespaced JSON script MUST be stripped');
    assert.equal(foreignObj.isConnected, false, 'SVG <foreignObject> MUST be stripped even when allowSvg is true');
    assert.equal(svgAnim.isConnected, false, 'SVG <animate> MUST be stripped even when allowSvg is true');
    assert.equal(tpl.isConnected, true, 'Alpine <template> wrapper stays for server fragments');
    assert.equal(tpl.hasAttribute('onclick'), false, 'onclick on <template> must be stripped');
    assert.equal(tplScript.isConnected, false, '<template>.content <script> MUST be stripped');
    assert.equal(tplImg.hasAttribute('onerror'), false, '<template>.content onerror MUST be stripped');
    assert.equal(tplImg.hasAttribute('srcset'), false, 'dangerous srcset URL MUST be stripped');
    assert.equal(badJsLink.hasAttribute('href'), false, 'javascript: href must be stripped');
    assert.equal(safeLink.getAttribute('href'), '/dashboard', 'safe relative href must be preserved');
    assert.equal(safeLink.hasAttribute('onclick'), false, 'onclick on safe link must be stripped');

    // Default sanitizeHtmlToFragment (allowTemplate: false) MUST remove <template> completely.
    const bodyDefault = mkEl('body');
    const defaultTpl = mkEl('template');
    bodyDefault.append(defaultTpl);
    const defaultDoc = {
        defaultView: { DOMParser: class { parseFromString() { return { body: bodyDefault }; } } },
        createDocumentFragment: () => { const nodes = []; return { childNodes: nodes, append: (...n) => nodes.push(...n) }; },
        adoptNode: (n) => n,
    };
    const defaultFrag = sanitizeHtmlToFragment('<template><script>1</script></template>', defaultDoc);
    assert.equal(defaultFrag.childNodes.length, 0, 'default sanitizer must strip <template>');

    // Verify safeSetHtml delegates directly to Element.prototype.setHTML when supported.
    const origElement = globalThis.Element;
    let calledWith = null;
    try {
        function FakeElement() {}
        FakeElement.prototype.setHTML = function (html) { calledWith = html; };
        globalThis.Element = FakeElement;
        const setHtmlTarget = new FakeElement();
        safeSetHtml(setHtmlTarget, '<p>Hello</p>');
        assert.equal(calledWith, '<p>Hello</p>');
    } finally {
        if (origElement === undefined) delete globalThis.Element;
        else globalThis.Element = origElement;
    }
});

test('origin-validator rejects cross-origin, prefix/subdomain spoofs, and opaque null origins', () => {
    const trusted = 'https://portal.adasi.test';
    assert.equal(isTrustedSameOrigin('https://portal.adasi.test', trusted), true);
    assert.equal(isTrustedSameOrigin('https://evil.test', trusted), false);
    assert.equal(isTrustedSameOrigin('https://sub.portal.adasi.test', trusted), false);
    assert.equal(isTrustedSameOrigin('https://portal.adasi.test.evil.com', trusted), false);
    assert.equal(isTrustedSameOrigin('http://portal.adasi.test', trusted), false);
    assert.equal(isTrustedSameOrigin('https://portal.adasi.test:8443', trusted), false);
    assert.equal(isTrustedSameOrigin('null', 'null'), false, 'two opaque "null" origins must NEVER match');
    assert.equal(isTrustedSameOrigin('null', trusted), false);
    assert.equal(isTrustedSameOrigin('', trusted), false);

    assert.equal(isSameOriginHttpUrl('/dashboard', trusted), true);
    assert.equal(isSameOriginHttpUrl('https://portal.adasi.test/po/1', trusted), true);
    assert.equal(isSameOriginHttpUrl('https://evil.test/steal', trusted), false);
    assert.equal(isSameOriginHttpUrl('javascript:alert(1)', trusted), false);
    assert.equal(isSameOriginHttpUrl('data:text/html,<h1>x</h1>', trusted), false);
});

test('IME composition guard blocks Enter submit behaviorally (isComposing and keyCode 229) and is wired across all handlers', () => {
    // 1. Behavioral test against filter-bar.js bindLive Enter keydown listener.
    const listeners = new Map();
    const searchInput = {
        value: '',
        addEventListener(type, fn) { listeners.set(type, fn); },
    };
    const container = {
        dataset: {},
        querySelector: (sel) => (sel === '#q' ? searchInput : null),
        querySelectorAll: () => [],
        addEventListener() {},
    };
    const changeEvents = [];
    bindLive(container, {
        fields: [{ key: 'search', selector: '#q', type: 'search', label: 'Search' }],
        onFilterChange: (meta) => changeEvents.push(meta),
    });

    searchInput.value = 'にほんご';
    const keydown = listeners.get('keydown');
    assert.equal(typeof keydown, 'function', 'filter-bar must register keydown listener');

    let preventedComposing = false;
    keydown({ key: 'Enter', isComposing: true, keyCode: 13, preventDefault() { preventedComposing = true; } });
    assert.equal(changeEvents.length, 0, 'IME isComposing:true Enter must NOT trigger submit');
    assert.equal(preventedComposing, false, 'IME isComposing:true Enter must NOT call preventDefault in filter-bar');

    let preventedSafari229 = false;
    keydown({ key: 'Enter', isComposing: false, keyCode: 229, preventDefault() { preventedSafari229 = true; } });
    assert.equal(changeEvents.length, 0, 'Safari keyCode:229 Enter must NOT trigger submit');
    assert.equal(preventedSafari229, false, 'Safari keyCode:229 Enter must NOT call preventDefault in filter-bar');

    let preventedRealEnter = false;
    keydown({ key: 'Enter', isComposing: false, keyCode: 13, preventDefault() { preventedRealEnter = true; } });
    assert.equal(changeEvents.length, 1, 'Confirmed non-IME Enter MUST trigger submit');
    assert.equal(preventedRealEnter, true);

    // 2. Coverage across all 8 Enter-handling files.
    const guardedFiles = [
        'resources/js/calendar.js',
        'resources/js/exchange-rates.js',
        'resources/js/export-column-designer.js',
        'resources/js/filter-bar.js',
        'resources/js/server-tabs.js',
        'resources/js/time-picker.js',
        'resources/views/conversations/show.blade.php',
        'resources/views/partials/chat-drawer.blade.php',
    ];
    for (const relPath of guardedFiles) {
        const source = readRepo(relPath);
        assert.match(
            source,
            /isComposing\s*\|\|\s*[a-zA-Z_$][\w$]*\.keyCode\s*===\s*229/,
            `${relPath} must guard Enter keydown handlers with isComposing || keyCode === 229`
        );
    }
});

test('overlay-modality inerts nested #mainWrapper > #main-content siblings, prevents stacked leaks, tracks native dialogs, and restores focus', () => {
    const makeNode = (id, { inert = false, classes = [], connected = true } = {}) => {
        const classSet = new Set(classes);
        const node = {
            id,
            tagName: 'DIV',
            inert,
            isConnected: connected,
            parentElement: null,
            children: [],
            focused: false,
            classList: {
                add: (c) => classSet.add(c),
                remove: (c) => classSet.delete(c),
                contains: (c) => classSet.has(c),
            },
            append(...kids) {
                for (const k of kids) {
                    k.parentElement = node;
                    node.children.push(k);
                }
            },
            focus() { this.focused = true; },
        };
        return node;
    };

    // Realistic portal DOM hierarchy: <x-ui.dialog> lives inside #mainWrapper > #main-content!
    const body = makeNode('body');
    const closedSidebar = makeNode('sidebar', { inert: true });
    const mainWrapper = makeNode('mainWrapper');
    const topNavbar = makeNode('topNavbar');
    const mainContent = makeNode('main-content');
    const pageContent = makeNode('pageContent');
    const nestedDialog = makeNode('nestedDialog');
    const triggerNested = makeNode('triggerNested');
    const lightbox = makeNode('adasiImageLightbox');
    const triggerLightbox = makeNode('triggerLightbox', { connected: false });
    const toastRegion = makeNode('toastRegion', { classes: ['adasi-toast-region'] });
    const toastAnnouncer = makeNode('adasi-toast-announcer');

    mainContent.append(pageContent, nestedDialog);
    mainWrapper.append(topNavbar, mainContent);
    body.append(closedSidebar, mainWrapper, lightbox, toastRegion, toastAnnouncer);

    const dispatched = [];
    const docListeners = new Map();
    const doc = {
        body,
        activeElement: triggerNested,
        addEventListener(type, fn) { docListeners.set(type, fn); },
        defaultView: {
            dispatchEvent(evt) { dispatched.push(evt.detail?.activeCount); },
        },
    };
    resetOverlayModality(doc);
    bindNativeDialogModality(doc);

    // 1. Open nested <x-ui.dialog> inside #mainWrapper > #main-content.
    acquireOverlayModality(nestedDialog, { triggerEl: triggerNested, doc });
    assert.equal(getActiveOverlayCount(doc), 1);
    assert.equal(nestedDialog.inert, false);
    assert.equal(mainContent.inert, false, 'dialog ancestor #main-content stays interactive');
    assert.equal(mainWrapper.inert, false, 'dialog ancestor #mainWrapper stays interactive');
    assert.equal(pageContent.inert, true, 'background sibling inside #main-content MUST be inert');
    assert.equal(topNavbar.inert, true, 'background sibling .top-navbar MUST be inert');
    assert.equal(lightbox.inert, true, 'inactive body-level overlay becomes inert');
    assert.equal(closedSidebar.inert, true);
    assert.equal(toastRegion.inert, false, 'toast region stays un-inerted');
    assert.equal(toastAnnouncer.inert, false, 'toast announcer stays un-inerted');
    assert.equal(body.classList.contains('ui-dialog-open'), true);

    // 2. Stack body-level lightbox above nestedDialog -> #mainWrapper becomes inert.
    acquireOverlayModality(lightbox, { triggerEl: triggerLightbox, doc });
    assert.equal(getActiveOverlayCount(doc), 2);
    assert.equal(lightbox.inert, false);
    assert.equal(mainWrapper.inert, true, '#mainWrapper becomes inert under body-level lightbox');

    // 3. Re-promote nestedDialog above lightbox -> pageContent & topNavbar MUST stay inert (no background leak!).
    acquireOverlayModality(nestedDialog, { triggerEl: triggerNested, doc });
    assert.equal(getActiveOverlayCount(doc), 2);
    assert.equal(mainWrapper.inert, false);
    assert.equal(pageContent.inert, true, 'pageContent must NOT leak un-inerted when nestedDialog stacks over lightbox');
    assert.equal(topNavbar.inert, true, 'topNavbar must NOT leak un-inerted when nestedDialog stacks over lightbox');
    assert.equal(lightbox.inert, true, 'underlying lightbox becomes inert');

    // 4. Close nestedDialog -> lightbox returns to top, #mainWrapper re-inerts, focus restores to triggerNested.
    releaseOverlayModality(nestedDialog, { restoreFocus: true, doc });
    assert.equal(getActiveOverlayCount(doc), 1);
    assert.equal(lightbox.inert, false);
    assert.equal(mainWrapper.inert, true);
    assert.equal(triggerNested.focused, true);

    // 5. Close lightbox (detached trigger must not be focused) -> all baselines restored, closedSidebar stays true.
    releaseOverlayModality(lightbox, { restoreFocus: true, doc });
    assert.equal(getActiveOverlayCount(doc), 0);
    assert.equal(triggerLightbox.focused, false);
    assert.equal(mainWrapper.inert, false);
    assert.equal(topNavbar.inert, false);
    assert.equal(pageContent.inert, false);
    assert.equal(closedSidebar.inert, true, 'preexisting inert on closed sidebar MUST be preserved');
    assert.equal(body.classList.contains('ui-dialog-open'), false);

    // 6. Native <dialog> lifecycle increments/decrements activeCount without mutating inert.
    const nativeDialog = makeNode('nativeDialog');
    docListeners.get('adasi:modal-show')({ target: nativeDialog });
    assert.equal(getActiveOverlayCount(doc), 1);
    assert.equal(mainWrapper.inert, false, 'Native dialog tracking must not conflict with Top-Layer inert handling');
    docListeners.get('adasi:modal-hidden')({ target: nativeDialog });
    assert.equal(getActiveOverlayCount(doc), 0);
    assert.equal(dispatched.at(-1), 0);
});

test('yieldToMain prefers scheduler.yield(), falls back to setTimeout, and is wired into advanced-export.js', async () => {
    const advancedExport = readRepo('resources/js/advanced-export.js');
    assert.match(advancedExport, /import\s*\{\s*yieldToMain\s*\}\s*from\s*'\.\/task-yield\.js'/);
    assert.match(advancedExport, /await\s+yieldToMain\(\)/);

    const originalScheduler = globalThis.scheduler;
    try {
        let yielded = 0;
        globalThis.scheduler = {
            yield: async () => { yielded += 1; },
        };
        await yieldToMain();
        assert.equal(yielded, 1, 'should call scheduler.yield() when supported');

        delete globalThis.scheduler;
        await yieldToMain();
    } finally {
        if (originalScheduler === undefined) delete globalThis.scheduler;
        else globalThis.scheduler = originalScheduler;
    }
});

test('shared layouts, auth forms, toast announcers, progress bars, lightbox, and tables preserve Modern Web Guidance contracts', () => {
    for (const layout of ['resources/views/layouts/app.blade.php', 'resources/views/layouts/auth.blade.php', 'resources/views/layouts/guest.blade.php']) {
        const html = readRepo(layout);
        assert.match(html, /<meta\s+name="text-scale"\s+content="scale"/, `${layout} must declare text-scale meta`);
        assert.match(html, /<meta\s+name="color-scheme"\s+content="light dark"/, `${layout} must declare color-scheme meta`);
        assert.match(html, /ui-skip-link/, `${layout} must render skip link`);
        assert.match(html, /<main[^>]*id="main-content"[^>]*tabindex="-1"/, `${layout} must render focusable #main-content landmark`);
    }

    const loginBlade = readRepo('resources/views/auth/login.blade.php');
    assert.equal(/\saria-describedby="email-error"/.test(loginBlade), false, 'login.blade.php must not statically reference hidden #email-error in aria-describedby');
    assert.equal(/\saria-describedby="password-error"/.test(loginBlade), false, 'login.blade.php must not statically reference hidden #password-error in aria-describedby');

    for (const authView of ['resources/views/auth/login.blade.php', 'resources/views/auth/supplier-register.blade.php']) {
        const html = readRepo(authView);
        assert.match(html, /autocomplete="username"/, `${authView} must use autocomplete="username"`);
        assert.match(html, /spellcheck="false"/, `${authView} must disable spellcheck on identifier fields`);
    }

    // Pre-mounted toast live regions & suppression during modals.
    const toastBlade = readRepo('resources/views/components/ui/toast-container.blade.php');
    const appJs = readRepo('resources/js/app.js');
    const authJs = readRepo('resources/js/auth.js');
    const toastCenterJs = readRepo('resources/js/toast-center.js');
    assert.match(toastBlade, /id="adasi-toast-announcer"[^>]*aria-live="polite"/);
    assert.match(toastBlade, /id="adasi-toast-announcer-assertive"[^>]*aria-live="assertive"/);
    assert.match(appJs, /registerToastCenter/);
    assert.match(authJs, /registerToastCenter/);
    assert.match(toastCenterJs, /deferredOverlayToasts/);
    assert.match(toastCenterJs, /flushDeferredOverlayToasts/);
    assert.match(toastCenterJs, /lastFocusBeforeToast/);

    // Native <progress> elements must NEVER contain inner fallback text.
    const progressViews = [
        'resources/views/components/ui/supplier-scorecard.blade.php',
        'resources/views/local-supplier/supplier-audits/edit.blade.php',
        'resources/views/local-supplier/supplier-audits/index.blade.php',
        'resources/views/purchasing/po/show.blade.php',
        'resources/views/purchasing/supplier-audits/show.blade.php',
    ];
    for (const relPath of progressViews) {
        const html = readRepo(relPath);
        const matches = [...html.matchAll(/<progress\b(?:[^>"']|"[^"]*"|'[^']*')*>([\s\S]*?)<\/progress>/g)];
        assert.ok(matches.length > 0, `${relPath} must contain a native <progress> element`);
        for (const match of matches) {
            assert.equal(match[1].trim(), '', `${relPath} <progress> must not contain fallback inner text`);
        }
    }

    const lightbox = readRepo('resources/views/components/ui/image-lightbox.blade.php');
    assert.match(lightbox, /aria-labelledby="adasiImageLightboxTitle"/);
    assert.match(lightbox, /id="adasiImageLightboxDownload"[\s\S]*?aria-label=/);

    const dataTable = readRepo('resources/views/components/ui/data-table.blade.php');
    assert.match(dataTable, /ui-data-table__scroll[\s\S]*role="region"[^>]*tabindex="0"/);

    const adminUsers = readRepo('resources/views/admin/users/index.blade.php');
    assert.equal(adminUsers.includes('ui-data-table__scroll'), false, 'admin/users/index.blade.php must not nest duplicate .ui-data-table__scroll inside <x-ui.data-table>');

    const drpSupplier = readRepo('resources/views/finance/drp/supplier.blade.php');
    assert.equal(drpSupplier.includes('aria-sort="none"') || drpSupplier.includes(": 'none'"), false, 'sortable headers must omit aria-sort on unsorted columns');

    // Form control components must gate $errorId in aria-describedby on actual error presence (never point at hidden empty error nodes).
    for (const controlBlade of [
        'resources/views/components/ui/date-picker.blade.php',
        'resources/views/components/ui/date-range-picker.blade.php',
        'resources/views/components/ui/time-picker.blade.php',
    ]) {
        const html = readRepo(controlBlade);
        assert.match(html, /\$message\s*\?\s*\$errorId\s*:\s*null/, `${controlBlade} must gate $errorId in aria-describedby on $message`);
    }
    const fileUploadBlade = readRepo('resources/views/components/ui/file-upload.blade.php');
    assert.match(fileUploadBlade, /\$baseDescriptionIds/, 'file-upload.blade.php must separate non-error aria-describedby IDs from $errorId');
    assert.match(fileUploadBlade, /\$descriptionIdsWithError/, 'file-upload.blade.php must bind $errorId in aria-describedby only when an error is present');
});

test('Blade views satisfy Phase 4 UI calibration guardrails (no slot footers on data-table, no nested responsive scroll, no broken utilities or unbundled weights)', () => {
    const walkBlades = (dir) => {
        let out = [];
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const full = resolve(dir, entry.name);
            if (entry.isDirectory()) out = out.concat(walkBlades(full));
            else if (entry.name.endsWith('.blade.php')) out.push(full);
        }
        return out;
    };

    const viewsRoot = resolve(root, 'resources/views');
    const blades = walkBlades(viewsRoot).filter((full) => {
        const rel = relative(root, full).split(sep).join('/');
        return rel !== 'resources/views/welcome.blade.php' && !rel.startsWith('resources/views/pdf/');
    });

    const violations = [];
    for (const full of blades) {
        const rel = relative(root, full).split(sep).join('/');
        const src = readFileSync(full, 'utf8');

        if (src.includes('tw-shadow-')) violations.push(`${rel}: broken tw-shadow-* utility`);
        if (src.includes('tw:text-ui-md')) violations.push(`${rel}: broken tw:text-ui-md utility`);
        if (src.includes('tw:bg-surface-variant')) violations.push(`${rel}: nonexistent tw:bg-surface-variant utility`);
        if (src.includes('tw:dark:')) violations.push(`${rel}: raw tw:dark:* override instead of semantic token`);
        if (/\btw:(?:[a-z0-9-]+:)*text-\[1[01]px\]/.test(src)) violations.push(`${rel}: micro pixel font-size tw:text-[10px|11px]`);
        if (/\btw:(?:[a-z0-9-]+:)*font-medium\b/.test(src)) violations.push(`${rel}: unbundled Source Sans Pro weight tw:font-medium`);

        const dataTables = src.match(/<x-ui\.data-table[\s\S]*?<\/x-ui\.data-table>/g) ?? [];
        for (const block of dataTables) {
            if (block.includes('<x-slot:footer')) violations.push(`${rel}: <x-slot:footer> inside <x-ui.data-table> (use <x-slot:pagination>)`);
            if (block.includes('ui-table-responsive')) violations.push(`${rel}: nested .ui-table-responsive inside <x-ui.data-table>`);
        }

        if (src.includes('grid-cols-12')) {
            for (const match of src.matchAll(/<(div|section|aside|article|main|form|ul|dl|dd)\b[^>]*\bclass="([^"]*)"[^>]*>/g)) {
                const cls = match[2];
                if (/\btw:(?:[a-z]+:)?col-span-(?:[1-9]|1[0-2])\b/.test(cls) && !/\btw:min-w-0\b/.test(cls)) {
                    violations.push(`${rel}: grid-cols-12 col-span-* missing tw:min-w-0`);
                }
            }
        }
    }

    assert.deepEqual(violations, [], `UI calibration Blade guardrail violations:\n${violations.join('\n')}`);
});

test('dialog and drawer components bind AdasiOverlayModality to $root, expose data-ui-* markers, and release on destroy', () => {
    const dialogBlade = readRepo('resources/views/components/ui/dialog.blade.php');
    assert.match(dialogBlade, /data-ui-dialog="/, 'dialog root must expose data-ui-dialog marker');
    assert.match(dialogBlade, /AdasiOverlayModality\??\.acquire\(this\.\$root\b/, 'openDialog must acquire this.$root (not this.$el)');
    assert.match(dialogBlade, /AdasiOverlayModality\.release\(this\.\$root\b/, 'closeDialog must release this.$root (not child this.$el)');
    assert.match(dialogBlade, /x-init="if \(open\)[^"]*window\.AdasiOverlayModality\??\.acquire\(\$root\)/, 'dialog x-init must acquire $root');
    assert.match(dialogBlade, /destroy\s*\(\)\s*\{[\s\S]*?AdasiOverlayModality\?\.release\(this\.\$root,\s*\{\s*restoreFocus:\s*false\s*\}\)/, 'dialog must release modality in Alpine destroy()');
    assert.equal(/AdasiOverlayModality\??\.(?:acquire|release)\(\s*this\.\$el\b/.test(dialogBlade), false, 'dialog must never pass this.$el to AdasiOverlayModality');

    const drawerBlade = readRepo('resources/views/components/ui/drawer.blade.php');
    assert.match(drawerBlade, /data-ui-drawer="/, 'drawer root must expose data-ui-drawer marker');
    assert.match(drawerBlade, /AdasiOverlayModality\??\.acquire\(this\.\$root\b/, 'openDrawer must acquire this.$root (not this.$el)');
    assert.match(drawerBlade, /AdasiOverlayModality\.release\(this\.\$root\b/, 'closeDrawer must release this.$root (not child this.$el)');
    assert.match(drawerBlade, /x-init="if \(open\)[^"]*window\.AdasiOverlayModality\??\.acquire\(\$root\)/, 'drawer x-init must acquire $root');
    assert.match(drawerBlade, /destroy\s*\(\)\s*\{[\s\S]*?AdasiOverlayModality\?\.release\(this\.\$root,\s*\{\s*restoreFocus:\s*false\s*\}\)/, 'drawer must release modality in Alpine destroy()');
    assert.equal(/AdasiOverlayModality\??\.(?:acquire|release)\(\s*this\.\$el\b/.test(drawerBlade), false, 'drawer must never pass this.$el to AdasiOverlayModality');

    const lightboxBlade = readRepo('resources/views/components/ui/image-lightbox.blade.php');
    assert.match(lightboxBlade, /clearTimeout\(/, 'image-lightbox must cancel pending close timeout on rapid reopen');

    const periodsIndex = readRepo('resources/views/purchasing/periods/index.blade.php');
    assert.equal(/bootstrap\.Modal/.test(periodsIndex), false, 'periods/index.blade.php must use AdasiModal instead of bootstrap.Modal');

    const poShow = readRepo('resources/views/purchasing/po/show.blade.php');
    assert.equal(/bootstrap\.Modal/.test(poShow), false, 'po/show.blade.php must use AdasiModal instead of bootstrap.Modal');
});

test('overlay-modality exempts #adasiLoader, resolves descendant release targets, prunes disconnected overlays, and cleans up container swaps', () => {
    const makeNode = (id, { inert = false, classes = [], connected = true } = {}) => {
        const classSet = new Set(classes);
        const node = {
            id,
            tagName: 'DIV',
            inert,
            isConnected: connected,
            parentElement: null,
            children: [],
            style: {},
            focused: false,
            removed: false,
            classList: {
                add: (c) => classSet.add(c),
                remove: (c) => classSet.delete(c),
                contains: (c) => classSet.has(c),
            },
            append(...kids) {
                for (const k of kids) {
                    k.parentElement = node;
                    node.children.push(k);
                }
            },
            contains(target) {
                let cursor = target;
                while (cursor) {
                    if (cursor === node) return true;
                    cursor = cursor.parentElement;
                }
                return false;
            },
            remove() {
                this.removed = true;
                this.isConnected = false;
            },
            focus() { this.focused = true; },
        };
        return node;
    };

    const body = makeNode('body');
    const loader = makeNode('adasiLoader', { classes: ['adasi-loader-overlay'] });
    const swapContainer = makeNode('swapContainer');
    const tableSection = makeNode('tableSection');
    const dialogRoot = makeNode('dialogRoot');
    const closeBtn = makeNode('closeBtn');
    const triggerBtn = makeNode('triggerBtn');
    const header = makeNode('header');
    const nativeDialog = makeNode('nativeDialog', { classes: ['ui-native-dialog'] });
    nativeDialog.open = true;
    const popoverNode = makeNode('columnMenu');
    popoverNode.matches = (sel) => sel === ':popover-open' && !popoverHidden;
    let dialogHideCalled = false;
    let popoverHidden = false;
    popoverNode.hidePopover = () => { popoverHidden = true; };

    dialogRoot.append(closeBtn);
    swapContainer.append(tableSection, dialogRoot, nativeDialog, popoverNode);
    body.append(header, swapContainer, loader);

    const doc = {
        body,
        activeElement: triggerBtn,
        querySelectorAll() {
            return [];
        },
        defaultView: {
            dispatchEvent() {},
            AdasiModal: {
                getInstance: (el) => (el === nativeDialog ? {
                    hide() {
                        dialogHideCalled = true;
                        nativeDialog.open = false;
                    },
                } : null),
            },
        },
    };
    swapContainer.querySelectorAll = (sel) => {
        if (sel.includes('dialog')) return nativeDialog.open ? [nativeDialog] : [];
        if (sel.includes('popover')) return [popoverNode];
        return [];
    };

    resetOverlayModality(doc);

    // 1. #adasiLoader stays un-inerted while dialog is open.
    acquireOverlayModality(dialogRoot, { triggerEl: triggerBtn, doc });
    assert.equal(getActiveOverlayCount(doc), 1);
    assert.equal(tableSection.inert, true);
    assert.equal(header.inert, true);
    assert.equal(loader.inert, false, '#adasiLoader must remain non-inert so Dismiss button stays operable');

    // 2. Releasing with a child element (descendant) still resolves and releases the owning dialogRoot.
    releaseOverlayModality(closeBtn, { restoreFocus: true, doc });
    assert.equal(getActiveOverlayCount(doc), 0, 'releasing a descendant button must unwind the owning overlay');
    assert.equal(tableSection.inert, false);
    assert.equal(header.inert, false);
    assert.equal(body.classList.contains('ui-dialog-open'), false);
    assert.equal(triggerBtn.focused, true);

    // 3. Disconnected overlay pruning prevents permanent inert leak if DOM is removed without calling release().
    acquireOverlayModality(dialogRoot, { triggerEl: triggerBtn, doc });
    assert.equal(header.inert, true);
    dialogRoot.isConnected = false;
    assert.equal(getActiveOverlayCount(doc), 0, 'disconnected overlay must be pruned automatically');
    assert.equal(header.inert, false, 'pruning disconnected overlay must restore inert on surviving siblings');
    assert.equal(body.classList.contains('ui-dialog-open'), false);

    // 4. releaseOverlaysWithin(container) releases custom overlays, native dialogs, and open popovers.
    dialogRoot.isConnected = true;
    acquireOverlayModality(dialogRoot, { triggerEl: triggerBtn, doc });
    releaseOverlaysWithin(swapContainer, { doc });
    assert.equal(getActiveOverlayCount(doc), 0);
    assert.equal(header.inert, false);
    assert.equal(dialogHideCalled, true);
    assert.equal(popoverHidden, true);

    // 5. Distinct nested overlay root must NOT prematurely release its open parent overlay.
    const parentDrawer = makeNode('parentDrawer');
    parentDrawer.dataset = { uiDrawer: 'outer-drawer' };
    const childDialog = makeNode('childDialog');
    childDialog.dataset = { uiDialog: 'inner-dialog' };
    parentDrawer.append(childDialog);
    body.append(parentDrawer);
    acquireOverlayModality(parentDrawer, { triggerEl: triggerBtn, doc });
    assert.equal(getActiveOverlayCount(doc), 1);
    releaseOverlayModality(childDialog, { restoreFocus: false, doc });
    assert.equal(getActiveOverlayCount(doc), 1, 'releasing an unacquired nested dialog root must not release parent drawer');

    // 6. Blocking session-expired modal retains top priority over later lower-layer overlays.
    const sessionModal = makeNode('sessionModal');
    sessionModal.dataset = { sessionExpiredModal: '' };
    body.append(sessionModal);
    acquireOverlayModality(sessionModal, { triggerEl: triggerBtn, doc });
    acquireOverlayModality(dialogRoot, { triggerEl: triggerBtn, doc });
    assert.equal(sessionModal.inert, false, 'session-expired modal must stay interactive when lower overlay acquires');
    assert.equal(swapContainer.inert, true, 'container holding lower overlay must stay inert behind active session-expired modal');
    resetOverlayModality(doc);
});

test('heading, modal title, table header, and shell heading typography hierarchy stays readable and never clips', () => {
    const appCss = readRepo('resources/css/app.css');
    const authCss = readRepo('resources/css/auth.css');
    const workspaceCss = readRepo('resources/css/workspace-shell.css');

    for (const [label, css] of [['app.css', appCss], ['auth.css', authCss]]) {
        assert.match(css, /h5,\s*h6\s*\{\s*font-size:\s*var\(--ui-font-size-sm\);/, `${label} must set h5/h6 baseline to at least sm`);
    }

    assert.match(appCss, /\.modal-title\s*\{[^}]*font-size:\s*var\(--ui-font-size-lg\);/s);
    assert.match(appCss, /\.ui-section__title,\s*\.ui-form-section__title\s*\{[^}]*font-size:\s*0\.9375rem;/s);
    assert.match(appCss, /\.ui-table\s*>\s*thead\s*>\s*tr\s*>\s*th\s*\{[^}]*font-size:\s*0\.8125rem;[^}]*min-height:\s*var\(--ui-density-table-head-min/s);
    assert.match(appCss, /\.ui-data-table table thead th\s*\{[^}]*font-size:\s*0\.8125rem;[^}]*min-height:\s*var\(--ui-density-table-head-min/s);
    assert.match(appCss, /\.ui-table--sm\s*>\s*thead\s*>\s*tr\s*>\s*th\s*\{[^}]*font-size:\s*0\.8125rem;/s);
    assert.match(appCss, /\.sidebar-heading\s*\{[^}]*font-size:\s*0\.8125rem;/s);
    assert.match(appCss, /\.notification-menu-heading\s*\{[^}]*font-size:\s*0\.8125rem;/s);
    assert.match(workspaceCss, /\.workspace-session-menu__heading\s*\{[^}]*font-size:\s*0\.8125rem;/s);

    const formSection = readRepo('resources/views/components/ui/form-section.blade.php');
    assert.equal(formSection.includes('tw:text-xs'), false, 'x-ui.form-section title must not override .ui-form-section__title down to xs');

    const walkBlades = (dir) => {
        let out = [];
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const full = resolve(dir, entry.name);
            if (entry.isDirectory()) out = out.concat(walkBlades(full));
            else if (entry.name.endsWith('.blade.php')) out.push(full);
        }
        return out;
    };

    const viewsRoot = resolve(root, 'resources/views');
    const blades = walkBlades(viewsRoot).filter((full) => {
        const rel = relative(root, full).split(sep).join('/');
        return rel !== 'resources/views/welcome.blade.php' && !rel.startsWith('resources/views/pdf/');
    });

    const violations = [];
    for (const full of blades) {
        const rel = relative(root, full).split(sep).join('/');
        const src = readFileSync(full, 'utf8');

        for (const m of src.matchAll(/<h[1-6]\b(?:[^>"']|"[^"]*"|'[^']*'|->|=>)*>/g)) {
            if (/\btw:(?:[a-z0-9-]+:)*text-(?:ui-)?2?xs\b/.test(m[0])) {
                violations.push(`${rel}: undersized heading ${m[0].slice(0, 80)}`);
            }
            if (/\bmodal-title\b/.test(m[0]) && !/\btw:(?:[a-z0-9-]+:)*text-(?:ui-)?(?:base|lg|xl|2xl)\b/.test(m[0])) {
                violations.push(`${rel}: modal-title must declare base/lg/xl text utility (${m[0].slice(0, 80)})`);
            }
        }

        for (const m of src.matchAll(/<th\b(?:[^>"']|"[^"]*"|'[^']*'|->|=>)*>/g)) {
            if (/\btw:(?:[a-z0-9-]+:)*text-(?:ui-)?2?xs\b/.test(m[0]) && !/\bcol-supplier-cell\b/.test(m[0])) {
                violations.push(`${rel}: standard <th> overrides size down to xs (${m[0].slice(0, 80)})`);
            }
        }
    }

    assert.deepEqual(violations, [], `Typography hierarchy violations:\n${violations.join('\n')}`);
});
