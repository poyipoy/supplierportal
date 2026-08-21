@extends('layouts.app')

@section('title', 'Master Material & HS Code - ADASI Portal')
@section('page-title', 'Master Material & HS Code')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Master Material & HS Code"
        description="Maintain material mappings, deterministic HS Code rules, and data-quality signals."
        eyebrow="Admin Master Data"
    />

    <x-ui.card padding="none">
        <div class="tw-border-b tw-border-outline-variant tw-px-4 tw-pt-3 shell:tw-px-5">
            <ul class="nav nav-tabs border-0" id="masterTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="materials-tab" data-bs-toggle="tab" data-bs-target="#materials" type="button" role="tab" aria-controls="materials" aria-selected="true">Materials</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="rules-tab" data-bs-toggle="tab" data-bs-target="#rules" type="button" role="tab" aria-controls="rules" aria-selected="false">HS Code Rules</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="data-quality-tab" data-bs-toggle="tab" data-bs-target="#data-quality" type="button" role="tab" aria-controls="data-quality" aria-selected="false">Data Quality</button></li>
            </ul>
        </div>

        <div class="tab-content tw-p-4 shell:tw-p-5">
            <div class="tab-pane fade show active" id="materials" role="tabpanel" aria-labelledby="materials-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-3 xl:tw-flex-row xl:tw-items-end xl:tw-justify-between">
                    <div class="tw-grid tw-flex-1 tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-4">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Status
                            <select id="materialStatusFilter" class="form-select form-select-sm"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">HS category
                            <select id="materialCategoryFilter" class="form-select form-select-sm"><option value="">All HS categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Density
                            <select id="materialDensityFilter" class="form-select form-select-sm"><option value="">All density</option>@foreach($densityProfiles as $density)<option value="{{ $density }}">{{ ucfirst($density) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Manufacturer
                            <select id="materialManufacturerFilter" class="form-select form-select-sm"><option value="">All manufacturers</option>@foreach($manufacturerScopes as $scope)<option value="{{ $scope }}">{{ str_replace('_', ' ', $scope) }}</option>@endforeach</select>
                        </label>
                    </div>
                    <x-ui.button type="button" size="sm" id="btnAddMaterial"><x-ui.icon name="plus" /> Add Material</x-ui.button>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="materialsTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th>No</th><th>Material</th><th>Raw Category</th><th>HS Category</th><th>Density</th><th>Manufacturer</th><th>Status</th><th>Source</th><th>Action</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="rules" role="tabpanel" aria-labelledby="rules-tab" tabindex="0">
                <div class="tw-mb-4 tw-flex tw-flex-col tw-gap-3 xl:tw-flex-row xl:tw-items-end xl:tw-justify-between">
                    <div class="tw-grid tw-flex-1 tw-gap-3 md:tw-grid-cols-3">
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Status
                            <select id="ruleStatusFilter" class="form-select form-select-sm"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="conflict">Conflict</option></select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Category
                            <select id="ruleCategoryFilter" class="form-select form-select-sm"><option value="">All categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select>
                        </label>
                        <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium">Shape
                            <select id="ruleShapeFilter" class="form-select form-select-sm"><option value="">All shapes</option>@foreach($shapes as $shape)<option value="{{ $shape }}">{{ $shape }}</option>@endforeach</select>
                        </label>
                    </div>
                    <x-ui.button type="button" size="sm" id="btnAddRule"><x-ui.icon name="plus" /> Add Rule</x-ui.button>
                </div>
                <div class="ui-data-table__scroll tw-overflow-x-auto">
                    <table id="rulesTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th>No</th><th>HS Code</th><th>Category</th><th>Shape</th><th>Conditions</th><th>Priority</th><th>Status</th><th>Source</th><th>Action</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="data-quality" role="tabpanel" aria-labelledby="data-quality-tab" tabindex="0">
                <div id="qualityLoading" class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-12" role="status">
                    <span class="ui-spinner" aria-hidden="true"></span><span class="tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Analyzing master data...</span>
                </div>
                <div id="qualityContent" class="d-none">
                    <div class="row g-3 mb-4" id="qualityCards"></div>
                    <div class="tw-grid tw-gap-4 lg:tw-grid-cols-[minmax(0,1.4fr)_minmax(18rem,1fr)]">
                        <x-ui.card title="Needs Attention" description="Items that can affect an automatic HS Code result." variant="tonal">
                            <div class="tw-grid tw-gap-4 tw-text-ui-sm">
                                <div><div class="tw-font-semibold">Materials without HS mapping</div><div id="unmappedMaterials" class="tw-mt-1"></div></div>
                                <div><div class="tw-font-semibold">Categories without active HS rules</div><div id="categoriesWithoutRules" class="tw-mt-1"></div></div>
                                <div><div class="tw-font-semibold">Rules needing review</div><div id="rulesNeedingReview" class="tw-mt-1"></div></div>
                            </div>
                        </x-ui.card>
                        <x-ui.card title="Reference Notes" description="Useful context that does not require an immediate change." variant="flat">
                            <div class="tw-grid tw-gap-4 tw-text-ui-sm">
                                <div><div class="tw-font-semibold">Duplicate rule coverage</div><div id="duplicateRuleCoverage" class="tw-mt-1 tw-text-on-surface-variant"></div></div>
                                <div><div class="tw-font-semibold">Inactive rules kept for reference</div><div id="inactiveRulesForReference" class="tw-mt-1"></div></div>
                                <div><div class="tw-font-semibold">Rule categories not used by current materials</div><div id="unusedRuleCategories" class="tw-mt-1"></div></div>
                                <div><div class="tw-font-semibold">Reference-only materials</div><div id="referenceOnlyMaterials" class="tw-mt-1"></div></div>
                            </div>
                        </x-ui.card>
                    </div>
                </div>
            </div>
        </div>
    </x-ui.card>
</div>

@include('admin.material-hs-code._material_form')
@include('admin.material-hs-code._rule_form')
@endsection

@push('scripts')
@include('admin.material-hs-code._script')
@endpush
