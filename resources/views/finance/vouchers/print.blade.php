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
                <div class="company-dept">FINANCE &amp; ACCOUNTS PAYABLE DEPARTMENT</div>
                <div class="company-address">Kawasan Industri Delta Silicon 8, Jl. Albasia Raya K. 07 No.003 Lippo Cikarang, Cikarang Pusat - Bekasi </div>
                <div class="company-contact">Telp: 021-39506699 &middot; Email: finance@adasi.co.id &middot; Website: www.astra-daido.co.id</div>
            </td>
            <td class="kop-spacer-cell"></td>
        </tr>
    </table>
    <div class="kop-divider"></div>

    <div class="title">VOUCHER BAYAR</div>
    <div class="number">{{ $voucher->voucher_number }}</div>

    <table class="meta">
        <tr>
            <td><strong>Ref/Batch</strong> {{ $voucher->batch->batch_number }}</td>
            <td><strong>Tgl.</strong> {{ $voucher->voucher_date->format('d F Y') }}</td>
            <td class="checkboxes"><strong>Metode</strong> [{{ $voucher->payment_method === 'BANK' ? 'X' : ' ' }}] Bank &nbsp; [{{ $voucher->payment_method === 'KAS' ? 'X' : ' ' }}] Kas</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Dibayar kepada</strong> {{ $voucher->supplier_name_snapshot }}</td>
            <td><strong>NPWP</strong> {{ $voucher->npwp_snapshot ?: '-' }}</td>
        </tr>
        <tr class="bank-block">
            <td colspan="3"><strong>Bank</strong> {{ $voucher->bank_name_snapshot }} / {{ $voucher->bank_account_snapshot }} a.n. {{ $voucher->bank_account_holder_snapshot }}</td>
        </tr>
    </table>

    <table style="margin-top: 14px">
        <thead><tr><th>Keterangan</th><th class="right">Nilai (IDR)</th></tr></thead>
        <tbody>
            <tr><td>Invoice {{ $voucher->invoice_number_snapshot }}<br>PO {{ $voucher->po_number_snapshot }}<br>GR {{ $voucher->gr_references_snapshot }}</td><td class="right">{{ number_format($voucher->dpp_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>PPN</td><td class="right">{{ number_format($voucher->ppn_snapshot, 2, ',', '.') }}</td></tr>
            <tr><td>Potongan PPh</td><td class="right">({{ number_format($voucher->pph_snapshot, 2, ',', '.') }})</td></tr>
            <tr class="totals"><th>Total Voucher</th><th class="right amount">Rp {{ number_format($voucher->amount, 2, ',', '.') }}</th></tr>
            <tr><td colspan="2"><strong>Terbilang:</strong> {{ $voucher->terbilang_snapshot }}</td></tr>
        </tbody>
    </table>

    @if($voucher->remarks_snapshot)<p><strong>Catatan:</strong> {{ $voucher->remarks_snapshot }}</p>@endif

    <table class="signatures">
        <tr><td>Diterima<br><br><br>&nbsp;</td><td>Disetujui<br><br><br>&nbsp;</td><td>Diperiksa<br><br><br>&nbsp;</td><td>Dibuat<br><br><br>&nbsp;</td></tr>
    </table>
    <table class="admin-footer"><tr><td>[ ] Jurnal</td><td>[ ] Cek</td><td>[ ] Posting</td><td>[ ] Filing</td></tr></table>
    <p class="muted">Generated from authoritative server snapshots on {{ $voucher->finalized_at->format('d M Y H:i') }}.</p>
</body>
</html>
