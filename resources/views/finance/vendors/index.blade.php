@extends('layouts.app')
@section('title', __('finance.closure.vendor_master_title'))
@section('page-title', __('local_procurement.labels.vendor_data'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_procurement.labels.vendor_data')"
        :description="__('local_procurement.vendor_ui.index_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Pending Change Requests Card --}}
    @if($pendingRequests->isNotEmpty())
        <x-ui.card
            :title="__('local_procurement.vendor_ui.requests_title')"
            :description="__('local_procurement.vendor_ui.requests_help')"
        >
            <div class="tw-space-y-4">
                @foreach($pendingRequests as $req)
                    <div class="tw-p-4 tw-rounded-ui-sm tw-border tw-border-warning/30 tw-bg-warning-container/20">
                        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                            <div>
                                <div class="tw-flex tw-items-center tw-gap-2">
                                    <strong class="tw-text-ui-sm tw-text-on-surface">
                                        {{ $req->supplier->supplier?->company_name ?: $req->supplier->name }}
                                    </strong>
                                    <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-warning tw-text-warning-foreground">
                                        {{ __('local_procurement.vendor_ui.type', ['type' => match($req->change_type) { 'profile' => __('local_procurement.vendor_ui.change_profile'), 'bank_account' => __('local_procurement.vendor_ui.change_bank'), default => ucwords(str_replace('_', ' ', $req->change_type)) }]) }}
                                    </span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block tw-mt-1">
                                    {{ __('local_procurement.vendor_ui.requested_by_date', ['name' => $req->requester?->name ?? __('local_invoice.labels.supplier'), 'date' => $req->created_at->format('d M Y H:i')]) }}
                                </span>
                            </div>

                            {{-- Approve & Reject Actions --}}
                            <div class="tw-flex tw-items-center tw-gap-2">
                                <form method="POST" action="{{ route('finance.vendor-change-requests.approve', $req) }}" onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: {{ json_encode(__('local_procurement.vendor_ui.approve_title'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, text: {{ json_encode(__('local_procurement.vendor_ui.approve_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, confirmText: {{ json_encode(__('local_procurement.vendor_ui.approve_yes'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, cancelText: {{ json_encode(__('local_invoice.actions.cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}}).then(r => { if (r.isConfirmed) this.submit(); });">
                                    @csrf
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="check" size="sm" />
                                        <span>{{ __('local_procurement.vendor_ui.approve') }}</span>
                                    </x-ui.button>
                                </form>

                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#rejectReqModal-{{ $req->id }}"
                                >
                                    <x-ui.icon name="x" size="sm" />
                                    <span>{{ __('local_procurement.vendor_ui.reject') }}</span>
                                </button>
                            </div>
                        </div>

                        {{-- Proposed changes comparison summary --}}
                        @include('partials.vendor-change-request-details', ['changeRequest' => $req])
                    </div>

                    {{-- Reject Modal --}}
                    <div class="modal fade" id="rejectReqModal-{{ $req->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <form method="POST" action="{{ route('finance.vendor-change-requests.reject', $req) }}">
                                @csrf
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('local_procurement.vendor_ui.reject_title') }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.labels.rejection_required') }} <span class="text-danger">*</span></label>
                                            <textarea name="notes" class="form-control form-control-sm" rows="3" required placeholder="{{ __('local_procurement.vendor_ui.reject_example') }}"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button>
                                        <button type="submit" class="btn btn-danger btn-sm">{{ __('local_procurement.vendor_ui.reject_submit') }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Daftar Master Rekanan Vendor Lokal --}}
    <x-ui.data-table
        :title="__('local_procurement.labels.vendor_register_old')"
        :description="__('local_invoice.labels.company_data')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.vendor_ui.company_vendor') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('common.fields.category') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.tax_status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp.payee_account_active') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.change_details.payment_term') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
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
                                <strong class="tw-text-on-surface">{{ $sup?->company_name ?: $vendorUser->name }}</strong>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $vendorUser->email }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $sup?->vendor_category === 'Barang' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-info/10 tw-text-info' }}">
                                    {{ match($sup?->vendor_category ?? 'Barang') { 'Barang' => __('local_procurement.vendor_ui.goods'), 'Jasa' => __('local_procurement.vendor_ui.services'), 'Lainnya' => __('local_procurement.vendor_ui.other'), default => $sup?->vendor_category } }}
                                </span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $sup?->is_pkp ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-surface-container tw-text-on-surface-variant' }}">
                                    {{ $sup?->is_pkp ? 'PKP' : 'Non-PKP' }}
                                </span>
                            </td>
                            <td>
                                @if($activeBank)
                                    <span class="tw-block tw-text-ui-xs">{{ __('finance.drp_ui.bank_details', ['bank' => $activeBank->bank_name, 'account' => $activeBank->account_number, 'holder' => $activeBank->account_holder_name]) }}</span>
                                @else
                                    <span class="tw-text-ui-xs tw-text-error">{{ __('local_invoice.empty.not_verified') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="tw-font-semibold tw-text-ui-xs">{{ trans_choice('local_invoice.table.term_days', $sup?->payment_term_days ?? 30) }}</span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.vendor-master.show', $vendorUser)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('local_procurement.labels.master_detail') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
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
