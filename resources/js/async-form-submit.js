import { t } from './i18n.js';

/**
 * ADASI Portal Supplier - File-preserving async form submit.
 *
 * Forms marked `data-async-submit` are posted with fetch instead of a full page reload so a
 * server-side validation failure (422) never wipes the files the user already selected:
 * browsers cannot refill `<input type="file">` after a reload.
 *
 * Contract with the server: a JSON request receives `{ redirect }` on success (with any flash
 * data kept in the session, never in the body) and Laravel's standard `{ message, errors }` on 422.
 * Without fetch/FormData the form falls back to the classic POST.
 *
 * Events dispatched on the form:
 * - `adasi:form-submitting`  before the request
 * - `adasi:form-errors`      detail: { errors, message } on 422
 * - `adasi:reveal-field`     detail: { element, key } before focusing an errored field (wizards switch step)
 * - `adasi:form-success`     detail: { redirect } just before navigation
 * - `adasi:form-settled`     after any non-navigating outcome (buttons/spinners should be restored)
 */

/**
 * Candidate input names for a Laravel error key, e.g.
 * `invoice.0` -> `invoice[]`, `questionnaire.msds` -> `questionnaire[msds]`.
 */
export function errorKeyToFieldNames(key) {
    const parts = String(key || '').split('.').filter((part) => part !== '');
    if (!parts.length) return [];

    const [base, ...rest] = parts;
    const bracket = (numericAsEmpty) => base + rest
        .map((part) => (/^\d+$/.test(part) && numericAsEmpty ? '[]' : `[${part}]`))
        .join('');

    return [...new Set([bracket(true), bracket(false), base, `${base}[]`])];
}

export function isSameOriginUrl(url, origin) {
    try {
        const target = new URL(String(url), origin);
        return target.origin === new URL(origin).origin && ['http:', 'https:'].includes(target.protocol);
    } catch (error) {
        return false;
    }
}

/** Flatten Laravel `{ field: [messages] }` into ordered `[{ key, message }]`. */
export function flattenErrors(errors) {
    if (!errors || typeof errors !== 'object') return [];

    return Object.entries(errors)
        .map(([key, messages]) => ({ key, message: String([].concat(messages)[0] ?? '') }))
        .filter((item) => item.message !== '');
}

export function findFieldForErrorKey(form, key) {
    if (!form) return null;

    for (const name of errorKeyToFieldNames(key)) {
        const escaped = globalThis.CSS?.escape ? CSS.escape(name) : name.replace(/["\\]/g, '\\$&');
        const field = form.querySelector(`[name="${escaped}"]`);
        if (field) return field;
    }

    return null;
}

function revealField(form, key, element) {
    form.dispatchEvent(new CustomEvent('adasi:reveal-field', { detail: { key, element } }));
}

function focusField(element) {
    if (!element) return;

    // Wait for wizards to switch step (x-show) before scrolling/focusing.
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        const target = element.type === 'file' || element.type === 'hidden'
            ? (element.closest('[x-data]') || element.parentElement || element)
            : element;
        target.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
        if (element.type !== 'hidden' && typeof element.focus === 'function') {
            element.focus({ preventScroll: true });
        }
    }));
}

function errorSummary(form) {
    let summary = form.querySelector('[data-async-error-summary]');
    if (!summary) {
        summary = document.createElement('div');
        summary.setAttribute('data-async-error-summary', '');
        form.prepend(summary);
    }

    return summary;
}

function clearRenderedErrors(form) {
    form.querySelectorAll('[data-async-error]').forEach((node) => node.remove());
    form.querySelectorAll('[data-async-invalid]').forEach((field) => {
        field.removeAttribute('aria-invalid');
        field.removeAttribute('data-async-invalid');
    });

    const summary = form.querySelector('[data-async-error-summary]');
    if (summary) {
        summary.hidden = true;
        summary.replaceChildren();
    }
}

function isFileUploadComponentField(form, key) {
    const field = findFieldForErrorKey(form, key);
    return field?.type === 'file' && Boolean(field.closest('[x-data^="adasiFileUploadComponent"]'));
}

function renderInlineError(field, message) {
    if (!field || field.type === 'hidden') return;
    // <x-ui.file-upload> dropzones render their own error from `adasi:form-errors`.
    if (field.type === 'file' && field.closest('[x-data^="adasiFileUploadComponent"]')) return;

    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('data-async-invalid', '');

    const anchor = field.closest('fieldset')
        || field.closest('[data-async-error-anchor]')
        || (field.parentElement?.classList.contains('tw-relative') ? field.parentElement : field);

    const note = document.createElement('p');
    note.className = 'tw-m-0 tw-mt-1 tw-text-ui-xs tw-font-medium tw-text-error';
    note.setAttribute('data-async-error', '');
    note.textContent = message;
    anchor.insertAdjacentElement('afterend', note);
}

function renderSummary(form, items) {
    const summary = errorSummary(form);
    summary.className = 'tw-mb-5 tw-rounded-ui-sm tw-border tw-border-error/40 tw-bg-error-container tw-p-3.5 tw-text-on-error-container';
    summary.setAttribute('role', 'alert');
    summary.setAttribute('tabindex', '-1');
    summary.setAttribute('data-error-summary', '');

    const title = document.createElement('p');
    title.className = 'tw-m-0 tw-text-ui-sm tw-font-semibold';
    title.textContent = t('js.async_form.errors_title');

    const kept = document.createElement('p');
    kept.className = 'tw-m-0 tw-mt-0.5 tw-text-ui-xs';
    kept.textContent = t('js.async_form.files_kept');

    const list = document.createElement('ul');
    list.className = 'tw-m-0 tw-mt-2 tw-ps-5 tw-text-ui-xs tw-space-y-1';
    items.forEach(({ key, message }) => {
        const li = document.createElement('li');
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'ui-focus-ring tw-border-0 tw-bg-transparent tw-p-0 tw-text-start tw-font-medium tw-text-on-error-container tw-underline tw-underline-offset-2';
        link.setAttribute('data-error-field', key);
        link.textContent = message;
        li.append(link);
        list.append(li);
    });

    summary.replaceChildren(title, kept, list);
    summary.hidden = false;

    return summary;
}

