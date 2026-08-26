(function (window, document) {
    'use strict';

    const selector = '[data-async-export]';
    const activeExports = new Map();
    const trackedExportJobs = new Map();
    const pollIntervalMs = 1000;
    const pollTimeoutMs = 660000;
    const maxConsecutivePollFailures = 5;
    const completedExportRetentionMs = 30000;
    const pendingExportStorageKey = 'adasi:pending-export-jobs:v1';
    const downloadClaimStoragePrefix = 'adasi:export-download-claim:';
    const progressStageLabels = Object.freeze({
        queued: 'Waiting for processing',
        preparing: 'Preparing data',
        generating: 'Generating workbook',
        finalizing: 'Finalizing file',
        completed: 'Completed',
        failed: 'Failed',
        cancelled: 'Cancelled',
    });
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

        window.__adasiToastQueue = window.__adasiToastQueue || [];
        window.__adasiToastQueue.push({ type, title, message: text });
    };

    const exportActions = (exportsUrl, cancelAction = null, cancelDisabled = false) => {
        const actions = [];

        if (exportsUrl) {
            actions.push({
                label: 'View jobs',
                variant: 'primary',
                url: exportsUrl,
                dismiss: false,
            });
        }

        if (cancelAction || cancelDisabled) {
            actions.push({
                label: 'Cancel',
                variant: 'danger',
                onClick: cancelAction,
                disabled: cancelDisabled,
                dismiss: false,
            });
        }

        actions.push({
            label: 'Dismiss',
            variant: 'secondary',
        });

        return actions;
    };

    const createExportToastId = () => {
        const randomPart = window.crypto?.randomUUID?.()
            || `${Date.now()}-${Math.random().toString(36).slice(2)}`;

        return `adasi-export-toast-${randomPart}`;
    };

    const showOrQueueToast = (options) => {
        if (window.AdasiToast) {
            return options.type === 'progress'
                ? window.AdasiToast.progress(options)
                : window.AdasiToast.show(options);
        }

        window.__adasiToastQueue = window.__adasiToastQueue || [];
        window.__adasiToastQueue.push({
            ...options,
            type: options.type || 'progress',
            autoClose: options.autoClose ?? (options.type === 'progress' ? 0 : undefined),
        });

        return options.id;
    };

    const showOrQueueProgressToast = (options) => showOrQueueToast({
        ...options,
        type: 'progress',
        autoClose: 0,
    });

    const createStartingToast = (toastId) => showOrQueueProgressToast({
        id: toastId,
        title: 'Starting export',
        message: 'Submitting the export request...',
        progressLabel: 'Starting',
        actions: exportActions(null),
    });

    const cleanPresentationText = (value, fallback) => {
        const text = typeof value === 'string' ? value.trim() : '';

        return text || fallback;
    };

    const exportPresentationFor = (control) => ({
        sourceSingular: cleanPresentationText(control?.dataset?.exportSourceSingular, 'record'),
        sourcePlural: cleanPresentationText(control?.dataset?.exportSourcePlural, 'records'),
        rowLabel: cleanPresentationText(control?.dataset?.exportRowLabel, 'rows'),
        rowExplanation: cleanPresentationText(control?.dataset?.exportRowExplanation, ''),
        filtered: control?.dataset?.exportFiltered !== 'false',
    });

    const explicitSourceCount = (control) => {
        const value = control?.dataset?.exportSourceCount;
        if (value === undefined || value === '') return null;

        const count = Number(value);

        return Number.isInteger(count) && count >= 0 ? count : null;
    };

    const dataTableSourceCount = (control) => {
        const tableSelector = control?.dataset?.exportCountTable;
        if (!tableSelector || typeof window.jQuery === 'undefined' || !window.jQuery.fn.dataTable) {
            return null;
        }

        let table;
        try {
            table = document.querySelector(tableSelector);
        } catch (error) {
            return null;
        }

        if (!table || !window.jQuery.fn.dataTable.isDataTable(table)) {
            return null;
        }

        const count = Number(window.jQuery(table).DataTable().page.info().recordsDisplay);

        return Number.isInteger(count) && count >= 0 ? count : null;
    };

    const sourceCountFor = (control) => explicitSourceCount(control) ?? dataTableSourceCount(control);

    const rowUnitFor = (rowLabel, totalRows) => totalRows === 1
        ? rowLabel.replace(/\brows$/i, 'row')
        : rowLabel;

    const rowProgressLabel = (processedRows, totalRows, rowLabel) => (
        `${processedRows.toLocaleString()} of ${totalRows.toLocaleString()} ${rowUnitFor(rowLabel, totalRows)}`
    );

    const progressMessageFor = (payload, stage, processedRows, totalRows, rowLabel) => {
        if (totalRows > 0 && stage === 'generating') {
            return `Processed ${rowProgressLabel(processedRows, totalRows, rowLabel)}.`;
        }

        if (totalRows > 0 && stage === 'finalizing') {
            const rowUnit = rowUnitFor(rowLabel, totalRows);
            const verb = totalRows === 1 ? 'is' : 'are';

            return `All ${totalRows.toLocaleString()} ${rowUnit} ${verb} processed. Finalizing the file.`;
        }

        return payload.message || 'The export is continuing in the background.';
    };

    const normalizeExportJobId = (value) => value === null || value === undefined
        ? ''
        : String(value);

    const isSameOriginUrl = (value) => {
        if (typeof value !== 'string' || value === '') return false;

        try {
            return new URL(value, window.location.origin).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    };

    const readPendingExports = () => {
        try {
            const records = JSON.parse(window.localStorage.getItem(pendingExportStorageKey) || '[]');

            return Array.isArray(records)
                ? records.filter((record) => record
                    && normalizeExportJobId(record.exportJobId)
                    && typeof record.statusUrl === 'string'
                    && isSameOriginUrl(record.statusUrl)
                    && (!record.cancelUrl || isSameOriginUrl(record.cancelUrl)))
                : [];
        } catch (error) {
            return [];
        }
    };

    const writePendingExports = (records) => {
        try {
            window.localStorage.setItem(pendingExportStorageKey, JSON.stringify(records.slice(-25)));
        } catch (error) {
            // The current-page polling path remains available when storage is disabled.
        }
    };

    const persistPendingExport = (state) => {
        if (!state.exportJobId || !state.statusUrl) return;

        const existingRecords = readPendingExports();
        const previousRecord = existingRecords.find((record) => (
            normalizeExportJobId(record.exportJobId) === normalizeExportJobId(state.exportJobId)
        ));
        const records = existingRecords.filter((record) => (
            normalizeExportJobId(record.exportJobId) !== normalizeExportJobId(state.exportJobId)
        ));

        records.push({
            exportJobId: normalizeExportJobId(state.exportJobId),
            statusUrl: state.statusUrl,
            startedAt: state.startedAt,
            toastId: state.toastId,
            exportsUrl: state.exportsUrl || previousRecord?.exportsUrl || null,
            cancelUrl: state.cancelUrl || previousRecord?.cancelUrl || null,
            status: state.lastStatus,
            stage: state.lastStage,
            progress: state.lastProgress,
            processedRows: state.lastProcessedRows,
            totalRows: state.lastTotalRows,
            message: state.lastMessage,
            rowLabel: state.rowLabel,
            progressDismissed: state.progressDismissed === true || previousRecord?.progressDismissed === true,
            terminalDismissed: state.terminalDismissed === true || previousRecord?.terminalDismissed === true,
            terminalToastId: state.terminalToastId || previousRecord?.terminalToastId || null,
        });
        writePendingExports(records);
    };

    const createTerminalToastId = (exportJobId) => {
        const normalizedId = normalizeExportJobId(exportJobId);

        return normalizedId
            ? `adasi-export-result-${normalizedId}`
            : createExportToastId();
    };

    const isManualDismissReason = (reason) => ['manual', 'action', 'clear'].includes(reason);

    const handleToastDismissed = (event) => {
        const toastId = normalizeExportJobId(event?.detail?.id);
        if (!toastId || !isManualDismissReason(event?.detail?.reason)) return;

        const state = Array.from(activeExports.values()).find((candidate) => (
            candidate.toastId === toastId
            || candidate.persistedToastId === toastId
            || candidate.terminalToastId === toastId
        ));
        if (!state) return;

        const terminalToast = state.terminalToastId === toastId
            || state.terminalToastShown === true
            || ['completed', 'failed', 'cancelled'].includes(state.lastStatus);

        if (terminalToast) {
            state.terminalDismissed = true;
        } else {
            state.progressDismissed = true;
        }

        state.toastId = null;
        state.rehydrateToast = false;
        persistPendingExport(state);
    };

    window.addEventListener('adasi:toast-dismissed', handleToastDismissed);

    const removePersistedExport = (exportJobId) => {
        const normalizedId = normalizeExportJobId(exportJobId);
        if (!normalizedId) return;

        writePendingExports(readPendingExports().filter((record) => (
            normalizeExportJobId(record.exportJobId) !== normalizedId
        )));
    };

    const downloadClaimKey = (exportJobId) => (
        `${downloadClaimStoragePrefix}${normalizeExportJobId(exportJobId)}`
    );

    const claimDownload = (exportJobId) => {
        const key = downloadClaimKey(exportJobId);
        if (key.endsWith(':')) return false;

        try {
            const current = JSON.parse(window.localStorage.getItem(key) || 'null');
            if (current && Number(current.claimedAt) > Date.now() - 10 * 60 * 1000) {
                return false;
            }

            window.localStorage.setItem(key, JSON.stringify({ claimedAt: Date.now() }));
            return true;
        } catch (error) {
            return true;
        }
    };

    const releaseDownloadClaim = (exportJobId) => {
        try {
            window.localStorage.removeItem(downloadClaimKey(exportJobId));
        } catch (error) {
            // Ignore storage cleanup failures.
        }
    };

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

    const progressActionsForState = (state) => exportActions(
        state.exportsUrl,
        state.cancelUrl && !state.cancelInFlight ? () => cancelExport(state) : null,
        state.cancelInFlight === true,
    );

    const cancelExport = async (state) => {
        if (!state?.cancelUrl || state.cancelInFlight || !state.isPending) return;

        state.cancelInFlight = true;
        updateExportToast(state, {
            title: 'Cancelling export',
            message: 'Requesting cancellation from the export worker...',
            progressLabel: 'Cancelling',
            actions: exportActions(state.exportsUrl, () => cancelExport(state), true),
            maxActions: 3,
            autoClose: 0,
            terminal: false,
        });

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await window.fetch(state.cancelUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                const error = new Error(await responseError(response));
                error.status = response.status;
                throw error;
            }

            const payload = await response.json();
            state.cancelInFlight = false;
            state.isPending = false;
            state.cancelled = true;
            applyProgressUpdate(state, payload);
            state.stopMonitoring?.();
        } catch (error) {
            state.cancelInFlight = false;

            notify(
                Number(error?.status) === 409 ? 'warning' : 'error',
                Number(error?.status) === 409 ? 'Export already finished' : 'Unable to cancel export',
                error instanceof Error
                    ? error.message
                    : 'The export will continue processing in the background.',
            );

            if (state.isPending && !state.progressDismissed) {
                updateExportToast(state, {
                    title: state.lastStatus === 'queued' ? 'Export queued' : 'Export in progress',
                    message: state.lastMessage,
                    progressLabel: state.lastTotalRows > 0
                        ? rowProgressLabel(state.lastProcessedRows, state.lastTotalRows, state.rowLabel)
                        : progressStageLabels[state.lastStage] || 'Processing',
                    actions: progressActionsForState(state),
                    maxActions: 3,
                    autoClose: 0,
                    terminal: false,
                });
            }
        }
    };

    const updateExportToast = (state, changes) => {
        const terminal = changes.terminal === true;

        if (terminal && state.terminalDismissed) return;
        if (!terminal && state.progressDismissed) return;

        if (terminal && !state.terminalToastId) {
            state.terminalToastId = state.toastId || createTerminalToastId(state.exportJobId);
            state.terminalToastShown = true;
        }

        if (!state.toastId && terminal) {
            state.toastId = showOrQueueToast({
                ...changes,
                id: state.terminalToastId,
                actions: changes.actions || exportActions(state.exportsUrl),
                maxActions: changes.maxActions ?? 2,
                type: changes.type || 'info',
            });
            state.rehydrateToast = false;
            return;
        }

        if (!state.toastId && state.rehydrateToast && window.AdasiToast && !state.progressDismissed) {
            state.toastId = window.AdasiToast.progress({
                id: state.persistedToastId || createExportToastId(),
                title: changes.title || 'Export in progress',
                message: changes.message || 'The export is continuing in the background.',
                progress: changes.progress,
                indeterminate: changes.indeterminate === true,
                progressLabel: changes.progressLabel || 'Processing',
                actions: changes.actions || progressActionsForState(state),
                maxActions: changes.maxActions ?? 3,
            });
            state.rehydrateToast = false;
        }

        if (state.toastId && window.AdasiToast?.update(state.toastId, changes)) return;
        if (terminal) {
            state.toastId = showOrQueueToast({
                ...changes,
                id: state.terminalToastId || createTerminalToastId(state.exportJobId),
                actions: changes.actions || exportActions(state.exportsUrl),
                maxActions: changes.maxActions ?? 2,
                type: changes.type || 'info',
            });
            return;
        }
        if (state.silent && !changes.forceNotify) return;
        notify(changes.type || 'info', changes.title || 'Export update', changes.message || '');
    };

    const findActiveExport = (exportJobId) => {
        const normalizedId = normalizeExportJobId(exportJobId);
        if (!normalizedId) return null;

        return Array.from(activeExports.values())
            .find((state) => normalizeExportJobId(state.exportJobId) === normalizedId) || null;
    };

    const applyProgressUpdate = (state, payload = {}) => {
        if (!state || !['queued', 'processing', 'completed', 'failed', 'cancelled'].includes(payload.status)) {
            return;
        }

        if (state.cancelled && payload.status !== 'cancelled') {
            return;
        }

        const stage = typeof payload.stage === 'string' && progressStageLabels[payload.stage]
            ? payload.stage
            : payload.status;
        const numericProgress = payload.progress === null || payload.progress === undefined
            ? null
            : Number(payload.progress);
        const progress = Number.isFinite(numericProgress)
            ? Math.min(100, Math.max(0, Math.round(numericProgress)))
            : null;
        const processedRows = Math.max(0, Number(payload.processed_rows) || 0);
        const totalRows = Math.max(0, Number(payload.total_rows) || 0);
        const rowLabel = cleanPresentationText(state.rowLabel, 'rows');
        const terminal = ['completed', 'failed', 'cancelled'].includes(payload.status);
        const message = progressMessageFor(payload, stage, processedRows, totalRows, rowLabel);
        const signature = [
            payload.status,
            stage,
            progress,
            processedRows,
            totalRows,
            rowLabel,
            message,
        ].join(':');

        if (state.lastProgressSignature === signature
            && !(state.rehydrateToast && window.AdasiToast)) {
            return;
        }

        state.lastStatus = payload.status;
        state.lastStage = stage;
        state.lastProgress = progress;
        state.lastProcessedRows = processedRows;
        state.lastTotalRows = totalRows;
        state.lastMessage = message;
        state.cancelUrl = typeof payload.cancel_url === 'string' && payload.cancel_url
            ? payload.cancel_url
            : state.cancelUrl;
        state.isPending = ['queued', 'processing'].includes(payload.status);
        if (payload.status === 'cancelled') state.cancelled = true;
        state.lastProgressSignature = signature;
        if (terminal && !state.terminalToastId) {
            state.terminalToastId = state.toastId || createTerminalToastId(state.exportJobId);
            state.terminalToastShown = true;
        }

        persistPendingExport(state);

        const changes = {
            title: payload.status === 'completed'
                ? 'Export completed'
                : payload.status === 'failed'
                    ? 'Export could not be completed'
                    : payload.status === 'cancelled'
                        ? 'Export cancelled'
                    : payload.status === 'queued' ? 'Export queued' : 'Export in progress',
            message,
            progressLabel: totalRows > 0 && ['generating', 'finalizing'].includes(stage)
                ? rowProgressLabel(processedRows, totalRows, rowLabel)
                : progressStageLabels[stage] || 'Processing',
            actions: terminal ? exportActions(state.exportsUrl) : progressActionsForState(state),
            maxActions: terminal ? 2 : 3,
            autoClose: 0,
            terminal,
        };

        if (progress === null && !terminal) {
            changes.indeterminate = true;
        } else {
            if (progress !== null) changes.progress = progress;
        }

        if (payload.status === 'completed') changes.type = 'success';
        if (payload.status === 'failed') changes.type = 'error';
        if (payload.status === 'cancelled') changes.type = 'warning';

        updateExportToast(state, changes);
    };

    const handleProgress = (payload = {}) => {
        const state = findActiveExport(payload.export_job_id);
        if (!state) return;

        applyProgressUpdate(state, payload);
        if (payload.status === 'cancelled') {
            state.cancelled = true;
            state.stopMonitoring?.();
        }
    };

    const confirmExport = (control) => {
        const presentation = exportPresentationFor(control);
        const sourceCount = sourceCountFor(control);
        const sourceLabel = sourceCount === 1
            ? presentation.sourceSingular
            : presentation.sourcePlural;
        const scope = sourceCount === null
            ? `Export ${presentation.sourcePlural}${presentation.filtered ? ' matching the current filters' : ''}?`
            : `Export ${sourceCount.toLocaleString()} ${sourceLabel}${presentation.filtered ? ' matching the current filters' : ''}?`;
        const explanation = presentation.rowExplanation
            || `Progress will track ${presentation.rowLabel}.`;
        const options = {
            title: 'Confirm Export',
            text: `${scope} ${explanation}`,
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
            state.monitoringStopped = true;
            activeExports.delete(state.requestUrl);
            removePersistedExport(state.exportJobId);
            releaseTrackedExportJob(state.exportJobId, retainNotificationSuppression);
            setBusy(state.control, false);
        };
        state.stopMonitoring = finish;

        const poll = async () => {
            if (state.monitoringStopped) return;

            if (Date.now() - state.startedAt >= pollTimeoutMs) {
                finish();
                updateExportToast(state, {
                    forceNotify: true,
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
                    const error = new Error(await responseError(response));
                    error.status = response.status;
                    throw error;
                }

                const payload = await response.json();
                consecutiveFailures = 0;
                if (state.monitoringStopped) return;
                applyProgressUpdate(state, payload);

                if (payload.status === 'cancelled') {
                    state.cancelled = true;
                    finish();
                    return;
                }

                if (payload.status === 'completed') {
                    if (payload.download_url) {
                        const downloadJobId = payload.id || state.exportJobId;
                        if (!claimDownload(downloadJobId)) {
                            finish(true);
                            return;
                        }

                        try {
                            await triggerDownload(
                                payload.download_url,
                                payload.file_name,
                                downloadJobId
                            );
                            finish(true);
                            updateExportToast(state, {
                                forceNotify: true,
                                type: 'success',
                                title: 'Export completed',
                                message: 'The Excel file was downloaded automatically.',
                                progress: 100,
                                progressLabel: 'Completed',
                                autoClose: 4000,
                                terminal: true,
                            });
                        } catch (error) {
                            releaseDownloadClaim(downloadJobId);
                            finish(true);
                            updateExportToast(state, {
                                forceNotify: true,
                                type: 'error',
                                title: 'Automatic download failed',
                                message: error instanceof Error ? error.message : 'Download the file from Export History.',
                                autoClose: 0,
                                terminal: true,
                            });
                        }
                    } else {
                        finish(true);
                        updateExportToast(state, {
                            forceNotify: true,
                            type: 'error',
                            title: 'Export file is unavailable',
                            message: payload.message || 'The export file cannot be downloaded.',
                            autoClose: 0,
                            terminal: true,
                        });
                    }
                    return;
                }

                if (payload.status === 'failed') {
                    finish(true);
                    updateExportToast(state, {
                        forceNotify: true,
                        type: 'error',
                        title: 'Export could not be completed',
                        message: payload.message || 'Try the export again or review Export History.',
                        autoClose: 0,
                        terminal: true,
                    });
                    return;
                }

                if (payload.status !== 'queued' && payload.status !== 'processing') {
                    finish();
                    updateExportToast(state, {
                        forceNotify: true,
                        type: 'error',
                        title: 'Export status is unavailable',
                        message: 'Review the job in Export History.',
                        autoClose: 0,
                        terminal: true,
                    });
                    return;
                }

            } catch (error) {
                if ([401, 403, 404, 410].includes(Number(error?.status))) {
                    finish();
                    updateExportToast(state, {
                        forceNotify: true,
                        type: 'warning',
                        title: error.status === 401 ? 'Session expired' : 'Export is unavailable',
                        message: error instanceof Error
                            ? error.message
                            : 'Review the job in Export History.',
                        autoClose: 0,
                        terminal: true,
                    });
                    return;
                }

                consecutiveFailures += 1;

                if (consecutiveFailures >= maxConsecutivePollFailures) {
                    finish();
                    updateExportToast(state, {
                        forceNotify: true,
                        type: 'warning',
                        title: 'Export status could not be refreshed',
                        message: 'The file will remain available in Export History after processing.',
                        autoClose: 0,
                        terminal: true,
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

        const toastId = createExportToastId();
        const presentation = exportPresentationFor(control);
        const state = {
            control,
            requestUrl,
            exportJobId: null,
            statusUrl: null,
            cancelUrl: null,
            toastId: createStartingToast(toastId),
            exportsUrl: null,
            silent: false,
            progressDismissed: false,
            terminalDismissed: false,
            terminalToastId: null,
            terminalToastShown: false,
            cancelInFlight: false,
            cancelled: false,
            monitoringStopped: false,
            isPending: false,
            lastStatus: 'starting',
            lastStage: 'starting',
            lastProgress: null,
            lastProcessedRows: 0,
            lastTotalRows: 0,
            lastMessage: 'Submitting the export request...',
            rowLabel: presentation.rowLabel,
            lastProgressSignature: null,
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
            state.cancelUrl = payload.cancel_url || null;
            state.exportsUrl = payload.exports_url || null;
            state.lastStatus = 'queued';
            state.isPending = true;
            trackExportJob(state.exportJobId);
            persistPendingExport(state);

            applyProgressUpdate(state, {
                status: 'queued',
                stage: 'queued',
                progress: 0,
                processed_rows: 0,
                total_rows: 0,
                message: payload.message || 'The export will continue in the background.',
            });
            updateExportToast(state, {
                actions: progressActionsForState(state),
                maxActions: 3,
            });

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
                terminal: true,
            });
        }
    };

    const resumePersistedExports = () => {
        readPendingExports().forEach((record) => {
            const exportJobId = normalizeExportJobId(record.exportJobId);
            const requestUrl = `__persisted_export__:${exportJobId}`;
            const toastId = typeof record.toastId === 'string' && record.toastId
                ? record.toastId
                : `adasi-export-toast-${exportJobId}`;

            if (!exportJobId || findActiveExport(exportJobId) || activeExports.has(requestUrl)) {
                return;
            }

            const restoredStatus = record.status || 'queued';
            const restoredStage = record.stage || 'queued';
            const restoredProgress = record.progress === null || record.progress === undefined
                ? null
                : Math.min(100, Math.max(0, Number(record.progress) || 0));
            const restoredProcessedRows = Math.max(0, Number(record.processedRows) || 0);
            const restoredTotalRows = Math.max(0, Number(record.totalRows) || 0);
            const restoredRowLabel = cleanPresentationText(record.rowLabel, 'rows');
            const restoredExportsUrl = typeof record.exportsUrl === 'string' && record.exportsUrl
                ? record.exportsUrl
                : null;
            const restoredCancelUrl = isSameOriginUrl(record.cancelUrl)
                ? record.cancelUrl
                : null;
            const restoredProgressDismissed = record.progressDismissed === true;
            const restoredTerminalDismissed = record.terminalDismissed === true;
            const restoredTerminalStatus = ['completed', 'failed', 'cancelled'].includes(restoredStatus);
            const restoredMessage = progressMessageFor(
                { message: record.message },
                restoredStage,
                restoredProcessedRows,
                restoredTotalRows,
                restoredRowLabel,
            );
            const restoredProgressLabel = restoredTotalRows > 0 && ['generating', 'finalizing'].includes(restoredStage)
                ? rowProgressLabel(restoredProcessedRows, restoredTotalRows, restoredRowLabel)
                : progressStageLabels[restoredStage] || 'Processing';

            const state = {
                control: null,
                requestUrl,
                exportJobId,
                statusUrl: record.statusUrl,
                cancelUrl: restoredCancelUrl,
                toastId: null,
                exportsUrl: restoredExportsUrl,
                silent: true,
                progressDismissed: restoredProgressDismissed,
                terminalDismissed: restoredTerminalDismissed,
                terminalToastId: typeof record.terminalToastId === 'string' && record.terminalToastId
                    ? record.terminalToastId
                    : restoredTerminalStatus ? createTerminalToastId(exportJobId) : null,
                terminalToastShown: false,
                cancelInFlight: false,
                cancelled: restoredStatus === 'cancelled',
                monitoringStopped: false,
                isPending: ['queued', 'processing'].includes(restoredStatus),
                rehydrateToast: !restoredProgressDismissed && !restoredTerminalStatus,
                persistedToastId: toastId,
                lastStatus: restoredStatus,
                lastStage: restoredStage,
                lastProgress: restoredProgress,
                lastProcessedRows: restoredProcessedRows,
                lastTotalRows: restoredTotalRows,
                lastMessage: restoredMessage,
                rowLabel: restoredRowLabel,
                lastProgressSignature: null,
                startedAt: Number(record.startedAt) || Date.now(),
            };

            if (state.rehydrateToast) {
                state.toastId = showOrQueueProgressToast({
                    id: toastId,
                    title: restoredStatus === 'queued' ? 'Export queued' : 'Export in progress',
                    message: restoredMessage,
                    progress: restoredProgress,
                    progressLabel: restoredProgressLabel,
                    actions: progressActionsForState(state),
                    maxActions: 3,
                    restored: true,
                });
            }

            activeExports.set(requestUrl, state);
            trackExportJob(exportJobId);
            applyProgressUpdate(state, {
                status: restoredStatus,
                stage: restoredStage,
                progress: restoredProgress,
                processed_rows: restoredProcessedRows,
                total_rows: restoredTotalRows,
                cancel_url: restoredCancelUrl,
                message: restoredMessage,
            });
            pollStatus(state);
        });
    };

    window.AdasiAsyncExport = Object.freeze({
        handleProgress,
        isTrackingNotification,
    });

    resumePersistedExports();

    const confirmAndStart = async (control) => {
        if (confirmationOpen) {
            return;
        }

        confirmationOpen = true;

        try {
            if (await confirmExport(control)) {
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
