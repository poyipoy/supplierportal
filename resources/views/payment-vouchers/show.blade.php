@extends('layouts.app')
@section('title', __('payment_voucher.detail_page_title', ['number' => $voucher->voucher_number]))
@section('page-title', $voucher->voucher_number)

@section('content')
@php
    use App\Models\PaymentVoucher;
    use App\Support\Money;
    use App\Support\StatusHelper;
@endphp

<div class="tw:mx-auto tw:max-w-5xl tw:grid tw:gap-6 tw:pb-12">
    <x-ui.page-header
        :title="$voucher->voucher_number"
        :eyebrow="__('payment_voucher.eyebrow')"
        :description="__('payment_voucher.labels.issued_by', [
            'name' => $voucher->creator?->name ?? '-',
            'date' => $voucher->issued_at ? $regionalFormatter->timestamp($voucher->issued_at) : '-',
        ])"
    >
        <x-slot:status>
            <x-ui.status-chip :tone="StatusHelper::localFinanceTone($voucher->status)">
                {{ StatusHelper::localFinanceLabel($voucher->status) }}
            </x-ui.status-chip>
        </x-slot:status>

        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.print', $voucher)" target="_blank" variant="primary" size="md">
                <x-slot:leading><x-ui.icon name="printer" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.print_pdf') }}
            </x-ui.button>

            <x-ui.button :href="route($routePrefix.'.print', ['paymentVoucher' => $voucher, 'download' => 1])" variant="secondary" size="md" data-download-pdf data-no-loader>
                <x-slot:leading><x-ui.icon name="download" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.download_pdf') }}
            </x-ui.button>

            <x-ui.button :href="route($routePrefix.'.create', ['duplicate_from' => $voucher->hash])" variant="outline" size="sm">
                <x-slot:leading><x-ui.icon name="copy" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.duplicate') }}
            </x-ui.button>

            <x-ui.button :href="route($routePrefix.'.index')" variant="ghost" size="sm">
                <x-slot:leading><x-ui.icon name="arrow-left" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.back_list') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($voucher->isCancelled())
        <x-ui.alert tone="error" :title="__('payment_voucher.labels.voided_banner')">
            <div class="tw:grid tw:gap-1 tw:mt-1">
                <div>
                    <strong>{{ __('payment_voucher.fields.void_reason') }}:</strong>
                    {{ $voucher->void_reason }}
                </div>
                <div class="tw:text-ui-xs">
                    {{ __('payment_voucher.labels.voided_by', [
                        'name' => $voucher->voider?->name ?? '-',
                        'date' => $voucher->voided_at ? $regionalFormatter->timestamp($voucher->voided_at) : '-',
                    ]) }}
                </div>
            </div>
        </x-ui.alert>
    @endif

    <div class="tw:grid tw:grid-cols-1 tw:lg:grid-cols-3 tw:gap-6">
        <div class="tw:lg:col-span-2 tw:grid tw:gap-6">
            {{-- Voucher Summary & Purpose --}}
            <x-ui.card :title="$voucher->payee_name_snapshot" :description="PaymentVoucher::payeeTypeLabel($voucher->payee_type)">
                <dl class="tw:m-0 tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:gap-x-6 tw:gap-y-4 tw:text-ui-sm">
                    <div>
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.labels.department') }}</dt>
                        <dd class="tw:m-0 tw:mt-0.5 tw:font-semibold tw:text-on-surface">{{ PaymentVoucher::departmentLabel($voucher->source_department) }}</dd>
                    </div>
                    <div>
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.labels.voucher_date') }}</dt>
                        <dd class="tw:m-0 tw:mt-0.5 tw:font-semibold tw:text-on-surface">{{ $regionalFormatter->date($voucher->voucher_date, 'human') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.labels.payment_method') }}</dt>
                        <dd class="tw:m-0 tw:mt-0.5 tw:font-semibold tw:text-on-surface">
                            {{ $voucher->isBankMethod() ? __('payment_voucher.methods.bank') : __('payment_voucher.methods.kas') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.fields.reference_number') }}</dt>
                        <dd class="tw:m-0 tw:mt-0.5 ui-tabular-nums tw:font-medium tw:text-on-surface">{{ $voucher->reference_number ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">NPWP</dt>
                        <dd class="tw:m-0 tw:mt-0.5 ui-tabular-nums tw:text-on-surface">{{ $voucher->npwp_snapshot ?: '-' }}</dd>
                    </div>
                    @if($voucher->payee_department_snapshot)
                        <div>
                            <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.labels.department') }} ({{ __('payment_voucher.payee_types.employee') }})</dt>
                            <dd class="tw:m-0 tw:mt-0.5 tw:text-on-surface">{{ $voucher->payee_department_snapshot }}</dd>
                        </div>
                    @endif
                    <div class="tw:sm:col-span-2 tw:border-t tw:border-outline-variant tw:pt-3">
                        <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.fields.description') }}</dt>
                        <dd class="tw:m-0 tw:mt-1 tw:text-on-surface tw:whitespace-pre-line">{{ $voucher->description }}</dd>
                    </div>
                    @if($voucher->notes)
                        <div class="tw:sm:col-span-2">
                            <dt class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.fields.notes') }}</dt>
                            <dd class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant tw:whitespace-pre-line">{{ $voucher->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            {{-- Authoritative Monetary Breakdown --}}
            <x-ui.card :title="__('payment_voucher.sections.amounts')">
                <div class="tw:grid tw:gap-2.5 tw:text-ui-sm">
                    <div class="tw:flex tw:items-center tw:justify-between tw:gap-4">
                        <span class="tw:text-on-surface-variant">{{ __('payment_voucher.fields.dpp_amount') }}</span>
                        <span class="ui-tabular-nums tw:font-semibold tw:text-on-surface">Rp {{ $regionalFormatter->money($voucher->dpp_amount, 2) }}</span>
                    </div>

                    @if(Money::compare((string) $voucher->ppn_amount, Money::ZERO) > 0)
                        <div class="tw:flex tw:items-center tw:justify-between tw:gap-4">
                            <span class="tw:text-on-surface-variant">{{ __('payment_voucher.fields.ppn_amount') }}</span>
                            <span class="ui-tabular-nums tw:text-on-surface">+ Rp {{ $regionalFormatter->money($voucher->ppn_amount, 2) }}</span>
                        </div>
                    @endif

                    @if(Money::compare((string) $voucher->pph_amount, Money::ZERO) > 0)
                        <div class="tw:flex tw:items-center tw:justify-between tw:gap-4">
                            <span class="tw:text-on-surface-variant">{{ __('payment_voucher.fields.pph_amount') }}</span>
                            <span class="ui-tabular-nums tw:text-error">- Rp {{ $regionalFormatter->money($voucher->pph_amount, 2) }}</span>
                        </div>
                    @endif

                    @if(Money::compare((string) $voucher->bank_fee, Money::ZERO) > 0)
                        <div class="tw:flex tw:items-center tw:justify-between tw:gap-4">
                            <span class="tw:text-on-surface-variant">{{ __('payment_voucher.fields.bank_fee') }}</span>
                            <span class="ui-tabular-nums tw:text-error">- Rp {{ $regionalFormatter->money($voucher->bank_fee, 2) }}</span>
                        </div>
                    @endif

                    <div class="tw:flex tw:items-center tw:justify-between tw:gap-4 tw:border-t tw:border-outline-variant tw:pt-3">
                        <span class="tw:font-bold tw:text-on-surface">{{ __('payment_voucher.labels.net_amount') }}</span>
                        <span class="ui-tabular-nums tw:text-lg tw:font-bold tw:text-primary">Rp {{ $regionalFormatter->money($voucher->net_amount, 2) }}</span>
                    </div>

                    <div class="tw:rounded-ui-sm tw:bg-surface-container tw:p-3 tw:text-ui-xs">
                        <span class="tw:font-medium tw:text-on-surface-variant">{{ __('payment_voucher.labels.amount_words') }}:</span>
                        <span class="tw:font-semibold tw:text-on-surface">{{ $voucher->terbilang_snapshot }}</span>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="tw:grid tw:gap-6 tw:content-start">
            {{-- Destination Bank Snapshot --}}
            <x-ui.card :title="__('payment_voucher.labels.bank_snapshot_card')">
                @if($voucher->isBankMethod())
                    <dl class="tw:m-0 tw:grid tw:gap-3 tw:text-ui-sm">
                        <div>
                            <dt class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('payment_voucher.fields.bank_name') }}</dt>
                            <dd class="tw:m-0 tw:mt-0.5 tw:font-semibold tw:text-on-surface">{{ $voucher->bank_name_snapshot }}</dd>
                        </div>
                        <div>
                            <dt class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('payment_voucher.fields.account_number') }}</dt>
                            <dd class="tw:m-0 tw:mt-0.5 ui-tabular-nums tw:font-bold tw:text-on-surface">{{ $voucher->bank_account_snapshot }}</dd>
                        </div>
                        <div>
                            <dt class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('payment_voucher.fields.account_holder') }}</dt>
                            <dd class="tw:m-0 tw:mt-0.5 tw:font-medium tw:text-on-surface">{{ $voucher->bank_account_holder_snapshot }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                        {{ __('payment_voucher.labels.kas_no_bank_notice') }}
                    </p>
                @endif
            </x-ui.card>

            {{-- Supporting Documents --}}
            <x-ui.card :title="__('payment_voucher.labels.supporting_documents')">
                @if($voucher->documents->isEmpty())
                    <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('payment_voucher.labels.no_documents') }}</p>
                @else
                    <ul class="tw:m-0 tw:list-none tw:grid tw:gap-2 tw:p-0">
                        @foreach($voucher->documents as $document)
                            <li class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:p-2.5">
                                <div class="tw:min-w-0 tw:flex-1">
                                    <div class="tw:truncate tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                                        {{ $document->original_filename ?: basename($document->file_path) }}
                                    </div>
                                    <div class="tw:text-[11px] tw:text-on-surface-variant">
                                        {{ number_format(((int) $document->file_size) / 1024, 1) }} KB
                                    </div>
                                </div>
                                <x-ui.button :href="route($routePrefix.'.documents.show', [$voucher, $document])" size="sm" variant="outline">
                                    <x-slot:leading><x-ui.icon name="download" size="sm" /></x-slot:leading>
                                    {{ __('payment_voucher.actions.download_document') }}
                                </x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Void Action Card (Department-scoped via PaymentVoucherPolicy) --}}
            @can('void', $voucher)
                <x-ui.card :title="__('payment_voucher.labels.audit_card')">
                    <div x-data="{ voidOpen: {{ $errors->has('void_reason') ? 'true' : 'false' }} }" class="tw:grid tw:gap-3">
                        <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                            {{ __('payment_voucher.labels.void_help') }}
                        </p>

                        <div x-show="!voidOpen">
                            <x-ui.button type="button" variant="danger" size="sm" @click="voidOpen = true" aria-controls="pv-void-panel" ::aria-expanded="voidOpen">
                                <x-slot:leading><x-ui.icon name="ban" size="sm" /></x-slot:leading>
                                {{ __('payment_voucher.actions.void') }}
                            </x-ui.button>
                        </div>

                        <form
                            id="pv-void-panel"
                            method="POST"
                            action="{{ route($routePrefix.'.void', $voucher) }}"
                            x-show="voidOpen"
                            x-cloak
                            class="tw:grid tw:gap-3 tw:border-t tw:border-outline-variant tw:pt-3"
                        >
                            @csrf
                            <x-ui.textarea
                                name="void_reason"
                                :label="__('payment_voucher.fields.void_reason')"
                                :placeholder="__('payment_voucher.fields.void_reason_placeholder')"
                                rows="2"
                                maxlength="500"
                                required
                            />
                            <div class="tw:flex tw:items-center tw:justify-end tw:gap-2">
                                <x-ui.button type="button" size="sm" variant="ghost" @click="voidOpen = false">
                                    {{ __('payment_voucher.actions.cancel') }}
                                </x-ui.button>
                                <x-ui.button type="submit" size="sm" variant="danger">
                                    {{ __('payment_voucher.actions.confirm_void') }}
                                </x-ui.button>
                            </div>
                        </form>
                    </div>
                </x-ui.card>
            @endcan
        </div>
    </div>
</div>
@endsection

