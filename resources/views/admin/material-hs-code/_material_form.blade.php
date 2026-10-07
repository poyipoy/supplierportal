<div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="materialForm" method="POST" action="{{ route('admin.material-masters.store') }}" class="modal-content">
            @csrf
            <input type="hidden" id="materialFormMethod">
            <input type="hidden" name="form_context" value="material">
            <input type="hidden" name="record_id" id="materialRecordId" value="{{ old('form_context') === 'material' ? old('record_id') : '' }}">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-6 fw-bold tw-text-on-surface" id="materialModalTitle">{{ __('admin.copy.add_material_master') }}</h2>
                    <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.configure_material_identity_hs_classification_category_density_profile_and_manufacturer_scope') }}</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin.copy.close') }}"></button>
            </div>

            <div class="modal-body tw-grid tw-gap-4 tw-p-5">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialCode">
                            {{ __('admin.copy.material_code') }} <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="material_code"
                            id="materialCode"
                            class="form-control"
                            maxlength="100"
                            placeholder="{{ __('admin.copy.e_g_dc11_skd11_ss400') }}"
                            required
                            value="{{ old('form_context') === 'material' ? old('material_code') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialRawCategory">
                            {{ __('admin.copy.raw_material_category') }} <span class="tw-text-on-surface-variant tw-font-normal">{{ __('admin.copy.optional') }}</span>
                        </label>
                        <input
                            type="text"
                            name="raw_category"
                            id="materialRawCategory"
                            class="form-control"
                            maxlength="100"
                            placeholder="{{ __('admin.copy.e_g_cold_work_die_steel') }}"
                            value="{{ old('form_context') === 'material' ? old('raw_category') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialHsCategory">
                            {{ __('admin.copy.hs_code_category') }} <span class="tw-text-on-surface-variant tw-font-normal">{{ __('admin.copy.optional') }}</span>
                        </label>
                        <select name="hs_category" id="materialHsCategory" class="form-select">
                            <option value="">{{ __('admin.copy.unmapped_generic') }}</option>
                            @foreach($hsCategories as $category)
                                <option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialDensity">
                            {{ __('admin.copy.density_profile') }} <span class="text-danger">*</span>
                        </label>
                        <select name="density_profile" id="materialDensity" class="form-select" required>
                            @foreach($densityProfiles as $density)
                                <option value="{{ $density }}">{{ \App\Models\MaterialMaster::densityProfileLabel($density) }} ({{ $density === 'steel' ? '7.85 g/cm³' : __('admin.copy.standard') }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:tw-col-span-2">
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialManufacturer">
                            {{ __('admin.copy.manufacturer_scope') }} <span class="text-danger">*</span>
                        </label>
                        <select name="manufacturer_scope" id="materialManufacturer" class="form-select" required>
                            @foreach($manufacturerScopes as $scope)
                                <option value="{{ $scope }}">{{ \App\Models\MaterialMaster::manufacturerScopeLabel($scope) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:tw-col-span-2 tw-pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="materialActive" checked>
                            <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="materialActive">
                                {{ __('admin.copy.active_selectable_in_requisition_items') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('admin.copy.cancel') }}</x-ui.button>
                <x-ui.button type="submit" id="btnSaveMaterial">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="spinnerSaveMaterial"></span>
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.save_material') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
