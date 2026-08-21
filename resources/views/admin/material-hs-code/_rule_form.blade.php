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
                    <h2 class="modal-title fs-6 fw-bold tw-text-on-surface" id="ruleModalTitle">Add HS Code Classification Rule</h2>
                    <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Deterministic rule configuration matching material category, cross-section shape, and dimension parameters.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body tw-grid tw-gap-5 tw-p-5">
                {{-- Rule Primary Parameters --}}
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleHsCode">
                            HS Code <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="hs_code"
                            id="ruleHsCode"
                            class="form-control"
                            placeholder="e.g. 7228.30.10"
                            required
                            value="{{ old('form_context') === 'rule' ? old('hs_code') : '' }}"
                        >
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleCategory">
                            Material Category <span class="text-danger">*</span>
                        </label>
                        <select name="material_category" id="ruleCategory" class="form-select" required>
                            @foreach($hsCategories as $category)
                                <option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleShape">
                            Cross-Section Shape <span class="text-danger">*</span>
                        </label>
                        <select name="shape" id="ruleShape" class="form-select" required>
                            @foreach($shapes as $shape)
                                <option value="{{ $shape }}">{{ $shape }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="rulePriority">
                            Evaluation Priority <span class="text-danger">*</span>
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
                        <small class="tw-text-ui-xs tw-text-on-surface-variant">Lower numbers evaluate first.</small>
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleStatus">
                            Rule Status <span class="text-danger">*</span>
                        </label>
                        <select name="status" id="ruleStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="conflict">Conflict Review</option>
                        </select>
                    </div>
                </div>

                {{-- Dimension Threshold Matrix --}}
                <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-4 tw-bg-surface-low">
                    <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                        <span class="tw-text-ui-sm tw-font-bold tw-text-on-surface">Dimensional Boundary Conditions (mm)</span>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">Leave bounds empty if dimension is unconstrained.</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 tw-text-ui-xs">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width: 180px;">Dimension</th>
                                    <th scope="col">Min Bound (mm)</th>
                                    <th scope="col" class="text-center" style="width: 110px;">Min Inclusive (&ge;)</th>
                                    <th scope="col">Max Bound (mm)</th>
                                    <th scope="col" class="text-center" style="width: 110px;">Max Inclusive (&le;)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(\App\Models\PrItem::DIMENSION_FIELDS as $dimension)
                                    <tr class="rule-condition-row" data-dimension="{{ $dimension }}">
                                        <td>
                                            <span class="fw-semibold tw-text-on-surface">{{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }}</span>
                                            <span class="text-muted small">({{ $dimension }})</span>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.0001"
                                                class="form-control form-control-sm condition-min"
                                                placeholder="None"
                                                aria-label="{{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }} minimum"
                                            >
                                        </td>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                class="form-check-input condition-min-inclusive"
                                                checked
                                                aria-label="Include {{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }} minimum"
                                            >
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.0001"
                                                class="form-control form-control-sm condition-max"
                                                placeholder="None"
                                                aria-label="{{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }} maximum"
                                            >
                                        </td>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                class="form-check-input condition-max-inclusive"
                                                checked
                                                aria-label="Include {{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }} maximum"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <p id="ruleConditionError" class="d-none tw-m-0 tw-text-ui-xs tw-font-semibold tw-text-error" role="alert" tabindex="-1">
                    Define at least one minimum or maximum dimensional boundary before saving this rule.
                </p>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="ruleNotes">
                        Tariff Notes &amp; Legal Reference <span class="tw-text-on-surface-variant tw-font-normal">(Optional)</span>
                    </label>
                    <textarea
                        name="notes"
                        id="ruleNotes"
                        class="form-control"
                        rows="2"
                        maxlength="5000"
                        placeholder="e.g. BTKI 2022 Chapter 72 - Other alloy steel hot-rolled bars"
                    >{{ old('form_context') === 'rule' ? old('notes') : '' }}</textarea>
                </div>
            </div>

            <div class="modal-footer tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="submit" id="btnSaveRule">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="spinnerSaveRule"></span>
                    <x-ui.icon name="check" size="sm" />
                    Save Rule
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
