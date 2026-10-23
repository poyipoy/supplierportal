@if($gr->status === 'AVAILABLE')
<div class="tw:inline-flex tw:gap-2">
    <x-ui.button type="button" size="sm" variant="outline" data-gr-edit>{{ __('common.final_copy.edit') }}</x-ui.button>
    <x-ui.button type="button" size="sm" variant="ghost" class="tw:text-error!" data-gr-cancel>{{ __('local_invoice.actions.cancel') }}</x-ui.button>
</div>
@elseif($gr->status === 'CANCELLED' && $purchaseOrder->status === 'OPEN')
    @can('restoreGoodsReceipt', $purchaseOrder)
        <x-ui.button type="button" size="sm" variant="outline" data-gr-restore data-gr-restore-url="{{ route($routePrefix.'.goods-receipts.restore', $gr) }}" data-gr-number="{{ $gr->gr_number }}" data-gr-hash="{{ $gr->hash }}">{{ __('local_procurement.recovery.restore_gr') }}</x-ui.button>
    @endcan
@endif
