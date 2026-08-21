<div class="btn-group" role="group">
    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <x-ui.icon name="upload" class="me-1" /> Import Data
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('supplier.quotations.import-template', $pr) }}">
                <x-ui.icon name="file-down" class="me-1" /> Download Template
            </a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#quotationImportModal">
                <x-ui.icon name="file-spreadsheet" class="me-1" /> Import Excel
            </button>
        </li>
    </ul>
</div>
