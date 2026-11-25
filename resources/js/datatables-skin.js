function assignDeepCompat(target, source) {
    if (!target || typeof target !== 'object' || !source || typeof source !== 'object') {
        return target;
    }

    Object.entries(source).forEach(([key, value]) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            if (!target[key] || typeof target[key] !== 'object' || Array.isArray(target[key])) {
                target[key] = {};
            }
            assignDeepCompat(target[key], value);
            return;
        }
        target[key] = value;
    });

    return target;
}

function mergeIntoDataTable(DataTable, target, source) {
    const objUtil = DataTable?.util?.object;
    if (typeof objUtil?.assignDeepObjects === 'function') {
        objUtil.assignDeepObjects(target, source, true);
        return;
    }
    if (typeof objUtil?.assignDeep === 'function') {
        objUtil.assignDeep(target, source);
        return;
    }
    assignDeepCompat(target, source);
}

/**
 * Register the pure-token ADASI DataTables 3.1.5 skin without Bootstrap 5 adapters.
 */
export function applyAdasiDataTablesSkin(DataTable = typeof window !== 'undefined' ? window.DataTable : null) {
    if (!DataTable || typeof DataTable.Dom?.c !== 'function') {
        return false;
    }

    DataTable.defaults ??= {};
    DataTable.ext ??= {};
    DataTable.ext.classes ??= {};
    DataTable.ext.renderer ??= {};
    DataTable.ext.renderer.pagingButton ??= {};
    DataTable.ext.renderer.pagingContainer ??= {};

    mergeIntoDataTable(DataTable, DataTable.defaults, {
        renderer: 'adasi',
    });

    mergeIntoDataTable(DataTable, DataTable.ext.classes, {
        container: 'dt-container ui-dt-container',
        search: {
            input: 'ui-dt-search-input',
        },
        length: {
            select: 'ui-dt-length-select',
        },
        processing: {
            container: 'dt-processing',
        },
        layout: {
            row: 'dt-layout-row ui-dt-layout-row',
            cell: 'dt-layout-cell ui-dt-layout-cell',
            tableRow: 'dt-layout-table',
            tableCell: 'dt-layout-table-cell',
            start: 'dt-layout-start',
            end: 'dt-layout-end',
            full: 'dt-layout-full',
        },
    });

    DataTable.ext.renderer.pagingButton.adasi = (_settings, _buttonType, content, active, disabled) => {
        const itemClasses = ['dt-paging-button', 'page-item'];
        if (active) {
            itemClasses.push('active');
        }
        if (disabled) {
            itemClasses.push('disabled');
        }

        const li = DataTable.Dom.c('li').classAdd(itemClasses.join(' '));
        const btn = DataTable.Dom.c('button')
            .classAdd('page-link')
            .attr('role', 'link')
            .attr('type', 'button')
            .html(content)
            .appendTo(li);

        return {
            display: li.get(0),
            clicker: btn.get(0),
        };
    };

    DataTable.ext.renderer.pagingContainer.adasi = (_settings, buttonEls) => DataTable.Dom.c('ul')
        .classAdd('pagination')
        .append(buttonEls)
        .get(0);

    return true;
}
