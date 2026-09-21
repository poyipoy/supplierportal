<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $voucher->voucher_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 16mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 10px; }
        header { display: flex; align-items: center; border-bottom: 2px solid #000; padding-bottom: 8px; page-break-inside: avoid; }
        .logo { width: 120px; height: auto; }
        .company { margin-left: auto; text-align: right; }
        .title { text-align: center; font-size: 18px; font-weight: bold; margin: 18px 0 4px; }
        .number { text-align: center; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 7px; vertical-align: top; }
        th { text-align: left; }
        .right { text-align: right; }
        .amount { font-size: 13px; font-weight: bold; }
        .meta td { border: 0; padding: 3px; }
        .bank-block, .totals, .signatures, .admin-footer { page-break-inside: avoid; }
        .checkboxes { white-space: nowrap; }
        .signatures { margin-top: 30px; }
        .signatures td { height: 76px; text-align: center; width: 25%; }
        .admin-footer { margin-top: 18px; }
        .admin-footer td { border: 0; padding: 3px 8px; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <header>
        <img class="logo" src="{{ public_path('assets/images/logo-adasi.png') }}" alt="ADASI">
        <div class="company"><strong>PT ASTRA DAIDO STEEL INDONESIA</strong><br>Finance & Accounts Payable</div>
    </header>

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
