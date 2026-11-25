@php
    $invoiceRoute = $routePrefix === 'purchasing.local-procurement'
        ? 'purchasing.local-invoices.show'
        : 'finance.invoices.show';
@endphp

@if($activeTab === 'goods-receipts')
    @if($goodsReceipts->isEmpty())
        <x-ui.empty-state icon="package-check" :title="__('local_procurement.mmt.empty.goods_receipts')" class="tw:py-6" />
    @else
        <div class="ui-table-scroll">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('local_procurement.surface.gr_number') }}</th>
                        <th scope="col">{{ __('local_procurement.labels.gr_date') }}</th>
                        <th scope="col">{{ __('local_procurement.labels.goods_description') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.fields.qty') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col">{{ __('local_procurement.labels.gr_invoice') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('local_procurement.gr_documents.column') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($goodsReceipts as $gr)
                        <tr>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface">{{ $gr->gr_number }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">{{ $regionalFormatter->date($gr->gr_date, 'human') ?? '—' }}</td>
                            <td class="tw:text-on-surface ui-col-data-name">{{ $gr->description ?: '—' }}</td>
                            <td class="tw:text-end ui-tabular-nums">{{ \App\Models\LocalGoodsReceipt::formatQuantity($gr->qty) }} {{ $gr->uom ?? '' }}</td>
                            <td class="tw:text-center">@include('finance.local-procurement._gr_status', ['status' => $gr->status])</td>
                            <td>@include('finance.local-procurement._gr_invoice', ['invoice' => $gr->currentInvoice, 'invoiceRoute' => $invoiceRoute])</td>
                            <td class="tw:text-end">@include('finance.local-procurement._gr_document', ['gr' => $gr])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@elseif($activeTab === 'invoices')
    @if($invoices->isEmpty())
        <x-ui.empty-state icon="receipt" :title="__('local_procurement.mmt.empty.invoices')" class="tw:py-6" />
    @else
        <div class="ui-table-scroll">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('local_procurement.mmt.fields.submission_number') }}</th>
                        <th scope="col">{{ __('local_procurement.mmt.fields.invoice_number') }}</th>
                        <th scope="col">{{ __('local_procurement.mmt.fields.invoice_date') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('local_procurement.mmt.fields.invoice_amount') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">{{ $invoice->submission_number ?: '—' }}</td>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface ui-col-data-name">{{ $invoice->invoice_number }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">{{ $regionalFormatter->date($invoice->invoice_date, 'human') ?? '—' }}</td>
                            <td class="tw:text-end ui-tabular-nums tw:font-semibold tw:text-on-surface">Rp {{ $regionalFormatter->money($invoice->invoice_amount, 2) }}</td>
                            <td class="tw:text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($invoice->status)">
                                    {{ \App\Support\StatusHelper::localInvoiceLabel($invoice->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-end">
                                @if(\Illuminate\Support\Facades\Gate::allows('view', $invoice))
                                    <x-ui.row-action
                                        :href="route($invoiceRoute, $invoice)"
                                        :context="$invoice->invoice_number"
                                    />
                                @else
                                    <span class="tw:text-on-surface-variant">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@else
    @if($documents->isEmpty())
        <x-ui.empty-state icon="file-text" :title="__('local_procurement.mmt.empty.documents')" class="tw:py-6" />
    @else
        <div class="ui-table-scroll">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('local_procurement.mmt.fields.document_file') }}</th>
                        <th scope="col">{{ __('local_procurement.mmt.fields.uploaded_at') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $attachment)
                        <tr>
                            <td class="tw:font-semibold tw:text-on-surface ui-col-data-name">{{ $attachment->file_name }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">
                                {{ $attachment->created_at ? $regionalFormatter->timestamp($attachment->created_at, 'datetime_comma') : '—' }}
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route('attachments.show', $attachment)"
                                    target="_blank"
                                    rel="noopener"
                                    tone="neutral"
                                    icon="file-text"
                                    :label="__('local_procurement.mmt.fields.open_pdf')"
                                    :context="$attachment->file_name"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
