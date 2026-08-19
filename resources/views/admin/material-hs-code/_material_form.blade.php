<div class="modal fade" id="materialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form id="materialForm" method="POST" action="{{ route('admin.material-masters.store') }}" class="modal-content">
            @csrf
            <input type="hidden" id="materialFormMethod">
            <input type="hidden" name="form_context" value="material">
            <input type="hidden" name="record_id" id="materialRecordId" value="{{ old('form_context') === 'material' ? old('record_id') : '' }}">
            <div class="modal-header"><h5 class="modal-title" id="materialModalTitle">Add Material</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="materialCode">Material Code <span class="text-danger">*</span></label><input type="text" name="material_code" id="materialCode" class="form-control" maxlength="100" required value="{{ old('form_context') === 'material' ? old('material_code') : '' }}"></div>
                    <div class="col-md-6"><label class="form-label" for="materialRawCategory">Raw Category <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="raw_category" id="materialRawCategory" class="form-control" maxlength="100" value="{{ old('form_context') === 'material' ? old('raw_category') : '' }}"></div>
                    <div class="col-md-4"><label class="form-label" for="materialHsCategory">HS Category <span class="text-muted fw-normal">(optional)</span></label><select name="hs_category" id="materialHsCategory" class="form-select"><option value="">Unmapped</option>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label" for="materialDensity">Density <span class="text-danger">*</span></label><select name="density_profile" id="materialDensity" class="form-select" required>@foreach($densityProfiles as $density)<option value="{{ $density }}">{{ ucfirst($density) }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label" for="materialManufacturer">Manufacturer Scope <span class="text-danger">*</span></label><select name="manufacturer_scope" id="materialManufacturer" class="form-select" required>@foreach($manufacturerScopes as $scope)<option value="{{ $scope }}">{{ str_replace('_', ' ', $scope) }}</option>@endforeach</select></div>
                    <div class="col-12"><input type="hidden" name="is_active" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="materialActive" checked><label class="form-check-label" for="materialActive">Active and selectable for new PR items</label></div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span>Save Material</button></div>
        </form>
    </div>
</div>
