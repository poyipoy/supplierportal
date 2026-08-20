@php
    $itemData = is_array($item) ? $item : ($item?->toArray() ?? []);
    $status = $itemData['hs_code_resolution_status'] ?? 'insufficient_data';
    $source = $itemData['hs_code_source'] ?? 'auto';
    $statusLabel = blank($itemData['hs_code'] ?? null) ? 'Unresolved' : match (true) {
        $source === 'manual' => 'Manual selection',
        $status === 'matched' => 'Auto matched',
        $status === 'ambiguous' => 'Ambiguous',
        $status === 'no_rule' => 'No rule',
        $status === 'unmapped_material' => 'Unmapped material',
        default => 'Needs more data',
    };
    $unitKg = (float) ($itemData['weight_needed'] ?? 0);
    $quantity = (int) ($itemData['quantity'] ?? 1);
    $shapeValue = $itemData['shape'] ?? null;
    $dimensionSlots = array_pad(
        App\Models\PrItem::relevantDimensionFields($shapeValue),
        3,
        null,
    );
    $dimensionLabels = [
        'thickness' => 'Thickness',
        'd_inner' => 'Inner Diameter',
        'd_outer' => 'Outer Diameter',
        'width' => 'Width',
        'length' => 'Length',
    ];
@endphp
<tr class="item-row">
    <td class="pr-sticky-material">
        @if(!empty($itemData['id']))
            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $itemData['id'] }}">
        @endif
        <input type="hidden" name="items[{{ $index }}][material_master_id]" class="material-master-id" value="{{ $itemData['material_master_id'] ?? '' }}">
        @foreach(\App\Models\PrItem::DIMENSION_FIELDS as $dimensionField)
            <input
                type="hidden"
                name="items[{{ $index }}][{{ $dimensionField }}]"
                class="dimension-canonical-input"
                data-dimension-canonical-field="{{ $dimensionField }}"
                value="{{ $itemData[$dimensionField] ?? '' }}"
            >
        @endforeach
        <div class="position-relative">
            <input
                type="text"
                name="items[{{ $index }}][material_name]"
                class="form-control form-control-sm material-master-search"
                required
                autocomplete="off"
                aria-label="Material name"
                placeholder="Search master material"
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

        <div class="mt-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text">HS</span>
                <input type="text" name="items[{{ $index }}][hs_code]" class="form-control hs-code-display" maxlength="20" value="{{ $itemData['hs_code'] ?? '' }}" placeholder="Auto or manual HS code" aria-label="HS code">
            </div>
            <input type="hidden" name="items[{{ $index }}][hs_code_manual_override]" class="hs-code-manual-override" value="{{ $source === 'manual' ? '1' : '0' }}">
            <span class="badge mt-1 hs-status-badge {{ $source === 'manual' ? 'bg-warning text-dark' : ($status === 'matched' ? 'bg-success' : 'bg-secondary') }}">{{ $statusLabel }}</span>
            @error("items.{$index}.hs_code")
                <div class="text-danger small">{{ $message }}</div>
            @enderror
        </div>
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
    @foreach($dimensionSlots as $slotIndex => $dimensionField)
        @php
            $slot = $slotIndex + 1;
            $dimensionRelevant = $dimensionField !== null;
            $dimensionLabel = $dimensionField
                ? ($dimensionLabels[$dimensionField] ?? ucfirst(str_replace('_', ' ', $dimensionField)))
                : "Dimension {$slot}";
        @endphp
        <td
            class="pr-dimension-cell pr-dimension-slot-cell {{ $dimensionRelevant ? '' : 'is-disabled' }}"
            data-dimension-slot-cell="{{ $slot }}"
            data-dimension-field-cell="{{ $dimensionField ?? '' }}"
        >
            <div class="pr-dimension-slot">
                <span class="pr-dimension-slot-label" data-dimension-slot-label="{{ $slot }}">
                    {{ $dimensionLabel }}{{ $dimensionRelevant ? ' (mm)' : '' }}
                </span>
                <input
                    id="item-{{ $index }}-dimension-slot-{{ $slot }}"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    class="form-control form-control-sm text-end dimension-input"
                    data-dimension-slot="{{ $slot }}"
                    data-dimension-field="{{ $dimensionField ?? '' }}"
                    aria-label="{{ $dimensionRelevant ? $dimensionLabel . ' in millimeters' : 'Dimension ' . $slot . ' unavailable' }}"
                    placeholder="&mdash;"
                    value="{{ $dimensionField ? ($itemData[$dimensionField] ?? '') : '' }}"
                    {{ $dimensionRelevant ? '' : 'disabled' }}
                >
                @if($dimensionField)
                    @error("items.{$index}.{$dimensionField}") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                @endif
            </div>
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
    <td>
        <textarea name="items[{{ $index }}][remark]" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="Optional material remark" aria-label="Material remark">{{ $itemData['remark'] ?? '' }}</textarea>
        @error("items.{$index}.remark") <div class="text-danger small">{{ $message }}</div> @enderror
    </td>
    <td class="text-center pr-sticky-action">
        <button type="button" class="btn btn-sm btn-outline-danger border-0 pr-delete-button" onclick="removeRow(this)" aria-label="Delete material row">
            <i class="bi bi-trash"></i>
        </button>
    </td>
</tr>
