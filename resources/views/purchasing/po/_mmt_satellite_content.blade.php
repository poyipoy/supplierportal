@if($activeTab === 'items')
    @if($orderedLines->isEmpty())
        <x-ui.empty-state icon="package" :title="__('purchasing.mmt.empty.items')" class="tw:py-6" />
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle tw:m-0 tw:text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-center" style="width: 36px;">{{ __('purchasing.copy.no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                        <th scope="col" class="text-center">{{ __('purchasing.copy.qty') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.total_weight') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orderedLines as $idx => $line)
                        @php
                            $item = $line->quotationItem;
                            $currency = $item->quotation?->currency ?? $po->currency;
                        @endphp
                        <tr>
                            <td class="text-center ui-tabular-nums tw:text-on-surface-variant">{{ $idx + 1 }}</td>
                            <td class="tw:font-semibold tw:text-on-surface">
                                <div>{{ $item->prItem?->material_name ?? '-' }}</div>
                                @if($item->prItem?->shape)
                                    <div class="tw:text-[11px] tw:font-normal tw:text-on-surface-variant">{{ $item->available_dimension_label }}</div>
                                @endif
                            </td>
                            <td class="text-center ui-tabular-nums">{{ $line->qty }} pcs</td>
                            <td class="text-end ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($line->kg), 'decimal') }} kg</td>
                            <td class="text-end ui-tabular-nums tw:text-on-surface-variant">
                                {{ $line->pricePerKg !== null ? $regionalFormatter->groupedDecimal($line->pricePerKg, 4).' '.$currency : '—' }}
                            </td>
                            <td class="text-end ui-tabular-nums tw:font-semibold tw:text-on-surface">
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
        <div class="table-responsive">
            <table class="table table-hover align-middle tw:m-0 tw:text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('shipments.copy.shipment_no') }}</th>
                        <th scope="col">{{ __('shipments.copy.shipment_date') }}</th>
                        <th scope="col">{{ __('shipments.copy.target_actual_arrival') }}</th>
                        <th scope="col" class="text-center">{{ __('shipments.copy.status') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipments as $shipment)
                        <tr>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface">{{ $shipment->shipment_number }}</td>
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
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)">
                                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.shipments.show', $shipment)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>{{ __('shipments.copy.view_details') }}</span>
                                </x-ui.button>
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
        <div class="table-responsive">
            <table class="table table-hover align-middle tw:m-0 tw:text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('claims.copy.claim_id') }}</th>
                        <th scope="col">{{ __('claims.copy.expected_resolution') }}</th>
                        <th scope="col">{{ __('claims.copy.response_deadline') }}</th>
                        <th scope="col" class="text-center">{{ __('claims.copy.status') }}</th>
                        <th scope="col" class="text-end">{{ __('claims.copy.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($claims as $claim)
                        <tr>
                            <td class="ui-tabular-nums tw:font-semibold tw:text-on-surface">{{ $claim->claim_number }}</td>
                            <td class="tw:text-on-surface">{{ \Illuminate\Support\Str::limit($claim->resolution_expected ?: $claim->description, 80) }}</td>
                            <td class="ui-tabular-nums tw:text-on-surface-variant">
                                {{ $claim->deadline ? $regionalFormatter->date($claim->deadline, 'human') : '—' }}
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::claimTone($claim->status)">
                                    {{ \App\Support\StatusHelper::claimLabel($claim->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $claim)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>{{ __('claims.copy.view_details') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
