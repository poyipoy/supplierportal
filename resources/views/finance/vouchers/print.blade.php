{{-- Fixed English form: labels never follow the viewer's locale or regional preferences. --}}
@php
    use App\Support\Pdf\FormDocument;

    $drpAt = $voucher->batch->finalized_at ?? $voucher->batch->created_at ?? null;
    $drpDate = $drpAt ? \App\Support\BusinessTime::toBusiness($drpAt)->locale('en')->translatedFormat('d M Y') : '-';
    // The bank fee is charged per transfer (payment group), which can carry several vouchers. This voucher shows its
    // proportional share; the shares add up to the group fee. Fee/subtotal only change in DRAFT, and a voucher needs a finalized DRP,
    // so this is stable. voucher.amount (the settlement amount) is untouched: only the printed total is net of the fee.
    $group = $voucher->group ?? null;
    $feeShare = \App\Support\Money::ZERO;
    if ($group && \App\Support\Money::compare($group->bank_fee, \App\Support\Money::ZERO) > 0) {
        $weights = collect($group->activeItems ?? [])->mapWithKeys(fn ($item) => [$item->id => $item->amount])->all();
        $feeShare = \App\Support\Money::allocate($group->bank_fee, $weights)[$voucher->payment_item_id ?? null] ?? \App\Support\Money::ZERO;
    }
    $printedTotal = \App\Support\Money::subtract($voucher->amount, $feeShare);
    $printedWords = \App\Support\Money::compare($feeShare, \App\Support\Money::ZERO) > 0
        ? app(\App\Services\Payment\PaymentVoucherService::class)->terbilang((float) $printedTotal).' Rupiah'
        : $voucher->terbilang_snapshot;

    $label = fn (string $key) => trans($key, [], 'en');

    // The voucher is issued by Finance; the letterhead image already carries the
    // company address and phone, so only the department line is added.
    $letterheadOverrides = [
        'department' => trans('finance.copy_review.finance_department', [], 'en'),
    ];

    $documentTitle = trans('finance.voucher.print_title', [], 'en');
    $documentNumber = $voucher->voucher_number;
    $documentNumberLabel = trans('documents.voucher.number_heading', [], 'en');
    $formKey = 'payment_voucher';
    $stamp = FormDocument::barcode($voucher->voucher_number);
    $stampImage = $stamp['barcode'];
    $stampWidthMm = $stamp['barcode_width'];
    $stampCaption = $voucher->voucher_number;
@endphp
@extends('pdf.layout')

@section('document')
    {{-- Left: values from the finalized DRP snapshot. Right: boxes filled in by hand after printing. --}}
    <table style="width: 188.6mm; border-collapse: collapse">
        <tr>
            <td style="width: 55%; padding: 0 6mm 0 0; border: 0; vertical-align: top">
                <table class="fields" style="width: 100%">
                    <tr><td class="field-label">{{ $label('finance.voucher.print_header.ref') }}</td><td class="field-colon">:</td><td class="field-value">{{ $voucher->batch->batch_number }}</td></tr>
                    <tr><td class="field-label">{{ $label('finance.voucher.print_header.drp_date') }}</td><td class="field-colon">:</td><td class="field-value">{{ $drpDate }}</td></tr>
                    <tr><td class="field-label">{{ $label('finance.voucher.print_header.paid_to') }}</td><td class="field-colon">:</td><td class="field-value">{{ $voucher->supplier_name_snapshot }}</td></tr>
                    <tr><td class="field-label">{{ $label('finance.voucher.print_header.bank') }}</td><td class="field-colon">:</td><td class="field-value">{{ $voucher->bank_name_snapshot }} / {{ $voucher->bank_account_snapshot }}</td></tr>
                    <tr><td class="field-label">{{ $label('finance.voucher.print_header.account_holder') }}</td><td class="field-colon">:</td><td class="field-value">{{ $voucher->bank_account_holder_snapshot }}</td></tr>
                    <tr><td class="field-label">{{ $label('finance.voucher.method') }}</td><td class="field-colon">:</td><td class="field-value">[{{ $voucher->payment_method === 'BANK' ? 'X' : ' ' }}] {{ $label('finance.voucher.print_header.bank') }} &nbsp; [{{ $voucher->payment_method === 'KAS' ? 'X' : ' ' }}] {{ $label('finance.voucher.cash') }}</td></tr>
                    <tr><td class="field-label">NPWP</td><td class="field-colon">:</td><td class="field-value">{{ $voucher->npwp_snapshot ?: '-' }}</td></tr>
                </table>
            </td>
            <td style="width: 45%; border: 0; vertical-align: top">
                <table style="width: 100%; border-collapse: collapse">
                    @foreach(['document', 'batch', 'date'] as $manualField)
                        <tr>
                            <td style="width: 26mm; border: 0; padding: 0 1mm 1.5mm 0; line-height: 4.1mm; white-space: nowrap">{{ $label('finance.voucher.print_header.'.$manualField) }}</td>
                            <td style="width: 3mm; border: 0; padding: 0 0 1.5mm; line-height: 4.1mm">:</td>
                            <td style="border: 0; border-bottom: 0.35pt solid #555; padding: 0 0 1.5mm; line-height: 4.1mm">&nbsp;</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <div class="spacer-3"></div>

    <table class="form-table">
        <thead>
            <tr>
                <th style="width: 72%; text-align: left">{{ $label('finance.voucher.description') }}</th>
                <th style="width: 28%">{{ $label('finance.voucher.amount_idr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{-- Aligned label column so the three references line up with balanced top/bottom padding in Dompdf. --}}
                    <table class="ref-table">
                        <tr>
                            <td style="width: 18%">{{ $label('local_invoice.labels.invoice') }}</td>
                            <td>{{ $voucher->invoice_number_snapshot }}</td>
                        </tr>
                        <tr>
                            <td>PO</td>
                            <td>{{ $voucher->po_number_snapshot }}</td>
                        </tr>
                        <tr>
                            <td>GR</td>
                            <td>@foreach(FormDocument::wrap((string) $voucher->gr_references_snapshot, 105) as $line)<div>{{ $line }}</div>@endforeach</td>
                        </tr>
                    </table>
                </td>
                <td class="right">{{ number_format($voucher->dpp_snapshot, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>PPN</td><td class="right">{{ number_format($voucher->ppn_snapshot, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>{{ $label('finance.voucher.withholding') }}</td><td class="right">({{ number_format($voucher->pph_snapshot, 2, ',', '.') }})</td>
            </tr>
            <tr>
                <td>{{ $label('finance.voucher.bank_fee') }}</td><td class="right">({{ number_format($feeShare, 2, ',', '.') }})</td>
            </tr>
        </tbody>
    </table>

    <table class="totals" style="margin-top: 3mm">
        <tr class="grand">
            <td class="total-label" style="width: 72%">{{ $label('finance.voucher.total_amount') }}</td>
            <td class="amount" style="width: 28%">Rp {{ number_format($printedTotal, 2, ',', '.') }}</td>
        </tr>
    </table>

    <table class="form-notes" style="margin-top: 3mm">
        <tr>
            <td class="note-label">{{ $label('finance.voucher.remarks') }}</td>
            <td>@foreach(FormDocument::wrap((string) $printedWords, 142, 8) as $line)<div class="note-line">{{ $line }}</div>@endforeach</td>
        </tr>
        @if($voucher->remarks_snapshot)
            <tr>
                <td class="note-label">{{ $label('local_invoice.labels.notes') }}</td>
                <td>@foreach(FormDocument::wrap($voucher->remarks_snapshot, 142, 8) as $line)<div class="note-line">{{ $line }}</div>@endforeach</td>
            </tr>
        @endif
    </table>

    <div class="spacer-5"></div>

    <table class="signatures">
        <thead>
            <tr>
                <th style="width: 25%">{{ $label('local_invoice.labels.received') }}</th>
                <th style="width: 25%">{{ $label('local_invoice.labels.approved') }}</th>
                <th style="width: 25%">{{ $label('local_invoice.labels.reviewed') }}</th>
                <th style="width: 25%">{{ $label('local_invoice.labels.created') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td></td><td></td><td></td><td></td></tr>
        </tbody>
    </table>
@endsection

@section('document-footer')
    <table class="form-notes" style="margin-top: 3mm; width: 188.6mm; table-layout: fixed">
        <tr>
            <td>[ ] {{ trans('finance.voucher.journal', [], 'en') }}</td>
            <td>[ ] {{ trans('finance.voucher.check', [], 'en') }}</td>
            <td>[ ] {{ trans('finance.voucher.posting', [], 'en') }}</td>
            <td>[ ] {{ trans('finance.voucher.filing', [], 'en') }}</td>
        </tr>
    </table>
@endsection
