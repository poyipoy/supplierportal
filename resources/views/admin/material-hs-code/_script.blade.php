@php
    $safeOldInput = \Illuminate\Support\Arr::only((array) old(), [
        'record_id', 'hs_category', 'density_profile', 'manufacturer_scope',
        'is_active', 'material_category', 'shape', 'priority', 'status', 'conditions_json',
    ]);
@endphp
<script>
(() => {
const bootMaterialHsMaster = () => {
    const materialModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('materialModal'));
    const ruleModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleModal'));
    const materialStoreUrl = @json(route($masterRoutePrefix.'.material-masters.store'));
    const materialUpdateUrl = @json(route($masterRoutePrefix.'.material-masters.update', 0));
    const materialStatusUrl = @json(route($masterRoutePrefix.'.material-masters.status', 0));
    const ruleStoreUrl = @json(route($masterRoutePrefix.'.hs-code-rules.store'));
    const ruleUpdateUrl = @json(route($masterRoutePrefix.'.hs-code-rules.update', 0));
    const ruleStatusUrl = @json(route($masterRoutePrefix.'.hs-code-rules.status', 0));
    const materialDestroyUrl = @json(route($masterRoutePrefix.'.material-masters.destroy', 0));
    const ruleDestroyUrl = @json(route($masterRoutePrefix.'.hs-code-rules.destroy', 0));
    const csrfToken = @json(csrf_token());
    const oldContext = @json(old('form_context'));
    const oldInput = @json($safeOldInput);
    const shapeDimensions = {
        Flat: ['thickness', 'width', 'length'],
        Round: ['d_outer', 'length'],
        Hollow: ['d_inner', 'd_outer', 'length']
    };
    let qualityLoaded = false;

    const materialsTable = new DataTable('#materialsTable', {
        processing: true,
        serverSide: true,
        layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
        ajax: {
            url: @json(route($masterRoutePrefix.'.material-masters.data')),
            data: (data) => Object.assign(data, {
                status: document.getElementById('materialStatusFilter')?.value ?? '',
                category: document.getElementById('materialCategoryFilter')?.value ?? '',
                density: document.getElementById('materialDensityFilter')?.value ?? '',
                manufacturer: document.getElementById('materialManufacturerFilter')?.value ?? ''
            })
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center text-muted' },
            { data: 'material_code', name: 'material_code', className: 'ui-tabular-nums fw-semibold text-primary text-nowrap' },
            { data: 'raw_category', name: 'raw_category', defaultContent: '-' },
            { data: 'hs_category_label', name: 'hs_category', defaultContent: '-' },
            { data: 'density_profile_label', name: 'density_profile' },
            { data: 'manufacturer_scope_label', name: 'manufacturer_scope' },
            { data: 'status_badge', name: 'is_active', searchable: false },
            { data: 'source_display', name: 'source_sheet', searchable: false },
            { data: 'updated_date', name: 'updated_at', className: 'text-nowrap' },
            { data: 'action', orderable: false, searchable: false, className: 'text-end text-nowrap' }
        ],

        order: []
    });

    const rulesTable = new DataTable('#rulesTable', {
        processing: true,
        serverSide: true,
        layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
        ajax: {
            url: @json(route($masterRoutePrefix.'.hs-code-rules.data')),
            data: (data) => Object.assign(data, {
                status: document.getElementById('ruleStatusFilter')?.value ?? '',
                category: document.getElementById('ruleCategoryFilter')?.value ?? '',
                shape: document.getElementById('ruleShapeFilter')?.value ?? ''
            })
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center text-muted' },
            { data: 'hs_code', name: 'hs_code', className: 'ui-tabular-nums fw-semibold text-primary text-nowrap' },
            { data: 'material_category_label', name: 'material_category' },
            { data: 'shape_label', name: 'shape' },
            { data: 'conditions_display', orderable: false, searchable: false },
            { data: 'priority', name: 'priority', className: 'text-center' },
            { data: 'status_badge', name: 'status', searchable: false },
            { data: 'source_display', orderable: false, searchable: false },
            { data: 'updated_date', name: 'updated_at', className: 'text-nowrap' },
            { data: 'action', orderable: false, searchable: false, className: 'text-end text-nowrap' }
        ],

        order: []
    });

    window.AdasiFilterBar.bindLive('#materialsFilterBar', {
        debounceMs: 250,
        fields: [
            { key: 'search', label: @json(__('local_invoice.actions.search')), selector: '#materialSearch', type: 'search' },
            { key: 'status', label: @json(__('admin.copy.status')), selector: '#materialStatusFilter', type: 'select' },
            { key: 'category', label: @json(__('admin.copy.hs_category')), selector: '#materialCategoryFilter', type: 'select', advanced: true },
            { key: 'density', label: @json(__('admin.copy.density_profile')), selector: '#materialDensityFilter', type: 'select', advanced: true },
            { key: 'manufacturer', label: @json(__('admin.copy.manufacturer_scope')), selector: '#materialManufacturerFilter', type: 'select', advanced: true },
        ],
        onFilterChange: () => materialsTable.search(document.getElementById('materialSearch')?.value || '').ajax.reload(),
    });

    window.AdasiFilterBar.bindLive('#rulesFilterBar', {
        debounceMs: 250,
        fields: [
            { key: 'search', label: @json(__('local_invoice.actions.search')), selector: '#ruleSearch', type: 'search' },
            { key: 'status', label: @json(__('admin.copy.status')), selector: '#ruleStatusFilter', type: 'select' },
            { key: 'category', label: @json(__('admin.copy.category')), selector: '#ruleCategoryFilter', type: 'select', advanced: true },
            { key: 'shape', label: @json(__('admin.copy.shape')), selector: '#ruleShapeFilter', type: 'select', advanced: true },
        ],
        onFilterChange: () => rulesTable.search(document.getElementById('ruleSearch')?.value || '').ajax.reload(),
    });

    function setMethod(inputEl, method) {
        if (!inputEl) return;
        if (method) {
            inputEl.setAttribute('name', '_method');
            inputEl.value = method;
        } else {
            inputEl.removeAttribute('name');
            inputEl.value = '';
        }
    }

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value ?? '';
    }

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = String(text ?? '');
    }

    function setChecked(id, checked) {
        const el = document.getElementById(id);
        if (el) el.checked = Boolean(checked);
    }

    const createTextEl = (tag, className = '') => ({
        text(value) {
            const el = document.createElement(tag);
            if (className) el.className = className;
            el.textContent = String(value ?? '');
            return el;
        },
    });

    async function sendMasterRequest(url, { method = 'GET', data = null } = {}) {
        const headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const options = {
            method,
            headers,
            credentials: 'same-origin',
        };

        if (method !== 'GET') {
            headers['X-CSRF-TOKEN'] = csrfToken;
            if (data) {
                const params = new URLSearchParams();
                Object.entries(data).forEach(([key, value]) => {
                    params.append(key, value === null || value === undefined ? '' : String(value));
                });
                options.body = params;
            }
        }

        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw Object.assign(new Error(`HTTP ${response.status}`), {
                status: response.status,
                responseJSON: payload,
            });
        }

        return payload;
    }

    function resetMaterialForm() {
        const form = document.getElementById('materialForm');
        form?.reset();
        form?.setAttribute('action', materialStoreUrl);
        setMethod(document.getElementById('materialFormMethod'), null);
        setValue('materialRecordId', '');
        setText('materialModalTitle', @json(__('admin.copy.add_material')));
        setChecked('materialActive', true);
        setValue('materialDensity', 'steel');
        setValue('materialManufacturer', 'unknown');
    }

    function editMaterial(material) {
        resetMaterialForm();
        document.getElementById('materialForm')?.setAttribute('action', materialUpdateUrl.replace('/0', `/${material.id}`));
        setMethod(document.getElementById('materialFormMethod'), 'PUT');
        setValue('materialRecordId', material.id);
        setText('materialModalTitle', @json(__('admin.copy.edit_material')));
        setValue('materialCode', material.material_code || '');
        setValue('materialRawCategory', material.raw_category || '');
        setValue('materialHsCategory', material.hs_category || '');
        setValue('materialDensity', material.density_profile || 'steel');
        setValue('materialManufacturer', material.manufacturer_scope || 'unknown');
        setChecked('materialActive', material.is_active === true || material.is_active === 1);
        materialModal.show();
    }

    document.getElementById('btnAddMaterial')?.addEventListener('click', () => {
        resetMaterialForm();
        materialModal.show();
    });

    function refreshQuality() {
        qualityLoaded = false;
        document.getElementById('qualityLoading')?.classList.remove('d-none');
        document.getElementById('qualityContent')?.classList.add('d-none');
        loadQuality();
    }

    // Q3 guarded hard delete. A referenced record is refused by the server
    // (422, audited); offer deactivation instead.
    function deleteMasterRecord(url, name, table, deactivate) {
        AdasiAlert.confirm({
            title: @json(__('materials.master_data.delete_confirm_title')),
            text: name,
            confirmText: @json(__('materials.master_data.delete')),
            confirmTone: 'danger'
        }, true).then((result) => {
            if (!result.isConfirmed) return;
            sendMasterRequest(url, { method: 'POST', data: { _token: csrfToken, _method: 'DELETE' } })
                .then((response) => {
                    table.ajax.reload(null, false);
                    refreshQuality();
                    AdasiToast.success(response.message);
                })
                .catch((err) => {
                    if (err?.status !== 422 || !err.responseJSON?.message) {
                        showAjaxError(err);
                        return;
                    }
                    AdasiAlert.confirm({
                        title: @json(__('materials.master_data.delete_refused_title')),
                        text: err.responseJSON.message,
                        confirmText: @json(__('materials.master_data.deactivate_instead')),
                        confirmTone: 'primary'
                    }).then((choice) => { if (choice.isConfirmed) deactivate(); });
                });
        });
    }

    function resetRuleConditions() {
        document.querySelectorAll('.rule-condition-row').forEach((rowEl) => {
            rowEl.querySelectorAll('.condition-min, .condition-max').forEach((inputEl) => {
                inputEl.value = '';
            });
            rowEl.querySelectorAll('.condition-min-inclusive, .condition-max-inclusive').forEach((checkEl) => {
                checkEl.checked = true;
            });
        });
    }

    function applyRuleShape() {
        const shapeValue = document.getElementById('ruleShape')?.value || '';
        const relevant = shapeDimensions[shapeValue] || [];
        document.querySelectorAll('.rule-condition-row').forEach((rowEl) => {
            const enabled = relevant.includes(rowEl.dataset.dimension);
            rowEl.classList.toggle('d-none', !enabled);
            if (!enabled) {
                rowEl.querySelectorAll('.condition-min, .condition-max').forEach((inputEl) => {
                    inputEl.value = '';
                });
            }
        });
    }

    function populateConditions(conditions) {
        resetRuleConditions();
        Object.entries(conditions || {}).forEach(([dimension, bounds]) => {
            const rowEl = document.querySelector(`.rule-condition-row[data-dimension="${CSS.escape(dimension)}"]`);
            if (!rowEl) return;
            const minInput = rowEl.querySelector('.condition-min');
            const maxInput = rowEl.querySelector('.condition-max');
            const minCheck = rowEl.querySelector('.condition-min-inclusive');
            const maxCheck = rowEl.querySelector('.condition-max-inclusive');
            if (minInput) minInput.value = bounds.min ?? '';
            if (maxInput) maxInput.value = bounds.max ?? '';
            if (minCheck) minCheck.checked = bounds.min_inclusive !== false;
            if (maxCheck) maxCheck.checked = bounds.max_inclusive !== false;
        });
    }

    function resetRuleForm() {
        const form = document.getElementById('ruleForm');
        form?.reset();
        form?.setAttribute('action', ruleStoreUrl);
        setMethod(document.getElementById('ruleFormMethod'), null);
        setValue('ruleRecordId', '');
        setText('ruleModalTitle', @json(__('admin.copy.add_hs_code_rule')));
        setValue('rulePriority', 100);
        setValue('ruleStatus', 'active');
        resetRuleConditions();
        applyRuleShape();
    }

    function editRule(rule) {
        resetRuleForm();
        document.getElementById('ruleForm')?.setAttribute('action', ruleUpdateUrl.replace('/0', `/${rule.id}`));
        setMethod(document.getElementById('ruleFormMethod'), 'PUT');
        setValue('ruleRecordId', rule.id);
        setText('ruleModalTitle', @json(__('admin.copy.edit_hs_code_rule')));
        setValue('ruleHsCode', rule.hs_code || '');
        setValue('ruleCategory', rule.material_category);
        setValue('ruleShape', rule.shape);
        setValue('rulePriority', rule.priority || 100);
        setValue('ruleStatus', rule.status || 'inactive');
        setValue('ruleNotes', rule.notes || '');
        applyRuleShape();
        populateConditions(rule.conditions || {});
        ruleModal.show();
    }

    document.getElementById('btnAddRule')?.addEventListener('click', () => {
        resetRuleForm();
        ruleModal.show();
    });
    document.getElementById('ruleShape')?.addEventListener('change', applyRuleShape);

    document.addEventListener('click', (e) => {
        const target = e.target;

        const editMatBtn = target.closest('.btn-edit-material');
        if (editMatBtn) {
            editMaterial(JSON.parse(editMatBtn.getAttribute('data-material')));
            return;
        }

        const toggleMatBtn = target.closest('.btn-toggle-material');
        if (toggleMatBtn) {
            sendMasterRequest(materialStatusUrl.replace('/0/', `/${toggleMatBtn.dataset.id}/`), {
                method: 'POST',
                data: { _token: csrfToken, _method: 'PATCH', is_active: toggleMatBtn.dataset.active }
            }).then(() => {
                materialsTable.ajax.reload(null, false);
                refreshQuality();
                AdasiToast.success(@json(__('admin.copy.material_status_updated')));
            }).catch(showAjaxError);
            return;
        }

        const deleteMatBtn = target.closest('.btn-delete-material');
        if (deleteMatBtn) {
            const matId = deleteMatBtn.dataset.id;
            deleteMasterRecord(materialDestroyUrl.replace(/\/0$/, `/${matId}`), String(deleteMatBtn.dataset.name), materialsTable, () => {
                sendMasterRequest(materialStatusUrl.replace('/0/', `/${matId}/`), {
                    method: 'POST',
                    data: { _token: csrfToken, _method: 'PATCH', is_active: 0 }
                }).then(() => {
                    materialsTable.ajax.reload(null, false);
                    refreshQuality();
                    AdasiToast.success(@json(__('admin.copy.material_status_updated')));
                }).catch(showAjaxError);
            });
            return;
        }

        const editRuleBtn = target.closest('.btn-edit-rule');
        if (editRuleBtn) {
            editRule(JSON.parse(editRuleBtn.getAttribute('data-rule')));
            return;
        }

        const toggleRuleBtn = target.closest('.btn-toggle-rule');
        if (toggleRuleBtn) {
            sendMasterRequest(ruleStatusUrl.replace('/0/', `/${toggleRuleBtn.dataset.id}/`), {
                method: 'POST',
                data: { _token: csrfToken, _method: 'PATCH', status: toggleRuleBtn.dataset.status }
            }).then(() => {
                rulesTable.ajax.reload(null, false);
                refreshQuality();
                AdasiToast.success(@json(__('admin.copy.hs_code_rule_status_updated')));
            }).catch(showAjaxError);
            return;
        }

        const deleteRuleBtn = target.closest('.btn-delete-rule');
        if (deleteRuleBtn) {
            const ruleId = deleteRuleBtn.dataset.id;
            deleteMasterRecord(ruleDestroyUrl.replace(/\/0$/, `/${ruleId}`), String(deleteRuleBtn.dataset.name), rulesTable, () => {
                sendMasterRequest(ruleStatusUrl.replace('/0/', `/${ruleId}/`), {
                    method: 'POST',
                    data: { _token: csrfToken, _method: 'PATCH', status: 'inactive' }
                }).then(() => {
                    rulesTable.ajax.reload(null, false);
                    refreshQuality();
                    AdasiToast.success(@json(__('admin.copy.hs_code_rule_status_updated')));
                }).catch(showAjaxError);
            });
        }
    });

    document.getElementById('ruleForm')?.addEventListener('submit', function (event) {
        const conditions = {};
        document.querySelectorAll('.rule-condition-row:not(.d-none)').forEach((rowEl) => {
            const min = rowEl.querySelector('.condition-min')?.value ?? '';
            const max = rowEl.querySelector('.condition-max')?.value ?? '';
            if (min === '' && max === '') return;
            conditions[rowEl.dataset.dimension] = {
                min: min === '' ? null : Number(min),
                min_inclusive: Boolean(rowEl.querySelector('.condition-min-inclusive')?.checked),
                max: max === '' ? null : Number(max),
                max_inclusive: Boolean(rowEl.querySelector('.condition-max-inclusive')?.checked),
            };
        });

        const errorEl = document.getElementById('ruleConditionError');
        if (Object.keys(conditions).length === 0) {
            event.preventDefault();
            if (errorEl) {
                errorEl.classList.remove('d-none');
                errorEl.focus();
            }
            return;
        }
        errorEl?.classList.add('d-none');
        setValue('ruleConditionsJson', JSON.stringify(conditions));
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.querySelector('.ui-spinner')?.classList.remove('d-none');
        }
    });

    document.getElementById('materialForm')?.addEventListener('submit', function () {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.querySelector('.ui-spinner')?.classList.remove('d-none');
        }
    });

    function showAjaxError(err) {
        const errors = err?.responseJSON?.errors || {};
        const first = Object.values(errors)[0];
        AdasiToast.error(Array.isArray(first) ? first[0] : (first || err?.responseJSON?.message || @json(__('admin.copy.request_failed'))));
    }

    function loadQuality() {
        if (qualityLoaded) return;
        qualityLoaded = true;
        sendMasterRequest(@json(route($masterRoutePrefix.'.master-data-quality.index')))
            .then((report) => {
                const summary = report.summary || {};
                const attention = report.needs_attention || {};
                const referenceNotes = report.reference_notes || {};
                setText('qualityMaterials', summary.materials ?? 0);
                setText('qualityMapped', summary.materials_with_hs_mapping ?? 0);
                setText('qualityNeedsMapping', summary.materials_needing_hs_mapping ?? 0);
                setText('qualityActiveRules', summary.active_hs_rules ?? 0);
                setText('qualityNeedsReview', summary.rules_needing_review ?? 0);
                renderTagList('#unmappedMaterials', attention.materials_without_hs_mapping, @json(__('admin.copy.all_materials_have_an_hs_mapping')));
                renderTagList('#categoriesWithoutRules', attention.categories_without_active_hs_rules, @json(__('admin.copy.every_mapped_category_has_an_active_hs_rule')));
                renderRulesNeedingReview(attention.rules_needing_review || []);

                const duplicateCoverage = referenceNotes.duplicate_rule_coverage || {};
                setText('duplicateRuleCoverage', duplicateCoverage.message || @json(__('admin.copy.no_duplicate_rule_coverage_was_found')));
                renderInactiveRulesForReference(referenceNotes.inactive_rules_kept_for_reference || []);
                renderTagList('#unusedRuleCategories', referenceNotes.rule_categories_not_used_by_materials, @json(__('admin.copy.all_active_rule_categories_are_used_by_current_materials')));
                renderTagList('#referenceOnlyMaterials', referenceNotes.reference_only_materials, @json(__('admin.copy.none')));
                document.getElementById('qualityLoading')?.classList.add('d-none');
                document.getElementById('qualityError')?.classList.add('d-none');
                document.getElementById('qualityContent')?.classList.remove('d-none');
            })
            .catch(() => {
                qualityLoaded = false;
                document.getElementById('qualityLoading')?.classList.add('d-none');
                document.getElementById('qualityContent')?.classList.remove('d-none');
                document.getElementById('qualityError')?.classList.remove('d-none');
            });
    }

    function renderTagList(selector, values, emptyMessage) {
        const container = document.querySelector(selector);
        if (!container) return;
        container.replaceChildren();
        if (!Array.isArray(values) || values.length === 0) {
            container.appendChild(createTextEl('span', 'text-muted').text(emptyMessage));
            return;
        }

        const list = document.createElement('ul');
        list.className = 'list-unstyled mb-0 d-grid gap-1';
        values.forEach((value) => {
            list.appendChild(createTextEl('li', 'ui-tabular-nums small').text(value));
        });
        container.appendChild(list);
    }

    function renderRulesNeedingReview(items) {
        const container = document.getElementById('rulesNeedingReview');
        if (!container) return;
        container.replaceChildren();
        if (!items.length) {
            container.appendChild(createTextEl('div', 'text-muted').text(@json(__('admin.copy.no_active_rules_need_review'))));
            return;
        }

        items.forEach((item) => {
            const card = document.createElement('div');
            card.className = 'border-top pt-3 mt-3';

            card.appendChild(createTextEl('div', 'fw-semibold').text(`${item.category} / ${item.shape}`));
            card.appendChild(createTextEl('div', 'text-muted mt-1').text(item.message));

            const codesWrap = document.createElement('div');
            codesWrap.className = 'mt-2';
            codesWrap.appendChild(createTextEl('span', 'small fw-semibold me-2').text(@json(__('admin.copy.hs_codes'))));
            codesWrap.appendChild(document.createTextNode((item.hs_codes || []).join(', ')));
            card.appendChild(codesWrap);

            container.appendChild(card);
        });
    }

    function renderInactiveRulesForReference(items) {
        const container = document.getElementById('inactiveRulesForReference');
        if (!container) return;
        container.replaceChildren();
        if (!items.length) {
            container.appendChild(createTextEl('span', 'text-muted').text(@json(__('admin.copy.none'))));
            return;
        }

        const list = document.createElement('ul');
        list.className = 'list-unstyled mb-0';
        items.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'mb-2';
            li.appendChild(createTextEl('span', 'ui-tabular-nums me-1').text(item.hs_code));
            li.appendChild(document.createTextNode(item.note ? `— ${item.note}` : '— ' + @js(__('common.final_review.inactive_reference'))));
            list.appendChild(li);
        });
        container.appendChild(list);
    }

    document.querySelectorAll('button[data-bs-target="#data-quality"]').forEach((btn) => {
        btn.addEventListener('shown.bs.tab', loadQuality);
    });
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach((btn) => {
        btn.addEventListener('shown.bs.tab', () => {
            history.replaceState(null, '', btn.getAttribute('data-bs-target'));
        });
    });

    if (location.hash) {
        const trigger = document.querySelector(`[data-bs-target="${location.hash}"]`);
        if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
    }

    if (oldContext === 'material') {
        if (oldInput.record_id) {
            document.getElementById('materialForm')?.setAttribute('action', materialUpdateUrl.replace('/0', `/${oldInput.record_id}`));
            setMethod(document.getElementById('materialFormMethod'), 'PUT');
            setText('materialModalTitle', @json(__('admin.copy.edit_material')));
        }
        setValue('materialHsCategory', oldInput.hs_category || '');
        setValue('materialDensity', oldInput.density_profile || 'steel');
        setValue('materialManufacturer', oldInput.manufacturer_scope || 'unknown');
        setChecked('materialActive', String(oldInput.is_active) === '1');
        materialModal.show();
    } else if (oldContext === 'rule') {
        if (oldInput.record_id) {
            document.getElementById('ruleForm')?.setAttribute('action', ruleUpdateUrl.replace('/0', `/${oldInput.record_id}`));
            setMethod(document.getElementById('ruleFormMethod'), 'PUT');
            setText('ruleModalTitle', @json(__('admin.copy.edit_hs_code_rule')));
        }
        setValue('ruleCategory', oldInput.material_category);
        setValue('ruleShape', oldInput.shape);
        setValue('rulePriority', oldInput.priority || 100);
        setValue('ruleStatus', oldInput.status || 'inactive');
        applyRuleShape();
        try { populateConditions(JSON.parse(oldInput.conditions_json || '{}')); } catch (error) {}
        ruleModal.show();
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootMaterialHsMaster, { once: true });
} else {
    bootMaterialHsMaster();
}
})();
</script>
