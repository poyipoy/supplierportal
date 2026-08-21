(function (window, document) {
    'use strict';

    const selector = '[data-async-export]';
    const activeExports = new Map();
    const trackedExportJobs = new Map();
    const pollIntervalMs = 1000;
    const pollTimeoutMs = 660000;
    const maxConsecutivePollFailures = 5;
    const completedExportRetentionMs = 30000;
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
        if (window.AdasiToast) {
            window.AdasiToast.show({ type, title, message: text });
            return;
        }

        if (type === 'error' && window.AdasiAlert) {
            window.AdasiAlert.error({ title, text });
        }
    };

    const exportActions = (exportsUrl) => exportsUrl ? [{
        label: 'View jobs',
        variant: 'primary',
        url: exportsUrl,
    }] : [];

    const createStartingToast = () => {
        if (!window.AdasiToast) return null;

        return window.AdasiToast.progress({
            title: 'Starting export',
            message: 'Submitting the export request...',
            progressLabel: 'Starting',
        });
    };

    const normalizeExportJobId = (value) => value === null || value === undefined
        ? ''
        : String(value);

    const removeExpiredTrackedJobs = () => {
        const now = Date.now();

        trackedExportJobs.forEach((expiresAt, exportJobId) => {
            if (expiresAt !== Infinity && expiresAt <= now) {
                trackedExportJobs.delete(exportJobId);
            }
        });
    };

    const trackExportJob = (exportJobId) => {
        const normalizedId = normalizeExportJobId(exportJobId);
        if (normalizedId) trackedExportJobs.set(normalizedId, Infinity);
    };

    const releaseTrackedExportJob = (exportJobId, retainBriefly = false) => {
        const normalizedId = normalizeExportJobId(exportJobId);
        if (!normalizedId) return;

        if (retainBriefly) {
            trackedExportJobs.set(normalizedId, Date.now() + completedExportRetentionMs);
            return;
        }

        trackedExportJobs.delete(normalizedId);
    };

    const isTrackingNotification = (notification = {}) => {
        if (!['export.completed', 'export.failed'].includes(notification.event)) {
            return false;
        }

        removeExpiredTrackedJobs();

        const exportJobId = normalizeExportJobId(notification.export_job_id);
        return exportJobId !== '' && trackedExportJobs.has(exportJobId);
    };

    const updateExportToast = (state, changes) => {
        if (state.toastId && window.AdasiToast?.update(state.toastId, changes)) return;
        notify(changes.type || 'info', changes.title || 'Export update', changes.message || '');
    };

    const recordsTotal = () => {
        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn.dataTable) {
            return 'all';
        }

        const tables = window.jQuery.fn.dataTable.tables(true);
        if (!tables.length) {
            return 'all';
        }

        return window.jQuery(tables[0]).DataTable().page.info().recordsTotal;
    };

    const confirmExport = () => {
        const options = {
            title: 'Confirm Export',
            text: `You are about to export ${recordsTotal()} data rows to Excel. Continue?`,
            type: 'info',
            confirmTone: 'success',
            confirmText: 'Export',
            cancelText: 'Cancel',
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
            ? 'Your session has expired. Please sign in again.'
            : 'The export request could not be processed.';
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
            throw new Error('The download session is invalid. Please sign in again.');
        }

        const blob = await response.blob();
        if (!blob.size) {
            throw new Error('The export file is empty or unavailable.');
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

        const finish = (retainNotificationSuppression = false) => {
            activeExports.delete(state.requestUrl);
            releaseTrackedExportJob(state.exportJobId, retainNotificationSuppression);
            setBusy(state.control, false);
        };

        const poll = async () => {
            if (Date.now() - state.startedAt >= pollTimeoutMs) {
                finish();
                updateExportToast(state, {
                    type: 'warning',
                    title: 'Export is still processing',
                    message: 'Automatic monitoring has stopped. The file will remain available in Export History.',
                    autoClose: 0,
                });
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
                            finish(true);
                            updateExportToast(state, {
                                type: 'success',
                                title: 'Export completed',
                                message: 'The Excel file was downloaded automatically.',
                                progress: 100,
                                progressLabel: 'Completed',
                                autoClose: 4000,
                            });
                        } catch (error) {
                            finish(true);
                            updateExportToast(state, {
                                type: 'error',
                                title: 'Automatic download failed',
                                message: error instanceof Error ? error.message : 'Download the file from Export History.',
                                autoClose: 0,
                            });
                        }
                    } else {
                        finish(true);
                        updateExportToast(state, {
                            type: 'error',
                            title: 'Export file is unavailable',
                            message: payload.message || 'The export file cannot be downloaded.',
                            autoClose: 0,
                        });
                    }
                    return;
                }

                if (payload.status === 'failed') {
                    finish(true);
                    updateExportToast(state, {
                        type: 'error',
                        title: 'Export could not be completed',
                        message: payload.message || 'Try the export again or review Export History.',
                        autoClose: 0,
                    });
                    return;
                }

                if (payload.status !== 'queued' && payload.status !== 'processing') {
                    finish();
                    updateExportToast(state, {
                        type: 'error',
                        title: 'Export status is unavailable',
                        message: 'Review the job in Export History.',
                        autoClose: 0,
                    });
                    return;
                }

                if (payload.status !== state.lastStatus) {
                    state.lastStatus = payload.status;
                    updateExportToast(state, {
                        title: payload.status === 'processing' ? 'Export in progress' : 'Export queued',
                        message: payload.message || 'The export is continuing in the background.',
                        progressLabel: payload.status === 'processing' ? 'Preparing file' : 'Waiting for processing',
                        indeterminate: true,
                    });
                }
            } catch (error) {
                consecutiveFailures += 1;

                if (consecutiveFailures >= maxConsecutivePollFailures) {
                    finish();
                    updateExportToast(state, {
                        type: 'warning',
                        title: 'Export status could not be refreshed',
                        message: 'The file will remain available in Export History after processing.',
                        autoClose: 0,
                    });
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

        const state = {
            control,
            requestUrl,
            exportJobId: null,
            statusUrl: null,
            toastId: createStartingToast(),
            lastStatus: 'starting',
            startedAt: Date.now(),
        };

        activeExports.set(requestUrl, state);
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
                throw new Error('The export response is incomplete.');
            }

            state.exportJobId = payload.export_job_id;
            state.statusUrl = payload.status_url;
            state.lastStatus = 'queued';
            trackExportJob(state.exportJobId);

            if (state.toastId) {
                updateExportToast(state, {
                    title: 'Export queued',
                    message: payload.message || 'The export will continue in the background.',
                    progressLabel: 'Waiting for processing',
                    indeterminate: true,
                    actions: exportActions(payload.exports_url),
                });
            } else {
                notify('info', 'Export queued', payload.message || 'The file will download automatically when ready.');
            }

            pollStatus(state);
        } catch (error) {
            activeExports.delete(requestUrl);
            releaseTrackedExportJob(state.exportJobId);
            setBusy(control, false);
            updateExportToast(state, {
                type: 'error',
                title: 'Unable to Start Export',
                message: error instanceof Error ? error.message : 'The export request could not be processed.',
                autoClose: 0,
            });
        }
    };

    window.AdasiAsyncExport = Object.freeze({
        isTrackingNotification,
    });

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
