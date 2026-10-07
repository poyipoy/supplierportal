@extends('layouts.app')
@section('title', __('local_procurement.vendor_ui.detail_title', ['company' => $vendor->supplier?->company_name ?: $vendor->name]).' - '.__('finance.labels.finance_ap'))
@section('page-title', __('local_procurement.labels.vendor_master_detail'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$vendor->supplier?->company_name ?: $vendor->name"
        :description="__('common.closure.email_tax', ['email' => $vendor->email, 'tax' => $vendor->supplier?->npwp ?? '—'])"
        :eyebrow="__('finance.copy_review.vendor_v2')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.vendor-master.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_procurement.vendor_ui.back_master') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php $sup = $vendor->supplier; @endphp

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left: Vendor Profile & Bank Accounts --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Profile Card --}}
            <x-ui.card :title="__('local_invoice.labels.company_profile')">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.change_details.company') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $sup?->company_name ?: $vendor->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.vendor_category') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-primary">{{ match($sup?->vendor_category ?? 'Barang') { 'Barang' => __('local_procurement.vendor_ui.goods'), 'Jasa' => __('local_procurement.vendor_ui.services'), 'Lainnya' => __('local_procurement.vendor_ui.other'), default => $sup?->vendor_category } }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.tax_status') }}:</span>
                        <strong class="tw-text-ui-sm {{ $sup?->is_pkp ? 'tw-text-success' : 'tw-text-on-surface' }}">
                            {{ $sup?->is_pkp ? __('local_procurement.vendor_ui.pkp_required') : 'Non-PKP' }}
                        </strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('finance.copy_review.npwp_number') }}</span>
                        <span class="tw-font-mono tw-text-ui-xs">{{ $sup?->npwp ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_phone') }}:</span>
                        <span>{{ $sup?->phone ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.default_term') }}</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ trans_choice('local_invoice.table.term_days', $sup?->payment_term_days ?? 30) }}</strong>
                    </div>
                    <div class="tw-col-span-full">
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_address') }}:</span>
                        <span>{{ $sup?->address ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.change_details.pic') }}:</span>
                        <span>{{ $sup?->pic_name ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.pic_email') }}:</span>
                        <span>{{ $sup?->pic_email ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.pic_phone') }}:</span>
                        <span>{{ $sup?->pic_phone ?? '—' }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Bank Accounts History Card (Requirement 5) --}}
            <x-ui.card
                :title="__('local_invoice.labels.bank_history')"
                :description="__('local_procurement.vendor_ui.bank_history_help')"
            >
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('local_procurement.change_details.bank') }}</th>
                                <th scope="col">{{ __('ga.detail.bank_number') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.account_name') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.verification_status') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.active') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.verified_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sup?->bankAccounts ?? [] as $b)
                                <tr>
                                    <td><strong class="tw-text-on-surface">{{ $b->bank_name }}</strong></td>
                                    <td><span class="tw-font-mono">{{ $b->account_number }}</span></td>
                                    <td>{{ $b->account_holder_name }}</td>
                                    <td>
                                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($b->status)">
                                            {{ \App\Support\StatusHelper::localFinanceLabel($b->status) }}
                                        </x-ui.status-chip>
                                    </td>
                                    <td>
                                        @if($b->status === 'VERIFIED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary tw-font-bold">{{ __('local_invoice.labels.active') }}</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">{{ __('local_procurement.vendor_ui.inactive') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $b->verified_at?->format('d M Y H:i') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center tw-py-4 tw-text-on-surface-variant">{{ __('local_invoice.empty.bank') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            {{-- Change Request History --}}
            <x-ui.card
                :title="__('local_invoice.labels.change_history')"
                :description="__('local_procurement.vendor_ui.history_help')"
            >
                <div class="tw-space-y-3">
                    @forelse($sup?->changeRequests ?? [] as $cr)
                        <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-text-ui-xs">
                            <div class="tw-flex tw-justify-between tw-mb-1">
                                <strong>{{ __('local_procurement.vendor_ui.type', ['type' => match($cr->change_type) { 'profile' => __('local_procurement.vendor_ui.change_profile'), 'bank_account' => __('local_procurement.vendor_ui.change_bank'), default => ucwords(str_replace('_', ' ', $cr->change_type)) }]) }}</strong>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($cr->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($cr->status) }}
                                </x-ui.status-chip>
                            </div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.submitted_at', ['date' => $cr->created_at->format('d M Y H:i')]) }}</span>
                            @if($cr->review_notes)
                                <div class="tw-mt-1 tw-text-[11px] tw-italic">{{ __('local_procurement.vendor_ui.reviewer_notes', ['notes' => $cr->review_notes]) }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_invoice.empty.change_history') }}</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Right: Vendor Master Documents (Requirement 6) --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            <x-ui.card
                :title="__('local_invoice.labels.legal_master')"
                :description="__('local_procurement.vendor_ui.legal_help')"
            >
                <div class="tw-space-y-3">
                    @forelse($sup?->masterDocuments ?? [] as $md)
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-items-center tw-justify-between">
                            <div>
                                <strong class="tw-text-ui-xs tw-text-on-surface tw-block">{{ __('local_procurement.vendor_ui.document_types.'.strtolower($md->document_type)) }}</strong>
                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ $md->original_filename }}</span>
                            </div>
                            <a href="{{ route('supplier-master-documents.show', $md) }}" target="_blank" class="btn btn-xs btn-outline-primary">{{ __('local_invoice.actions.download') }}</a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('local_invoice.empty.legal_upload') }}
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
