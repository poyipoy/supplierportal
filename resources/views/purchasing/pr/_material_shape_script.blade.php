const materialDimensionFields = @json(\App\Models\PrItem::RELEVANT_DIMENSIONS);
const fixedDimensionOrder = @json(\App\Models\PrItem::FIXED_DIMENSION_ORDER);
const materialMasterSearchUrl = @json(route('purchasing.material-masters.search'));
const materialCalculationPreviewUrl = @json(route('purchasing.material-calculations.preview'));
const materialPreviewTimers = new WeakMap();
const materialPreviewRequests = new WeakMap();
const materialSearchTimers = new WeakMap();
const materialSearchRequests = new WeakMap();

// Programmatic .val() writes fire no input event; the totals footer listens for this (PUR-02).
function notifyPrItemsChanged() {
    document.dispatchEvent(new CustomEvent('adasi:pr-items-changed'));
}

function renumberPrRows() {
    $('#itemsBody tr.item-row').each(function(index) {
        $(this).find('[data-pr-row-number]').text(index + 1);
    });
    notifyPrItemsChanged();
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
    $row.find('.hs-status-badge').removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error').addClass('ui-status-chip--neutral').text(@json(__('purchasing.copy.needs_more_data')));
    $row.find('.material-grade-display').val('').attr('title', '');
    $row.find('.weight-unit-display').val('0.0000');
    $row.find('.weight-manual-override').val('0');
    notifyPrItemsChanged();
}

function selectMaterialInRow($row, material) {
    $row.find('.material-master-id').val(material.id);
    $row.find('.material-master-search').val(material.material_code || material.text || '');
    $row.find('.material-search-results').empty().addClass('d-none');
    setMaterialSearchOpen($row, false);
    $row.find('.hs-code-manual-override').val('0');
    $row.find('.weight-manual-override').val('0');
    const initialGrade = material.category_label || material.category || '';
    $row.find('.material-grade-display').val(initialGrade).attr('title', initialGrade);
    $row.find('.pr-remark-material-name').text(material.material_code || material.text || @json(__('purchasing.copy.material')));
    scheduleMaterialPreview($row, 0);
}

