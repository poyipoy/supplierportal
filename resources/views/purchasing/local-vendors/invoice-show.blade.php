@extends('layouts.app')
@section('title', __('finance.invoice_ui.audit_title', ['number' => $invoice->invoice_number]))
@section('page-title', __('accounting.labels.read_only'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.invoice_ui.audit_heading', ['number' => $invoice->invoice_number])"
        :description="__('finance.invoice_ui.reference', ['number' => $invoice->submission_number, 'supplier' => $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name])"
        :eyebrow="__('finance.invoice_ui.audit_eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.local-vendors.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('finance.invoice_ui.back_vendor') }}</span>
            </x-ui.button>
            @if($invoice->receipt)
                <x-ui.button :href="route('finance.invoices.receipt', $invoice)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>{{ __('finance.invoice_ui.receipt_view') }}</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoice->has_po_discrepancy)
        <x-ui.alert tone="warning" :title="__('finance.invoice_ui.discrepancy')">
            {{ __('finance.invoice_ui.discrepancy_help', ['invoice' => $regionalFormatter->number(number_format($invoice->invoice_amount, 0, ',', '.'), 'indonesian'), 'remaining' => $regionalFormatter->number(number_format($invoice->po_remaining_snapshot ?? 0, 0, ',', '.'), 'indonesian')]) }}
        </x-ui.alert>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Overview Card --}}
            <x-ui.card :title="__('local_invoice.labels.billing_information')">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.invoice_number') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.invoice_date') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_date ? $regionalFormatter->date($invoice->invoice_date, 'human') : '—' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('finance.invoice_ui.invoice_status') }}</span>
                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($invoice->status)">
                            {{ \App\Support\StatusHelper::localInvoiceLabel($invoice->status) }}
                        </x-ui.status-chip>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('finance.invoice_ui.po_source', ['source' => $invoice->po_source]) }}</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->po_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('finance.invoice_ui.gr_reference') }}</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->gr_reference ?? '—' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.delivery_date_short') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">
                            {{ $regionalFormatter->date($invoice->physical_delivery_date, 'human') ?? '—' }}
                        </strong>
                    </div>
                    @if($invoice->tax_invoice_number)
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.tax_number') }}:</span>
                            <strong class="tw-text-ui-sm tw-font-mono tw-text-primary">{{ $invoice->tax_invoice_number }}</strong>
                        </div>
                    @endif
                    <div class="tw-col-span-full tw-border-t tw-border-outline-variant tw-pt-3 tw-grid tw-grid-cols-3 tw-gap-4">
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.dpp') }}:</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format($invoice->invoice_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">PPN ({{ $invoice->ppn_scheme ?? '11%' }}):</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-primary">
                                Rp {{ $regionalFormatter->number(number_format($invoice->tax_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.form.total_summary') }}</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-success">
                                Rp {{ $regionalFormatter->number(number_format($invoice->invoice_amount + $invoice->tax_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Dokumen Upload Supplier --}}
            <x-ui.card
                :title="__('local_invoice.labels.attachment_documents')"
                :description="__('finance.copy_review.vendor_docs')"
            >
                @php $latestRev = $invoice->revisions->last(); @endphp
                @include('local-invoices.partials._documents_grid', [
                    'documents' => $latestRev ? $latestRev->documents : collect()
                ])
            </x-ui.card>

            {{-- Section A Checklist Results (Read-Only) --}}
            <x-ui.card :title="__('finance.labels.document_results')">
                @if($verification)
                    <div class="tw-space-y-3 tw-text-ui-xs">
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.doc_invoice') }}</span>
                            <strong>{{ $verification->invoice_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.doc_tax') }}</span>
                            <strong>{{ $verification->tax_invoice_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.doc_po') }}</span>
                            <strong>{{ $verification->po_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.doc_dn') }}</span>
                            <strong>{{ $verification->delivery_note_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.doc_gr') }}</span>
                            <strong>{{ $verification->gr_check }}</strong>
                        </div>
                    </div>
                @else
                    <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('finance.invoice_ui.section_a_empty') }}
                    </div>
                @endif
            </x-ui.card>

            {{-- Section B Tax Results (Read-Only) --}}
            <x-ui.card :title="__('finance.labels.tax_results')">
                @if($verification)
                    <div class="tw-space-y-3 tw-text-ui-xs">
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.labels.ppn_status') }}:</span>
                            <strong>{{ $verification->ppn_status }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.invoice_ui.ppn_verified') }}</span>
                            <strong class="tw-font-mono">Rp {{ $regionalFormatter->number(number_format($verification->verified_ppn ?? $invoice->tax_amount, 0, ',', '.'), 'indonesian') }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>{{ __('finance.invoice_ui.pph23_verified') }}</span>
                            <strong class="tw-font-mono">{{ $verification->pph_23_applicable ? __('finance.invoice_ui.tax_rate_amount', ['amount' => $regionalFormatter->number(number_format($verification->pph_23_amount, 0, ',', '.'), 'indonesian'), 'rate' => $verification->pph_23_rate]) : __('local_invoice.labels.none') }}</strong>
                        </div>
                        @if($verification->pph_4_2_applicable)
                            <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>PPh 4(2):</span>
                                <strong class="tw-font-mono">Rp {{ $regionalFormatter->number(number_format($verification->pph_4_2_amount, 0, ',', '.'), 'indonesian') }}</strong>
                            </div>
                        @endif
                        @if($verification->pph_21_applicable)
                            <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>PPh 21:</span>
                                <strong class="tw-font-mono">Rp {{ $regionalFormatter->number(number_format($verification->pph_21_amount, 0, ',', '.'), 'indonesian') }}</strong>
                            </div>
                        @endif
                        @if($verification->tax_notes)
                            <div class="tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>{{ __('local_invoice.labels.tax_notes') }}:</span>
                                <em>{{ $verification->tax_notes }}</em>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('finance.invoice_ui.section_b_empty') }}
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Jadwal Pembayaran --}}
            <x-ui.card :title="__('local_invoice.labels.payment_and_due')">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('finance.invoice_ui.cashier_date') }}</span>
                        <strong class="tw-text-on-surface">{{ $invoice->cashier_received_at ? $regionalFormatter->timestamp($invoice->cashier_received_at, 'datetime') : __('local_invoice.detail.await_physical') }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('local_invoice.detail.payment_term') }}</span>
                        <strong class="tw-text-on-surface">{{ trans_choice('local_invoice.table.term_days', $invoice->payment_term_days_snapshot ?? 30) }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.due_date') }}:</span>
                        <strong class="tw-text-on-surface tw-text-ui-sm {{ $invoice->isOverdue() ? 'tw-text-error' : 'tw-text-primary' }}">
                            {{ $invoice->due_date ? $regionalFormatter->date($invoice->due_date, 'human') : __('finance.waiting_cashier') }}
                        </strong>
                    </div>
                    @if($invoice->due_date)
                        <div class="tw-p-2 tw-rounded tw-text-center {{ $invoice->isOverdue() ? 'tw-bg-error/10 tw-text-error' : 'tw-bg-success/10 tw-text-success' }}">
                            <strong>{{ $invoice->remainingDays() < 0 ? trans_choice('local_invoice.table.overdue_days', abs($invoice->remainingDays())) : trans_choice('local_invoice.table.remaining_days', $invoice->remainingDays()) }}</strong>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Audit Timeline --}}
            <x-ui.card :title="__('local_invoice.labels.audit_status')">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    @forelse($invoice->statusHistories as $hist)
                        <div class="tw-p-2 tw-rounded tw-bg-surface-container">
                            <div class="tw-flex tw-justify-between">
                                <strong>{{ \App\Models\LocalInvoice::eventLabel($hist->event) }}</strong>
                                <span class="tw-text-on-surface-variant">{{ $regionalFormatter->timestamp($hist->created_at, 'short_datetime') }}</span>
                            </div>
                            <div class="tw-text-on-surface-variant">{{ __('ga.detail.actor', ['name' => $hist->actor?->name ?? __('ga.detail.system')]) }}</div>
                            @if($hist->notes)
                                <div class="tw-mt-1 tw-text-[11px] tw-italic">{{ $hist->notes }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="tw-text-on-surface-variant">{{ __('local_invoice.empty.history') }}</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
