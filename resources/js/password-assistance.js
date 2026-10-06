import { t } from './i18n.js';
export async function copyAssistanceText(text, environment = globalThis) {
    if (environment.isSecureContext && environment.navigator?.clipboard?.writeText) {
        try {
            await environment.navigator.clipboard.writeText(text);
            return true;
        } catch { /* Use the selectable fallback when clipboard permission is unavailable. */ }
    }
    const document = environment.document;
    const previous = document?.activeElement;
    let area;
    try {
        area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.focus();
        area.select();
        return document.execCommand?.('copy') === true;
    } catch {
        return false;
    } finally {
        area?.remove();
        previous?.focus?.();
    }
}

export function bootPasswordAssistance(root = document, environment = globalThis) {
    root.querySelectorAll('[data-password-assistance]').forEach(container => {
        const status = container.querySelector('[data-copy-status]');
        container.querySelectorAll('[data-password-assistance-copy]').forEach(button => {
            button.addEventListener('click', async () => {
                const text = root.getElementById(button.dataset.passwordAssistanceCopy)?.textContent ?? '';
                const copied = await copyAssistanceText(text, environment);
                status.textContent = copied ? t('js.copy.success', { label: button.dataset.copyLabel })
                    : t('js.copy.failed');
            });
        });
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootPasswordAssistance());
    } else {
        bootPasswordAssistance();
    }
}
