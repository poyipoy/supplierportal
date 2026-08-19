<div class="dropdown">
    <x-ui.button type="button" variant="ghost" size="sm" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <x-slot:leading><i class="bi bi-upload" aria-hidden="true"></i></x-slot:leading>
        Import Data
    </x-ui.button>
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
