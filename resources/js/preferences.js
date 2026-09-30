export function resolveEffectiveTheme(choice, prefersDark) {
    return choice === 'system' ? (prefersDark ? 'dark' : 'light') : choice;
}

export function bindCustomizationPreviews(documentRef, preferences) {
    documentRef.querySelectorAll('input[name="theme"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) preferences.previewTheme(input.value);
        });
    });

    documentRef.querySelectorAll('input[name="accent"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) preferences.previewAccent(input.value);
        });
    });

    documentRef.querySelectorAll('input[name="density"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) preferences.previewDensity(input.value);
        });
    });
}

export function bindBackForwardRestoration(windowRef, preferences, documentRef = windowRef.document) {
    windowRef.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        preferences.restoreSaved();
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
            if (status) status.textContent = row.dataset.widgetLabel + ' is at position ' + (updated.indexOf(row) + 1) + ' of ' + updated.length + '. Save Changes to keep this order.';
            button.focus();
        });
    });
}

export function bindDashboardDragAndDrop(documentRef) {
    documentRef.querySelectorAll('[data-dashboard-controls]').forEach((list) => {
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
                const rect = typeof targetRow.getBoundingClientRect === 'function' ? targetRow.getBoundingClientRect() : null;
                const isAfter = rect && rect.height > 0 && typeof event.clientY === 'number'
                    ? (event.clientY > rect.top + rect.height / 2)
                    : false;

                if (isAfter) {
                    targetRow.after(draggedRow);
                } else {
                    targetRow.before(draggedRow);
                }

                const updated = Array.from(list.querySelectorAll('[data-dashboard-choice]'));
                const status = list.closest('[data-dashboard-section]')?.querySelector('[data-dashboard-status]');
                if (status) {
                    status.textContent = draggedRow.dataset.widgetLabel + ' is at position ' + (updated.indexOf(draggedRow) + 1) + ' of ' + updated.length + '. Save Changes to keep this order.';
                }
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
