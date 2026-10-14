import { t as globalT } from './i18n.js';

let importMessages = {};
try { importMessages = JSON.parse(document.getElementById('local-import-i18n')?.textContent || '{}'); } catch { /* Use the shared dictionary fallback. */ }
const t = (key, replacements = {}) => typeof importMessages[key] === 'string'
    ? importMessages[key].replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (placeholder, name) => Object.hasOwn(replacements, name) ? String(replacements[name]) : placeholder)
    : globalT(key, replacements);

const active = new Set(['QUEUED', 'READING', 'VALIDATING', 'IMPORTING']);
const slotStates = new Set([...active, 'READY']);

function safeUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin && !url.username && !url.password ? url : null;
    } catch { return null; }
}

function resultAction(body) {
    return { label: t('js.local_import.view_results'), onClick: () => {
        const url = body?.results_url && safeUrl(body.results_url);
        if (url) window.location.assign(url.href);
        else window.location.reload();
    } };
}
// Toast hilang saat halaman di-reload; pesan sukses dititipkan sekali pakai di tab ini.
const TOAST_KEY = 'adasi:local-import-toast';
const TOAST_TTL_MS = 60000;

const reloadAction = () => ({ label: t('js.local_import.reload'), onClick: () => window.location.reload() });

function queueToastAndReload(message) {
    try {
        window.sessionStorage.setItem(TOAST_KEY, JSON.stringify({ message, expires: Date.now() + TOAST_TTL_MS }));
    } catch {
        // Tanpa storage pesan akan hilang saat reload; biarkan user memuat ulang sendiri.
        window.AdasiToast?.success(message, { actions: [reloadAction()] });

        return;
    }
    window.location.reload();
}

function showPendingToast() {
    let payload = null;
    try {
        const raw = window.sessionStorage?.getItem(TOAST_KEY);
        if (raw === null || raw === undefined) return;
        window.sessionStorage.removeItem(TOAST_KEY);
        payload = JSON.parse(raw);
    } catch {
        return;
    }
    if (typeof payload?.message !== 'string' || !(payload.expires > Date.now())) return;
    if (window.AdasiToast) window.AdasiToast.success(payload.message);
    else (window.__adasiToastQueue ??= []).push({ type: 'success', message: payload.message });
}

async function json(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', ...options.headers } });
    const body = await response.json();
    if (!response.ok) throw new Error(body.errors ? Object.values(body.errors).flat().join(' ') : body.message);
    return body;
}

function progressMeter(body, labelId) {
    const data = body.progress ?? { mode: ['QUEUED', 'READING', 'VALIDATING', 'IMPORTING'].includes(body.status) ? 'indeterminate' : 'none' };
    const track = document.createElement('div');
    track.className = 'local-import-progress-track';
    track.hidden = data.mode === 'none';
    track.setAttribute('role', 'progressbar');
    track.setAttribute('aria-labelledby', labelId);
    if (data.mode === 'determinate') {
        track.setAttribute('aria-valuemin', '0');
        track.setAttribute('aria-valuemax', '100');
        track.setAttribute('aria-valuenow', String(data.percent));
    }
    const fill = document.createElement('span');
    fill.className = data.mode === 'indeterminate' && !data.stale ? 'local-import-progress-fill is-indeterminate' : 'local-import-progress-fill';
    (fill.style ??= {}).width = `${data.mode === 'determinate' ? data.percent : 35}%`;
    track.append(fill);
    return track;
}

function progressDetails(body, kind, number) {
    const data = body.progress;
    if (data?.total > 0 && ['SAVING', 'FINALIZING', 'PREPARING', 'RETRYING'].includes(data.phase)) {
        return t('js.local_import.record_progress', { current: number(data.current), total: number(data.total), kind })
            + (data.percent === null ? '' : ` · ${number(data.percent)}%`);
    }
    return ['READING', 'VALIDATING'].includes(body.status)
        ? t('js.local_import.progress', { count: number(body.processed_rows ?? 0) }) : '';
}

