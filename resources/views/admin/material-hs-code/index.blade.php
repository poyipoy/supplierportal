@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('admin.copy.master_material_hs_code_adasi_portal'))
@section('page-title', __('admin.copy.master_material_hs_code'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('admin.copy.master_material_and_hs_code')" :description="__('admin.copy.maintain_material_mappings_deterministic_hs_code_rules_and_master_data_quality_signals')" :eyebrow="__('admin.copy.admin_master_data')" />

    <x-ui.tab-list
        :label="__('admin.copy.master_data_workspace')"
        active="materials"
        :tabs="[
            ['id' => 'materials', 'label' => __('admin.copy.materials'), 'icon' => 'boxes'],
            ['id' => 'rules', 'label' => __('admin.copy.hs_code_rules'), 'icon' => 'sliders-horizontal'],
            ['id' => 'data-quality', 'label' => __('admin.copy.data_quality'), 'icon' => 'shield-check'],
        ]"
    />

    <div class="tab-content" id="masterTabsContent">
        <div class="tab-pane fade show active" id="materials" role="tabpanel" aria-labelledby="materials-tab" tabindex="0">
            <x-ui.data-table
                :title="__('admin.copy.material_register')"
                :description="__('admin.copy.searchable_operational_codes_and_their_calculation_and_hs_mapping_attributes')"
            >
                <x-slot:actions>
                    <x-ui.button type="button" size="sm" id="btnAddMaterial">
                        <x-ui.icon name="plus" size="sm" />
                        <span>{{ __('admin.copy.add_material') }}</span>
                    </x-ui.button>
                </x-slot:actions>
                <x-slot:filters>
                    <x-ui.filter-bar
                        id="materialsFilterBar"
                        mode="live"
                        variant="embedded"
                        class="tw:w-full"
                        :aria-label="__('admin.copy.material_master_controls')"
                        advanced-id="materialMoreFilters"
                        :advanced-label="__('admin.copy.more_filters')"
                        reset-id="resetMaterialFilters"
                        :reset-label="__('admin.copy.reset')"
                    >
                        <x-slot:search>
                            <label for="materialSearch" class="tw:sr-only">{{ __('admin.copy.search_materials') }}</label>
                            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                            <input
                                name="material_search"
                                id="materialSearch"
                                type="search"
                                placeholder="{{ __('admin.copy.search_material_code_or_category') }}"
                                aria-label="{{ __('admin.copy.search_materials') }}"
                                autocomplete="off"
                                class="form-control form-control-sm ui-filter-bar__search-input"
                            />
                        </x-slot:search>
                        <x-slot:filters>
                            <label for="materialStatusFilter" class="tw:sr-only">{{ __('admin.copy.status') }}</label>
                            <select id="materialStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44">
                                <option value="">{{ __('admin.copy.all_statuses') }}</option>
                                <option value="active">{{ __('admin.copy.active') }}</option>
                                <option value="inactive">{{ __('admin.copy.inactive') }}</option>
                            </select>
                        </x-slot:filters>
                        <x-slot:advanced>
                            <div>
                                <label for="materialCategoryFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.hs_category') }}</label>
                                <select id="materialCategoryFilter" class="form-select form-select-sm tw:w-full">
                                    <option value="">{{ __('admin.copy.all_hs_categories') }}</option>
                                    @foreach($hsCategories as $category)
                                        <option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="materialDensityFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.density_profile') }}</label>
                                <select id="materialDensityFilter" class="form-select form-select-sm tw:w-full">
                                    <option value="">{{ __('admin.copy.all_density_profiles') }}</option>
                                    @foreach($densityProfiles as $density)
                                        <option value="{{ $density }}">{{ \App\Models\MaterialMaster::densityProfileLabel($density) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="materialManufacturerFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.manufacturer_scope') }}</label>
                                <select id="materialManufacturerFilter" class="form-select form-select-sm tw:w-full">
                                    <option value="">{{ __('admin.copy.all_manufacturer_scopes') }}</option>
                                    @foreach($manufacturerScopes as $scope)
                                        <option value="{{ $scope }}">{{ \App\Models\MaterialMaster::manufacturerScopeLabel($scope) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </x-slot:advanced>
                        <x-slot:chips>
                            <x-ui.filter-chips id="materialFilterChips" />
                        </x-slot:chips>
                    </x-ui.filter-bar>
                </x-slot:filters>
                <table id="materialsTable" class="table table-hover align-middle w-100 tw:m-0 tw:text-ui-sm">
                    <thead class="table-light"><tr><th scope="col" class="text-center">{{ __('admin.copy.no') }}</th><th scope="col">{{ __('admin.copy.material_code') }}</th><th scope="col">{{ __('admin.copy.raw_category') }}</th><th scope="col">{{ __('admin.copy.hs_category') }}</th><th scope="col">{{ __('admin.copy.density') }}</th><th scope="col">{{ __('admin.copy.manufacturer') }}</th><th scope="col">{{ __('admin.copy.status') }}</th><th scope="col">{{ __('admin.copy.source') }}</th><th scope="col">{{ __('admin.copy.updated') }}</th><th scope="col" class="tw:text-right text-end">{{ __('common.table.action') }}</th></tr></thead>
                </table>
            </x-ui.data-table>
        </div>

        <div class="tab-pane fade" id="rules" role="tabpanel" aria-labelledby="rules-tab" tabindex="0">
            <x-ui.data-table
                :title="__('admin.copy.hs_code_rule_register')"
                :description="__('admin.copy.prioritized_dimensional_rules_used_by_the_deterministic_hs_code_mapping_workflow')"
            >
                <x-slot:actions>
                    <x-ui.button type="button" size="sm" id="btnAddRule">
                        <x-ui.icon name="plus" size="sm" />
                        <span>{{ __('admin.copy.add_rule') }}</span>
                    </x-ui.button>
                </x-slot:actions>
                <x-slot:filters>
                    <x-ui.filter-bar
                        id="rulesFilterBar"
                        mode="live"
                        variant="embedded"
                        class="tw:w-full"
                        :aria-label="__('admin.copy.hs_code_rule_controls')"
                        advanced-id="ruleMoreFilters"
                        :advanced-label="__('admin.copy.more_filters')"
                        reset-id="resetRuleFilters"
                        :reset-label="__('admin.copy.reset')"
                    >
                        <x-slot:search>
                            <label for="ruleSearch" class="tw:sr-only">{{ __('admin.copy.search_hs_code_rules') }}</label>
                            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                            <input
                                name="rule_search"
                                id="ruleSearch"
                                type="search"
                                placeholder="{{ __('admin.copy.search_hs_code_or_category') }}"
                                aria-label="{{ __('admin.copy.search_hs_code_rules') }}"
                                autocomplete="off"
                                class="form-control form-control-sm ui-filter-bar__search-input"
                            />
                        </x-slot:search>
                        <x-slot:filters>
                            <label for="ruleStatusFilter" class="tw:sr-only">{{ __('admin.copy.status') }}</label>
                            <select id="ruleStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44">
                                <option value="">{{ __('admin.copy.all_statuses') }}</option>
                                <option value="active">{{ __('admin.copy.active') }}</option>
                                <option value="inactive">{{ __('admin.copy.inactive') }}</option>
                                <option value="conflict">{{ __('admin.copy.conflict') }}</option>
                            </select>
                        </x-slot:filters>
                        <x-slot:advanced>
                            <div>
                                <label for="ruleCategoryFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.category') }}</label>
                                <select id="ruleCategoryFilter" class="form-select form-select-sm tw:w-full">
                                    <option value="">{{ __('admin.copy.all_categories') }}</option>
                                    @foreach($hsCategories as $category)
                                        <option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ruleShapeFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.shape') }}</label>
                                <select id="ruleShapeFilter" class="form-select form-select-sm tw:w-full">
                                    <option value="">{{ __('admin.copy.all_shapes') }}</option>
                                    @foreach($shapes as $shape)
                                        <option value="{{ $shape }}">{{ \App\Models\PrItem::shapeLabel($shape) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </x-slot:advanced>
                        <x-slot:chips>
                            <x-ui.filter-chips id="ruleFilterChips" />
                        </x-slot:chips>
                    </x-ui.filter-bar>
                </x-slot:filters>
                <table id="rulesTable" class="table table-hover align-middle w-100 tw:m-0 tw:text-ui-sm">
                    <thead class="table-light"><tr><th scope="col" class="text-center">{{ __('admin.copy.no') }}</th><th scope="col">HS Code</th><th scope="col">{{ __('admin.copy.category') }}</th><th scope="col">{{ __('admin.copy.shape') }}</th><th scope="col">{{ __('admin.copy.dimension_conditions') }}</th><th scope="col" class="text-center">{{ __('admin.copy.priority') }}</th><th scope="col">{{ __('admin.copy.status') }}</th><th scope="col">{{ __('admin.copy.source') }}</th><th scope="col">{{ __('admin.copy.updated') }}</th><th scope="col" class="tw:text-right text-end">{{ __('common.table.action') }}</th></tr></thead>
                </table>
            </x-ui.data-table>
        </div>

        <div class="tab-pane fade" id="data-quality" role="tabpanel" aria-labelledby="data-quality-tab" tabindex="0">
            <div class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-3 tw:shell:p-4">
                <x-ui.inline-loader id="qualityLoading" :label="__('admin.copy.analyzing_master_data')" class="tw:py-12" />
                <div id="qualityContent" class="d-none">
                    <section class="tw:overflow-hidden tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container" aria-labelledby="quality-summary-title">
                        <h3 id="quality-summary-title" class="tw:sr-only">{{ __('admin.copy.data_quality_summary') }}</h3>
                        <dl class="tw:m-0 tw:grid tw:grid-cols-2 tw:lg:grid-cols-5">
                            @foreach([
                                ['id' => 'qualityMaterials', 'label' => __('admin.copy.materials')],
                                ['id' => 'qualityMapped', 'label' => __('admin.copy.with_hs_mapping')],
                                ['id' => 'qualityNeedsMapping', 'label' => __('admin.copy.needs_mapping')],
                                ['id' => 'qualityActiveRules', 'label' => __('admin.copy.active_rules')],
                                ['id' => 'qualityNeedsReview', 'label' => __('admin.copy.needs_review')],
                            ] as $metric)
                                <div class="tw:border-b tw:border-r tw:border-outline-variant tw:p-3 tw:last:border-r-0 tw:lg:border-b-0">
                                    <dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wide tw:text-on-surface-variant">{{ $metric['label'] }}</dt>
                                    <dd id="{{ $metric['id'] }}" class="ui-tabular-nums tw:m-0 tw:mt-1 tw:text-lg tw:font-semibold">-</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <div id="qualityError" class="d-none tw:mt-4 tw:border tw:border-error/40 tw:bg-error-container tw:p-3 tw:text-ui-sm tw:text-on-surface" role="alert">{{ __('admin.copy.data_quality_could_not_be_loaded_try_opening_this_tab_again') }}</div>

                    <div class="tw:mt-5 tw:grid tw:gap-5 tw:lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.8fr)]">
                        <section class="tw:min-w-0 tw:overflow-hidden tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface-container" aria-labelledby="quality-attention-title">
                            <header class="tw:border-b tw:border-outline-variant tw:px-4 tw:py-3">
                                <h3 id="quality-attention-title" class="tw:m-0 tw:text-ui-sm tw:font-semibold">{{ __('admin.copy.needs_attention') }}</h3>
                                <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('admin.copy.records_that_can_affect_automatic_hs_code_results') }}</p>
                            </header>
                            <div class="tw:divide-y tw:divide-outline-variant tw:text-ui-sm">
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.materials_without_hs_mapping') }}</div><div id="unmappedMaterials" class="tw:mt-2"></div></div>
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.categories_without_active_hs_rules') }}</div><div id="categoriesWithoutRules" class="tw:mt-2"></div></div>
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.rules_needing_review') }}</div><div id="rulesNeedingReview" class="tw:mt-2"></div></div>
                            </div>
                        </section>
                        <section class="tw:min-w-0 tw:overflow-hidden tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface-container" aria-labelledby="quality-reference-title">
                            <header class="tw:border-b tw:border-outline-variant tw:px-4 tw:py-3">
                                <h3 id="quality-reference-title" class="tw:m-0 tw:text-ui-sm tw:font-semibold">{{ __('admin.copy.reference_context') }}</h3>
                                <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('admin.copy.useful_context_that_does_not_require_immediate_action') }}</p>
                            </header>
                            <div class="tw:divide-y tw:divide-outline-variant tw:text-ui-sm">
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.duplicate_rule_coverage') }}</div><div id="duplicateRuleCoverage" class="tw:mt-2 tw:text-on-surface-variant"></div></div>
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.inactive_rules_retained_for_reference') }}</div><div id="inactiveRulesForReference" class="tw:mt-2"></div></div>
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.rule_categories_not_used_by_materials') }}</div><div id="unusedRuleCategories" class="tw:mt-2"></div></div>
                                <div class="tw:p-4"><div class="tw:font-semibold">{{ __('admin.copy.reference_only_materials') }}</div><div id="referenceOnlyMaterials" class="tw:mt-2"></div></div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@include('admin.material-hs-code._material_form')
@include('admin.material-hs-code._rule_form')
@endsection

@push('scripts')
@include('admin.material-hs-code._script')
@endpush
