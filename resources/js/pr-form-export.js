/**
 * PR Form Material Export (Role Purchasing):
 * Posts the entered PR form (without file uploads or form submission)
 * and downloads the populated XLSX template.
 * If the form is completely empty, it downloads the blank template directly.
 */

import { runExport } from './quotation-form-export.js';

export function hasEnteredItems(form) {
    if (!form) return false;
    const inputs = form.querySelectorAll('input[name*="[material_name]"], input[name*="[material_master_id]"], select[name*="[shape]"]');
    for (const input of inputs) {
        if (input.value && String(input.value).trim() !== '') {
            return true;
        }
    }

    return false;
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

export function bootPrFormExport(root = document) {
    root.querySelectorAll('[data-pr-form-export]').forEach((button) => {
        if (button.dataset.prFormExportBound === '1') return;
        button.dataset.prFormExportBound = '1';
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const form = document.querySelector(button.dataset.form || '#prForm');
            const fallbackUrl = button.dataset.fallbackUrl || button.getAttribute('href') || '';

            if (!form || !hasEnteredItems(form)) {
                saveFallbackUrl(fallbackUrl);
                return;
            }

            if (button.getAttribute('aria-busy') === 'true') return;
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-disabled', 'true');

            try {
                await runExport({
                    form,
                    url: button.dataset.url,
                    fallbackUrl,
                    csrfToken: form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '',
                    formDataFactory: (target) => new FormData(target),
                    fetchImpl: (url, init) => fetch(url, init),
                    notify: toast,
                    download: saveBlob,
                    fallbackDownload: saveFallbackUrl,
                    messages: {
                        fallbackName: 'template_import_pr.xlsx',
                        invalid: translate('purchasing.js.export_form_invalid', 'Requisition entries are invalid. A blank template was downloaded without your entered data.'),
                        failed: translate('purchasing.js.export_form_failed', 'Could not include your current form entries. A blank template was downloaded instead.'),
                        done: translate('purchasing.js.export_form_done', 'Requisition template downloaded with your current entries.'),
                    },
                });
            } finally {
                button.removeAttribute('aria-busy');
                button.removeAttribute('aria-disabled');
            }
        });
    });
}

if (typeof document !== 'undefined') bootPrFormExport();

