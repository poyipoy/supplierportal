export function bootRegistrationReceipt(root = document) {
    const receipt = root.querySelector('[data-registration-receipt]');
    if (!receipt || receipt.dataset.initialized) return;
    receipt.dataset.initialized = 'true';
    const reference = receipt.querySelector('[data-receipt-reference]');
    const key = receipt.querySelector('[data-receipt-key]');
    const message = receipt.querySelector('[data-receipt-message]');
    const details = () => `${receipt.querySelector('[data-receipt-label-reference]').textContent}: ${reference.value}\n${receipt.querySelector('[data-receipt-label-key]').textContent}: ${key.value}\n`;
    receipt.querySelector('[data-receipt-copy]').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(details());
            message.textContent = receipt.querySelector('[data-receipt-copied]').textContent;
        } catch {
            message.textContent = receipt.querySelector('[data-receipt-copy-failed]').textContent;
            key.focus();
            key.select();
        }
    });
    receipt.querySelector('[data-receipt-download]').addEventListener('click', () => {
        const url = URL.createObjectURL(new Blob([details()], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `${reference.value}.txt`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => bootRegistrationReceipt());
else bootRegistrationReceipt();
