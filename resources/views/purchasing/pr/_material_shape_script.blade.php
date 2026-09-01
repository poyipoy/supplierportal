const materialDimensionFields = @json(\App\Models\PrItem::RELEVANT_DIMENSIONS);
const fixedDimensionOrder = @json(\App\Models\PrItem::FIXED_DIMENSION_ORDER);
const materialMasterSearchUrl = @json(route('purchasing.material-masters.search'));
const materialCalculationPreviewUrl = @json(route('purchasing.material-calculations.preview'));
const materialPreviewTimers = new WeakMap();
const materialPreviewRequests = new WeakMap();
const materialSearchTimers = new WeakMap();
const materialSearchRequests = new WeakMap();

function renumberPrRows() {
    $('#itemsBody tr.item-row').each(function(index) {
        $(this).find('[data-pr-row-number]').text(index + 1);
    });
}

function positionMaterialSearchResults($row) {
    const $input = $row.find('.material-master-search');
    const $panel = $row.find('.material-search-results');

    if (!$input.length || !$panel.length || $panel.hasClass('d-none')) {
        return;
    }

    const input = $input[0];
    const rect = input.getBoundingClientRect();
    const viewportPadding = 8;
    const gap = 2;
    const width = Math.min(rect.width, Math.max(0, window.innerWidth - (viewportPadding * 2)));
    const panelHeight = Math.min($panel[0].scrollHeight || 220, 220);
    const fitsBelow = rect.bottom + gap + panelHeight <= window.innerHeight - viewportPadding;
    const fitsAbove = rect.top - gap - panelHeight >= viewportPadding;
    const opensAbove = !fitsBelow && fitsAbove;
    const left = Math.min(
        Math.max(rect.left, viewportPadding),
        Math.max(viewportPadding, window.innerWidth - width - viewportPadding),
    );

    $panel.css({
        bottom: opensAbove
            ? `${Math.max(viewportPadding, window.innerHeight - rect.top + gap)}px`
            : 'auto',
        left: `${left}px`,
        top: opensAbove
            ? 'auto'
            : `${Math.max(viewportPadding, rect.bottom + gap)}px`,
        position: 'fixed',
        zIndex: 1080,
        width: `${width}px`,
    });
}

function repositionVisibleMaterialSearchResults() {
    $('#itemsBody tr.item-row').each(function() {
        const $panel = $(this).find('.material-search-results');
        if (!$panel.hasClass('d-none')) {
            positionMaterialSearchResults($(this));
        }
    });
}

function setMaterialSearchOpen($row, open) {
    $row.find('.pr-sticky-material').toggleClass('material-search-open', open);
}

function applyMaterialShapeRules(row, clearIrrelevant = true) {
    const $row = $(row);
    const shape = $row.find('.material-shape-select').val();
    const relevantFields = materialDimensionFields[shape] || [];

    fixedDimensionOrder.forEach((field) => {
        const isRelevant = relevantFields.includes(field);
        const $cell = $row.find(`[data-dimension-field-cell="${field}"]`);
        const $input = $cell.find(`.dimension-input[data-dimension-field="${field}"]`);
        const $na = $cell.find(`[data-dimension-na="${field}"]`);

        if (!isRelevant && clearIrrelevant) {
            $input.val('');
        }

        $input
            .prop('disabled', !isRelevant)
            .prop('hidden', !isRelevant)
            .attr('aria-disabled', isRelevant ? 'false' : 'true');

        $na
            .toggleClass('d-none', isRelevant)
            .attr('aria-hidden', isRelevant ? 'true' : 'false');

        $cell.toggleClass('is-disabled', !isRelevant);
    });
}

function resetMaterialPreview($row) {
    $row.find('.hs-code-display').val('');
    $row.find('.hs-code-manual-override').val('0');
    $row.find('.hs-status-badge').removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error').addClass('ui-status-chip--neutral').text('Needs more data');
    $row.find('.weight-unit-display').val('0.0000');
    $row.find('.weight-manual-override').val('0');
}

function selectMaterialInRow($row, material) {
    $row.find('.material-master-id').val(material.id);
    $row.find('.material-master-search').val(material.material_code || material.text || '');
    $row.find('.material-search-results').empty().addClass('d-none');
    setMaterialSearchOpen($row, false);
    $row.find('.hs-code-manual-override').val('0');
    $row.find('.weight-manual-override').val('0');
    $row.find('.pr-remark-material-name').text(material.material_code || material.text || 'Material');
    scheduleMaterialPreview($row, 0);
}

