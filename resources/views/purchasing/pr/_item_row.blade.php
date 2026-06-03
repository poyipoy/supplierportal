<tr class="item-row">
    <td>
        <input type="text" name="items[{{ $index }}][material_name]" class="form-control form-control-sm mb-1" required placeholder="Nama Material" value="{{ $item['material_name'] ?? '' }}">
        <input type="text" name="items[{{ $index }}][hs_code]" class="form-control form-control-sm" required placeholder="HS Code" style="font-size: 0.75rem" value="{{ $item['hs_code'] ?? '' }}">
    </td>
    <td>
        <select name="items[{{ $index }}][shape]" class="form-select form-select-sm material-shape-select">
            <option value="">Pilih</option>
            <option value="Flat" {{ ($item['shape'] ?? '') == 'Flat' ? 'selected' : '' }}>Flat</option>
            <option value="Round" {{ ($item['shape'] ?? '') == 'Round' ? 'selected' : '' }}>Round</option>
            <option value="Hollow" {{ ($item['shape'] ?? '') == 'Hollow' ? 'selected' : '' }}>Hollow</option>
        </select>
    </td>
    <td>
        <input type="number" step="1" min="1" name="items[{{ $index }}][quantity]" class="form-control form-control-sm text-center" required value="{{ $item['quantity'] ?? 1 }}">
    </td>
    <td>
        <div class="dimension-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(82px, 1fr)); gap: .5rem;">
            <div class="dimension-cell" data-dimension-cell="thickness">
                <div class="small text-muted" style="font-size: 0.7rem">Ketebalan</div>
                <input type="number" step="0.01" min="0" name="items[{{ $index }}][thickness]" class="form-control form-control-sm dimension-input" data-dimension-field="thickness" value="{{ $item['thickness'] ?? '' }}">
            </div>
            <div class="dimension-cell" data-dimension-cell="d_inner">
                <div class="small text-muted" style="font-size: 0.7rem">D.Dalam</div>
                <input type="number" step="0.01" min="0" name="items[{{ $index }}][d_inner]" class="form-control form-control-sm dimension-input" data-dimension-field="d_inner" value="{{ $item['d_inner'] ?? '' }}">
            </div>
            <div class="dimension-cell" data-dimension-cell="d_outer">
                <div class="small text-muted" style="font-size: 0.7rem">D.Luar</div>
                <input type="number" step="0.01" min="0" name="items[{{ $index }}][d_outer]" class="form-control form-control-sm dimension-input" data-dimension-field="d_outer" value="{{ $item['d_outer'] ?? '' }}">
            </div>
            <div class="dimension-cell" data-dimension-cell="width">
                <div class="small text-muted" style="font-size: 0.7rem">Lebar</div>
                <input type="number" step="0.01" min="0" name="items[{{ $index }}][width]" class="form-control form-control-sm dimension-input" data-dimension-field="width" value="{{ $item['width'] ?? '' }}">
            </div>
            <div class="dimension-cell" data-dimension-cell="length">
                <div class="small text-muted" style="font-size: 0.7rem">Panjang</div>
                <input type="number" step="0.01" min="0" name="items[{{ $index }}][length]" class="form-control form-control-sm dimension-input" data-dimension-field="length" value="{{ $item['length'] ?? '' }}">
            </div>
        </div>
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
