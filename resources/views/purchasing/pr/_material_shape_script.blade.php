const materialDimensionFields = @json(\App\Models\PrItem::RELEVANT_DIMENSIONS);
const fixedDimensionOrder = @json(\App\Models\PrItem::FIXED_DIMENSION_ORDER);
const materialMasterSearchUrl = @json(route('purchasing.material-masters.search'));
const materialCalculationPreviewUrl = @json(route('purchasing.material-calculations.preview'));
const prCsrfToken = @json(csrf_token());
const materialPreviewTimers = new WeakMap();
const materialPreviewRequests = new WeakMap();
const materialSearchTimers = new WeakMap();
const materialSearchRequests = new WeakMap();
const materialOptionMap = new WeakMap();

const prPurchasingCopy = {
    needsMoreData: @json(__('purchasing.copy.needs_more_data')),
    manualSelection: @json(__('purchasing.copy.manual_selection')),
    invalidData: @json(__('purchasing.copy.invalid_data')),
    material: @json(__('purchasing.copy.material')),
    noActiveMasterMaterialFound: @json(__('purchasing.copy.no_active_master_material_found')),
    addRemark: @json(__('purchasing.copy.add_remark')),
    clickToAddRemark: @json(__('purchasing.copy.click_to_add_remark')),
    remarkEntered: @json(__('purchasing.copy.remark_entered')),
    addMaterial: @json(__('purchasing.copy.add_material')),
    deleteThisRow: @json(__('purchasing.copy.delete_this_row')),
    confirmDelete: @json(__('purchasing.audit_ui.confirm_delete')),
    cancel: @json(__('purchasing.copy.cancel')),
};

function resolvePrElement(input) {
    if (input instanceof Element) {
        return input;
    }
    if (input && !(input instanceof Element) && input[0] instanceof Element) {
        return input[0];
    }
    return null;
}

function setPrBadgeState(badgeEl, variantClass, text) {
    if (!badgeEl) return;
    badgeEl.classList.remove(
        'ui-status-chip--success',
        'ui-status-chip--warning',
        'ui-status-chip--error',
        'ui-status-chip--neutral',
    );
    badgeEl.classList.add(variantClass);
    badgeEl.textContent = text;
}

function setPrInputValueAndDispatch(input, value, eventNames = ['input', 'change']) {
    if (!input) return;
    input.value = value;
    eventNames.forEach((eventName) => {
        input.dispatchEvent(new Event(eventName, { bubbles: true }));
    });
}

function getHsBadgeResolution(hs) {
    const labelMap = {
        matched: @json(__('purchasing.copy.auto_matched')),
        ambiguous: @json(__('purchasing.copy.ambiguous')),
        no_rule: @json(__('purchasing.copy.no_rule')),
        unmapped_material: @json(__('purchasing.copy.unmapped_material')),
        insufficient_data: @json(__('purchasing.copy.needs_more_data')),
    };
    const isManual = hs.source === 'manual';
    const variantClass = isManual
        ? 'ui-status-chip--warning'
        : (hs.status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral');
    const text = isManual
        ? prPurchasingCopy.manualSelection
        : (labelMap[hs.status] || hs.status || prPurchasingCopy.needsMoreData);

    return { isManual, variantClass, text };
}

function syncPrRemarkTriggerUi(cell, value) {
    if (!cell) return;
    const trigger = cell.querySelector('[data-remark-trigger]');
    const triggerText = trigger?.querySelector('.pr-remark-trigger__text');
    if (!trigger) return;

    if (value) {
        if (triggerText) triggerText.textContent = value;
        trigger.classList.add('has-remark');
        trigger.setAttribute('title', value);
        if (!trigger.querySelector('.pr-remark-trigger__badge')) {
            const badge = document.createElement('span');
            badge.className = 'pr-remark-trigger__badge';
            badge.title = prPurchasingCopy.remarkEntered;
            badge.setAttribute('aria-hidden', 'true');
            trigger.appendChild(badge);
        }
    } else {
        if (triggerText) triggerText.textContent = prPurchasingCopy.addRemark;
        trigger.classList.remove('has-remark');
        trigger.setAttribute('title', prPurchasingCopy.clickToAddRemark);
        trigger.querySelector('.pr-remark-trigger__badge')?.remove();
    }
}

function createPrSearchRequestState(timeoutMs) {
    const state = {
        controller: new AbortController(),
        timedOut: false,
        timerId: null,
    };
    state.timerId = setTimeout(() => {
        state.timedOut = true;
        state.controller.abort();
    }, timeoutMs);
    return state;
}

function clearPrRequestStateTimer(state) {
    if (state?.timerId) {
        clearTimeout(state.timerId);
        state.timerId = null;
    }
}

function buildPrPreviewFormBody(payload) {
    const params = new URLSearchParams();
    Object.entries(payload).forEach(([key, value]) => {
        params.append(key, value === null || value === undefined ? '' : String(value));
    });
    return params;
}

// Programmatic .value writes fire no input event; the totals footer listens for this (PUR-02).
function notifyPrItemsChanged() {
    document.dispatchEvent(new CustomEvent('adasi:pr-items-changed'));
}

function renumberPrRows() {
    document.querySelectorAll('#itemsBody tr.item-row').forEach((rowEl, index) => {
        const numberEl = rowEl.querySelector('[data-pr-row-number]');
        if (numberEl) {
            numberEl.textContent = String(index + 1);
        }
    });
    notifyPrItemsChanged();
}

function positionMaterialSearchResults(row) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const input = rowEl.querySelector('.material-master-search');
    const panel = rowEl.querySelector('.material-search-results');

    if (!input || !panel || panel.classList.contains('d-none')) {
        return;
    }

    const rect = input.getBoundingClientRect();
    const viewportPadding = 8;
    const gap = 2;
    const width = Math.min(rect.width, Math.max(0, window.innerWidth - (viewportPadding * 2)));
    const panelHeight = Math.min(panel.scrollHeight || 220, 220);
    const fitsBelow = rect.bottom + gap + panelHeight <= window.innerHeight - viewportPadding;
    const fitsAbove = rect.top - gap - panelHeight >= viewportPadding;
    const opensAbove = !fitsBelow && fitsAbove;
    const left = Math.min(
        Math.max(rect.left, viewportPadding),
        Math.max(viewportPadding, window.innerWidth - width - viewportPadding),
    );

    Object.assign(panel.style, {
        bottom: opensAbove
            ? `${Math.max(viewportPadding, window.innerHeight - rect.top + gap)}px`
            : 'auto',
        left: `${left}px`,
        top: opensAbove
            ? 'auto'
            : `${Math.max(viewportPadding, rect.bottom + gap)}px`,
        position: 'fixed',
        zIndex: '1080',
        width: `${width}px`,
    });
}

