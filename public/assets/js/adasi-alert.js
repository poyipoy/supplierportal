(function (window, document) {
    'use strict';

    const SweetAlert = window.Swal;
    const fallbackResult = (overrides) => Object.assign({
        isConfirmed: false,
        isDenied: false,
        isDismissed: true,
        value: undefined,
    }, overrides || {});

    const asOptions = (options) => options && typeof options === 'object' ? options : {};
    const asText = (value, fallback) => value === undefined || value === null ? fallback : String(value);
    const classes = (confirmTone) => ({
        container: 'adasi-alert-container',
        popup: 'adasi-alert-popup',
        title: 'adasi-alert-title',
        htmlContainer: 'adasi-alert-content',
        icon: 'adasi-alert-icon',
        actions: 'adasi-alert-actions',
        confirmButton: `adasi-alert-btn adasi-alert-btn--${confirmTone || 'primary'}`,
        cancelButton: 'adasi-alert-btn adasi-alert-btn--secondary',
        input: 'adasi-alert-input',
        validationMessage: 'adasi-alert-validation',
        closeButton: 'adasi-alert-close',
    });

    const contentOptions = (options) => {
        const result = {};
        if (options.html !== undefined) {
            result.html = String(options.html);
        } else if (options.text !== undefined) {
            result.text = String(options.text);
        }
        return result;
    };

    const baseOptions = (options, type, confirmTone) => ({
        titleText: asText(options.title, ''),
        ...contentOptions(options),
        icon: type,
        buttonsStyling: false,
        customClass: classes(confirmTone),
        showClass: { popup: 'adasi-alert-show' },
        hideClass: { popup: 'adasi-alert-hide' },
        returnFocus: true,
        allowEscapeKey: true,
    });

    const nativeConfirm = (options, danger) => Promise.resolve(fallbackResult({
        isConfirmed: window.confirm(`${asText(options.title, danger ? 'Confirm action' : 'Confirmation')}\n\n${asText(options.text, '')}`),
        isDismissed: false,
    })).then((result) => ({ ...result, isDismissed: !result.isConfirmed }));

    const confirm = (rawOptions, danger) => {
        const options = asOptions(rawOptions);
        if (!SweetAlert) return nativeConfirm(options, danger);

        return SweetAlert.fire({
            ...baseOptions(options, danger ? 'warning' : asText(options.type, 'question'), danger ? 'danger' : asText(options.confirmTone, 'primary')),
            showCancelButton: true,
            confirmButtonText: asText(options.confirmText, danger ? 'Yes, Continue' : 'Yes, Continue'),
            cancelButtonText: asText(options.cancelText, 'Cancel'),
            reverseButtons: true,
            focusConfirm: !danger,
            focusCancel: danger,
            allowOutsideClick: false,
        });
    };

    const prompt = (rawOptions) => {
        const options = asOptions(rawOptions);
        if (!SweetAlert) {
            const value = window.prompt(asText(options.title, 'Input'), asText(options.initialValue, ''));
            return Promise.resolve(fallbackResult({
                isConfirmed: value !== null,
                isDismissed: value === null,
                value,
            }));
        }

        const maxLength = Number.isFinite(Number(options.maxLength)) ? Number(options.maxLength) : null;

        return SweetAlert.fire({
            ...baseOptions(options, asText(options.type, 'question'), asText(options.confirmTone, 'primary')),
            input: asText(options.input, 'textarea'),
            inputLabel: asText(options.inputLabel, ''),
            inputPlaceholder: asText(options.placeholder, ''),
            inputValue: asText(options.initialValue, ''),
            inputAttributes: maxLength ? { maxlength: String(maxLength) } : {},
            showCancelButton: true,
            confirmButtonText: asText(options.confirmText, 'Submit'),
            cancelButtonText: asText(options.cancelText, 'Cancel'),
            reverseButtons: true,
            focusConfirm: false,
            allowOutsideClick: false,
            inputValidator: (value) => {
                const normalized = String(value || '').trim();
                if (options.required && !normalized) {
                    return asText(options.requiredMessage, 'This field is required.');
                }
                if (maxLength && normalized.length > maxLength) {
                    return `Maximum ${maxLength} characters are allowed.`;
                }
                return typeof options.validate === 'function' ? options.validate(value) : null;
            },
        });
    };

    const alert = (type, rawOptions) => {
        const options = asOptions(rawOptions);
        if (!SweetAlert) {
            window.alert(`${asText(options.title, type)}\n\n${asText(options.text, '')}`);
            return Promise.resolve(fallbackResult({ isConfirmed: true, isDismissed: false }));
        }

        return SweetAlert.fire({
            ...baseOptions(options, type, asText(options.confirmTone, 'primary')),
            confirmButtonText: asText(options.confirmText, 'OK'),
            showConfirmButton: options.showConfirmButton !== false,
            showCloseButton: options.showCloseButton === true,
            allowOutsideClick: options.allowOutsideClick !== false,
            focusConfirm: true,
        });
    };

    const toast = (rawOptions) => {
        const options = asOptions(rawOptions);
        const type = asText(options.type, 'info');

        const actions = typeof options.onClick === 'function'
            ? [{
                label: asText(options.actionLabel, 'View'),
                variant: 'primary',
                onClick: options.onClick,
            }]
            : options.actions;
        const payload = {
            type,
            title: asText(options.title, ''),
            message: asText(options.text ?? options.message, ''),
            timestamp: options.timestamp,
            autoClose: Number(options.duration) > 0 ? Number(options.duration) : undefined,
            actions,
        };

        if (window.AdasiToast) {
            const id = window.AdasiToast.show(payload);

            return Promise.resolve({ ...fallbackResult(), id });
        }

        window.__adasiToastQueue = window.__adasiToastQueue || [];
        window.__adasiToastQueue.push(payload);

        return Promise.resolve(fallbackResult());
    };

    const AdasiAlert = {
        confirm: (options) => confirm(options, false),
        confirmDanger: (options) => confirm(options, true),
        prompt: prompt,
        success: (options) => alert('success', options),
        error: (options) => alert('error', options),
        warning: (options) => alert('warning', options),
        info: (options) => alert('info', options),
        toast: toast,
        notification: (options) => toast({ ...asOptions(options), type: asText(asOptions(options).type, 'info'), duration: asOptions(options).duration || 5000 }),
    };

    window.AdasiAlert = Object.freeze(AdasiAlert);

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-adasi-flash]').forEach((element) => {
            const type = element.dataset.type || 'info';
            const options = {
                title: element.dataset.title || '',
                text: element.dataset.message || '',
                duration: Number(element.dataset.duration) || undefined,
            };

            toast({ ...options, type });
        });
    });
})(window, document);
