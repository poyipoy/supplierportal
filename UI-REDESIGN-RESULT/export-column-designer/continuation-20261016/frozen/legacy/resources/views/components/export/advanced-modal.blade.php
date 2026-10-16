@props(['exportKey', 'action', 'suppliers' => [], 'periods' => [], 'initialFilters' => [], 'filterSelectors' => [], 'table' => null, 'triggerId' => null])
@php
    $uid = 'advanced-export-'.str_replace('.', '-', $exportKey);
    $definition = \App\Support\Export\ExportDefinitions::get($exportKey);
    $fields = $definition->filterSchema();
    $copy = collect(['loading', 'failed', 'default_columns', 'move_up', 'move_down', 'selected', 'saved', 'required', 'save_failed'])->mapWithKeys(fn ($key) => [$key => __('exports.advanced.'.$key)])->all();
@endphp
<div data-advanced-export data-export-key="{{ $exportKey }}" data-definition-url="{{ route('exports.definitions.show', $exportKey) }}"
     data-presets-url="{{ route('export-presets.index') }}" data-preset-store-url="{{ route('export-presets.store') }}"
     data-filter-selectors='@json($filterSelectors)' data-initial-filters='@json($initialFilters)' data-export-copy='@json($copy)' data-export-table="{{ $table }}">
    <div class="tw-flex tw-items-center tw-gap-2">
        <x-ui.button type="button" :id="$triggerId" variant="outline" size="sm" data-bs-toggle="modal" data-bs-target="#{{ $uid }}" data-export-open>
            <x-ui.icon name="file-spreadsheet" />{{ __('exports.advanced.title') }}
        </x-ui.button>
        <x-ui.button type="button" variant="ghost" size="sm" data-export-quick>{{ __('exports.advanced.quick') }}</x-ui.button>
    </div>
    <div class="modal fade" id="{{ $uid }}" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="{{ $uid }}-title" aria-describedby="{{ $uid }}-help">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content tw-border-outline tw-bg-surface tw-text-on-surface">
                <div class="modal-header">
                    <h2 class="modal-title tw-text-ui-lg" id="{{ $uid }}-title">{{ __('exports.advanced.title') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
                <form action="{{ $action }}" method="POST" data-advanced-export-form class="tw-flex tw-flex-col tw-min-h-0 tw-overflow-hidden">
                    @csrf
                    <div class="modal-body tw-grid tw-gap-4">
                        <p id="{{ $uid }}-help" class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('exports.advanced.help') }}</p>
                        <p data-export-error hidden role="alert" tabindex="-1" class="tw-m-0 tw-text-ui-sm tw-text-error"></p>
                        <p data-export-status aria-live="polite" class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant"></p>
                        <fieldset class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.filters_title') }}</legend>
                            <div class="tw-grid tw-gap-3 md:tw-grid-cols-2">
                                @if(collect($fields)->contains('type','month'))
                                    <div class="md:tw-col-span-2"><x-ui.date-range-picker :id="$uid.'-months'" granularity="month" start-name="date_from" :start-id="$uid.'-date_from'" :start-label="__('exports.advanced.filters.date_from')" end-name="date_to" :end-id="$uid.'-date_to'" :end-label="__('exports.advanced.filters.date_to')" :error-id="$uid.'-months-error'" /></div>
                                @endif
                                @foreach($fields as $field)
                                    @continue($field['type'] === 'month')
                                    @php($fieldId = $uid.'-'.$field['name'])
                                    <div>
                                        @if($field['type'] === 'date')
                                            <x-ui.date-picker :name="$field['name']" :id="$fieldId" :label="__('exports.advanced.filters.'.$field['name'])" :data-filter-name="$field['name']" />
                                        @else
                                            <label for="{{ $fieldId }}" class="tw-text-ui-sm tw-font-medium">{{ __('exports.advanced.filters.'.$field['name']) }}</label>
                                            @if(in_array($field['type'], ['select','supplier','period'], true))
                                                <select id="{{ $fieldId }}" name="{{ $field['name'] }}" data-filter-name="{{ $field['name'] }}" class="form-select form-select-sm">
                                                    @if(!($field['required'] ?? false))<option value="">{{ __('exports.advanced.all') }}</option>@endif
                                                    @if($field['type'] === 'supplier')
                                                        @foreach($suppliers as $supplier)<option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>@endforeach
                                                    @elseif($field['type'] === 'period')
                                                        @foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->display_label }}</option>@endforeach
                                                    @else
                                                        @foreach($field['options'] as $value)<option value="{{ $value }}">{{ isset($field['labels'][$value]) ? __($field['labels'][$value]) : (($field['domain'] ?? 'po') === 'local_invoice' ? \App\Support\StatusHelper::localInvoiceLabel($value) : (($field['domain'] ?? 'po') === 'currency' ? $value : ($value === 'unresponded' ? __('exports.advanced.unresponded') : __('status.'.($field['domain'] ?? 'po').'.'.$value)))) }}</option>@endforeach
                                                    @endif
                                                </select>
                                            @else
                                                @if($field['type'] === 'boolean')
                                                    <select id="{{ $fieldId }}" name="{{ $field['name'] }}" data-filter-name="{{ $field['name'] }}" class="form-select form-select-sm"><option value="">{{ __('exports.advanced.all') }}</option><option value="1">{{ __('exports.advanced.enabled') }}</option><option value="0">{{ __('exports.advanced.disabled') }}</option></select>
                                                @else
                                                    <input type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" id="{{ $fieldId }}" name="{{ $field['name'] }}" data-filter-name="{{ $field['name'] }}" class="form-control form-control-sm" maxlength="255">
                                                @endif
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="tw-mt-3" @if(!collect($fields)->contains('name','start_date')) hidden @endif>
                                <label class="tw-text-ui-sm tw-inline-flex tw-items-center tw-gap-2"><input type="checkbox" data-export-relative>{{ __('exports.advanced.relative') }}</label>
                                <label for="{{ $uid }}-days" class="tw-text-ui-xs">{{ __('exports.advanced.days') }}</label>
                                <input id="{{ $uid }}-days" type="number" min="1" max="3650" value="30" data-export-days class="form-control form-control-sm" disabled>
                            </div>
                        </fieldset>
                        <fieldset class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.columns_title') }}</legend>
                            <x-ui.button type="button" size="sm" variant="ghost" data-export-reset>{{ __('exports.advanced.reset') }}</x-ui.button>
                            <ul data-export-columns class="tw-list-none tw-m-0 tw-mt-2 tw-p-0 tw-grid tw-gap-1"></ul>
                        </fieldset>
                        <fieldset class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.format_title') }}</legend>
                            <label for="{{ $uid }}-format" class="tw-text-ui-sm">{{ __('exports.advanced.format_title') }}</label>
                            <select id="{{ $uid }}-format" data-export-format class="form-select form-select-sm"><option value="xlsx">XLSX</option><option value="csv">CSV</option></select>
                            <p class="tw-m-0 tw-mt-2 tw-text-ui-xs tw-text-on-surface-variant">{{ __('exports.advanced.csv_hint') }}</p>
                        </fieldset>
                        <fieldset class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.presets_title') }}</legend>
                            <label for="{{ $uid }}-preset" class="tw-text-ui-sm">{{ __('exports.advanced.presets_title') }}</label>
                            <select id="{{ $uid }}-preset" data-export-preset class="form-select form-select-sm"></select>
                            <label for="{{ $uid }}-name" class="tw-mt-2 tw-text-ui-sm">{{ __('exports.advanced.preset_name') }}</label>
                            <input id="{{ $uid }}-name" data-export-preset-name type="text" maxlength="80" class="form-control form-control-sm">
                            <label class="tw-text-ui-sm tw-mt-2 tw-inline-flex tw-items-center tw-gap-2"><input type="checkbox" data-export-default>{{ __('exports.advanced.make_default') }}</label>
                            <div class="tw-flex tw-gap-2 tw-mt-2">
                                <x-ui.button type="button" variant="outline" size="sm" data-export-save>{{ __('exports.advanced.save') }}</x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" data-export-update>{{ __('exports.advanced.update') }}</x-ui.button>
                                <x-ui.button type="button" variant="danger" size="sm" data-export-delete>{{ __('exports.advanced.delete') }}</x-ui.button>
                            </div>
                        </fieldset>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" data-export-submit disabled>{{ __('exports.advanced.export') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <template data-export-column-template>
        <li data-export-column draggable="true" class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-px-2 tw-py-1.5">
            <label class="tw-inline-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-py-1"><input type="checkbox" data-column-check><span data-column-label></span><span data-column-required hidden class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('exports.advanced.required') }}</span></label>
            <div class="tw-inline-flex tw-gap-1">
                <x-ui.button type="button" variant="ghost" size="sm" data-column-up><x-ui.icon name="arrow-up" /></x-ui.button>
                <x-ui.button type="button" variant="ghost" size="sm" data-column-down><x-ui.icon name="arrow-down" /></x-ui.button>
            </div>
        </li>
    </template>
</div>
