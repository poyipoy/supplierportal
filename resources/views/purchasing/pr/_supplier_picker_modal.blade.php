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

<div class="supplier-picker tw:grid tw:gap-1.5" data-supplier-picker="{{ $modalId }}">
    <label class="form-label small fw-semibold tw:text-on-surface mb-0">{{ __('purchasing.copy.supplier_audience') }}</label>
    <x-ui.button
        type="button"
        variant="outline"
        size="sm"
        class="tw:w-full tw:justify-between tw:text-start"
        data-ui-modal-open="#{{ $modalId }}"
        aria-describedby="{{ $modalId }}Summary"
    >
        <span class="d-inline-flex align-items-center gap-2">
            <x-ui.icon name="users" size="sm" class="tw:text-on-surface-variant" />
            <span class="tw:text-on-surface fw-medium">{{ __('purchasing.copy.select_invited_suppliers') }}</span>
        </span>
        <span class="supplier-selected-count ui-status-chip ui-status-chip--info ui-tabular-nums">
            {{ $selectedSupplierCount > 0 ? $selectedSupplierCount : __('purchasing.copy.all') }}
        </span>
    </x-ui.button>
    <div id="{{ $modalId }}Summary" class="supplier-selected-summary tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5" data-empty-text="{{ __('purchasing.copy.all_registered_suppliers') }}">
        {{ __('purchasing.copy.all_registered_suppliers') }}
    </div>
    @error('supplier_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @error('supplier_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

    <dialog class="ui-native-dialog ui-density-modal" id="{{ $modalId }}" data-supplier-picker-modal="{{ $modalId }}" aria-labelledby="{{ $modalId }}Label" closedby="any">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title fw-bold" id="{{ $modalId }}Label">{{ __('purchasing.copy.select_invited_suppliers') }}</h6>
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">{{ __('purchasing.copy.check_specific_suppliers_to_invite_for_this_pr_or_leave_empty_to_open_to_all_registered_suppliers') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('purchasing.copy.close') }}"></button>
                </div>
                <div class="modal-body tw:p-3.5">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text tw:bg-surface tw:text-on-surface-variant"><x-ui.icon name="search" size="sm" /></span>
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
                                <label class="supplier-option d-flex gap-3 align-items-start tw:p-2.5 border-bottom mb-0" data-supplier-key="{{ $supplierKey }}">
                                    <input class="form-check-input mt-1 supplier-checkbox" type="checkbox" name="supplier_ids[]" form="{{ $formId ?? 'prForm' }}" value="{{ $supplier->id }}" data-supplier-name="{{ $supplierName }}" aria-label="{{ __('purchasing.a11y.select_supplier', ['supplier' => $supplierName]) }}" @checked(in_array((string) $supplier->id, $selectedSupplierIds, true))>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold tw:text-on-surface tw:text-ui-sm">{{ $supplierName }}</span>
                                        <span class="d-block tw:text-on-surface-variant tw:text-ui-xs">{{ $supplierEmail ?: $supplier->name }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-4 text-center tw:text-on-surface-variant tw:text-ui-sm">
                                    {{ __('purchasing.copy.no_registered_suppliers_found') }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="modal-footer tw:bg-surface-low border-top">
                    <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('purchasing.copy.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" data-ui-modal-close>{{ __('purchasing.copy.apply_selection') }}</x-ui.button>
                </div>
            </div>
        </div>
    </dialog>
</div>

@once
    @push('scripts')
        <script>
            (function() {
                function getSupplierPicker(el) {
                    if (!(el instanceof Element)) return null;
                    const direct = el.closest('[data-supplier-picker]');
                    if (direct) return direct;

                    const modalEl = el.closest('[data-supplier-picker-modal]');
                    const modalId = modalEl?.dataset.supplierPickerModal;
                    return modalId ? document.querySelector(`[data-supplier-picker="${CSS.escape(modalId)}"]`) : null;
                }

                function getSupplierModal(pickerEl) {
                    if (!(pickerEl instanceof Element)) return null;
                    const modalId = pickerEl.dataset.supplierPicker;
                    return modalId ? document.querySelector(`[data-supplier-picker-modal="${CSS.escape(modalId)}"]`) : pickerEl;
                }

                function updateSupplierPickerState(pickerEl) {
                    if (!pickerEl) return;
                    const modalEl = getSupplierModal(pickerEl);
                    const checked = modalEl ? Array.from(modalEl.querySelectorAll('.supplier-checkbox:checked')) : [];
                    const count = checked.length;
                    const summaryEl = pickerEl.querySelector('.supplier-selected-summary');
                    const countEl = pickerEl.querySelector('.supplier-selected-count');
                    const emptyText = summaryEl?.dataset.emptyText || @json(__('purchasing.copy.all_registered_suppliers'));

                    if (countEl) {
                        countEl.textContent = count > 0 ? String(count) : @json(__('purchasing.copy.all'));
                    }

                    if (!summaryEl) return;

                    if (count === 0) {
                        summaryEl.textContent = emptyText;
                        return;
                    }

                    const names = checked.map((cb) => cb.dataset.supplierName || '');
                    const visibleNames = names.slice(0, 2).join(', ');
                    const suffix = count > 2 ? window.AdasiI18n.choice('purchasing.js.other_suppliers', count - 2) : '';

                    summaryEl.textContent = visibleNames + suffix;
                }

                document.addEventListener('change', (event) => {
                    const checkbox = event.target instanceof Element ? event.target.closest('.supplier-checkbox') : null;
                    if (!checkbox) return;
                    updateSupplierPickerState(getSupplierPicker(checkbox));
                });

                document.addEventListener('input', (event) => {
                    const searchInput = event.target instanceof Element ? event.target.closest('.supplier-search-input') : null;
                    if (!searchInput) return;

                    const keyword = searchInput.value.toLowerCase().trim();
                    const modalEl = searchInput.closest('[data-supplier-picker-modal]');
                    if (!modalEl) return;

                    modalEl.querySelectorAll('.supplier-option').forEach((optionEl) => {
                        const key = (optionEl.dataset.supplierKey || '').toString();
                        optionEl.classList.toggle('d-none', keyword !== '' && !key.includes(keyword));
                    });
                });

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;
                    if (!target) return;

                    const selectAllBtn = target.closest('.supplier-select-all');
                    if (selectAllBtn) {
                        const modalEl = selectAllBtn.closest('[data-supplier-picker-modal]');
                        modalEl?.querySelectorAll('.supplier-option:not(.d-none) .supplier-checkbox').forEach((cb) => {
                            cb.checked = true;
                        });
                        updateSupplierPickerState(getSupplierPicker(selectAllBtn));
                        return;
                    }

                    const clearAllBtn = target.closest('.supplier-clear-all');
                    if (clearAllBtn) {
                        const modalEl = clearAllBtn.closest('[data-supplier-picker-modal]');
                        modalEl?.querySelectorAll('.supplier-checkbox').forEach((cb) => {
                            cb.checked = false;
                        });
                        updateSupplierPickerState(getSupplierPicker(clearAllBtn));
                    }
                });

                function bootSupplierPickers() {
                    document.querySelectorAll('[data-supplier-picker-modal]').forEach((modalEl) => {
                        if (modalEl.parentElement !== document.body) {
                            document.body.appendChild(modalEl);
                        }
                    });

                    document.querySelectorAll('[data-supplier-picker]').forEach((pickerEl) => {
                        updateSupplierPickerState(pickerEl);
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', bootSupplierPickers);
                } else {
                    bootSupplierPickers();
                }
            })();
        </script>
    @endpush
@endonce
