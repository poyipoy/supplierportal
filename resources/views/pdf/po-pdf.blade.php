<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - {{ $po->po_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.5;
        }

        .page {
            padding: 25px 30px;
        }

        /* ── Header ── */
        .header {
            border-bottom: 3px solid #1F5FA6;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header-top {
            display: table;
            width: 100%;
        }

        .header-left {
            display: table-cell;
            vertical-align: middle;
            width: 60%;
        }

        .header-right {
            display: table-cell;
            vertical-align: middle;
            width: 40%;
            text-align: right;
        }

        .company-name {
            font-size: 18px;
            font-weight: 700;
            color: #1F5FA6;
            letter-spacing: 0.5px;
        }

        .company-subtitle {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }

        .doc-title {
            font-size: 22px;
            font-weight: 700;
            color: #C0392B;
            letter-spacing: 1px;
        }

        .doc-number {
            font-size: 12px;
            color: #475569;
            margin-top: 2px;
        }

        /* ── Info Section ── */
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .info-box {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .info-box-right {
            padding-left: 20px;
        }

        .info-label {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 11px;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .info-value strong {
            font-weight: 700;
        }

        /* ── Table ── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table thead th {
            background-color: #1F5FA6;
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 6px;
            text-align: left;
            border: 1px solid #1a5290;
        }

        .items-table thead th.text-right {
            text-align: right;
        }

        .items-table thead th.text-center {
            text-align: center;
        }

        .items-table tbody td {
            padding: 7px 6px;
            font-size: 10px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* ── Totals ── */
        .totals-section {
            display: table;
            width: 100%;
            margin-bottom: 25px;
        }

        .totals-spacer {
            display: table-cell;
            width: 55%;
        }

        .totals-box {
            display: table-cell;
            width: 45%;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 5px 8px;
            font-size: 11px;
        }

        .totals-table .grand-total td {
            background-color: #1F5FA6;
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            padding: 8px;
        }

        /* ── Signatures ── */
        .signature-section {
            display: table;
            width: 100%;
            margin-top: 40px;
            page-break-inside: avoid;
        }

        .signature-box {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }

        .signature-title {
            font-size: 10px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 60px;
        }

        .signature-line {
            border-top: 1px solid #1e293b;
            padding-top: 5px;
            font-size: 10px;
            color: #475569;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="header-left">
                    <div class="company-name">PT. ASTRA DAIDO STEEL INDONESIA</div>
                    <div class="company-subtitle">Kawasan Industri Suryacipta, Karawang, Jawa Barat 41363</div>
                </div>
                <div class="header-right">
                    <div class="doc-title">PURCHASE ORDER</div>
                    <div class="doc-number">{{ $po->po_number }}</div>
                </div>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-label">Tanggal PO</div>
                <div class="info-value"><strong>{{ $po->created_at->format('d F Y') }}</strong></div>

                <div class="info-label">No. PR</div>
                <div class="info-value">
                    @php $prs = $po->purchaseRequirements(); @endphp
                    {{ $prs->map(fn($pr) => $pr->pr_number ?? '-')->implode(', ') }}
                    @if($prs->count() > 1)
                        ({{ $prs->count() }} PR digabung)
                    @endif
                </div>

                <div class="info-label">Periode</div>
                <div class="info-value">{{ $prs->map(fn($pr) => $pr->period->name ?? '-')->unique()->implode(', ') }}</div>

                <div class="info-label">Dibuat Oleh</div>
                <div class="info-value">{{ $po->creator->name ?? '-' }}</div>
            </div>
            <div class="info-box info-box-right">
                <div class="info-label">Supplier</div>
                <div class="info-value"><strong>{{ $po->supplier->name ?? '-' }}</strong></div>

                <div class="info-label">Mata Uang</div>
                <div class="info-value">{{ $po->currency ?? 'USD' }}</div>

                <div class="info-label">Estimasi Kedatangan</div>
                <div class="info-value">{{ $po->estimated_arrival ? $po->estimated_arrival->format('d F Y') : '-' }}</div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 30px;">No</th>
                    <th>Material</th>
                    <th class="text-center">HS Code</th>
                    <th class="text-center">Spesifikasi</th>
                    <th class="text-right">Berat (kg)</th>
                    <th class="text-right">Harga/kg</th>
                    <th class="text-right">Total ({{ $po->currency ?? 'USD' }})</th>
                    <th class="text-right">Total (IDR)</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotalFx = 0; $grandTotalIdr = 0; $globalNo = 1; @endphp
                @foreach($po->quotations as $quotation)
                    @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                    @if($po->quotations->count() > 1)
                        <tr>
                            <td colspan="8" style="background-color: #eef2f7; font-weight: 700; font-size: 10px; padding: 6px;">
                                {{ $quotation->purchaseRequirement->pr_number ?? 'PR -' }}
                                @if($rate)
                                    <span style="color: #64748b; font-weight: 400; margin-left: 8px;">
                                        Kurs: 1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endif
                    @foreach($quotation->items as $item)
                        @php
                            $totalFx = $item->amount;
                            $totalIdr = $totalFx * ($rate ? $rate->rate_to_idr : 1);
                            $grandTotalFx += $totalFx;
                            $grandTotalIdr += $totalIdr;

                            $spec = $item->prItem->dimension_label;
                        @endphp
                        <tr>
                            <td class="text-center">{{ $globalNo++ }}</td>
                            <td><strong>{{ $item->prItem->material_name }}</strong><br><small style="color:#64748b;">{{ $item->prItem->shape ?? '-' }}</small></td>
                            <td class="text-center">{{ $item->prItem->hs_code ?? '-' }}</td>
                            <td class="text-center" style="font-size:9px;">{{ $spec }}</td>
                            <td class="text-right">{{ number_format($item->prItem->weight_needed, 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($item->price_per_kg, 4) }}</td>
                            <td class="text-right">{{ number_format($totalFx, 2, ',', '.') }}</td>
                            <td class="text-right">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-spacer"></div>
            <div class="totals-box">
                <table class="totals-table">
                    <tr>
                        <td>Total ({{ $po->currency ?? 'USD' }})</td>
                        <td class="text-right"><strong>{{ number_format($grandTotalFx, 2, ',', '.') }}</strong></td>
                    </tr>
                    <tr class="grand-total">
                        <td>GRAND TOTAL (IDR)</td>
                        <td class="text-right">Rp {{ number_format($grandTotalIdr, 0, ',', '.') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-title">Dibuat Oleh</div>
                <div class="signature-line">{{ $po->creator->name ?? '_______________' }}<br>Purchasing</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">Disetujui Oleh</div>
                <div class="signature-line">_______________<br>Manager Purchasing</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">Diterima Oleh</div>
                <div class="signature-line">_______________<br>Supplier</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            Dokumen ini digenerate secara otomatis oleh ADASI Supplier Portal pada {{ now()->format('d F Y, H:i') }} WIB.
        </div>
    </div>
</body>
</html>
