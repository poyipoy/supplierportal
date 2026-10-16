@extends('layouts.app')
@section('title', __('finance.invoice_ui.page_title', ['number' => $invoice->invoice_number]))
@section('page-title', __('finance.labels.verification'))

@section('content')
@php
    $isLocked = (bool) ($verification?->is_locked || in_array($invoice->status, [
        \App\Models\LocalInvoice::STATUS_READY_TO_PAY,
        \App\Models\LocalInvoice::STATUS_PAID,
        \App\Models\LocalInvoice::STATUS_REJECTED,
        \App\Models\LocalInvoice::STATUS_CANCELLED,
    ], true));
@endphp
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('terms.invoice').' '.$invoice->invoice_number"
        :description="__('finance.invoice_ui.reference', ['number' => $invoice->submission_number, 'supplier' => $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name])"
        :eyebrow="__('finance.labels.workflow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.invoices.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.detail.back') }}</span>
            </x-ui.button>
            @if($invoice->receipt)
                <x-ui.button :href="route('finance.invoices.receipt', $invoice)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>{{ __('local_invoice.actions.print_receipt') }}</span>
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
        {{-- Left Column: Details, Documents, Verification Sections --}}
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
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.delivery_date') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">
                            {{ $invoice->scheduled_physical_delivery_date?->format('d M Y (l)') ?? '—' }}
                            @if($invoice->missed_delivery_count > 0)
                                <span class="tw-text-error">{{ __('finance.invoice_ui.missed', ['count' => $invoice->missed_delivery_count]) }}</span>
                            @endif
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

            @if($invoice->local_purchase_order_id)
                <x-ui.card :title="__('finance.invoice_ui.po_gr_title')" :description="__('finance.invoice_ui.po_gr_help')">
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs tw-mb-4">
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.po_number') }}</span><strong class="tw-font-mono tw-text-on-surface">{{ $invoice->localPurchaseOrder?->po_number ?? $invoice->po_number }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.labels.po_date') }}</span><strong class="tw-text-on-surface">{{ $invoice->localPurchaseOrder?->po_date ? $regionalFormatter->date($invoice->localPurchaseOrder->po_date, 'human') : '—' }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.labels.po_total') }}</span><strong class="tw-font-mono tw-text-on-surface">Rp {{ $regionalFormatter->number(number_format($invoice->localPurchaseOrder?->total_amount ?? $invoice->po_value_snapshot ?? 0, 2, ',', '.'), 'indonesian') }}</strong></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle tw-m-0 tw-text-ui-xs">
                            <thead><tr><th>{{ __('local_invoice.detail.gr_number') }}</th><th>{{ __('local_invoice.labels.date') }}</th><th class="text-end">{{ __('common.fields.qty') }}</th><th scope="col">{{ __('local_procurement.labels.uom') }}</th><th>{{ __('local_invoice.labels.status') }}</th></tr></thead>
                            <tbody>
                            @forelse($invoice->goodsReceiptHistories->sortBy('id') as $history)
                                <tr>
                                    <td class="tw-font-mono">{{ $history->gr_number_snapshot }}</td>
                                    <td>{{ $history->goodsReceipt?->gr_date ? $regionalFormatter->date($history->goodsReceipt->gr_date, 'human') : '—' }}</td>
                                    <td class="text-end tw-font-mono">{{ $history->gr_qty_snapshot !== null ? rtrim(rtrim(number_format((float) $history->gr_qty_snapshot, 4, ',', '.'), '0'), ',') : ($history->goodsReceipt?->qty !== null ? rtrim(rtrim(number_format((float) $history->goodsReceipt->qty, 4, ',', '.'), '0'), ',') : '—') }}</td><td>{{ $history->gr_uom_snapshot ?? '—' }}</td>
                                    <td><x-ui.status-chip :tone="$history->state === 'CONSUMED' ? 'success' : ($history->state === 'RELEASED' ? 'neutral' : 'warning')">{{ \App\Support\StatusHelper::localFinanceLabel($history->state) }}</x-ui.status-chip></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="tw-text-center tw-text-on-surface-variant">{{ __('local_procurement.empty.authoritative_history') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif

            {{-- Dokumen Upload Supplier --}}
            <x-ui.card
                :title="__('local_invoice.labels.attachment_documents')"
                :description="__('finance.invoice_ui.documents_help')"
            >
                @php $latestRev = $invoice->revisions->last(); @endphp
                @include('local-invoices.partials._documents_grid', [
                    'documents' => $latestRev ? $latestRev->documents : collect()
                ])
            </x-ui.card>

            {{-- Step 1: Cashier Receipt Section --}}
            @if($invoice->status === \App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT)
                <x-ui.card
                    :title="__('common.final_review.cashier_receipt')"
                    :description="__('finance.labels.physical_received_help')"
                >
                    <form method="POST" action="{{ route('finance.invoices.receive-physical', $invoice) }}">
                        @csrf
                        <div class="tw-space-y-3">
                            <div>
                                <label for="receipt_qr" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.labels.scan_receipt') }}</label>
                                <input type="text" name="receipt_qr" id="receipt_qr" class="form-control form-control-sm" inputmode="none" autocomplete="off" autofocus required maxlength="2048" placeholder="{{ __('finance.labels.scan_instruction') }}">
                                <p class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('finance.invoice_ui.qr_help') }}</p>
                                @error('receipt_qr')
                                    <div class="tw-text-error tw-text-ui-xs" role="alert">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <label for="cashier-notes" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.labels.cashier_notes') }}</label>
                                <input type="text" name="notes" id="cashier-notes" class="form-control form-control-sm" placeholder="{{ __('finance.copy_review.cashier_example') }}">
                            </div>
                            <x-ui.button type="submit" variant="primary" size="sm">
                                <x-ui.icon name="inbox" size="sm" />
                                <span>{{ __('finance.invoice_ui.receive') }}</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Step 2: SECTION A — Document & Reference Checklist --}}
            @if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_READY_TO_PAY, \App\Models\LocalInvoice::STATUS_PAID]))
                <x-ui.card
                    id="section-a"
                    class="tw-scroll-mt-20"
                    :title="__('finance.labels.document_checks')"
                    :description="__('finance.invoice_ui.section_a_help')"
                >
                    <x-slot:actions>
                        @if($isLocked)
                            <x-ui.status-chip tone="neutral">
                                <x-ui.icon name="lock" size="xs" />
                                <span>{{ __('local_invoice.labels.read_only') }}</span>
                            </x-ui.status-chip>
                        @elseif($verification?->is_section_a_passed)
                            <x-ui.status-chip tone="success">
                                <x-ui.icon name="check" size="xs" />
                                <span>{{ __('finance.invoice_ui.passed') }}</span>
                            </x-ui.status-chip>
                        @endif
                    </x-slot:actions>

                    @if($isLocked)
                        <div class="tw-mb-4 tw-p-3 tw-rounded-lg tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-gap-2.5 tw-text-ui-xs tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" class="tw-text-primary tw-shrink-0" />
                            <span>
                                {{ __('finance.invoice_ui.section_a_locked') }}
                                {{ __('finance.invoice_ui.locked_details', ['name' => $verification?->verifier?->name ?? '—', 'date' => $verification?->verified_at ? $regionalFormatter->businessTime($verification->verified_at) : '—']) }}
                            </span>
                        </div>
                    @endif

                    <form id="form-verify-section-a" method="POST" action="{{ route('finance.invoices.verify-section-a', $invoice) }}">
                        @csrf
                        <fieldset @disabled($isLocked) class="tw-m-0 tw-p-0 tw-border-0 tw-space-y-4">
                            {{-- 1. Invoice Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">{{ __('finance.labels.doc_invoice_stamp') }}</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="invoice_check" value="OK" @checked(($verification->invoice_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="invoice_check" value="NOT_OK" @checked(($verification->invoice_check ?? '') === 'NOT_OK')> {{ __('finance.invoice_ui.not_ok') }}
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="invoice_notes" class="form-control form-control-sm" placeholder="{{ __('finance.invoice_ui.check_notes') }}" value="{{ $verification->invoice_notes ?? '' }}">
                            </div>

                            {{-- 2. Faktur Pajak Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <div>
                                        <span class="tw-font-semibold tw-text-ui-xs tw-block">{{ __('finance.labels.doc_tax_required') }}</span>
                                        @if($invoice->tax_invoice_number)
                                            <span class="tw-text-[11px] tw-font-mono tw-text-primary tw-block tw-mt-0.5">
                                                NSFP: {{ $invoice->tax_invoice_number }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="OK" @checked(($verification->tax_invoice_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="NOT_OK" @checked(($verification->tax_invoice_check ?? '') === 'NOT_OK')> {{ __('finance.invoice_ui.not_ok') }}
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="NOT_APPLICABLE" @checked(($verification->tax_invoice_check ?? '') === 'NOT_APPLICABLE' || !$invoice->supplier->supplier?->is_pkp)> {{ __('finance.invoice_ui.not_applicable_pkp') }}
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="tax_invoice_notes" class="form-control form-control-sm" placeholder="{{ __('finance.copy_review.tax_notes') }}" value="{{ $verification->tax_invoice_notes ?? '' }}">
                            </div>

                            {{-- 3. PO Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">{{ __('finance.labels.doc_po_match') }}</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="po_check" value="OK" @checked(($verification->po_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="po_check" value="NOT_OK" @checked(($verification->po_check ?? '') === 'NOT_OK')> {{ __('finance.invoice_ui.not_ok') }}
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="po_notes" class="form-control form-control-sm" placeholder="{{ __('local_procurement.labels.po_notes') }}" value="{{ $verification->po_notes ?? '' }}">
                            </div>

                            {{-- 4. Surat Jalan Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">{{ __('finance.labels.doc_dn_required') }}</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="OK" @checked(($verification->delivery_note_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="NOT_OK" @checked(($verification->delivery_note_check ?? '') === 'NOT_OK')> {{ __('finance.invoice_ui.not_ok') }}
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="NOT_APPLICABLE" @checked(($verification->delivery_note_check ?? '') === 'NOT_APPLICABLE' || $invoice->supplier->supplier?->vendor_category === 'Jasa')> {{ __('finance.invoice_ui.not_applicable_services') }}
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="delivery_note_notes" class="form-control form-control-sm" placeholder="{{ __('finance.copy_review.delivery_notes') }}" value="{{ $verification->delivery_note_notes ?? '' }}">
                            </div>

                            {{-- 5. GR Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">{{ __('finance.labels.doc_gr_confirm') }}</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="gr_check" value="OK" @checked(($verification->gr_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="gr_check" value="NOT_OK" @checked(($verification->gr_check ?? '') === 'NOT_OK')> {{ __('finance.invoice_ui.not_ok') }}
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="gr_notes" class="form-control form-control-sm" placeholder="{{ __('local_procurement.labels.gr_notes') }}" value="{{ $verification->gr_notes ?? '' }}">
                            </div>

                            @if(!$isLocked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button id="btn-submit-section-a" type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="save" size="sm" />
                                        <span>{{ __('finance.actions.save_a') }}</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </fieldset>
                    </form>
                </x-ui.card>

                {{-- Step 3: SECTION B — Tax Verification --}}
                <x-ui.card
                    id="section-b"
                    class="tw-scroll-mt-20"
                    :title="__('finance.labels.tax_checks')"
                    :description="__('finance.labels.tax_help')"
                >
                    <x-slot:actions>
                        @if($isLocked)
                            <x-ui.status-chip tone="neutral">
                                <x-ui.icon name="lock" size="xs" />
                                <span>{{ __('local_invoice.labels.read_only') }}</span>
                            </x-ui.status-chip>
                        @elseif($verification?->is_section_b_passed)
                            <x-ui.status-chip tone="success">
                                <x-ui.icon name="check" size="xs" />
                                <span>{{ __('finance.invoice_ui.passed') }}</span>
                            </x-ui.status-chip>
                        @endif
                    </x-slot:actions>

                    @if($isLocked)
                        <div class="tw-mb-4 tw-p-3 tw-rounded-lg tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-gap-2.5 tw-text-ui-xs tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" class="tw-text-primary tw-shrink-0" />
                            <span>
                                {{ __('finance.labels.tax_locked') }}
                            </span>
                        </div>
                    @endif

                    <form id="form-verify-section-b" method="POST" action="{{ route('finance.invoices.verify-section-b', $invoice) }}">
                        @csrf
                        <fieldset @disabled($isLocked) class="tw-m-0 tw-p-0 tw-border-0 tw-space-y-4">
                            @if($invoice->tax_invoice_number)
                                <div class="tw-p-2.5 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-justify-between">
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_invoice.detail.tax_number') }}:</span>
                                    <span class="tw-font-mono tw-font-bold tw-text-ui-xs tw-text-primary">{{ $invoice->tax_invoice_number }}</span>
                                </div>
                            @endif
                            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                <div>
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.invoice_ui.ppn_match') }}</label>
                                    <select name="ppn_status" class="form-select form-select-sm" required>
                                        <option value="SESUAI" @selected(($verification->ppn_status ?? 'SESUAI') === 'SESUAI')>{{ __('finance.labels.tax_conform') }}</option>
                                        <option value="TIDAK_SESUAI" @selected(($verification->ppn_status ?? '') === 'TIDAK_SESUAI')>{{ __('finance.labels.tax_mismatch') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.invoice_ui.verified_ppn') }}</label>
                                    <input type="number" step="0.01" name="verified_ppn" class="form-control form-control-sm" value="{{ $verification->verified_ppn ?? $invoice->tax_amount }}">
                                </div>
                            </div>

                            <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                                <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-block tw-mb-2">{{ __('finance.invoice_ui.pph23') }}</span>
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-4 tw-gap-3">
                                    <div class="tw-flex tw-items-center tw-gap-2">
                                        <input type="checkbox" name="pph_23_applicable" id="pph23-app" value="1" @checked($verification->pph_23_applicable ?? false)>
                                        <label for="pph23-app" class="tw-text-ui-xs">{{ __('finance.invoice_ui.deduct_pph23') }}</label>
                                    </div>
                                    <div>
                                        <label for="pph23-base" class="form-label tw-text-[11px]">DPP PPh 23</label>
                                        <input type="number" step="0.01" id="pph23-base" name="pph_23_base" class="form-control form-control-sm" value="{{ $verification->pph_23_base ?? $invoice->invoice_amount }}">
                                    </div>
                                    <div>
                                        <label for="pph23-rate" class="form-label tw-text-[11px]">{{ __('finance.invoice_ui.pph23_rate') }}</label>
                                        <input type="number" step="0.01" id="pph23-rate" name="pph_23_rate" class="form-control form-control-sm" value="{{ $verification->pph_23_rate ?? 2 }}">
                                    </div>
                                    <div>
                                        <label for="pph23-amount" class="form-label tw-text-[11px]">{{ __('finance.invoice_ui.pph23_amount') }}</label>
                                        <input type="number" step="0.01" id="pph23-amount" name="pph_23_amount" class="form-control form-control-sm" value="{{ $verification->pph_23_amount ?? 0 }}">
                                        <span id="pph23-calc-hint" class="tw-text-[11px] tw-text-primary tw-font-mono tw-mt-1 tw-block"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                                <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-block tw-mb-2">{{ __('finance.invoice_ui.other_pph') }}</span>
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                    <div class="tw-flex tw-items-center tw-gap-3">
                                        <input type="checkbox" name="pph_4_2_applicable" id="pph42-app" value="1" @checked($verification->pph_4_2_applicable ?? false)>
                                        <label for="pph42-app" class="tw-text-ui-xs tw-shrink-0">PPh 4(2)</label>
                                        <input type="number" step="0.01" name="pph_4_2_amount" class="form-control form-control-sm" placeholder="{{ __('finance.invoice_ui.amount_rp') }}" value="{{ $verification->pph_4_2_amount ?? 0 }}">
                                    </div>
                                    <div class="tw-flex tw-items-center tw-gap-3">
                                        <input type="checkbox" name="pph_21_applicable" id="pph21-app" value="1" @checked($verification->pph_21_applicable ?? false)>
                                        <label for="pph21-app" class="tw-text-ui-xs tw-shrink-0">PPh 21</label>
                                        <input type="number" step="0.01" name="pph_21_amount" class="form-control form-control-sm" placeholder="{{ __('finance.invoice_ui.amount_rp') }}" value="{{ $verification->pph_21_amount ?? 0 }}">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.tax_notes') }}</label>
                                <textarea name="tax_notes" class="form-control form-control-sm" rows="2" placeholder="{{ __('finance.invoice_ui.tax_notes') }}">{{ $verification->tax_notes ?? '' }}</textarea>
                            </div>

                            @if(!$isLocked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button id="btn-submit-section-b" type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="save" size="sm" />
                                        <span>{{ __('finance.actions.save_b') }}</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </fieldset>
                    </form>
                </x-ui.card>
            @endif
        </div>

        {{-- Right Column: Payment, Status Timeline & Final Actions --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Payment Schedule & Term Card --}}
            <x-ui.card :title="__('finance.copy_review.cashier_schedule')">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('finance.invoice_ui.cashier_date') }}</span>
                        <strong class="tw-text-on-surface">{{ $invoice->cashier_received_at ? $regionalFormatter->timestamp($invoice->cashier_received_at, 'datetime') : __('local_invoice.detail.await_physical') }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('local_procurement.change_details.payment_term') }}:</span>
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

            {{-- Bank Account Snapshot / Destination --}}
            <x-ui.card :title="__('finance.copy_review.supplier_account')">
                @php $bank = $invoice->supplier->supplier?->activeBankAccount; @endphp
                <div class="tw-space-y-2 tw-text-ui-xs">
                    @if($bank)
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">{{ __('common.labels_review.bank_label') }}</span>
                            <strong class="tw-text-on-surface">{{ $bank->bank_name }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">{{ __('ga.detail.bank_number') }}:</span>
                            <strong class="tw-font-mono tw-text-on-surface">{{ $bank->account_number }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.account_name') }}:</span>
                            <strong class="tw-text-on-surface">{{ $bank->account_holder_name }}</strong>
                        </div>
                    @else
                        <div class="tw-text-error">{{ __('finance.invoice_ui.bank_missing') }}</div>
                    @endif
                </div>
            </x-ui.card>

            @if($invoice->voucher)
                <x-ui.card :title="__('finance.voucher.settlement')" :description="__('finance.voucher.notes')">
                    <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-text-ui-xs">
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('common.labels_review.voucher') }}</span><strong class="tw-font-mono tw-text-primary">{{ $invoice->voucher->voucher_number }}</strong></div>
                        <x-ui.button :href="route('finance.vouchers.show', $invoice->voucher)" variant="outline" size="sm">{{ __('finance.voucher.open') }}</x-ui.button>
                    </div>
                    @if($invoice->voucher->payment)
                        <div class="tw-mt-3 tw-border-t tw-border-outline-variant tw-pt-3 tw-text-ui-xs">
                            <div class="tw-flex tw-justify-between"><span class="tw-text-on-surface-variant">{{ __('common.labels_review.settlement') }}</span><x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($invoice->voucher->payment->status)">{{ \App\Support\StatusHelper::localFinanceLabel($invoice->voucher->payment->status) }}</x-ui.status-chip></div>
                            <div class="tw-mt-1 tw-flex tw-justify-between"><span class="tw-text-on-surface-variant">{{ __('finance.payment.expected_actual') }}</span><span class="tw-font-mono">Rp {{ $regionalFormatter->number(number_format($invoice->voucher->payment->expected_amount, 2, ',', '.'), 'indonesian') }} / Rp {{ $regionalFormatter->number(number_format($invoice->voucher->payment->actual_paid_total, 2, ',', '.'), 'indonesian') }}</span></div>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Final Approval Actions --}}
            @if(!$isLocked && $invoice->status === \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION)
                <x-ui.card :title="__('finance.labels.final_approval')">
                    <div class="tw-space-y-3">
                        <form method="POST" action="{{ route('finance.invoices.approve-ready-to-pay', $invoice) }}">
                            @csrf
                            <x-ui.button
                                id="btn-approve-ready-to-pay"
                                type="submit"
                                variant="primary"
                                size="sm"
                                class="w-100"
                                :disabled="!$verification || !$verification->is_section_a_passed || !$verification->is_section_b_passed"
                            >
                                <x-ui.icon name="check-circle" size="sm" />
                                <span>{{ __('finance.invoice_ui.approve') }}</span>
                            </x-ui.button>
                        </form>

                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm w-100"
                            data-bs-toggle="modal"
                            data-bs-target="#revisionModal"
                        >
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>{{ __('finance.invoice_ui.request_revision') }}</span>
                        </button>
                    </div>
                </x-ui.card>
            @endif

            @if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_NEED_REVISION], true))
                <x-ui.card :title="__('finance.actions.reject_invoice')" :description="__('finance.invoice_ui.reject_help')">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <x-ui.icon name="x-circle" size="sm" /> {{ __('finance.actions.reject_invoice') }}
                    </button>
                </x-ui.card>
            @endif

            {{-- Status Timeline --}}
            <x-ui.card :title="__('local_invoice.labels.timeline')">
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

{{-- Request Revision Modal --}}
<div class="modal fade" id="revisionModal" tabindex="-1" aria-labelledby="revisionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('finance.invoices.request-revision', $invoice) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title tw-text-ui-sm tw-font-bold" id="revisionModalLabel">{{ __('finance.invoice_ui.revision_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-3">
                        {{ __('finance.invoice_ui.revision_help') }}
                    </p>
                    <div class="mb-3">
                        <label for="rev-notes" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('ga.verification.reason') }} <span class="text-danger">*</span></label>
                        <textarea name="notes" id="rev-notes" class="form-control form-control-sm" rows="3" required placeholder="{{ __('finance.invoice_ui.revision_example') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button>
                    <button type="submit" class="btn btn-danger btn-sm">{{ __('ga.verification.send_revision') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_NEED_REVISION], true))
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('finance.invoices.reject', $invoice) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title tw-text-ui-sm tw-font-bold" id="rejectModalLabel">{{ __('finance.actions.reject_invoice') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
                <div class="modal-body"><label for="reject-notes" class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.labels.rejection_reason') }} <span class="text-danger">*</span></label><textarea name="notes" id="reject-notes" class="form-control form-control-sm" rows="3" maxlength="2000" required></textarea></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button><button type="submit" class="btn btn-danger btn-sm">{{ __('finance.actions.reject_release') }}</button></div>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Restore scroll position if previously stored before full page reload/redirect
    const savedScroll = sessionStorage.getItem('finance_invoice_verify_scroll');
    if (savedScroll !== null) {
        sessionStorage.removeItem('finance_invoice_verify_scroll');
        window.scrollTo({
            top: parseInt(savedScroll, 10),
            behavior: 'instant'
        });
    } else if (window.location.hash) {
        const target = document.querySelector(window.location.hash);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Helper to toggle button loading state
    function setBtnLoading(btn, isLoading, loadingText) {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('disabled', 'tw-opacity-75');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span><span></span>';
            btn.lastElementChild.textContent = loadingText;
        } else {
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
            btn.disabled = false;
            btn.classList.remove('disabled', 'tw-opacity-75');
        }
    }

    @if(!$isLocked)
    // Helper to update approval button state
    function updateApprovalButton(canApprove) {
        const approveBtn = document.getElementById('btn-approve-ready-to-pay');
        if (!approveBtn) return;
        if (canApprove) {
            approveBtn.removeAttribute('disabled');
            approveBtn.disabled = false;
            approveBtn.classList.remove('disabled', 'tw-cursor-not-allowed', 'tw-opacity-50');
        } else {
            approveBtn.setAttribute('disabled', 'disabled');
            approveBtn.disabled = true;
            approveBtn.classList.add('disabled', 'tw-cursor-not-allowed', 'tw-opacity-50');
        }
    }
    // 2. AJAX Submission for Section A
    const formSectionA = document.getElementById('form-verify-section-a');
    if (formSectionA) {
        formSectionA.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-section-a') || formSectionA.querySelector('button[type="submit"]');
            setBtnLoading(submitBtn, true, @js(__('finance.verification_ui.saving_a')));

            try {
                const response = await fetch(formSectionA.action, {
                    method: 'POST',
                    body: new FormData(formSectionA),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    if (window.AdasiToast) {
                        window.AdasiToast.success(data.message || @js(__('local_invoice.feedback.section_a_saved')));
                    }
                    updateApprovalButton(data.can_approve);

                    // Smoothly guide user to Section B if section A passed
                    if (data.is_section_a_passed) {
                        const secB = document.getElementById('section-b');
                        if (secB) {
                            secB.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                } else {
                    let errMsg = data.message || @js(__('finance.verification_ui.error_a'));
                    if (data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        if (firstKey && data.errors[firstKey][0]) {
                            errMsg = data.errors[firstKey][0];
                        }
                    }
                    if (window.AdasiToast) {
                        window.AdasiToast.error(errMsg);
                    } else {
                        alert(errMsg);
                    }
                }
            } catch (err) {
                console.error('Section A save error:', err);
                sessionStorage.setItem('finance_invoice_verify_scroll', window.scrollY.toString());
                formSectionA.submit();
                return;
            } finally {
                setBtnLoading(submitBtn, false);
            }
        });
    }

    // 3. AJAX Submission for Section B
    const formSectionB = document.getElementById('form-verify-section-b');
    if (formSectionB) {
        formSectionB.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-section-b') || formSectionB.querySelector('button[type="submit"]');
            setBtnLoading(submitBtn, true, @js(__('finance.verification_ui.saving_b')));

            try {
                const response = await fetch(formSectionB.action, {
                    method: 'POST',
                    body: new FormData(formSectionB),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    if (window.AdasiToast) {
                        window.AdasiToast.success(data.message || @js(__('local_invoice.feedback.section_b_saved')));
                    }
                    updateApprovalButton(data.can_approve);
                } else {
                    let errMsg = data.message || @js(__('finance.verification_ui.error_b'));
                    if (data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        if (firstKey && data.errors[firstKey][0]) {
                            errMsg = data.errors[firstKey][0];
                        }
                    }
                    if (window.AdasiToast) {
                        window.AdasiToast.error(errMsg);
                    } else {
                        alert(errMsg);
                    }
                }
            } catch (err) {
                console.error('Section B save error:', err);
                sessionStorage.setItem('finance_invoice_verify_scroll', window.scrollY.toString());
                formSectionB.submit();
                return;
            } finally {
                setBtnLoading(submitBtn, false);
            }
        });
    }
    @endif

    // 4. Auto-calculation for PPh 23
    const pph23App = document.getElementById('pph23-app');
    const pph23Base = document.getElementById('pph23-base');
    const pph23Rate = document.getElementById('pph23-rate');
    const pph23Amount = document.getElementById('pph23-amount');
    const pph23Hint = document.getElementById('pph23-calc-hint');

    if (pph23App && pph23Base && pph23Rate && pph23Amount) {
        function formatRupiah(num) {
            const raw = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(raw, 'indonesian') : raw;
        }

        @if(!$isLocked)
        function calculatePph23() {
            if (!pph23App.checked) {
                pph23Amount.value = '0.00';
                if (pph23Hint) pph23Hint.textContent = '';
                return;
            }

            const base = parseFloat(pph23Base.value) || 0;
            const rate = parseFloat(pph23Rate.value) || 0;
            const calculated = Math.round(base * (rate / 100) * 100) / 100;

            pph23Amount.value = calculated.toFixed(2);
        }

        pph23App.addEventListener('change', function () {
            if (pph23App.checked) {
                if (!parseFloat(pph23Base.value)) {
                    pph23Base.value = '{{ (float) $invoice->invoice_amount }}';
                }
                if (!parseFloat(pph23Rate.value)) {
                    pph23Rate.value = '2';
                }
            }
            calculatePph23();
        });

        pph23Base.addEventListener('input', calculatePph23);
        pph23Rate.addEventListener('input', calculatePph23);

        // Initial setup on load if checked
        if (pph23App.checked) {
            const currentAmt = parseFloat(pph23Amount.value) || 0;
            if (currentAmt === 0) {
                calculatePph23();
            } else {
                const base = parseFloat(pph23Base.value) || 0;
                const rate = parseFloat(pph23Rate.value) || 0;
                if (pph23Hint && base > 0 && rate > 0) {
                    pph23Hint.textContent = @js(__('finance.verification_ui.automatic_pph')).replace(':rate', () => String(rate)).replace(':base', () => formatRupiah(base)).replace(':amount', () => formatRupiah(currentAmt));
                }
            }
        }
        @else
        // Read-only calculation hint display
        if (pph23App.checked) {
            const currentAmt = parseFloat(pph23Amount.value) || 0;
            const base = parseFloat(pph23Base.value) || 0;
            const rate = parseFloat(pph23Rate.value) || 0;
            if (pph23Hint && base > 0 && rate > 0) {
                pph23Hint.textContent = @js(__('finance.verification_ui.applied_pph')).replace(':rate', () => String(rate)).replace(':base', () => formatRupiah(base)).replace(':amount', () => formatRupiah(currentAmt));
            }
        }
        @endif
    }
});
</script>
@endpush
