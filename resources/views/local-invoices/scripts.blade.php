@once
@push('scripts')
<script>
document.querySelectorAll('.local-invoice-form').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('[type="submit"]');
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); button.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>'); }
}));
document.querySelectorAll('.local-workflow-form').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const result = await window.AdasiAlert.confirm({title: form.dataset.confirm, text: 'This action will be recorded in the invoice history.', confirmText: 'Confirm'});
    if (result.isConfirmed) { const button = form.querySelector('[type="submit"]'); button.disabled = true; button.setAttribute('aria-busy', 'true'); button.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>'); form.submit(); }
}));
document.querySelectorAll('[data-print-receipt]').forEach(button => button.addEventListener('click', () => window.print()));
</script>
@endpush
@endonce
