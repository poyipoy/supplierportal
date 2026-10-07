<div class="modal fade" id="ruleModal" tabindex="-1" aria-labelledby="ruleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <form id="ruleForm" method="POST" action="{{ route('admin.hs-code-rules.store') }}" class="modal-content">
            @csrf
            <input type="hidden" id="ruleFormMethod">
            <input type="hidden" name="form_context" value="rule">
            <input type="hidden" name="record_id" id="ruleRecordId" value="{{ old('form_context') === 'rule' ? old('record_id') : '' }}">
            <input type="hidden" name="conditions_json" id="ruleConditionsJson" value="{{ old('form_context') === 'rule' ? old('conditions_json') : '' }}">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-6 fw-bold tw-text-on-surface" id="ruleModalTitle">{{ __('admin.copy.add_hs_code_classification_rule') }}</h2>
                    <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.deterministic_rule_configuration_matching_material_category_cross_section_shape_and_dimension_parame') }}</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin.copy.close') }}"></button>
            </div>

            <div class="modal-body tw-grid tw-gap-5 tw-p-5">
                {{-- Rule Primary Parameters --}}
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleHsCode">
                            {{ __('common.fields.hs_code') }} <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="hs_code"
                            id="ruleHsCode"
                            class="form-control"
                            placeholder="{{ __('admin.copy.e_g_7228_30_10') }}"
                            required
                            value="{{ old('form_context') === 'rule' ? old('hs_code') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleCategory">
                            {{ __('admin.copy.material_category') }} <span class="text-danger">*</span>
                        </label>
                        <select name="material_category" id="ruleCategory" class="form-select" required>
                            @foreach($hsCategories as $category)
                                <option value="{{ $category }}">{{ \App\Models\MaterialMaster::hsCategoryLabel($category) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleShape">
                            {{ __('admin.copy.cross_section_shape') }} <span class="text-danger">*</span>
                        </label>
                        <select name="shape" id="ruleShape" class="form-select" required>
                            @foreach($shapes as $shape)
                                <option value="{{ $shape }}">{{ \App\Models\PrItem::shapeLabel($shape) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="rulePriority">
                            {{ __('admin.copy.evaluation_priority') }} <span class="text-danger">*</span>
                        </label>
                        <input
                            type="number"
                            name="priority"
                            id="rulePriority"
                            class="form-control"
                            min="1"
                            max="65535"
                            value="100"
                            required
                        >
                        <small class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.lower_numbers_evaluate_first') }}</small>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleStatus">
                            {{ __('admin.copy.rule_status') }} <span class="text-danger">*</span>
                        </label>
                        <select name="status" id="ruleStatus" class="form-select">
                            <option value="active">{{ __('admin.copy.active') }}</option>
                            <option value="inactive">{{ __('admin.copy.inactive') }}</option>
                            <option value="conflict">{{ __('admin.copy.conflict_review') }}</option>
                        </select>
                    </div>
                </div>

                {{-- Dimension Threshold Matrix --}}
                <div class="tw-border tw-border-outline tw-rounded-ui-sm tw-p-4 tw-bg-surface-container">
                    <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                        <span class="tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('admin.copy.dimensional_boundary_conditions_mm') }}</span>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.leave_bounds_empty_if_dimension_is_unconstrained') }}</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 tw-text-ui-xs">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width: 180px;">{{ __('admin.copy.dimension') }}</th>
                                    <th scope="col">{{ __('admin.copy.min_bound_mm') }}</th>
                                    <th scope="col" class="text-center" style="width: 110px;">{{ __('admin.copy.min_inclusive_ge') }}</th>
                                    <th scope="col">{{ __('admin.copy.max_bound_mm') }}</th>
                                    <th scope="col" class="text-center" style="width: 110px;">{{ __('admin.copy.max_inclusive_le') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(\App\Models\PrItem::DIMENSION_FIELDS as $dimension)
                                    <tr class="rule-condition-row" data-dimension="{{ $dimension }}">
                                        <td>
                                            <span class="fw-semibold tw-text-on-surface">{{ \App\Models\PrItem::dimensionLabel($dimension) }}</span>
                                            <span class="text-muted small">({{ $dimension }})</span>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                id="condition-{{ $dimension }}-min"
                                                step="0.0001"
                                                class="form-control form-control-sm condition-min"
                                                placeholder="{{ __('admin.copy.none') }}"
                                                aria-label="{{ __('materials.a11y.minimum', ['dimension' => \App\Models\PrItem::dimensionLabel($dimension)]) }}"
                                            >
                                        </td>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                id="condition-{{ $dimension }}-min-inclusive"
                                                class="form-check-input condition-min-inclusive"
                                                checked
                                                aria-label="{{ __('materials.a11y.include_minimum', ['dimension' => \App\Models\PrItem::dimensionLabel($dimension)]) }}"
                                            >
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                id="condition-{{ $dimension }}-max"
                                                step="0.0001"
                                                class="form-control form-control-sm condition-max"
                                                placeholder="{{ __('admin.copy.none') }}"
                                                aria-label="{{ __('materials.a11y.maximum', ['dimension' => \App\Models\PrItem::dimensionLabel($dimension)]) }}"
                                            >
                                        </td>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                id="condition-{{ $dimension }}-max-inclusive"
                                                class="form-check-input condition-max-inclusive"
                                                checked
                                                aria-label="{{ __('materials.a11y.include_maximum', ['dimension' => \App\Models\PrItem::dimensionLabel($dimension)]) }}"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <p id="ruleConditionError" class="d-none tw-m-0 tw-text-ui-xs tw-font-semibold tw-text-error" role="alert" tabindex="-1">
                    {{ __('admin.copy.define_at_least_one_minimum_or_maximum_dimensional_boundary_before_saving_this_rule') }}
                </p>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleNotes">
                        {{ __('admin.copy.tariff_notes_legal_reference') }} <span class="tw-text-on-surface-variant tw-font-normal">{{ __('admin.copy.optional') }}</span>
                    </label>
                    <textarea
                        name="notes"
                        id="ruleNotes"
                        class="form-control"
                        rows="2"
                        maxlength="5000"
                        placeholder="{{ __('admin.copy.e_g_btki_2022_chapter_72_other_alloy_steel_hot_rolled_bars') }}"
                    >{{ old('form_context') === 'rule' ? old('notes') : '' }}</textarea>
                </div>
            </div>

            <div class="modal-footer tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('admin.copy.cancel') }}</x-ui.button>
                <x-ui.button type="submit" id="btnSaveRule">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="spinnerSaveRule"></span>
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.save_rule') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
