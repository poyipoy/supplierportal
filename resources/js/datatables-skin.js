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

    DataTable.ext.renderer.pagingButton.adasi = (_settings, buttonType, content, active, disabled) => {
        const type = String(buttonType || '');
        const itemClasses = ['dt-paging-button', 'page-item'];
        if (type === 'previous' || type === 'next') {
            itemClasses.push(type);
        }
        if (active) {
            itemClasses.push('active');
        }
        if (disabled || type === 'ellipsis') {
            itemClasses.push('disabled');
        }

        const li = DataTable.Dom.c('li').classAdd(itemClasses.join(' '));
        if (type === 'ellipsis') {
            li.attr('aria-hidden', 'true');
            const span = DataTable.Dom.c('span')
                .classAdd('page-link ellipsis')
                .html(content)
                .appendTo(li);

            return {
                display: li.get(0),
                clicker: span.get(0),
            };
        }

        const btnClasses = ['page-link'];
        if (type === 'previous' || type === 'next') {
            btnClasses.push(type);
        }

        const btn = DataTable.Dom.c('button')
            .classAdd(btnClasses.join(' '))
            .attr('type', 'button')
            .html(content);

        if (active) {
            btn.attr('aria-current', 'page');
        }
        if (disabled) {
            btn.attr('disabled', 'disabled')
                .attr('aria-disabled', 'true');
        }

        btn.appendTo(li);

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
