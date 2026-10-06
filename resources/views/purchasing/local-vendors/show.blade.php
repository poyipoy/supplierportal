@extends('layouts.app')
@section('title', __('purchasing.closure.vendor_title', ['name' => $vendor->supplier?->company_name ?: $vendor->name]))
@section('page-title', __('local_procurement.labels.vendor_detail'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$vendor->supplier?->company_name ?: $vendor->name"
        :description="__('common.closure.email_tax', ['email' => $vendor->email, 'tax' => $vendor->supplier?->npwp ?? '—'])"
        :eyebrow="__('finance.copy_review.purchasing')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.local-vendors.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php $sup = $vendor->supplier; @endphp

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card :title="__('local_invoice.labels.company_profile')">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.company') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $sup?->company_name ?: $vendor->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.vendor_category') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-primary">{{ match($sup?->vendor_category ?? 'Barang') { 'Barang' => __('local_procurement.vendor_ui.goods'), 'Jasa' => __('local_procurement.vendor_ui.services'), 'Lainnya' => __('local_procurement.vendor_ui.other'), default => $sup?->vendor_category } }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.tax_status') }}:</span>
                        <strong class="tw-text-ui-sm {{ $sup?->is_pkp ? 'tw-text-success' : 'tw-text-on-surface' }}">
                            {{ $sup?->is_pkp ? 'PKP' : 'Non-PKP' }}
                        </strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">NPWP:</span>
                        <span class="tw-font-mono">{{ $sup?->npwp ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_phone') }}:</span>
                        <span>{{ $sup?->phone ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.payment_term') }}</span>
                        <strong>{{ trans_choice('local_invoice.table.term_days', $sup?->payment_term_days ?? 30) }}</strong>
                    </div>
                    <div class="tw-col-span-full">
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_address') }}:</span>
                        <span>{{ $sup?->address ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.pic_name') }}:</span>
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

            {{-- Bank Accounts History --}}
            <x-ui.card :title="__('local_invoice.labels.bank_accounts')">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('local_procurement.vendor_ui.bank_name') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.bank_number') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.account_name') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                                <th scope="col">{{ __('ga.labels.active_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sup?->bankAccounts ?? [] as $b)
                                <tr>
                                    <td><strong class="tw-text-on-surface">{{ $b->bank_name }}</strong></td>
                                    <td><span class="tw-font-mono">{{ $b->account_number }}</span></td>
                                    <td>{{ $b->account_holder_name }}</td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] {{ $b->status === 'VERIFIED' ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-warning/10 tw-text-warning' }}">
                                            {{ \App\Support\StatusHelper::localFinanceLabel($b->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($b->status === 'VERIFIED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary tw-font-bold">{{ __('local_invoice.labels.active') }}</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">{{ __('local_procurement.vendor_ui.inactive') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center tw-py-4 tw-text-on-surface-variant">{{ __('local_invoice.empty.bank') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <div class="lg:tw-col-span-4 tw-space-y-6">
            <x-ui.card :title="__('local_invoice.labels.legal_master')">
                <div class="tw-space-y-3">
                    @forelse($sup?->masterDocuments ?? [] as $md)
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-items-center tw-justify-between">
                            <div>
                                <strong class="tw-text-ui-xs tw-text-on-surface tw-block">{{ ucwords(str_replace('_', ' ', $md->document_type)) }}</strong>
                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ $md->original_filename }}</span>
                            </div>
                            <a href="{{ route('supplier-master-documents.show', $md) }}" target="_blank" class="btn btn-xs btn-outline-primary">{{ __('local_invoice.actions.download') }}</a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('local_invoice.empty.legal') }}
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