function notify(type, message) {
    if (window.AdasiToast?.[type]) {
        window.AdasiToast[type](message);
    }
}

const loaderTokens = new WeakMap();

function settle(form) {
    if (loaderTokens.has(form)) {
        // Immediate: error summary focus (below) must not hit an inert page.
        window.AdasiLoader?.hide(loaderTokens.get(form), true);
        loaderTokens.delete(form);
    }
    delete form.dataset.asyncSubmitting;
    form.removeAttribute('aria-busy');
    window.AdasiButton?.resetForm?.(form);
    try {
        window.turnstile?.reset?.();
    } catch (error) {
        // Turnstile not rendered on this page.
    }
    form.dispatchEvent(new CustomEvent('adasi:form-settled'));
}

async function readJson(response) {
    try {
        return await response.json();
    } catch (error) {
        return {};
    }
}

export function handleValidationErrors(form, errors, message = '') {
    const items = flattenErrors(errors);
    form.dispatchEvent(new CustomEvent('adasi:form-errors', { detail: { errors: errors || {}, message } }));

    if (form.dataset.asyncInlineErrors !== 'off') {
        items.forEach(({ key, message: text }) => renderInlineError(findFieldForErrorKey(form, key), text));
    }

    const summaryItems = form.dataset.asyncSummarySkipFileErrors === 'true'
        ? items.filter(({ key }) => !isFileUploadComponentField(form, key))
        : items;
    let summary = null;
    if (summaryItems.length > 0 || items.length === 0) {
        summary = renderSummary(form, summaryItems);
    } else {
        const existingSummary = form.querySelector('[data-async-error-summary]');
        if (existingSummary) {
            existingSummary.hidden = true;
            existingSummary.replaceChildren();
        }
    }

    const first = items[0];
    if (first) {
        revealField(form, first.key, findFieldForErrorKey(form, first.key));
    }

    if (summary) {
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            summary.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
            summary.focus({ preventScroll: true });
        }));
    } else if (first) {
        focusField(findFieldForErrorKey(form, first.key));
    }
}

export async function submitAsync(form, submitter = null) {
    form.dataset.asyncSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    clearRenderedErrors(form);
    form.dispatchEvent(new CustomEvent('adasi:form-submitting'));
    const loaderToken = window.AdasiLoader?.show();
    if (loaderToken !== undefined) loaderTokens.set(form, loaderToken);

    const body = new FormData(form);
    if (submitter?.name) body.append(submitter.name, submitter.value ?? '');

    const token = form.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content;

    let response;
    try {
        response = await fetch(form.action, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
        });
    } catch (error) {
        notify('error', t('js.async_form.failed'));
        settle(form);
        return;
    }

    if (response.ok) {
        const data = await readJson(response);
        if (data.status_url && isSameOriginUrl(data.status_url, window.location.origin)) {
            settle(form);
            form.dispatchEvent(new CustomEvent('adasi:form-processing', { detail: data }));
            return;
        }
        const target = data.redirect || (response.redirected ? response.url : null);
        if (target && isSameOriginUrl(target, window.location.origin)) {
            form.dispatchEvent(new CustomEvent('adasi:form-success', { detail: { redirect: target } }));
            window.location.assign(target);
            return;
        }
        notify('error', t('js.async_form.failed'));
        settle(form);
        return;
    }

    if (response.status === 413) {
        const message = t('js.async_form.too_large');
        const field = form.querySelector('input[type="file"]');
        const key = String(field?.name || 'file').replace(/\[\]$/, '');
        settle(form);
        handleValidationErrors(form, { [key]: [message] }, message);
        return;
    }

    if (response.status === 422) {
        const data = await readJson(response);
        settle(form);
        handleValidationErrors(form, data.errors, data.message || '');
        return;
    }

    // 419 (expired session) and 429 (throttled) share one message; it already says to reload if the session ended.
    notify('error', t('js.async_form.failed'));
    settle(form);
}

function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-async-submit]')) return;
    // Client-side validation (Alpine/inline handlers) already blocked this submit.
    if (event.defaultPrevented) return;
    if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return;

    event.preventDefault();
    if (form.dataset.asyncSubmitting === 'true') return;

    submitAsync(form, event.submitter || null);
}

/** Error-summary links (async and server-rendered `[data-error-summary]`) jump to their field. */
function onSummaryClick(event) {
    const link = event.target.closest?.('[data-error-summary] [data-error-field]');
    if (!link) return;

    const form = link.closest('form') || document.getElementById(link.closest('[data-error-summary]')?.dataset.errorSummary || '');
    if (!(form instanceof HTMLFormElement)) return;

    event.preventDefault();
    const key = link.getAttribute('data-error-field');
    const field = findFieldForErrorKey(form, key);
    revealField(form, key, field);
    focusField(field);
}

if (typeof document !== 'undefined') {
    // Bubble phase on document: runs after form-level handlers (validation, ceiling checks).
    document.addEventListener('submit', onSubmit);
    document.addEventListener('click', onSummaryClick);

    window.AdasiAsyncForm = Object.freeze({
        submit: submitAsync,
        findFieldForErrorKey,
        errorKeyToFieldNames,
    });
}
