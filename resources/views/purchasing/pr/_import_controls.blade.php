<div class="btn-group" role="group">
    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-upload me-1"></i> Import Data
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('purchasing.requisitions.import-template') }}">
                <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Template
            </a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item" href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#prImportModal">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Import Excel
            </a>
        </li>
    </ul>
</div>
