/**
 * GEN-03 Mode 1: Export Form. Posts the entered quotation form (no files, no
 * save action) and downloads the XLSX. The form is never submitted, reset or
 * edited, so a 422 leaves every entered value and the local draft in place.
 */

const MAX_MESSAGES = 5;

export function exportEntries(formData) {
    const entries = new FormData();
    for (const [key, value] of formData.entries()) {
        if (key === 'action' || (typeof File !== 'undefined' && value instanceof File)) continue;
        entries.append(key, value);
    }

    return entries;
}

export function errorMessages(errors) {
    const messages = [];
    for (const list of Object.values(errors || {})) {
        for (const message of Array.isArray(list) ? list : [list]) {
            if (typeof message === 'string' && message !== '' && !messages.includes(message)) messages.push(message);
        }
    }

    return messages.slice(0, MAX_MESSAGES);
}

export function fileNameFrom(disposition, fallback) {
    const header = String(disposition || '');
    const encoded = header.match(/filename\*=UTF-8''([^;]+)/i);
    if (encoded) {
        try {
            return decodeURIComponent(encoded[1].trim());
        } catch {
            // Fall through to the plain filename.
        }
    }
    const plain = header.match(/filename="?([^";]+)"?/i);

    return plain ? plain[1].trim() : fallback;
}

export async function runExport({ form, url, csrfToken = '', formDataFactory, fetchImpl, notify, download, messages = {} }) {
    const body = exportEntries(formDataFactory(form));
    let response;
    try {
        response = await fetchImpl(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
        });
    } catch {
        notify('error', messages.failed || 'Export failed.');

        return { ok: false, errors: [] };
    }

    if (response.ok) {
        download(await response.blob(), fileNameFrom(response.headers.get('Content-Disposition'), messages.fallbackName || 'quotation_form.xlsx'));
        if (messages.done) notify('success', messages.done);

        return { ok: true, errors: [] };
    }

    let payload = {};
    try {
        payload = await response.json();
    } catch {
        payload = {};
    }
    const errors = response.status === 422 ? errorMessages(payload.errors) : [];
    const summary = errors.length ? `${messages.invalid || payload.message || ''} ${errors.join(' ')}`.trim() : (payload.message || messages.failed || 'Export failed.');
    notify('error', summary);

    return { ok: false, errors };
}

function saveBlob(blob, name) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

function toast(type, message) {
    const api = window.AdasiToast;
    if (api && typeof api[type] === 'function') api[type](message);
    else if (api && typeof api.show === 'function') api.show({ type, message });
}

function translate(key, fallback) {
    const value = window.AdasiI18n?.t?.(key);

    return value && value !== key ? value : fallback;
}

export function bootQuotationFormExport(root = document) {
    root.querySelectorAll('[data-quotation-form-export]').forEach((button) => {
        if (button.dataset.quotationFormExportBound === '1') return;
        button.dataset.quotationFormExportBound = '1';
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const form = document.querySelector(button.dataset.form || '#quotationForm');
            if (!form || button.getAttribute('aria-busy') === 'true') return;
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-disabled', 'true');
            try {
                await runExport({
                    form,
                    url: button.dataset.url,
                    csrfToken: form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '',
                    formDataFactory: (target) => new FormData(target),
                    fetchImpl: (url, init) => fetch(url, init),
                    notify: toast,
                    download: saveBlob,
                    messages: {
                        invalid: translate('supplier.js.export_form_invalid', 'Fix these entries before exporting:'),
                        failed: translate('supplier.js.export_form_failed', 'The form could not be exported. Try again.'),
                        done: translate('supplier.js.export_form_done', 'Form exported.'),
                    },
                });
            } finally {
                button.removeAttribute('aria-busy');
                button.removeAttribute('aria-disabled');
            }
        });
    });
}

if (typeof document !== 'undefined') bootQuotationFormExport();