function repositionVisibleMaterialSearchResults() {
    document.querySelectorAll('#itemsBody tr.item-row').forEach((rowEl) => {
        const panel = rowEl.querySelector('.material-search-results');
        if (panel && !panel.classList.contains('d-none')) {
            positionMaterialSearchResults(rowEl);
        }
    });
}

function setMaterialSearchOpen(row, open) {
    const rowEl = resolvePrElement(row);
    rowEl?.querySelector('.pr-sticky-material')?.classList.toggle('material-search-open', open);
}

function applyMaterialShapeRules(row, clearIrrelevant = true) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const shape = rowEl.querySelector('.material-shape-select')?.value || '';
    const relevantFields = materialDimensionFields[shape] || [];

    fixedDimensionOrder.forEach((field) => {
        const isRelevant = relevantFields.includes(field);
        const cell = rowEl.querySelector(`[data-dimension-field-cell="${field}"]`);
        const input = cell?.querySelector(`.dimension-input[data-dimension-field="${field}"]`);
        const na = cell?.querySelector(`[data-dimension-na="${field}"]`);

        if (input) {
            if (!isRelevant && clearIrrelevant) {
                input.value = '';
            }
            input.disabled = !isRelevant;
            input.hidden = !isRelevant;
            input.setAttribute('aria-disabled', isRelevant ? 'false' : 'true');
        }

        if (na) {
            na.classList.toggle('d-none', isRelevant);
            na.setAttribute('aria-hidden', isRelevant ? 'true' : 'false');
        }

        cell?.classList.toggle('is-disabled', !isRelevant);
    });
}

function resetMaterialPreview(row) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const hsCodeInput = rowEl.querySelector('.hs-code-display');
    const hsOverrideInput = rowEl.querySelector('.hs-code-manual-override');
    const gradeInput = rowEl.querySelector('.material-grade-display');
    const weightInput = rowEl.querySelector('.weight-unit-display');
    const weightOverrideInput = rowEl.querySelector('.weight-manual-override');

    if (hsCodeInput) hsCodeInput.value = '';
    if (hsOverrideInput) hsOverrideInput.value = '0';
    setPrBadgeState(
        rowEl.querySelector('.hs-status-badge'),
        'ui-status-chip--neutral',
        prPurchasingCopy.needsMoreData,
    );
    if (gradeInput) {
        gradeInput.value = '';
        gradeInput.setAttribute('title', '');
    }
    if (weightInput) weightInput.value = '0.0000';
    if (weightOverrideInput) weightOverrideInput.value = '0';
    notifyPrItemsChanged();
}

function selectMaterialInRow(row, material) {
    const rowEl = resolvePrElement(row);
    if (!rowEl || !material) return;

    const masterIdInput = rowEl.querySelector('.material-master-id');
    const searchInput = rowEl.querySelector('.material-master-search');
    const resultsPanel = rowEl.querySelector('.material-search-results');
    const hsOverrideInput = rowEl.querySelector('.hs-code-manual-override');
    const weightOverrideInput = rowEl.querySelector('.weight-manual-override');
    const gradeInput = rowEl.querySelector('.material-grade-display');
    const remarkMaterialName = rowEl.querySelector('.pr-remark-material-name');

    if (masterIdInput) masterIdInput.value = material.id ?? '';
    if (searchInput) searchInput.value = material.material_code || material.text || '';
    if (resultsPanel) {
        resultsPanel.replaceChildren();
        resultsPanel.classList.add('d-none');
    }
    setMaterialSearchOpen(rowEl, false);
    if (hsOverrideInput) hsOverrideInput.value = '0';
    if (weightOverrideInput) weightOverrideInput.value = '0';

    const initialGrade = material.category_label || material.category || '';
    if (gradeInput) {
        gradeInput.value = initialGrade;
        gradeInput.setAttribute('title', initialGrade);
    }
    if (remarkMaterialName) {
        remarkMaterialName.textContent = material.material_code || material.text || prPurchasingCopy.material;
    }
    scheduleMaterialPreview(rowEl, 0);
}

function renderMaterialSearchResults(row, results) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const panel = rowEl.querySelector('.material-search-results');
    if (!panel) return;

    panel.replaceChildren();
    if (!Array.isArray(results) || results.length === 0) {
        const emptyEl = document.createElement('div');
        emptyEl.className = 'list-group-item small text-muted';
        emptyEl.textContent = prPurchasingCopy.noActiveMasterMaterialFound;
        panel.appendChild(emptyEl);
    } else {
        results.forEach((material) => {
            const category = material.category_label || material.category || 'unmapped';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2';

            const codeEl = document.createElement('div');
            codeEl.className = 'fw-semibold';
            codeEl.textContent = material.material_code || '';

            const metaEl = document.createElement('div');
            metaEl.className = 'small text-muted';
            metaEl.textContent = `${category} | ${material.density_profile}`;

            btn.append(codeEl, metaEl);
            materialOptionMap.set(btn, material);
            panel.appendChild(btn);
        });
    }
    setMaterialSearchOpen(rowEl, true);
    panel.classList.remove('d-none');
    positionMaterialSearchResults(rowEl);
}

function searchMaterial(input) {
    const inputEl = resolvePrElement(input);
    const row = inputEl?.closest('tr');
    if (!inputEl || !row) return;

    const term = String(inputEl.value || '').trim();

    clearTimeout(materialSearchTimers.get(row));
    materialSearchTimers.set(row, setTimeout(async () => {
        const previous = materialSearchRequests.get(row);
        if (previous) {
            previous.controller.abort();
            clearPrRequestStateTimer(previous);
        }

        const requestState = createPrSearchRequestState(8000);
        materialSearchRequests.set(row, requestState);

        try {
            const url = new URL(materialMasterSearchUrl, window.location.origin);
            url.searchParams.set('q', term);

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: requestState.controller.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const payload = await response.json();
            renderMaterialSearchResults(row, payload.results || []);
        } catch (err) {
            if (err?.name !== 'AbortError' || requestState.timedOut) {
                renderMaterialSearchResults(row, []);
            }
        } finally {
            clearPrRequestStateTimer(requestState);
            if (materialSearchRequests.get(row) === requestState) {
                materialSearchRequests.delete(row);
            }
        }
    }, 250));
}

