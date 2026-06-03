@php
    $selectedSupplierIds = collect($selectedSupplierIds ?? [])
        ->filter()
        ->map(fn ($id) => (string) $id)
        ->all();
    $selectedSupplierCount = count($selectedSupplierIds);
    $modalId = $modalId ?? 'supplierPickerModal';
@endphp

<div class="supplier-picker" data-supplier-picker>
    <label class="form-label fw-medium">Pilih Supplier</label>
    <button type="button" class="btn btn-outline-primary w-100 d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
        <span><i class="bi bi-people me-1"></i> Pilih Supplier</span>
        <span class="badge bg-primary supplier-selected-count">{{ $selectedSupplierCount > 0 ? $selectedSupplierCount : 'Semua' }}</span>
    </button>
    <div class="form-text supplier-selected-summary" data-empty-text="Semua Supplier Terdaftar">
        Semua Supplier Terdaftar
    </div>
    @error('supplier_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @error('supplier_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-semibold" id="{{ $modalId }}Label">Pilih Supplier</h5>
                        <div class="small text-muted">Checklist supplier yang akan menerima penawaran PR ini.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control supplier-search-input" placeholder="Cari nama, email, atau perusahaan supplier...">
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-primary supplier-select-all">
                                Centang Semua
                            </button>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary supplier-clear-all">
                                Hapus Pilihan
                            </button>
                        </div>
                    </div>

                    <div class="border rounded-3 overflow-hidden">
                        <div class="supplier-option-list" style="max-height: 360px; overflow-y: auto;">
                            @forelse($suppliers as $supplier)
                                @php
                                    $supplierName = $supplier->supplier->company_name ?? $supplier->name;
                                    $supplierEmail = $supplier->email ?? '';
                                    $supplierKey = strtolower($supplierName . ' ' . $supplierEmail . ' ' . ($supplier->name ?? ''));
                                @endphp
                                <label class="supplier-option d-flex gap-3 align-items-start p-3 border-bottom mb-0" data-supplier-key="{{ $supplierKey }}">
                                    <input class="form-check-input mt-1 supplier-checkbox" type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}" data-supplier-name="{{ $supplierName }}" @checked(in_array((string) $supplier->id, $selectedSupplierIds, true))>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold">{{ $supplierName }}</span>
                                        <span class="d-block small text-muted">{{ $supplierEmail ?: $supplier->name }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-4 text-center text-muted">
                                    Belum ada supplier terdaftar.
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Jika tidak ada supplier yang dicentang, PR akan dibuka untuk semua supplier terdaftar.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" data-bs-dismiss="modal">
                        Simpan Pilihan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function updateSupplierPickerState($picker) {
                const checked = $picker.find('.supplier-checkbox:checked');
                const count = checked.length;
                const emptyText = $picker.find('.supplier-selected-summary').data('empty-text') || 'Semua Supplier Terdaftar';

                $picker.find('.supplier-selected-count').text(count > 0 ? count : 'Semua');

                if (count === 0) {
                    $picker.find('.supplier-selected-summary').text(emptyText);
                    return;
                }

                const names = checked.map(function() {
                    return $(this).data('supplier-name');
                }).get();
                const visibleNames = names.slice(0, 2).join(', ');
                const suffix = count > 2 ? ` +${count - 2} supplier lain` : '';

                $picker.find('.supplier-selected-summary').text(visibleNames + suffix);
            }

            $(document).on('change', '.supplier-checkbox', function() {
                updateSupplierPickerState($(this).closest('[data-supplier-picker]'));
            });

            $(document).on('input', '.supplier-search-input', function() {
                const keyword = $(this).val().toLowerCase().trim();
                const $picker = $(this).closest('[data-supplier-picker]');

                $picker.find('.supplier-option').each(function() {
                    const key = ($(this).data('supplier-key') || '').toString();
                    $(this).toggleClass('d-none', keyword !== '' && !key.includes(keyword));
                });
            });

            $(document).on('click', '.supplier-select-all', function() {
                const $picker = $(this).closest('[data-supplier-picker]');
                $picker.find('.supplier-option:not(.d-none) .supplier-checkbox').prop('checked', true);
                updateSupplierPickerState($picker);
            });

            $(document).on('click', '.supplier-clear-all', function() {
                const $picker = $(this).closest('[data-supplier-picker]');
                $picker.find('.supplier-checkbox').prop('checked', false);
                updateSupplierPickerState($picker);
            });

            $(function() {
                $('[data-supplier-picker]').each(function() {
                    updateSupplierPickerState($(this));
                });
            });
        </script>
    @endpush
@endonce