function renderMaterialSearchResults($row, results) {
    const $panel = $row.find('.material-search-results').empty();
    if (!Array.isArray(results) || results.length === 0) {
        $('<div class="list-group-item small text-muted">{{ __('purchasing.copy.no_active_master_material_found') }}</div>').appendTo($panel);
    } else {
        results.forEach((material) => {
            const category = material.category_label || material.category || 'unmapped';
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
        matched: @json(__('purchasing.copy.auto_matched')),
        ambiguous: @json(__('purchasing.copy.ambiguous')),
        no_rule: @json(__('purchasing.copy.no_rule')),
        unmapped_material: @json(__('purchasing.copy.unmapped_material')),
        insufficient_data: @json(__('purchasing.copy.needs_more_data'))
    };

    $row.find('.hs-code-display').val(selectedCode);
    $row.find('.hs-status-badge')
        .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error ui-status-chip--neutral')
        .addClass(isManual ? 'ui-status-chip--warning' : (hs.status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral'))
        .text(isManual ? @json(__('purchasing.copy.manual_selection')) : (labelMap[hs.status] || hs.status || @json(__('purchasing.copy.needs_more_data'))));

    $row.find('.hs-code-manual-override').val(isManual ? '1' : '0');
    const gradeLabel = hs.grade_label || (payload.material && payload.material.category_label) || '';
    $row.find('.material-grade-display').val(gradeLabel).attr('title', gradeLabel);

    const unitKg = Number(weight.unit_kg || 0);
    $row.find('.weight-unit-display').val(unitKg.toFixed(4));
    $row.find('.weight-manual-override').val(weight.status === 'manual' ? '1' : '0');
    notifyPrItemsChanged();
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
                notifyPrItemsChanged();
            }
            $row.find('.hs-status-badge')
                .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--neutral')
                .addClass('ui-status-chip--error')
                .text(@json(__('purchasing.copy.invalid_data')));
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

// Remark popover event handlers & positioning
function positionRemarkPopover($popover, trigger) {
    if (!$popover || !$popover.length || $popover.prop('hidden')) return;

    const triggerEl = trigger || $popover.closest('td').find('[data-remark-trigger]')[0];
    if (!triggerEl) return;

    const rect = triggerEl.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) {
        $popover.prop('hidden', true);
        return;
    }

    // Hide if trigger is scrolled outside the table scroll container horizontally
    const $scrollContainer = $popover.closest('.pr-form-table-scroll');
    if ($scrollContainer.length) {
        const containerRect = $scrollContainer[0].getBoundingClientRect();
        if (rect.right < containerRect.left + 10 || rect.left > containerRect.right - 10) {
            $popover.prop('hidden', true);
            $(triggerEl).attr('aria-expanded', 'false');
            return;
        }
    }

    // Hide if trigger is scrolled outside window viewport vertically
    if (rect.bottom < 0 || rect.top > window.innerHeight) {
        $popover.prop('hidden', true);
        $(triggerEl).attr('aria-expanded', 'false');
        return;
    }

    const viewportPadding = 8;
    const gap = 4;
    const popoverWidth = Math.min(320, Math.max(280, window.innerWidth - (viewportPadding * 2)));
    const popoverHeight = Math.min($popover.outerHeight() || $popover[0].scrollHeight || 230, 280);

    const fitsBelow = rect.bottom + gap + popoverHeight <= window.innerHeight - viewportPadding;
    const fitsAbove = rect.top - gap - popoverHeight >= viewportPadding;
    const opensAbove = !fitsBelow && fitsAbove;

    let top;
    if (opensAbove) {
        top = Math.max(viewportPadding, rect.top - gap - popoverHeight);
    } else {
        top = Math.min(window.innerHeight - popoverHeight - viewportPadding, rect.bottom + gap);
    }

    let left = rect.right - popoverWidth;
    if (left < viewportPadding) {
        left = Math.max(viewportPadding, rect.left);
    }
    if (left + popoverWidth > window.innerWidth - viewportPadding) {
        left = Math.max(viewportPadding, window.innerWidth - popoverWidth - viewportPadding);
    }

    $popover.css({
        position: 'fixed',
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        bottom: 'auto',
        right: 'auto',
        width: `${popoverWidth}px`,
        zIndex: 1080,
    });
}

function repositionVisibleRemarkPopovers() {
    $('[data-remark-popover]:not([hidden])').each(function() {
        const $popover = $(this);
        const $trigger = $popover.closest('td').find('[data-remark-trigger]');
        if ($trigger.length) {
            positionRemarkPopover($popover, $trigger[0]);
        }
    });
}

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

        const matName = $cell.closest('tr').find('.material-master-search').val() || @json(__('purchasing.copy.material'));
        $cell.find('.pr-remark-material-name').text(matName);

        $popover.prop('hidden', false);
        $(this).attr('aria-expanded', 'true');
        positionRemarkPopover($popover, this);
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
            $trigger.append('<span class="pr-remark-trigger__badge" title="{{ __('purchasing.copy.remark_entered') }}" aria-hidden="true"></span>');
        }
    } else {
        $triggerText.text(@json(__('purchasing.copy.add_remark')));
        $trigger.removeClass('has-remark').attr('title', @json(__('purchasing.copy.click_to_add_remark')));
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
            $trigger.append('<span class="pr-remark-trigger__badge" title="{{ __('purchasing.copy.remark_entered') }}" aria-hidden="true"></span>');
        }
    } else {
        $triggerText.text(@json(__('purchasing.copy.add_remark')));
        $trigger.removeClass('has-remark').attr('title', @json(__('purchasing.copy.click_to_add_remark')));
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
    notifyPrItemsChanged();
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

function handleWindowResize() {
    repositionVisibleMaterialSearchResults();
    repositionVisibleRemarkPopovers();
}

function handleDocumentScroll() {
    repositionVisibleMaterialSearchResults();
    repositionVisibleRemarkPopovers();
}

$(window).on('resize', handleWindowResize);
document.addEventListener('scroll', handleDocumentScroll, true);

// ==========================================
// Mobile Material Item Editor & Cards Sync
// ==========================================

function escapePrHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatMobileDimensions(shape, $row) {
    if (!shape) return '—';
    const fields = materialDimensionFields[shape] || [];
    const parts = [];
    const abbrevMap = {
        thickness: 'T',
        width: 'W',
        length: 'L',
        d_outer: 'OD',
        d_inner: 'ID',
    };
    fields.forEach((f) => {
        const val = $row.find(`.dimension-input[data-dimension-field="${f}"]`).val();
        if (val && Number(val) > 0) {
            parts.push(`${abbrevMap[f] || f}: ${val}`);
        }
    });
    return parts.length ? `${parts.join(' × ')} mm` : '—';
}

function renderMobileMaterialCards() {
    const $container = $('#prMobileCardsList');
    if (!$container.length) return;

    $container.empty();
    const $rows = $('#itemsBody tr.item-row');
    const totalCount = $rows.length;

    $('#prMobileItemCount').text(totalCount);

    if (totalCount === 0) {
        $('#prMobileEmptyState').removeClass('d-none');
        return;
    }

    $('#prMobileEmptyState').addClass('d-none');

    $rows.each(function(index) {
        const $row = $(this);
        const matName = String($row.find('.material-master-search').val() || '').trim();
        const grade = String($row.find('.material-grade-display').val() || '').trim();
        const shape = String($row.find('.material-shape-select').val() || '').trim();
        const qty = parseInt($row.find('.material-quantity').val(), 10) || 1;
        const unitKg = Number($row.find('.weight-unit-display').val() || 0);
        const rowTotalKg = String($row.find('[data-pr-row-total-kg]').text() || '').trim() || '—';
        const hsCode = String($row.find('.hs-code-display').val() || '').trim();
        const remark = String($row.find('.pr-item-remark').val() || '').trim();
        const dimSummary = formatMobileDimensions(shape, $row);

        const isUnconfigured = !matName && !$row.find('.material-master-id').val();
        const displayName = isUnconfigured ? '— (Belum diisi)' : matName;

        const card = `
            <div class="tw:p-3.5 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-xs" data-mobile-row-card="${index}">
                <div class="tw:flex tw:items-start tw:justify-between tw:gap-2 tw:mb-2">
                    <div class="tw:min-w-0">
                        <div class="tw:flex tw:items-center tw:gap-1.5 tw:mb-0.5">
                            <span class="tw:inline-flex tw:items-center tw:justify-center tw:w-5 tw:h-5 tw:rounded-full tw:bg-surface-container-high tw:text-on-surface tw:text-[11px] tw:font-bold ui-tabular-nums">
                                ${index + 1}
                            </span>
                            <span class="tw:text-ui-sm tw:font-bold tw:text-on-surface tw:truncate ${isUnconfigured ? 'tw:italic tw:text-on-surface-variant' : ''}">
                                ${escapePrHtml(displayName)}
                            </span>
                        </div>
                        <div class="tw:text-[11px] tw:text-on-surface-variant tw:flex tw:items-center tw:gap-1.5">
                            ${grade ? `<span>${escapePrHtml(grade)}</span>` : ''}
                            ${shape ? `<span>&bull; ${escapePrHtml(shape)}</span>` : ''}
                            ${isUnconfigured ? `<span class="tw:text-warning-dark tw:font-semibold">&bull; Belum Lengkap</span>` : ''}
                        </div>
                    </div>
                    <div class="tw:text-right tw:shrink-0">
                        <span class="tw:inline-flex tw:items-center tw:px-2 tw:py-0.5 tw:rounded-ui-xs tw:text-ui-xs tw:font-bold tw:bg-surface-container tw:text-on-surface">
                            ${qty} Unit
                        </span>
                    </div>
                </div>

                <div class="tw:grid tw:grid-cols-2 tw:gap-2 tw:py-2 tw:my-2 tw:border-y tw:border-outline-variant tw:text-ui-xs">
                    <div>
                        <div class="tw:text-on-surface-variant tw:text-[11px]">Dimensi</div>
                        <div class="tw:font-medium tw:text-on-surface tw:truncate">${dimSummary}</div>
                    </div>
                    <div>
                        <div class="tw:text-on-surface-variant tw:text-[11px]">Total Bobot</div>
                        <div class="tw:font-bold tw:text-on-surface ui-tabular-nums">${rowTotalKg}</div>
                    </div>
                    <div>
                        <div class="tw:text-on-surface-variant tw:text-[11px]">HS Code</div>
                        <div class="tw:font-mono tw:text-on-surface tw:truncate">${escapePrHtml(hsCode || '—')}</div>
                    </div>
                    <div>
                        <div class="tw:text-on-surface-variant tw:text-[11px]">Bobot Satuan</div>
                        <div class="tw:font-medium tw:text-on-surface ui-tabular-nums">${unitKg.toFixed(4)} kg</div>
                    </div>
                </div>

                ${remark ? `
                    <div class="tw:text-ui-xs tw:text-on-surface-variant tw:bg-surface-container-low tw:p-2 tw:rounded-ui-xs tw:mb-2.5 tw:flex tw:items-start tw:gap-1.5">
                        <span class="tw:font-semibold tw:shrink-0">Catatan:</span>
                        <span class="tw:truncate">${escapePrHtml(remark)}</span>
                    </div>
                ` : ''}

                <div class="tw:flex tw:items-center tw:justify-end tw:gap-2 tw:pt-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary tw:py-1 tw:px-3 tw:text-ui-xs tw:rounded-ui-xs pr-mobile-edit-btn" data-row-index="${index}">
                        <svg class="tw:inline-block tw:align-middle me-1" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger tw:py-1 tw:px-2.5 tw:text-ui-xs tw:rounded-ui-xs pr-mobile-delete-btn" data-row-index="${index}">
                        <svg class="tw:inline-block tw:align-middle me-1" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        Hapus
                    </button>
                </div>
            </div>
        `;
        $container.append(card);
    });
}

function applyMobileModalShapeRules() {
    const shape = $('#mmShape').val();
    const relevant = materialDimensionFields[shape] || [];

    $('.mm-dim-container').each(function() {
        const field = $(this).data('dim-field');
        const isRel = relevant.includes(field);
        if (isRel) {
            $(this).show();
        } else {
            $(this).hide();
            $(this).find('.mm-dim-input').val('');
        }
    });
}

let mmPreviewTimer = null;
let mmPreviewRequest = null;

function requestMobileModalPreview() {
    const matId = $('#mmMaterialMasterId').val();
    const matName = $('#mmMaterialSearch').val().trim();
    if (!matId && !matName) {
        $('#mmWeightNeeded').val('0.0000');
        $('#mmTotalWeightDisplay').text('0.0000 kg');
        return;
    }

    if (mmPreviewRequest) {
        mmPreviewRequest.abort();
    }

    const hsDisplay = $('#mmHsCode').val().trim();
    const isExact8 = /^\d{8}$/.test(hsDisplay.replace(/\./g, ''));
    const hsManual = $('#mmHsCodeManualOverride').val() === '1' && isExact8 ? '1' : '0';

    const payload = {
        _token: @json(csrf_token()),
        material_master_id: matId,
        material_name: matName,
        shape: $('#mmShape').val(),
        quantity: $('#mmQuantity').val() || 1,
        hs_code: isExact8 ? hsDisplay : '',
        hs_code_manual_override: hsManual,
        weight_needed: $('#mmWeightNeeded').val(),
        weight_manual_override: $('#mmWeightManualOverride').val() || 0,
    };

    fixedDimensionOrder.forEach((field) => {
        payload[field] = $(`#mm_dim_${field}`).val() || '';
    });

    mmPreviewRequest = $.ajax({
        url: materialCalculationPreviewUrl,
        method: 'POST',
        data: payload,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        global: false,
        timeout: 10000,
    });

    mmPreviewRequest.done((res) => {
        if (res.material && res.material.id) {
            $('#mmMaterialMasterId').val(res.material.id);
        }
        const hs = res.hs_code || {};
        const weight = res.weight || {};
        const isManual = hs.source === 'manual';

        if (hs.selected_code || hs.code) {
            $('#mmHsCode').val(hs.selected_code || hs.code);
        }

        const labelMap = {
            matched: @json(__('purchasing.copy.auto_matched')),
            ambiguous: @json(__('purchasing.copy.ambiguous')),
            no_rule: @json(__('purchasing.copy.no_rule')),
            unmapped_material: @json(__('purchasing.copy.unmapped_material')),
            insufficient_data: @json(__('purchasing.copy.needs_more_data'))
        };

        $('#mmHsBadge')
            .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error ui-status-chip--neutral')
            .addClass(isManual ? 'ui-status-chip--warning' : (hs.status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral'))
            .text(isManual ? @json(__('purchasing.copy.manual_selection')) : (labelMap[hs.status] || hs.status || @json(__('purchasing.copy.needs_more_data'))));

        const unitKg = Number(weight.unit_kg || 0);
        $('#mmWeightNeeded').val(unitKg.toFixed(4));
        const qty = Number($('#mmQuantity').val() || 1);
        const totalKg = (unitKg * qty).toFixed(4);
        $('#mmTotalWeightDisplay').text(`${totalKg} kg`);
    });
}

function scheduleMobileModalPreview(delay = 300) {
    clearTimeout(mmPreviewTimer);
    mmPreviewTimer = setTimeout(requestMobileModalPreview, delay);
}

let mmSearchTimer = null;
let mmSearchRequest = null;

function searchMobileMaterial(term) {
    clearTimeout(mmSearchTimer);
    mmSearchTimer = setTimeout(() => {
        if (mmSearchRequest) mmSearchRequest.abort();

        const $results = $('#mmMaterialResults').empty();
        if (!term || term.length < 1) {
            $results.addClass('d-none');
            return;
        }

        mmSearchRequest = $.ajax({
            url: materialMasterSearchUrl,
            method: 'GET',
            dataType: 'json',
            data: { q: term },
            global: false,
            timeout: 8000,
        });

        mmSearchRequest.done((payload) => {
            const results = payload.results || [];
            $results.empty();
            if (!results.length) {
                $('<div class="list-group-item small text-muted p-3">')
                    .text(@json(__('purchasing.copy.no_active_master_material_found')))
                    .appendTo($results);
            } else {
                results.forEach((mat) => {
                    const category = mat.category_label || mat.category || 'unmapped';
                    $('<button type="button" class="list-group-item list-group-item-action py-2.5 px-3">')
                        .append($('<div class="fw-bold tw:text-ui-xs tw:text-on-surface">').text(mat.material_code))
                        .append($('<div class="small text-muted">').text(`${category} | ${mat.density_profile || ''}`))
                        .data('material', mat)
                        .appendTo($results);
                });
            }
            $results.removeClass('d-none');
        }).fail((xhr, status) => {
            if (status !== 'abort') {
                $results.addClass('d-none');
            }
        });
    }, 250);
}

// Autocomplete input in modal
$(document).on('focus input', '#mmMaterialSearch', function() {
    searchMobileMaterial($(this).val().trim());
});

// Autocomplete selection
$(document).on('click', '#mmMaterialResults button', function() {
    const mat = $(this).data('material');
    if (!mat) return;

    $('#mmMaterialMasterId').val(mat.id);
    $('#mmMaterialSearch').val(mat.material_code || mat.text || '');
    $('#mmBtnClearMaterial').removeClass('d-none');
    $('#mmMaterialResults').addClass('d-none').empty();

    $('#mmSelectedName').text(mat.material_code || mat.text || '');
    $('#mmSelectedGrade').text(mat.category_label || mat.category || '');
    $('#mmSelectedFeedback').removeClass('d-none');

    if (mat.default_shape && !$('#mmShape').val()) {
        $('#mmShape').val(mat.default_shape);
        applyMobileModalShapeRules();
    }

    scheduleMobileModalPreview(0);
});

// Clear material
$(document).on('click', '#mmBtnClearMaterial', function() {
    $('#mmMaterialMasterId').val('');
    $('#mmMaterialSearch').val('');
    $('#mmSelectedFeedback').addClass('d-none');
    $('#mmBtnClearMaterial').addClass('d-none');
    $('#mmHsCode').val('');
    $('#mmWeightNeeded').val('0.0000');
    $('#mmTotalWeightDisplay').text('0.0000 kg');
    $('#mmMaterialResults').addClass('d-none').empty();
});

// Hide results when clicking outside
$(document).on('click', function(e) {
    if (!$(e.target).closest('#mmMaterialSearch, #mmMaterialResults').length) {
        $('#mmMaterialResults').addClass('d-none');
    }
});

// Shape change in modal
$(document).on('change', '#mmShape', function() {
    applyMobileModalShapeRules();
    $('#mmWeightManualOverride').val('0');
    scheduleMobileModalPreview();
});

// Dimensions & Qty change in modal
$(document).on('input change', '.mm-dim-input', function() {
    $('#mmWeightManualOverride').val('0');
    scheduleMobileModalPreview();
});

$(document).on('input change', '#mmQuantity', function() {
    scheduleMobileModalPreview();
});

// Weight manual edit in modal
$(document).on('input change', '#mmWeightNeeded', function() {
    $('#mmWeightManualOverride').val('1');
    const unitKg = Number($(this).val() || 0);
    const qty = Number($('#mmQuantity').val() || 1);
    $('#mmTotalWeightDisplay').text(`${(unitKg * qty).toFixed(4)} kg`);
});

// HS Code manual edit in modal
$(document).on('input change', '#mmHsCode', function() {
    $('#mmHsCodeManualOverride').val('1');
    scheduleMobileModalPreview();
});

// Open modal in Add mode
$(document).on('click', '#btnOpenMobileMaterialModal', function() {
    $('#mobileMaterialModalTitle').text(@json(__('purchasing.copy.add_material')));
    $('#mmEditingIndex').val('-1');
    $('#mmMaterialMasterId').val('');
    $('#mmMaterialSearch').val('');
    $('#mmBtnClearMaterial').addClass('d-none');
    $('#mmSelectedFeedback').addClass('d-none');
    $('#mmMaterialResults').addClass('d-none').empty();
    $('#mmShape').val('');
    applyMobileModalShapeRules();
    $('.mm-dim-input').val('');
    $('#mmQuantity').val('1');
    $('#mmWeightNeeded').val('0.0000');
    $('#mmTotalWeightDisplay').text('0.0000 kg');
    $('#mmHsCode').val('');
    $('#mmHsBadge').removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error').addClass('ui-status-chip--neutral').text('Otomatis');
    $('#mmHsCodeManualOverride').val('0');
    $('#mmWeightManualOverride').val('0');
    $('#mmRemark').val('');

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mobileMaterialModal'));
    modal.show();
});

// Open modal in Edit mode from card
$(document).on('click', '.pr-mobile-edit-btn', function() {
    const idx = parseInt($(this).data('row-index'), 10);
    const $row = $('#itemsBody tr.item-row').eq(idx);
    if (!$row.length) return;

    $('#mobileMaterialModalTitle').text(`Edit Material #${idx + 1}`);
    $('#mmEditingIndex').val(idx);

    const matId = $row.find('.material-master-id').val() || '';
    const matName = $row.find('.material-master-search').val() || '';
    const grade = $row.find('.material-grade-display').val() || '';

    $('#mmMaterialMasterId').val(matId);
    $('#mmMaterialSearch').val(matName);

    if (matName) {
        $('#mmBtnClearMaterial').removeClass('d-none');
        $('#mmSelectedName').text(matName);
        $('#mmSelectedGrade').text(grade || '—');
        $('#mmSelectedFeedback').removeClass('d-none');
    } else {
        $('#mmBtnClearMaterial').addClass('d-none');
        $('#mmSelectedFeedback').addClass('d-none');
    }

    $('#mmMaterialResults').addClass('d-none').empty();

    const shape = $row.find('.material-shape-select').val() || '';
    $('#mmShape').val(shape);
    applyMobileModalShapeRules();

    fixedDimensionOrder.forEach((f) => {
        const val = $row.find(`.dimension-input[data-dimension-field="${f}"]`).val() || '';
        $(`#mm_dim_${f}`).val(val);
    });

    const qty = $row.find('.material-quantity').val() || '1';
    $('#mmQuantity').val(qty);

    const unitKg = Number($row.find('.weight-unit-display').val() || 0);
    $('#mmWeightNeeded').val(unitKg.toFixed(4));
    const totalKg = (unitKg * Number(qty)).toFixed(4);
    $('#mmTotalWeightDisplay').text(`${totalKg} kg`);

    const hsCode = $row.find('.hs-code-display').val() || '';
    $('#mmHsCode').val(hsCode);

    const hsBadgeClass = $row.find('.hs-status-badge').attr('class') || 'ui-status-chip ui-status-chip--neutral';
    const hsBadgeText = $row.find('.hs-status-badge').text().trim() || 'Otomatis';
    $('#mmHsBadge').attr('class', hsBadgeClass).text(hsBadgeText);

    $('#mmHsCodeManualOverride').val($row.find('.hs-code-manual-override').val() || '0');
    $('#mmWeightManualOverride').val($row.find('.weight-manual-override').val() || '0');
    $('#mmRemark').val($row.find('.pr-item-remark').val() || '');

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mobileMaterialModal'));
    modal.show();
});

// Delete row from card
$(document).on('click', '.pr-mobile-delete-btn', function() {
    const idx = parseInt($(this).data('row-index'), 10);
    const $row = $('#itemsBody tr.item-row').eq(idx);
    if (!$row.length) return;

    AdasiAlert.confirmDanger({
        title: @json(__('purchasing.copy.delete_this_row')),
        confirmText: @json(__('purchasing.audit_ui.confirm_delete')),
        cancelText: @json(__('purchasing.copy.cancel'))
    }).then((result) => {
        if (result.isConfirmed) {
            $row.remove();
            renumberPrRows();
            checkRowCount();
            renderMobileMaterialCards();
        }
    });
});

// Save from Modal
$(document).on('click', '#btnSaveMobileMaterialItem', function() {
    const matName = $('#mmMaterialSearch').val().trim();
    if (!matName) {
        AdasiAlert.error({
            title: 'Material Diperlukan',
            text: 'Silakan pilih atau masukkan nama material sebelum menyimpan.'
        });
        $('#mmMaterialSearch').focus();
        return;
    }

    const shape = $('#mmShape').val();
    if (!shape) {
        AdasiAlert.error({
            title: 'Bentuk Material Diperlukan',
            text: 'Silakan pilih bentuk material (Shape).'
        });
        $('#mmShape').focus();
        return;
    }

    const qty = parseInt($('#mmQuantity').val(), 10) || 1;
    if (qty < 1) {
        AdasiAlert.error({
            title: 'Jumlah Tidak Valid',
            text: 'Quantity minimal 1.'
        });
        $('#mmQuantity').focus();
        return;
    }

    const editIdx = parseInt($('#mmEditingIndex').val(), 10);
    let $targetRow;

    if (editIdx >= 0) {
        $targetRow = $('#itemsBody tr.item-row').eq(editIdx);
    } else {
        // Reuse first row if it's completely blank
        const $firstRow = $('#itemsBody tr.item-row').first();
        if ($('#itemsBody tr.item-row').length === 1 &&
            !$firstRow.find('.material-master-search').val().trim() &&
            !$firstRow.find('.material-master-id').val().trim()) {
            $targetRow = $firstRow;
        } else {
            addRow();
            $targetRow = $('#itemsBody tr.item-row').last();
        }
    }

    // Write values to row
    $targetRow.find('.material-master-id').val($('#mmMaterialMasterId').val());
    $targetRow.find('.material-master-search').val(matName);
    $targetRow.data('selected-material-name', matName);
    $targetRow.find('.material-shape-select').val(shape);
    applyMaterialShapeRules($targetRow, false);

    fixedDimensionOrder.forEach((f) => {
        const val = $(`#mm_dim_${f}`).val();
        $targetRow.find(`.dimension-input[data-dimension-field="${f}"]`).val(val || '');
    });

    $targetRow.find('.material-quantity').val(qty);
    $targetRow.find('.weight-unit-display').val($('#mmWeightNeeded').val() || '0.0000');
    $targetRow.find('.weight-manual-override').val($('#mmWeightManualOverride').val() || '0');
    $targetRow.find('.hs-code-display').val($('#mmHsCode').val() || '');
    $targetRow.find('.hs-code-manual-override').val($('#mmHsCodeManualOverride').val() || '0');

    const hsBadgeClass = $('#mmHsBadge').attr('class');
    const hsBadgeText = $('#mmHsBadge').text();
    $targetRow.find('.hs-status-badge').attr('class', hsBadgeClass).text(hsBadgeText);

    const remark = $('#mmRemark').val().trim();
    $targetRow.find('.pr-item-remark').val(remark).trigger('input').trigger('change');

    scheduleMaterialPreview($targetRow, 0);
    renumberPrRows();
    checkRowCount();

    const modalEl = document.getElementById('mobileMaterialModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    renderMobileMaterialCards();
});

// Toggle full table view on mobile
$(document).on('click', '#btnTogglePrTableView', function() {
    const $container = $('#prTableContainer');
    const isHidden = $container.hasClass('d-none');
    if (isHidden) {
        $container.removeClass('d-none');
        $(this).attr('aria-expanded', 'true');
        $('#btnTogglePrTableLabel').text('Tutup Tabel');
    } else {
        $container.addClass('d-none');
        $(this).attr('aria-expanded', 'false');
        $('#btnTogglePrTableLabel').text('Lihat Tabel Penuh');
    }
});

// Listen for items changed event to synchronize cards
document.addEventListener('adasi:pr-items-changed', function() {
    renderMobileMaterialCards();
});


