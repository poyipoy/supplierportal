/**
 * Smart span: pure layout math shared by the dashboard editor and the Customization wireframe.
 * It must stay identical to DashboardWidgetService::computeEffectiveSpans(); both run the cases in
 * tests/Fixtures/dashboard-smart-span-cases.json.
 */

export const GRID_COLUMNS = 12;

// Natural widths a widget may declare. The smallest is 3, so a widened last card in a row
// (12 minus what precedes it) always lands inside this set too.
export const ALLOWED_SPANS = Object.freeze([3, 4, 5, 6, 7, 8, 9, 12]);

// Literal strings on purpose: the Tailwind content scanner only emits classes it can read in the
// source. Keep this map identical to the one in components/ui/dashboard-layout.blade.php.
export const SPAN_CLASSES = Object.freeze({
    3: 'lg:tw-col-span-3',
    4: 'lg:tw-col-span-4',
    5: 'lg:tw-col-span-5',
    6: 'lg:tw-col-span-6',
    7: 'lg:tw-col-span-7',
    8: 'lg:tw-col-span-8',
    9: 'lg:tw-col-span-9',
    12: 'lg:tw-col-span-12',
});

export function normalizeSpan(span) {
    return Number.isInteger(span) && ALLOWED_SPANS.includes(span) ? span : GRID_COLUMNS;
}

export function spanClass(span) {
    return SPAN_CLASSES[normalizeSpan(span)];
}

// The Customization wireframe is a miniature of the dashboard grid at every screen width, so its classes carry no
// breakpoint prefix. Keep identical to the map in profile/customization.blade.php.
export const TILE_SPAN_CLASSES = Object.freeze({
    3: 'tw-col-span-3',
    4: 'tw-col-span-4',
    5: 'tw-col-span-5',
    6: 'tw-col-span-6',
    7: 'tw-col-span-7',
    8: 'tw-col-span-8',
    9: 'tw-col-span-9',
    12: 'tw-col-span-12',
});

export function tileSpanClass(span) {
    return TILE_SPAN_CLASSES[normalizeSpan(span)];
}

/**
 * Where a dragged panel lands relative to the one under the pointer. Panels sharing a row are
 * compared on the horizontal axis, full-width ones on the vertical axis.
 */
export function dropPlacement(rect, point, containerWidth) {
    const sharesRow = rect.width > 0 && rect.width < containerWidth * 0.9;
    if (sharesRow) {
        return point.x < rect.left + rect.width / 2 ? { position: 'before', edge: 'left' } : { position: 'after', edge: 'right' };
    }

    return point.y < rect.top + rect.height / 2 ? { position: 'before', edge: 'top' } : { position: 'after', edge: 'bottom' };
}

/**
 * @param {Array<{key: string, span?: unknown, visible?: unknown}>} items widgets in display order
 * @returns {Record<string, number>} effective span per visible key
 */
export function computeEffectiveSpans(items) {
    const rows = [];
    let current = [];
    let used = 0;

    for (const item of items) {
        if (item.visible !== true) continue;

        const span = normalizeSpan(item.span);
        if (used + span > GRID_COLUMNS) {
            rows.push(current);
            current = [];
            used = 0;
        }

        current.push([item.key, span]);
        used += span;
    }

    if (current.length) rows.push(current);

    const result = {};
    for (const row of rows) {
        const total = row.reduce((sum, [, span]) => sum + span, 0);
        row.forEach(([key, span], index) => {
            result[key] = index === row.length - 1 ? span + (GRID_COLUMNS - total) : span;
        });
    }

    return result;
}
