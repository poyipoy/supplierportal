@php
    $itemData = is_array($item) ? $item : ($item?->toArray() ?? []);
    $status = $itemData['hs_code_resolution_status'] ?? 'insufficient_data';
    $source = $itemData['hs_code_source'] ?? 'auto';
    $statusLabel = blank($itemData['hs_code'] ?? null) ? 'Unresolved' : match (true) {
        $source === 'manual' => 'Manual',
        $status === 'matched' => 'Auto',
        $status === 'ambiguous' => 'Ambiguous',
        $status === 'no_rule' => 'No rule',
        $status === 'unmapped_material' => 'Unmapped',
        default => 'Needs data',
    };
    $unitKg = (float) ($itemData['weight_needed'] ?? 0);
    $quantity = (int) ($itemData['quantity'] ?? 1);
    $shapeValue = $itemData['shape'] ?? null;
    $fixedDimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
    $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($shapeValue);
    $rowMasterId = $itemData['material_master_id'] ?? '';
@endphp
<tr class="item-row">
    <td class="pr-sticky-number text-center tw-text-on-surface-variant ui-tabular-nums" data-pr-row-number>
        {{ is_numeric($index) ? ((int) $index + 1) : '' }}
    </td>
    <td class="pr-sticky-material">
        @if(!empty($itemData['id']))
            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $itemData['id'] }}">
        @endif
        <input type="hidden" name="items[{{ $index }}][material_master_id]" class="material-master-id" value="{{ $rowMasterId }}">
        <div class="position-relative">
            <input
                type="text"
                name="items[{{ $index }}][material_name]"
                class="form-control form-control-sm material-master-search"
                required
                autocomplete="off"
                aria-label="Material name"
                placeholder="Search grade or name (e.g. SKD11, DC53, S50C)..."
                value="{{ $itemData['material_name'] ?? '' }}"
            >
            <div
                class="material-search-results list-group shadow-sm d-none tw-absolute tw-left-0 tw-top-full tw-mt-1 tw-w-full tw-bg-surface tw-z-[1000] tw-max-h-[220px] tw-overflow-y-auto tw-rounded-md tw-border tw-border-outline-variant"
                role="listbox"
            ></div>
        </div>
        @error("items.{$index}.material_master_id")
            <div class="text-danger small">{{ $message }}</div>
        @enderror

    </td>
    <td>
        <div class="pr-hs-control">
            <input type="text" name="items[{{ $index }}][hs_code]" class="form-control form-control-sm hs-code-display" maxlength="20" value="{{ $itemData['hs_code'] ?? '' }}" placeholder="e.g. 7228.30.90" aria-label="HS code">
            <span class="ui-status-chip hs-status-badge {{ $source === 'manual' ? 'ui-status-chip--warning' : ($status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral') }}">{{ $statusLabel }}</span>
        </div>
        <input type="hidden" name="items[{{ $index }}][hs_code_manual_override]" class="hs-code-manual-override" value="{{ $source === 'manual' ? '1' : '0' }}">
        @error("items.{$index}.hs_code")
            <div class="text-danger small">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <select name="items[{{ $index }}][shape]" class="form-select form-select-sm material-shape-select" aria-label="Material shape">
            <option value="">Select</option>
            @foreach(\App\Models\PrItem::SHAPES as $shape)
                <option value="{{ $shape }}" {{ ($itemData['shape'] ?? '') === $shape ? 'selected' : '' }}>{{ $shape }}</option>
            @endforeach
        </select>
            @error("items.{$index}.shape") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td>
        <input type="number" step="1" min="1" name="items[{{ $index }}][quantity]" class="form-control form-control-sm text-center material-quantity" required value="{{ $quantity }}" aria-label="Material quantity">
        @error("items.{$index}.quantity") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    @foreach($fixedDimensionOrder as $dimensionField)
        @php
            $isRelevant = in_array($dimensionField, $relevantDimensions, true);
            $dimensionLabel = \App\Models\PrItem::DIMENSION_LABELS[$dimensionField]
                ?? ucfirst(str_replace('_', ' ', $dimensionField));
        @endphp
        <td
            class="pr-dimension-cell {{ $isRelevant ? '' : 'is-disabled' }}"
            data-dimension-field-cell="{{ $dimensionField }}"
        >
            <div class="pr-dimension-control">
                <input
                    id="item-{{ $index }}-dimension-{{ $dimensionField }}"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    name="items[{{ $index }}][{{ $dimensionField }}]"
                    class="form-control form-control-sm text-end dimension-input"
                    data-dimension-field="{{ $dimensionField }}"
                    aria-label="{{ $dimensionLabel }} in millimeters"
                    value="{{ $itemData[$dimensionField] ?? '' }}"
                    {{ $isRelevant ? '' : 'disabled' }}
                    {{ $isRelevant ? '' : 'hidden' }}
                >

                <span
                    class="pr-dimension-na {{ $isRelevant ? 'd-none' : '' }}"
                    data-dimension-na="{{ $dimensionField }}"
                    aria-hidden="{{ $isRelevant ? 'true' : 'false' }}"
                >&mdash;</span>
            </div>

            @error("items.{$index}.{$dimensionField}")
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </td>
    @endforeach
    <td>
        <div class="input-group input-group-sm">
            <input type="number" step="0.0001" name="items[{{ $index }}][weight_needed]" class="form-control text-end weight-unit-display" value="{{ number_format($unitKg, 4, '.', '') }}" aria-label="Unit weight in kilograms">
            <span class="input-group-text">kg</span>
        </div>
        <input type="hidden" name="items[{{ $index }}][weight_manual_override]" class="weight-manual-override" value="{{ ($itemData['weight_calculation_status'] ?? '') === 'manual' ? '1' : '0' }}">
        @error("items.{$index}.weight_needed") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td class="pr-remark-cell">
        @php $currentRemark = $itemData['remark'] ?? ''; @endphp
        <textarea
            name="items[{{ $index }}][remark]"
            class="pr-item-remark visually-hidden @error("items.{$index}.remark") is-invalid @enderror"
            aria-label="Material remark"
            tabindex="-1"
        >{{ $currentRemark }}</textarea>

        <button
            type="button"
            class="pr-remark-trigger ui-motion ui-focus-ring @error("items.{$index}.remark") is-invalid @enderror {{ !empty($currentRemark) ? 'has-remark' : '' }}"
            data-remark-trigger
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-label="Edit remark"
            title="{{ $currentRemark ?: 'Click to add remark' }}"
        >
            <x-ui.icon name="file-text" size="sm" class="pr-remark-trigger__icon" aria-hidden="true" />
            <span class="pr-remark-trigger__text text-truncate">{{ $currentRemark ?: 'Add remark...' }}</span>
            @if(!empty($currentRemark))
                <span class="pr-remark-trigger__badge" title="Remark entered" aria-hidden="true"></span>
            @endif
        </button>
        @error("items.{$index}.remark")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

        <div class="pr-remark-popover" data-remark-popover hidden role="dialog" aria-modal="false" aria-label="Material remark">
            <div class="pr-remark-popover__header">
                <div>
                    <span class="pr-remark-popover__title">Material Remark</span>
                    <span class="pr-remark-popover__subtitle text-truncate pr-remark-material-name">{{ $itemData['material_name'] ?? 'Material' }}</span>
                </div>
                <button type="button" class="pr-remark-popover__close" data-remark-cancel aria-label="Close remark popover">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>
            <div class="pr-remark-popover__body">
                <textarea
                    class="form-control form-control-sm pr-remark-draft"
                    rows="4"
                    maxlength="2000"
                    placeholder="e.g. Prime grade, ultrasonic test E/e, MTC required..."
                    aria-label="Material remark draft"
                >{{ $currentRemark }}</textarea>
                <div class="pr-remark-popover__hint">Specify technical specifications, certifications, or tolerances.</div>
            </div>
            <div class="pr-remark-popover__footer">
                <button type="button" class="btn btn-sm btn-outline-secondary pr-remark-btn-cancel" data-remark-cancel>Cancel</button>
                <button type="button" class="btn btn-sm btn-primary pr-remark-btn-save" data-remark-save>
                    <x-ui.icon name="check" size="sm" class="me-1" /> Save
                </button>
            </div>
        </div>
    </td>
    <td class="text-center pr-sticky-action">
        <x-ui.icon-button icon="trash" label="Delete material row" variant="danger" size="sm" class="pr-delete-button" onclick="removeRow(this)" />
    </td>
</tr>
