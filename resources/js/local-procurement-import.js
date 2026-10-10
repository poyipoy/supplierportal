import { t } from './i18n.js';

const active = new Set(['QUEUED', 'READING', 'VALIDATING', 'IMPORTING']);

async function json(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', ...options.headers } });
    const body = await response.json();
    if (!response.ok) throw new Error(body.errors ? Object.values(body.errors).flat().join(' ') : body.message);
    return body;
}

export function bootLocalProcurementImports(root = document) {
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
        const form = find('ConfirmForm');
        if (!modal || !parse || !form) return;
        const confirmAction = form.action;
        const progress = document.createElement('div');
        progress.className = 'tw-text-ui-sm tw-py-2';
        progress.setAttribute('role', 'status');
        progress.setAttribute('aria-live', 'polite');
        modal.querySelector('.modal-body').prepend(progress);
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
        reload.textContent = t('js.local_import.reload');
        reload.hidden = true;
        reload.addEventListener('click', () => window.location.reload());
        controls.append(previous, pageLabel, next, cancel, reload);
        modal.querySelector('.modal-body').append(controls);
        controls.hidden = true;
        let statusUrl = null;
        let last = null;
        let page = 1;
        let errorPage = 1;
        let requestInFlight = false;
        let timer = null;
        let modalGeneration = 0;
        const csrf = () => form.querySelector('[name="_token"]').value;
        const tell = error => { progress.textContent = error.message || t('js.local_import.error'); };
        const number = value => window.AdasiPreferences?.displayNumber?.(value) ?? String(value ?? 0);
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
                const fields = kind === 'PO' ? ['_row', 'po_number', 'supplier_name', 'po_date', 'po_amount', 'action']
                    : ['_row', 'gr_number', 'po_number', 'description', 'gr_date', 'qty', 'uom', 'source_rows_count', 'action'];
                fields.forEach(field => textCell(row, field === 'action' ? value.action_label ?? value.action : value[field]));
                body.append(row);
            });
            find('PreviewPanel')?.classList.remove('d-none');
        }
        function renderErrors(errors) {
            const list = find('ErrorsList');
            list.replaceChildren();
            errors.forEach(error => {
                const item = document.createElement('li');
                item.textContent = t('js.local_import.row_error', { row: error.row ?? '—', column: error.column, message: error.message });
                list.append(item);
            });
            find('ErrorsPanel')?.classList.toggle('d-none', errors.length === 0);
        }
        function render(body) {
            last = body;
            progress.textContent = `${t(`js.local_import.status.${body.status}`)} · ${t('js.local_import.progress').replace(':count', number(body.processed_rows))}`;
            if (body.failure) progress.textContent += ` · ${body.failure}`;
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
            confirm.disabled = body.status !== 'READY';
            find('Token').value = body.token ?? '';
            parse.disabled = requestInFlight || active.has(body.status);
            cancel.disabled = ['IMPORTING', 'COMPLETED', 'CANCELLED', 'EXPIRED'].includes(body.status);
            form.action = body.confirm_url;
            find('Result')?.classList.remove('d-none');
            const summary = body.preview?.summary ?? {};
            const metrics = kind === 'PO' ? { TotalRows: 'source_rows', New: 'new_po', Existing: 'existing_po', Conflict: 'conflicts', InvalidRows: 'invalid' }
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
            controls.hidden = false;
            const pages = Math.max(1, Math.ceil((body.records_total ?? 0) / 100));
            previous.disabled = page <= 1;
            next.disabled = page >= pages;
            pageLabel.textContent = `${page} / ${pages}`;
            const errorPages = Math.max(1, Math.ceil((body.errors_total ?? 0) / 100));
            errorPrevious.disabled = errorPage <= 1;
            errorNext.disabled = errorPage >= errorPages;
            errorLabel.textContent = `${errorPage} / ${errorPages}`;
        }
        async function poll() {
            clearTimeout(timer);
            if (!statusUrl || document.hidden) return;
            const generation = modalGeneration;
            try {
                const body = await json(statusUrl);
                if (generation !== modalGeneration) return;
                render(body);
                if (active.has(body.status)) timer = setTimeout(poll, 3000);
            } catch (error) {
                if (generation !== modalGeneration) return;
                tell(error); timer = setTimeout(poll, 10000);
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
            } catch (error) { if (generation === modalGeneration) tell(error); }
            finally {
                if (generation === modalGeneration) {
                    requestInFlight = false; parse.disabled = active.has(last?.status); find('Spinner')?.classList.add('d-none');
                }
            }
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (requestInFlight || last?.status !== 'READY') return;
            const generation = modalGeneration;
            requestInFlight = true;
            confirm.disabled = true;
            try {
                const body = await json(form.action, { method: 'POST', body: new FormData(form) });
                if (generation !== modalGeneration) return;
                statusUrl = body.status_url;
                await poll();
            } catch (error) {
                if (generation === modalGeneration) { tell(error); confirm.disabled = last?.status !== 'READY'; }
            }
            finally { if (generation === modalGeneration) requestInFlight = false; }
        });
        async function changePage(delta) {
            if (!last) return;
            const generation = modalGeneration;
            page += delta;
            previous.disabled = true;
            next.disabled = true;
            try {
                const body = await json(`${last.records_url}?page=${page}`);
                if (generation !== modalGeneration) return;
                renderRows(body.data);
                render(last);
            } catch (error) { if (generation === modalGeneration) { page -= delta; render(last); tell(error); } }
        }
        previous.addEventListener('click', () => changePage(-1));
        next.addEventListener('click', () => changePage(1));
        cancel.addEventListener('click', async () => {
            if (!statusUrl || requestInFlight) return;
            const generation = modalGeneration;
            requestInFlight = true;
            try {
                await json(`${statusUrl}/cancel`, { method: 'POST', body: new URLSearchParams({ _token: csrf() }) });
                if (generation === modalGeneration) await poll();
            }
            catch (error) { if (generation === modalGeneration) tell(error); }
            finally { if (generation === modalGeneration) requestInFlight = false; }
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
            errorPage += delta;
            try {
                const result = await json(`${last.errors_url}?page=${errorPage}`);
                if (generation !== modalGeneration) return;
                renderErrors(result.data);
                errorPrevious.disabled = errorPage <= 1;
                errorNext.disabled = errorPage >= result.last_page;
                errorLabel.textContent = `${errorPage} / ${result.last_page}`;
            } catch (error) { if (generation === modalGeneration) { errorPage -= delta; tell(error); } }
        };
        errorPrevious.addEventListener('click', () => errorMove(-1));
        errorNext.addEventListener('click', () => errorMove(1));
        function resetModal() {
            modalGeneration++;
            clearTimeout(timer);
            statusUrl = last = timer = null;
            page = errorPage = 1;
            requestInFlight = false;
            progress.textContent = pageLabel.textContent = errorLabel.textContent = '';
            warnings.replaceChildren();
            controls.hidden = reload.hidden = true;
            [previous, next, errorPrevious, errorNext, cancel, confirm].forEach(button => { button.disabled = true; });
            parse.disabled = false;
            form.action = confirmAction;
            find('Token').value = '';
            find('Spinner')?.classList.add('d-none');
            ['Result', 'PreviewPanel', 'ErrorsPanel'].forEach(suffix => find(suffix)?.classList.add('d-none'));
            ['PreviewBody', 'ErrorsList', 'RowCount'].forEach(suffix => find(suffix)?.replaceChildren());
            modal.querySelectorAll(`[id^="kpi${name}"]`).forEach(element => { element.textContent = number(0); });
            const input = find('File');
            const dropzone = input?.closest('[x-data]');
            if (dropzone && window.Alpine?.$data) window.Alpine.$data(dropzone).clearAll?.();
            if (input) input.value = '';
        }
        modal.addEventListener('show.bs.modal', resetModal);
        modal.addEventListener('hidden.bs.modal', resetModal);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); else clearTimeout(timer); });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => bootLocalProcurementImports());
else bootLocalProcurementImports();
