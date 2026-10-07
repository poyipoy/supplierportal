<!doctype html>
<html lang="{{ app()->getLocale() }}">
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
        .number { text-align: center; font-size: 11px; font-weight: 600; color: #333; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 7px; vertical-align: top; }
        th { text-align: left; }
        .right { text-align: right; }
        .amount { font-size: 13px; font-weight: bold; }
        .meta td { border: 0; padding: 3px; }
        .bank-block, .totals, .signatures, .admin-footer { page-break-inside: avoid; }
        .checkboxes { white-space: nowrap; }
        .signatures { margin-top: 26px; }
        .signatures td { height: 74px; text-align: center; width: 25%; }
        .admin-footer { margin-top: 16px; }
        .admin-footer td { border: 0; padding: 3px 8px; }
        .muted { color: #666; font-size: 9px; margin-top: 10px; }
    </style>
</head>
<body>
    <table class="kop-table">
        <tr>
            <td class="kop-logo-cell">
                <img class="kop-logo" src="{{ public_path('assets/images/logo-adasi.png') }}" alt="ADASI">
            </td>
            <td class="kop-text-cell">
                <div class="company-name">PT. ASTRA DAIDO STEEL INDONESIA</div>
                <div class="company-dept">{{ __('finance.copy_review.finance_department') }}</div>
                <div class="company-address">Kawasan Industri Delta Silicon 8, Jl. Albasia Raya K. 07 No.003 Lippo Cikarang, Cikarang Pusat - Bekasi </div>
                <div class="company-contact">{{ __('documents.copy.phone') }}: 021-39506699 &middot; {{ __('documents.copy.email') }}: finance@adasi.co.id &middot; {{ __('documents.copy.website') }}: www.astra-daido.co.id</div>
            </td>
            <td class="kop-spacer-cell"></td>
        </tr>
    </table>
    <div class="kop-divider"></div>

    <div class="title">{{ __('finance.voucher.print_title') }}</div>
    <div class="number">{{ $voucher->voucher_number }}</div>

    <table class="meta">
        <tr>
            <td><strong>{{ __('common.labels_review.ref_batch') }}</strong> {{ $voucher->batch->batch_number }}</td>
            <td><strong>{{ __('local_invoice.labels.date') }}</strong> {{ $regionalFormatter->date($voucher->voucher_date, 'full_human') }}</td>
            <td class="checkboxes"><strong>{{ __('finance.voucher.method') }}</strong> [{{ $voucher->payment_method === 'BANK' ? 'X' : ' ' }}] {{ __('common.labels_review.bank') }} &nbsp; [{{ $voucher->payment_method === 'KAS' ? 'X' : ' ' }}] {{ __('finance.voucher.cash') }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>{{ __('finance.voucher.payee') }}</strong> {{ $voucher->supplier_name_snapshot }}</td>
            <td><strong>NPWP</strong> {{ $voucher->npwp_snapshot ?: '-' }}</td>
        </tr>
        <tr class="bank-block">
            <td colspan="3"><strong>{{ __('common.labels_review.bank') }}</strong> {{ $voucher->bank_name_snapshot }} / {{ $voucher->bank_account_snapshot }} {{ __('finance.drp_surface.account_holder') }} {{ $voucher->bank_account_holder_snapshot }}</td>
        </tr>
    </table>

    <table style="margin-top: 14px">
        <thead><tr><th>{{ __('finance.voucher.description') }}</th><th class="right">{{ __('finance.voucher.amount_idr') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ __('local_invoice.labels.invoice') }} {{ $voucher->invoice_number_snapshot }}<br>PO {{ $voucher->po_number_snapshot }}<br>GR {{ $voucher->gr_references_snapshot }}</td><td class="right">{{ number_format($voucher->dpp_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>PPN</td><td class="right">{{ number_format($voucher->ppn_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>{{ __('finance.voucher.withholding') }}</td><td class="right">({{ number_format($voucher->pph_snapshot, 2, ',', '.') }})</td></tr>
            <tr class="totals"><th>{{ __('finance.voucher.total_amount') }}</th><th class="right amount">Rp {{ number_format($voucher->amount, 2, ',', '.') }}</th></tr>
            <tr><td colspan="2"><strong>{{ __('finance.voucher.remarks') }}:</strong> {{ $voucher->terbilang_snapshot }}</td></tr>
        </tbody>
    </table>

    @if($voucher->remarks_snapshot)<p><strong>{{ __('local_invoice.labels.notes') }}:</strong> {{ $voucher->remarks_snapshot }}</p>@endif

    <table class="signatures">
        <tr><td>{{ __('local_invoice.labels.received') }}<br><br><br>&nbsp;</td><td>{{ __('local_invoice.labels.approved') }}<br><br><br>&nbsp;</td><td>{{ __('local_invoice.labels.reviewed') }}<br><br><br>&nbsp;</td><td>{{ __('local_invoice.labels.created') }}<br><br><br>&nbsp;</td></tr>
    </table>
    <table class="admin-footer"><tr><td>[ ] {{ __('finance.voucher.journal') }}</td><td>[ ] {{ __('finance.voucher.check') }}</td><td>[ ] {{ __('finance.voucher.posting') }}</td><td>[ ] {{ __('finance.voucher.filing') }}</td></tr></table>
    <p class="muted">{{ __('finance.voucher.generated', ['date' => $regionalFormatter->timestamp($voucher->finalized_at, 'datetime')]) }}</p>
</body>
</html>