function renderMaterialSearchResults($row, results) {
    const $panel = $row.find('.material-search-results').empty();
    if (!Array.isArray(results) || results.length === 0) {
        $('<div class="list-group-item small text-muted">No active master material found.</div>').appendTo($panel);
    } else {
        results.forEach((material) => {
            const category = material.category || 'unmapped';
            $('<button type="button" class="list-group-item list-group-item-action py-2">')
                .append($('<div class="fw-semibold">').text(material.material_code))
                .append($('<div class="small text-muted">').text(`${category} | ${material.density_profile}`))
                .data('material', material)
                .appendTo($panel);
        });
    }
    setMaterialSearchOpen($row, true);
    $panel.removeClass('d-none');
    positionMaterialSearchResults($row);
}

function searchMaterial($input) {
    const row = $input.closest('tr')[0];
    const $row = $(row);
    const term = String($input.val() || '').trim();

    clearTimeout(materialSearchTimers.get(row));
    materialSearchTimers.set(row, setTimeout(() => {
        const previous = materialSearchRequests.get(row);
        if (previous) {
            previous.abort();
        }

        const request = $.ajax({
            url: materialMasterSearchUrl,
            method: 'GET',
            dataType: 'json',
            data: { q: term },
            global: false,
            timeout: 8000,
        });
        materialSearchRequests.set(row, request);
        request
            .done((payload) => renderMaterialSearchResults($row, payload.results || []))
            .fail((xhr, status) => {
                if (status !== 'abort') {
                    renderMaterialSearchResults($row, []);
                }
            })
            .always(() => {
                if (materialSearchRequests.get(row) === request) {
                    materialSearchRequests.delete(row);
                }
            });
    }, 250));
}

function materialPreviewPayload($row) {
    const hsDisplay = String($row.find('.hs-code-display').val() || '').trim();
    const isExact8 = /^\d{8}$/.test(hsDisplay.replace(/\./g, ''));
    const hsOverrideVal = $row.find('.hs-code-manual-override').val();
    const hsManualOverride = (hsOverrideVal === '1' && isExact8) ? '1' : '0';

    const payload = {
        _token: @json(csrf_token()),
        material_master_id: $row.find('.material-master-id').val() || '',
        material_name: $row.find('.material-master-search').val() || '',
        shape: $row.find('.material-shape-select').val(),
        quantity: $row.find('.material-quantity').val() || 1,
        hs_code: isExact8 ? hsDisplay : '',
        hs_code_manual_override: hsManualOverride,
        weight_needed: $row.find('.weight-unit-display').val(),
        weight_manual_override: $row.find('.weight-manual-override').val() || 0,
    };
    fixedDimensionOrder.forEach((field) => {
        payload[field] = $row
            .find(`.dimension-input[data-dimension-field="${field}"]`)
            .val() || '';
    });

    return payload;
}

