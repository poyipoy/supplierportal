{{--
    D4 / E1: Sales Contract handshake panel shared by the Supplier and Purchasing PO pages.
    Flow: Supplier uploads -> Purchasing uploads signed copy (or requests revision) -> Supplier acknowledges -> shipments unlock.

    @param \App\Models\PurchaseOrder $po      supplierSalesContractAttachment + purchasingSalesContractAttachment loaded
    @param string                    $viewer  'supplier' | 'purchasing'
--}}
@php
    $scStatus = $po->salesContractStatusEnum();
    $isPurchasingViewer = $viewer === 'purchasing';
    $statusLabels = [
        'pending_supplier' => __('purchasing.copy.sales_contract_status_pending_supplier'),
        'supplier_uploaded' => __('purchasing.copy.sales_contract_status_supplier_uploaded'),
        'revision_requested' => __('purchasing.copy.sales_contract_status_revision_requested'),
        'purchasing_signed' => __('purchasing.copy.sales_contract_status_purchasing_signed'),
    ];
    $statusTones = [
        'pending_supplier' => 'neutral',
        'supplier_uploaded' => 'info',
        'revision_requested' => 'warning',
        'purchasing_signed' => 'success',
    ];
    $supplierFile = $po->supplierSalesContractAttachment;
    $signedFile = $po->purchasingSalesContractAttachment;
    $poOpen = in_array((string) $po->status, ['active', 'overdue'], true);
    $canSupplierUpload = ! $isPurchasingViewer && $poOpen && $scStatus !== \App\Enums\PoSalesContractStatus::PURCHASING_SIGNED;
    $canPurchasingReview = $isPurchasingViewer && $poOpen
        && $scStatus === \App\Enums\PoSalesContractStatus::SUPPLIER_UPLOADED;
    $fileHelpId = 'sc-file-help-'.$viewer;
