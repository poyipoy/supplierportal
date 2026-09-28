export function resolveEffectiveTheme(choice, prefersDark) {
    return choice === 'system' ? (prefersDark ? 'dark' : 'light') : choice;
}

export function bindCustomizationPreviews(documentRef, preferences) {
    documentRef.querySelectorAll('input[name="theme"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) preferences.previewTheme(input.value);
        });
    });

    documentRef.querySelectorAll('input[name="density"]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) preferences.previewDensity(input.value);
        });
    });
}

export function bindBackForwardRestoration(windowRef, preferences) {
    windowRef.addEventListener('pageshow', (event) => {
        if (event.persisted) preferences.restoreSaved();
    });
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.AdasiPreferences) {
    const preferences = window.AdasiPreferences;
    const setupPreview = () => bindCustomizationPreviews(document, preferences);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupPreview, { once: true });
    } else {
        setupPreview();
    }

    bindBackForwardRestoration(window, preferences);
}