function materialPreviewPayload(row) {
    const rowEl = resolvePrElement(row);
    const hsDisplay = String(rowEl?.querySelector('.hs-code-display')?.value || '').trim();
    const isExact8 = /^\d{8}$/.test(hsDisplay.replace(/\./g, ''));
    const hsOverrideVal = rowEl?.querySelector('.hs-code-manual-override')?.value;
    const hsManualOverride = (hsOverrideVal === '1' && isExact8) ? '1' : '0';

    const payload = {
        _token: prCsrfToken,
        material_master_id: rowEl?.querySelector('.material-master-id')?.value || '',
        material_name: rowEl?.querySelector('.material-master-search')?.value || '',
        shape: rowEl?.querySelector('.material-shape-select')?.value || '',
        quantity: rowEl?.querySelector('.material-quantity')?.value || 1,
        hs_code: isExact8 ? hsDisplay : '',
        hs_code_manual_override: hsManualOverride,
        weight_needed: rowEl?.querySelector('.weight-unit-display')?.value || '',
        weight_manual_override: rowEl?.querySelector('.weight-manual-override')?.value || 0,
    };
    fixedDimensionOrder.forEach((field) => {
        payload[field] = rowEl?.querySelector(`.dimension-input[data-dimension-field="${field}"]`)?.value || '';
    });

    return payload;
}

function renderMaterialPreview(row, payload) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const hs = payload.hs_code || {};
    const weight = payload.weight || {};
    const selectedCode = hs.selected_code || hs.code || '';
    const { isManual, variantClass, text } = getHsBadgeResolution(hs);

    const hsCodeInput = rowEl.querySelector('.hs-code-display');
    const hsOverrideInput = rowEl.querySelector('.hs-code-manual-override');
    const gradeInput = rowEl.querySelector('.material-grade-display');
    const weightInput = rowEl.querySelector('.weight-unit-display');
    const weightOverrideInput = rowEl.querySelector('.weight-manual-override');

    if (hsCodeInput) hsCodeInput.value = selectedCode;
    setPrBadgeState(rowEl.querySelector('.hs-status-badge'), variantClass, text);

    if (hsOverrideInput) hsOverrideInput.value = isManual ? '1' : '0';
    const gradeLabel = hs.grade_label || (payload.material && payload.material.category_label) || '';
    if (gradeInput) {
        gradeInput.value = gradeLabel;
        gradeInput.setAttribute('title', gradeLabel);
    }

    const unitKg = Number(weight.unit_kg || 0);
    if (weightInput) weightInput.value = unitKg.toFixed(4);
    if (weightOverrideInput) weightOverrideInput.value = weight.status === 'manual' ? '1' : '0';
    notifyPrItemsChanged();
}

async function requestMaterialPreview(row) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    const matId = rowEl.querySelector('.material-master-id')?.value || '';
    const matName = String(rowEl.querySelector('.material-master-search')?.value || '').trim();
    if (!matId && !matName) {
        resetMaterialPreview(rowEl);
        return;
    }

    const previous = materialPreviewRequests.get(rowEl);
    if (previous) {
        previous.controller.abort();
        clearPrRequestStateTimer(previous);
    }

    const requestState = createPrSearchRequestState(10000);
    materialPreviewRequests.set(rowEl, requestState);

    try {
        const response = await fetch(materialCalculationPreviewUrl, {
            method: 'POST',
            body: buildPrPreviewFormBody(materialPreviewPayload(rowEl)),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': prCsrfToken,
            },
            signal: requestState.controller.signal,
        });

        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw Object.assign(new Error(`HTTP ${response.status}`), { responseJSON: body });
        }

        if (body.material && body.material.id) {
            const masterIdInput = rowEl.querySelector('.material-master-id');
            if (masterIdInput) masterIdInput.value = body.material.id;
        }
        renderMaterialPreview(rowEl, body);
    } catch (err) {
        if (err?.name === 'AbortError' && !requestState.timedOut) {
            return;
        }
        const responseBody = err?.responseJSON || {};
        const hasErrors = Object.keys(responseBody.errors || {}).length > 0;
        if (responseBody.hs_code && responseBody.weight && !hasErrors) {
            renderMaterialPreview(rowEl, responseBody);
        } else if (!hasErrors) {
            const hsCodeInput = rowEl.querySelector('.hs-code-display');
            const weightInput = rowEl.querySelector('.weight-unit-display');
            if (hsCodeInput) hsCodeInput.value = '';
            if (weightInput) weightInput.value = '0.0000';
            notifyPrItemsChanged();
        }
        setPrBadgeState(
            rowEl.querySelector('.hs-status-badge'),
            'ui-status-chip--error',
            prPurchasingCopy.invalidData,
        );
    } finally {
        clearPrRequestStateTimer(requestState);
        if (materialPreviewRequests.get(rowEl) === requestState) {
            materialPreviewRequests.delete(rowEl);
        }
    }
}

function scheduleMaterialPreview(row, delay = 300) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;

    clearTimeout(materialPreviewTimers.get(rowEl));
    materialPreviewTimers.set(rowEl, setTimeout(() => requestMaterialPreview(rowEl), delay));
}

function initializeMaterialShapeRows() {
    document.querySelectorAll('#itemsBody tr.item-row').forEach((rowEl) => {
        rowEl.dataset.selectedMaterialName = rowEl.querySelector('.material-master-search')?.value || '';
        applyMaterialShapeRules(rowEl, true);
        const hasMatId = Boolean(rowEl.querySelector('.material-master-id')?.value);
        const hasMatName = Boolean(String(rowEl.querySelector('.material-master-search')?.value || '').trim());
        const hasExistingHs = Boolean(rowEl.querySelector('.hs-code-display')?.value);
        const hasExistingWeight = Number(rowEl.querySelector('.weight-unit-display')?.value || 0) > 0;

        if (hasMatId || hasMatName) {
            scheduleMaterialPreview(rowEl, 0);
        } else if (!hasExistingHs && !hasExistingWeight) {
            resetMaterialPreview(rowEl);
        }
    });
    renumberPrRows();
}

// Remark popover event handlers & positioning
function positionRemarkPopover(popover, trigger) {
    const popoverEl = resolvePrElement(popover);
    if (!popoverEl || popoverEl.hidden) return;

    const triggerEl = resolvePrElement(trigger) || popoverEl.closest('td')?.querySelector('[data-remark-trigger]');
    if (!triggerEl) return;

    const rect = triggerEl.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) {
        popoverEl.hidden = true;
        return;
    }

    // Hide if trigger is scrolled outside the table scroll container horizontally
    const scrollContainer = popoverEl.closest('.pr-form-table-scroll');
    if (scrollContainer) {
        const containerRect = scrollContainer.getBoundingClientRect();
        if (rect.right < containerRect.left + 10 || rect.left > containerRect.right - 10) {
            popoverEl.hidden = true;
            triggerEl.setAttribute('aria-expanded', 'false');
            return;
        }
    }

    // Hide if trigger is scrolled outside window viewport vertically
    if (rect.bottom < 0 || rect.top > window.innerHeight) {
        popoverEl.hidden = true;
        triggerEl.setAttribute('aria-expanded', 'false');
        return;
    }

    const viewportPadding = 8;
    const gap = 4;
    const popoverWidth = Math.min(320, Math.max(280, window.innerWidth - (viewportPadding * 2)));
    const popoverHeight = Math.min(popoverEl.offsetHeight || popoverEl.scrollHeight || 230, 280);

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

    Object.assign(popoverEl.style, {
        position: 'fixed',
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        bottom: 'auto',
        right: 'auto',
        width: `${popoverWidth}px`,
        zIndex: '1080',
    });
}