@endphp
@if($scStatus !== null)
    <x-ui.card :title="__('purchasing.copy.sales_contract_title')" id="sec-sales-contract" class="tw:scroll-mt-24">
        <x-slot:actions>
            <x-ui.status-chip :tone="$statusTones[$scStatus->value] ?? 'neutral'">{{ $statusLabels[$scStatus->value] ?? $scStatus->value }}</x-ui.status-chip>
        </x-slot:actions>

        <ol class="tw:m-0 tw:mb-3 tw:ps-4 tw:grid tw:gap-0.5 tw:text-ui-xs tw:text-on-surface-variant">
            <li>{{ __('purchasing.copy.sales_contract_step_supplier') }}</li>
            <li>{{ __('purchasing.copy.sales_contract_step_purchasing') }}</li>
            <li>{{ __('purchasing.copy.sales_contract_step_ack') }}</li>
        </ol>

        <div class="tw:grid tw:gap-3 tw:md:grid-cols-2 tw:text-ui-xs">
            <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:p-3">
                <div class="tw:font-semibold tw:text-on-surface">{{ __('purchasing.copy.sales_contract_supplier_file') }}</div>
                @if($supplierFile)
                    <a href="{{ route('attachments.show', $supplierFile) }}" target="_blank" rel="noopener" class="tw:inline-flex tw:items-center tw:gap-1 tw:text-primary tw:no-underline tw:font-semibold tw:mt-1">
                        <x-ui.icon name="file-text" size="sm" />
                        <span class="tw:truncate" style="max-width: 18rem;" title="{{ $supplierFile->file_name }}">{{ $supplierFile->file_name }}</span>
                    </a>
                    @if($po->supplier_sales_contract_uploaded_at)
                        <div class="tw:text-on-surface-variant ui-tabular-nums">{{ __('purchasing.copy.sales_contract_uploaded_at', ['date' => $regionalFormatter->timestamp($po->supplier_sales_contract_uploaded_at, 'datetime')]) }}</div>
                    @endif
                @else
                    <div class="tw:text-on-surface-variant tw:italic tw:mt-1">{{ __('purchasing.copy.sales_contract_not_uploaded') }}</div>
                @endif
            </div>
            <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:p-3">
                <div class="tw:font-semibold tw:text-on-surface">{{ __('purchasing.copy.sales_contract_purchasing_signed_file') }}</div>
                @if($signedFile)
                    <a href="{{ route('attachments.show', $signedFile) }}" target="_blank" rel="noopener" class="tw:inline-flex tw:items-center tw:gap-1 tw:text-primary tw:no-underline tw:font-semibold tw:mt-1">
                        <x-ui.icon name="file-check" size="sm" />
                        <span class="tw:truncate" style="max-width: 18rem;" title="{{ $signedFile->file_name }}">{{ $signedFile->file_name }}</span>
                    </a>
                    @if($po->purchasing_sales_contract_uploaded_at)
                        <div class="tw:text-on-surface-variant ui-tabular-nums">{{ __('purchasing.copy.sales_contract_uploaded_at', ['date' => $regionalFormatter->timestamp($po->purchasing_sales_contract_uploaded_at, 'datetime')]) }}</div>
                    @endif
                @else
                    <div class="tw:text-on-surface-variant tw:italic tw:mt-1">{{ __('purchasing.copy.sales_contract_not_uploaded') }}</div>
                @endif
            </div>
        </div>

        @if($po->sales_contract_notes)
            <div class="tw:mt-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3 tw:text-ui-xs">
                <span class="tw:font-semibold">{{ __('purchasing.copy.sales_contract_notes_prefix') }}</span> {{ $po->sales_contract_notes }}
            </div>
        @endif

        @if($canSupplierUpload)
            <form method="POST" action="{{ route('supplier.purchase-orders.sales-contract.store', $po) }}" enctype="multipart/form-data" data-async-submit class="tw:mt-4 tw:border-t tw:border-outline-variant tw:pt-4 tw:grid tw:gap-3 tw:md:grid-cols-12 tw:items-start">
                @csrf
                <div class="tw:min-w-0 tw:md:col-span-5">
                    <label for="sales_contract_file" class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('purchasing.copy.sales_contract_file_label') }} <span class="tw:text-error" aria-hidden="true">*</span></label>
                    <input type="file" name="sales_contract_file" id="sales_contract_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" aria-describedby="{{ $fileHelpId }}"
                        class="ui-input ui-input--sm @error('sales_contract_file') is-invalid @enderror">
                    <div id="{{ $fileHelpId }}" class="ui-field-help tw:text-ui-xs">{{ __('purchasing.copy.sales_contract_file_help') }}</div>
                    @error('sales_contract_file')<div class="ui-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="tw:min-w-0 tw:md:col-span-4">
                    <label for="sales_contract_notes" class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('purchasing.copy.sales_contract_notes_label') }}</label>
                    <input type="text" name="sales_contract_notes" id="sales_contract_notes" maxlength="2000" value="{{ old('sales_contract_notes') }}" class="ui-input ui-input--sm">
                </div>
                <div class="tw:min-w-0 tw:md:col-span-3">
                    <span class="ui-label tw:text-ui-xs tw:font-semibold tw:hidden tw:md:block tw:invisible" aria-hidden="true">&nbsp;</span>
                    <x-ui.button type="submit" variant="primary" size="sm" class="tw:w-full tw:justify-center">
                        <x-ui.icon name="upload" size="sm" />
                        <span>{{ $scStatus === \App\Enums\PoSalesContractStatus::REVISION_REQUESTED || $supplierFile ? __('purchasing.copy.sales_contract_reupload_supplier') : __('purchasing.copy.sales_contract_upload_supplier') }}</span>
                    </x-ui.button>
                </div>
            </form>
        @endif

        @if($canPurchasingReview)
            <div class="tw:mt-4 tw:border-t tw:border-outline-variant tw:pt-4 tw:grid tw:gap-4 tw:lg:grid-cols-2">
                <form method="POST" action="{{ route('purchasing.purchase-orders.sales-contract.sign', $po) }}" enctype="multipart/form-data" data-async-submit class="tw:grid tw:gap-2">
                    @csrf
                    <label for="signed_sales_contract_file" class="ui-label tw:text-ui-xs tw:font-semibold tw:m-0">{{ __('purchasing.copy.sales_contract_signed_file_label') }} <span class="tw:text-error" aria-hidden="true">*</span></label>
                    <input type="file" name="signed_sales_contract_file" id="signed_sales_contract_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        class="ui-input ui-input--sm @error('signed_sales_contract_file') is-invalid @enderror">
                    @error('signed_sales_contract_file')<div class="ui-field-error">{{ $message }}</div>@enderror
                    <input type="text" name="sales_contract_notes" maxlength="2000" class="ui-input ui-input--sm" placeholder="{{ __('purchasing.copy.sales_contract_notes_label') }}" aria-label="{{ __('purchasing.copy.sales_contract_notes_label') }}">
                    <div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="file-check" size="sm" />
                            <span>{{ __('purchasing.copy.sales_contract_upload_signed') }}</span>
                        </x-ui.button>
                    </div>
                </form>
                <form method="POST" action="{{ route('purchasing.purchase-orders.sales-contract.revision', $po) }}" class="tw:grid tw:gap-2 tw:content-start">
                    @csrf
                    <label for="sales_contract_revision_notes" class="ui-label tw:text-ui-xs tw:font-semibold tw:m-0">{{ __('purchasing.copy.sales_contract_revision_reason_label') }} <span class="tw:text-error" aria-hidden="true">*</span></label>
                    <textarea name="sales_contract_notes" id="sales_contract_revision_notes" rows="2" maxlength="2000" required class="ui-input ui-input--sm @error('sales_contract_notes') is-invalid @enderror">{{ old('sales_contract_notes') }}</textarea>
                    @error('sales_contract_notes')<div class="ui-field-error">{{ $message }}</div>@enderror
                    <div>
                        <x-ui.button type="submit" variant="outline" size="sm">
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>{{ __('purchasing.copy.sales_contract_request_revision') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        @endif

        @if(! $isPurchasingViewer && ! $po->hasSignedSalesContract())
            <x-ui.alert tone="info" class="tw:mt-3">{{ __('purchasing.copy.sales_contract_ack_locked') }}</x-ui.alert>
        @endif
    </x-ui.card>
@endif
