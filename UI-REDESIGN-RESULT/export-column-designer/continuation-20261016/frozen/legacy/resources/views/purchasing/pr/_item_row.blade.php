@php
    $itemData = is_array($item) ? $item : ($item?->toArray() ?? []);
    $status = $itemData['hs_code_resolution_status'] ?? 'insufficient_data';
    $source = $itemData['hs_code_source'] ?? 'auto';
    $statusLabel = blank($itemData['hs_code'] ?? null) ? __('purchasing.copy.unresolved') : match (true) {
        $source === 'manual' => __('purchasing.copy.manual_selection'),
        $status === 'matched' => __('purchasing.copy.auto_matched'),
        $status === 'ambiguous' => __('purchasing.copy.ambiguous'),
        $status === 'no_rule' => __('purchasing.copy.no_rule'),
        $status === 'unmapped_material' => __('purchasing.copy.unmapped_material'),
        default => __('purchasing.copy.needs_data'),
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
                aria-label="{{ __('purchasing.copy.material_name') }}"
                placeholder="{{ __('purchasing.copy.search_grade_or_name_e_g_skd11_dc53_s50c') }}"
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
            <input type="text" name="items[{{ $index }}][hs_code]" class="form-control form-control-sm hs-code-display" maxlength="20" value="{{ $itemData['hs_code'] ?? '' }}" placeholder="{{ __('purchasing.copy.e_g_7228_30_90') }}" aria-label="{{ __('common.fields.hs_code') }}">
            <span class="ui-status-chip hs-status-badge {{ $source === 'manual' ? 'ui-status-chip--warning' : ($status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral') }}">{{ $statusLabel }}</span>
        </div>
        <input type="hidden" name="items[{{ $index }}][hs_code_manual_override]" class="hs-code-manual-override" value="{{ $source === 'manual' ? '1' : '0' }}">
        @error("items.{$index}.hs_code")
            <div class="text-danger small">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <select name="items[{{ $index }}][shape]" class="form-select form-select-sm material-shape-select" aria-label="{{ __('purchasing.copy.material_shape') }}">
            <option value="">{{ __('purchasing.copy.select') }}</option>
            @foreach(\App\Models\PrItem::SHAPES as $shape)
                <option value="{{ $shape }}" {{ ($itemData['shape'] ?? '') === $shape ? 'selected' : '' }}>{{ $shape }}</option>
            @endforeach
        </select>
            @error("items.{$index}.shape") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td>
        <input type="number" step="1" min="1" name="items[{{ $index }}][quantity]" class="form-control form-control-sm text-center material-quantity" required value="{{ $quantity }}" aria-label="{{ __('purchasing.copy.material_quantity') }}">
        @error("items.{$index}.quantity") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    @foreach($fixedDimensionOrder as $dimensionField)
        @php
            $isRelevant = in_array($dimensionField, $relevantDimensions, true);
            $dimensionLabel = \App\Models\PrItem::dimensionLabel($dimensionField)
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
                    aria-label="{{ __('materials.a11y.dimension_unit', ['dimension' => $dimensionLabel]) }}"
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
            <input type="number" step="0.0001" name="items[{{ $index }}][weight_needed]" class="form-control text-end weight-unit-display" value="{{ number_format($unitKg, 4, '.', '') }}" aria-label="{{ __('purchasing.copy.unit_weight_in_kilograms') }}">
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
            aria-label="{{ __('purchasing.copy.material_remark') }}"
            tabindex="-1"
        >{{ $currentRemark }}</textarea>

        <button
            type="button"
            class="pr-remark-trigger ui-motion ui-focus-ring @error("items.{$index}.remark") is-invalid @enderror {{ !empty($currentRemark) ? 'has-remark' : '' }}"
            data-remark-trigger
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-label="{{ __('purchasing.copy.edit_remark') }}"
            title="{{ $currentRemark ?: __('purchasing.copy.click_to_add_remark') }}"
        >
            <x-ui.icon name="file-text" size="sm" class="pr-remark-trigger__icon" aria-hidden="true" />
            <span class="pr-remark-trigger__text text-truncate">{{ $currentRemark ?: __('purchasing.copy.add_remark') }}</span>
            @if(!empty($currentRemark))
                <span class="pr-remark-trigger__badge" title="{{ __('purchasing.copy.remark_entered') }}" aria-hidden="true"></span>
            @endif
        </button>
        @error("items.{$index}.remark")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

        <div class="pr-remark-popover" data-remark-popover hidden role="dialog" aria-modal="false" aria-label="{{ __('purchasing.copy.material_remark') }}">
            <div class="pr-remark-popover__header">
                <div>
                    <span class="pr-remark-popover__title">{{ __('purchasing.copy.material_remark_a0f2a7') }}</span>
                    <span class="pr-remark-popover__subtitle text-truncate pr-remark-material-name">{{ $itemData['material_name'] ?? __('purchasing.copy.material') }}</span>
                </div>
                <button type="button" class="pr-remark-popover__close" data-remark-cancel aria-label="{{ __('purchasing.copy.close_remark_popover') }}">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>
            <div class="pr-remark-popover__body">
                <textarea
                    class="form-control form-control-sm pr-remark-draft"
                    rows="4"
                    maxlength="2000"
                    placeholder="{{ __('purchasing.copy.e_g_prime_grade_ultrasonic_test_e_e_mtc_required') }}"
                    aria-label="{{ __('purchasing.copy.material_remark_draft') }}"
                >{{ $currentRemark }}</textarea>
                <div class="pr-remark-popover__hint">{{ __('purchasing.copy.specify_technical_specifications_certifications_or_tolerances') }}</div>
            </div>
            <div class="pr-remark-popover__footer">
                <button type="button" class="btn btn-sm btn-outline-secondary pr-remark-btn-cancel" data-remark-cancel>{{ __('purchasing.copy.cancel') }}</button>
                <button type="button" class="btn btn-sm btn-primary pr-remark-btn-save" data-remark-save>
                    <x-ui.icon name="check" size="sm" class="me-1" /> {{ __('purchasing.copy.save') }}
                </button>
            </div>
        </div>
    </td>
    <td class="text-center pr-sticky-action">
        <x-ui.icon-button icon="trash" :label="__('purchasing.copy.delete_material_row')" variant="danger" size="sm" class="pr-delete-button" onclick="removeRow(this)" />
    </td>
</tr>
