@php
    $document ??= \App\Support\PurchaseOrderPdf::data($po);
    $label = fn (string $key) => trans('documents.po.'.$key, [], 'en');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $label('title') }} - {{ $po->po_number }}</title>
    <style>
        @font-face { font-family: 'PO Noto Sans'; font-weight: normal; src: url('{{ $document['font_regular'] }}') format('truetype'); }
        @font-face { font-family: 'PO Noto Sans'; font-weight: bold; src: url('{{ $document['font_bold'] }}') format('truetype'); }
        body, div, table, td, th { margin: 0; padding: 0; }
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: 'PO Noto Sans', sans-serif; font-size: 9pt; line-height: 4.1mm; color: #000; }
        .sheet { position: relative; width: 190mm; height: 276mm; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .company-header { position: absolute; left: 0; top: 0; width: 98.69mm; height: 21.9mm; }
        .barcode { position: absolute; left: 105mm; top: 0; width: 41.8mm; text-align: center; }
        .barcode img { display: block; margin: 0 auto; height: 8.8mm; }
        .barcode-caption { font-size: 6pt; height: 3mm; line-height: 3mm; margin-top: 0.3mm; }
        .copy { position: absolute; right: 0; top: -2mm; text-align: right; font-weight: normal; }
        .form-code { position: absolute; right: 0; top: 7mm; }
        .po-heading { position: absolute; left: 0; top: 25.5mm; width: 55mm; font-weight: normal; }
        .po-number { position: absolute; left: 55.5mm; top: 25.5mm; }
        .supplier { position: absolute; top: 31.8mm; left: 0; width: 112mm; }
        .page-number { position: absolute; top: 31.8mm; right: 0; text-align: right; white-space: pre; }
        .dates { position: absolute; top: 40.5mm; right: 0; width: 55.5mm; border-collapse: collapse; }
        .dates td { padding: 0; line-height: 4.1mm; white-space: nowrap; }
        .dates .date-value { text-align: right; }
        .introduction { position: absolute; left: 0; font-size: 9pt; }
        .items { position: absolute; left: 0.7mm; width: 188.6mm; border-collapse: collapse; table-layout: fixed; }
        .items th { border: 0.35pt solid #555; height: 8.3mm; font-weight: normal; text-align: center; padding: 0; }
        .items td { border-left: 0.35pt solid #555; border-right: 0.35pt solid #555; padding: 1mm 2mm; vertical-align: top; }
        .items .row-number { padding-left: 0; padding-right: 0; text-align: center; }
        .items .numeric { text-align: right; }
        .cell-line { height: 4.1mm; line-height: 4.1mm; white-space: pre; }
        .totals { position: absolute; left: 0.7mm; width: 188.6mm; height: 17mm; border: 0.35pt solid #555; border-collapse: collapse; }
        .totals td { padding: 0; line-height: 4.2mm; }
        .totals .total-label { vertical-align: top; padding-left: 8mm; padding-top: 1.5mm; }
        .totals .amount-label { width: 17mm; text-align: right; }
        .totals .amount { width: 28mm; text-align: right; padding-right: 1mm; }
        .closing-notes { position: absolute; left: 0; width: 190mm; font-size: 8pt; }
        .closing-notes table { width: 190mm; border-collapse: collapse; table-layout: fixed; }
        .closing-notes td { vertical-align: top; line-height: 3.8mm; }
        .closing-notes .note-label { width: 12%; }
        .note-line { height: 3.8mm; line-height: 3.8mm; white-space: pre; }
        .ship-to { position: absolute; top: 232.3mm; left: 0; width: 88.1mm; height: 30.5mm; border: 0.35pt solid #555; text-align: center; }
        .ship-title { border-bottom: 0.35pt solid #555; height: 6mm; line-height: 3.2mm; font-weight: normal; }
        .ship-body { padding-top: 0.5mm; font-size: 9pt; line-height: 4.1mm; }
        .ship-line { height: 6.3mm; line-height: 4.1mm; }
        .signatures { position: absolute; top: 232.3mm; right: 0; width: 98.1mm; height: 30.5mm; border-collapse: collapse; table-layout: fixed; }
        .signatures th, .signatures td { border: 1pt solid #000; padding: 0; text-align: center; font-weight: normal; }
        .signatures th { line-height: 3.8mm; font-size: 8pt; }
        .signature-title { height: 5.3mm; line-height: 3.8mm; }
        .signatures td { height: 24.5mm; }
        .sign-hint { color: #bbb; font-size: 8pt; font-style: italic; }
        .continuation-title { font-size: 9pt; margin-bottom: 3mm; }
    </style>
</head>
<body>
@foreach($document['pages'] as $page)
    @php $lastPage = $loop->last; @endphp
    <div class="sheet">
        <img class="company-header" src="{{ $document['company_header'] }}" alt="ASTRA DAIDO STEEL INDONESIA">
        <div class="barcode">
            <img src="{{ $document['barcode'] }}" style="width: {{ $document['barcode_width'] }}mm" alt="{{ $po->po_number }}">
            <div class="barcode-caption">{{ $po->po_number }}</div>
        </div>
        <div class="copy">{{ $label('original') }}</div>
        <div class="form-code">F-PC-01-01-01:00</div>
        <div class="po-heading">{{ $label('number') }}</div>
        <div class="po-number">@foreach($document['po_number_lines'] as $line)<div class="cell-line">{{ $line }}</div>@endforeach</div>
        <div class="supplier">@foreach($document['supplier_lines'] as $line)<div class="cell-line">{{ $line }}</div>@endforeach</div>
        <div class="page-number">{{ trans('documents.po.page', ['current' => $loop->iteration, 'total' => count($document['pages'])], 'en') }}</div>
        <table class="dates">
            <tr><td><div class="cell-line">{{ $label('date') }}</div></td><td><div class="cell-line">:</div></td><td class="date-value"><div class="cell-line">{{ $document['date'] }}</div></td></tr>
            <tr><td><div class="cell-line">{{ $label('receipt_date') }}</div></td><td><div class="cell-line">:</div></td><td class="date-value"><div class="cell-line">{{ $document['receipt_date'] }}</div></td></tr>
            <tr><td><div class="cell-line">{{ $label('currency') }}</div></td><td><div class="cell-line">:</div></td><td class="date-value"><div class="cell-line">{{ $document['currency'] }}</div></td></tr>
        </table>
        @if($page['note_lines'] !== [])
            <div class="closing-notes" style="top: {{ $document['table_top'] }}mm">
                <div class="continuation-title">{{ $label('note_continued') }}</div>
                <table><tbody>@foreach($page['note_lines'] as $line)<tr><td class="note-label"><div class="note-line">{{ $line['label'] }}</div></td><td style="width: 88%"><div class="note-line">{{ $line['text'] }}</div></td></tr>@endforeach</tbody></table>
            </div>
        @else
            <div class="introduction" style="top: {{ $document['table_top'] - 8.1 }}mm">{{ $label('introduction') }}</div>
            <table class="items" style="top: {{ $document['table_top'] }}mm">
                <thead><tr><th style="width: 3.7116%">{{ $label('no') }}</th><th style="width: 51.2195%">{{ $label('description') }}</th><th style="width: 15.9067%">{{ $label('quantity') }}</th><th style="width: 14.5811%">{{ $label('unit_price') }}</th><th style="width: 14.5811%">{{ $label('total_price') }}</th></tr></thead>
                <tbody>
                @forelse($page['rows'] as $row)
                    <tr>@foreach($row['cells'] as $column => $lines)<td class="{{ $column === 0 ? 'row-number' : ($column > 1 ? 'numeric' : '') }}" style="height: {{ $row['height'] - 2 }}mm">@foreach($lines as $line)<div class="cell-line">{{ $line }}</div>@endforeach</td>@endforeach</tr>
                @empty
                    <tr><td colspan="5" style="height: 0; padding: 0; border: 0"></td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
        @if($lastPage)
            <table class="totals" style="top: {{ $document['table_top'] + 8.5 + $page['height'] + 4 }}mm">
                <tr><td class="total-label" rowspan="3">{{ $label('total') }}</td><td class="amount-label">{{ $document['currency'] }}</td><td class="amount">{{ $document['total'] }}</td></tr>
                <tr><td class="amount-label">{{ $label('ppn') }}</td><td class="amount">-</td></tr>
                <tr><td class="amount-label">{{ $label('total') }}</td><td class="amount">{{ $document['total'] }}</td></tr>
            </table>
            <div class="closing-notes" style="top: {{ $document['note_top'] }}mm">
                <table><tbody>@foreach($document['footer_lines'] as $line)<tr><td class="note-label"><div class="note-line">{{ $line['label'] }}</div></td><td style="width: 88%"><div class="note-line">{{ $line['text'] }}</div></td></tr>@endforeach</tbody></table>
            </div>
            <div class="ship-to">
                <div class="ship-title">{{ $label('ship_to') }}</div>
                <div class="ship-body"><div class="ship-line">PT ASTRA DAIDO STEEL INDONESIA</div><div class="ship-line">Kawasan Industri Green Land Cluster Batavia Blok AG/12</div><div class="ship-line">Delta Mas Cikarang Pusat - Phone : 021-899 73 241,242</div></div>
            </div>
            <table class="signatures">
                <thead><tr><th colspan="2"><div class="signature-title">{{ $label('ordered_by') }}</div></th><th style="width: 33.3333%"><div class="signature-title">{{ $label('supplier_confirmation') }}</div></th></tr></thead>
                <tbody><tr><td></td><td></td><td><span class="sign-hint">{{ $label('sign_stamp') }}</span></td></tr></tbody>
            </table>
        @endif
    </div>
@endforeach
</body>
</html>
