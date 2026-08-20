const materialDimensionFields = {
    Flat: [
        { field: 'thickness', label: 'Thickness' },
        { field: 'width', label: 'Width' },
        { field: 'length', label: 'Length' },
    ],
    Round: [
        { field: 'd_outer', label: 'Outer Diameter' },
        { field: 'length', label: 'Length' },
    ],
    Hollow: [
        { field: 'd_inner', label: 'Inner Diameter' },
        { field: 'd_outer', label: 'Outer Diameter' },
        { field: 'length', label: 'Length' },
    ],
};

const allMaterialDimensions = ['thickness', 'd_inner', 'd_outer', 'width', 'length'];
const materialDimensionSlotCount = 3;
const materialMasterSearchUrl = @json(route('purchasing.material-masters.search'));
const materialCalculationPreviewUrl = @json(route('purchasing.material-calculations.preview'));
const materialPreviewTimers = new WeakMap();
const materialPreviewRequests = new WeakMap();
const materialSearchTimers = new WeakMap();
const materialSearchRequests = new WeakMap();

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

function canonicalDimensionInput($row, field) {
    return $row.find(`.dimension-canonical-input[data-dimension-canonical-field="${field}"]`);
}

function setMaterialSearchOpen($row, open) {
    $row.find('.pr-sticky-material').toggleClass('material-search-open', open);
}

function updateMaterialDimensionHeaders() {
    const rowShapes = $('#itemsBody tr.item-row')
        .map(function() {
            return $(this).find('.material-shape-select').val() || '';
        })
        .get();
    const shapes = [...new Set(rowShapes)];
    const sharedDimensions = shapes.length === 1 ? (materialDimensionFields[shapes[0]] || []) : [];

    for (let slot = 1; slot <= materialDimensionSlotCount; slot++) {
        const dimension = sharedDimensions[slot - 1];
        const label = dimension?.label || `Dimension ${slot}`;
        $(`[data-dimension-slot-header="${slot}"]`).text(`${label} (mm)`);
    }
}

function applyMaterialShapeRules(row, clearIrrelevant = true) {
    const $row = $(row);
    const shape = $row.find('.material-shape-select').val();
    const relevantDimensions = materialDimensionFields[shape] || [];
    const relevantFields = relevantDimensions.map((dimension) => dimension.field);

    allMaterialDimensions.forEach((field) => {
        const isRelevant = relevantFields.includes(field);
        const $canonicalInput = canonicalDimensionInput($row, field);

        if (!isRelevant && clearIrrelevant) {
            $canonicalInput.val('');
        }
    });

    for (let slotIndex = 0; slotIndex < materialDimensionSlotCount; slotIndex++) {
        const slot = slotIndex + 1;
        const dimension = relevantDimensions[slotIndex] || null;
        const field = dimension?.field || '';
        const label = dimension?.label || `Dimension ${slot}`;
        const value = field ? canonicalDimensionInput($row, field).val() || '' : '';
        const $cell = $row.find(`[data-dimension-slot-cell="${slot}"]`);
        const $input = $cell.find('.dimension-input');
        const $label = $cell.find(`[data-dimension-slot-label="${slot}"]`);

        $label.text(`${label}${field ? ' (mm)' : ''}`);
        $input
            .val(value)
            .attr('data-dimension-field', field)
            .attr('aria-label', field ? `${label} in millimeters` : `Dimension ${slot} unavailable`)
            .prop('disabled', !field)
            .attr('aria-disabled', field ? 'false' : 'true');
        $cell
            .attr('data-dimension-field-cell', field)
            .toggleClass('is-disabled', !field);
    }

    updateMaterialDimensionHeaders();
}

function resetMaterialPreview($row) {
    $row.find('.hs-code-display').val('');
    $row.find('.hs-code-manual-override').val('0');
    $row.find('.hs-status-badge').removeClass('bg-success bg-warning text-dark bg-danger').addClass('bg-secondary').text('Needs more data');
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
    const payload = {
        _token: @json(csrf_token()),
        material_master_id: $row.find('.material-master-id').val(),
        shape: $row.find('.material-shape-select').val(),
        quantity: $row.find('.material-quantity').val() || 1,
        hs_code: $row.find('.hs-code-display').val(),
        hs_code_manual_override: $row.find('.hs-code-manual-override').val() || 0,
        weight_needed: $row.find('.weight-unit-display').val(),
        weight_manual_override: $row.find('.weight-manual-override').val() || 0,
    };
    allMaterialDimensions.forEach((field) => {
        payload[field] = canonicalDimensionInput($row, field).val() || '';
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
        .removeClass('bg-success bg-warning text-dark bg-danger bg-secondary')
        .addClass(isManual ? 'bg-warning text-dark' : (hs.status === 'matched' ? 'bg-success' : 'bg-secondary'))
        .text(isManual ? 'Manual selection' : (labelMap[hs.status] || hs.status || 'Needs more data'));

    $row.find('.hs-code-manual-override').val(isManual ? '1' : '0');

    const unitKg = Number(weight.unit_kg || 0);
    $row.find('.weight-unit-display').val(unitKg.toFixed(4));
    $row.find('.weight-manual-override').val(weight.status === 'manual' ? '1' : '0');
}

function requestMaterialPreview($row) {
    const row = $row[0];
    if (!$row.find('.material-master-id').val()) {
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

    request.done((payload) => renderMaterialPreview($row, payload))
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
                .removeClass('bg-success bg-warning text-dark bg-secondary')
                .addClass('bg-danger')
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
        $(this).data('selected-material-name', $(this).find('.material-master-search').val());
        applyMaterialShapeRules(this, true);
        if ($(this).find('.material-master-id').val()) {
            scheduleMaterialPreview($(this), 0);
        } else {
            resetMaterialPreview($(this));
        }
    });
    updateMaterialDimensionHeaders();
}

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
    const field = $(this).attr('data-dimension-field');
    if (field) {
        canonicalDimensionInput($row, field).val($(this).val());
    }
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
