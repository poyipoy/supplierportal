@extends('layouts.app')

@section('title', 'Master Material & HS Code - ADASI Portal')
@section('page-title', 'Master Material & HS Code')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white pt-3 pb-0">
        <ul class="nav nav-tabs border-0" id="masterTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#materials" type="button">Materials</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rules" type="button">HS Code Rules</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#data-quality" type="button">Data Quality</button></li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="materials">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <div class="row g-2 flex-grow-1">
                        <div class="col-md-3"><select id="materialStatusFilter" class="form-select form-select-sm"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        <div class="col-md-3"><select id="materialCategoryFilter" class="form-select form-select-sm"><option value="">All HS categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select></div>
                        <div class="col-md-3"><select id="materialDensityFilter" class="form-select form-select-sm"><option value="">All density</option>@foreach($densityProfiles as $density)<option value="{{ $density }}">{{ ucfirst($density) }}</option>@endforeach</select></div>
                        <div class="col-md-3"><select id="materialManufacturerFilter" class="form-select form-select-sm"><option value="">All manufacturers</option>@foreach($manufacturerScopes as $scope)<option value="{{ $scope }}">{{ str_replace('_', ' ', $scope) }}</option>@endforeach</select></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAddMaterial"><i class="bi bi-plus-lg me-1"></i>Add Material</button>
                </div>
                <div class="table-responsive">
                    <table id="materialsTable" class="table table-hover align-middle w-100" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>No</th><th>Material</th><th>Raw Category</th><th>HS Category</th><th>Density</th><th>Manufacturer</th><th>Status</th><th>Source</th><th>Action</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="rules">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <div class="row g-2 flex-grow-1">
                        <div class="col-md-4"><select id="ruleStatusFilter" class="form-select form-select-sm"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="conflict">Conflict</option></select></div>
                        <div class="col-md-4"><select id="ruleCategoryFilter" class="form-select form-select-sm"><option value="">All categories</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select></div>
                        <div class="col-md-4"><select id="ruleShapeFilter" class="form-select form-select-sm"><option value="">All shapes</option>@foreach($shapes as $shape)<option value="{{ $shape }}">{{ $shape }}</option>@endforeach</select></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAddRule"><i class="bi bi-plus-lg me-1"></i>Add Rule</button>
                </div>
                <div class="table-responsive">
                    <table id="rulesTable" class="table table-hover align-middle w-100" style="font-size:.82rem">
                        <thead class="table-light"><tr><th>No</th><th>HS Code</th><th>Category</th><th>Shape</th><th>Conditions</th><th>Priority</th><th>Status</th><th>Source</th><th>Action</th></tr></thead>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="data-quality">
                <div id="qualityLoading" class="text-center py-5"><div class="spinner-border text-primary"></div><div class="small text-muted mt-2">Analyzing master data...</div></div>
                <div id="qualityContent" class="d-none">
                    <div class="row g-3 mb-4" id="qualityCards"></div>
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <section class="border rounded-3 p-3 h-100" aria-labelledby="qualityAttentionTitle">
                                <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-exclamation-circle text-warning fs-5" aria-hidden="true"></i><div><h6 class="mb-0" id="qualityAttentionTitle">Needs attention</h6><p class="small text-muted mb-0">Review only the items that can affect an automatic HS Code result.</p></div></div>
                                <div class="mb-3"><div class="fw-semibold small mb-2">Materials without HS mapping</div><div id="unmappedMaterials" class="small"></div></div>
                                <div class="mb-3"><div class="fw-semibold small mb-2">Categories without active HS rules</div><div id="categoriesWithoutRules" class="small"></div></div>
                                <div><div class="fw-semibold small mb-2">Rules needing review</div><div id="rulesNeedingReview" class="small"></div></div>
                            </section>
                        </div>
                        <div class="col-lg-5">
                            <section class="border rounded-3 p-3 h-100" aria-labelledby="qualityReferenceTitle">
                                <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-info-circle text-primary fs-5" aria-hidden="true"></i><div><h6 class="mb-0" id="qualityReferenceTitle">Reference notes</h6><p class="small text-muted mb-0">Useful context that does not require an immediate change.</p></div></div>
                                <div class="mb-3"><div class="fw-semibold small mb-1">Duplicate rule coverage</div><div id="duplicateRuleCoverage" class="small text-muted"></div></div>
                                <div class="mb-3"><div class="fw-semibold small mb-1">Inactive rules kept for reference</div><div id="inactiveRulesForReference" class="small"></div></div>
                                <div class="mb-3"><div class="fw-semibold small mb-1">Rule categories not used by current materials</div><div id="unusedRuleCategories" class="small"></div></div>
                                <div><div class="fw-semibold small mb-1">Reference-only materials</div><div id="referenceOnlyMaterials" class="small"></div></div>
                            </section>
                        </div>
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
