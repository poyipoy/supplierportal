<x-ui.metric-card :label="__('customization.density_sample_items')" value="24" icon="package" />
<div class="ui-toolbar"><span class="tw:text-ui-xs">{{ __('customization.density_sample_filter') }}</span><span class="tw:text-ui-xs">{{ __('customization.density_sample_status') }}</span></div>
<div class="ui-data-table"><table class="table"><thead><tr><th>{{ __('customization.density_sample_items') }}</th><th>{{ __('customization.density_sample_status') }}</th></tr></thead><tbody>
@foreach(['A', 'B', 'C'] as $sample)<tr><td>{{ __('customization.density_sample_item', ['item' => $sample]) }}</td><td>{{ __('customization.density_sample_ready') }}</td></tr>@endforeach
</tbody></table></div>
