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

export async function runExport({
    form,
    url,
    fallbackUrl = '',
    csrfToken = '',
    formDataFactory,
    fetchImpl,
    notify,
    download,
    fallbackDownload,
    messages = {},
}) {
    const body = exportEntries(formDataFactory(form));
    const triggerFallback = () => {
        if (!fallbackUrl || typeof fallbackDownload !== 'function') return false;
        fallbackDownload(fallbackUrl);

        return true;
    };
    let response;
    try {
        response = await fetchImpl(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
        });
    } catch {
        const fallback = triggerFallback();
        notify('warning', messages.failed || 'Could not include your current form entries. A blank template was downloaded instead.');

        return { ok: false, fallback, errors: [] };
    }

    if (response.ok) {
        download(await response.blob(), fileNameFrom(response.headers.get('Content-Disposition'), messages.fallbackName || 'template_import_quotation.xlsx'));
        if (messages.done) notify('success', messages.done);

        return { ok: true, fallback: false, errors: [] };
    }

    let payload = {};
    try {
        payload = await response.json();
    } catch {
        payload = {};
    }
    const fallback = triggerFallback();
    const errors = response.status === 422 ? errorMessages(payload.errors) : [];
    const invalidWarning = messages.invalid || 'Some quotation entries are invalid. A blank template was downloaded without your entered data.';
    const failedWarning = messages.failed || 'Could not include your current form entries. A blank template was downloaded instead.';
    const summary = response.status === 422
        ? (errors.length ? `${invalidWarning} ${errors.join(' ')}`.trim() : invalidWarning)
        : (payload.message ? `${failedWarning} ${payload.message}`.trim() : failedWarning);
    notify('warning', summary);

    return { ok: false, fallback, errors };
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

function saveFallbackUrl(url) {
    if (!url || typeof document === 'undefined' || !document.body) return;
    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    link.remove();
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
                    fallbackUrl: button.dataset.fallbackUrl || button.getAttribute('href') || '',
                    csrfToken: form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '',
                    formDataFactory: (target) => new FormData(target),
                    fetchImpl: (url, init) => fetch(url, init),
                    notify: toast,
                    download: saveBlob,
                    fallbackDownload: saveFallbackUrl,
                    messages: {
                        fallbackName: 'template_import_quotation.xlsx',
                        invalid: translate('supplier.js.export_form_invalid', 'Some quotation entries are invalid. A blank template was downloaded without your entered data.'),
                        failed: translate('supplier.js.export_form_failed', 'Could not include your current form entries. A blank template was downloaded instead.'),
                        done: translate('supplier.js.export_form_done', 'Quotation template downloaded with your current entries.'),
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
