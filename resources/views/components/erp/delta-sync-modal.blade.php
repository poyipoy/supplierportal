@props([
    'modalId' => 'erpDeltaSyncModal',
    'hashesUrl',
    'syncUrl',
    'reconciliationUrl' => null,
    'applyUrl' => null,
    'ignoreUrl' => null,
    'canApply' => false,
    'suppliers' => null,
])

@php
    $reconMessages = trans('local_procurement.delta_sync.reconciliation');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div
            class="modal-content"
            data-erp-delta-sync
            data-hashes-url="{{ $hashesUrl }}"
            data-sync-url="{{ $syncUrl }}"
            data-reconciliation-url="{{ $reconciliationUrl ?? '' }}"
            data-apply-url="{{ $applyUrl ?? '' }}"
            data-ignore-url="{{ $ignoreUrl ?? '' }}"
            data-can-apply="{{ $canApply ? '1' : '0' }}"
            data-msg-syncing="{{ __('local_procurement.delta_sync.syncing') }}"
            data-msg-synced="{{ __('local_procurement.delta_sync.synced') }}"
            data-msg-no-changes="{{ __('local_procurement.delta_sync.no_changes') }}"
            data-msg-error-prefix="{{ __('local_procurement.delta_sync.error_prefix') }}"
            data-msg-select-supplier="{{ __('local_procurement.delta_sync.select_supplier') }}"
            data-msg-select-file="{{ __('local_procurement.delta_sync.select_file') }}"
            data-reconciliation-i18n="{{ json_encode(is_array($reconMessages) ? $reconMessages : []) }}"
        >
            <div class="modal-header tw:border-b tw:border-outline-variant">
                <div class="tw:min-w-0 tw:flex-1">
                    <h6 class="modal-title fw-bold tw:text-on-surface" id="{{ $modalId }}Label">
                        {{ __('local_procurement.delta_sync.title') }}
                    </h6>
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">
                        {{ __('local_procurement.delta_sync.help') }}
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
            </div>

            <div class="modal-body tw:p-4 tw:space-y-4">
                @if($suppliers !== null)
                    <div>
                        <label for="{{ $modalId }}SupplierId" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                            {{ __('local_procurement.labels.supplier_partner') }} <span class="tw:text-error">*</span>
                        </label>
                        <select
                            id="{{ $modalId }}SupplierId"
                            class="form-select form-select-sm tw:text-ui-xs"
                            data-erp-delta-supplier
                            required
                        >
                            <option value="">{{ __('local_procurement.labels.supplier_option') }}</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" @selected((string) request('supplier_id') === (string) $s->id)>
                                    {{ $s->name }} ({{ $s->supplier?->company_name ?: $s->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <x-ui.file-upload
                    name="delta_file"
                    :id="$modalId . 'File'"
                    :label="__('local_procurement.delta_sync.file')"
                    :helper="__('local_procurement.delta_sync.file_help')"
                    accept=".xlsx,.csv"
                    :allowed-extensions="['xlsx', 'csv']"
                    :max-size-mb="20"
                    data-erp-delta-file
                    required
                />

                <div
                    data-erp-delta-status
                    role="status"
                    aria-live="polite"
                    class="d-none tw:rounded-ui-sm tw:border tw:px-3 tw:py-2 tw:text-ui-xs"
                ></div>

                @if($reconciliationUrl)
                    <section
                        class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-container-low tw:p-3.5 tw:space-y-3"
                        aria-labelledby="{{ $modalId }}ReconHeading"
                        data-erp-delta-recon-section
                    >
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                            <div class="tw:min-w-0">
                                <h6 id="{{ $modalId }}ReconHeading" class="tw:m-0 tw:text-ui-sm tw:font-bold tw:text-on-surface">
                                    {{ __('local_procurement.delta_sync.reconciliation.title') }}
                                </h6>
                                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant tw:mt-0.5">
                                    {{ __('local_procurement.delta_sync.reconciliation.help') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm tw:inline-flex tw:items-center tw:gap-1.5 tw:min-h-[28px] tw:min-w-[28px] tw:text-ui-xs"
                                data-erp-delta-recon-refresh
                            >
                                <x-ui.icon name="refresh-cw" size="xs" />
                                <span>{{ __('local_procurement.delta_sync.reconciliation.refresh') }}</span>
                            </button>
                        </div>

                        <div
                            data-erp-delta-recon-status
                            role="status"
                            aria-live="polite"
                            class="d-none tw:rounded-ui-sm tw:border tw:px-3 tw:py-2 tw:text-ui-xs"
                        ></div>

                        <div class="tw:overflow-x-auto tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container-lowest">
                            <table class="table table-sm table-hover align-middle tw:mb-0 tw:text-ui-xs">
                                <thead class="tw:bg-surface-container tw:text-on-surface-variant">
                                    <tr>
                                        <th scope="col" class="tw:font-semibold tw:py-2 tw:px-3">{{ __('local_procurement.delta_sync.reconciliation.doc_col') }}</th>
                                        <th scope="col" class="tw:font-semibold tw:py-2 tw:px-3 tw:text-end">{{ __('local_procurement.delta_sync.reconciliation.erp_col') }}</th>
                                        <th scope="col" class="tw:font-semibold tw:py-2 tw:px-3 tw:text-end">{{ __('local_procurement.delta_sync.reconciliation.portal_col') }}</th>
                                        <th scope="col" class="tw:font-semibold tw:py-2 tw:px-3">{{ __('local_procurement.delta_sync.reconciliation.status_col') }}</th>
                                        <th scope="col" class="tw:font-semibold tw:py-2 tw:px-3 tw:text-end">{{ __('local_procurement.delta_sync.reconciliation.action_col') }}</th>
                                    </tr>
                                </thead>
                                <tbody data-erp-delta-recon-body>
                                    <tr>
                                        <td colspan="5" class="tw:py-4 tw:px-3 tw:text-center tw:text-on-surface-variant">
                                            {{ $suppliers !== null ? __('local_procurement.delta_sync.reconciliation.select_supplier_hint') : __('local_procurement.delta_sync.reconciliation.empty') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <template data-erp-delta-icon="applied"><x-ui.icon name="circle-check" size="xs" /></template>
                        <template data-erp-delta-icon="matched"><x-ui.icon name="check" size="xs" /></template>
                        <template data-erp-delta-icon="diff"><x-ui.icon name="triangle-alert" size="xs" /></template>
                        <template data-erp-delta-icon="blocked"><x-ui.icon name="shield-alert" size="xs" /></template>
                        <template data-erp-delta-icon="ignored"><x-ui.icon name="circle-x" size="xs" /></template>
                    </section>
                @endif
            </div>

            <div class="modal-footer tw:bg-surface-container-lowest tw:border-t tw:border-outline-variant">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                    {{ __('local_invoice.actions.close') }}
                </x-ui.button>
                <x-ui.button type="button" variant="primary" size="sm" data-erp-delta-submit>
                    <x-ui.icon name="refresh-cw" size="sm" />
                    <span>{{ __('local_procurement.delta_sync.submit') }}</span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