function repositionVisibleRemarkPopovers() {
    document.querySelectorAll('[data-remark-popover]:not([hidden])').forEach((popoverEl) => {
        const triggerEl = popoverEl.closest('td')?.querySelector('[data-remark-trigger]');
        if (triggerEl) {
            positionRemarkPopover(popoverEl, triggerEl);
        }
    });
}

function closeAllRemarkPopovers() {
    document.querySelectorAll('[data-remark-popover]').forEach((el) => {
        el.hidden = true;
    });
    document.querySelectorAll('[data-remark-trigger]').forEach((el) => {
        el.setAttribute('aria-expanded', 'false');
    });
}

function resetManualWeightOverride(row) {
    const rowEl = resolvePrElement(row);
    if (!rowEl) return;
    const weightOverrideInput = rowEl.querySelector('.weight-manual-override');
    const weightInput = rowEl.querySelector('.weight-unit-display');
    if (weightOverrideInput) weightOverrideInput.value = '0';
    if (weightInput) weightInput.value = '';
    notifyPrItemsChanged();
}

function handleWindowResize() {
    repositionVisibleMaterialSearchResults();
    repositionVisibleRemarkPopovers();
}

function handleDocumentScroll() {
    repositionVisibleMaterialSearchResults();
    repositionVisibleRemarkPopovers();
}

window.addEventListener('resize', handleWindowResize);
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

function formatMobileDimensions(shape, row) {
    const rowEl = resolvePrElement(row);
    if (!shape || !rowEl) return '—';
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
        const val = rowEl.querySelector(`.dimension-input[data-dimension-field="${f}"]`)?.value;
        if (val && Number(val) > 0) {
            parts.push(`${abbrevMap[f] || f}: ${val}`);
        }
    });
    return parts.length ? `${parts.join(' × ')} mm` : '—';
}

function renderMobileMaterialCards() {
    const container = document.getElementById('prMobileCardsList');
    if (!container) return;

    container.replaceChildren();
    const rows = document.querySelectorAll('#itemsBody tr.item-row');
    const totalCount = rows.length;

    const itemCountEl = document.getElementById('prMobileItemCount');
    if (itemCountEl) itemCountEl.textContent = String(totalCount);

    const emptyStateEl = document.getElementById('prMobileEmptyState');
    if (totalCount === 0) {
        emptyStateEl?.classList.remove('d-none');
        return;
    }

    emptyStateEl?.classList.add('d-none');

    rows.forEach((rowEl, index) => {
        const matName = String(rowEl.querySelector('.material-master-search')?.value || '').trim();
        const grade = String(rowEl.querySelector('.material-grade-display')?.value || '').trim();
        const shape = String(rowEl.querySelector('.material-shape-select')?.value || '').trim();
        const qty = parseInt(rowEl.querySelector('.material-quantity')?.value, 10) || 1;
        const unitKg = Number(rowEl.querySelector('.weight-unit-display')?.value || 0);
        const rowTotalKg = String(rowEl.querySelector('[data-pr-row-total-kg]')?.textContent || '').trim() || '—';
        const hsCode = String(rowEl.querySelector('.hs-code-display')?.value || '').trim();
        const remark = String(rowEl.querySelector('.pr-item-remark')?.value || '').trim();
        const dimSummary = formatMobileDimensions(shape, rowEl);

        const isUnconfigured = !matName && !rowEl.querySelector('.material-master-id')?.value;
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
                        <div class="tw:font-medium tw:text-on-surface tw:truncate">${escapePrHtml(dimSummary)}</div>
                    </div>
                    <div>
                        <div class="tw:text-on-surface-variant tw:text-[11px]">Total Bobot</div>
                        <div class="tw:font-bold tw:text-on-surface ui-tabular-nums">${escapePrHtml(rowTotalKg)}</div>
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
                    <button type="button" class="ui-data-action ui-motion ui-focus-ring tw:py-1 tw:px-3 tw:text-ui-xs pr-mobile-edit-btn" data-row-index="${index}">
                        <svg class="tw:inline-block tw:align-middle me-1" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        Edit
                    </button>
                    <button type="button" class="ui-data-action ui-data-action--danger ui-motion ui-focus-ring tw:py-1 tw:px-2.5 tw:text-ui-xs pr-mobile-delete-btn" data-row-index="${index}">
                        <svg class="tw:inline-block tw:align-middle me-1" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        Hapus
                    </button>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', card);
    });
}

function applyMobileModalShapeRules() {
    const shape = document.getElementById('mmShape')?.value || '';
    const relevant = materialDimensionFields[shape] || [];

    document.querySelectorAll('.mm-dim-container').forEach((containerEl) => {
        const field = containerEl.dataset.dimField;
        const isRel = relevant.includes(field);
        containerEl.style.display = isRel ? '' : 'none';
        if (!isRel) {
            const dimInput = containerEl.querySelector('.mm-dim-input');
            if (dimInput) dimInput.value = '';
        }
    });
}

let mmPreviewTimer = null;
let mmPreviewRequest = null;

