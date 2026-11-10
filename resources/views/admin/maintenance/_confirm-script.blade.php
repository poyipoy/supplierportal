@once
@push('scripts')
<script>
// Confirms buttons marked data-maintenance-confirm, then resubmits with the same
// submitter so its name/value (e.g. action=activate) still reaches the server.
document.addEventListener('submit', function (event) {
    const form = event.target;
    const submitter = event.submitter;
    if (!(form instanceof HTMLFormElement) || !submitter || !submitter.hasAttribute('data-maintenance-confirm')) {
        return;
    }
    if (form.dataset.maintenanceConfirmed === '1') {
        delete form.dataset.maintenanceConfirmed;
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const options = {
        title: submitter.dataset.confirmTitle,
        text: submitter.dataset.confirmText,
        confirmText: submitter.dataset.confirmLabel,
        cancelText: submitter.dataset.confirmCancel,
    };
    const ask = submitter.dataset.confirmDanger === '1' ? window.AdasiAlert.confirmDanger : window.AdasiAlert.confirm;

    ask(options).then(function (result) {
        if (!result || !result.isConfirmed) {
            return;
        }
        form.dataset.maintenanceConfirmed = '1';
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter);
            return;
        }
        if (submitter.name) {
            const mirror = document.createElement('input');
            mirror.type = 'hidden';
            mirror.name = submitter.name;
            mirror.value = submitter.value;
            form.appendChild(mirror);
        }
        form.submit();
    });
}, true);
</script>
@endpush
@endonce
