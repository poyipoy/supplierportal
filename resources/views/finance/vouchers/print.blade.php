{{-- Fixed English form: labels never follow the viewer's locale or regional preferences. --}}
@php
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
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $voucher->voucher_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 10px; line-height: 1.4; }

        /* ── Kop Surat Resmi Korporat ── */
        .kop-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .kop-table td { border: 0; padding: 0; vertical-align: middle; }
        .kop-logo-cell { width: 75px; text-align: left; }
        .kop-logo { width: 68px; height: auto; }
        .kop-text-cell { text-align: center; }
        .kop-spacer-cell { width: 75px; } /* Penyeimbang simetris untuk optical center */

        .company-name { font-size: 14px; font-weight: bold; color: #000; letter-spacing: 0.5px; margin-bottom: 2px; }
        .company-dept { font-size: 10px; font-weight: bold; color: #1F5FA6; letter-spacing: 0.5px; margin-bottom: 3px; }
        .company-address { font-size: 8.5px; color: #333; line-height: 1.3; margin-bottom: 2px; }
        .company-contact { font-size: 8px; color: #555; line-height: 1.2; }

        .kop-divider {
            border-top: 2px solid #000;
            border-bottom: 0.75px solid #000;
            height: 1px;
            margin-top: 6px;
            margin-bottom: 14px;
        }

        .title { text-align: center; font-size: 16px; font-weight: bold; margin: 0 0 2px; letter-spacing: 1px; }
        .number { text-align: center; font-size: 9px; color: #333; margin: 4px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 7px; vertical-align: top; }
        th { text-align: left; }
        .right { text-align: right; }
        .amount { font-size: 13px; font-weight: bold; }

        /* ── Header voucher: field sistem kiri, kotak isian manual kanan ── */
        .header-layout { page-break-inside: avoid; border-bottom: 1.5px solid #000; margin-bottom: 14px; }
        .header-layout > tbody > tr > td { border: 0; padding: 0 0 10px; }
        .header-left { width: 55%; padding-right: 14px !important; }
        .header-right { width: 45%; }
        .fields td { border: 0; padding: 3px 2px; }
        .fields .label { width: 92px; font-weight: bold; white-space: nowrap; }
        .fields .colon, .blank-box .colon { width: 8px; }
        .blank-box td { border: 0; padding: 6px 8px; height: 22px; vertical-align: middle; }
        .blank-box .label { width: 70px; font-weight: bold; }

        .totals, .signatures, .admin-footer { page-break-inside: avoid; }
        .checkboxes { white-space: nowrap; }
        .signatures { margin-top: 26px; }
        .signatures td { height: 74px; text-align: center; width: 25%; }
        .admin-footer { margin-top: 16px; }
        .admin-footer td { border: 0; padding: 3px 8px; }    </style>
</head>
<body>
    <table class="kop-table">
        <tr>
            <td class="kop-logo-cell">
                <img class="kop-logo" src="{{ public_path('assets/images/logo-adasi.png') }}" alt="ADASI">
            </td>
            <td class="kop-text-cell">
                <div class="company-name">PT. ASTRA DAIDO STEEL INDONESIA</div>
                <div class="company-dept">{{ trans('finance.copy_review.finance_department', [], 'en') }}</div>
                <div class="company-address">Kawasan Industri Delta Silicon 8, Jl. Albasia Raya K. 07 No.003 Lippo Cikarang, Cikarang Pusat - Bekasi </div>
                <div class="company-contact">{{ trans('documents.copy.phone', [], 'en') }}: 021-39506699 &middot; {{ trans('documents.copy.email', [], 'en') }}: {{ config('finance.adasi_refund_account.finance_email') ?: config('support.supplier_email') }} &middot; {{ trans('documents.copy.website', [], 'en') }}: www.astra-daido.co.id</div>
            </td>
            <td class="kop-spacer-cell"></td>
        </tr>
    </table>
    <div class="kop-divider"></div>

    <div class="title">{{ trans('finance.voucher.print_title', [], 'en') }}</div>
    <div class="number">{{ $voucher->voucher_number }}</div>

    <table class="header-layout">
        <tbody>
            <tr>
                <td class="header-left">
                    <table class="fields">
                        <tr><td class="label">{{ trans('finance.voucher.print_header.ref', [], 'en') }}</td><td class="colon">:</td><td>{{ $voucher->batch->batch_number }}</td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.drp_date', [], 'en') }}</td><td class="colon">:</td><td>{{ $drpDate }}</td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.paid_to', [], 'en') }}</td><td class="colon">:</td><td>{{ $voucher->supplier_name_snapshot }}</td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.bank', [], 'en') }}</td><td class="colon">:</td><td>{{ $voucher->bank_name_snapshot }} / {{ $voucher->bank_account_snapshot }}</td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.account_holder', [], 'en') }}</td><td class="colon">:</td><td>{{ $voucher->bank_account_holder_snapshot }}</td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.method', [], 'en') }}</td><td class="colon">:</td><td class="checkboxes">[{{ $voucher->payment_method === 'BANK' ? 'X' : ' ' }}] {{ trans('finance.voucher.print_header.bank', [], 'en') }} &nbsp; [{{ $voucher->payment_method === 'KAS' ? 'X' : ' ' }}] {{ trans('finance.voucher.cash', [], 'en') }}</td></tr>
                        <tr><td class="label">NPWP</td><td class="colon">:</td><td>{{ $voucher->npwp_snapshot ?: '-' }}</td></tr>
                    </table>
                </td>
                <td class="header-right">
                    <table class="blank-box">
                        <tr><td class="label">{{ trans('finance.voucher.print_header.document', [], 'en') }}</td><td class="colon">:</td><td></td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.batch', [], 'en') }}</td><td class="colon">:</td><td></td></tr>
                        <tr><td class="label">{{ trans('finance.voucher.print_header.date', [], 'en') }}</td><td class="colon">:</td><td></td></tr>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead><tr><th>{{ trans('finance.voucher.description', [], 'en') }}</th><th class="right">{{ trans('finance.voucher.amount_idr', [], 'en') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ trans('local_invoice.labels.invoice', [], 'en') }} {{ $voucher->invoice_number_snapshot }}<br>PO {{ $voucher->po_number_snapshot }}<br>GR {{ $voucher->gr_references_snapshot }}</td><td class="right">{{ number_format($voucher->dpp_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>PPN</td><td class="right">{{ number_format($voucher->ppn_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>{{ trans('finance.voucher.withholding', [], 'en') }}</td><td class="right">({{ number_format($voucher->pph_snapshot, 2, ',', '.') }})</td></tr>
            <tr><td>{{ trans('finance.voucher.bank_fee', [], 'en') }}</td><td class="right">({{ number_format($feeShare, 2, ',', '.') }})</td></tr>
            <tr class="totals"><th>{{ trans('finance.voucher.total_amount', [], 'en') }}</th><th class="right amount">Rp {{ number_format($printedTotal, 2, ',', '.') }}</th></tr>
            <tr><td colspan="2"><strong>{{ trans('finance.voucher.remarks', [], 'en') }}:</strong> {{ $printedWords }}</td></tr>
        </tbody>
    </table>

    @if($voucher->remarks_snapshot)<p><strong>{{ trans('local_invoice.labels.notes', [], 'en') }}:</strong> {{ $voucher->remarks_snapshot }}</p>@endif

    <table class="signatures">
        <tr><td>{{ trans('local_invoice.labels.received', [], 'en') }}<br><br><br>&nbsp;</td><td>{{ trans('local_invoice.labels.approved', [], 'en') }}<br><br><br>&nbsp;</td><td>{{ trans('local_invoice.labels.reviewed', [], 'en') }}<br><br><br>&nbsp;</td><td>{{ trans('local_invoice.labels.created', [], 'en') }}<br><br><br>&nbsp;</td></tr>
    </table>
    <table class="admin-footer"><tr><td>[ ] {{ trans('finance.voucher.journal', [], 'en') }}</td><td>[ ] {{ trans('finance.voucher.check', [], 'en') }}</td><td>[ ] {{ trans('finance.voucher.posting', [], 'en') }}</td><td>[ ] {{ trans('finance.voucher.filing', [], 'en') }}</td></tr></table></body>
</html>
