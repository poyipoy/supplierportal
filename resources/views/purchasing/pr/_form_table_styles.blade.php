<style>
    /* Keep the period control at its intrinsic height when the notes field is taller. */
    .pr-period-field {
        align-self: start;
    }

    .pr-material-toolbar {
        gap: .75rem;
    }

    .pr-form-table-scroll {
        border: 1px solid var(--md-outline-variant);
        border-radius: .65rem;
        display: block;
        width: 100%;
        max-width: 100%;
        overflow-x: auto !important;
        overflow-y: hidden;
        overscroll-behavior: auto;
        -webkit-overflow-scrolling: touch;
        position: relative;
        scrollbar-color: #64748B #E2E8F0;
        scrollbar-width: auto;
        padding-bottom: 12px;
    }

    .pr-form-table-scroll::-webkit-scrollbar {
        height: 12px !important;
        display: block !important;
    }

    .pr-form-table-scroll::-webkit-scrollbar-track {
        background: #E2E8F0 !important;
        border-radius: 6px;
    }

    .pr-form-table-scroll::-webkit-scrollbar-thumb {
        background: #64748B !important;
        border: 2px solid #E2E8F0;
        border-radius: 6px;
    }

    .pr-form-table-scroll::-webkit-scrollbar-thumb:hover {
        background: #334155 !important;
    }

    #itemsTable.pr-items-table {
        border: 0 !important;
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: .82rem;
        margin-bottom: 0;
        min-width: 1602px !important;
        width: 1602px !important;
        table-layout: fixed;
        background-color: var(--md-surface, #ffffff);
    }

    #itemsTable.pr-items-table th,
    #itemsTable.pr-items-table td {
        padding: .65rem .55rem;
        background-color: var(--md-surface, #ffffff);
    }

    #itemsTable.pr-items-table thead th {
        background-color: var(--md-surface-container-low, #f1f5f9) !important;
        line-height: 1.25;
        vertical-align: middle !important;
        border-bottom: 1px solid var(--md-outline-variant) !important;
    }

    #itemsTable.pr-items-table .pr-group-header th {
        height: 38px;
        top: 0;
        z-index: 7;
    }

    #itemsTable.pr-items-table .pr-sticky-material {
        background-color: var(--md-surface, #ffffff) !important;
        border-right: 1px solid var(--md-outline-variant) !important;
        box-shadow: none !important;
        left: 0;
        position: sticky;
        width: 300px;
        z-index: 6;
    }

    #itemsTable.pr-items-table thead .pr-sticky-material {
        background-color: var(--md-surface-container, #e2e8f0) !important;
        box-shadow: none !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-material {
        box-shadow: none !important;
        z-index: 6;
    }

    #itemsTable.pr-items-table .pr-sticky-action {
        background-color: var(--md-surface, #ffffff) !important;
        border-left: 1px solid var(--md-outline-variant) !important;
        box-shadow: none !important;
        position: sticky;
        right: 0;
        width: 72px;
    }

    #itemsTable.pr-items-table thead .pr-sticky-action {
        background-color: var(--md-surface-container, #e2e8f0) !important;
        box-shadow: none !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-action {
        box-shadow: none !important;
        z-index: 4;
    }

    #itemsTable.pr-items-table tbody tr:hover > td,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-material,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-action {
        background-color: var(--md-surface-container-lowest, #f8f9fc) !important;
    }

    #itemsTable.pr-items-table .form-control,
    #itemsTable.pr-items-table .form-select,
    #itemsTable.pr-items-table .input-group-text {
        min-height: 38px;
        background-color: var(--md-surface);
        border: 1px solid var(--md-outline);
        box-shadow: none !important;
        border-radius: var(--md-shape-xs);
    }

    #itemsTable.pr-items-table .form-control:focus,
    #itemsTable.pr-items-table .form-select:focus {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 var(--ui-focus-ring-width) rgba(var(--md-primary-rgb), .14) !important;
        position: relative;
        z-index: 2;
    }

    #itemsTable.pr-items-table .pr-dimension-cell.is-disabled {
        background: var(--md-surface-container-low) !important;
    }

    #itemsTable.pr-items-table .dimension-input,
    #itemsTable.pr-items-table .material-quantity,
    #itemsTable.pr-items-table .weight-unit-display {
        font-variant-numeric: tabular-nums;
    }

    #itemsTable.pr-items-table .dimension-input:disabled {
        background: var(--md-surface-container-low) !important;
        border-style: dashed;
        color: var(--md-on-surface-variant);
        cursor: not-allowed;
        opacity: 1;
    }

    #itemsTable.pr-items-table .dimension-input::placeholder {
        color: var(--md-on-surface-variant);
        opacity: 1;
    }

    #itemsTable.pr-items-table .hs-status-badge {
        font-size: .67rem;
        line-height: 1.25;
        white-space: normal;
    }

    #itemsTable.pr-items-table .pr-delete-button {
        align-items: center;
        display: inline-flex;
        height: 40px;
        justify-content: center;
        padding: 0;
        width: 40px;
    }

    @media (max-width: 767.98px) {
        .pr-material-toolbar {
            align-items: stretch !important;
            flex-direction: column;
        }

        .pr-material-toolbar > div {
            justify-content: flex-start !important;
            width: 100%;
        }

        #itemsTable.pr-items-table .form-control,
        #itemsTable.pr-items-table .form-select,
        #itemsTable.pr-items-table .input-group-text {
            min-height: 44px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        #itemsTable.pr-items-table *,
        .pr-form-table-scroll * {
            scroll-behavior: auto !important;
            transition: none !important;
        }
    }
</style>
