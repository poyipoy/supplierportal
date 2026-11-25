@extends('layouts.app')
@section('title', __('purchasing.closure.vendor_list_title'))
@section('page-title', __('local_procurement.labels.vendor_approval'))

@section('content')
<div class="tw:grid tw:gap-6 tw:pb-16">
    <x-ui.page-header
        :title="__('finance.copy_review.vendor_master')"
        :description="__('local_procurement.labels.vendor_help')"
        :eyebrow="__('finance.copy_review.purchasing')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Pending Change Requests --}}
    @if($pendingRequests->isNotEmpty())
        <x-ui.card
            :title="__('common.final_review.pending_vendor_changes')"
            :description="__('finance.copy_review.purchasing_vendor_help')"
        >
            <div class="tw:space-y-4">
                @foreach($pendingRequests as $req)
                    <div class="tw:p-4 tw:rounded-ui-sm tw:border tw:border-warning/30 tw:bg-warning-container/20">
                        <div class="tw:flex tw:flex-col tw:sm:flex-row tw:sm:items-center tw:sm:justify-between tw:gap-3">
                            <div>
                                <div class="tw:flex tw:items-center tw:gap-2">
                                    <strong class="tw:text-ui-sm tw:text-on-surface">
                                        {{ $req->supplier->supplier?->company_name ?: $req->supplier->name }}
                                    </strong>
                                    <span class="tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[11px] tw:font-semibold tw:bg-warning tw:text-warning-foreground">
                                        {{ __('local_procurement.vendor_ui.type', ['type' => match($req->change_type) { 'profile' => __('local_procurement.vendor_ui.change_profile'), 'bank_account' => __('local_procurement.vendor_ui.change_bank'), default => ucwords(str_replace('_', ' ', $req->change_type)) }]) }}
                                    </span>
                                </div>
                                <span class="tw:text-ui-xs tw:text-on-surface-variant tw:block tw:mt-1">
                                    {{ __('local_procurement.vendor_ui.submitted_at', ['date' => $regionalFormatter->timestamp($req->created_at, 'datetime')]) }}
                                </span>
                            </div>

                            @php
                                $proposal = $req->proposed_data ?? [];
                                $hasBankChange = ! empty($proposal['bank_name']) || ! empty($proposal['account_number']) || ! empty($proposal['account_holder_name']);
                                $supplierBankDocs = $req->supplier?->supplier?->masterDocuments
                                    ?->where('document_type', \App\Models\SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING) ?? collect();
                                $hasValidDocAttached = $supplierBankDocs->contains(fn ($d) => (int) $d->id === (int) ($proposal['bank_confirmation_document_id'] ?? 0));
                                $canApproveDirectly = ! $hasBankChange || $hasValidDocAttached;
                                $canSelectDocToApprove = $hasBankChange && ! $hasValidDocAttached && $supplierBankDocs->isNotEmpty();
                                $isBlockedNoDoc = $hasBankChange && ! $hasValidDocAttached && $supplierBankDocs->isEmpty();
                            @endphp

                            <x-finance.vendor-bank-proposal :change-request="$req" />
                            <div class="tw:flex tw:items-center tw:gap-2">
                                @if($canApproveDirectly)
                                    <form method="POST" action="{{ route('purchasing.local-vendors.change-requests.approve', $req) }}" onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: {{ json_encode(__('local_procurement.vendor_ui.approve_title'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, text: {{ json_encode(__('local_procurement.vendor_ui.approve_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, confirmText: {{ json_encode(__('local_procurement.vendor_ui.approve_yes'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, cancelText: {{ json_encode(__('local_invoice.actions.cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}}).then(r => { if (r.isConfirmed) this.submit(); });">
                                        @csrf
                                        <x-ui.button type="submit" variant="primary" size="sm">
                                            <x-ui.icon name="check" size="sm" />
                                            <span>{{ __('local_procurement.vendor_ui.approve') }}</span>
                                        </x-ui.button>
                                    </form>
                                @elseif($canSelectDocToApprove)
                                    <x-ui.button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        data-ui-modal-open="#approveReqModal-{{ $req->id }}"
                                    >
                                        <x-ui.icon name="check" size="sm" />
                                        <span>{{ __('local_procurement.vendor_ui.approve') }}</span>
                                    </x-ui.button>
                                @else
                                    <x-ui.button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        disabled
                                        title="{{ __('finance_controls.bank_document_missing_for_approval') }}"
                                    >
                                        <x-ui.icon name="check" size="sm" />
                                        <span>{{ __('local_procurement.vendor_ui.approve') }}</span>
                                    </x-ui.button>
                                @endif

                                <x-ui.button
                                    type="button"
                                    variant="danger-outline"
                                    size="sm"
                                    data-ui-modal-open="#rejectReqModal-{{ $req->id }}"
                                >
                                    <x-ui.icon name="x" size="sm" />
                                    <span>{{ __('local_procurement.vendor_ui.reject') }}</span>
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Proposed changes comparison summary --}}
                        @include('partials.vendor-change-request-details', ['changeRequest' => $req])
                    </div>

                    @if($canSelectDocToApprove)
                        {{-- Approve with Document Selection Modal --}}
                        <dialog class="ui-native-dialog" id="approveReqModal-{{ $req->id }}" aria-labelledby="approveReqModalTitle-{{ $req->id }}" closedby="any">
                            <div class="modal-dialog">
                                <form method="POST" action="{{ route('purchasing.local-vendors.change-requests.approve', $req) }}">
                                    @csrf
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 id="approveReqModalTitle-{{ $req->id }}" class="modal-title tw:text-ui-sm tw:font-bold">{{ __('local_procurement.vendor_ui.approve_title') }}</h5>
                                            <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                        </div>
                                        <div class="modal-body tw:space-y-3">
                                            <div class="tw:p-2.5 tw:rounded-sm tw:bg-info/10 tw:border tw:border-info/20 tw:text-info tw:text-ui-xs">
                                                {{ __('finance_controls.bank_document_select_for_approval') }}
                                            </div>
                                            <div>
                                                <label class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('finance_controls.bank_document_label') }} <span class="tw:text-error">*</span></label>
                                                <select name="bank_confirmation_document_id" class="ui-select ui-select--sm" required>
                                                    <option value="">{{ __('local_procurement.vendor_ui.document_select') }}</option>
                                                    @foreach($supplierBankDocs as $doc)
                                                        <option value="{{ $doc->id }}">{{ $doc->original_filename }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.labels.review_notes') }}</label>
                                                <textarea name="notes" class="ui-textarea tw:text-ui-xs tw:px-2 tw:py-1" rows="2" placeholder="{{ __('local_procurement.vendor_ui.approve_help') }}"></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                                            <x-ui.button type="submit" variant="primary" size="sm">{{ __('local_procurement.vendor_ui.approve') }}</x-ui.button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </dialog>
                    @endif

                    {{-- Reject Modal --}}
                    <dialog class="ui-native-dialog" id="rejectReqModal-{{ $req->id }}" aria-labelledby="rejectReqModalTitle-{{ $req->id }}" closedby="any">
                        <div class="modal-dialog">
                            <form method="POST" action="{{ route('purchasing.local-vendors.change-requests.reject', $req) }}">
                                @csrf
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 id="rejectReqModalTitle-{{ $req->id }}" class="modal-title tw:text-ui-sm tw:font-bold">{{ __('local_procurement.vendor_ui.reject_title') }}</h5>
                                        <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="tw:mb-3">
                                            <label class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('finance.labels.rejection_required') }} <span class="tw:text-error">*</span></label>
                                            <textarea name="notes" class="ui-textarea tw:text-ui-xs tw:px-2 tw:py-1" rows="3" required placeholder="{{ __('finance.copy_review.vendor_reject') }}"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('local_procurement.vendor_ui.reject_submit') }}</x-ui.button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </dialog>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Suppliers Table --}}
    <x-ui.data-table
        :title="__('local_procurement.labels.vendor_register')"
        :description="__('local_procurement.labels.vendor_list_help')"
    >
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-sm tw:w-full">
                <thead>
                    <tr>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.vendor_ui.company_vendor') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('common.fields.category') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('finance.copy_review.pkp_status') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('finance.copy_review.active_account') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.change_details.payment_term') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($vendors as $vendorUser)
                        @php
                            $sup = $vendorUser->supplier;
                            $activeBank = $sup?->activeBankAccount;
                        @endphp
                        <tr>
                            <td>
                                <strong class="tw:text-on-surface">{{ $sup?->company_name ?: $vendorUser->name }}</strong>
                                <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ $vendorUser->email }}</span>
                            </td>
                            <td>
                                <span class="tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[11px] tw:font-semibold {{ $sup?->vendor_category === 'Barang' ? 'tw:bg-primary/10 tw:text-primary' : 'tw:bg-info/10 tw:text-info' }}">
                                    {{ match($sup?->vendor_category ?? 'Barang') { 'Barang' => __('local_procurement.vendor_ui.goods'), 'Jasa' => __('local_procurement.vendor_ui.services'), 'Lainnya' => __('local_procurement.vendor_ui.other'), default => $sup?->vendor_category } }}
                                </span>
                            </td>
                            <td>
                                <span class="tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[11px] tw:font-semibold {{ $sup?->is_pkp ? 'tw:bg-success/10 tw:text-success' : 'tw:bg-surface-container tw:text-on-surface-variant' }}">
                                    {{ $sup?->is_pkp ? 'PKP' : 'Non-PKP' }}
                                </span>
                            </td>
                            <td>
                                @if($activeBank)
                                    <span class="tw:block tw:text-ui-xs">{{ __('finance.drp_ui.bank_details', ['bank' => $activeBank->bank_name, 'account' => $activeBank->account_number, 'holder' => $activeBank->account_holder_name]) }}</span>
                                @else
                                    <span class="tw:text-ui-xs tw:text-error">{{ __('local_invoice.empty.not_verified') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="tw:font-semibold tw:text-ui-xs">{{ trans_choice('local_invoice.table.term_days', $sup?->payment_term_days ?? 30) }}</span>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route('purchasing.local-vendors.show', $vendorUser)"
                                    :context="$sup?->company_name ?: $vendorUser->name"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tw:text-center tw:py-8 tw:text-on-surface-variant tw:text-ui-sm">
                                {{ __('local_procurement.vendor_ui.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($vendors->hasPages())
            <x-slot:pagination>
                {{ $vendors->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