export function bootLocalProcurementImports(root = document) {
    showPendingToast();
    root.querySelectorAll('[data-local-import]').forEach(config => {
        if (config.dataset.initialized) return;
        config.dataset.initialized = 'true';
        const kind = config.dataset.localImport;
        if (!['PO', 'GR'].includes(kind)) return;
        const name = kind === 'PO' ? 'Po' : 'Gr';
        const modal = document.getElementById(config.dataset.modalId);
        const find = suffix => document.getElementById(`local${name}Import${suffix}`);
        const parse = document.getElementById(`btnParseLocal${name}Import`);
        const confirm = document.getElementById(`btnConfirmLocal${name}Import`);
        const revalidate = document.getElementById(`btnRevalidateLocal${name}Import`);
        const replace = document.getElementById(`btnReplaceLocal${name}Import`);
        const form = find('ConfirmForm');
        if (!modal || !parse || !form) return;
        modal.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const fields = [...modal.querySelectorAll('button, [href], input:not([type="hidden"]), select, textarea, summary, [tabindex]')]
                .filter(field => !field.disabled && field.tabIndex >= 0 && field.getClientRects().length);
            const first = fields[0], lastField = fields.at(-1);
            if (first && ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === lastField))) {
                event.preventDefault();
                (event.shiftKey ? lastField : first).focus();
            }
        });
        const confirmAction = form.action;
        const confirmLabel = confirm.querySelector('span');
        const confirmText = confirmLabel?.textContent ?? '';
        const progress = document.createElement('div');
        // Tanpa pesan, baris status tidak boleh menyisakan celah di atas panel.
        progress.className = 'tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface';
        progress.setAttribute('role', 'status');
        progress.setAttribute('aria-live', 'polite');
        progress.id = `local${name}ImportProgressLabel`;
        const statusPanel = document.createElement('section');
        statusPanel.className = 'tw-w-full tw-min-w-0 tw-rounded-ui-sm tw-border tw-border-solid tw-border-outline-variant tw-bg-surface-container tw-p-3 tw-space-y-1.5';
        statusPanel.hidden = true;
        const filename = document.createElement('div');
        filename.className = 'tw-text-ui-xs tw-text-on-surface-variant tw-break-all';
        const countLabel = document.createElement('div');
        countLabel.className = 'tw-text-ui-xs tw-text-on-surface-variant tw-tabular-nums';
        const meter = document.createElement('div');
        const helper = document.createElement('p');
        helper.className = 'tw-m-0 tw-text-ui-xs tw-text-on-surface-variant tw-text-pretty';
        const retry = document.createElement('button');
        retry.type = 'button'; retry.className = 'btn btn-outline-secondary btn-sm'; retry.hidden = true;
        retry.textContent = t('js.local_import.retry_status');
        retry.addEventListener('click', () => poll());
        const announcer = document.createElement('div');
        announcer.className = 'visually-hidden'; announcer.setAttribute('aria-live', 'polite');
        statusPanel.append(filename, progress, countLabel, meter, helper, retry, announcer);
        (modal.querySelector('.modal-header') ?? modal.querySelector('.modal-body')).append(statusPanel);
        const warnings = document.createElement('ul');
        modal.querySelector('.modal-body').append(warnings);
        const controls = document.createElement('div');
        controls.className = 'tw-flex tw-items-center tw-gap-2 tw-py-2';
        const previous = document.createElement('button');
        const next = document.createElement('button');
        const cancel = document.createElement('button');
        const reload = document.createElement('button');
        const pageLabel = document.createElement('span');
        [previous, next, cancel, reload].forEach(button => { button.type = 'button'; button.className = 'btn btn-outline-secondary btn-sm'; });
        previous.textContent = config.dataset.previous;
        next.textContent = config.dataset.next;
        cancel.textContent = config.dataset.cancel;
        reload.textContent = t('js.local_import.view_results');
        reload.hidden = true;
        reload.addEventListener('click', () => { if (last?.status === 'COMPLETED') resultAction(last).onClick(); });
        controls.append(previous, pageLabel, next);
        statusPanel.append(cancel);
        cancel.hidden = true;
        modal.querySelector('.modal-body').append(controls);
        // Footer selalu terlihat; ujung body tertutup tabel pratinjau yang panjang.
        (modal.querySelector('.modal-footer') ?? controls).prepend(reload);
        controls.hidden = true;
        let statusUrl = null;
        let last = null;
        let page = 1;
        let errorPage = 1;
        let requestInFlight = false;
        let timer = null;
        let modalGeneration = 0;
        let pollSequence = 0;
        let awaitingCommit = false;
        let announcedMilestone = '';
        const csrf = () => form.querySelector('[name="_token"]').value;
        const tell = error => { statusPanel.hidden = false; progress.textContent = error.message || t('js.local_import.error'); };
        // Panel "Import belum selesai" dirender server (_import_recovery.blade.php); JS hanya mengisi baris.
        const recoveryPanel = modal.querySelector('[data-import-recovery]');
        const recoveryList = modal.querySelector('[data-recovery-list]');
        const processingList = modal.querySelector('[data-recovery-processing-list]');
        const followUpList = modal.querySelector('[data-recovery-follow-up-list]');
        const processingGroup = modal.querySelector('[data-recovery-processing-group]');
        const followUpGroup = modal.querySelector('[data-recovery-follow-up-group]');
        const recoverySlots = modal.querySelector('[data-recovery-slots]');
        const recoveryError = modal.querySelector('[data-recovery-error]');
        const recoveryAnnouncer = modal.querySelector('[data-recovery-announcer]');
        const refreshImports = modal.querySelector('[data-recovery-refresh]');
        const slotLimit = Number(recoveryPanel?.dataset.slotLimit) || 2;
        const RECOVERY_REFRESH_MS = 3000;
        // Chip/tombol memakai komponen design system dari <template> agar ikon Lucide tetap konsisten.
        const fromTemplate = (selector, fallbackTag) => modal.querySelector(selector)?.content?.firstElementChild?.cloneNode(true)
            ?? Object.assign(document.createElement(fallbackTag), fallbackTag === 'button' ? { type: 'button' } : {});
        const fillSlot = (element, slot, text) => { (element.querySelector?.(slot) ?? element).textContent = text; return element; };
        const chipKey = status => ({ READY: 'ready', QUEUED: 'queued', IMPORTING: 'saving', VALIDATION_FAILED: 'invalid', FAILED: 'invalid' })[status] ?? 'processing';
        let recoveryRequest = 0;
        let recoveryTimer = null;
        let knownStatuses = new Map();
        let recoveryButtons = new Map();
        function openImport(entry) {
            if (requestInFlight) return;
            if (entry.kind === kind) return resumeImport(entry);
            const target = document.getElementById(entry.kind === 'PO' ? 'localPoImportModal' : 'localGrImportModal');
            if (!target || !window.bootstrap?.Modal) return;
            requestInFlight = true;
            target.addEventListener('shown.bs.modal', () => target.dispatchEvent(new CustomEvent('adasi:resume-import', { detail: entry })), { once: true });
            modal.addEventListener('hidden.bs.modal', () => window.bootstrap.Modal.getOrCreateInstance(target).show(), { once: true });
            window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        }
        async function cancelImport(entry, buttons, trigger) {
            if (requestInFlight) return;
            const generation = modalGeneration;
            // Pembatalan tidak dapat diurungkan: sebut file dan akibatnya, fokus default ke opsi aman.
            const options = { title: t('js.local_import.cancel_title'), text: t('js.local_import.cancel_text', { file: entry.filename }),
                confirmText: t('js.local_import.cancel_confirm'), cancelText: t('js.local_import.cancel_keep') };
            const decision = window.AdasiAlert?.confirmDanger
                ? await window.AdasiAlert.confirmDanger(options) : { isConfirmed: window.confirm(`${options.title}\n\n${options.text}`) };
            if (generation !== modalGeneration) return;
            // Dialog menutup tanpa mengembalikan fokus (jatuh ke <body>); kembalikan ke pemicunya.
            if (!decision?.isConfirmed || requestInFlight) return trigger?.focus?.();
            requestInFlight = true;
            buttons.forEach(button => { button.disabled = true; });
            parse.disabled = confirm.disabled = true;
            try {
                await json(`${entry.status_url}/cancel`, { method: 'POST', body: new URLSearchParams({ _token: csrf() }) });
                if (generation !== modalGeneration) return;
                if (statusUrl === entry.status_url) resetSession({ clearFile: false });
                await loadActiveImports();
                // Baris pemicu sudah hilang: pindahkan fokus ke kontrol yang tetap ada.
                (recoveryPanel && !recoveryPanel.hidden && refreshImports ? refreshImports : modal).focus?.();
            } catch (error) {
                if (generation === modalGeneration) showRecoveryError(error);
            } finally {
                if (generation === modalGeneration) {
                    requestInFlight = false;
                    if (last) render(last);
                    else parse.disabled = false;
                    buttons.forEach(button => { button.disabled = false; });
                }
            }
        }
        function recoveryRow(entry, index) {
            const item = document.createElement('li');
            // Lebar border default browser "medium": nolkan dulu, lalu hanya garis atas di antara baris.
            item.className = 'tw-flex tw-flex-col lg:tw-flex-row tw-items-start lg:tw-items-center tw-gap-x-3 tw-gap-y-2 tw-py-2.5 tw-border-0 tw-border-t tw-border-solid tw-border-outline-variant first:tw-border-t-0';
            item.dataset.importUrl = entry.status_url;
            const noNew = entry.status === 'VALIDATION_FAILED' && entry.summary?.invalid === 0
                && entry.summary?.[entry.kind === 'PO' ? 'new_po' : 'new_gr'] === 0
                && (entry.kind !== 'PO' || (entry.summary?.po_pr_filled ?? 0) === 0)
                && entry.summary?.[entry.kind === 'PO' ? 'existing_po' : 'existing_gr'] > 0;
            const chip = fillSlot(fromTemplate(`[data-recovery-chip="${chipKey(entry.status)}"]`, 'span'), '[data-chip-label]', t(noNew ? 'js.local_import.no_new_title' : `js.local_import.status.${entry.status}`));
            // Kolom status selebar tetap agar nama file antarbaris sejajar dan mudah dipindai.
            const status = document.createElement('div');
            status.className = 'tw-shrink-0 lg:tw-w-56';
            status.append(chip);
            const text = document.createElement('div');
            text.className = 'tw-min-w-0 tw-flex-1';
            const file = document.createElement('p');
            file.id = `local${name}ImportRecoveryFile${index}`;
            file.className = 'tw-m-0 tw-break-all tw-text-ui-sm tw-font-medium tw-text-on-surface';
            file.textContent = entry.filename;
            const meta = document.createElement('p');
            meta.className = 'tw-m-0 tw-text-ui-xs tw-text-on-surface-variant tw-tabular-nums';
            meta.textContent = t('js.local_import.recovery_meta', { kind: entry.kind, time: entry.created_at ?? '—', rows: number(entry.processed_rows ?? 0) });
            chip.id = `local${name}ImportRecoveryStatus${index}`;
            const detail = document.createElement('div');
            detail.className = 'tw-text-ui-xs tw-tabular-nums tw-text-on-surface-variant tw-mt-1';
            detail.textContent = progressDetails(entry, entry.kind, number);
            text.append(file, meta);
            text.append(progressMeter(entry, chip.id), detail);
            const actions = document.createElement('div');
            actions.className = 'lg:tw-ms-auto tw-flex tw-shrink-0 tw-flex-wrap tw-items-center tw-gap-3';
            const primary = fillSlot(fromTemplate('[data-recovery-action]', 'button'), '[data-button-label]',
                t(active.has(entry.status) ? 'js.local_import.recovery_progress' : 'js.local_import.recovery_review'));
            // Nama aksi tetap label yang terlihat; file ditautkan sebagai deskripsi (WCAG 2.5.3).
            primary.setAttribute('aria-describedby', file.id);
            primary.addEventListener('click', () => openImport(entry));
            recoveryButtons.set(`${entry.status_url}|resume`, primary);
            const buttons = [primary];
            let secondary;
            if (entry.status === 'IMPORTING') {
                secondary = document.createElement('span');
                secondary.className = 'tw-text-ui-xs tw-text-on-surface-variant';
                secondary.textContent = t('js.local_import.recovery_saving');
            } else {
                secondary = fillSlot(fromTemplate('[data-recovery-cancel]', 'button'), '[data-button-label]', config.dataset.cancel);
                secondary.setAttribute('aria-describedby', file.id);
                secondary.addEventListener('click', () => cancelImport(entry, buttons, secondary));
                recoveryButtons.set(`${entry.status_url}|cancel`, secondary);
                buttons.push(secondary);
                if (entry.status_url === statusUrl) secondary.hidden = true;
            }
            actions.append(primary, secondary);
            item.append(status, text, actions);

            return item;
        }
        function renderRecovery(imports) {
            if (!recoveryList) return;
            const focusedKey = [...recoveryButtons].find(([, button]) => button === document.activeElement)?.[0];
            const ready = imports.filter(entry => entry.status === 'READY' && knownStatuses.has(entry.status_url) && knownStatuses.get(entry.status_url) !== 'READY');
            recoveryButtons = new Map();
            if (processingList?.replaceChildren && followUpList?.replaceChildren) {
                const processing = imports.filter(entry => active.has(entry.status));
                const followUp = imports.filter(entry => !active.has(entry.status));
                processingList.replaceChildren(...processing.map(entry => recoveryRow(entry, imports.indexOf(entry))));
                followUpList.replaceChildren(...followUp.map(entry => recoveryRow(entry, imports.indexOf(entry))));
                processingGroup.hidden = processing.length === 0;
                followUpGroup.hidden = followUp.length === 0;
            } else recoveryList.replaceChildren(...imports.map(recoveryRow));
            knownStatuses = new Map(imports.map(entry => [entry.status_url, entry.status]));
            if (recoveryPanel) recoveryPanel.hidden = imports.length === 0 && (recoveryError?.hidden ?? true);
            if (recoverySlots) recoverySlots.textContent = imports.length ? t('js.local_import.recovery_slots', { used: number(imports.filter(entry => slotStates.has(entry.status)).length), total: number(slotLimit) }) : '';
            // Ringkasan, bukan seluruh daftar, agar pembaca layar tidak mengulang isi tiap refresh.
            if (ready.length && recoveryAnnouncer) recoveryAnnouncer.textContent = ready.map(entry => t('js.local_import.recovery_ready', { file: entry.filename })).join(' ');
            if (focusedKey) (recoveryButtons.get(focusedKey) ?? modal).focus?.();
        }
        function showRecoveryError(error) {
            if (!recoveryError) return;
            recoveryError.textContent = error?.message || t('js.local_import.error');
            recoveryError.hidden = false;
            if (recoveryPanel) recoveryPanel.hidden = false;
        }
        async function loadActiveImports() {
            if (!recoveryList) return;
            const generation = modalGeneration;
            const request = ++recoveryRequest;
            const current = () => generation === modalGeneration && request === recoveryRequest;
            clearTimeout(recoveryTimer);
            if (refreshImports) refreshImports.disabled = true;
            try {
                const separator = config.dataset.importIndex.includes('?') ? '&' : '?';
                const imports = await json(`${config.dataset.importIndex}${separator}active=1&include_recoverable=1`);
                if (!current()) return;
                if (recoveryError) { recoveryError.hidden = true; recoveryError.textContent = ''; }
                renderRecovery(imports);
                // Perbarui sendiri hanya selama masih ada import yang diproses; READY menunggu aksi user.
                if (!document.hidden && imports.some(entry => entry.status !== 'READY' && active.has(entry.status))) {
                    recoveryTimer = setTimeout(() => (current() ? loadActiveImports() : undefined), RECOVERY_REFRESH_MS);
                }
            } catch (error) {
                if (current()) showRecoveryError(error);
            } finally {
                if (current() && refreshImports) refreshImports.disabled = false;
            }
        }
        async function resumeImport(entry) {
            if (requestInFlight || entry.kind !== kind) return;
            if (watcher?.url === entry.status_url) stopWatcher();
            resetSession();
            const generation = modalGeneration;
            statusUrl = entry.status_url;
            requestInFlight = true;
            parse.disabled = true;
            await poll();
            if (generation !== modalGeneration) return;
            requestInFlight = false;
            parse.disabled = Boolean(statusUrl) && (!last || active.has(last.status));
            if (last) render(last);
            await loadActiveImports();
        }
        refreshImports?.addEventListener('click', async () => { await loadActiveImports(); if (statusUrl) await poll(); });
        modal.addEventListener('adasi:resume-import', event => resumeImport(event.detail));
        const number = value => window.AdasiPreferences?.displayNumber?.(value) ?? String(value ?? 0);
        const newCount = body => {
            const summary = body?.preview?.summary;
            const count = summary?.[kind === 'PO' ? 'new_po' : 'new_gr'];
            if (kind !== 'PO' || !Number.isInteger(count)) return count;
            const filled = summary?.po_pr_filled ?? 0;
            return Number.isInteger(filled) && filled >= 0 && count >= 0 ? count + filled : null;
        };
        const canConfirm = body => body?.status === 'READY' && Number.isInteger(newCount(body)) && newCount(body) > 0
            && Number(body.preview?.summary?.invalid ?? 0) === 0 && Number(body.errors_total ?? 0) === 0;
        const textCell = (row, value) => {
            const cell = document.createElement('td');
            cell.textContent = String(value ?? '—');
            row.append(cell);
        };
        function renderRows(rows) {
            const body = find('PreviewBody');
            body.replaceChildren();
            rows.slice(0, 100).forEach(value => {
                const row = document.createElement('tr');
                const fields = kind === 'PO' ? ['_row', 'po_number', 'pr_number', 'supplier_name', 'po_date', 'po_amount', 'action']
                    : ['_row', 'gr_number', 'po_number', 'description', 'gr_date', 'qty', 'uom', 'source_rows_count', 'action'];
                fields.forEach(field => {
                    const content = field === 'action' ? value.action_label ?? value.action
                        : field === 'pr_number' ? value.pr_number || t('js.local_import.no_pr') : value[field];
                    textCell(row, content);
                    if (field === 'pr_number') {
                        const cell = row.children[row.children.length - 1];
                        cell.setAttribute('style', 'max-width: 16rem; overflow-wrap: anywhere;');
                    }
                });
                body.append(row);
            });
            find('PreviewPanel')?.classList.remove('d-none');
        }
        function renderErrors(errors) {
            const list = find('ErrorsList');
            list.replaceChildren();
            errors.forEach(error => {
                const item = document.createElement('li');
                const column = ['po_number','pr_number','gr_number','qty','uom','gr_date','po_date','po_amount','supplier_name'].includes(error.column) ? error.column : 'other';
                item.textContent = t('js.local_import.row_error', { row: error.row ?? '—', column: t(`js.local_import.column.${column}`), message: error.message });
                const hint = document.createElement('p');
                hint.className = 'tw-mt-1 tw-mb-1 tw-text-ui-xs';
                hint.textContent = t(['PO_NOT_FOUND', 'PO_CANCELLED', 'PO_CLOSED', 'GR_CANCELLED', 'IDENTITY_CONFLICT'].includes(error.code)
                    ? `js.local_import.fix_${error.code}` : 'js.local_import.generic_fix');
                item.append(hint);
                const target = error.action?.url && safeUrl(error.action.url);
                if (target) {
                    const link = document.createElement('a');
                    link.href = target.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
                    link.className = 'ui-focus-ring tw-font-semibold';
                    link.textContent = `${error.action.label} (${t('js.local_import.new_tab')})`;
                    item.append(link);
                }
                list.append(item);
            });
            find('ErrorsPanel')?.classList.toggle('d-none', errors.length === 0);
        }
        function render(body) {
            const previousStatus = last?.status;
            last = body;
            statusPanel.hidden = false;
            retry.hidden = true;
            filename.textContent = body.filename ?? '';
            const phase = body.progress?.phase ?? body.status;
            const phaseKey = `js.local_import.phase.${phase}`;
            let statusText = ['WAITING_SAVE', 'PREPARING', 'SAVING', 'FINALIZING', 'RETRYING', 'AWAITING_PROGRESS'].includes(phase)
                ? t(phaseKey) : t(`js.local_import.status.${body.status}`);
            if (body.failure) statusText += ` · ${body.failure}`;
            else if (body.status === 'READY' && newCount(body) === 0) statusText += ` · ${t('js.local_import.no_new_records')}`;
            if (progress.textContent !== statusText) progress.textContent = statusText;
            countLabel.textContent = progressDetails(body, kind, number);
            meter.replaceChildren(progressMeter(body, progress.id));
            helper.textContent = body.progress?.stale ? t('js.local_import.stale_progress')
                : body.status === 'QUEUED' || phase === 'WAITING_SAVE' ? t('js.local_import.queued_help')
                    : body.status === 'IMPORTING' ? t('js.local_import.saving_help')
                        : body.status === 'READY' ? t('js.local_import.ready_help') : '';
            const milestone = Math.floor((body.progress?.percent ?? 0) / 25) * 25;
            const milestoneKey = `${body.progress?.execution ?? ''}:${milestone}`;
            if (milestone > 0 && announcedMilestone !== milestoneKey) {
                announcer.textContent = countLabel.textContent;
                announcedMilestone = milestoneKey;
            }
            const picker = modal.querySelector('[data-import-file-picker]');
            if (picker) picker.hidden = body.can_revalidate || !['VALIDATION_FAILED', 'FAILED', 'EXPIRED', 'CANCELLED'].includes(body.status);
            reload.hidden = body.status !== 'COMPLETED';
            warnings.replaceChildren();
            (body.warnings ?? []).slice(0, 100).forEach(warning => {
                const item = document.createElement('li');
                item.textContent = warning.message;
                warnings.append(item);
            });
            if ((body.warnings_total ?? 0) > 100) {
                const item = document.createElement('li');
                item.textContent = t('js.local_import.more_warnings', { count: body.warnings_total - 100 });
                warnings.append(item);
            }
            confirm.disabled = requestInFlight || !canConfirm(body);
            confirm.hidden = !['READY', 'IMPORTING'].includes(body.status);
            if (revalidate) {
                revalidate.hidden = !body.can_revalidate;
                revalidate.disabled = requestInFlight || active.has(body.status);
                revalidate.classList.toggle('is-primary', body.status !== 'READY');
            }
            if (replace) { replace.hidden = active.has(body.status) || body.status === 'COMPLETED'; replace.disabled = requestInFlight; }
            parse.hidden = active.has(body.status) || body.status === 'READY' || Boolean(body.can_revalidate);
            if (confirmLabel) confirmLabel.textContent = body.status === 'IMPORTING' ? t('js.local_import.phase.SAVING') : confirmText;
            find('Token').value = canConfirm(body) ? (body.token ?? '') : '';
            parse.disabled = requestInFlight || active.has(body.status);
            cancel.disabled = ['IMPORTING', 'COMPLETED', 'CANCELLED', 'EXPIRED'].includes(body.status);
            cancel.hidden = cancel.disabled || !statusUrl;
            form.action = body.confirm_url ?? confirmAction;
            find('Result')?.classList.toggle('d-none', !body.preview);
            const summary = body.preview?.summary ?? {};
            const staleReview = body.status === 'VALIDATION_FAILED' && body.failure && Number(summary.invalid ?? 0) === 0 && newCount(body) > 0 && Number(body.errors_total ?? 0) === 0;
            const reviewSummary = find('ReviewSummary');
            if (reviewSummary) {
                reviewSummary.hidden = active.has(body.status) || !body.preview;
                const noNew = body.status === 'VALIDATION_FAILED' && Number(summary.invalid ?? 0) === 0 && newCount(body) === 0 && summary[kind === 'PO' ? 'existing_po' : 'existing_gr'] > 0;
                let title = t(`js.local_import.status.${body.status}`);
                let message = '';
                if (body.status === 'READY') message = kind === 'PO'
                    ? t('js.local_import.ready_po_summary', { po_count: number(summary.new_po ?? 0), filled_count: number(summary.po_pr_filled ?? 0) })
                    : t('js.local_import.ready_summary', { count: number(newCount(body)), existing: number(summary.existing_gr ?? 0), kind });
                else if (noNew) { title = t('js.local_import.no_new_title'); message = t('js.local_import.no_new_records'); }
                else if (body.status === 'VALIDATION_FAILED') {
                    title = t('js.local_import.correct_data');
                    message = staleReview ? `${body.failure} ${t('js.local_import.review_changed')}` : (body.failure || t('js.local_import.correct_help', {count:number(summary.invalid ?? body.errors_total ?? 0)}));
                } else if (body.status === 'COMPLETED') message = completedMessage(body);
                else if (body.status === 'EXPIRED') message = t('js.local_import.expired_help');
                else if (body.status === 'CANCELLED') message = t('js.local_import.cancelled_help');
                else message = body.failure || t('js.local_import.not_saved_help');
                find('SummaryTitle').textContent = title;
                find('SummaryMessage').textContent = message;
                find('StoredHelp').hidden = !body.can_revalidate;
                if (noNew) progress.textContent = t('js.local_import.no_new_records');
                if (previousStatus !== body.status && ['VALIDATION_FAILED', 'FAILED', 'COMPLETED'].includes(body.status)) reviewSummary.focus?.();
            }
            modal.querySelectorAll('[data-import-step]').forEach(step => {
                const current = ['IMPORTING', 'COMPLETED'].includes(body.status) ? '3' : '2';
                if (step.dataset.importStep === current) step.setAttribute('aria-current', 'step');
                else step.removeAttribute?.('aria-current');
            });
            const metrics = kind === 'PO' ? { TotalRows: 'source_rows', New: 'new_po', PrFilled: 'po_pr_filled', Existing: 'existing_po', Conflict: 'conflicts', InvalidRows: 'invalid' }
                : { SourceRows: 'source_rows', Consolidated: 'consolidated_gr', New: 'new_gr', Existing: 'existing_gr', UnmatchedPo: 'unmatched_po', InvalidRows: 'invalid' };
            Object.entries(metrics).forEach(([suffix, key]) => {
                const element = document.getElementById(`kpi${name}${suffix}`);
                if (element) element.textContent = number(summary[key] ?? 0);
            });
            if (body.preview) {
                if (page === 1) renderRows(body.preview.rows ?? []);
                if (errorPage === 1) renderErrors(body.preview.errors ?? []);
            }
            find('RowCount')?.replaceChildren(document.createTextNode(number(body.records_total)));
            if (staleReview) find('PreviewPanel')?.classList.add('d-none');
            const metricsPanel = modal.querySelector('[data-import-metrics]');
            if (metricsPanel) metricsPanel.hidden = Boolean(staleReview);
            controls.hidden = !body.preview || Boolean(staleReview);
            const pages = Math.max(1, Math.ceil((body.records_total ?? 0) / 100));
            previous.disabled = page <= 1;
            next.disabled = page >= pages;
            pageLabel.textContent = `${page} / ${pages}`;
            const errorPages = Math.max(1, Math.ceil((body.errors_total ?? 0) / 100));
            errorPrevious.disabled = errorPage <= 1;
            errorNext.disabled = errorPage >= errorPages;
            errorLabel.textContent = `${errorPage} / ${errorPages}`;
        }
        // Penyimpanan berjalan di worker; hasilnya harus terlihat walau modal sudah
        // di-scroll ke tengah tabel pratinjau, bukan hanya teks status di atas body.
        const completedMessage = body => kind === 'PO'
            ? t('js.local_import.completed_po', { po_count: number(body.preview?.summary?.newPo ?? 0),
                filled_count: number(body.preview?.summary?.filledPoPr ?? 0),
                existing: number(Math.max(0, (body.preview?.summary?.existingPo ?? 0) - (body.preview?.summary?.filledPoPr ?? 0))) })
            : t('js.local_import.completed', { kind, count: number(body.preview?.summary?.newGr ?? 0),
                existing: number(body.preview?.summary?.existingGr ?? 0) });
        const notSavedMessage = body => t('js.local_import.not_saved', { kind, reason: body.failure || t(`js.local_import.status.${body.status}`) });
        function announceOutcome(body) {
            if (body.status === 'IMPORTING') {
                awaitingCommit = true;

                return;
            }
            if (!awaitingCommit || active.has(body.status)) return;
            awaitingCommit = false;
            if (body.status === 'COMPLETED') {
                // Keep the committed result visible until the user chooses where to go.
                return;
            }
            // Gagal: modal tetap terbuka supaya detail error dapat dibaca.
            progress.scrollIntoView?.({ block: 'nearest' });
            window.AdasiToast?.error(notSavedMessage(body));
        }
        // Menutup modal tidak membatalkan penyimpanan di worker; hasilnya tetap diumumkan.
        let watcher = null;
        const stopWatcher = () => { if (watcher) clearTimeout(watcher.timer); watcher = null; };
        function watchInBackground(url) {
            if (!url || watcher?.url === url) return;
            stopWatcher();
            const current = { url, timer: null };
            watcher = current;
            const later = ms => { current.timer = setTimeout(tick, ms); };
            async function tick() {
                if (watcher !== current) return;
                if (document.hidden) return later(3000);
                let body;
                try {
                    body = await json(url);
                } catch {
                    if (watcher === current) later(10000);

                    return;
                }
                if (watcher !== current) return;
                if (active.has(body.status)) return later(3000);
                watcher = null;
                if (body.status !== 'COMPLETED') return window.AdasiToast?.error(notSavedMessage(body));
                // Jangan reload di atas preview/berkas lain yang sedang dikerjakan user.
                window.AdasiToast?.success(completedMessage(body), { actions: [resultAction(body)] });
            }
            later(3000);
        }
        async function poll() {
            clearTimeout(timer);
            if (!statusUrl || document.hidden) return;
            const generation = modalGeneration;
            const sequence = ++pollSequence;
            try {
                let body = await json(`${statusUrl}?progress_only=1`);
                if (generation !== modalGeneration || sequence !== pollSequence) return;
                if (!active.has(body.status)) {
                    body = last?.status === body.status && last?.validation_attempt === body.validation_attempt && last?.preview ? { ...last, ...body } : await json(statusUrl);
                    if (generation !== modalGeneration || sequence !== pollSequence) return;
                }
                render(body);
                announceOutcome(body);
                if (active.has(body.status)) timer = setTimeout(poll, 3000);
            } catch (error) {
                if (generation !== modalGeneration || sequence !== pollSequence) return;
                // A network failure is not evidence that an active import has stopped.
                helper.textContent = t('js.local_import.network_status'); tell(error); retry.hidden = false; timer = setTimeout(poll, 10000);
            }
        }
        parse.addEventListener('click', async () => {
            if (requestInFlight) return;
            const file = find('File').files[0];
            if (!file) return tell(new Error(t('js.local_import.select')));
            const generation = modalGeneration;
            requestInFlight = true;
            parse.disabled = true;
            confirm.disabled = true;
            find('Spinner')?.classList.remove('d-none');
            clearTimeout(timer);
            const data = new FormData();
            data.append('import_file', file);
            data.append('_token', csrf());
            try {
                const body = await json(config.dataset.importPreview, { method: 'POST', body: data });
                if (generation !== modalGeneration) return;
                statusUrl = body.status_url;
                page = 1;
                errorPage = 1;
                await poll();
                if (generation === modalGeneration) await loadActiveImports();
            } catch (error) { if (generation === modalGeneration) { tell(error); await loadActiveImports(); } }
            finally {
                if (generation === modalGeneration) {
                    requestInFlight = false; parse.disabled = Boolean(statusUrl) && (!last || active.has(last.status)); find('Spinner')?.classList.add('d-none');
                    if (last) render(last);
                }
            }
        });
        revalidate?.addEventListener('click', async () => {
            if (requestInFlight || !last?.can_revalidate || !Number.isInteger(last.validation_attempt)) return;
            const candidate = last;
            if (!safeUrl(candidate.revalidate_url)) return;
            const generation = ++modalGeneration;
            clearTimeout(timer);
            requestInFlight = true;
            find('Token').value = '';
            confirm.disabled = true;
            last = null;
            find('Result')?.classList.add('d-none');
            revalidate.disabled = true;
            try {
                const response = await json(candidate.revalidate_url, { method: 'POST', body: new URLSearchParams({_token:csrf(), expected_attempt:String(candidate.validation_attempt)}) });
                if (generation !== modalGeneration) return;
                statusUrl = response.status_url;
                page = errorPage = 1;
                await poll();
                await loadActiveImports();
            } catch (error) {
                if (generation === modalGeneration) { tell(error); await poll(); }
            } finally {
                if (generation === modalGeneration) { requestInFlight = false; if (last) render(last); }
            }
        });
        replace?.addEventListener('click', () => {
            if (requestInFlight || active.has(last?.status)) return;
            const picker = modal.querySelector('[data-import-file-picker]');
            if (picker) picker.hidden = false;
            find('File')?.click?.();
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (requestInFlight || !canConfirm(last)) return;
            const generation = modalGeneration;
            requestInFlight = true;
            confirm.disabled = true;
            try {
                const body = await json(form.action, { method: 'POST', body: new FormData(form) });
                if (generation !== modalGeneration) return;
                // Worker bisa selesai sebelum poll pertama, jadi IMPORTING mungkin tak pernah terlihat.
                awaitingCommit = true;
                statusUrl = body.status_url;
                await poll();
                if (generation === modalGeneration) await loadActiveImports();
            } catch (error) {
                if (generation === modalGeneration) { tell(error); confirm.disabled = !canConfirm(last); }
            }
            finally {
                // render() di atas berjalan saat request masih in-flight sehingga Validasi ikut terkunci.
                if (generation === modalGeneration) { requestInFlight = false; parse.disabled = Boolean(statusUrl) && (!last || active.has(last.status)); if (last) render(last); }
            }
        });
        async function changePage(delta) {
            if (!last) return;
            const generation = modalGeneration;
            const attempt = last.validation_attempt;
            page += delta;
            previous.disabled = true;
            next.disabled = true;
            try {
                const body = await json(`${last.records_url}?page=${page}`);
                if (generation !== modalGeneration || attempt !== last?.validation_attempt) return;
                renderRows(body.data);
                render(last);
            } catch (error) { if (generation === modalGeneration && attempt === last?.validation_attempt) { page -= delta; render(last); tell(error); } }
        }
        previous.addEventListener('click', () => changePage(-1));
        next.addEventListener('click', () => changePage(1));
        cancel.addEventListener('click', async () => {
            if (statusUrl && last) await cancelImport({...last, status_url:statusUrl}, [cancel], cancel);
        });
        const errorControls = document.createElement('div');
        const errorPrevious = previous.cloneNode(true);
        const errorNext = next.cloneNode(true);
        const errorLabel = document.createElement('span');
        errorControls.append(errorPrevious, errorLabel, errorNext);
        find('ErrorsPanel')?.append(errorControls);
        const errorMove = async delta => {
            if (!last) return;
            const generation = modalGeneration;
            const attempt = last.validation_attempt;
            errorPage += delta;
            try {
                const result = await json(`${last.errors_url}?page=${errorPage}`);
                if (generation !== modalGeneration || attempt !== last?.validation_attempt) return;
                renderErrors(result.data);
                errorPrevious.disabled = errorPage <= 1;
                errorNext.disabled = errorPage >= result.last_page;
                errorLabel.textContent = `${errorPage} / ${result.last_page}`;
            } catch (error) { if (generation === modalGeneration && attempt === last?.validation_attempt) { errorPage -= delta; tell(error); } }
        };
        errorPrevious.addEventListener('click', () => errorMove(-1));
        errorNext.addEventListener('click', () => errorMove(1));
        function resetSession({ clearFile = true } = {}) {
            modalGeneration++;
            clearTimeout(timer);
            statusUrl = last = timer = null;
            page = errorPage = 1;
            requestInFlight = awaitingCommit = false;
            recoveryRequest++;
            clearTimeout(recoveryTimer);
            knownStatuses = new Map();
            recoveryButtons = new Map();
            if (processingList?.replaceChildren && followUpList?.replaceChildren) {
                processingList.replaceChildren(); followUpList.replaceChildren();
                processingGroup.hidden = followUpGroup.hidden = true;
            } else recoveryList?.replaceChildren();
            if (recoveryPanel) recoveryPanel.hidden = true;
            if (recoveryError) { recoveryError.hidden = true; recoveryError.textContent = ''; }
            if (recoverySlots) recoverySlots.textContent = '';
            if (refreshImports) refreshImports.disabled = false;
            progress.textContent = pageLabel.textContent = errorLabel.textContent = '';
            filename.textContent = countLabel.textContent = helper.textContent = announcer.textContent = '';
            meter.replaceChildren(); statusPanel.hidden = true; retry.hidden = true; announcedMilestone = '';
            const picker = modal.querySelector('[data-import-file-picker]');
            if (picker) picker.hidden = false;
            warnings.replaceChildren();
            controls.hidden = reload.hidden = true;
            cancel.hidden = true;
            if (revalidate) { revalidate.hidden = true; revalidate.disabled = false; }
            if (replace) { replace.hidden = true; replace.disabled = false; }
            if (find('ReviewSummary')) find('ReviewSummary').hidden = true;
            [previous, next, errorPrevious, errorNext, cancel, confirm].forEach(button => { button.disabled = true; });
            parse.disabled = false; parse.hidden = false; confirm.hidden = true;
            if (confirmLabel) confirmLabel.textContent = confirmText;
            form.action = confirmAction;
            find('Token').value = '';
            find('Spinner')?.classList.add('d-none');
            ['Result', 'PreviewPanel', 'ErrorsPanel'].forEach(suffix => find(suffix)?.classList.add('d-none'));
            ['PreviewBody', 'ErrorsList', 'RowCount'].forEach(suffix => find(suffix)?.replaceChildren());
            modal.querySelectorAll(`[id^="kpi${name}"]`).forEach(element => { element.textContent = number(0); });
            modal.querySelectorAll('[data-import-step]').forEach(step => {
                if (step.dataset.importStep === '1') step.setAttribute('aria-current', 'step');
                else step.removeAttribute?.('aria-current');
            });
            if (!clearFile) return;
            const input = find('File');
            const dropzone = input?.closest('[x-data]');
            if (dropzone && window.Alpine?.$data) window.Alpine.$data(dropzone).clearAll?.();
            if (input) input.value = '';
        }
        // Memilih berkas lain harus melepaskan run sebelumnya: tanpa ini tombol
        // validasi tetap terkunci selama status lama belum terminal, dan preview
        // terlantar ikut memakan kuota import aktif milik user.
        find('File')?.addEventListener('change', () => {
            if (!statusUrl) return;
            if (last?.status === 'IMPORTING') {
                tell(new Error(t('js.local_import.busy')));

                return;
            }
            // Run yang sudah terminal tidak perlu dibatalkan lagi.
            const cancelUrl = ['COMPLETED', 'CANCELLED', 'EXPIRED'].includes(last?.status) ? null : `${statusUrl}/cancel`;
            resetSession({ clearFile: false });
            progress.textContent = t('js.local_import.file_changed');
            if (cancelUrl) json(cancelUrl, { method: 'POST', body: new URLSearchParams({ _token: csrf() }) }).catch(() => {});
        });
        modal.addEventListener('show.bs.modal', () => { resetSession(); return loadActiveImports(); });
        modal.addEventListener('hidden.bs.modal', () => {
            if (last?.status === 'IMPORTING') watchInBackground(statusUrl);
            resetSession();
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                poll();
                if (recoveryPanel && !recoveryPanel.hidden) loadActiveImports();
            } else {
                clearTimeout(timer); clearTimeout(recoveryTimer);
            }
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => bootLocalProcurementImports());
else bootLocalProcurementImports();
