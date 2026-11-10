@include('local-invoices.filters', ['serverTabs' => true, 'paymentStatus' => $paymentStatus ?? 'ALL'])

<x-ui.data-table
    :title="__('finance.labels.master_invoice')"
    :description="trans_choice('finance.closure.invoice_matches', $invoices->total())"
>
    @include('local-invoices.table', ['invoices' => $invoices, 'portal' => 'finance', 'payments' => true])

    @if($invoices->hasPages())
        <x-slot:pagination>
            {{ $invoices->links() }}
        </x-slot:pagination>
    @endif
</x-ui.data-table>

