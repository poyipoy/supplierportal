@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Master Material & HS Code - ADASI Portal')
@section('page-title', 'Master Material & HS Code')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Master Material and HS Code" description="Maintain material mappings, deterministic HS Code rules, and master-data quality signals." eyebrow="Admin Master Data" />

    <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="master-data-workspace-title">
        <h2 id="master-data-workspace-title" class="tw-sr-only">Master data workspace</h2>
        <div class="tw-border-b tw-border-outline-variant tw-px-3 tw-pt-3 shell:tw-px-4">
            <ul class="nav nav-tabs border-0 gap-1" id="masterTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active rounded-1" id="materials-tab" data-bs-toggle="tab" data-bs-target="#materials" type="button" role="tab" aria-controls="materials" aria-selected="true">Materials</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link rounded-1" id="rules-tab" data-bs-toggle="tab" data-bs-target="#rules" type="button" role="tab" aria-controls="rules" aria-selected="false">HS Code Rules</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link rounded-1" id="data-quality-tab" data-bs-toggle="tab" data-bs-target="#data-quality" type="button" role="tab" aria-controls="data-quality" aria-selected="false">Data Quality</button></li>
            </ul>
        </div>

        <div class="tab-content tw-p-3 shell:tw-p-4">
            <div class="tab-pane fade show active" id="materials" role="tabpanel" aria-labelledby="materials-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-1">
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">Material Register</h3>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Searchable operational codes and their calculation and HS mapping attributes.</p>
                </div>
                <x-ui.toolbar aria-label="Material master controls">
                    <x-slot:search>
                        <x-ui.input name="material_search" id="materialSearch" type="search" placeholder="Search material code or category" aria-label="Search materials" autocomplete="off" />
                    </x-slot:search>
                    <x-slot:filters>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialStatusFilter">Status
                            <select id="materialStatusFilter" class="form-select form-select-sm tw-min-w-36"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        </label>
                        <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#materialMoreFilters" aria-expanded="false" aria-controls="materialMoreFilters"><x-ui.icon name="sliders-horizontal" /> More Filters</x-ui.button>
                    </x-slot:filters>
                    <x-slot:actions>
                        <x-ui.button type="button" variant="ghost" size="sm" id="resetMaterialFilters"><x-ui.icon name="rotate-ccw" /> Reset</x-ui.button>
                        <x-ui.button type="button" size="sm" id="btnAddMaterial"><x-ui.icon name="plus" /> Add Material</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
                <div class="collapse" id="materialMoreFilters">
                    <div class="tw-mb-4 tw-grid tw-gap-3 tw-border tw-border-outline-variant tw-bg-surface-low tw-p-4 md:tw-grid-cols-3">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialCategoryFilter">HS Category
                            <select id="materialCategoryFilter" class="form-select form-select-sm"><option value="">All HS categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str($category)->replace('_', ' ')->title() }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialDensityFilter">Density Profile
                            <select id="materialDensityFilter" class="form-select form-select-sm"><option value="">All density profiles</option>@foreach($densityProfiles as $density)<option value="{{ $density }}">{{ ucfirst($density) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="materialManufacturerFilter">Manufacturer Scope
                            <select id="materialManufacturerFilter" class="form-select form-select-sm"><option value="">All manufacturer scopes</option>@foreach($manufacturerScopes as $scope)<option value="{{ $scope }}">{{ str($scope)->replace('_', ' ')->title() }}</option>@endforeach</select>
                        </label>
                    </div>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="materialsTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th scope="col" class="text-center">No</th><th scope="col">Material Code</th><th scope="col">Raw Category</th><th scope="col">HS Category</th><th scope="col">Density</th><th scope="col">Manufacturer</th><th scope="col">Status</th><th scope="col">Source</th><th scope="col">Updated</th><th scope="col" class="text-end">Actions</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="rules" role="tabpanel" aria-labelledby="rules-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-1">
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">HS Code Rule Register</h3>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Prioritized dimensional rules used by the deterministic HS Code mapping workflow.</p>
                </div>
                <x-ui.toolbar aria-label="HS Code rule controls">
                    <x-slot:search>
                        <x-ui.input name="rule_search" id="ruleSearch" type="search" placeholder="Search HS Code or category" aria-label="Search HS Code rules" autocomplete="off" />
                    </x-slot:search>
                    <x-slot:filters>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleStatusFilter">Status
                            <select id="ruleStatusFilter" class="form-select form-select-sm tw-min-w-36"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="conflict">Conflict</option></select>
                        </label>
                        <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#ruleMoreFilters" aria-expanded="false" aria-controls="ruleMoreFilters"><x-ui.icon name="sliders-horizontal" /> More Filters</x-ui.button>
                    </x-slot:filters>
                    <x-slot:actions>
                        <x-ui.button type="button" variant="ghost" size="sm" id="resetRuleFilters"><x-ui.icon name="rotate-ccw" /> Reset</x-ui.button>
                        <x-ui.button type="button" size="sm" id="btnAddRule"><x-ui.icon name="plus" /> Add Rule</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
                <div class="collapse" id="ruleMoreFilters">
                    <div class="tw-mb-4 tw-grid tw-gap-3 tw-border tw-border-outline-variant tw-bg-surface-low tw-p-4 md:tw-grid-cols-2">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleCategoryFilter">Category
                            <select id="ruleCategoryFilter" class="form-select form-select-sm"><option value="">All categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str($category)->replace('_', ' ')->title() }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="ruleShapeFilter">Shape
                            <select id="ruleShapeFilter" class="form-select form-select-sm"><option value="">All shapes</option>@foreach($shapes as $shape)<option value="{{ $shape }}">{{ $shape }}</option>@endforeach</select>
                        </label>
                    </div>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="rulesTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th scope="col" class="text-center">No</th><th scope="col">HS Code</th><th scope="col">Category</th><th scope="col">Shape</th><th scope="col">Dimension Conditions</th><th scope="col" class="text-center">Priority</th><th scope="col">Status</th><th scope="col">Source</th><th scope="col">Updated</th><th scope="col" class="text-end">Actions</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="data-quality" role="tabpanel" aria-labelledby="data-quality-tab" tabindex="0">
                <div id="qualityLoading" class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-12" role="status">
                    <span class="ui-spinner" aria-hidden="true"></span><span class="tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Analyzing master data...</span>
                </div>
                <div id="qualityContent" class="d-none">
                    <section class="tw-border-y tw-border-outline-variant tw-bg-surface" aria-labelledby="quality-summary-title">
                        <h3 id="quality-summary-title" class="tw-sr-only">Data quality summary</h3>
                        <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-5">
                            @foreach([
                                ['id' => 'qualityMaterials', 'label' => 'Materials'],
                                ['id' => 'qualityMapped', 'label' => 'With HS Mapping'],
                                ['id' => 'qualityNeedsMapping', 'label' => 'Needs Mapping'],
                                ['id' => 'qualityActiveRules', 'label' => 'Active Rules'],
                                ['id' => 'qualityNeedsReview', 'label' => 'Needs Review'],
                            ] as $metric)
                                <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-3 last:tw-border-r-0 lg:tw-border-b-0">
                                    <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ $metric['label'] }}</dt>
                                    <dd id="{{ $metric['id'] }}" class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-lg tw-font-semibold">-</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <div id="qualityError" class="d-none tw-mt-4 tw-border tw-border-error/40 tw-bg-error-container tw-p-3 tw-text-ui-sm tw-text-on-surface" role="alert">Data quality could not be loaded. Try opening this tab again.</div>

                    <div class="tw-mt-5 tw-grid tw-gap-5 lg:tw-grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.8fr)]">
                        <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="quality-attention-title">
                            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                                <h3 id="quality-attention-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Needs Attention</h3>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Records that can affect automatic HS Code results.</p>
                            </header>
                            <div class="tw-divide-y tw-divide-outline-variant tw-text-ui-sm">
                                <div class="tw-p-4"><div class="tw-font-semibold">Materials without HS mapping</div><div id="unmappedMaterials" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">Categories without active HS rules</div><div id="categoriesWithoutRules" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">Rules needing review</div><div id="rulesNeedingReview" class="tw-mt-2"></div></div>
                            </div>
                        </section>
                        <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="quality-reference-title">
                            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                                <h3 id="quality-reference-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Reference Context</h3>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Useful context that does not require immediate action.</p>
                            </header>
                            <div class="tw-divide-y tw-divide-outline-variant tw-text-ui-sm">
                                <div class="tw-p-4"><div class="tw-font-semibold">Duplicate rule coverage</div><div id="duplicateRuleCoverage" class="tw-mt-2 tw-text-on-surface-variant"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">Inactive rules retained for reference</div><div id="inactiveRulesForReference" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">Rule categories not used by materials</div><div id="unusedRuleCategories" class="tw-mt-2"></div></div>
                                <div class="tw-p-4"><div class="tw-font-semibold">Reference-only materials</div><div id="referenceOnlyMaterials" class="tw-mt-2"></div></div>
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
