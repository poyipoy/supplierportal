@if($activeTab === 'items')
    @if($orderedLines->isEmpty())
        <x-ui.empty-state icon="package" :title="__('purchasing.mmt.empty.items')" class="tw:py-6" />
    @else
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col" class="tw:text-center" style="width: 36px;">{{ __('purchasing.copy.no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('purchasing.copy.qty') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('purchasing.copy.total_weight') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('purchasing.copy.price_kg') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('purchasing.copy.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orderedLines as $idx => $line)
                        @php
                            $item = $line->quotationItem;
                            $currency = $item->quotation?->currency ?? $po->currency;
                        @endphp
                        <tr>
                            <td class="tw:text-center ui-tabular-nums tw:text-on-surface-variant">{{ $idx + 1 }}</td>
                            <td class="tw:font-semibold tw:text-on-surface ui-col-data-name">
                                <div>{{ $item->prItem?->material_name ?? '-' }}</div>
                                @if($item->prItem?->shape)
                                    <div class="tw:text-[11px] tw:font-normal tw:text-on-surface-variant">{{ $item->available_dimension_label }}</div>
                                @endif
                            </td>
                            <td class="tw:text-center ui-tabular-nums">{{ $line->qty }} pcs</td>
                            <td class="tw:text-end ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($line->kg), 'decimal') }} kg</td>
                            <td class="tw:text-end ui-tabular-nums tw:text-on-surface-variant">
                                {{ $line->pricePerKg !== null ? $regionalFormatter->groupedDecimal($line->pricePerKg, 4).' '.$currency : '—' }}
                            </td>
                            <td class="tw:text-end ui-tabular-nums tw:font-semibold tw:text-on-surface">
                                {{ $regionalFormatter->groupedDecimal($line->amount).' '.$currency }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@elseif($activeTab === 'shipments')
    @if($shipments->isEmpty())
        <x-ui.empty-state icon="truck" :title="__('purchasing.mmt.empty.shipments')" class="tw:py-6" />
    @else
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('shipments.copy.shipment_no') }}</th>
                        <th scope="col">{{ __('shipments.copy.shipment_date') }}</th>
                        <th scope="col">{{ __('shipments.copy.target_actual_arrival') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('shipments.copy.status') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipments as $shipment)
                        <tr>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface ui-col-data-name">{{ $shipment->shipment_number }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">
                                {{ $shipment->shipment_date ? $regionalFormatter->date($shipment->shipment_date, 'human') : '—' }}
                            </td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">
                                @if($shipment->actual_arrival_date)
                                    {{ $regionalFormatter->date($shipment->actual_arrival_date, 'human') }}
                                @elseif($shipment->estimated_arrival_date)
                                    {{ $regionalFormatter->date($shipment->estimated_arrival_date, 'human') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="tw:text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)">
                                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="\App\Support\PurchasingNavigation::toRoute('purchasing.shipments.show', $shipment)"
                                    :context="$shipment->shipment_number"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@else
    @if($claims->isEmpty())
        <x-ui.empty-state icon="alert-triangle" :title="__('purchasing.mmt.empty.claims')" class="tw:py-6" />
    @else
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('claims.copy.claim_id') }}</th>
                        <th scope="col">{{ __('claims.copy.expected_resolution') }}</th>
                        <th scope="col">{{ __('claims.copy.response_deadline') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('claims.copy.status') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($claims as $claim)
                        <tr>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface">{{ $claim->claim_number }}</td>
                            <td class="tw:text-on-surface ui-col-data-name">{{ \Illuminate\Support\Str::limit($claim->resolution_expected ?: $claim->description, 80) }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">
                                {{ $claim->deadline ? $regionalFormatter->date($claim->deadline, 'human') : '—' }}
                            </td>
                            <td class="tw:text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::claimTone($claim->status)">
                                    {{ \App\Support\StatusHelper::claimLabel($claim->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $claim)"
                                    :context="$claim->claim_number"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
