<!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'id' ? 'id' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('qc.copy.qc_inspection_report') }} - {{ $inspection->purchaseOrder->po_number ?? 'N/A' }}</title>
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
            font-size: 18px;
            font-weight: 700;
            color: #C0392B;
            letter-spacing: 0.5px;
        }

        .doc-subtitle {
            font-size: 10px;
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

        /* ── Status Badge ── */
        .status-ok {
            display: inline-block;
            background-color: #22c55e;
            color: #ffffff;
            padding: 3px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .status-ng {
            display: inline-block;
            background-color: #ef4444;
            color: #ffffff;
            padding: 3px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
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
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 7px 5px;
            text-align: center;
            border: 1px solid #1a5290;
        }

        .items-table tbody td {
            padding: 6px 5px;
            font-size: 9px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
            text-align: center;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .text-left {
            text-align: left !important;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .cell-ok {
            background-color: #dcfce7 !important;
            color: #166534;
            font-weight: 700;
        }

        .cell-ng {
            background-color: #fee2e2 !important;
            color: #991b1b;
            font-weight: 700;
        }

        /* ── Summary ── */
        .summary-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }

        .summary-cell {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding: 12px;
            vertical-align: middle;
        }

        .summary-cell + .summary-cell {
            border-left: 1px solid #cbd5e1;
        }

        .summary-number {
            font-size: 24px;
            font-weight: 700;
        }

        .summary-label {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        .color-total { color: #1F5FA6; }
        .color-ok { color: #22c55e; }
        .color-ng { color: #ef4444; }

        /* ── Conclusion ── */
        .conclusion {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 25px;
            font-size: 12px;
            font-weight: 700;
        }

        .conclusion-ok {
            background-color: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }

        .conclusion-ng {
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
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
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 20px;
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
                    <div class="doc-title">{{ __('qc.pdf.report') }}</div>
                    <div class="doc-subtitle">{{ __('qc.copy.quality_control_inspection') }}</div>
                </div>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-label">{{ __('qc.copy.no_purchase_order') }}</div>
                <div class="info-value"><strong>{{ $inspection->purchaseOrder->po_number ?? '-' }}</strong></div>

                <div class="info-label">{{ __('qc.copy.period') }}</div>
                <div class="info-value">{{ $inspection->purchaseOrder->quotations->first()?->purchaseRequisition->period->display_label ?? $inspection->purchaseOrder->quotations->first()?->purchaseRequisition->period->name ?? '-' }}</div>

                <div class="info-label">{{ __('qc.copy.supplier') }}</div>
                <div class="info-value"><strong>{{ $inspection->purchaseOrder->supplier->name ?? '-' }}</strong></div>
            </div>
            <div class="info-box info-box-right">
                <div class="info-label">{{ __('qc.copy.inspection_date') }}</div>
                <div class="info-value"><strong>{{ $inspection->inspected_at ? \App\Support\BusinessTime::toBusiness($inspection->inspected_at)->locale(app()->getLocale())->translatedFormat('d F Y, H:i') : '-' }}</strong></div>

                <div class="info-label">{{ __('qc.pdf.inspector_short', []) }}</div>
                <div class="info-value">{{ $inspection->inspector->name ?? '-' }}</div>

                <div class="info-label">{{ __('qc.copy.inspection_result') }}</div>
                <div class="info-value">
                    <span class="status-{{ $inspection->status }}">{{ \App\Support\StatusHelper::qcLabel($inspection->status) }}</span>
                </div>
            </div>
        </div>

        <!-- Summary -->
        @php
            $totalItems = $inspection->items->count();
            $okItems = $inspection->items->where('status', 'ok')->count();
            $ngItems = $inspection->items->where('status', 'ng')->count();
        @endphp
        <div class="summary-section">
            <div class="summary-cell">
                <div class="summary-number color-total">{{ $totalItems }}</div>
                <div class="summary-label">{{ __('qc.copy.total_items_inspected') }}</div>
            </div>
            <div class="summary-cell">
                <div class="summary-number color-ok">{{ $okItems }}</div>
                <div class="summary-label">{{ __('qc.copy.item_ok') }}</div>
            </div>
            <div class="summary-cell">
                <div class="summary-number color-ng">{{ $ngItems }}</div>
                <div class="summary-label">{{ __('qc.copy.item_ng') }}</div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th scope="col" style="width:25px;">{{ __('qc.copy.no') }}</th>
                    <th scope="col">{{ __('qc.copy.material') }}</th>
                    <th scope="col">{{ __('qc.copy.thickness') }}<br>{{ __('qc.copy.requested') }}</th>
                    <th scope="col">{{ __('qc.copy.thickness') }}<br>{{ __('qc.copy.actual') }}</th>
                    <th scope="col">{{ __('qc.copy.width') }}<br>{{ __('qc.copy.requested') }}</th>
                    <th scope="col">{{ __('qc.copy.width') }}<br>{{ __('qc.copy.actual') }}</th>
                    <th scope="col">{{ __('qc.copy.length') }}<br>{{ __('qc.copy.requested') }}</th>
                    <th scope="col">{{ __('qc.copy.length') }}<br>{{ __('qc.copy.actual') }}</th>
                    <th scope="col">{{ __('qc.copy.qty') }}</th>
                    <th scope="col">{{ __('qc.copy.weight_unit') }}<br>{{ __('qc.copy.requested') }}</th>
                    <th scope="col">{{ __('qc.copy.weight') }}<br>{{ __('qc.copy.actual') }}</th>
                    <th scope="col">{{ __('qc.copy.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($inspection->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-left"><strong>{{ $item->prItem->material_name ?? '-' }}</strong></td>
                        <td>{{ $item->prItem->thickness ? number_format($item->prItem->thickness, 2) : '-' }}</td>
                        <td>{{ $item->actual_thickness ? number_format($item->actual_thickness, 2) : '-' }}</td>
                        <td>{{ $item->prItem->width ? number_format($item->prItem->width, 1) : '-' }}</td>
                        <td>{{ $item->actual_width ? number_format($item->actual_width, 1) : '-' }}</td>
                        <td>{{ $item->prItem->length ? number_format($item->prItem->length, 1) : '-' }}</td>
                        <td>{{ $item->actual_length ? number_format($item->actual_length, 1) : '-' }}</td>
                        <td>{{ number_format($item->prItem->quantity_value, 0) }}</td>
                        <td>{{ number_format($item->prItem->weight_needed, 0) }}</td>
                        <td>{{ $item->actual_weight ? number_format($item->actual_weight, 0) : '-' }}</td>
                        <td class="cell-{{ $item->status }}">{{ \App\Support\StatusHelper::qcLabel($item->status) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Conclusion -->
        <div class="conclusion conclusion-{{ $inspection->status }}">
            @if($inspection->status === 'ok')
                {{ __('qc.pdf.conclusion_ok') }}
            @else
                {{ __('qc.pdf.conclusion_ng', ['count' => $ngItems]) }}
            @endif
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-title">{{ __('qc.pdf.inspector') }}</div>
                <div class="signature-line">{{ $inspection->inspector->name ?? '_______________' }}<br>{{ __('qc.copy.quality_control') }}</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">{{ __('qc.pdf.acknowledged') }}</div>
                <div class="signature-line">_______________<br>{{ __('qc.copy.manager_qc') }}</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            {{ __('documents.pdf.generated', ['date' => \App\Support\BusinessTime::now()->locale(app()->getLocale())->translatedFormat('d F Y, H:i').' '.\App\Support\BusinessTime::label()]) }}
        </div>
    </div>
</body>
</html>
