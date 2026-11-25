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
    $gradeLabel = $item instanceof \App\Models\PrItem
        ? $item->grade_label
        : ($itemData['grade_label'] ?? (
            ! empty($rowMasterId) && is_numeric($rowMasterId)
                ? \App\Models\PrItem::resolveGradeLabel(
                    ! empty($itemData['hs_code_rule_id']) ? \App\Models\HsCodeRule::find($itemData['hs_code_rule_id'])?->material_category : null,
                    \App\Models\MaterialMaster::find($rowMasterId)?->hs_category,
                )
                : null
        ));
@endphp
<tr class="item-row">
    <td class="pr-sticky-number tw:text-center tw:text-on-surface-variant ui-tabular-nums" data-pr-row-number>
        {{ is_numeric($index) ? ((int) $index + 1) : '' }}
    </td>
    <td class="pr-sticky-material">
        @if(!empty($itemData['id']))
            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $itemData['id'] }}">
        @endif
        <input type="hidden" name="items[{{ $index }}][material_master_id]" class="material-master-id" value="{{ $rowMasterId }}">
        <div class="tw:relative">
            <input
                type="text"
                name="items[{{ $index }}][material_name]"
                class="ui-input ui-input--sm material-master-search"
                required
                autocomplete="off"
                aria-label="{{ __('purchasing.copy.material_name') }}"
                placeholder="{{ __('purchasing.copy.search_grade_or_name_e_g_skd11_dc53_s50c') }}"
                value="{{ $itemData['material_name'] ?? '' }}"
            >
            <div
                class="material-search-results tw:list-none tw:m-0 tw:p-0 tw:border tw:border-outline-variant tw:rounded-ui-sm tw:shadow-ui-1 tw:hidden tw:absolute tw:left-0 tw:top-full tw:mt-1 tw:w-full tw:bg-surface tw:z-[1000] tw:max-h-[220px] tw:overflow-y-auto tw:rounded-md tw:border tw:border-outline-variant"
                role="listbox"
            ></div>
        </div>
        @error("items.{$index}.material_master_id")
            <div class="tw:text-error tw:text-ui-xs">{{ $message }}</div>
        @enderror

    </td>
    <td>
        <div class="pr-hs-control">
            <input type="text" name="items[{{ $index }}][hs_code]" class="ui-input ui-input--sm hs-code-display" maxlength="20" value="{{ $itemData['hs_code'] ?? '' }}" placeholder="{{ __('purchasing.copy.e_g_7228_30_90') }}" aria-label="{{ __('common.fields.hs_code') }}">
            <span class="ui-status-chip hs-status-badge {{ $source === 'manual' ? 'ui-status-chip--warning' : ($status === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral') }}">{{ $statusLabel }}</span>
        </div>
        <input type="hidden" name="items[{{ $index }}][hs_code_manual_override]" class="hs-code-manual-override" value="{{ $source === 'manual' ? '1' : '0' }}">
        @error("items.{$index}.hs_code")
            <div class="tw:text-error tw:text-ui-xs">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <input
            type="text"
            class="ui-input ui-input--sm material-grade-display"
            value="{{ $gradeLabel ?? '' }}"
            placeholder="—"
            readonly
            aria-readonly="true"
            aria-label="{{ __('purchasing.copy.material_grade') }}"
            title="{{ $gradeLabel ?? '' }}"
        >
    </td>
    <td>
        <select name="items[{{ $index }}][shape]" class="ui-select ui-select--sm material-shape-select" aria-label="{{ __('purchasing.copy.material_shape') }}">
            <option value="">{{ __('purchasing.copy.select') }}</option>
            @foreach(\App\Models\PrItem::SHAPES as $shape)
                <option value="{{ $shape }}" {{ ($itemData['shape'] ?? '') === $shape ? 'selected' : '' }}>{{ $shape }}</option>
            @endforeach
        </select>
            @error("items.{$index}.shape") <div class="tw:text-error tw:text-ui-xs">{{ $message }}</div> @enderror
    </td>
    <td>
        <input type="number" step="1" min="1" name="items[{{ $index }}][quantity]" class="ui-input ui-input--sm tw:text-center material-quantity" required value="{{ $quantity }}" aria-label="{{ __('purchasing.copy.material_quantity') }}">
        @error("items.{$index}.quantity") <div class="tw:text-error tw:text-ui-xs">{{ $message }}</div> @enderror
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
                    class="ui-input ui-input--sm tw:text-end dimension-input"
                    data-dimension-field="{{ $dimensionField }}"
                    aria-label="{{ __('materials.a11y.dimension_unit', ['dimension' => $dimensionLabel]) }}"
                    value="{{ $itemData[$dimensionField] ?? '' }}"
                    {{ $isRelevant ? '' : 'disabled' }}
                    {{ $isRelevant ? '' : 'hidden' }}
                >

                <span
                    class="pr-dimension-na {{ $isRelevant ? 'tw:hidden' : '' }}"
                    data-dimension-na="{{ $dimensionField }}"
                    aria-hidden="{{ $isRelevant ? 'true' : 'false' }}"
                >&mdash;</span>
            </div>

            @error("items.{$index}.{$dimensionField}")
                <div class="tw:text-error tw:text-ui-xs tw:mt-1">{{ $message }}</div>
            @enderror
        </td>
    @endforeach
    <td>
        <div class="ui-input-group ui-input-group--sm">
            <input type="number" step="0.0001" name="items[{{ $index }}][weight_needed]" class="ui-input tw:text-end weight-unit-display" value="{{ number_format($unitKg, 4, '.', '') }}" aria-label="{{ __('purchasing.copy.unit_weight_in_kilograms') }}">
            <span class="ui-input-group-text">kg</span>
        </div>
        <input type="hidden" name="items[{{ $index }}][weight_manual_override]" class="weight-manual-override" value="{{ ($itemData['weight_calculation_status'] ?? '') === 'manual' ? '1' : '0' }}">
        @error("items.{$index}.weight_needed") <div class="tw:text-error tw:text-ui-xs">{{ $message }}</div> @enderror
    </td>
    {{-- PUR-02: KG/Unit × Qty, calculated by the server without saving. --}}
    @php
        $initialRowTotalKgDisplay = ($unitKg > 0 && $quantity >= 1)
            ? app(\App\Services\RegionalDisplayFormatter::class)->money(\App\Support\Money::multiply($unitKg, $quantity, 4), 4, 'international')
            : '—';
    @endphp
    <td class="tw:text-end tw:font-semibold ui-tabular-nums tw:whitespace-nowrap" data-pr-row-total-kg>{{ $initialRowTotalKgDisplay }}</td>
    <td class="pr-remark-cell">
        @php $currentRemark = $itemData['remark'] ?? ''; @endphp
        <textarea
            name="items[{{ $index }}][remark]"
            class="pr-item-remark tw:sr-only @error("items.{$index}.remark") is-invalid @enderror"
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
            <span class="pr-remark-trigger__text tw:truncate">{{ $currentRemark ?: __('purchasing.copy.add_remark') }}</span>
            @if(!empty($currentRemark))
                <span class="pr-remark-trigger__badge" title="{{ __('purchasing.copy.remark_entered') }}" aria-hidden="true"></span>
            @endif
        </button>
        @error("items.{$index}.remark")<div class="ui-field-error tw:block">{{ $message }}</div>@enderror

        <div class="pr-remark-popover" data-remark-popover hidden role="dialog" aria-modal="false" aria-label="{{ __('purchasing.copy.material_remark') }}">
            <div class="pr-remark-popover__header">
                <div>
                    <span class="pr-remark-popover__title">{{ __('purchasing.copy.material_remark_a0f2a7') }}</span>
                    <span class="pr-remark-popover__subtitle tw:truncate pr-remark-material-name">{{ $itemData['material_name'] ?? __('purchasing.copy.material') }}</span>
                </div>
                <button type="button" class="pr-remark-popover__close" data-remark-cancel aria-label="{{ __('purchasing.copy.close_remark_popover') }}">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>
            <div class="pr-remark-popover__body">
                <textarea
                    class="ui-textarea tw:text-ui-xs tw:px-2 tw:py-1 pr-remark-draft"
                    rows="4"
                    maxlength="2000"
                    placeholder="{{ __('purchasing.copy.e_g_prime_grade_ultrasonic_test_e_e_mtc_required') }}"
                    aria-label="{{ __('purchasing.copy.material_remark_draft') }}"
                >{{ $currentRemark }}</textarea>
                <div class="pr-remark-popover__hint">{{ __('purchasing.copy.specify_technical_specifications_certifications_or_tolerances') }}</div>
            </div>
            <div class="pr-remark-popover__footer">
                <x-ui.button type="button" variant="ghost" size="sm" class="pr-remark-btn-cancel" data-remark-cancel>{{ __('purchasing.copy.cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="primary" size="sm" class="pr-remark-btn-save" data-remark-save>
                    <x-ui.icon name="check" size="sm" class="tw:me-1" /> {{ __('purchasing.copy.save') }}
                </x-ui.button>
            </div>
        </div>
    </td>
    <td class="tw:text-center pr-sticky-action">
        <x-ui.icon-button icon="trash" :label="__('purchasing.copy.delete_material_row')" variant="danger" size="sm" class="pr-delete-button" onclick="removeRow(this)" />
    </td>
</tr>