async function requestMobileModalPreview() {
    const matIdInput = document.getElementById('mmMaterialMasterId');
    const matSearchInput = document.getElementById('mmMaterialSearch');
    const weightNeededInput = document.getElementById('mmWeightNeeded');
    const totalWeightDisplay = document.getElementById('mmTotalWeightDisplay');
    const hsCodeInput = document.getElementById('mmHsCode');
    const hsManualInput = document.getElementById('mmHsCodeManualOverride');
    const shapeInput = document.getElementById('mmShape');
    const quantityInput = document.getElementById('mmQuantity');
    const weightManualInput = document.getElementById('mmWeightManualOverride');

    const matId = matIdInput?.value || '';
    const matName = String(matSearchInput?.value || '').trim();
    if (!matId && !matName) {
        if (weightNeededInput) weightNeededInput.value = '0.0000';
        if (totalWeightDisplay) totalWeightDisplay.textContent = '0.0000 kg';
        return;
    }

    if (mmPreviewRequest) {
        mmPreviewRequest.controller.abort();
        clearPrRequestStateTimer(mmPreviewRequest);
    }

    const hsDisplay = String(hsCodeInput?.value || '').trim();
    const isExact8 = /^\d{8}$/.test(hsDisplay.replace(/\./g, ''));
    const hsManual = hsManualInput?.value === '1' && isExact8 ? '1' : '0';

    const payload = {
        _token: prCsrfToken,
        material_master_id: matId,
        material_name: matName,
        shape: shapeInput?.value || '',
        quantity: quantityInput?.value || 1,
        hs_code: isExact8 ? hsDisplay : '',
        hs_code_manual_override: hsManual,
        weight_needed: weightNeededInput?.value || '',
        weight_manual_override: weightManualInput?.value || 0,
    };

    fixedDimensionOrder.forEach((field) => {
        payload[field] = document.getElementById(`mm_dim_${field}`)?.value || '';
    });

    const requestState = createPrSearchRequestState(10000);
    mmPreviewRequest = requestState;

    try {
        const response = await fetch(materialCalculationPreviewUrl, {
            method: 'POST',
            body: buildPrPreviewFormBody(payload),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': prCsrfToken,
            },
            signal: requestState.controller.signal,
        });
        if (!response.ok) {
            return;
        }
        const res = await response.json();
        if (res.material && res.material.id && matIdInput) {
            matIdInput.value = res.material.id;
        }
        const hs = res.hs_code || {};
        const weight = res.weight || {};

        if ((hs.selected_code || hs.code) && hsCodeInput) {
            hsCodeInput.value = hs.selected_code || hs.code;
        }

        const { variantClass, text } = getHsBadgeResolution(hs);
        setPrBadgeState(document.getElementById('mmHsBadge'), variantClass, text);

        const unitKg = Number(weight.unit_kg || 0);
        if (weightNeededInput) weightNeededInput.value = unitKg.toFixed(4);
        const qty = Number(quantityInput?.value || 1);
        if (totalWeightDisplay) {
            totalWeightDisplay.textContent = `${(unitKg * qty).toFixed(4)} kg`;
        }
    } catch (err) {
        if (err?.name !== 'AbortError') {
            return;
        }
    } finally {
        clearPrRequestStateTimer(requestState);
        if (mmPreviewRequest === requestState) {
            mmPreviewRequest = null;
        }
    }
}

function scheduleMobileModalPreview(delay = 300) {
    clearTimeout(mmPreviewTimer);
    mmPreviewTimer = setTimeout(requestMobileModalPreview, delay);
}

let mmSearchTimer = null;
let mmSearchRequest = null;

function searchMobileMaterial(term) {
    clearTimeout(mmSearchTimer);
    mmSearchTimer = setTimeout(async () => {
        if (mmSearchRequest) {
            mmSearchRequest.controller.abort();
            clearPrRequestStateTimer(mmSearchRequest);
        }

        const resultsEl = document.getElementById('mmMaterialResults');
        if (!resultsEl) return;
        resultsEl.replaceChildren();

        if (!term || term.length < 1) {
            resultsEl.classList.add('d-none');
            return;
        }

        const requestState = createPrSearchRequestState(8000);
        mmSearchRequest = requestState;

        try {
            const url = new URL(materialMasterSearchUrl, window.location.origin);
            url.searchParams.set('q', term);

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: requestState.controller.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            const results = payload.results || [];
            resultsEl.replaceChildren();
            if (!results.length) {
                const emptyEl = document.createElement('div');
                emptyEl.className = 'list-group-item small text-muted p-3';
                emptyEl.textContent = prPurchasingCopy.noActiveMasterMaterialFound;
                resultsEl.appendChild(emptyEl);
            } else {
                results.forEach((mat) => {
                    const category = mat.category_label || mat.category || 'unmapped';
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action py-2.5 px-3';

                    const codeEl = document.createElement('div');
                    codeEl.className = 'fw-bold tw:text-ui-xs tw:text-on-surface';
                    codeEl.textContent = mat.material_code || '';

                    const metaEl = document.createElement('div');
                    metaEl.className = 'small text-muted';
                    metaEl.textContent = `${category} | ${mat.density_profile || ''}`;

                    btn.append(codeEl, metaEl);
                    materialOptionMap.set(btn, mat);
                    resultsEl.appendChild(btn);
                });
            }
            resultsEl.classList.remove('d-none');
        } catch (err) {
            if (err?.name !== 'AbortError' || requestState.timedOut) {
                resultsEl.classList.add('d-none');
            }
        } finally {
            clearPrRequestStateTimer(requestState);
            if (mmSearchRequest === requestState) {
                mmSearchRequest = null;
            }
        }
    }, 250);
}

function handlePrMaterialSearchFocusOrInput(target) {
    const input = target.closest('.material-master-search');
    if (input) {
        const row = input.closest('tr');
        if (!row) return;
        const selectedName = String(row.dataset.selectedMaterialName || '').trim();
        const masterIdInput = row.querySelector('.material-master-id');
        if (masterIdInput?.value && selectedName && selectedName !== String(input.value).trim()) {
            masterIdInput.value = '';
            resetMaterialPreview(row);
        }
        searchMaterial(input);
        return;
    }

    const mmSearch = target.closest('#mmMaterialSearch');
    if (mmSearch) {
        searchMobileMaterial(String(mmSearch.value || '').trim());
    }
}

document.addEventListener('focusin', (e) => {
    handlePrMaterialSearchFocusOrInput(e.target);
});

