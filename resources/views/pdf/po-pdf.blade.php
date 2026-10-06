<!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'id' ? 'id' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('purchasing.copy.purchase_order') }} - {{ $po->po_number }}</title>
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
                    <div class="doc-title">{{ __('documents.copy.purchase_order') }}</div>
                    <div class="doc-number">{{ $po->po_number }}</div>
                </div>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-label">{{ __('documents.copy.date_po') }}</div>
                <div class="info-value"><strong>{{ \App\Support\BusinessTime::toBusiness($po->created_at)->locale(app()->getLocale())->translatedFormat('d F Y') }}</strong></div>

                <div class="info-label">{{ __('documents.copy.pr_no') }}</div>
                <div class="info-value">
                    @php $prs = $po->purchaseRequisitions(); @endphp
                    {{ $prs->map(fn($pr) => $pr->pr_number ?? '-')->implode(', ') }}
                    @if($prs->count() > 1)
                        ({{ trans_choice('documents.copy.combined_prs', $prs->count(), ['count' => $prs->count()]) }})
                    @endif
                </div>

                <div class="info-label">{{ __('documents.copy.period') }}</div>
                <div class="info-value">{{ $prs->map(fn($pr) => $pr->period->display_label ?? $pr->period->name ?? '-')->unique()->implode(', ') }}</div>

                <div class="info-label">{{ __('documents.copy.created_by') }}</div>
                <div class="info-value">{{ $po->creator->name ?? '-' }}</div>
            </div>
            <div class="info-box info-box-right">
                <div class="info-label">{{ __('documents.copy.supplier') }}</div>
                <div class="info-value"><strong>{{ $po->supplier->name ?? '-' }}</strong></div>

                <div class="info-label">{{ __('documents.copy.currency') }}</div>
                <div class="info-value">{{ $po->currency ?? 'USD' }}</div>

                <div class="info-label">{{ __('documents.copy.estimated_arrival') }}</div>
                <div class="info-value">{{ $po->estimated_arrival ? $po->estimated_arrival->locale(app()->getLocale())->translatedFormat('d F Y') : '-' }}</div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th scope="col" class="text-center" style="width: 30px;">{{ __('documents.copy.no') }}</th>
                    <th scope="col">{{ __('documents.copy.material') }}</th>
                    <th scope="col" class="text-center">HS Code</th>
                    <th scope="col" class="text-center">{{ __('documents.copy.specification') }}</th>
                    <th scope="col" class="text-center">{{ __('documents.copy.qty') }}</th>
                    <th scope="col" class="text-right">{{ __('documents.copy.weight_unit') }}</th>
                    <th scope="col" class="text-right">{{ __('documents.copy.total_weight') }}</th>
                    <th scope="col" class="text-right">{{ __('documents.copy.price_kg') }}</th>
                    <th scope="col" class="text-right">{{ __('documents.copy.total') }} ({{ $po->currency ?? 'USD' }})</th>
                    <th scope="col" class="text-right">{{ __('documents.copy.total_idr') }}</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotalFx = 0; $grandTotalIdr = 0; $globalNo = 1; @endphp
                @foreach($po->commercialQuotations() as $quotation)
                    @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                    @if($po->quotations->count() > 1)
                        <tr>
                            <td colspan="10" style="background-color: #eef2f7; font-weight: 700; font-size: 10px; padding: 6px;">
                                {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                @if($rate)
                                    <span style="color: #64748b; font-weight: 400; margin-left: 8px;">
                                        {{ __('common.final_review.exchange_rate', ['currency' => $quotation->currency, 'amount' => number_format($rate->rate_to_idr, 0, ',', '.')]) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endif
                    @foreach($quotation->items as $item)
                        @php
                            $totalFx = $item->resolved_amount;
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
                            <td class="text-center">{{ number_format($item->prItem->quantity_value, 0, ',', '.') }}</td>
                            <td class="text-right">{{ \App\Support\NumberFormat::maxDecimals($item->prItem->weight_needed) }}</td>
                            <td class="text-right">{{ \App\Support\NumberFormat::maxDecimals($item->prItem->total_weight) }}</td>
                            <td class="text-right">{{ $item->price_per_kg === null ? '-' : \App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4) }}</td>
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
                        <td>{{ __('documents.copy.total') }} ({{ $po->currency ?? 'USD' }})</td>
                        <td class="text-right"><strong>{{ number_format($grandTotalFx, 2, ',', '.') }}</strong></td>
                    </tr>
                    <tr class="grand-total">
                        <td>{{ __('documents.copy.grand_total_idr') }}</td>
                        <td class="text-right">Rp {{ number_format($grandTotalIdr, 0, ',', '.') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-title">{{ __('documents.copy.created_by') }}</div>
                <div class="signature-line">{{ $po->creator->name ?? '_______________' }}<br>{{ __('documents.copy.purchasing') }}</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">{{ __('documents.copy.approved_by') }}</div>
                <div class="signature-line">_______________<br>{{ __('documents.copy.manager_purchasing') }}</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">{{ __('documents.copy.received_by') }}</div>
                <div class="signature-line">_______________<br>{{ __('documents.copy.supplier') }}</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            {{ __('documents.pdf.generated', ['date' => \App\Support\BusinessTime::now()->locale(app()->getLocale())->translatedFormat('d F Y, H:i').' '.\App\Support\BusinessTime::label()]) }}
        </div>
    </div>
</body>
</html>
