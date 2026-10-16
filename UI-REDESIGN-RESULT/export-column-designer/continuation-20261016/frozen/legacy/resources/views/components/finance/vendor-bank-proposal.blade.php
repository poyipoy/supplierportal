@props(['changeRequest'])
@php
    $proposal = $changeRequest->proposed_data ?? [];
    $bankDocument = $changeRequest->supplier?->supplier?->masterDocuments
        ?->firstWhere('id', $proposal['bank_confirmation_document_id'] ?? 0);
@endphp
@if(isset($proposal['bank_name']) || isset($proposal['account_number']) || isset($proposal['account_holder_name']))
    <div class="tw-mt-2 tw-text-ui-xs">
        <p class="tw-m-0">{{ __('finance.drp_ui.bank_details', ['bank' => $proposal['bank_name'] ?? '-', 'account' => $proposal['account_number'] ?? '-', 'holder' => $proposal['account_holder_name'] ?? '-']) }}</p>
        @if($bankDocument)
            <a href="{{ route('supplier-master-documents.show', $bankDocument) }}" target="_blank" rel="noopener" class="tw-text-primary tw-underline">
                {{ __('finance_controls.bank_document_label') }}: {{ $bankDocument->original_filename }}
            </a>
        @endif
    </div>
@endif
