@props(['changeRequest'])
@php
    $proposal = $changeRequest->proposed_data ?? [];
    $hasBank = ! empty($proposal['bank_name']) || ! empty($proposal['account_number']) || ! empty($proposal['account_holder_name']);
    $bankDocs = $changeRequest->supplier?->supplier?->masterDocuments
        ?->where('document_type', \App\Models\SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING) ?? collect();
    $bankDocument = $bankDocs->firstWhere('id', $proposal['bank_confirmation_document_id'] ?? 0);
@endphp
@if($hasBank)
    <div class="tw:mt-2 tw:text-ui-xs">
        <p class="tw:m-0 tw:font-semibold">{{ __('finance.drp_ui.bank_details', ['bank' => $proposal['bank_name'] ?? '-', 'account' => $proposal['account_number'] ?? '-', 'holder' => $proposal['account_holder_name'] ?? '-']) }}</p>
        @if($bankDocument)
            <a href="{{ route('supplier-master-documents.show', $bankDocument) }}" target="_blank" rel="noopener" class="tw:text-primary tw:underline tw:inline-flex tw:items-center tw:gap-1 tw:mt-0.5">
                <x-ui.icon name="file-text" size="xs" />
                <span>{{ __('finance_controls.bank_document_label') }}: {{ $bankDocument->original_filename }}</span>
            </a>
        @elseif($bankDocs->isNotEmpty())
            <span class="tw:inline-flex tw:items-center tw:gap-1 tw:text-warning tw:font-medium tw:mt-0.5">
                <x-ui.icon name="alert-triangle" size="xs" />
                <span>{{ __('finance_controls.bank_document_select_for_approval') }}</span>
            </span>
        @else
            <span class="tw:inline-flex tw:items-center tw:gap-1 tw:text-error tw:font-medium tw:mt-0.5">
                <x-ui.icon name="alert-circle" size="xs" />
                <span>{{ __('finance_controls.bank_document_not_uploaded') }}</span>
            </span>
        @endif
    </div>
@endif
