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
