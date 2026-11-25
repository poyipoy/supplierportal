<x-ui.data-table
    density="compact"
    :empty="$rows->isEmpty()"
>
    <x-slot:emptyState>
        <x-ui.empty-state
            icon="package-search"
            :title="__('purchasing.outstanding_materials.empty_title')"
            :description="__('purchasing.outstanding_materials.empty_description')"
        />
    </x-slot:emptyState>

    <table class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-sm tw:w-full" id="outstandingMaterialTable">
        <thead>
            <tr>
                <th scope="col" class="tw:text-center" style="width: 48px;">{{ __('purchasing.outstanding_materials.col_no') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_po') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_invoice_reference') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_supplier') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_material') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_dimension') }}</th>
                <th scope="col" class="tw:text-center">{{ __('purchasing.outstanding_materials.col_status') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_port_of_loading') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_port_of_discharge') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_est_arrival') }}</th>
                <th scope="col">{{ __('purchasing.outstanding_materials.col_actual_arrival') }}</th>
            </tr>
        </thead>
        <tbody>
            @php
                $rowOffset = ($rows->currentPage() - 1) * $rows->perPage();
            @endphp
            @foreach($rows as $index => $row)
                <tr>
                    <td class="tw:text-center tw:text-on-surface-variant ui-tabular-nums">{{ $rowOffset + $index + 1 }}</td>
                    <td class="tw:font-bold tw:text-on-surface ui-tabular-nums">
                        <a href="{{ route($poShowRouteName, $row['po']) }}" class="tw:text-primary tw:no-underline">
                            {{ $row['po_number'] }}
                        </a>
                    </td>
                    <td class="ui-tabular-nums tw:text-on-surface">
                        @if(! empty($row['invoice_references']))
                            <div class="tw:grid tw:gap-1">
                                @foreach($row['invoice_references'] as $ref)
                                    <div class="tw:leading-tight">
                                        @if($ref['invoice_number'] !== '')
                                            <div class="tw:font-medium">{{ $ref['invoice_number'] }}</div>
                                        @endif
                                        @if($ref['supplier_reference'] !== '')
                                            <div class="tw:text-ui-xs tw:text-on-surface-variant">
                                                {{ __('purchasing.outstanding_materials.ref_prefix') }} {{ $ref['supplier_reference'] }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <span class="tw:text-on-surface-variant">-</span>
                        @endif
                    </td>
                    <td class="tw:font-semibold">{{ $row['supplier_name'] }}</td>
                    <td class="tw:font-medium">{{ $row['material_name'] }}</td>
                    <td class="ui-tabular-nums tw:text-on-surface-variant">{{ $row['dimension'] ?: '-' }}</td>
                    <td class="tw:text-center">
                        <div class="tw:inline-flex tw:flex-wrap tw:items-center tw:justify-center tw:gap-1">
                            <x-ui.status-chip :tone="$row['status_tone']" size="sm">
                                {{ $row['status_label'] }}
                            </x-ui.status-chip>
                            @if(! empty($row['is_overdue']))
                                <x-ui.status-chip tone="error" size="sm">
                                    {{ \App\Support\StatusHelper::poLabel('overdue') }}
                                </x-ui.status-chip>
                            @endif
                        </div>
                    </td>
                    <td>{{ $row['port_of_loading'] ?: '-' }}</td>
                    <td>{{ $row['port_of_discharge'] ?: '-' }}</td>
                    <td class="ui-tabular-nums tw:text-on-surface-variant">
                        {{ $row['estimated_arrival'] ? $regionalFormatter->date($row['estimated_arrival'], 'human') : '-' }}
                    </td>
                    <td class="ui-tabular-nums tw:text-on-surface-variant">
                        @if($row['actual_arrival'])
                            <span class="tw:text-success tw:font-semibold">{{ $regionalFormatter->date($row['actual_arrival'], 'human') }}</span>
                        @else
                            <span>-</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($rows->hasPages())
        <x-slot:footer>
            {{ $rows->links() }}
        </x-slot:footer>
    @endif
</x-ui.data-table>
