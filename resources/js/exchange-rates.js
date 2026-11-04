import { clearValidationErrors, handleValidationErrors, isSameOriginUrl } from './async-form-submit.js';
import { t } from './i18n.js';

const SELECTOR = '#exchangeRatesWorkspace';

export function sanitizeRateValue(raw) {
    if (typeof raw !== 'string') return '';
    let val = raw.replace(/,/g, '.');
    val = val.replace(/[^0-9.]/g, '');
    const firstDotIndex = val.indexOf('.');
    if (firstDotIndex !== -1) {
        val = val.slice(0, firstDotIndex + 1) + val.slice(firstDotIndex + 1).replace(/\./g, '');
        const [integerPart, decimalPart] = val.split('.');
        if (decimalPart !== undefined && decimalPart.length > 4) {
            val = integerPart + '.' + decimalPart.slice(0, 4);
        }
    }
    return val;
}

export function bootExchangeRates(root = document) {
    const workspace = root.querySelector(SELECTOR);
    if (!workspace || workspace.dataset.exchangeRatesReady || !window.AdasiServerTabs?.navigate) return;
    workspace.dataset.exchangeRatesReady = 'true';

    const content = workspace.querySelector('[data-server-tabs-content]');
    const modal = root.querySelector('#rateModal');
    const config = JSON.parse(workspace.querySelector('[data-exchange-rates-config]')?.textContent || '{}');
    const state = { saving: false, savedRedirect: null, discarded: false, failed: new Set() };
    const unsaved = window.AdasiUnsaved;
    const notify = (type, message) => window.AdasiToast?.[type]?.(message);
    unsaved?.registerDirtySource?.(() => state.saving || (!state.discarded && [...state.failed].some((form) => form.isConnected)));

    const hideModal = () => {
        if (!modal?.classList.contains('show')) return Promise.resolve();
        return new Promise((resolve) => {
            const hide = () => window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            const finished = () => {
                modal.removeEventListener('shown.bs.modal', hide);
                resolve();
            };
            modal.addEventListener('hidden.bs.modal', finished, { once: true });
            // Bootstrap ignores hide() during its opening transition; retry when that transition ends.
            modal.addEventListener('shown.bs.modal', hide, { once: true });
            hide();
        });
    };

    const confirmNavigation = () => {
        if (!unsaved?.isDirty()) return true;
        return new Promise((resolve) => {
            let confirmed = false;
            const result = unsaved.showLeaveConfirmation(() => {
                confirmed = true;
                state.discarded = true;
                resolve(true);
            });
            Promise.resolve(result).then(() => {
                if (!confirmed) resolve(false);
            }).catch(() => resolve(false));
        });
    };

    const beforeNavigate = async (url) => {
        if (state.saving) return url === state.savedRedirect;
        if (state.discarded) return true;
        // Back closes the permanent modal first, retaining the entered values.
        if (modal?.classList.contains('show')) {
            await hideModal();
            return false;
        }
        unsaved?.setSubmitting(false);
        return confirmNavigation();
    };

    const clearErrors = (form) => {
        clearValidationErrors(form);
        const errorScope = workspace.contains(form) ? workspace : form;
        errorScope.querySelectorAll('[data-exchange-rates-server-error]').forEach((node) => node.remove());
        form.querySelectorAll('[data-exchange-rates-request-error]').forEach((node) => node.remove());
        form.querySelectorAll('[aria-describedby]').forEach((field) => {
            const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && document.getElementById(id));
            if (ids.length) field.setAttribute('aria-describedby', ids.join(' '));
            else field.removeAttribute('aria-describedby');
        });
    };

    const addReviewLink = (target, form, url = form.dataset.exchangeRatesReviewUrl, label = form.dataset.exchangeRatesReviewLabel) => {
        if (!url || !isSameOriginUrl(url, window.location.origin)) return;
        const link = document.createElement('a');
        link.href = url;
        link.textContent = label || config.refreshLabel;
        link.dataset.exchangeRatesLink = '';
        // The modal is outside the workspace; its review action still belongs to this adapter.
        link.dataset.allowDirty = 'true';
        link.className = 'ui-focus-ring tw:mt-2 tw:inline-block tw:font-semibold tw:underline';
        target.append(link);
    };

    const requestError = (form, message, url = null) => {
        const box = document.createElement('div');
        box.dataset.exchangeRatesRequestError = '';
        box.setAttribute('role', 'alert');
        box.className = 'tw:m-3 tw:border tw:border-error tw:bg-error-container tw:p-3 tw:text-ui-sm tw:text-on-error-container';
        const text = document.createElement('p');
        text.className = 'tw:m-0';
        text.textContent = message;
        box.append(text);
        addReviewLink(box, form, url || form.dataset.exchangeRatesReviewUrl);
        form.prepend(box);
    };

    const onRateKeyDown = (event) => {
        if (event.defaultPrevented) return;
        const input = event.target;
        if (!input.matches?.('[data-annual-rate-input]')) return;

        if ([
            'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
            'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
            'Home', 'End',
        ].includes(event.key)) {
            return;
        }

        if (event.ctrlKey || event.metaKey) {
            return;
        }

        if (event.key === ',' || event.key === '.') {
            const val = input.value || '';
            const start = input.selectionStart ?? val.length;
            const end = input.selectionEnd ?? val.length;
            const selectedText = val.slice(start, end);

            if (val.includes('.') && !selectedText.includes('.')) {
                event.preventDefault();
                return;
            }

            if (event.key === ',') {
                event.preventDefault();
                const newVal = val.slice(0, start) + '.' + val.slice(end);
                input.value = newVal;
                input.setSelectionRange?.(start + 1, start + 1);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            return;
        }

        if (/^[0-9]$/.test(event.key)) {
            const val = input.value || '';
            const dotIndex = val.indexOf('.');
            const start = input.selectionStart ?? val.length;
            const end = input.selectionEnd ?? val.length;
            if (dotIndex !== -1 && start > dotIndex && (end - start === 0)) {
                const decimalPart = val.slice(dotIndex + 1);
                if (decimalPart.length >= 4) {
                    event.preventDefault();
                    return;
                }
            }
            return;
        }

        event.preventDefault();
    };

    const onRateInput = (event) => {
        const input = event.target;
        if (!input.matches?.('[data-annual-rate-input]')) return;

        const original = input.value || '';
        const sanitized = sanitizeRateValue(original);
        if (original !== sanitized) {
            const start = input.selectionStart;
            input.value = sanitized;
            if (typeof start === 'number') {
                const newPos = Math.min(start, sanitized.length);
                input.setSelectionRange?.(newPos, newPos);
            }
        }
    };

    const onRatePaste = (event) => {
        const input = event.target;
        if (!input.matches?.('[data-annual-rate-input]')) return;

        const pastedData = (event.clipboardData || window.clipboardData)?.getData('text');
        if (!pastedData) return;

        event.preventDefault();
        const val = input.value || '';
        const start = input.selectionStart ?? val.length;
        const end = input.selectionEnd ?? val.length;
        const candidate = val.slice(0, start) + pastedData + val.slice(end);
        const sanitized = sanitizeRateValue(candidate);
        input.value = sanitized;
        const cursor = Math.min(start + pastedData.length, sanitized.length);
        input.setSelectionRange?.(cursor, cursor);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const prepare = () => {
        workspace.querySelectorAll('a[data-exchange-rates-link]').forEach((link) => { link.dataset.allowDirty = 'true'; });
        workspace.querySelectorAll('[data-annual-rate-input]').forEach((input) => {
            if (!input.dataset.rateInputReady) {
                input.dataset.rateInputReady = 'true';
                input.addEventListener('keydown', onRateKeyDown);
                input.addEventListener('input', onRateInput);
                input.addEventListener('paste', onRatePaste);
            }
        });
        const forms = [...workspace.querySelectorAll('[data-exchange-rates-save]')];
        const modalForm = modal?.querySelector('[data-exchange-rates-save]');
        if (modalForm) {
            modalForm.dataset.exchangeRatesReviewUrl = workspace.querySelector('[data-tab-name="effective"]')?.href || '';
            modalForm.dataset.exchangeRatesReviewLabel = config.refreshLabel;
            forms.push(modalForm);
        }
        forms.forEach((form) => {
            form.dataset.managedSubmit = '';
            form.querySelectorAll('input, select, textarea').forEach((field) => {
                field.classList.add('tw:aria-[invalid=true]:border-error');
            });
            unsaved?.resetSnapshot(form);
        });
        if (content.querySelector('[data-exchange-rates-restored="true"]')) {
            const form = content.querySelector('[data-exchange-rates-save]');
            if (form) state.failed.add(form);
            unsaved?.markDirty();
        }
        const effective = Boolean(workspace.querySelector('[data-tab-name="effective"][aria-current="page"]'));
        const action = root.querySelector('[data-exchange-rates-effective-action]');
        if (action) action.hidden = !effective;
    };

    window.AdasiServerTabs.init(SELECTOR, {
        cacheTtlMs: 0,
        prefetch: false,
        managedHistory: true,
        beforeNavigate,
        onLoading: () => {
            // Prevent typing into an editor after its replacement has already been requested.
            content.inert = true;
            const action = root.querySelector('[data-exchange-rates-effective-action]');
            if (action) action.inert = true;
        },
        onError: () => {
            unsaved?.resumeTracking?.();
            content.inert = false;
            const action = root.querySelector('[data-exchange-rates-effective-action]');
            if (action) action.inert = false;
            if (state.discarded) {
                state.discarded = false;
                unsaved?.setSubmitting(false);
                unsaved?.markDirty();
            }
        },
        onUpdated: () => {
            unsaved?.resumeTracking?.();
            content.inert = false;
            const action = root.querySelector('[data-exchange-rates-effective-action]');
            if (action) action.inert = false;
            state.failed.clear();
            state.discarded = false;
            unsaved?.setSubmitting(false);
            unsaved?.markClean();
            const modalForm = modal?.querySelector('[data-exchange-rates-save]');
            if (modalForm && !modal.classList.contains('show')) {
                modalForm.reset();
                modalForm.querySelectorAll('[data-adasi-date-picker], [data-adasi-date-range]').forEach((picker) => {
                    window.AdasiCalendar?.reset?.(picker);
                });
                clearErrors(modalForm);
            }
            prepare();
            const focus = content.querySelector('[autofocus]');
            if (focus) focus.focus();
            else if (!document.activeElement?.closest('[data-server-tabs-nav]')) {
                const heading = content.querySelector('h2');
                if (heading) {
                    heading.tabIndex = -1;
                    heading.focus({ preventScroll: true });
                }
            }
        },
    });
    workspace.dataset.serverTabsUnsaved = 'managed';
    prepare();

    const followLink = (event) => {
        const link = event.target.closest?.('a[data-exchange-rates-link]');
        if (!link || !link.href || event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
            (link.target && link.target !== '_self')) return;
        if (!workspace.contains(link) && !modal?.contains(link)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.AdasiServerTabs.navigate(SELECTOR, link.href);
    };
    workspace.addEventListener('click', followLink, true);
    modal?.addEventListener('click', followLink, true);
    workspace.addEventListener('keydown', onRateKeyDown);
    workspace.addEventListener('input', onRateInput);
    workspace.addEventListener('paste', onRatePaste);
    workspace.addEventListener('submit', (event) => {
        if (event.target.matches('[data-server-tabs-form]')) unsaved?.setSubmitting(false);
    }, true);

    const onSubmit = async (event) => {
        const form = event.target;
        if (!form.matches?.('[data-exchange-rates-save]') || (!workspace.contains(form) && !modal?.contains(form))) return;
        if (event.defaultPrevented) {
            unsaved?.setSubmitting(false);
            return;
        }
        event.preventDefault();
        if (state.saving || form.dataset.exchangeRatesCommitted === 'true') return;
        state.saving = true;
        unsaved?.setSubmitting(false);
        clearErrors(form);
        const body = new FormData(form);
        const token = form.querySelector('[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content;
        const submitter = event.submitter || form.querySelector('[type="submit"]');
        window.AdasiButton?.startLoading(submitter);
        window.AdasiButton?.disableSiblingButtons(form, submitter);
        const controls = [...form.querySelectorAll('input:not([type="hidden"]), select, textarea')]
            .map((field) => ({ field, disabled: field.disabled }));
        controls.forEach(({ field }) => { field.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        let committed = false;
        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', body, credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
            });
            const data = await response.json();
            if (response.status === 422) {
                state.failed.add(form);
                controls.forEach(({ field, disabled }) => { field.disabled = disabled; });
                handleValidationErrors(form, data.errors || {}, data.message);
                if (data.errors?.year || data.errors?.expected_revision) {
                    addReviewLink(form.querySelector('[data-async-error-summary]') || form, form);
                }
                return;
            }
            if (!response.ok || !data.redirect || !isSameOriginUrl(data.redirect, window.location.origin)) {
                throw new Error('Unconfirmed exchange-rate save');
            }
            committed = true;
            state.savedRedirect = data.redirect;
            state.failed.delete(form);
            unsaved?.markClean();
            await hideModal();
            window.AdasiServerTabs.invalidateCache();
            const refreshed = await window.AdasiServerTabs.navigate(SELECTOR, data.redirect);
            notify('success', data.message);
            if (!refreshed && form.isConnected) {
                form.dataset.exchangeRatesCommitted = 'true';
                requestError(form, config.refreshFailed, data.redirect);
            }
        } catch {
            if (committed) {
                form.dataset.exchangeRatesCommitted = 'true';
                requestError(form, config.refreshFailed, state.savedRedirect);
            } else {
                state.failed.add(form);
                requestError(form, config.saveUnknown || t('js.async_form.failed'));
            }
        } finally {
            clearTimeout(timeout);
            controls.forEach(({ field, disabled }) => { field.disabled = disabled; });
            form.removeAttribute('aria-busy');
            window.AdasiButton?.resetForm(form);
            if (committed && form.dataset.exchangeRatesCommitted === 'true') {
                form.querySelectorAll('[type="submit"]').forEach((button) => { button.disabled = true; });
            }
            state.saving = false;
            state.savedRedirect = null;
            unsaved?.resumeTracking?.();
            unsaved?.setSubmitting(false);
            if (committed) unsaved?.markClean();
            else unsaved?.markDirty();
        }
    };
    document.addEventListener('submit', onSubmit);
}
