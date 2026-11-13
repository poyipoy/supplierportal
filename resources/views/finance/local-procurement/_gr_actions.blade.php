@if($gr->status !== 'CANCELLED')
<div class="tw:inline-flex tw:gap-2">
    <x-ui.button type="button" size="sm" variant="outline" data-gr-document data-gr-document-url="{{ route($routePrefix.'.goods-receipts.document', $gr) }}" data-gr-number="{{ $gr->gr_number }}" aria-label="{{ __('local_procurement.gr_documents.row_label', ['number' => $gr->gr_number]) }}">
        <x-ui.icon name="file-up" size="sm" />
        <span>{{ __('local_procurement.gr_documents.row_action') }}</span>
    </x-ui.button>
    @if($gr->status === 'AVAILABLE')
        <x-ui.button type="button" size="sm" variant="outline" data-gr-edit>{{ __('common.final_copy.edit') }}</x-ui.button>
        <x-ui.button type="button" size="sm" variant="ghost" class="tw:text-error!" data-gr-cancel>{{ __('local_invoice.actions.cancel') }}</x-ui.button>
    @endif
</div>
@elseif($purchaseOrder->status === 'OPEN')
    @can('restoreGoodsReceipt', $purchaseOrder)
        <x-ui.button type="button" size="sm" variant="outline" data-gr-restore data-gr-restore-url="{{ route($routePrefix.'.goods-receipts.restore', $gr) }}" data-gr-number="{{ $gr->gr_number }}" data-gr-hash="{{ $gr->hash }}">{{ __('local_procurement.recovery.restore_gr') }}</x-ui.button>
    @endcan
@endif
