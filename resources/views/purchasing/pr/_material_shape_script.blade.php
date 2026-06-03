const materialDimensionMap = {
    Flat: ['thickness', 'width', 'length'],
    Round: ['d_outer', 'length'],
    Hollow: ['d_inner', 'd_outer', 'length']
};

const allMaterialDimensions = ['thickness', 'd_inner', 'd_outer', 'width', 'length'];

function applyMaterialShapeRules(row, clearIrrelevant = true) {
    const $row = $(row);
    const shape = $row.find('.material-shape-select').val();
    const relevantFields = materialDimensionMap[shape] || [];

    allMaterialDimensions.forEach((field) => {
        const isRelevant = relevantFields.includes(field);
        const $cell = $row.find(`[data-dimension-cell="${field}"]`);
        const $input = $row.find(`[data-dimension-field="${field}"]`);

        $cell.toggleClass('d-none', !isRelevant);
        $input.prop('disabled', !isRelevant);

        if (!isRelevant && clearIrrelevant) {
            $input.val('');
        }
    });
}

function initializeMaterialShapeRows() {
    $('#itemsBody tr.item-row').each(function() {
        applyMaterialShapeRules(this, true);
    });
}

$(document).on('change', '.material-shape-select', function() {
    applyMaterialShapeRules($(this).closest('tr'), true);
});
