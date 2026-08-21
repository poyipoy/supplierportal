@php
    $selectedSupplierIds = collect($selectedSupplierIds ?? [])
        ->filter()
        ->map(fn ($id) => (string) $id)
        ->all();
    $selectedSupplierCount = count($selectedSupplierIds);
    $modalId = $modalId ?? 'supplierPickerModal';
@endphp

@once
    @push('styles')
        <style>
            .supplier-option-list {
                max-height: 22.5rem;
                overflow-y: auto;
            }

            .supplier-option {
                cursor: pointer;
                transition: background-color var(--ui-motion-fast) var(--ui-easing-standard);
            }

            .supplier-option:hover {
                background: var(--md-surface-container-low);
            }
        </style>
    @endpush
@endonce

<div class="supplier-picker tw-grid tw-gap-1.5" data-supplier-picker>
    <span class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Select supplier</span>
    <x-ui.button
        type="button"
        variant="secondary"
        class="tw-w-full tw-justify-between"
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        aria-describedby="{{ $modalId }}Summary"
    >
        <x-slot:leading><x-ui.icon name="users" /></x-slot:leading>
        Select supplier
        <x-slot:trailing>
            <span class="supplier-selected-count tw-inline-flex tw-min-w-7 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-primary tw-px-2 tw-py-1 tw-text-ui-xs tw-font-semibold tw-text-primary-foreground">{{ $selectedSupplierCount > 0 ? $selectedSupplierCount : 'All' }}</span>
        </x-slot:trailing>
    </x-ui.button>
    <div id="{{ $modalId }}Summary" class="supplier-selected-summary tw-text-ui-xs tw-text-on-surface-variant" data-empty-text="All Registered Suppliers">
        All Registered Suppliers
    </div>
    @error('supplier_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @error('supplier_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-semibold" id="{{ $modalId }}Label">Select Supplier</h5>
                        <div class="small text-muted">Check the suppliers that will receive this PR quotation invitation.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><x-ui.icon name="search" /></span>
                                <input type="text" class="form-control supplier-search-input" placeholder="Search supplier name, email, or company..." aria-label="Search suppliers">
                            </div>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="secondary" size="sm" class="supplier-select-all">Select all</x-ui.button>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="ghost" size="sm" class="supplier-clear-all">Clear selection</x-ui.button>
                        </div>
                    </div>

                    <div class="border rounded-3 overflow-hidden">
                        <div class="supplier-option-list">
                            @forelse($suppliers as $supplier)
                                @php
                                    $supplierName = $supplier->supplier->company_name ?? $supplier->name;
                                    $supplierEmail = $supplier->email ?? '';
                                    $supplierKey = strtolower($supplierName . ' ' . $supplierEmail . ' ' . ($supplier->name ?? ''));
                                @endphp
                                <label class="supplier-option d-flex gap-3 align-items-start p-3 border-bottom mb-0" data-supplier-key="{{ $supplierKey }}">
                                    <input class="form-check-input mt-1 supplier-checkbox" type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}" data-supplier-name="{{ $supplierName }}" aria-label="Select supplier {{ $supplierName }}" @checked(in_array((string) $supplier->id, $selectedSupplierIds, true))>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold">{{ $supplierName }}</span>
                                        <span class="d-block small text-muted">{{ $supplierEmail ?: $supplier->name }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-4 text-center text-muted">
                                    No supplier terdaftar.
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        If no supplier is checked, the PR will be opened to all registered suppliers.
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Close</x-ui.button>
                    <x-ui.button type="button" data-bs-dismiss="modal">Save selection</x-ui.button>
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
                const emptyText = $picker.find('.supplier-selected-summary').data('empty-text') || 'All Registered Suppliers';

                $picker.find('.supplier-selected-count').text(count > 0 ? count : 'All');

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
