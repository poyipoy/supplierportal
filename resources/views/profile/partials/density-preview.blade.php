@php
    $sampleRows = [
        ['code' => 'PR-2026-014', 'item' => 'A', 'tone' => 'success'],
        ['code' => 'PR-2026-019', 'item' => 'B', 'tone' => 'info'],
        ['code' => 'PR-2026-023', 'item' => 'C', 'tone' => 'warning'],
    ];
@endphp
<x-ui.metric-card :label="__('customization.density_sample_items')" value="24" icon="package" />
<div class="ui-toolbar">
    <div class="ui-toolbar__left">
        <span class="tw:inline-flex tw:items-center tw:gap-1.5 tw:text-ui-xs tw:font-medium tw:text-on-surface">
            <x-ui.icon name="search" size="xs" class="tw:text-on-surface-variant" />
            <span>{{ __('customization.density_sample_filter') }}</span>
        </span>
    </div>
    <div class="ui-toolbar__right">
        <span class="ui-tabular-nums tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">3 / 24</span>
    </div>
</div>
<div class="ui-data-table">
    <table class="ui-table">
        <thead>
            <tr>
                <th scope="col">{{ __('customization.density_sample_code') }}</th>
                <th scope="col">{{ __('customization.density_sample_items') }}</th>
                <th scope="col">{{ __('customization.density_sample_status') }}</th>
                <th scope="col" class="tw:text-right">{{ __('customization.density_sample_action') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sampleRows as $row)
                <tr>
                    <td class="ui-tabular-nums tw:font-medium tw:whitespace-nowrap">{{ $row['code'] }}</td>
                    <td class="ui-col-data-name">{{ __('customization.density_sample_item', ['item' => $row['item']]) }}</td>
                    <td><x-ui.status-chip :tone="$row['tone']">{{ __('customization.density_sample_ready') }}</x-ui.status-chip></td>
                    <td class="tw:text-right"><span class="ui-data-action ui-data-action--primary">{{ __('customization.density_sample_open') }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
