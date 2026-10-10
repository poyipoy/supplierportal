export function bootPoDocumentUploads(root = document) {
    root.querySelectorAll('[data-po-document-upload]').forEach((form) => {
        if (form.dataset.poUploadBound) return;
        form.dataset.poUploadBound = 'true';
        const status = form.querySelector('[data-po-upload-status]');
        const progress = form.querySelector('[data-po-upload-progress]');
        const retry = form.querySelector('[data-po-upload-retry]');
        const storageKey = `adasi.po-documents.${form.action}`;
        let timer;
        let statusUrl;
        let retryUrl;
        const safeUrl = (value) => {
            try { return new URL(value, location.origin).origin === location.origin; } catch { return false; }
        };
        const announce = (text) => { if (status && status.textContent !== text) status.textContent = text; };
        const remember = (value) => { try { if (value) sessionStorage.setItem(storageKey, value); else sessionStorage.removeItem(storageKey); } catch {} };
        const render = (data) => {
            announce(data.message || '');
            if (progress) {
                progress.hidden = false;
                progress.max = Math.max(1, Number(data.total));
                progress.value = Number(data.processed) || 0;
            }
            retryUrl = data.retry_url;
            if (retry) retry.hidden = !retryUrl;
            const terminal = ['COMPLETED', 'FAILED', 'REJECTED'].includes(data.status);
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = !terminal; });
            if (!terminal) {
                remember(statusUrl);
                timer = setTimeout(poll, 3000);
            } else remember(null);
        };
        const poll = async () => {
            if (!statusUrl || !safeUrl(statusUrl)) return;
            try {
                const response = await fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if ([403, 404, 419].includes(response.status)) {
                    remember(null);
                    statusUrl = null;
                    form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
                    announce(form.dataset.offline);
                    return;
                }
                if (!response.ok) throw new Error('Status unavailable');
                render(await response.json());
            } catch {
                announce(form.dataset.offline);
                timer = setTimeout(poll, 10000);
            }
        };
        form.addEventListener('adasi:form-submitting', () => {
            clearTimeout(timer);
            announce(form.dataset.uploading);
            if (progress) { progress.hidden = false; progress.removeAttribute('value'); }
        });
        form.addEventListener('adasi:form-processing', (event) => {
            statusUrl = event.detail.status_url;
            render(event.detail);
        });
        form.addEventListener('adasi:form-errors', () => {
            if (progress) progress.hidden = true;
            announce('');
            if (crypto.randomUUID) form.elements.request_key.value = crypto.randomUUID();
        });
        form.addEventListener('change', (event) => {
            if (event.target.type === 'file' && crypto.randomUUID) form.elements.request_key.value = crypto.randomUUID();
        });
        retry?.addEventListener('click', async () => {
            if (!retryUrl || !safeUrl(retryUrl)) return;
            retry.disabled = true;
            try {
                const response = await fetch(retryUrl, { method: 'POST', credentials: 'same-origin', headers: {
                    Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value,
                } });
                if (!response.ok) throw new Error('Retry unavailable');
                render(await response.json());
            } catch { announce(form.dataset.offline); } finally { retry.disabled = false; }
        });
        try { statusUrl = sessionStorage.getItem(storageKey); } catch {}
        if (statusUrl) poll();
    });
}