function handlePrFieldInputOrChange(target) {
    const remarkInput = target.closest('.pr-item-remark');
    if (remarkInput) {
        syncPrRemarkTriggerUi(remarkInput.closest('td'), String(remarkInput.value || '').trim());
        return;
    }

    const hsDisplay = target.closest('.hs-code-display');
    if (hsDisplay) {
        const row = hsDisplay.closest('tr');
        const overrideInput = row?.querySelector('.hs-code-manual-override');
        if (overrideInput) overrideInput.value = '1';
        scheduleMaterialPreview(row);
        return;
    }

    const weightUnit = target.closest('.weight-unit-display');
    if (weightUnit) {
        const overrideInput = weightUnit.closest('tr')?.querySelector('.weight-manual-override');
        if (overrideInput) overrideInput.value = '1';
        return;
    }

    const dimInput = target.closest('.dimension-input');
    if (dimInput) {
        const row = dimInput.closest('tr');
        resetManualWeightOverride(row);
        scheduleMaterialPreview(row);
        return;
    }

    const qtyInput = target.closest('.material-quantity');
    if (qtyInput) {
        scheduleMaterialPreview(qtyInput.closest('tr'));
        return;
    }

    if (target.closest('.mm-dim-input')) {
        const weightOverride = document.getElementById('mmWeightManualOverride');
        if (weightOverride) weightOverride.value = '0';
        scheduleMobileModalPreview();
        return;
    }

    if (target.closest('#mmQuantity')) {
        scheduleMobileModalPreview();
        return;
    }

    const mmWeight = target.closest('#mmWeightNeeded');
    if (mmWeight) {
        const weightOverride = document.getElementById('mmWeightManualOverride');
        if (weightOverride) weightOverride.value = '1';
        const unitKg = Number(mmWeight.value || 0);
        const qty = Number(document.getElementById('mmQuantity')?.value || 1);
        const totalEl = document.getElementById('mmTotalWeightDisplay');
        if (totalEl) totalEl.textContent = `${(unitKg * qty).toFixed(4)} kg`;
        return;
    }

    if (target.closest('#mmHsCode')) {
        const hsOverride = document.getElementById('mmHsCodeManualOverride');
        if (hsOverride) hsOverride.value = '1';
        scheduleMobileModalPreview();
    }
}

document.addEventListener('input', (e) => {
    const target = e.target;
    if (target.closest('.material-master-search, #mmMaterialSearch')) {
        handlePrMaterialSearchFocusOrInput(target);
        return;
    }
    handlePrFieldInputOrChange(target);
});

document.addEventListener('change', (e) => {
    const target = e.target;

    const shapeSelect = target.closest('.material-shape-select');
    if (shapeSelect) {
        const row = shapeSelect.closest('tr');
        applyMaterialShapeRules(row, true);
        resetManualWeightOverride(row);
        scheduleMaterialPreview(row);
        return;
    }

    if (target.closest('#mmShape')) {
        applyMobileModalShapeRules();
        const weightOverride = document.getElementById('mmWeightManualOverride');
        if (weightOverride) weightOverride.value = '0';
        scheduleMobileModalPreview();
        return;
    }

    handlePrFieldInputOrChange(target);
});

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') {
        return;
    }
    closeAllRemarkPopovers();

    const searchInput = e.target.closest('.material-master-search');
    if (searchInput) {
        const row = searchInput.closest('tr');
        row?.querySelector('.material-search-results')?.classList.add('d-none');
        setMaterialSearchOpen(row, false);
    }
});

