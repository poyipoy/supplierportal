{{-- Fixed English form: labels never follow the viewer locale or regional preferences. --}}
@php
    use App\Models\PaymentVoucher;
    use App\Support\BusinessTime;
    use App\Support\Money;
    use App\Support\Pdf\FormDocument;

    $label = fn (string $key, array $replace = []) => trans($key, $replace, 'en');

    $deptHeading = match ($voucher->source_department) {
        PaymentVoucher::DEPT_FINANCE => $label('finance.copy_review.finance_department'),
        PaymentVoucher::DEPT_PURCHASING => $label('documents.voucher.purchasing_department'),
        default => $label('documents.voucher.admin_department'),
    };

    $deptNameEn = match ($voucher->source_department) {
        PaymentVoucher::DEPT_FINANCE => $label('documents.voucher.dept_finance'),
        PaymentVoucher::DEPT_PURCHASING => $label('documents.voucher.dept_purchasing'),
        default => $label('documents.voucher.dept_admin'),
    };

    $voucherDate = $voucher->voucher_date
        ? BusinessTime::toBusiness($voucher->voucher_date)->locale('en')->translatedFormat('d M Y')
        : '-';
    $issuedAt = $voucher->issued_at
        ? BusinessTime::toBusiness($voucher->issued_at)->locale('en')->translatedFormat('d M Y H:i')
        : '-';
    $voidedAt = $voucher->voided_at
        ? BusinessTime::toBusiness($voucher->voided_at)->locale('en')->translatedFormat('d M Y H:i')
        : '-';

    $letterheadOverrides = [
        'department' => $deptHeading,
    ];

    $documentTitle = $label('finance.voucher.print_title');
    $documentNumber = $voucher->voucher_number;
    $documentNumberLabel = $label('documents.voucher.number_heading');
    $formKey = 'manual_payment_voucher';
    $copyMark = $voucher->isCancelled() ? $label('documents.voucher.void_status') : null;

    $stamp = FormDocument::barcode($voucher->voucher_number);
    $stampImage = $stamp['barcode'];
    $stampWidthMm = $stamp['barcode_width'];
    $stampCaption = $voucher->voucher_number;

    $hasPpn = Money::compare((string) $voucher->ppn_amount, Money::ZERO) > 0;
    $hasPph = Money::compare((string) $voucher->pph_amount, Money::ZERO) > 0;
    $hasBankFee = Money::compare((string) $voucher->bank_fee, Money::ZERO) > 0;
@endphp
@extends('pdf.layout')

@section('document')
    @if($voucher->isCancelled())
        <table style="width: 188.6mm; border-collapse: collapse; margin-bottom: 3mm">
            <tr>
                <td style="border: 1pt solid #000; padding: 1.8mm 2.4mm">
                    <div style="font-weight: bold">{{ $label('documents.voucher.void_notice') }}</div>
                    <div style="margin-top: 0.6mm; font-size: 8pt">
                        {{ $label('documents.voucher.void_reason') }}: {{ $voucher->void_reason ?: '-' }}
                        @if($voucher->voider)
                            ({{ $voucher->voider->name }} - {{ $voidedAt }})
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    @endif

    {{-- Left: authoritative voucher snapshot. Right: manual accounting boxes filled after printing. --}}
    <table style="width: 188.6mm; border-collapse: collapse">
        <tr>
            <td style="width: 55%; padding: 0 6mm 0 0; border: 0; vertical-align: top">
                <table class="fields" style="width: 100%">
                    <tr>
                        <td class="field-label">{{ $label('documents.voucher.department') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">{{ $deptNameEn }}</td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('documents.voucher.voucher_date') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">{{ $voucherDate }}</td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('finance.voucher.print_header.ref') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">{{ $voucher->reference_number ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('finance.voucher.print_header.paid_to') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">
                            @foreach(FormDocument::wrap((string) $voucher->payee_name_snapshot, 62) as $line)
                                <div>{{ $line }}</div>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('finance.voucher.print_header.bank') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">
                            @if($voucher->isBankMethod())
                                {{ $voucher->bank_name_snapshot }} / {{ $voucher->bank_account_snapshot }}
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('finance.voucher.print_header.account_holder') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">
                            @if($voucher->isBankMethod() && $voucher->bank_account_holder_snapshot)
                                @foreach(FormDocument::wrap((string) $voucher->bank_account_holder_snapshot, 62) as $line)
                                    <div>{{ $line }}</div>
                                @endforeach
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">{{ $label('finance.voucher.method') }}</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">[{{ $voucher->isBankMethod() ? 'X' : ' ' }}] {{ $label('finance.voucher.print_header.bank') }} &nbsp; [{{ $voucher->isBankMethod() ? ' ' : 'X' }}] {{ $label('finance.voucher.cash') }}</td>
                    </tr>
                    <tr>
                        <td class="field-label">NPWP</td>
                        <td class="field-colon">:</td>
                        <td class="field-value">{{ $voucher->npwp_snapshot ?: '-' }}</td>
                    </tr>
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
                    @foreach(FormDocument::wrap((string) $voucher->description, 118) as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if($voucher->reference_number)
                        <div class="cell-sub" style="margin-top: 1mm">{{ $label('finance.voucher.print_header.ref') }}: {{ $voucher->reference_number }}</div>
                    @endif
                </td>
                <td class="right">{{ number_format((float) $voucher->dpp_amount, 2, ',', '.') }}</td>
            </tr>
            @if($hasPpn)
                <tr>
                    <td>PPN</td>
                    <td class="right">{{ number_format((float) $voucher->ppn_amount, 2, ',', '.') }}</td>
                </tr>
            @endif
            @if($hasPph)
                <tr>
                    <td>{{ $label('finance.voucher.withholding_label') }}</td>
                    <td class="right">({{ number_format((float) $voucher->pph_amount, 2, ',', '.') }})</td>
                </tr>
            @endif
            @if($hasBankFee)
                <tr>
                    <td>{{ $label('finance.voucher.bank_fee') }}</td>
                    <td class="right">({{ number_format((float) $voucher->bank_fee, 2, ',', '.') }})</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals" style="margin-top: 3mm">
        <tr class="grand">
            <td class="total-label" style="width: 72%">{{ $label('finance.voucher.total_amount') }}</td>
            <td class="amount" style="width: 28%">Rp {{ number_format((float) $voucher->net_amount, 2, ',', '.') }}</td>
        </tr>
    </table>

    <table class="form-notes" style="margin-top: 3mm">
        <tr>
            <td class="note-label">{{ $label('finance.voucher.remarks') }}</td>
            <td>
                @foreach(FormDocument::wrap((string) $voucher->terbilang_snapshot, 142, 8) as $line)
                    <div class="note-line">{{ $line }}</div>
                @endforeach
            </td>
        </tr>
        <tr>
            <td class="note-label">{{ $label('documents.voucher.issued_by') }}</td>
            <td>{{ $voucher->creator?->name ?? '-' }} ({{ $issuedAt }})</td>
        </tr>
        @if($voucher->notes)
            <tr>
                <td class="note-label">{{ $label('documents.voucher.notes') }}</td>
                <td>
                    @foreach(FormDocument::wrap((string) $voucher->notes, 142, 8) as $line)
                        <div class="note-line">{{ $line }}</div>
                    @endforeach
                </td>
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
            <td>[ ] {{ $label('finance.voucher.journal') }}</td>
            <td>[ ] {{ $label('finance.voucher.check') }}</td>
            <td>[ ] {{ $label('finance.voucher.posting') }}</td>
            <td>[ ] {{ $label('finance.voucher.filing') }}</td>
        </tr>
    </table>
@endsection

