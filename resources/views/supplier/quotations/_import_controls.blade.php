<div class="tw:relative tw:inline-flex" role="group">
    <x-ui.button type="button" variant="outline" size="sm" class="dropdown-toggle" data-ui-dropdown-trigger popovertarget="quotationImportActionsMenu" aria-controls="quotationImportActionsMenu" aria-expanded="false">
        <x-ui.icon name="upload" class="tw:me-1" /> {{ __('supplier.copy.import_data') }}
    </x-ui.button>
    <ul id="quotationImportActionsMenu" popover="auto" data-ui-dropdown-menu class="dropdown-menu dropdown-menu-end">
        <li>
            <a
                class="dropdown-item"
                href="{{ route('supplier.quotations.import-template', $pr) }}"
                data-quotation-form-export
                data-allow-dirty="true"
                data-no-loader
                data-form="#quotationForm"
                data-url="{{ route('supplier.quotations.export-form', $pr) }}"
                data-fallback-url="{{ route('supplier.quotations.import-template', $pr) }}"
            >
                <x-ui.icon name="file-down" class="tw:me-1" /> {{ __('supplier.copy.download_template') }}
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <button type="button" class="dropdown-item" data-ui-modal-open="#quotationImportModal">
                <x-ui.icon name="file-spreadsheet" class="tw:me-1" /> {{ __('supplier.copy.import_excel') }}
            </button>
        </li>
    </ul>
</div>
