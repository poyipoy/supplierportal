{{--
    Pre-Shipment Inspection snapshot of one shipment (read-only for Purchasing,
    optional Edit trigger for Supplier while Draft or Submitted).
    @param \App\Models\Shipment $shipment        preshipmentInspection loaded
    @param bool                 $canEdit         Show Edit PSI action button (Supplier owner on Draft/Submitted)
    @param string               $editDialogName  Target x-ui.dialog name
--}}
@php
    $inspection = $shipment->preshipmentInspection;
    $canEdit = $canEdit ?? false;
    $editDialogName = $editDialogName ?? 'edit-shipment-psi';
    $revisionCount = (int) ($inspection?->revision_count ?? 0);
    $groups = [
        'exporter' => [__('shipments.preshipment.exporter_title'), ['name', 'contact', 'address', 'city', 'country', 'zip_code', 'telephone', 'mobile', 'fax', 'email', 'port_of_loading']],
        'supplier' => [__('shipments.preshipment.supplier_title'), ['name', 'contact', 'address', 'country', 'city', 'telephone', 'mobile', 'fax', 'email']],
    ];
@endphp
<x-ui.card :title="__('shipments.preshipment.title')" id="shipmentPreshipmentDetail">
    @if($canEdit || $revisionCount > 0)
        <x-slot:actions>
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                @if($revisionCount > 0)
                    <x-ui.status-chip tone="warning" size="sm">
                        {{ __('shipments.copy.revised_badge', ['count' => $revisionCount]) }}
                    </x-ui.status-chip>
                @endif
                @if($canEdit)
                    <x-ui.button type="button" variant="outline" size="sm" x-data @click="$dispatch('open-ui-dialog', @js($editDialogName))">
                        <x-slot:leading><x-ui.icon name="pencil" size="sm" /></x-slot:leading>
                        <span>{{ __('shipments.preshipment.edit_psi') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </x-slot:actions>
    @endif

    @if($revisionCount > 0 && $inspection?->last_revised_at)
        <p class="tw:m-0 tw:mb-3 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">
            {{ __('shipments.copy.last_revised_at', ['date' => $regionalFormatter->timestamp($inspection->last_revised_at, 'datetime')]) }}
        </p>
    @endif

    @if(! $inspection)
        <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('shipments.preshipment.none') }}</p>
    @else
        <div class="tw:grid tw:gap-4 tw:lg:grid-cols-2">
            @foreach($groups as $prefix => [$title, $fields])
                <section class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-low tw:p-3">
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:mb-2">
                        <h3 class="tw:m-0 tw:text-ui-sm tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ $title }}</h3>
                        @if($prefix === 'supplier' && $inspection->supplier_same_as_exporter)
                            <x-ui.status-chip tone="info" size="sm">{{ __('shipments.preshipment.same_as_exporter_badge') }}</x-ui.status-chip>
                        @endif
                    </div>
                    <dl class="tw:grid tw:grid-cols-[minmax(7rem,auto)_1fr] tw:gap-x-3 tw:gap-y-1.5 tw:text-ui-xs tw:m-0">
                        @foreach($fields as $field)
                            @php
                                $raw = $inspection->{$prefix.'_'.$field};
                                $display = $field === 'country'
                                    ? \App\Support\CountryCatalog::label($raw)
                                    : ($raw ?: '-');
                            @endphp
                            <dt class="tw:text-on-surface-variant tw:font-semibold">{{ __('shipments.preshipment.'.$field) }}</dt>
                            <dd class="tw:m-0 tw:text-on-surface tw:break-words">{{ $display }}</dd>
                        @endforeach
                    </dl>
                </section>
            @endforeach
        </div>
    @endif
</x-ui.card>