document.addEventListener('click', (e) => {
    const target = e.target;

    const remarkTrigger = target.closest('[data-remark-trigger]');
    if (remarkTrigger) {
        const cell = remarkTrigger.closest('td');
        const popover = cell?.querySelector('[data-remark-popover]');
        if (!cell || !popover) return;

        const isVisible = !popover.hidden;
        closeAllRemarkPopovers();

        if (!isVisible) {
            const currentVal = cell.querySelector('.pr-item-remark')?.value || '';
            const draftInput = cell.querySelector('.pr-remark-draft');
            if (draftInput) draftInput.value = currentVal;

            const matName = cell.closest('tr')?.querySelector('.material-master-search')?.value || prPurchasingCopy.material;
            const remarkTitle = cell.querySelector('.pr-remark-material-name');
            if (remarkTitle) remarkTitle.textContent = matName;

            popover.hidden = false;
            remarkTrigger.setAttribute('aria-expanded', 'true');
            positionRemarkPopover(popover, remarkTrigger);
            draftInput?.focus();
        }
        return;
    }

    const remarkSave = target.closest('[data-remark-save]');
    if (remarkSave) {
        const cell = remarkSave.closest('td');
        const popover = cell?.querySelector('[data-remark-popover]');
        const draftInput = cell?.querySelector('.pr-remark-draft');
        const realInput = cell?.querySelector('.pr-item-remark');
        const trigger = cell?.querySelector('[data-remark-trigger]');
        const draftVal = String(draftInput?.value || '').trim();

        setPrInputValueAndDispatch(realInput, draftVal, ['input', 'change']);
        syncPrRemarkTriggerUi(cell, draftVal);

        if (popover) popover.hidden = true;
        trigger?.setAttribute('aria-expanded', 'false');
        return;
    }

    const remarkCancel = target.closest('[data-remark-cancel]');
    if (remarkCancel) {
        const cell = remarkCancel.closest('td');
        const popover = cell?.querySelector('[data-remark-popover]');
        const trigger = cell?.querySelector('[data-remark-trigger]');
        if (popover) popover.hidden = true;
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            trigger.focus();
        }
        return;
    }

    if (!target.closest('[data-remark-popover], [data-remark-trigger]')) {
        closeAllRemarkPopovers();
    }

    const searchResultBtn = target.closest('.material-search-results button');
    if (searchResultBtn) {
        const row = searchResultBtn.closest('tr');
        const material = materialOptionMap.get(searchResultBtn);
        if (row && material) {
            row.dataset.selectedMaterialName = material.material_code || '';
            selectMaterialInRow(row, material);
        }
        return;
    }

    if (!target.closest('.material-master-search, .material-search-results')) {
        document.querySelectorAll('.material-search-results').forEach((panel) => {
            panel.classList.add('d-none');
            setMaterialSearchOpen(panel.closest('tr'), false);
        });
    }

    const mmResultBtn = target.closest('#mmMaterialResults button');
    if (mmResultBtn) {
        const mat = materialOptionMap.get(mmResultBtn);
        if (!mat) return;

        const matIdEl = document.getElementById('mmMaterialMasterId');
        const matSearchEl = document.getElementById('mmMaterialSearch');
        const clearBtn = document.getElementById('mmBtnClearMaterial');
        const resultsEl = document.getElementById('mmMaterialResults');
        const selectedNameEl = document.getElementById('mmSelectedName');
        const selectedGradeEl = document.getElementById('mmSelectedGrade');
        const selectedFeedbackEl = document.getElementById('mmSelectedFeedback');
        const shapeEl = document.getElementById('mmShape');

        if (matIdEl) matIdEl.value = mat.id ?? '';
        if (matSearchEl) matSearchEl.value = mat.material_code || mat.text || '';
        clearBtn?.classList.remove('d-none');
        if (resultsEl) {
            resultsEl.classList.add('d-none');
            resultsEl.replaceChildren();
        }
        if (selectedNameEl) selectedNameEl.textContent = mat.material_code || mat.text || '';
        if (selectedGradeEl) selectedGradeEl.textContent = mat.category_label || mat.category || '';
        selectedFeedbackEl?.classList.remove('d-none');

        if (mat.default_shape && shapeEl && !shapeEl.value) {
            shapeEl.value = mat.default_shape;
            applyMobileModalShapeRules();
        }
        scheduleMobileModalPreview(0);
        return;
    }

    if (target.closest('#mmBtnClearMaterial')) {
        const matIdEl = document.getElementById('mmMaterialMasterId');
        const matSearchEl = document.getElementById('mmMaterialSearch');
        const hsCodeEl = document.getElementById('mmHsCode');
        const weightNeededEl = document.getElementById('mmWeightNeeded');
        const totalWeightEl = document.getElementById('mmTotalWeightDisplay');
        const resultsEl = document.getElementById('mmMaterialResults');

        if (matIdEl) matIdEl.value = '';
        if (matSearchEl) matSearchEl.value = '';
        document.getElementById('mmSelectedFeedback')?.classList.add('d-none');
        document.getElementById('mmBtnClearMaterial')?.classList.add('d-none');
        if (hsCodeEl) hsCodeEl.value = '';
        if (weightNeededEl) weightNeededEl.value = '0.0000';
        if (totalWeightEl) totalWeightEl.textContent = '0.0000 kg';
        if (resultsEl) {
            resultsEl.classList.add('d-none');
            resultsEl.replaceChildren();
        }
        return;
    }

    if (!target.closest('#mmMaterialSearch, #mmMaterialResults')) {
        document.getElementById('mmMaterialResults')?.classList.add('d-none');
    }

    if (target.closest('#btnOpenMobileMaterialModal')) {
        const titleEl = document.getElementById('mobileMaterialModalTitle');
        const editingIdxEl = document.getElementById('mmEditingIndex');
        const matIdEl = document.getElementById('mmMaterialMasterId');
        const matSearchEl = document.getElementById('mmMaterialSearch');
        const resultsEl = document.getElementById('mmMaterialResults');
        const shapeEl = document.getElementById('mmShape');
        const qtyEl = document.getElementById('mmQuantity');
        const weightEl = document.getElementById('mmWeightNeeded');
        const totalWeightEl = document.getElementById('mmTotalWeightDisplay');
        const hsEl = document.getElementById('mmHsCode');
        const hsOverrideEl = document.getElementById('mmHsCodeManualOverride');
        const weightOverrideEl = document.getElementById('mmWeightManualOverride');
        const remarkEl = document.getElementById('mmRemark');

        if (titleEl) titleEl.textContent = prPurchasingCopy.addMaterial;
        if (editingIdxEl) editingIdxEl.value = '-1';
        if (matIdEl) matIdEl.value = '';
        if (matSearchEl) matSearchEl.value = '';
        document.getElementById('mmBtnClearMaterial')?.classList.add('d-none');
        document.getElementById('mmSelectedFeedback')?.classList.add('d-none');
        if (resultsEl) {
            resultsEl.classList.add('d-none');
            resultsEl.replaceChildren();
        }
        if (shapeEl) shapeEl.value = '';
        applyMobileModalShapeRules();
        document.querySelectorAll('.mm-dim-input').forEach((el) => {
            el.value = '';
        });
        if (qtyEl) qtyEl.value = '1';
        if (weightEl) weightEl.value = '0.0000';
        if (totalWeightEl) totalWeightEl.textContent = '0.0000 kg';
        if (hsEl) hsEl.value = '';
        setPrBadgeState(document.getElementById('mmHsBadge'), 'ui-status-chip--neutral', 'Otomatis');
        if (hsOverrideEl) hsOverrideEl.value = '0';
        if (weightOverrideEl) weightOverrideEl.value = '0';
        if (remarkEl) remarkEl.value = '';

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mobileMaterialModal'));
        modal.show();
        return;
    }

    const editBtn = target.closest('.pr-mobile-edit-btn');
    if (editBtn) {
        const idx = parseInt(editBtn.dataset.rowIndex, 10);
        const rowEl = document.querySelectorAll('#itemsBody tr.item-row')[idx];
        if (!rowEl) return;

        const titleEl = document.getElementById('mobileMaterialModalTitle');
        const editingIdxEl = document.getElementById('mmEditingIndex');
        const matIdEl = document.getElementById('mmMaterialMasterId');
        const matSearchEl = document.getElementById('mmMaterialSearch');
        const clearBtn = document.getElementById('mmBtnClearMaterial');
        const selectedNameEl = document.getElementById('mmSelectedName');
        const selectedGradeEl = document.getElementById('mmSelectedGrade');
        const selectedFeedbackEl = document.getElementById('mmSelectedFeedback');
        const resultsEl = document.getElementById('mmMaterialResults');
        const shapeEl = document.getElementById('mmShape');
        const qtyEl = document.getElementById('mmQuantity');
        const weightEl = document.getElementById('mmWeightNeeded');
        const totalWeightEl = document.getElementById('mmTotalWeightDisplay');
        const hsEl = document.getElementById('mmHsCode');
        const mmHsBadge = document.getElementById('mmHsBadge');
        const hsOverrideEl = document.getElementById('mmHsCodeManualOverride');
        const weightOverrideEl = document.getElementById('mmWeightManualOverride');
        const remarkEl = document.getElementById('mmRemark');

        if (titleEl) titleEl.textContent = `Edit Material #${idx + 1}`;
        if (editingIdxEl) editingIdxEl.value = String(idx);

        const matId = rowEl.querySelector('.material-master-id')?.value || '';
        const matName = rowEl.querySelector('.material-master-search')?.value || '';
        const grade = rowEl.querySelector('.material-grade-display')?.value || '';

        if (matIdEl) matIdEl.value = matId;
        if (matSearchEl) matSearchEl.value = matName;

        if (matName) {
            clearBtn?.classList.remove('d-none');
            if (selectedNameEl) selectedNameEl.textContent = matName;
            if (selectedGradeEl) selectedGradeEl.textContent = grade || '—';
            selectedFeedbackEl?.classList.remove('d-none');
        } else {
            clearBtn?.classList.add('d-none');
            selectedFeedbackEl?.classList.add('d-none');
        }

        if (resultsEl) {
            resultsEl.classList.add('d-none');
            resultsEl.replaceChildren();
        }

        const shape = rowEl.querySelector('.material-shape-select')?.value || '';
        if (shapeEl) shapeEl.value = shape;
        applyMobileModalShapeRules();

        fixedDimensionOrder.forEach((f) => {
            const val = rowEl.querySelector(`.dimension-input[data-dimension-field="${f}"]`)?.value || '';
            const mmDimEl = document.getElementById(`mm_dim_${f}`);
            if (mmDimEl) mmDimEl.value = val;
        });

        const qty = rowEl.querySelector('.material-quantity')?.value || '1';
        if (qtyEl) qtyEl.value = qty;

        const unitKg = Number(rowEl.querySelector('.weight-unit-display')?.value || 0);
        if (weightEl) weightEl.value = unitKg.toFixed(4);
        const totalKg = (unitKg * Number(qty)).toFixed(4);
        if (totalWeightEl) totalWeightEl.textContent = `${totalKg} kg`;

        const hsCode = rowEl.querySelector('.hs-code-display')?.value || '';
        if (hsEl) hsEl.value = hsCode;

        const rowBadge = rowEl.querySelector('.hs-status-badge');
        const hsBadgeClass = rowBadge?.getAttribute('class') || 'ui-status-chip ui-status-chip--neutral';
        const hsBadgeText = String(rowBadge?.textContent || '').trim() || 'Otomatis';
        if (mmHsBadge) {
            mmHsBadge.className = hsBadgeClass;
            mmHsBadge.textContent = hsBadgeText;
        }

        if (hsOverrideEl) hsOverrideEl.value = rowEl.querySelector('.hs-code-manual-override')?.value || '0';
        if (weightOverrideEl) weightOverrideEl.value = rowEl.querySelector('.weight-manual-override')?.value || '0';
        if (remarkEl) remarkEl.value = rowEl.querySelector('.pr-item-remark')?.value || '';

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mobileMaterialModal'));
        modal.show();
        return;
    }

    const deleteBtn = target.closest('.pr-mobile-delete-btn');
    if (deleteBtn) {
        const idx = parseInt(deleteBtn.dataset.rowIndex, 10);
        const rowEl = document.querySelectorAll('#itemsBody tr.item-row')[idx];
        if (!rowEl) return;

        AdasiAlert.confirmDanger({
            title: prPurchasingCopy.deleteThisRow,
            confirmText: prPurchasingCopy.confirmDelete,
            cancelText: prPurchasingCopy.cancel,
        }).then((result) => {
            if (result.isConfirmed) {
                rowEl.remove();
                renumberPrRows();
                checkRowCount();
                renderMobileMaterialCards();
            }
        });
        return;
    }

    if (target.closest('#btnSaveMobileMaterialItem')) {
        const matSearchEl = document.getElementById('mmMaterialSearch');
        const shapeEl = document.getElementById('mmShape');
        const qtyEl = document.getElementById('mmQuantity');

        const matName = String(matSearchEl?.value || '').trim();
        if (!matName) {
            AdasiAlert.error({
                title: 'Material Diperlukan',
                text: 'Silakan pilih atau masukkan nama material sebelum menyimpan.',
            });
            matSearchEl?.focus();
            return;
        }

        const shape = shapeEl?.value || '';
        if (!shape) {
            AdasiAlert.error({
                title: 'Bentuk Material Diperlukan',
                text: 'Silakan pilih bentuk material (Shape).',
            });
            shapeEl?.focus();
            return;
        }

        const qty = parseInt(qtyEl?.value, 10) || 1;
        if (qty < 1) {
            AdasiAlert.error({
                title: 'Jumlah Tidak Valid',
                text: 'Quantity minimal 1.',
            });
            qtyEl?.focus();
            return;
        }

        const editIdx = parseInt(document.getElementById('mmEditingIndex')?.value, 10);
        const rows = document.querySelectorAll('#itemsBody tr.item-row');
        let targetRow = null;

        if (editIdx >= 0) {
            targetRow = rows[editIdx] || null;
        } else {
            const firstRow = rows[0] || null;
            if (rows.length === 1 &&
                firstRow &&
                !String(firstRow.querySelector('.material-master-search')?.value || '').trim() &&
                !String(firstRow.querySelector('.material-master-id')?.value || '').trim()) {
                targetRow = firstRow;
            } else {
                addRow();
                const updatedRows = document.querySelectorAll('#itemsBody tr.item-row');
                targetRow = updatedRows[updatedRows.length - 1] || null;
            }
        }

        if (!targetRow) return;

        const setTargetField = (selector, val) => {
            const el = targetRow.querySelector(selector);
            if (el) el.value = val;
        };

        setTargetField('.material-master-id', document.getElementById('mmMaterialMasterId')?.value || '');
        setTargetField('.material-master-search', matName);
        targetRow.dataset.selectedMaterialName = matName;
        setTargetField('.material-shape-select', shape);
        applyMaterialShapeRules(targetRow, false);

        fixedDimensionOrder.forEach((f) => {
            const val = document.getElementById(`mm_dim_${f}`)?.value || '';
            setTargetField(`.dimension-input[data-dimension-field="${f}"]`, val);
        });

        setTargetField('.material-quantity', String(qty));
        setTargetField('.weight-unit-display', document.getElementById('mmWeightNeeded')?.value || '0.0000');
        setTargetField('.weight-manual-override', document.getElementById('mmWeightManualOverride')?.value || '0');
        setTargetField('.hs-code-display', document.getElementById('mmHsCode')?.value || '');
        setTargetField('.hs-code-manual-override', document.getElementById('mmHsCodeManualOverride')?.value || '0');

        const mmHsBadge = document.getElementById('mmHsBadge');
        const targetBadge = targetRow.querySelector('.hs-status-badge');
        if (targetBadge && mmHsBadge) {
            targetBadge.className = mmHsBadge.className;
            targetBadge.textContent = mmHsBadge.textContent;
        }

        const remark = String(document.getElementById('mmRemark')?.value || '').trim();
        setPrInputValueAndDispatch(targetRow.querySelector('.pr-item-remark'), remark, ['input', 'change']);

        scheduleMaterialPreview(targetRow, 0);
        renumberPrRows();
        checkRowCount();

        const modalEl = document.getElementById('mobileMaterialModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        renderMobileMaterialCards();
        return;
    }

    const toggleTableBtn = target.closest('#btnTogglePrTableView');
    if (toggleTableBtn) {
        const container = document.getElementById('prTableContainer');
        const label = document.getElementById('btnTogglePrTableLabel');
        if (!container) return;

        const isHidden = container.classList.contains('d-none');
        if (isHidden) {
            container.classList.remove('d-none');
            toggleTableBtn.setAttribute('aria-expanded', 'true');
            if (label) label.textContent = 'Tutup Tabel';
        } else {
            container.classList.add('d-none');
            toggleTableBtn.setAttribute('aria-expanded', 'false');
            if (label) label.textContent = 'Lihat Tabel Penuh';
        }
    }
});

// Listen for items changed event to synchronize cards
document.addEventListener('adasi:pr-items-changed', function() {
    renderMobileMaterialCards();
});
