(function (window, document) {
    'use strict';

    const selector = '[data-async-export]';
    const activeExports = new Map();
    const pollIntervalMs = 1000;
    const pollTimeoutMs = 660000;
    const maxConsecutivePollFailures = 5;
    let confirmationOpen = false;

    document.documentElement.dataset.asyncExportReady = 'true';

    const isAsyncExportUrl = (url) => {
        try {
            const target = new URL(url, window.location.origin);
            const path = target.pathname.replace(/\/+$/, '');

            return target.origin === window.location.origin && (
                /^\/(purchasing|supplier|qc)\/export\//.test(path)
                || path === '/supplier/price-history/export'
            );
        } catch (error) {
            return false;
        }
    };

    const isAsyncExportControl = (control) => control.matches(selector)
        || isAsyncExportUrl(control instanceof HTMLFormElement ? control.action : control.href);

    const notify = (type, title, text) => {
        if (window.AdasiAlert) {
            if (type === 'error') {
                window.AdasiAlert.error({ title, text });
                return;
            }

            window.AdasiAlert.notification({ type, title, text, duration: 5000 });
            return;
        }

        if (type === 'error') {
            window.alert(`${title}\n\n${text}`);
        }
    };

    const recordsTotal = () => {
        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn.dataTable) {
            return 'semua';
        }

        const tables = window.jQuery.fn.dataTable.tables(true);
        if (!tables.length) {
            return 'semua';
        }

        return window.jQuery(tables[0]).DataTable().page.info().recordsTotal;
    };

    const confirmExport = () => {
        const options = {
            title: 'Konfirmasi Export',
            text: `Anda akan mengekspor ${recordsTotal()} baris data ke Excel. Lanjutkan?`,
            type: 'info',
            confirmTone: 'success',
            confirmText: 'Ya, Export',
            cancelText: 'Batal',
        };

        if (window.AdasiAlert) {
            return window.AdasiAlert.confirm(options).then((result) => Boolean(result && result.isConfirmed));
        }

        return Promise.resolve(window.confirm(`${options.title}\n\n${options.text}`));
    };

    const requestUrlFor = (control) => {
        if (control instanceof HTMLFormElement) {
            const url = new URL(control.action, window.location.origin);
            const formData = new FormData(control);

            for (const [key, value] of formData.entries()) {
                if (typeof value === 'string') {
                    url.searchParams.set(key, value);
                }
            }

            return url.toString();
        }

        return new URL(control.href, window.location.origin).toString();
    };

    const busyElementFor = (control) => control instanceof HTMLFormElement
        ? control.querySelector('button[type="submit"], input[type="submit"]')
        : control;

    const setBusy = (control, busy) => {
        const element = busyElementFor(control);
        if (!element) {
            return;
        }

        if (busy) {
            if (element.dataset.asyncExportOriginalHtml === undefined && element instanceof HTMLButtonElement) {
                element.dataset.asyncExportOriginalHtml = element.innerHTML;
            }
            if (element.dataset.asyncExportOriginalText === undefined && element instanceof HTMLInputElement) {
                element.dataset.asyncExportOriginalText = element.value;
            }

            element.setAttribute('aria-disabled', 'true');
            element.classList.add('disabled');
            if ('disabled' in element) {
                element.disabled = true;
            }

            if (element instanceof HTMLButtonElement) {
                element.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Menyiapkan...';
            } else if (element instanceof HTMLInputElement) {
                element.value = 'Menyiapkan...';
            }

            return;
        }

        element.removeAttribute('aria-disabled');
        element.classList.remove('disabled');
        if ('disabled' in element) {
            element.disabled = false;
        }

        if (element instanceof HTMLButtonElement && element.dataset.asyncExportOriginalHtml !== undefined) {
            element.innerHTML = element.dataset.asyncExportOriginalHtml;
            delete element.dataset.asyncExportOriginalHtml;
        } else if (element instanceof HTMLInputElement && element.dataset.asyncExportOriginalText !== undefined) {
            element.value = element.dataset.asyncExportOriginalText;
            delete element.dataset.asyncExportOriginalText;
        }
    };

    const responseError = async (response) => {
        let payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (payload && payload.errors && typeof payload.errors === 'object') {
            const validationMessage = Object.values(payload.errors)
                .flat()
                .filter((message) => typeof message === 'string')
                .join('\n');

            if (validationMessage) {
                return validationMessage;
            }
        }

        if (payload && typeof payload.message === 'string' && payload.message.trim() !== '') {
            return payload.message;
        }

        return response.status === 401
            ? 'Sesi Anda telah berakhir. Silakan login kembali.'
            : 'Permintaan export tidak dapat diproses.';
    };

    const triggerDownload = async (downloadUrl, fileName, exportJobId) => {
        const response = await window.fetch(downloadUrl, {
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(await responseError(response));
        }

        const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
        if (contentType.includes('text/html')) {
            throw new Error('Sesi download tidak valid. Silakan login kembali.');
        }

        const blob = await response.blob();
        if (!blob.size) {
            throw new Error('File export kosong atau tidak tersedia.');
        }

        const objectUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.hidden = true;
        link.href = objectUrl;
        link.download = fileName || `export-${exportJobId}.xlsx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => window.URL.revokeObjectURL(objectUrl), 60000);
    };

    const pollStatus = (state) => {
        let consecutiveFailures = 0;

        const finish = () => {
            activeExports.delete(state.requestUrl);
            setBusy(state.control, false);
        };

        const poll = async () => {
            if (Date.now() - state.startedAt >= pollTimeoutMs) {
                finish();
                notify(
                    'warning',
                    'Export masih diproses',
                    'Pemantauan otomatis dihentikan. File tetap dapat diunduh melalui notifikasi atau menu Export Saya.'
                );
                return;
            }

            try {
                const response = await window.fetch(state.statusUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error(await responseError(response));
                }

                const payload = await response.json();
                consecutiveFailures = 0;

                if (payload.status === 'completed') {
                    if (payload.download_url) {
                        try {
                            await triggerDownload(
                                payload.download_url,
                                payload.file_name,
                                payload.id || state.exportJobId
                            );
                            finish();
                            notify('success', 'Export selesai', 'File Excel berhasil diunduh otomatis.');
                        } catch (error) {
                            finish();
                            notify(
                                'error',
                                'Download otomatis gagal',
                                error instanceof Error ? error.message : 'Silakan unduh file melalui menu Export Saya.'
                            );
                        }
                    } else {
                        finish();
                        notify('error', 'File tidak tersedia', payload.message || 'File export tidak dapat diunduh.');
                    }
                    return;
                }

                if (payload.status === 'failed') {
                    finish();
                    notify('error', 'Export gagal', payload.message || 'Export gagal diproses. Silakan coba lagi.');
                    return;
                }

                if (payload.status !== 'queued' && payload.status !== 'processing') {
                    finish();
                    notify('error', 'Status export tidak valid', 'Silakan periksa menu Export Saya.');
                    return;
                }
            } catch (error) {
                consecutiveFailures += 1;

                if (consecutiveFailures >= maxConsecutivePollFailures) {
                    finish();
                    notify(
                        'warning',
                        'Status export tidak dapat diperbarui',
                        'File tetap dapat diunduh melalui notifikasi atau menu Export Saya setelah selesai.'
                    );
                    return;
                }
            }

            window.setTimeout(poll, pollIntervalMs);
        };

        window.setTimeout(poll, pollIntervalMs);
    };

    const startExport = async (control) => {
        const requestUrl = requestUrlFor(control);
        if (activeExports.has(requestUrl)) {
            return;
        }

        activeExports.set(requestUrl, true);
        setBusy(control, true);

        try {
            const response = await window.fetch(requestUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(await responseError(response));
            }

            const payload = await response.json();
            if (!payload.export_job_id || !payload.status_url) {
                throw new Error('Respons export tidak lengkap.');
            }

            notify('info', 'Permintaan export diterima', payload.message || 'File akan terunduh otomatis ketika siap.');
            pollStatus({
                control,
                requestUrl,
                exportJobId: payload.export_job_id,
                statusUrl: payload.status_url,
                startedAt: Date.now(),
            });
        } catch (error) {
            activeExports.delete(requestUrl);
            setBusy(control, false);
            notify('error', 'Export gagal dimulai', error instanceof Error ? error.message : 'Permintaan export tidak dapat diproses.');
        }
    };

    const confirmAndStart = async (control) => {
        if (confirmationOpen) {
            return;
        }

        confirmationOpen = true;

        try {
            if (await confirmExport()) {
                await startExport(control);
            }
        } finally {
            confirmationOpen = false;
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const link = target?.closest('a[href]');
        if (!link || !isAsyncExportControl(link) || event.button !== 0) {
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        confirmAndStart(link);
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !isAsyncExportControl(form)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        confirmAndStart(form);
    }, true);
})(window, document);
