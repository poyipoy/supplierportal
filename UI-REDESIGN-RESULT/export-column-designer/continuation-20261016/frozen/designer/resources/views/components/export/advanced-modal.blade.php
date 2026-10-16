@props(['exportKey', 'action', 'suppliers' => [], 'periods' => [], 'initialFilters' => [], 'filterSelectors' => [], 'table' => null, 'triggerId' => null])
@php
    $uid = 'advanced-export-'.str_replace('.', '-', $exportKey);
    $definition = \App\Support\Export\ExportDefinitions::get($exportKey);
    $fields = $definition->filterSchema();
    $copy = collect(['loading', 'failed', 'default_columns', 'saved', 'required', 'save_failed', 'invalid_columns', 'visible_summary', 'active_column', 'column_position', 'move_left', 'move_right', 'hide_required', 'hide_last', 'hidden_none', 'restore_before', 'restore_end', 'hidden_restored', 'column_hidden', 'columns_reset', 'drag_position', 'drag_cancelled', 'keyboard_hint', 'unhide'])->mapWithKeys(fn ($key) => [$key => __('exports.advanced.'.$key)])->all();
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
    <div class="modal fade ui-export-designer-modal" id="{{ $uid }}" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="{{ $uid }}-title" aria-describedby="{{ $uid }}-help">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content tw-border-outline tw-bg-surface tw-text-on-surface">
                <div class="modal-header">
                    <h2 class="modal-title tw-text-ui-lg" id="{{ $uid }}-title" tabindex="-1">{{ __('exports.advanced.title') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
                <form action="{{ $action }}" method="POST" data-advanced-export-form class="tw-flex tw-flex-col tw-min-h-0 tw-overflow-hidden">
                    @csrf
                    <div class="modal-body ui-export-designer__body tw-grid tw-gap-4">
                        <p id="{{ $uid }}-help" class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('exports.advanced.help') }}</p>
                        <p data-export-error hidden role="alert" tabindex="-1" class="tw-m-0 tw-text-ui-sm tw-text-error"></p>
                        <x-ui.button type="button" size="sm" variant="outline" data-export-retry hidden>{{ __('exports.advanced.retry') }}</x-ui.button>
                        <p data-export-status aria-live="polite" class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant"></p>
                        <fieldset class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.filters_title') }}</legend>
                            <div class="tw-grid tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-3">
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
                        <fieldset data-column-designer class="ui-column-designer tw-border tw-border-outline tw-rounded-ui-sm tw-p-3" disabled>
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.columns_title') }}</legend>
                            <div class="ui-column-ribbon" role="group" aria-label="{{ __('exports.advanced.column_actions') }}">
                                <x-ui.button type="button" size="sm" variant="outline" data-column-command="hide"><x-ui.icon name="eye-off" />{{ __('exports.advanced.hide') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="outline" data-column-command="unhide" aria-controls="{{ $uid }}-unhide"><x-ui.icon name="eye" />{{ __('exports.advanced.unhide') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" data-column-command="move-left"><x-ui.icon name="arrow-left" />{{ __('exports.advanced.left') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" data-column-command="move-right"><x-ui.icon name="arrow-right" />{{ __('exports.advanced.right') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" data-column-command="menu" aria-haspopup="menu" aria-controls="{{ $uid }}-menu"><x-ui.icon name="ellipsis" />{{ __('exports.advanced.column_menu') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" data-column-command="reset" data-export-reset>{{ __('exports.advanced.reset') }}</x-ui.button>
                            </div>
                            <p id="{{ $uid }}-keyboard" class="ui-column-help">{{ __('exports.advanced.keyboard_hint') }}</p>
                            <div class="ui-column-summary"><span data-column-summary></span><span data-column-selected-label></span></div>
                            <div data-column-scroll class="ui-column-scroll">
                                <div class="ui-column-sheet">
                                    <div id="{{ $uid }}-columns" data-export-columns class="ui-column-sheet__headers" role="listbox" aria-orientation="horizontal" aria-label="{{ __('exports.advanced.select_column') }}" aria-describedby="{{ $uid }}-keyboard"></div>
                                    <div data-column-empty-rows aria-hidden="true"></div>
                                </div>
                            </div>
                            <p data-column-feedback class="tw-sr-only" aria-live="polite" aria-atomic="true"></p>
                        </fieldset>
                        <fieldset data-export-format-section class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.format_title') }}</legend>
                            <label for="{{ $uid }}-format" class="tw-text-ui-sm">{{ __('exports.advanced.format_title') }}</label>
                            <select id="{{ $uid }}-format" data-export-format class="form-select form-select-sm"><option value="xlsx">XLSX</option><option value="csv">CSV</option></select>
                            <p class="tw-m-0 tw-mt-2 tw-text-ui-xs tw-text-on-surface-variant">{{ __('exports.advanced.csv_hint') }}</p>
                        </fieldset>
                        <fieldset data-export-presets-section class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-3">
                            <legend class="tw-float-none tw-w-auto tw-px-1 tw-text-ui-sm tw-font-semibold">{{ __('exports.advanced.presets_title') }}</legend>
                            <label for="{{ $uid }}-preset" class="tw-text-ui-sm">{{ __('exports.advanced.presets_title') }}</label>
                            <select id="{{ $uid }}-preset" data-export-preset class="form-select form-select-sm"></select>
                            <p class="ui-column-help">{{ __('exports.advanced.preset_columns_help') }}</p>
                            <label for="{{ $uid }}-name" class="tw-mt-2 tw-text-ui-sm">{{ __('exports.advanced.preset_name') }}</label>
                            <input id="{{ $uid }}-name" data-export-preset-name type="text" maxlength="80" class="form-control form-control-sm">
                            <label class="tw-text-ui-sm tw-mt-2 tw-inline-flex tw-items-center tw-gap-2"><input type="checkbox" data-export-default>{{ __('exports.advanced.make_default') }}</label>
                            <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2">
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
        <div id="{{ $uid }}-menu" data-column-menu data-column-layer class="ui-column-layer ui-column-menu" role="menu" aria-label="{{ __('exports.advanced.column_menu') }}" hidden>
            <button type="button" role="menuitem" data-column-command="hide">{{ __('exports.advanced.hide') }}</button>
            <button type="button" role="menuitem" data-column-command="unhide">{{ __('exports.advanced.unhide') }}</button>
            <button type="button" role="menuitem" data-column-command="move-left">{{ __('exports.advanced.left') }}</button>
            <button type="button" role="menuitem" data-column-command="move-right">{{ __('exports.advanced.right') }}</button>
            <button type="button" role="menuitem" data-column-command="reset">{{ __('exports.advanced.reset') }}</button>
        </div>
        <div id="{{ $uid }}-unhide" data-column-unhide data-column-layer class="ui-column-layer ui-column-unhide" role="group" aria-labelledby="{{ $uid }}-unhide-label" hidden>
            <label id="{{ $uid }}-unhide-label" for="{{ $uid }}-hidden-select">{{ __('exports.advanced.unhide') }}</label>
            <select id="{{ $uid }}-hidden-select" data-column-hidden-select class="form-select form-select-sm" aria-describedby="{{ $uid }}-restore-position"></select>
            <p id="{{ $uid }}-restore-position" data-column-restore-position class="ui-column-help"></p>
            <div class="tw-flex tw-flex-wrap tw-gap-2">
                <x-ui.button type="button" size="sm" data-column-command="restore">{{ __('exports.advanced.restore') }}</x-ui.button>
                <x-ui.button type="button" size="sm" variant="ghost" data-column-command="close-layer">{{ __('common.actions.cancel') }}</x-ui.button>
            </div>
        </div>
    </div>
    <template data-export-column-template>
        <div data-export-column draggable="true" class="ui-column-header" role="option" tabindex="-1" aria-selected="false">
            <span data-column-letter class="ui-column-header__letter" aria-hidden="true"></span>
            <span data-column-label class="ui-column-header__label"></span>
            <span data-column-required class="ui-column-header__required" hidden>{{ __('exports.advanced.required') }}</span>
        </div>
    </template>
</div>
