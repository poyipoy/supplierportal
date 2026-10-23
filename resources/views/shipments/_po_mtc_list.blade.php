{{-- Mission 05 (Q6B): MTC per shipment on the Purchasing PO page (read only). --}}
@php($mtcShipments = $po->shipments()->filter(fn ($shipment) => $shipment->mtcAttachments->isNotEmpty()))
@if(\App\Support\Procurement\ProcurementFeatures::shipmentMtcEnabled() || $mtcShipments->isNotEmpty())
    <x-ui.card :title="__('shipments.mtc.purchasing_title')" id="sec-mtc" class="tw:scroll-mt-24" data-po-mtc>
        @forelse($mtcShipments as $shipment)
            <div class="tw:py-2 @if(! $loop->first) tw:border-t tw:border-outline-variant @endif">
                <a href="{{ route('purchasing.shipments.show', $shipment) }}" class="tw:text-ui-xs fw-semibold text-primary text-decoration-none">{{ $shipment->shipment_number }}</a>
                <ul class="list-unstyled tw:m-0 tw:mt-1 tw:grid tw:gap-1 tw:text-ui-xs">
                    @foreach($shipment->mtcAttachments as $file)
                        <li>
                            <a href="{{ route('attachments.show', $file) }}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-1 text-decoration-none">
                                <x-ui.icon name="file-text" size="sm" />
                                <span class="text-truncate" style="max-width: 20rem;" title="{{ $file->file_name }}">{{ $file->file_name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('shipments.mtc.none') }}</p>
        @endforelse
    </x-ui.card>
@endif
