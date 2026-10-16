export function bootLocalPoGrTable(root = document) {
    const element = root.querySelector('[data-gr-table]');
    if (!element || element.dataset.initialized) return;
    const filter = root.querySelector('#poGrStatusFilter');
    const recoveryModals = root.querySelectorAll('[data-recovery-modal]');
    for (const modal of recoveryModals) {
        const form = modal.querySelector('form');
        modal.addEventListener('show.bs.modal', event => {
            if (event.relatedTarget) modal.recoveryTrigger = event.relatedTarget;
        });
        form.addEventListener('submit', event => {
            const reason = form.querySelector('[name="reason"]');
            reason.value = reason.value.trim();
            if (!form.reportValidity()) event.preventDefault();
        });
        modal.addEventListener('shown.bs.modal', () => {
            (modal.querySelector('[aria-invalid="true"]') || form.querySelector('[name="reason"]'))?.focus();
        });
        modal.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const fields = [...modal.querySelectorAll('button, [href], input:not([type="hidden"]), select, textarea, [tabindex]')]
                .filter(field => !field.disabled && field.tabIndex >= 0 && field.getClientRects().length);
            const first = fields[0], last = fields.at(-1);
            const active = (modal.ownerDocument || root).activeElement;
            if (first && ((event.shiftKey && active === first) || (!event.shiftKey && active === last))) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            }
        });
        modal.addEventListener('hidden.bs.modal', () => {
            (modal.recoveryTrigger?.isConnected ? modal.recoveryTrigger : filter)?.focus();
        });
        if (modal.dataset.recoveryReopen === '1') window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-gr-restore]');
        if (!button || element.dataset.readOnly === '1') return;
        const modal = root.querySelector('#restorePoGrModal');
        if (!modal) return;
        let action;
        try { action = new URL(button.dataset.grRestoreUrl, window.location.origin); } catch { return; }
        if (action.origin !== window.location.origin || action.username || action.password) return;
        event.preventDefault();
        const form = modal.querySelector('form');
        form.reset();
        form.querySelectorAll('[aria-invalid="true"]').forEach(field => {
            field.removeAttribute('aria-invalid');
            field.classList.remove('is-invalid');
        });
        modal.querySelectorAll('[data-recovery-error]').forEach(error => { error.hidden = true; });
        form.action = action.href;
        modal.querySelector('[data-gr-modal-number]').textContent = button.dataset.grNumber;
        modal.querySelector('[name="_recovery_target"]').value = button.dataset.grHash;
        modal.recoveryTrigger = button;
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    if (!window.jQuery?.fn?.dataTable) {
        root.querySelector('[data-gr-table-error]').hidden = false;
        root.querySelector('[data-gr-table-retry]').addEventListener('click', () => window.location.reload());
        return;
    }
    element.dataset.initialized = 'true';
    const readOnly = element.dataset.readOnly === '1';
    const error = root.querySelector('[data-gr-table-error]');
    const $ = window.jQuery;
    $.fn.dataTable.ext.errMode = 'none';
    const table = $(element).DataTable({
        processing: true, serverSide: true, searchDelay: 400, order: [[2, 'desc']],
        ajax: { url: element.dataset.url, data: data => { data.status = filter.value; },
            error: () => { error.hidden = false; } },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false, className: 'ui-tabular-nums' },
            { data: 'gr_number', name: 'gr_number' }, { data: 'gr_date', name: 'gr_date' },
            { data: 'description', name: 'description', defaultContent: '—' },
            { data: 'qty', name: 'qty', className: 'text-end ui-tabular-nums' },
            { data: 'uom', name: 'uom', defaultContent: '—' },
            { data: 'status_badge', name: 'status' },
            { data: 'invoice', orderable: false, searchable: false },
            { data: 'source_label', orderable: false, searchable: false, visible: !readOnly },
            { data: 'actions', orderable: false, searchable: false, visible: !readOnly },
        ],
    });
    $(element).on('xhr.dt', (_event, _settings, json) => { if (json) error.hidden = true; });
    root.querySelector('[data-gr-table-retry]')?.addEventListener('click', () => table.ajax.reload(null, false));
    filter.addEventListener('change', () => table.ajax.reload());
    element.addEventListener('click', event => {
        const button = event.target.closest('[data-gr-edit], [data-gr-cancel]');
        if (!button || readOnly) return;
        const row = table.row(button.closest('tr')).data();
        const data = row?.edit_data;
        if (!data) return;
        const edit = button.hasAttribute('data-gr-edit');
        const modal = root.querySelector(edit ? '#editPoGrModal' : '#cancelPoGrModal');
        const form = modal.querySelector('form');
        const action = new URL(edit ? data.update_url : data.cancel_url, window.location.origin);
        if (action.origin !== window.location.origin) return;
        form.action = action.href;
        modal.querySelector('[data-gr-modal-number]').textContent = data.gr_number;
        if (edit) {
            for (const name of ['gr_number', 'gr_date', 'qty', 'uom', 'description', 'notes']) {
                form.querySelector(`[name="${name}"]`).value = data[name] ?? '';
            }
            window.AdasiCalendar?.reset(form.querySelector('[name="gr_date"]'));
        }
        modal.addEventListener('hidden.bs.modal', () => (button.isConnected ? button : filter).focus(), { once: true });
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
}
