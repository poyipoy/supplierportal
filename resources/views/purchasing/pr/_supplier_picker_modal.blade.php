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
                max-height: 24rem;
                overflow-y: auto;
            }

            .supplier-option {
                cursor: pointer;
                transition: background-color 0.15s ease;
            }

            .supplier-option:hover {
                background: var(--md-surface-container-low);
            }
        </style>
    @endpush
@endonce

<div class="supplier-picker tw-grid tw-gap-1.5" data-supplier-picker>
    <label class="form-label small fw-semibold tw-text-on-surface mb-0">Supplier Audience</label>
    <x-ui.button
        type="button"
        variant="outline"
        size="sm"
        class="tw-w-full tw-justify-between tw-text-start"
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}"
        aria-describedby="{{ $modalId }}Summary"
    >
        <span class="d-inline-flex align-items-center gap-2">
            <x-ui.icon name="users" size="sm" class="tw-text-on-surface-variant" />
            <span class="tw-text-on-surface fw-medium">Select invited suppliers</span>
        </span>
        <span class="supplier-selected-count ui-status-chip ui-status-chip--info ui-tabular-nums">
            {{ $selectedSupplierCount > 0 ? $selectedSupplierCount : 'All' }}
        </span>
    </x-ui.button>
    <div id="{{ $modalId }}Summary" class="supplier-selected-summary tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5" data-empty-text="All Registered Suppliers">
        All Registered Suppliers
    </div>
    @error('supplier_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @error('supplier_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title fw-bold" id="{{ $modalId }}Label">Select Invited Suppliers</h6>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">Check specific suppliers to invite for this PR, or leave empty to open to all registered suppliers.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body tw-p-3.5">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text tw-bg-surface tw-text-outline"><x-ui.icon name="search" size="sm" /></span>
                                <input type="text" class="form-control supplier-search-input" placeholder="Search by supplier name, company, or email..." aria-label="Search suppliers">
                            </div>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="outline" size="sm" class="supplier-select-all">Select All</x-ui.button>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="ghost" size="sm" class="supplier-clear-all">Clear All</x-ui.button>
                        </div>
                    </div>

                    <div class="border rounded overflow-hidden">
                        <div class="supplier-option-list">
                            @forelse($suppliers as $supplier)
                                @php
                                    $supplierName = $supplier->supplier->company_name ?? $supplier->name;
                                    $supplierEmail = $supplier->email ?? '';
                                    $supplierKey = strtolower($supplierName . ' ' . $supplierEmail . ' ' . ($supplier->name ?? ''));
                                @endphp
                                <label class="supplier-option d-flex gap-3 align-items-start tw-p-2.5 border-bottom mb-0" data-supplier-key="{{ $supplierKey }}">
                                    <input class="form-check-input mt-1 supplier-checkbox" type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}" data-supplier-name="{{ $supplierName }}" aria-label="Select supplier {{ $supplierName }}" @checked(in_array((string) $supplier->id, $selectedSupplierIds, true))>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold tw-text-on-surface tw-text-ui-sm">{{ $supplierName }}</span>
                                        <span class="d-block tw-text-on-surface-variant tw-text-ui-xs">{{ $supplierEmail ?: $supplier->name }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-4 text-center tw-text-on-surface-variant tw-text-ui-sm">
                                    No registered suppliers found.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="modal-footer tw-bg-surface-low border-top">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                    <x-ui.button type="button" size="sm" data-bs-dismiss="modal">Apply Selection</x-ui.button>
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
                const suffix = count > 2 ? ` +${count - 2} other suppliers` : '';

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
