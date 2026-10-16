@if($invoice)
    <a href="{{ route($invoiceRoute, $invoice) }}" class="gr-invoice-link ui-focus-ring" data-gr-invoice-link>
        <x-ui.icon name="file-text" size="sm" class="tw-shrink-0 tw-mt-0.5 tw-text-primary" />
        <span class="tw-min-w-0">
            <span class="gr-invoice-link__number">{{ $invoice->invoice_number }}</span>
            <span class="gr-invoice-link__action">
                {{ __('local_procurement.gr_table.view_invoice') }}
                <x-ui.icon name="arrow-right" size="xs" />
            </span>
        </span>
    </a>
@else
    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.labels.not_invoiced') }}</span>
@endif
