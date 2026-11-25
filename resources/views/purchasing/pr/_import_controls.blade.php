<div class="dropdown">
    <x-ui.button type="button" variant="outline" size="sm" class="dropdown-toggle" data-ui-dropdown-trigger popovertarget="prImportActionsMenu" aria-controls="prImportActionsMenu" aria-expanded="false">
        <x-slot:leading><x-ui.icon name="upload" /></x-slot:leading>
        {{ __('purchasing.copy.import_data') }}
    </x-ui.button>
    <ul id="prImportActionsMenu" popover="auto" data-ui-dropdown-menu class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('purchasing.requisitions.import-template') }}"
               data-pr-form-export
               data-allow-dirty="true"
               data-no-loader
               data-form="#prForm"
               data-url="{{ route('purchasing.requisitions.export-form') }}"
               data-fallback-url="{{ route('purchasing.requisitions.import-template') }}">
                <x-ui.icon name="file-down" class="tw:me-1" /> {{ __('purchasing.copy.download_template') }}
            </a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <button type="button" class="dropdown-item" data-ui-modal-open="#prImportModal">
                <x-ui.icon name="file-spreadsheet" class="tw:me-1" /> {{ __('purchasing.copy.import_excel') }}
            </button>
        </li>
    </ul>
</div>