function renderMaterialPreview($row, payload) {
    const hs = payload.hs_code || {};
    const weight = payload.weight || {};
    const selectedCode = hs.selected_code || hs.code || '';
    const isManual = hs.source === 'manual';
    const labelMap = {
        matched: 'Auto matched',
        ambiguous: 'Ambiguous',
        no_rule: 'No rule',
        unmapped_material: 'Unmapped material',
        insufficient_data: 'Needs more data'
    };

    $row.find('.hs-code-display').val(selectedCode);
    $row.find('.hs-status-badge')
        .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error ui-status-chip--neutral')
        .addClass(isManual ? 'ui-status-chip--warning' : (hs.status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral'))
        .text(isManual ? 'Manual selection' : (labelMap[hs.status] || hs.status || 'Needs more data'));

    $row.find('.hs-code-manual-override').val(isManual ? '1' : '0');

    const unitKg = Number(weight.unit_kg || 0);
    $row.find('.weight-unit-display').val(unitKg.toFixed(4));
    $row.find('.weight-manual-override').val(weight.status === 'manual' ? '1' : '0');
}

function requestMaterialPreview($row) {
    const row = $row[0];
    const matId = $row.find('.material-master-id').val();
    const matName = String($row.find('.material-master-search').val() || '').trim();
    if (!matId && !matName) {
        resetMaterialPreview($row);
        return;
    }

    const previous = materialPreviewRequests.get(row);
    if (previous) {
        previous.abort();
    }

    const request = $.ajax({
        url: materialCalculationPreviewUrl,
        method: 'POST',
        data: materialPreviewPayload($row),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        global: false,
        timeout: 10000,
    });
    materialPreviewRequests.set(row, request);

    request.done((payload) => {
        if (payload.material && payload.material.id) {
            $row.find('.material-master-id').val(payload.material.id);
        }
        renderMaterialPreview($row, payload);
    })
        .fail((xhr, status) => {
            if (status === 'abort') return;
            const response = xhr.responseJSON || {};
            const hasErrors = Object.keys(response.errors || {}).length > 0;
            if (response.hs_code && response.weight && !hasErrors) {
                renderMaterialPreview($row, xhr.responseJSON);
            } else if (!hasErrors) {
                $row.find('.hs-code-display').val('');
                $row.find('.weight-unit-display').val('0.0000');
            }
            $row.find('.hs-status-badge')
                .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--neutral')
                .addClass('ui-status-chip--error')
                .text('Invalid data');
        })
        .always(() => {
            if (materialPreviewRequests.get(row) === request) {
                materialPreviewRequests.delete(row);
            }
        });
}

function scheduleMaterialPreview($row, delay = 300) {
    const row = $row[0];
    clearTimeout(materialPreviewTimers.get(row));
    materialPreviewTimers.set(row, setTimeout(() => requestMaterialPreview($row), delay));
}

function initializeMaterialShapeRows() {
    $('#itemsBody tr.item-row').each(function() {
        const $row = $(this);
        $row.data('selected-material-name', $row.find('.material-master-search').val());
        applyMaterialShapeRules(this, true);
        const hasMatId = !!$row.find('.material-master-id').val();
        const hasMatName = !!String($row.find('.material-master-search').val() || '').trim();
        const hasExistingHs = !!$row.find('.hs-code-display').val();
        const hasExistingWeight = Number($row.find('.weight-unit-display').val() || 0) > 0;

        if (hasMatId || hasMatName) {
            scheduleMaterialPreview($row, 0);
        } else if (!hasExistingHs && !hasExistingWeight) {
            resetMaterialPreview($row);
        }
    });
    renumberPrRows();
}

// Remark popover event handlers
$(document).on('click', '[data-remark-trigger]', function(e) {
    e.stopPropagation();
    const $cell = $(this).closest('td');
    const $popover = $cell.find('[data-remark-popover]');
    const isVisible = !$popover.prop('hidden');

    $('[data-remark-popover]').prop('hidden', true);
    $('[data-remark-trigger]').attr('aria-expanded', 'false');

    if (!isVisible) {
        const currentVal = $cell.find('.pr-item-remark').val() || '';
        $cell.find('.pr-remark-draft').val(currentVal);

        const matName = $cell.closest('tr').find('.material-master-search').val() || 'Material';
        $cell.find('.pr-remark-material-name').text(matName);

        const rect = this.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        if (spaceBelow < 240 && rect.top > 240) {
            $popover.css({ top: 'auto', bottom: 'calc(100% + 4px)', right: '0', left: 'auto' });
        } else {
            $popover.css({ top: 'calc(100% + 4px)', bottom: 'auto', right: '0', left: 'auto' });
        }

        $popover.prop('hidden', false);
        $(this).attr('aria-expanded', 'true');
        $cell.find('.pr-remark-draft').focus();
    }
});

$(document).on('click', '[data-remark-save]', function(e) {
    e.stopPropagation();
    const $cell = $(this).closest('td');
    const $popover = $cell.find('[data-remark-popover]');
    const draftVal = $cell.find('.pr-remark-draft').val().trim();
    const $realInput = $cell.find('.pr-item-remark');
    const $trigger = $cell.find('[data-remark-trigger]');
    const $triggerText = $trigger.find('.pr-remark-trigger__text');

    $realInput.val(draftVal).trigger('input').trigger('change');

    if (draftVal) {
        $triggerText.text(draftVal);
        $trigger.addClass('has-remark').attr('title', draftVal);
        if (!$trigger.find('.pr-remark-trigger__badge').length) {
            $trigger.append('<span class="pr-remark-trigger__badge" title="Remark entered" aria-hidden="true"></span>');
        }
    } else {
        $triggerText.text('Add remark...');
        $trigger.removeClass('has-remark').attr('title', 'Click to add remark');
        $trigger.find('.pr-remark-trigger__badge').remove();
    }

    $popover.prop('hidden', true);
    $trigger.attr('aria-expanded', 'false');
});

$(document).on('click', '[data-remark-cancel]', function(e) {
    e.stopPropagation();
    const $cell = $(this).closest('td');
    $cell.find('[data-remark-popover]').prop('hidden', true);
    $cell.find('[data-remark-trigger]').attr('aria-expanded', 'false').focus();
});

$(document).on('change input', '.pr-item-remark', function() {
    const val = $(this).val().trim();
    const $cell = $(this).closest('td');
    const $trigger = $cell.find('[data-remark-trigger]');
    const $triggerText = $trigger.find('.pr-remark-trigger__text');
    if (val) {
        $triggerText.text(val);
        $trigger.addClass('has-remark').attr('title', val);
        if (!$trigger.find('.pr-remark-trigger__badge').length) {
            $trigger.append('<span class="pr-remark-trigger__badge" title="Remark entered" aria-hidden="true"></span>');
        }
    } else {
        $triggerText.text('Add remark...');
        $trigger.removeClass('has-remark').attr('title', 'Click to add remark');
        $trigger.find('.pr-remark-trigger__badge').remove();
    }
});

$(document).on('click', function(e) {
    if (!$(e.target).closest('[data-remark-popover], [data-remark-trigger]').length) {
        $('[data-remark-popover]').prop('hidden', true);
        $('[data-remark-trigger]').attr('aria-expanded', 'false');
    }
});

$(document).on('keydown', function(e) {
    if (e.key === 'Escape') {
        $('[data-remark-popover]').prop('hidden', true);
        $('[data-remark-trigger]').attr('aria-expanded', 'false');
    }
});

$(document).on('focus input', '.material-master-search', function() {
    const $input = $(this);
    const $row = $input.closest('tr');
    const selectedName = String($row.data('selected-material-name') || '').trim();
    if ($row.find('.material-master-id').val() && selectedName && selectedName !== String($input.val()).trim()) {
        $row.find('.material-master-id').val('');
        resetMaterialPreview($row);
    }
    searchMaterial($input);
});

$(document).on('click', '.material-search-results button', function() {
    const $row = $(this).closest('tr');
    const material = $(this).data('material');
    $row.data('selected-material-name', material.material_code);
    selectMaterialInRow($row, material);
});

$(document).on('change', '.material-shape-select', function() {
    const $row = $(this).closest('tr');
    applyMaterialShapeRules($row, true);
    resetManualWeightOverride($row);
    scheduleMaterialPreview($row);
});

function resetManualWeightOverride($row) {
    $row.find('.weight-manual-override').val('0');
    $row.find('.weight-unit-display').val('');
}

$(document).on('input change', '.hs-code-display', function() {
    const $row = $(this).closest('tr');
    $row.find('.hs-code-manual-override').val('1');
    scheduleMaterialPreview($row);
});

$(document).on('input change', '.weight-unit-display', function() {
    $(this).closest('tr').find('.weight-manual-override').val('1');
});

$(document).on('input change', '.dimension-input', function() {
    const $row = $(this).closest('tr');
    resetManualWeightOverride($row);
    scheduleMaterialPreview($row);
});

$(document).on('input change', '.material-quantity', function() {
    scheduleMaterialPreview($(this).closest('tr'));
});

$(document).on('click', function(event) {
    if (!$(event.target).closest('.material-master-search, .material-search-results').length) {
        $('.material-search-results').each(function() {
            const $panel = $(this);
            $panel.addClass('d-none');
            setMaterialSearchOpen($panel.closest('tr'), false);
        });
    }
});

$(document).on('keydown', '.material-master-search', function(event) {
    if (event.key === 'Escape') {
        const $row = $(this).closest('tr');
        $row.find('.material-search-results').addClass('d-none');
        setMaterialSearchOpen($row, false);
    }
});

$(window).on('resize', repositionVisibleMaterialSearchResults);
document.addEventListener('scroll', repositionVisibleMaterialSearchResults, true);
