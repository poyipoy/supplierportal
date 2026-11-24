const setLoginButtonLoading = (submitBtn, isLoading) => {
    if (!submitBtn) return;
    const spinner = submitBtn.querySelector('[data-login-submit-spinner]');
    const label = submitBtn.querySelector('[data-login-submit-label]');
    submitBtn.disabled = Boolean(isLoading);
    if (isLoading) {
        submitBtn.setAttribute('aria-busy', 'true');
    } else {
        submitBtn.removeAttribute('aria-busy');
    }
    if (spinner) spinner.hidden = !isLoading;
    if (label) label.hidden = Boolean(isLoading);
};

export function bootAuthLoginSplashForms() {
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form[data-login-splash-form]').forEach((form) => {
            delete form.dataset.loginNativeFallback;
            setLoginButtonLoading(form.querySelector('[data-login-submit-btn], button[type="submit"]'), false);
        });
    });

    document.querySelectorAll('form[data-login-splash-form]').forEach((form) => {
        if (form.dataset.loginSplashBound === 'true') return;
        form.dataset.loginSplashBound = 'true';

        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) return;

            const submitBtn = form.querySelector('[data-login-submit-btn], button[type="submit"]');
            if (form.dataset.loginNativeFallback === 'true' || typeof window.fetch !== 'function') {
                setLoginButtonLoading(submitBtn, true);
                return;
            }

            event.preventDefault();

            const errorAlert = form.querySelector('[data-login-error-alert]');
            if (errorAlert) {
                errorAlert.hidden = true;
                errorAlert.textContent = '';
            }
            form.querySelectorAll('[data-login-ajax-invalid="true"]').forEach((field) => {
                field.removeAttribute('data-login-ajax-invalid');
                field.setAttribute('aria-invalid', 'false');
                if (errorAlert?.id) {
                    const remaining = (field.getAttribute('aria-describedby') || '')
                        .split(/\s+/)
                        .filter((id) => id && id !== errorAlert.id);
                    if (remaining.length) field.setAttribute('aria-describedby', remaining.join(' '));
                    else field.removeAttribute('aria-describedby');
                }
            });
            setLoginButtonLoading(submitBtn, true);
            let keepLoading = false;

            try {
                const response = await window.fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Auth-Splash-Client': '1',
                    },
                });

                const payload = await response.json().catch(() => null);

                if (response.ok && payload?.ok && typeof payload.redirect_url === 'string') {
                    keepLoading = true;
                    window.location.assign(payload.redirect_url);
                    return;
                }

                if (response.status === 422 && payload?.errors) {
                    if (payload.turnstile_required && !form.querySelector('.cf-turnstile')) {
                        keepLoading = true;
                        form.dataset.loginNativeFallback = 'true';
                        HTMLFormElement.prototype.submit.call(form);
                        return;
                    }
                    const firstFieldErrors = Object.values(payload.errors)[0];
                    const firstMessage = Array.isArray(firstFieldErrors) ? firstFieldErrors[0] : (payload.message || '');
                    if (errorAlert && firstMessage) {
                        errorAlert.textContent = firstMessage;
                        errorAlert.hidden = false;
                    }
                    for (const fieldKey of Object.keys(payload.errors)) {
                        const field = form.querySelector(`[name="${CSS.escape(fieldKey)}"]`);
                        if (!field) continue;
                        field.setAttribute('data-login-ajax-invalid', 'true');
                        field.setAttribute('aria-invalid', 'true');
                        if (errorAlert?.id) {
                            const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                            field.setAttribute('aria-describedby', [...new Set([...ids, errorAlert.id])].join(' '));
                        }
                    }
                    window.turnstile?.reset?.();
                    window.requestAnimationFrame(() => {
                        if (form.isConnected === false) return;
                        form.querySelector('input[name="password"], input[name="code"]')?.focus?.();
                    });
                    return;
                }

                if (response.status === 419) {
                    keepLoading = true;
                    window.location.reload();
                    return;
                }

                keepLoading = true;
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } catch {
                keepLoading = true;
                form.dataset.loginNativeFallback = 'true';
                HTMLFormElement.prototype.submit.call(form);
            } finally {
                if (!keepLoading) {
                    setLoginButtonLoading(submitBtn, false);
                }
            }
        });
    });
}
