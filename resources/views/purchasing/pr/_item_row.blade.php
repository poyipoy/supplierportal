<tr class="item-row">
    <td>
        <input type="text" name="items[{{ $index }}][hs_code]" class="form-control form-control-sm" value="{{ $item['hs_code'] ?? '' }}">
    </td>
    <td>
        <input type="text" name="items[{{ $index }}][material_name]" class="form-control form-control-sm" required value="{{ $item['material_name'] ?? '' }}">
    </td>
    <td>
        <select name="items[{{ $index }}][shape]" class="form-select form-select-sm">
            <option value="">-</option>
            <option value="Flat" {{ ($item['shape'] ?? '') == 'Flat' ? 'selected' : '' }}>Flat</option>
            <option value="Round" {{ ($item['shape'] ?? '') == 'Round' ? 'selected' : '' }}>Round</option>
            <option value="Hollow" {{ ($item['shape'] ?? '') == 'Hollow' ? 'selected' : '' }}>Hollow</option>
        </select>
    </td>
    <td>
        <input type="number" step="0.01" name="items[{{ $index }}][thickness]" class="form-control form-control-sm" value="{{ $item['thickness'] ?? '' }}">
    </td>
    <td>
        <input type="number" step="0.01" name="items[{{ $index }}][d_inner]" class="form-control form-control-sm" value="{{ $item['d_inner'] ?? '' }}">
    </td>
    <td>
        <input type="number" step="0.01" name="items[{{ $index }}][d_outer]" class="form-control form-control-sm" value="{{ $item['d_outer'] ?? '' }}">
    </td>
    <td>
        <input type="number" step="0.01" name="items[{{ $index }}][width]" class="form-control form-control-sm" value="{{ $item['width'] ?? '' }}">
    </td>
    <td>
        <input type="number" step="0.01" name="items[{{ $index }}][length]" class="form-control form-control-sm" value="{{ $item['length'] ?? '' }}">
    </td>
    <td>
        <input type="number" step="0.01" min="0.01" name="items[{{ $index }}][weight_needed]" class="form-control form-control-sm" required value="{{ $item['weight_needed'] ?? '' }}">
    </td>
    <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeRow(this)">
            <i class="bi bi-trash"></i>
        </button>
    </td>
</tr>
