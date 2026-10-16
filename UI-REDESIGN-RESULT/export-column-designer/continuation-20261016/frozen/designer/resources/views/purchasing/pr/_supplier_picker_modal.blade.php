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
    <label class="form-label small fw-semibold tw-text-on-surface mb-0">{{ __('purchasing.copy.supplier_audience') }}</label>
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
            <span class="tw-text-on-surface fw-medium">{{ __('purchasing.copy.select_invited_suppliers') }}</span>
        </span>
        <span class="supplier-selected-count ui-status-chip ui-status-chip--info ui-tabular-nums">
            {{ $selectedSupplierCount > 0 ? $selectedSupplierCount : __('purchasing.copy.all') }}
        </span>
    </x-ui.button>
    <div id="{{ $modalId }}Summary" class="supplier-selected-summary tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5" data-empty-text=__('purchasing.copy.all_registered_suppliers')>
        {{ __('purchasing.copy.all_registered_suppliers') }}
    </div>
    @error('supplier_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @error('supplier_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title fw-bold" id="{{ $modalId }}Label">{{ __('purchasing.copy.select_invited_suppliers') }}</h6>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ __('purchasing.copy.check_specific_suppliers_to_invite_for_this_pr_or_leave_empty_to_open_to_all_registered_suppliers') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
                </div>
                <div class="modal-body tw-p-3.5">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text tw-bg-surface tw-text-outline"><x-ui.icon name="search" size="sm" /></span>
                                <input type="text" class="form-control supplier-search-input" placeholder="{{ __('purchasing.copy.search_by_supplier_name_company_or_email') }}" aria-label="{{ __('purchasing.copy.search_suppliers') }}">
                            </div>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="outline" size="sm" class="supplier-select-all">{{ __('purchasing.copy.select_all') }}</x-ui.button>
                        </div>
                        <div class="col-auto">
                            <x-ui.button type="button" variant="ghost" size="sm" class="supplier-clear-all">{{ __('purchasing.copy.clear_all') }}</x-ui.button>
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
                                    <input class="form-check-input mt-1 supplier-checkbox" type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}" data-supplier-name="{{ $supplierName }}" aria-label="{{ __('purchasing.a11y.select_supplier', ['supplier' => $supplierName]) }}" @checked(in_array((string) $supplier->id, $selectedSupplierIds, true))>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold tw-text-on-surface tw-text-ui-sm">{{ $supplierName }}</span>
                                        <span class="d-block tw-text-on-surface-variant tw-text-ui-xs">{{ $supplierEmail ?: $supplier->name }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-4 text-center tw-text-on-surface-variant tw-text-ui-sm">
                                    {{ __('purchasing.copy.no_registered_suppliers_found') }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="modal-footer tw-bg-surface-low border-top">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.apply_selection') }}</x-ui.button>
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
                const emptyText = $picker.find('.supplier-selected-summary').data('empty-text') || @json(__('purchasing.copy.all_registered_suppliers'));

                $picker.find('.supplier-selected-count').text(count > 0 ? count : @json(__('purchasing.copy.all')));

                if (count === 0) {
                    $picker.find('.supplier-selected-summary').text(emptyText);
                    return;
                }

                const names = checked.map(function() {
                    return $(this).data('supplier-name');
                }).get();
                const visibleNames = names.slice(0, 2).join(', ');
                const suffix = count > 2 ? window.AdasiI18n.choice('purchasing.js.other_suppliers', count - 2) : '';

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
