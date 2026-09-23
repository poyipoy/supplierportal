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
document.querySelectorAll('[data-copy-text], [data-copy-target]').forEach(button => button.addEventListener('click', async () => {
    const targetSelector = button.getAttribute('data-copy-target');
    const targetEl = targetSelector ? document.querySelector(targetSelector) : null;
    const text = targetEl ? (targetEl.value ?? targetEl.textContent) : button.getAttribute('data-copy-text');
    const successMsg = button.getAttribute('data-copy-msg') || 'Nomor rekening berhasil disalin!';
    if (!text) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            document.execCommand('copy');
            textArea.remove();
        }
        if (window.AdasiToast) {
            window.AdasiToast.success(successMsg);
        } else if (window.Toast) {
            window.Toast.fire({ icon: 'success', title: successMsg });
        }
    } catch (err) {
        console.error('Failed to copy text: ', err);
        if (window.AdasiToast) {
            window.AdasiToast.error('Gagal menyalin teks.');
        }
    }
}));
</script>
@endpush
@endonce
