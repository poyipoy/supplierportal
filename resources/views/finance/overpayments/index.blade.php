@extends('layouts.app')
@section('title', __('finance.refund_ui.page_title'))
@section('page-title', __('finance.refund.register'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.refund.register')"
        :description="__('finance.refund_surface.overpayment_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    />

    <x-ui.card :title="__('finance.refund_surface.filter_title')">
        <form method="GET" class="tw-grid tw-gap-3 md:tw-grid-cols-2 lg:tw-grid-cols-5 lg:tw-items-end">
            <div>
                <label for="overpayment-q" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.refund_ui.search_label') }}</label>
                <input id="overpayment-q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="{{ __('finance.refund_surface.search') }}" maxlength="100">
            </div>
            <div>
                <label for="overpayment-supplier" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.supplier') }}</label>
                <select id="overpayment-supplier" name="supplier_id" class="form-select form-select-sm">
                    <option value="">{{ __('local_invoice.labels.all_suppliers') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>{{ $supplier->supplier?->company_name ?: $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="overpayment-status" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.status') }}</label>
                <select id="overpayment-status" name="status" class="form-select form-select-sm">
                    <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                    <option value="OPEN" @selected(request('status') === 'OPEN')>{{ __('status.finance.open') }}</option>
                    <option value="SETTLED" @selected(request('status') === 'SETTLED')>{{ __('status.finance.settled') }}</option>
                </select>
            </div>
            <div class="lg:tw-col-span-2">
                <x-ui.date-range-picker
                    id="overpayment-date-range"
                    start-name="date_from"
                    end-name="date_to"
                    start-label="{{ __('finance.refund_surface.created_from') }}"
                    end-label="{{ __('finance.refund_surface.created_to') }}"
                    :start-value="request('date_from')"
                    :end-value="request('date_to')"
                    :compact="true"
                />
            </div>
            <div class="md:tw-col-span-2 lg:tw-col-span-5 tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <x-ui.button type="submit" size="sm">{{ __('local_invoice.actions.apply_filters') }}</x-ui.button>
                <x-ui.button :href="route('finance.overpayments.index')" variant="ghost" size="sm">{{ __('common.actions.reset') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.data-table :title="__('finance.refund.receivable')">
        <div class="table-responsive">
            <table class="table align-middle tw-text-ui-sm">
                <thead>
                    <tr>
                        <th>{{ __('local_invoice.labels.invoice') }}</th>
                        <th>{{ __('local_invoice.labels.supplier') }}</th>
                        <th>{{ __('finance.refund_ui.reference') }}</th>
                        <th class="text-end">{{ __('finance.payment.expected_actual') }}</th>
                        <th class="text-end">{{ __('finance.refund_ui.overpayment') }}</th>
                        <th>{{ __('finance.refund_ui.status_date') }}</th>
                        <th class="text-end" style="min-width: 150px;">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overpayments as $refund)
                        <tr>
                            <td class="tw-font-medium">{{ $refund->invoice->invoice_number }}</td>
                            <td>{{ $refund->supplier->supplier?->company_name ?: $refund->supplier->name }}</td>
                            <td>
                                @forelse($refund->payment?->transfers ?? [] as $transfer)
                                    <span class="tw-block tw-font-mono tw-text-ui-xs">{{ $transfer->transfer_reference }}</span>
                                @empty
                                    <span class="tw-text-on-surface-variant">—</span>
                                @endforelse
                            </td>
                            <td class="text-end tw-font-mono">Rp {{ number_format($refund->payment?->expected_amount ?? 0, 2, ',', '.') }} / Rp {{ number_format($refund->payment?->actual_paid_total ?? 0, 2, ',', '.') }}</td>
                            <td class="text-end tw-font-mono tw-font-semibold">Rp {{ number_format($refund->overpayment_amount, 2, ',', '.') }}</td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($refund->status)">{{ \App\Support\StatusHelper::localFinanceLabel($refund->status) }}</x-ui.status-chip>
                                <span class="tw-mt-1 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $refund->status === 'SETTLED' ? $regionalFormatter->date($refund->refund_date, 'human') : $regionalFormatter->date($refund->created_at, 'human') }}</span>
                            </td>
                            <td class="text-end">
                                @if($refund->status === 'OPEN')
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        variant="primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#refundModal-{{ $refund->id }}"
                                        class="tw-whitespace-nowrap"
                                    >
                                        <x-ui.icon name="hand-coins" size="sm" />
                                        <span>{{ __('finance.refund_ui.process') }}</span>
                                    </x-ui.button>
                                @else
                                    <div class="tw-flex tw-flex-col tw-items-end tw-gap-1">
                                        <span class="tw-font-mono tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            {{ $refund->refund_reference }}
                                        </span>
                                        @if($refund->attachments->first())
                                            <x-ui.button
                                                :href="route('attachments.show', $refund->attachments->first())"
                                                variant="outline"
                                                size="sm"
                                                target="_blank"
                                                class="tw-whitespace-nowrap"
                                            >
                                                <x-ui.icon name="file-text" size="sm" />
                                                <span>{{ __('local_invoice.labels.transfer_proof') }}</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant">{{ __('finance.refund.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:pagination>{{ $overpayments->links() }}</x-slot:pagination>
    </x-ui.data-table>

    {{-- Modal Proses Pengembalian Dana (Ditempatkan di luar tabel agar DOM & Accessibility valid) --}}
    @foreach($overpayments as $refund)
        @if($refund->status === 'OPEN')
            <div
                class="modal fade"
                id="refundModal-{{ $refund->id }}"
                tabindex="-1"
                aria-labelledby="refundModalLabel-{{ $refund->id }}"
                aria-hidden="true"
            >
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <form
                        method="POST"
                        action="{{ route('finance.overpayments.refund', $refund) }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        <div class="modal-content tw-rounded-ui-xl tw-border-0 tw-shadow-ui-modal">
                            {{-- Modal Header --}}
                            <div class="modal-header tw-border-b tw-border-outline-variant/60 tw-px-6 tw-py-4">
                                <div class="tw-flex tw-items-center tw-gap-3">
                                    <div class="tw-w-10 tw-h-10 tw-rounded-ui-md tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                                        <x-ui.icon name="hand-coins" size="md" />
                                    </div>
                                    <div>
                                        <h5 class="modal-title tw-text-ui-md tw-font-bold tw-text-on-surface" id="refundModalLabel-{{ $refund->id }}">
                                            {{ __('finance.refund_ui.heading') }}
                                        </h5>
                                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">
                                            {{ __('finance.refund.help') }}
                                        </p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                            </div>

                            {{-- Modal Body --}}
                            <div class="modal-body tw-px-6 tw-py-5 tw-space-y-4">
                                {{-- Ringkasan Konteks Transaksi --}}
                                <div class="tw-rounded-ui-md tw-bg-surface-container tw-border tw-border-outline-variant/50 tw-p-4">
                                    <h6 class="tw-text-ui-xs tw-font-bold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-3">
                                        {{ __('finance.refund.summary') }}
                                    </h6>
                                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3 tw-text-ui-xs">
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">{{ __('finance.refund_ui.supplier') }}</span>
                                            <strong class="tw-text-ui-sm tw-text-on-surface">
                                                {{ $refund->supplier->supplier?->company_name ?: $refund->supplier->name }}
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.invoice_number') }}:</span>
                                            <strong class="tw-text-ui-sm tw-text-on-surface tw-font-mono">
                                                {{ $refund->invoice->invoice_number }}
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.original_reference') }}:</span>
                                            <div class="tw-font-mono tw-text-ui-xs tw-text-on-surface">
                                                @forelse($refund->payment?->transfers ?? [] as $transfer)
                                                    <span class="tw-inline-block">{{ $transfer->transfer_reference }}</span>{{ !$loop->last ? ', ' : '' }}
                                                @empty
                                                    <span class="tw-text-on-surface-variant">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">{{ __('finance.refund_ui.full_amount') }}</span>
                                            <strong class="tw-text-ui-base tw-font-bold tw-font-mono tw-text-primary">
                                                Rp {{ number_format($refund->overpayment_amount, 2, ',', '.') }}
                                            </strong>
                                        </div>
                                    </div>
                                </div>

                                {{-- Hidden Full Refund Amount (Locked in system, non-negotiable) --}}
                                <input type="hidden" name="refund_amount" value="{{ $refund->overpayment_amount }}">

                                {{-- Form Inputs --}}
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                    <div>
                                        <label for="refund_reference_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            {{ __('finance.refund_ui.reference_label') }} <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="refund_reference_{{ $refund->id }}"
                                            name="refund_reference"
                                            class="form-control form-control-sm"
                                            placeholder="{{ __('common.reference_example', ['reference' => 'TRF-REFUND-20260901']) }}"
                                            required
                                            maxlength="100"
                                        >
                                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                            {{ __('finance.refund_ui.reference_help') }}
                                        </div>
                                    </div>

                                    <div>
                                        <x-ui.date-picker
                                            :id="'refund_date_'.$refund->id"
                                            name="refund_date"
                                            :label="__('finance.refund.date')"
                                            :value="now()->format('Y-m-d')"
                                            required
                                        />
                                    </div>

                                    <div class="sm:tw-col-span-2">
                                        <label for="proof_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            {{ __('finance.refund_ui.proof') }} <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="file"
                                            id="proof_{{ $refund->id }}"
                                            name="proof"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            class="form-control form-control-sm"
                                            required
                                        >
                                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                            {{ __('finance.refund_ui.formats') }}
                                        </div>
                                    </div>

                                    <div class="sm:tw-col-span-2">
                                        <label for="notes_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            {{ __('finance.payment.reconciliation_notes') }}
                                        </label>
                                        <textarea
                                            id="notes_{{ $refund->id }}"
                                            name="notes"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            placeholder="{{ __('finance.refund_surface.notes') }}"
                                            maxlength="1000"
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            {{-- Modal Footer --}}
                            <div class="modal-footer tw-border-t tw-border-outline-variant/60 tw-px-6 tw-py-3 tw-bg-surface-container-low/50">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                    {{ __('local_invoice.actions.cancel') }}
                                </button>
                                <x-ui.button type="submit" variant="primary" size="sm">
                                    <x-ui.icon name="check-circle" size="sm" />
                                    <span>{{ __('finance.refund.save') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endsection
