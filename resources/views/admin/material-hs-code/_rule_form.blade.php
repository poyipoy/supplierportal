<div class="modal fade" id="ruleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form id="ruleForm" method="POST" action="{{ route('admin.hs-code-rules.store') }}" class="modal-content">
            @csrf
            <input type="hidden" id="ruleFormMethod">
            <input type="hidden" name="form_context" value="rule">
            <input type="hidden" name="record_id" id="ruleRecordId" value="{{ old('form_context') === 'rule' ? old('record_id') : '' }}">
            <input type="hidden" name="conditions_json" id="ruleConditionsJson" value="{{ old('form_context') === 'rule' ? old('conditions_json') : '' }}">
            <div class="modal-header"><h5 class="modal-title" id="ruleModalTitle">Add HS Code Rule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><label class="form-label">Stable Rule Key</label><input type="text" name="rule_key" id="ruleKey" class="form-control" maxlength="150" required value="{{ old('form_context') === 'rule' ? old('rule_key') : '' }}"></div>
                    <div class="col-md-2"><label class="form-label">HS Code</label><input type="text" name="hs_code" id="ruleHsCode" class="form-control" placeholder="7228.30.10" required value="{{ old('form_context') === 'rule' ? old('hs_code') : '' }}"></div>
                    <div class="col-md-2"><label class="form-label">Category</label><select name="material_category" id="ruleCategory" class="form-select" required>@foreach($hsCategories as $category)<option value="{{ $category }}">{{ str_replace('_', ' ', $category) }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label class="form-label">Shape</label><select name="shape" id="ruleShape" class="form-select" required>@foreach($shapes as $shape)<option value="{{ $shape }}">{{ $shape }}</option>@endforeach</select></div>
                    <div class="col-md-1"><label class="form-label">Priority</label><input type="number" name="priority" id="rulePriority" class="form-control" min="1" max="65535" value="100" required></div>
                    <div class="col-md-1"><label class="form-label">Status</label><select name="status" id="ruleStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option><option value="conflict">Conflict</option></select></div>
                </div>
                <h6 class="fw-bold">Dimension Bounds (mm)</h6>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Dimension</th><th>Minimum</th><th>Min inclusive</th><th>Maximum</th><th>Max inclusive</th></tr></thead><tbody>
                    @foreach(\App\Models\PrItem::DIMENSION_FIELDS as $dimension)
                        <tr class="rule-condition-row" data-dimension="{{ $dimension }}"><td>{{ \App\Models\PrItem::DIMENSION_LABELS[$dimension] }}</td><td><input type="number" step="0.0001" class="form-control form-control-sm condition-min"></td><td><input type="checkbox" class="form-check-input condition-min-inclusive" checked></td><td><input type="number" step="0.0001" class="form-control form-control-sm condition-max"></td><td><input type="checkbox" class="form-check-input condition-max-inclusive" checked></td></tr>
                    @endforeach
                </tbody></table></div>
                <div class="form-text mb-3">Leave both bounds empty when a dimension is not part of the rule.</div>
                <label class="form-label">Notes</label><textarea name="notes" id="ruleNotes" class="form-control" rows="3" maxlength="5000">{{ old('form_context') === 'rule' ? old('notes') : '' }}</textarea>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span>Save Rule</button></div>
        </form>
    </div>
</div>
