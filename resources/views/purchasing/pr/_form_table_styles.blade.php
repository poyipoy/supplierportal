<style>
    /* Keep the period control at its intrinsic height when the notes field is taller. */
    .pr-period-field {
        align-self: start;
    }

    .pr-material-toolbar {
        gap: .75rem;
    }

    .pr-material-section {
        overflow: visible !important;
    }

    .pr-material-section .ui-form-section__header {
        position: sticky;
        top: var(--topbar-height, 56px);
        z-index: 25;
        background-color: var(--md-surface-container);
        border-bottom: 1px solid var(--md-outline-variant);
        border-top-left-radius: var(--md-shape-md);
        border-top-right-radius: var(--md-shape-md);
        box-shadow: 0 2px 8px -2px rgba(0, 0, 0, 0.08);
    }

    .pr-form-table-scroll {
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-md);
        display: block;
        width: 100%;
        max-width: 100%;
        overflow-x: auto !important;
        overflow-y: hidden;
        overscroll-behavior: auto;
        -webkit-overflow-scrolling: touch;
        position: relative;
        scrollbar-color: var(--md-on-surface-variant) var(--md-outline-variant);
        scrollbar-width: auto;
        padding-bottom: 12px;
        min-height: 300px; /* Prevent dropdown clipping when table has few rows */
    }

    .pr-form-table-scroll::-webkit-scrollbar {
        height: 12px !important;
        display: block !important;
    }

    .pr-form-table-scroll::-webkit-scrollbar-track {
        background: var(--md-outline-variant) !important;
        border-radius: var(--md-shape-md);
    }

    .pr-form-table-scroll::-webkit-scrollbar-thumb {
        background: var(--md-on-surface-variant) !important;
        border: 2px solid var(--md-outline-variant);
        border-radius: var(--md-shape-md);
    }

    .pr-form-table-scroll::-webkit-scrollbar-thumb:hover {
        background: var(--md-secondary) !important;
    }

    #itemsTable.pr-items-table {
        border: 0 !important;
        border-collapse: separate !important;
        border-spacing: 0;
        font-size: var(--ui-font-size-xs);
        margin-bottom: 0;
        min-width: max-content !important;
        width: max-content !important;
        table-layout: fixed;
        background-color: var(--md-surface);
    }

    #itemsTable.pr-items-table th,
    #itemsTable.pr-items-table td {
        padding: .4rem .45rem;
        background-color: var(--md-surface);
        border-right: 1px solid var(--md-outline-variant) !important;
        border-bottom: 1px solid var(--md-outline-variant) !important;
    }

    #itemsTable.pr-items-table input[type="number"]::-webkit-outer-spin-button,
    #itemsTable.pr-items-table input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        margin: 0 !important;
    }
    #itemsTable.pr-items-table input[type="number"] {
        -moz-appearance: textfield !important;
    }

    #itemsTable.pr-items-table .form-control.is-invalid {
        background-image: none !important;
        padding-right: .5rem !important;
        padding-left: .5rem !important;
    }

    #itemsTable.pr-items-table thead th {
        background-color: var(--md-surface-container-low) !important;
        line-height: 1.25;
        vertical-align: middle !important;
        border-bottom: 1px solid var(--md-outline-variant) !important;
        white-space: normal !important;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    #itemsTable.pr-items-table .pr-group-header th {
        height: 38px;
        position: sticky;
        top: 0;
        z-index: 7;
    }

    #itemsTable.pr-items-table .pr-sticky-material {
        background-color: var(--md-surface) !important;
        border-right: 1px solid var(--md-outline-variant) !important;
        box-shadow: none !important;
        left: 48px;
        position: sticky;
        width: 250px;
        z-index: 6;
    }

    #itemsTable.pr-items-table .pr-sticky-number {
        background-color: var(--md-surface) !important;
        left: 0;
        min-width: 48px;
        position: sticky;
        width: 48px;
        z-index: 7;
    }

    #itemsTable.pr-items-table thead .pr-sticky-number {
        background-color: var(--md-surface-container) !important;
        z-index: 11;
    }

    #itemsTable.pr-items-table thead .pr-sticky-material {
        background-color: var(--md-surface-container) !important;
        box-shadow: none !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-material {
        box-shadow: none !important;
        z-index: 6;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-material.material-search-open {
        z-index: 30;
    }

    #itemsTable.pr-items-table .pr-sticky-action {
        background-color: var(--md-surface) !important;
        border-left: 1px solid var(--md-outline-variant) !important;
        box-shadow: none !important;
        position: sticky;
        right: 0;
        width: 72px;
    }

    #itemsTable.pr-items-table thead .pr-sticky-action {
        background-color: var(--md-surface-container) !important;
        box-shadow: none !important;
        z-index: 10;
    }

    #itemsTable.pr-items-table tbody .pr-sticky-action {
        box-shadow: none !important;
        z-index: 4;
    }

    #itemsTable.pr-items-table tbody tr {
        transition: background-color 150ms ease;
    }

    #itemsTable.pr-items-table tbody tr:hover > td,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-number,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-material,
    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-action {
        background-color: var(--md-surface-container-low) !important;
    }

    #itemsTable.pr-items-table tbody tr:hover > .pr-sticky-number {
        box-shadow: inset 3px 0 0 var(--md-primary) !important;
    }

    .pr-item-remark {
        display: none !important;
    }

    .pr-remark-cell {
        position: relative;
    }

    .pr-remark-trigger {
        align-items: center;
        background: var(--md-surface);
        border: 1px solid var(--md-outline);
        border-radius: var(--md-shape-xs, 4px);
        color: var(--md-on-surface);
        cursor: pointer;
        display: flex;
        font-size: var(--ui-font-size-xs, 12px);
        gap: .375rem;
        min-height: 34px;
        padding: .25rem .5rem;
        position: relative;
        text-align: left;
        transition: border-color var(--ui-motion-standard), background var(--ui-motion-standard);
        width: 100%;
    }

    .pr-remark-trigger:hover {
        background: var(--md-surface-container);
        border-color: var(--md-primary);
    }

    .pr-remark-trigger.has-remark {
        background: var(--md-surface);
        border-color: var(--md-primary);
        font-weight: 500;
    }

    .pr-remark-trigger.has-remark .pr-remark-trigger__icon {
        color: var(--md-primary);
    }

    .pr-remark-trigger__icon {
        color: var(--md-on-surface-variant);
        flex-shrink: 0;
    }

    .pr-remark-trigger__text {
        flex: 1 1 auto;
        min-width: 0;
    }

    .pr-remark-trigger:not(.has-remark) .pr-remark-trigger__text {
        color: var(--md-on-surface-variant);
    }

    .pr-remark-trigger__badge {
        background: var(--md-primary);
        border-radius: 50%;
        flex-shrink: 0;
        height: 6px;
        width: 6px;
    }

    .pr-remark-popover {
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: 12px;
        box-shadow: 0 4px 24px -2px rgba(20, 24, 43, 0.16), 0 12px 32px -4px rgba(20, 24, 43, 0.12);
        left: 0;
        min-width: 280px;
        max-width: 320px;
        padding: .75rem;
        position: absolute;
        top: calc(100% + 4px);
        width: max-content;
        z-index: 1050;
    }

    .pr-remark-popover[hidden] {
        display: none;
    }

    .pr-remark-popover__header {
        align-items: flex-start;
        display: flex;
        justify-content: space-between;
        margin-bottom: .5rem;
    }

    .pr-remark-popover__title {
        color: var(--md-on-surface);
        display: block;
        font-size: var(--ui-font-size-xs, 12px);
        font-weight: 700;
    }

    .pr-remark-popover__subtitle {
        color: var(--md-on-surface-variant);
        display: block;
        font-size: .6875rem;
        max-width: 220px;
    }

    .pr-remark-popover__close {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 6px;
        color: var(--md-on-surface-variant);
        cursor: pointer;
        display: inline-flex;
        height: 22px;
        justify-content: center;
        padding: 0;
        width: 22px;
        transition: background var(--ui-motion-standard), color var(--ui-motion-standard);
    }

    .pr-remark-popover__close:hover {
        background: var(--md-surface-container);
        color: var(--md-on-surface);
    }

    .pr-remark-draft {
        font-size: var(--ui-font-size-xs, 12px);
        line-height: 1.35;
        resize: vertical;
        min-height: 70px;
    }

    .pr-remark-popover__hint {
        color: var(--md-on-surface-variant);
        font-size: .6875rem;
        margin-top: .25rem;
    }

    .pr-remark-popover__footer {
        align-items: center;
        display: flex;
        gap: .375rem;
        justify-content: flex-end;
        margin-top: .5rem;
    }

    .pr-remark-btn-cancel,
    .pr-remark-btn-save {
        font-size: .75rem;
        padding: .2rem .5rem;
    }

    #itemsTable.pr-items-table .form-control,
    #itemsTable.pr-items-table .form-select,
    #itemsTable.pr-items-table .input-group-text {
        min-height: 34px;
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

    #itemsTable.pr-items-table .pr-dimension-slot {
        display: block;
        min-height: 34px;
        position: relative;
    }

    #itemsTable.pr-items-table .pr-dimension-slot-cell {
        vertical-align: middle !important;
    }

    #itemsTable.pr-items-table .pr-dimension-slot-label {
        display: none;
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
        font-size: var(--ui-font-size-xs);
        line-height: 1.15;
        max-width: 100%;
        white-space: nowrap;
    }

    #itemsTable.pr-items-table .pr-hs-control {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: .25rem;
    }

    #itemsTable.pr-items-table .pr-hs-control .hs-code-display {
        flex: 1 1 7rem;
        min-width: 0;
    }

    #itemsTable.pr-items-table .pr-delete-button {
        align-items: center;
        display: inline-flex;
        height: 34px;
        justify-content: center;
        padding: 0;
        width: 34px;
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

        #itemsTable.pr-items-table .pr-dimension-slot {
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
