<div class="dropdown">
    <x-ui.button type="button" variant="ghost" size="sm" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <x-slot:leading><x-ui.icon name="upload" /></x-slot:leading>
        Import Data
    </x-ui.button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route('purchasing.requisitions.import-template') }}">
                <x-ui.icon name="file-down" class="me-1" /> Download Template
            </a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#prImportModal">
                <x-ui.icon name="file-spreadsheet" class="me-1" /> Import Excel
            </button>
        </li>
    </ul>
</div>
