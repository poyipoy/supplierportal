/**
 * Keeps the Customization wireframe (a miniature of the dashboard grid) in step with its form controls.
 * The tile order and each panel's hide checkbox are the source of truth; widths are derived from them with
 * the same smart-span rule the dashboard itself uses.
 */
import { TILE_SPAN_CLASSES, computeEffectiveSpans, tileSpanClass } from './dashboard-layout-core.js';

const TILE_SPAN_CLASS_LIST = Object.values(TILE_SPAN_CLASSES);
const HIDDEN_TILE_CLASSES = ['tw:border-dashed', 'tw:opacity-70'];

export function syncWireframe(list) {
    if (!list || typeof list.querySelectorAll !== 'function') return;

    const tiles = Array.from(list.querySelectorAll('[data-dashboard-choice]'));
    const items = tiles.map((tile) => ({
        key: tile.dataset.widgetKey,
        span: Number(tile.dataset.widgetSpan),
        visible: !tile.querySelector('input[name="dashboard[hidden][]"]')?.checked,
    }));
    const spans = computeEffectiveSpans(items);

    tiles.forEach((tile, index) => {
        const item = items[index];
        TILE_SPAN_CLASS_LIST.forEach((name) => tile.classList.remove(name));
        // A hidden panel keeps its natural width so its tile still reads as that panel.
        tile.classList.add(tileSpanClass(item.visible ? spans[item.key] : item.span));
        HIDDEN_TILE_CLASSES.forEach((name) => tile.classList.toggle(name, !item.visible));
    });
}
