<script>
$(function () {
    const materialModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('materialModal'));
    const ruleModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleModal'));
    const materialStoreUrl = @json(route('admin.material-masters.store'));
    const materialUpdateUrl = @json(route('admin.material-masters.update', 0));
    const materialStatusUrl = @json(route('admin.material-masters.status', 0));
    const ruleStoreUrl = @json(route('admin.hs-code-rules.store'));
    const ruleUpdateUrl = @json(route('admin.hs-code-rules.update', 0));
    const ruleStatusUrl = @json(route('admin.hs-code-rules.status', 0));
    const csrfToken = @json(csrf_token());
    const oldContext = @json(old('form_context'));
    const oldInput = @json(old());
    const shapeDimensions = {
        Flat: ['thickness', 'width', 'length'],
        Round: ['d_outer', 'length'],
        Hollow: ['d_inner', 'd_outer', 'length']
    };
    let qualityLoaded = false;

    const materialsTable = $('#materialsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: @json(route('admin.material-masters.data')),
            data: (data) => Object.assign(data, {
                status: $('#materialStatusFilter').val(),
                category: $('#materialCategoryFilter').val(),
                density: $('#materialDensityFilter').val(),
                manufacturer: $('#materialManufacturerFilter').val()
            })
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
            { data: 'material_code', name: 'material_code' },
            { data: 'raw_category', name: 'raw_category', defaultContent: '-' },
            { data: 'hs_category', name: 'hs_category', defaultContent: '-' },
            { data: 'density_profile', name: 'density_profile' },
            { data: 'manufacturer_scope', name: 'manufacturer_scope' },
            { data: 'status_badge', name: 'is_active', searchable: false },
            { data: 'source_display', name: 'source_sheet', searchable: false },
            { data: 'action', orderable: false, searchable: false, className: 'text-nowrap' }
        ],
        pageLength: 25,
        order: []
    });

    const rulesTable = $('#rulesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: @json(route('admin.hs-code-rules.data')),
            data: (data) => Object.assign(data, {
                status: $('#ruleStatusFilter').val(),
                category: $('#ruleCategoryFilter').val(),
                shape: $('#ruleShapeFilter').val()
            })
        },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
            { data: 'hs_code', name: 'hs_code' },
            { data: 'material_category', name: 'material_category' },
            { data: 'shape', name: 'shape' },
            { data: 'conditions_display', orderable: false, searchable: false },
            { data: 'priority', name: 'priority', className: 'text-center' },
            { data: 'status_badge', name: 'status', searchable: false },
            { data: 'source_display', orderable: false, searchable: false },
            { data: 'action', orderable: false, searchable: false, className: 'text-nowrap' }
        ],
        pageLength: 25,
        order: []
    });

    $('#materialStatusFilter, #materialCategoryFilter, #materialDensityFilter, #materialManufacturerFilter').on('change', () => materialsTable.ajax.reload());
    $('#ruleStatusFilter, #ruleCategoryFilter, #ruleShapeFilter').on('change', () => rulesTable.ajax.reload());

    function setMethod($input, method) {
        if (method) {
            $input.attr('name', '_method').val(method);
        } else {
            $input.removeAttr('name').val('');
        }
    }

    function resetMaterialForm() {
        document.getElementById('materialForm').reset();
        $('#materialForm').attr('action', materialStoreUrl);
        setMethod($('#materialFormMethod'), null);
        $('#materialRecordId').val('');
        $('#materialModalTitle').text('Add Material');
        $('#materialActive').prop('checked', true);
        $('#materialDensity').val('steel');
        $('#materialManufacturer').val('unknown');
    }

    function editMaterial(material) {
        resetMaterialForm();
        $('#materialForm').attr('action', materialUpdateUrl.replace('/0', `/${material.id}`));
        setMethod($('#materialFormMethod'), 'PUT');
        $('#materialRecordId').val(material.id);
        $('#materialModalTitle').text('Edit Material');
        $('#materialCode').val(material.material_code || '');
        $('#materialRawCategory').val(material.raw_category || '');
        $('#materialHsCategory').val(material.hs_category || '');
        $('#materialDensity').val(material.density_profile || 'steel');
        $('#materialManufacturer').val(material.manufacturer_scope || 'unknown');
        $('#materialActive').prop('checked', material.is_active === true || material.is_active === 1);
        materialModal.show();
    }

    $('#btnAddMaterial').on('click', () => { resetMaterialForm(); materialModal.show(); });
    $(document).on('click', '.btn-edit-material', function () { editMaterial(JSON.parse($(this).attr('data-material'))); });

    function refreshQuality() {
        qualityLoaded = false;
        $('#qualityLoading').removeClass('d-none');
        $('#qualityContent').addClass('d-none');
        loadQuality();
    }

    $(document).on('click', '.btn-toggle-material', function () {
        const button = $(this);
        $.ajax({
            url: materialStatusUrl.replace('/0/', `/${button.data('id')}/`),
            method: 'POST',
            data: { _token: csrfToken, _method: 'PATCH', is_active: button.data('active') }
        }).done(() => { materialsTable.ajax.reload(null, false); refreshQuality(); })
          .fail(showAjaxError);
    });

    function resetRuleConditions() {
        $('.rule-condition-row').each(function () {
            $(this).find('.condition-min, .condition-max').val('');
            $(this).find('.condition-min-inclusive, .condition-max-inclusive').prop('checked', true);
        });
    }

    function applyRuleShape() {
        const relevant = shapeDimensions[$('#ruleShape').val()] || [];
        $('.rule-condition-row').each(function () {
            const enabled = relevant.includes($(this).data('dimension'));
            $(this).toggleClass('d-none', !enabled);
            if (!enabled) {
                $(this).find('.condition-min, .condition-max').val('');
            }
        });
    }

    function populateConditions(conditions) {
        resetRuleConditions();
        Object.entries(conditions || {}).forEach(([dimension, bounds]) => {
            const $row = $(`.rule-condition-row[data-dimension="${dimension}"]`);
            $row.find('.condition-min').val(bounds.min ?? '');
            $row.find('.condition-max').val(bounds.max ?? '');
            $row.find('.condition-min-inclusive').prop('checked', bounds.min_inclusive !== false);
            $row.find('.condition-max-inclusive').prop('checked', bounds.max_inclusive !== false);
        });
    }

    function resetRuleForm() {
        document.getElementById('ruleForm').reset();
        $('#ruleForm').attr('action', ruleStoreUrl);
        setMethod($('#ruleFormMethod'), null);
        $('#ruleRecordId').val('');
        $('#ruleModalTitle').text('Add HS Code Rule');
        $('#rulePriority').val(100);
        $('#ruleStatus').val('active');
        resetRuleConditions();
        applyRuleShape();
    }

    function editRule(rule) {
        resetRuleForm();
        $('#ruleForm').attr('action', ruleUpdateUrl.replace('/0', `/${rule.id}`));
        setMethod($('#ruleFormMethod'), 'PUT');
        $('#ruleRecordId').val(rule.id);
        $('#ruleModalTitle').text('Edit HS Code Rule');
        $('#ruleHsCode').val(rule.hs_code || '');
        $('#ruleCategory').val(rule.material_category);
        $('#ruleShape').val(rule.shape);
        $('#rulePriority').val(rule.priority || 100);
        $('#ruleStatus').val(rule.status || 'inactive');
        $('#ruleNotes').val(rule.notes || '');
        applyRuleShape();
        populateConditions(rule.conditions || {});
        ruleModal.show();
    }

    $('#btnAddRule').on('click', () => { resetRuleForm(); ruleModal.show(); });
    $('#ruleShape').on('change', applyRuleShape);
    $(document).on('click', '.btn-edit-rule', function () { editRule(JSON.parse($(this).attr('data-rule'))); });

    $('#ruleForm').on('submit', function (event) {
        const conditions = {};
        $('.rule-condition-row:not(.d-none)').each(function () {
            const min = $(this).find('.condition-min').val();
            const max = $(this).find('.condition-max').val();
            if (min === '' && max === '') return;
            conditions[$(this).data('dimension')] = {
                min: min === '' ? null : Number(min),
                min_inclusive: $(this).find('.condition-min-inclusive').is(':checked'),
                max: max === '' ? null : Number(max),
                max_inclusive: $(this).find('.condition-max-inclusive').is(':checked')
            };
        });
        if (Object.keys(conditions).length === 0) {
            event.preventDefault();
            AdasiAlert.error({ title: 'Condition Required', text: 'Enter at least one minimum or maximum dimension.' });
            return;
        }
        $('#ruleConditionsJson').val(JSON.stringify(conditions));
        $(this).find('button[type="submit"]').prop('disabled', true).find('.spinner-border').removeClass('d-none');
    });

    $('#materialForm').on('submit', function () {
        $(this).find('button[type="submit"]').prop('disabled', true).find('.spinner-border').removeClass('d-none');
    });

    $(document).on('click', '.btn-toggle-rule', function () {
        const button = $(this);
        $.ajax({
            url: ruleStatusUrl.replace('/0/', `/${button.data('id')}/`),
            method: 'POST',
            data: { _token: csrfToken, _method: 'PATCH', status: button.data('status') }
        }).done(() => { rulesTable.ajax.reload(null, false); refreshQuality(); })
          .fail(showAjaxError);
    });

    function showAjaxError(xhr) {
        const errors = xhr.responseJSON?.errors || {};
        const first = Object.values(errors)[0];
        AdasiAlert.error({ title: 'Unable to Save', text: Array.isArray(first) ? first[0] : (first || xhr.responseJSON?.message || 'Request failed.') });
    }

    function loadQuality() {
        if (qualityLoaded) return;
        qualityLoaded = true;
        $.getJSON(@json(route('admin.master-data-quality.index'))).done((report) => {
            const summary = report.summary || {};
            const attention = report.needs_attention || {};
            const referenceNotes = report.reference_notes || {};
            const cards = [
                ['Materials', summary.materials, 'boxes', 'text-primary'],
                ['With HS Mapping', summary.materials_with_hs_mapping, 'network', 'text-success'],
                ['Needs HS Mapping', summary.materials_needing_hs_mapping, 'circle-help', 'text-warning'],
                ['Active HS Rules', summary.active_hs_rules, 'check-circle', 'text-success'],
                ['Needs Review', summary.rules_needing_review, 'circle-alert', 'text-danger']
            ];
            const $cards = $('#qualityCards').empty();
            cards.forEach(([label, value, icon, colorClass]) => {
                $('<div class="col-sm-6 col-lg">').append(
                    $('<div class="border rounded-3 p-3 h-100">').append(
                        $('<div class="small text-muted">').append($('<i>').addClass(`bi ${icon} ${colorClass} me-1`).attr('aria-hidden', 'true')).append(document.createTextNode(label)),
                        $('<div class="fs-4 fw-bold mt-1">').text(value)
                    )
                ).appendTo($cards);
            });
            renderTagList('#unmappedMaterials', attention.materials_without_hs_mapping, 'All materials have an HS mapping.');
            renderTagList('#categoriesWithoutRules', attention.categories_without_active_hs_rules, 'Every mapped category has an active HS rule.');
            renderRulesNeedingReview(attention.rules_needing_review || []);

            const duplicateCoverage = referenceNotes.duplicate_rule_coverage || {};
            $('#duplicateRuleCoverage').text(duplicateCoverage.message || 'No duplicate rule coverage was found.');
            renderInactiveRulesForReference(referenceNotes.inactive_rules_kept_for_reference || []);
            renderTagList('#unusedRuleCategories', referenceNotes.rule_categories_not_used_by_materials, 'All active rule categories are used by current materials.');
            renderTagList('#referenceOnlyMaterials', referenceNotes.reference_only_materials, 'None.');
            $('#qualityLoading').addClass('d-none');
            $('#qualityContent').removeClass('d-none');
        }).fail(() => {
            qualityLoaded = false;
            $('#qualityLoading').html('<div class="alert alert-danger">Data Quality could not be loaded.</div>');
        });
    }

    function renderTagList(selector, values, emptyMessage) {
        const $container = $(selector).empty();
        if (!Array.isArray(values) || values.length === 0) {
            $('<span class="text-muted">').text(emptyMessage).appendTo($container);
            return;
        }

        const $tags = $('<div class="d-flex flex-wrap gap-2">');
        values.forEach((value) => $('<span class="badge text-bg-light border text-dark fw-normal">').text(value).appendTo($tags));
        $tags.appendTo($container);
    }

    function renderRulesNeedingReview(items) {
        const $container = $('#rulesNeedingReview').empty();
        if (!items.length) {
            $('<div class="text-success">').text('No active rules need review.').appendTo($container);
            return;
        }

        items.forEach((item) => {
            const $item = $('<div class="border rounded-3 bg-light p-3 mb-2">');
            $('<div class="fw-semibold">').text(`${item.category} / ${item.shape}`).appendTo($item);
            $('<div class="text-muted mt-1">').text(item.message).appendTo($item);
            $('<div class="mt-2">').append($('<span class="small fw-semibold me-2">').text('HS Codes:'), document.createTextNode((item.hs_codes || []).join(', '))).appendTo($item);
            $item.appendTo($container);
        });
    }

    function renderInactiveRulesForReference(items) {
        const $container = $('#inactiveRulesForReference').empty();
        if (!items.length) {
            $('<span class="text-muted">').text('None.').appendTo($container);
            return;
        }

        const $list = $('<ul class="list-unstyled mb-0">');
        items.forEach((item) => $('<li class="mb-2">')
            .append($('<span class="font-monospace me-1">').text(item.hs_code), document.createTextNode(item.note ? `— ${item.note}` : '— Kept inactive for reference.'))
            .appendTo($list));
        $list.appendTo($container);
    }

    $('button[data-bs-target="#data-quality"]').on('shown.bs.tab', loadQuality);
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () { history.replaceState(null, '', $(this).attr('data-bs-target')); });

    if (location.hash) {
        const trigger = document.querySelector(`[data-bs-target="${location.hash}"]`);
        if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
    }

    if (oldContext === 'material') {
        if (oldInput.record_id) {
            $('#materialForm').attr('action', materialUpdateUrl.replace('/0', `/${oldInput.record_id}`));
            setMethod($('#materialFormMethod'), 'PUT');
            $('#materialModalTitle').text('Edit Material');
        }
        $('#materialHsCategory').val(oldInput.hs_category || '');
        $('#materialDensity').val(oldInput.density_profile || 'steel');
        $('#materialManufacturer').val(oldInput.manufacturer_scope || 'unknown');
        $('#materialActive').prop('checked', String(oldInput.is_active) === '1');
        materialModal.show();
    } else if (oldContext === 'rule') {
        if (oldInput.record_id) {
            $('#ruleForm').attr('action', ruleUpdateUrl.replace('/0', `/${oldInput.record_id}`));
            setMethod($('#ruleFormMethod'), 'PUT');
            $('#ruleModalTitle').text('Edit HS Code Rule');
        }
        $('#ruleCategory').val(oldInput.material_category);
        $('#ruleShape').val(oldInput.shape);
        $('#rulePriority').val(oldInput.priority || 100);
        $('#ruleStatus').val(oldInput.status || 'inactive');
        applyRuleShape();
        try { populateConditions(JSON.parse(oldInput.conditions_json || '{}')); } catch (error) {}
        ruleModal.show();
    }
});
</script>
