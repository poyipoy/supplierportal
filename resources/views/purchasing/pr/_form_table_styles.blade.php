<style>
    .pr-material-toolbar {
        gap: .75rem;
    }

    .pr-form-table-scroll {
        border: 1px solid var(--md-outline);
        border-radius: .65rem;
        max-width: 100%;
        overflow-x: auto;
        position: relative;
        scrollbar-color: var(--md-on-surface-variant) var(--md-surface-container);
        scrollbar-width: thin;
    }

    .pr-form-table-scroll::-webkit-scrollbar {
        height: 10px;
    }

    .pr-form-table-scroll::-webkit-scrollbar-track {
        background: var(--md-surface-container);
    }

    .pr-form-table-scroll::-webkit-scrollbar-thumb {
        background: var(--md-on-surface-variant);
        border: 2px solid var(--md-surface-container);
        border-radius: 999px;
    }

    #itemsTable.pr-items-table {
        border: 0 !important;
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: .82rem;
        margin-bottom: 0;
        min-width: 1320px;
        table-layout: fixed;
        width: 100% !important;
    }

    #itemsTable.pr-items-table th,
    #itemsTable.pr-items-table td {
        padding: .65rem .55rem;
    }

    #itemsTable.pr-items-table thead th {
        line-height: 1.25;
        vertical-align: middle !important;
    }

    #itemsTable.pr-items-table .pr-group-header th {
        height: 38px;
        top: 0;
        z-index: 7;
    }

    #itemsTable.pr-items-table .pr-sticky-material {
        background: var(--md-surface);
        border-right: 1px solid var(--md-outline) !important;
        left: 0;
        position: sticky;
        width: 300px;
        z-index: 6;
    }

    #itemsTable.pr-items-table thead .pr-sticky-material {
        background: var(--md-surface-container) !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-material {
        box-shadow: 5px 0 9px -8px rgba(var(--md-on-surface-rgb), .65);
        z-index: 6;
    }

    #itemsTable.pr-items-table .pr-sticky-action {
        background: var(--md-surface);
        position: sticky;
        right: 0;
        width: 72px;
    }

    #itemsTable.pr-items-table thead .pr-sticky-action {
        background: var(--md-surface-container) !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-action {
        box-shadow: -5px 0 9px -8px rgba(var(--md-on-surface-rgb), .65);
        z-index: 4;
    }

    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-material,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-action {
        background: rgba(var(--md-primary-rgb), .05);
    }

    #itemsTable.pr-items-table .form-control,
    #itemsTable.pr-items-table .form-select,
    #itemsTable.pr-items-table .input-group-text {
        min-height: 38px;
    }

    #itemsTable.pr-items-table .form-control:focus,
    #itemsTable.pr-items-table .form-select:focus {
        border-color: var(--md-primary);
        box-shadow: 0 0 0 .18rem rgba(var(--md-primary-rgb), .14);
        position: relative;
        z-index: 2;
    }

    #itemsTable.pr-items-table .dimension-slot-label {
        color: var(--md-on-surface-variant);
        display: block;
        font-size: .69rem;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: .35rem;
        min-height: 1.7em;
        text-transform: none;
    }

    #itemsTable.pr-items-table .dimension-slot-content {
        position: relative;
        top: -.7rem;
    }

    #itemsTable.pr-items-table .dimension-slot-empty {
        align-items: center;
        background: var(--md-surface-container-low);
        border: 1px dashed var(--md-outline);
        border-radius: .375rem;
        color: var(--md-on-surface-variant);
        display: flex;
        font-size: 1rem;
        justify-content: center;
        min-height: 38px;
    }

    #itemsTable.pr-items-table .dimension-slot-input,
    #itemsTable.pr-items-table .material-quantity,
    #itemsTable.pr-items-table .weight-unit-display {
        font-variant-numeric: tabular-nums;
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
