<div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="materialForm" method="POST" action="{{ route('admin.material-masters.store') }}" class="modal-content">
            @csrf
            <input type="hidden" id="materialFormMethod">
            <input type="hidden" name="form_context" value="material">
            <input type="hidden" name="record_id" id="materialRecordId" value="{{ old('form_context') === 'material' ? old('record_id') : '' }}">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-6 fw-bold tw-text-on-surface" id="materialModalTitle">Add Material Master</h2>
                    <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Configure material identity, HS classification category, density profile, and manufacturer scope.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body tw-grid tw-gap-4 tw-p-5">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialCode">
                            Material Code <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="material_code"
                            id="materialCode"
                            class="form-control"
                            maxlength="100"
                            placeholder="e.g. DC11, SKD11, SS400"
                            required
                            value="{{ old('form_context') === 'material' ? old('material_code') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialRawCategory">
                            Raw Material Category <span class="tw-text-on-surface-variant tw-font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            name="raw_category"
                            id="materialRawCategory"
                            class="form-control"
                            maxlength="100"
                            placeholder="e.g. Cold Work Die Steel"
                            value="{{ old('form_context') === 'material' ? old('raw_category') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialHsCategory">
                            HS Code Category <span class="tw-text-on-surface-variant tw-font-normal">(Optional)</span>
                        </label>
                        <select name="hs_category" id="materialHsCategory" class="form-select">
                            <option value="">-- Unmapped / Generic --</option>
                            @foreach($hsCategories as $category)
                                <option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialDensity">
                            Density Profile <span class="text-danger">*</span>
                        </label>
                        <select name="density_profile" id="materialDensity" class="form-select" required>
                            @foreach($densityProfiles as $density)
                                <option value="{{ $density }}">{{ ucfirst($density) }} ({{ $density === 'steel' ? '7.85 g/cm³' : 'Standard' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:tw-col-span-2">
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="materialManufacturer">
                            Manufacturer Scope <span class="text-danger">*</span>
                        </label>
                        <select name="manufacturer_scope" id="materialManufacturer" class="form-select" required>
                            @foreach($manufacturerScopes as $scope)
                                <option value="{{ $scope }}">{{ str_replace('_', ' ', $scope) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:tw-col-span-2 tw-pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="materialActive" checked>
                            <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="materialActive">
                                Active &amp; Selectable in Requisition Items
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="submit" id="btnSaveMaterial">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="spinnerSaveMaterial"></span>
                    <x-ui.icon name="check" size="sm" />
                    Save Material
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
