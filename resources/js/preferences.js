import { t } from './i18n.js';
import { dropPlacement } from './dashboard-layout-core.js';

const previewInputs = new WeakSet();
const restorationTargets = new WeakSet();
const reorderLists = new WeakSet();
const dragLists = new WeakSet();

export function resolveEffectiveTheme(choice, prefersDark) {
    return choice === 'system' ? (prefersDark ? 'dark' : 'light') : choice;
}

export function bindCustomizationPreviews(documentRef, preferences) {
    for (const [name, method] of Object.entries({ theme: 'previewTheme', accent: 'previewAccent', density: 'previewDensity' })) {
        documentRef.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
            if (input.closest?.('#customizationForm') || previewInputs.has(input)) return;
            previewInputs.add(input);
            input.addEventListener('change', () => {
                if (input.checked) preferences[method](input.value);
            });
        });
    }
}

export function bindBackForwardRestoration(windowRef, preferences, documentRef = windowRef.document) {
    if (restorationTargets.has(windowRef)) return;
    restorationTargets.add(windowRef);
    windowRef.addEventListener('pageshow', (event) => {
        // Chrome can restore form fields on ordinary history navigation too.
        const historyNavigation = windowRef.performance?.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward';
        if (!event.persisted && !historyNavigation) return;
        preferences.restoreSaved();
        if (documentRef?.querySelector?.('#customizationForm')) {
            windowRef.dispatchEvent(new Event('adasi:preferences-restored'));
            return;
        }
        ['theme', 'density', 'accent'].forEach((name) => {
            documentRef?.querySelectorAll('input[name="' + name + '"]').forEach((input) => {
                input.checked = input.value === preferences[name];
            });
        });
    });
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.AdasiPreferences) {
    const preferences = window.AdasiPreferences;
    const setupPreview = () => {
        bindCustomizationPreviews(document, preferences);
        bindDashboardReorder(document);
        bindDashboardDragAndDrop(document);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupPreview, { once: true });
    } else {
        setupPreview();
    }

    bindBackForwardRestoration(window, preferences);
}

export function bindDashboardReorder(documentRef) {
    documentRef.querySelectorAll('[data-dashboard-controls]').forEach((list) => {
        if (reorderLists.has(list)) return;
        reorderLists.add(list);
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-dashboard-move]');
            if (!button) return;
            const row = button.closest('[data-dashboard-choice]');
            if (!row || !list.contains(row)) return;

            const rows = Array.from(list.querySelectorAll('[data-dashboard-choice]'));
            const index = rows.indexOf(row);
            const direction = button.dataset.dashboardMove;
            if (direction === 'up' && index > 0) {
                list.insertBefore(row, rows[index - 1]);
            } else if (direction === 'down' && index < rows.length - 1) {
                list.insertBefore(rows[index + 1], row);
            }

            const updated = Array.from(list.querySelectorAll('[data-dashboard-choice]'));
            const status = list.closest('[data-dashboard-section]')?.querySelector('[data-dashboard-status]');
            if (status) status.textContent = t('js.dashboard.position', { label: row.dataset.widgetLabel, position: updated.indexOf(row) + 1, total: updated.length });
            button.focus();
            if (updated.indexOf(row) !== index) list.dispatchEvent(new Event('adasi:dashboard-order-change', { bubbles: true }));
        });
    });
}

export function bindDashboardDragAndDrop(documentRef) {
    documentRef.querySelectorAll('[data-dashboard-controls]').forEach((list) => {
        if (dragLists.has(list)) return;
        dragLists.add(list);
        let draggedRow = null;

        const clearDragStyles = () => {
            list.querySelectorAll('[data-dashboard-choice]').forEach((item) => {
                item.classList.remove('tw-opacity-40', 'tw-border-primary', 'tw-bg-surface-container');
            });
        };

        list.addEventListener('dragstart', (event) => {
            const row = event.target.closest('[data-dashboard-choice]');
            if (!row || !list.contains(row)) return;

            draggedRow = row;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', row.dataset.widgetKey || '');
            }
            row.classList.add('tw-opacity-40');
        });

        list.addEventListener('dragover', (event) => {
            if (!draggedRow) return;
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }

            const targetRow = event.target.closest('[data-dashboard-choice]');
            if (targetRow && targetRow !== draggedRow && list.contains(targetRow)) {
                list.querySelectorAll('[data-dashboard-choice]').forEach((item) => {
                    if (item !== targetRow && item !== draggedRow) {
                        item.classList.remove('tw-border-primary', 'tw-bg-surface-container');
                    }
                });
                targetRow.classList.add('tw-border-primary', 'tw-bg-surface-container');
            }
        });

        list.addEventListener('dragleave', (event) => {
            const targetRow = event.target.closest('[data-dashboard-choice]');
            if (targetRow && event.relatedTarget && !targetRow.contains(event.relatedTarget)) {
                targetRow.classList.remove('tw-border-primary', 'tw-bg-surface-container');
            }
        });

        list.addEventListener('drop', (event) => {
            if (!draggedRow) return;
            event.preventDefault();

            const targetRow = event.target.closest('[data-dashboard-choice]');
            if (targetRow && targetRow !== draggedRow && list.contains(targetRow)) {
                const beforeIndex = Array.from(list.querySelectorAll('[data-dashboard-choice]')).indexOf(draggedRow);
                const rect = typeof targetRow.getBoundingClientRect === 'function' ? targetRow.getBoundingClientRect() : null;
                // Tiles can sit side by side in the wireframe, so the pointer is compared on both axes.
                const listWidth = typeof list.getBoundingClientRect === 'function' ? list.getBoundingClientRect().width : 0;
                const isAfter = rect && rect.height > 0 && typeof event.clientY === 'number'
                    ? dropPlacement(rect, { x: event.clientX, y: event.clientY }, listWidth).position === 'after'
                    : false;

                if (isAfter) {
                    targetRow.after(draggedRow);
                } else {
                    targetRow.before(draggedRow);
                }

                const updated = Array.from(list.querySelectorAll('[data-dashboard-choice]'));
                const status = list.closest('[data-dashboard-section]')?.querySelector('[data-dashboard-status]');
                if (status) {
                    status.textContent = t('js.dashboard.position', { label: draggedRow.dataset.widgetLabel, position: updated.indexOf(draggedRow) + 1, total: updated.length });
                }
                if (updated.indexOf(draggedRow) !== beforeIndex) list.dispatchEvent(new Event('adasi:dashboard-order-change', { bubbles: true }));
            }

            clearDragStyles();
            draggedRow = null;
        });

        list.addEventListener('dragend', () => {
            clearDragStyles();
            draggedRow = null;
        });
    });
}
