@php
    $readOnly = $readOnly ?? false;
    $grTotal = $readOnly ? $purchaseOrder->active_gr_count : $purchaseOrder->goods_receipts_count;
@endphp
<x-ui.data-table :title="__('local_procurement.labels.gr_list')" :description="$readOnly ? trans_choice('local_procurement.closure.gr_record_count', $grTotal) : trans_choice('finance.closure.gr_count', $grTotal)">
    <x-slot:filters>
        <div>
            <label for="poGrStatusFilter" class="form-label tw:text-ui-xs">{{ __('local_invoice.labels.status') }}</label>
            <select id="poGrStatusFilter" class="form-select form-select-sm">
                <option value="">{{ __('local_procurement.gr_table.all_statuses') }}</option>
                @foreach(['AVAILABLE', 'RESERVED', 'INVOICED', ...($readOnly ? [] : ['CANCELLED'])] as $status)
                    <option value="{{ $status }}">{{ \App\Support\StatusHelper::localFinanceLabel($status) }}</option>
                @endforeach
            </select>
        </div>
    </x-slot:filters>
    <div data-gr-table-error role="alert" hidden class="tw:p-3 tw:text-error">
        {{ __('local_procurement.gr_table.load_failed') }}
        <x-ui.button type="button" size="sm" variant="outline" data-gr-table-retry>{{ __('local_procurement.gr_table.retry') }}</x-ui.button>
    </div>
    <table id="poGoodsReceiptsTable" data-gr-table data-url="{{ route($routePrefix.'.goods-receipts.data', $purchaseOrder) }}" data-read-only="{{ $readOnly ? '1' : '0' }}" class="table table-hover align-middle tw:text-ui-sm w-100">
        <thead><tr>
            <th scope="col">{{ __('local_procurement.gr_table.number') }}</th>
            <th scope="col">{{ __('local_procurement.surface.gr_number') }}</th>
            <th scope="col">{{ __('local_procurement.labels.gr_date') }}</th>
            <th scope="col">{{ __('local_procurement.labels.goods_description') }}</th>
            <th scope="col">{{ __('common.fields.qty') }}</th>
            <th scope="col">{{ __('local_procurement.labels.uom') }}</th>
            <th scope="col">{{ __('local_invoice.labels.status') }}</th>
            <th scope="col">{{ __('local_procurement.labels.gr_invoice') }}</th>
            <th scope="col">{{ __('local_invoice.labels.source') }}</th>
            <th scope="col">{{ __('local_invoice.labels.actions') }}</th>
        </tr></thead>
        <tbody>
            @foreach($purchaseOrder->goodsReceipts as $gr)
                <tr>
                    <td>{{ $loop->iteration }}</td><td>{{ $gr->gr_number }}</td>
                    <td>{{ $regionalFormatter->date($gr->gr_date, 'human') ?? '—' }}</td>
                    <td>{{ $gr->description }}</td><td>{{ \App\Models\LocalGoodsReceipt::formatQuantity($gr->qty) }}</td><td>{{ $gr->uom ?? '—' }}</td>
                    <td>@include('finance.local-procurement._gr_status', ['status' => $gr->status])</td>
                    <td>
                        @php $invoiceRoute = $readOnly ? 'local-supplier.invoices.show' : ($routePrefix === 'purchasing.local-procurement' ? 'purchasing.local-invoices.show' : 'finance.invoices.show'); @endphp
                        @include('finance.local-procurement._gr_invoice', ['invoice' => $gr->currentInvoice, 'invoiceRoute' => $invoiceRoute])
                    </td>
                    <td>{{ __('local_procurement.sources.'.strtolower($gr->source ?: 'MANUAL')) }}</td><td>@unless($readOnly)@include('finance.local-procurement._gr_actions')@endunless</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-ui.data-table>
@unless($readOnly)
    @include('finance.local-procurement._gr_modals')
    @can('restoreGoodsReceipt', $purchaseOrder)
        @php
            $recoveryGr = null;
            if ($errors->getBag('restoreGr')->any() && is_string(old('_recovery_target'))) {
                $boundRecoveryGr = (new \App\Models\LocalGoodsReceipt)->resolveRouteBinding(old('_recovery_target'));
                $recoveryGr = $boundRecoveryGr && (int) $boundRecoveryGr->local_purchase_order_id === (int) $purchaseOrder->id ? $boundRecoveryGr : null;
            }
        @endphp
        @include('finance.local-procurement._restore_modal', ['recoveryKind' => 'gr', 'recoveryTarget' => $recoveryGr])
    @endcan
@endunless
