/**
 * Read-only rendered measurement collector for known local ADASI QA pages.
 * Returns geometry/style metadata only. No form submission, preference change,
 * storage access, business text, credentials, or network request.
 */
(() => {
    const local = ['127.0.0.1', 'localhost', 'adasi_portal_supplier.test'].includes(location.hostname);
    const allowedPath = /^\/(?:__density-components(?:-public)?|profile\/customization|(?:admin|purchasing|finance|accounting|qc|ga|supplier|local-supplier)\/(?:dashboard|users|requisitions|invoices|claims|employees|inspections|quotations|purchase-orders|local-procurement))(?:\/|$)/;
    if (!local || !allowedPath.test(location.pathname)) throw new Error('Open a known local ADASI measurement page first.');

    const properties = ['display', 'box-sizing', 'font-family', 'font-size', 'line-height', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-bottom', 'gap', 'row-gap', 'column-gap', 'height', 'min-height', 'max-height', 'width', 'overflow-x', 'overflow-y'];
    const rect = (element) => {
        const box = element.getBoundingClientRect();
        return Object.fromEntries(['x', 'y', 'width', 'height', 'top', 'right', 'bottom', 'left'].map(key => [key, Math.round(box[key] * 1000) / 1000]));
    };
    const sheets = [];
    const inaccessibleSheets = [];
    const walk = (rules, source, conditions = []) => {
        for (const rule of rules) {
            if (rule.media && !matchMedia(rule.conditionText).matches) continue;
            if (rule.constructor.name === 'CSSSupportsRule' && !CSS.supports(rule.conditionText)) continue;
            if (rule.selectorText && rule.style) sheets.push({ rule, source, conditions });
            if (rule.cssRules) walk(rule.cssRules, source, [...conditions, rule.conditionText || rule.name || rule.constructor.name]);
        }
    };
    for (const sheet of document.styleSheets) {
        const source = sheet.href ? new URL(sheet.href).origin + new URL(sheet.href).pathname : 'inline';
        try { walk(sheet.cssRules, source); }
        catch { inaccessibleSheets.push(source); }
    }
    const measure = (element) => {
        if (!element) return null;
        const style = getComputedStyle(element);
        const declarations = sheets.flatMap(({ rule, source, conditions }) => {
            try { if (!element.matches(rule.selectorText)) return []; }
            catch { return []; }
            const values = {};
            for (const property of ['padding', 'padding-block', 'padding-inline', 'padding-top', 'padding-bottom', 'height', 'min-height', 'gap', 'margin-bottom']) {
                const value = rule.style.getPropertyValue(property);
                if (value) values[property] = { value, important: rule.style.getPropertyPriority(property) === 'important' };
            }
            return Object.keys(values).length ? [{ source, selector: rule.selectorText, conditions, values }] : [];
        });
        return { rect: rect(element), computed: Object.fromEntries(properties.map(key => [key, style.getPropertyValue(key)])), matchedDeclarations: declarations };
    };
    const targets = {
        page: '#main-content', sidebar: '.sidebar-menu .sidebar-link',
        metric: '#fixture-metric, .content-area .ui-metric-card',
        card: '#fixture-card, .content-area .ui-card',
        cardBody: '#fixture-card > div:last-child, .content-area .ui-card__body',
        toolbar: '#fixture-toolbar, .content-area .ui-toolbar',
        formSection: '#fixture-form, .content-area .ui-form-section',
        formBody: '#fixture-form > div:last-child, .content-area .ui-form-section__body',
        textTable: '#fixture-text-table', actionTable: '#fixture-action-table',
        textCell: '#fixture-text-table tbody tr:first-child td:first-child',
        actionCell: '#fixture-action-table tbody tr:first-child td:last-child',
        actionButton: '#fixture-action-table tbody tr:first-child button',
        explicitCompactCell: '#fixture-explicit tbody td',
        densityControl: '[data-density-slider], input[name="density"]',
        densityFieldset: '#density-fieldset, #appearance fieldset:nth-child(2)',
        actions: '.ui-action-bar',
        save: '#customizationForm button[type="submit"]',
    };
    const tables = {};
    for (const kind of ['text', 'action']) {
        const table = document.querySelector(`#fixture-${kind}-table`);
        if (!table) continue;
        const rows = [...table.querySelectorAll('tbody tr')];
        const heights = rows.map(row => rect(row).height);
        tables[kind] = { rows: rows.length, rowHeights: heights, bodyHeight: table.tBodies[0]?.getBoundingClientRect().height, fullRowsIn480: Math.floor(480 / heights[0]), rect: rect(table) };
    }
    const root = document.documentElement;
    const canonical = document.querySelector('input[name="density"]:checked, select[name="density"]');
    return {
        version: 1, timestamp: new Date().toISOString(),
        page: location.origin + location.pathname,
        browser: navigator.userAgent,
        viewport: { width: innerWidth, height: innerHeight, dpr: devicePixelRatio, visualScale: visualViewport?.scale ?? null },
        fonts: { status: document.fonts.status, interReady: document.fonts.check('14px Inter'), rootSize: getComputedStyle(root).fontSize },
        state: { density: root.dataset.density ?? null, savedDensity: window.AdasiPreferences?.density ?? null, canonicalDensity: canonical?.value ?? null, theme: root.dataset.theme ?? null, locale: root.lang },
        pageSize: { scrollHeight: root.scrollHeight, clientHeight: root.clientHeight, scrollWidth: root.scrollWidth },
        targets: Object.fromEntries(Object.entries(targets).map(([key, selector]) => [key, measure(document.querySelector(selector))])),
        tables, inaccessibleSheets,
        note: 'Matched declarations are candidates; confirm winning declarations in DevTools Styles/CDP. No business field values were collected.',
    };
})();
