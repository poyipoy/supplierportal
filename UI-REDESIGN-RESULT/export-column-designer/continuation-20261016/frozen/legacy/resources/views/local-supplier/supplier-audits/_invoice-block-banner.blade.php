{{-- D13: tampil saat pengajuan invoice baru diblokir karena Supplier Audit melewati deadline. --}}
@if($supplierAuditInvoiceBlock ?? null)
    @inject('auditDateFormatter', 'App\Services\RegionalDisplayFormatter')
    <x-ui.alert tone="warning" :title="__('supplier_audit.invoice_block.banner_title')" data-supplier-audit-invoice-block>
        <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
            <p class="tw-m-0 tw-text-ui-sm" style="text-wrap: pretty;">
                {{ __('supplier_audit.invoice_block.message', [
                    'period' => $supplierAuditInvoiceBlock->period_label,
                    'date' => $auditDateFormatter->date($supplierAuditInvoiceBlock->due_date),
                ]) }}
            </p>
            <x-ui.button :href="route('local-supplier.supplier-audits.edit', $supplierAuditInvoiceBlock)" variant="primary" size="sm" class="tw-shrink-0">
                <x-ui.icon name="clipboard-check" size="sm" />
                <span>{{ __('supplier_audit.invoice_block.banner_action') }}</span>
            </x-ui.button>
        </div>
    </x-ui.alert>
@endif
