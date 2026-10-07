@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('admin.copy.master_material_hs_code_adasi_portal'))
@section('page-title', __('admin.copy.master_material_hs_code'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="__('admin.copy.master_material_and_hs_code')" :description="__('admin.copy.maintain_material_mappings_deterministic_hs_code_rules_and_master_data_quality_signals')" :eyebrow="__('admin.copy.admin_master_data')" />

    <section class="tw-min-w-0 tw-border tw-border-outline tw-bg-surface" aria-labelledby="master-data-workspace-title">
        <h2 id="master-data-workspace-title" class="tw-sr-only">{{ __('admin.copy.master_data_workspace') }}</h2>
        <div class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-3 tw-pt-3 shell:tw-px-4">
            <ul class="nav nav-tabs border-0 gap-1" id="masterTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active rounded-1" id="materials-tab" data-bs-toggle="tab" data-bs-target="#materials" type="button" role="tab" aria-controls="materials" aria-selected="true">{{ __('admin.copy.materials') }}</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link rounded-1" id="rules-tab" data-bs-toggle="tab" data-bs-target="#rules" type="button" role="tab" aria-controls="rules" aria-selected="false">{{ __('admin.copy.hs_code_rules') }}</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link rounded-1" id="data-quality-tab" data-bs-toggle="tab" data-bs-target="#data-quality" type="button" role="tab" aria-controls="data-quality" aria-selected="false">{{ __('admin.copy.data_quality') }}</button></li>
            </ul>
        </div>

        <div class="tab-content tw-p-3 shell:tw-p-4">
            <div class="tab-pane fade show active" id="materials" role="tabpanel" aria-labelledby="materials-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-1">
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.material_register') }}</h3>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.searchable_operational_codes_and_their_calculation_and_hs_mapping_attributes') }}</p>
                </div>
                <x-ui.toolbar aria-label="{{ __('admin.copy.material_master_controls') }}">
                    <x-slot:search>
                        <x-ui.input name="material_search" id="materialSearch" type="search" :placeholder="__('admin.copy.search_material_code_or_category')" aria-label="{{ __('admin.copy.search_materials') }}" autocomplete="off" />
                    </x-slot:search>
                    <x-slot:filters>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialStatusFilter">{{ __('admin.copy.status') }}
                            <select id="materialStatusFilter" class="form-select form-select-sm tw-min-w-36"><option value="">{{ __('admin.copy.all_statuses') }}</option><option value="active">{{ __('admin.copy.active') }}</option><option value="inactive">{{ __('admin.copy.inactive') }}</option></select>
                        </label>
                        <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#materialMoreFilters" aria-expanded="false" aria-controls="materialMoreFilters"><x-ui.icon name="sliders-horizontal" /> {{ __('admin.copy.more_filters') }}</x-ui.button>
                    </x-slot:filters>
                    <x-slot:actions>
                        <x-ui.button type="button" variant="ghost" size="sm" id="resetMaterialFilters"><x-ui.icon name="rotate-ccw" /> {{ __('admin.copy.reset') }}</x-ui.button>
                        <x-ui.button type="button" size="sm" id="btnAddMaterial"><x-ui.icon name="plus" /> {{ __('admin.copy.add_material') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
                <div class="collapse" id="materialMoreFilters">
                    <div class="tw-mb-4 tw-grid tw-gap-3 tw-border tw-border-outline tw-bg-surface-container tw-p-4 md:tw-grid-cols-3">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialCategoryFilter">{{ __('admin.copy.hs_category') }}
                            <select id="materialCategoryFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_hs_categories') }}</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialDensityFilter">{{ __('admin.copy.density_profile') }}
                            <select id="materialDensityFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_density_profiles') }}</option>@foreach($densityProfiles as $density)<option value="{{ $density }}">{{ \App\Models\MaterialMaster::densityProfileLabel($density) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialManufacturerFilter">{{ __('admin.copy.manufacturer_scope') }}
                            <select id="materialManufacturerFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_manufacturer_scopes') }}</option>@foreach($manufacturerScopes as $scope)<option value="{{ $scope }}">{{ \App\Models\MaterialMaster::manufacturerScopeLabel($scope) }}</option>@endforeach</select>
                        </label>
                    </div>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="materialsTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th scope="col" class="text-center">{{ __('admin.copy.no') }}</th><th scope="col">{{ __('admin.copy.material_code') }}</th><th scope="col">{{ __('admin.copy.raw_category') }}</th><th scope="col">{{ __('admin.copy.hs_category') }}</th><th scope="col">{{ __('admin.copy.density') }}</th><th scope="col">{{ __('admin.copy.manufacturer') }}</th><th scope="col">{{ __('admin.copy.status') }}</th><th scope="col">{{ __('admin.copy.source') }}</th><th scope="col">{{ __('admin.copy.updated') }}</th><th scope="col" class="text-end">{{ __('admin.copy.actions') }}</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="rules" role="tabpanel" aria-labelledby="rules-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-1">
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.hs_code_rule_register') }}</h3>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.prioritized_dimensional_rules_used_by_the_deterministic_hs_code_mapping_workflow') }}</p>
                </div>
                <x-ui.toolbar aria-label="{{ __('admin.copy.hs_code_rule_controls') }}">
                    <x-slot:search>
                        <x-ui.input name="rule_search" id="ruleSearch" type="search" :placeholder="__('admin.copy.search_hs_code_or_category')" aria-label="{{ __('admin.copy.search_hs_code_rules') }}" autocomplete="off" />
                    </x-slot:search>
                    <x-slot:filters>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleStatusFilter">{{ __('admin.copy.status') }}
                            <select id="ruleStatusFilter" class="form-select form-select-sm tw-min-w-36"><option value="">{{ __('admin.copy.all_statuses') }}</option><option value="active">{{ __('admin.copy.active') }}</option><option value="inactive">{{ __('admin.copy.inactive') }}</option><option value="conflict">{{ __('admin.copy.conflict') }}</option></select>
                        </label>
                        <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#ruleMoreFilters" aria-expanded="false" aria-controls="ruleMoreFilters"><x-ui.icon name="sliders-horizontal" /> {{ __('admin.copy.more_filters') }}</x-ui.button>
                    </x-slot:filters>
                    <x-slot:actions>
                        <x-ui.button type="button" variant="ghost" size="sm" id="resetRuleFilters"><x-ui.icon name="rotate-ccw" /> {{ __('admin.copy.reset') }}</x-ui.button>
                        <x-ui.button type="button" size="sm" id="btnAddRule"><x-ui.icon name="plus" /> {{ __('admin.copy.add_rule') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
                <div class="collapse" id="ruleMoreFilters">
                    <div class="tw-mb-4 tw-grid tw-gap-3 tw-border tw-border-outline tw-bg-surface-container tw-p-4 md:tw-grid-cols-2">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleCategoryFilter">{{ __('admin.copy.category') }}
                            <select id="ruleCategoryFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_categories') }}</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleShapeFilter">{{ __('admin.copy.shape') }}
                            <select id="ruleShapeFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_shapes') }}</option>@foreach($shapes as $shape)<option value="{{ $shape }}">{{ \App\Models\PrItem::shapeLabel($shape) }}</option>@endforeach</select>
                        </label>
                    </div>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="rulesTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th scope="col" class="text-center">{{ __('admin.copy.no') }}</th><th scope="col">HS Code</th><th scope="col">{{ __('admin.copy.category') }}</th><th scope="col">{{ __('admin.copy.shape') }}</th><th scope="col">{{ __('admin.copy.dimension_conditions') }}</th><th scope="col" class="text-center">{{ __('admin.copy.priority') }}</th><th scope="col">{{ __('admin.copy.status') }}</th><th scope="col">{{ __('admin.copy.source') }}</th><th scope="col">{{ __('admin.copy.updated') }}</th><th scope="col" class="text-end">{{ __('admin.copy.actions') }}</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="data-quality" role="tabpanel" aria-labelledby="data-quality-tab" tabindex="0">
                <div id="qualityLoading" class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-12" role="status">
                    <span class="ui-spinner" aria-hidden="true"></span><span class="tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">{{ __('admin.copy.analyzing_master_data') }}</span>
                </div>
                <div id="qualityContent" class="d-none">
                    <section class="tw-border-y tw-border-outline-variant tw-bg-surface-container" aria-labelledby="quality-summary-title">
                        <h3 id="quality-summary-title" class="tw-sr-only">{{ __('admin.copy.data_quality_summary') }}</h3>
                        <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-5">
                            @foreach([
                                ['id' => 'qualityMaterials', 'label' => __('admin.copy.materials')],
                                ['id' => 'qualityMapped', 'label' => __('admin.copy.with_hs_mapping')],
                                ['id' => 'qualityNeedsMapping', 'label' => __('admin.copy.needs_mapping')],
                                ['id' => 'qualityActiveRules', 'label' => __('admin.copy.active_rules')],
                                ['id' => 'qualityNeedsReview', 'label' => __('admin.copy.needs_review')],
                            ] as $metric)
                                <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-3 last:tw-border-r-0 lg:tw-border-b-0">
                                    <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ $metric['label'] }}</dt>
                                    <dd id="{{ $metric['id'] }}" class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-lg tw-font-semibold">-</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <div id="qualityError" class="d-none tw-mt-4 tw-border tw-border-error/40 tw-bg-error-container tw-p-3 tw-text-ui-sm tw-text-on-surface" role="alert">{{ __('admin.copy.data_quality_could_not_be_loaded_try_opening_this_tab_again') }}</div>

                    <div class="tw-mt-5 tw-grid tw-gap-5 lg:tw-grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.8fr)]">
                        <section class="tw-min-w-0 tw-border tw-border-outline tw-bg-surface-container" aria-labelledby="quality-attention-title">
                            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                                <h3 id="quality-attention-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.needs_attention') }}</h3>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.records_that_can_affect_automatic_hs_code_results') }}</p>
                            </header>
                            <div class="tw-divide-y tw-divide-outline-variant tw-text-ui-sm">
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.materials_without_hs_mapping') }}</div><div id="unmappedMaterials" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.categories_without_active_hs_rules') }}</div><div id="categoriesWithoutRules" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.rules_needing_review') }}</div><div id="rulesNeedingReview" class="tw-mt-2"></div></div>
                            </div>
                        </section>
                        <section class="tw-min-w-0 tw-border tw-border-outline tw-bg-surface-container" aria-labelledby="quality-reference-title">
                            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                                <h3 id="quality-reference-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.reference_context') }}</h3>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.useful_context_that_does_not_require_immediate_action') }}</p>
                            </header>
                            <div class="tw-divide-y tw-divide-outline-variant tw-text-ui-sm">
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.duplicate_rule_coverage') }}</div><div id="duplicateRuleCoverage" class="tw-mt-2 tw-text-on-surface-variant"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.inactive_rules_retained_for_reference') }}</div><div id="inactiveRulesForReference" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.rule_categories_not_used_by_materials') }}</div><div id="unusedRuleCategories" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">{{ __('admin.copy.reference_only_materials') }}</div><div id="referenceOnlyMaterials" class="tw-mt-2"></div></div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

@include('admin.material-hs-code._material_form')
@include('admin.material-hs-code._rule_form')
@endsection

@push('scripts')
@include('admin.material-hs-code._script')
@endpush
