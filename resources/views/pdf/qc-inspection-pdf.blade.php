{{--
    Quality Control Inspection Report — fixed English form, styled after PNR261178.
    Monochrome: NG is carried by the literal status text plus row rules, never
    by a colour fill, so the report survives a black-and-white printer and a
    reader who cannot distinguish red from green (WCAG 1.4.1).

    Labels never follow the viewer's locale or regional preferences. Status text
    still goes through StatusHelper::qcLabel(), which returns OK/NG verbatim.
--}}
@php
    use App\Models\PrItem;
    use App\Support\BusinessTime;
    use App\Support\Pdf\FormDocument;
    use App\Support\StatusHelper;

    $label = fn (string $key, array $replace = []) => trans('documents.qc.'.$key, $replace, 'en');

    $po = $inspection->purchaseOrder;
    $requisition = $po?->quotations->first()?->purchaseRequisition;
    $period = $requisition?->period;

    $items = $inspection->items;
    $okItems = $items->where('status', 'ok')->count();
    $ngItems = $items->where('status', 'ng')->count();

    $dimension = fn ($value, int $precision) => $value ? FormDocument::number($value, $precision) : '-';

    // actual_arrival and estimated_arrival are date columns: no timezone shift.
    $arrivedDate = $po?->actual_arrival?->locale('en')->translatedFormat('d F Y') ?? '-';

    $documentTitle = $label('title');
    $documentNumber = $po?->po_number;
    $documentNumberLabel = $label('po_number_heading');
    $formKey = 'qc_inspection';
    if ($documentNumber) {
        $stamp = FormDocument::barcode($documentNumber);
        $stampImage = $stamp['barcode'];
        $stampWidthMm = $stamp['barcode_width'];
        $stampCaption = $documentNumber;
    }
@endphp
@extends('pdf.layout')

@section('document')
    {{-- Percentages, not millimetres: dompdf splits the columns equally when a
         fixed-layout table is sized in mm, which leaves a gap after each colon. --}}
    <table class="fields" style="width: 188.6mm; table-layout: fixed">
        <tr>
            <td class="field-label" style="width: 15%">{{ $label('supplier') }}</td>
            <td style="width: 2%">:</td>
            <td style="width: 35%">{{ $po?->supplier?->name ?: '-' }}</td>
            <td class="field-label" style="width: 16%">{{ $label('arrived_date') }}</td>
            <td style="width: 2%">:</td>
            <td style="width: 30%">{{ $arrivedDate }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('period') }}</td>
            <td>:</td>
            <td>{{ $period?->display_label ?: ($period?->name ?: '-') }}</td>
            <td class="field-label">{{ $label('inspection_date') }}</td>
            <td>:</td>
            <td>{{ $inspection->inspected_at ? BusinessTime::toBusiness($inspection->inspected_at)->locale('en')->translatedFormat('d F Y, H:i') : '-' }}</td>
        </tr>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td class="field-label">{{ $label('result') }}</td>
            <td>:</td>
            <td><strong>{{ StatusHelper::qcLabel($inspection->status) }}</strong></td>
        </tr>
    </table>

    <div class="spacer-5"></div>

    <table class="form-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 3.7%">{{ $label('no') }}</th>
                <th rowspan="2" style="width: 24%">{{ $label('material') }}</th>
                <th colspan="2" style="width: 14%">{{ $label('thickness') }}</th>
                <th colspan="2" style="width: 14%">{{ $label('width') }}</th>
                <th colspan="2" style="width: 14%">{{ $label('length') }}</th>
                <th rowspan="2" style="width: 6.3%">{{ $label('qty') }}</th>
                <th colspan="2" style="width: 17%">{{ $label('weight') }}</th>
                <th rowspan="2" style="width: 7%">{{ $label('status') }}</th>
            </tr>
            <tr>
                <th style="width: 7%">{{ $label('requested') }}</th>
                <th style="width: 7%">{{ $label('actual') }}</th>
                <th style="width: 7%">{{ $label('requested') }}</th>
                <th style="width: 7%">{{ $label('actual') }}</th>
                <th style="width: 7%">{{ $label('requested') }}</th>
                <th style="width: 7%">{{ $label('actual') }}</th>
                <th style="width: 8.5%">{{ $label('per_unit') }}</th>
                <th style="width: 8.5%">{{ $label('actual') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($items as $index => $item)
            @php
                $prItem = $item->prItem;
                // canonicalShape() returns 'Flat'/'Round'/'Hollow'; the lang keys are lowercase.
                $shapeKey = PrItem::canonicalShape($prItem?->shape);
                $materialText = trim((string) ($prItem?->material_name ?: '-'));
                if ($shapeKey) {
                    $shapeLine = trans('materials.shape.'.strtolower($shapeKey), [], 'en');
                    $materialText .= ' · '.(str_starts_with($shapeLine, 'materials.') ? $shapeKey : $shapeLine);
                }
            @endphp
            <tr class="{{ $item->status === 'ng' ? 'row-flagged' : '' }}">
                <td class="row-number">{{ $index + 1 }}</td>
                <td>@foreach(FormDocument::wrap($materialText, 42) as $line)<div class="cell-line">{{ $line }}</div>@endforeach</td>
                <td class="numeric">{{ $dimension($prItem?->thickness, 2) }}</td>
                <td class="numeric">{{ $dimension($item->actual_thickness, 2) }}</td>
                <td class="numeric">{{ $dimension($prItem?->width, 1) }}</td>
                <td class="numeric">{{ $dimension($item->actual_width, 1) }}</td>
                <td class="numeric">{{ $dimension($prItem?->length, 1) }}</td>
                <td class="numeric">{{ $dimension($item->actual_length, 1) }}</td>
                <td class="numeric">{{ FormDocument::number($prItem?->quantity_value ?? 0, 0) }}</td>
                <td class="numeric">{{ FormDocument::number($prItem?->weight_needed ?? 0, 0) }}</td>
                <td class="numeric">{{ $dimension($item->actual_weight, 0) }}</td>
                <td class="row-number flag-value">{{ StatusHelper::qcLabel($item->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="12">{{ $label('no_items') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <table class="totals" style="margin-top: 4mm">
        <tr>
            <td class="total-label" style="width: 40%">{{ $label('summary') }}</td>
            <td class="amount" style="width: 12%">{{ $label('total_items') }}</td>
            <td class="amount" style="width: 8%">{{ $items->count() }}</td>
            <td class="amount" style="width: 12%">{{ $label('item_ok') }}</td>
            <td class="amount" style="width: 8%">{{ $okItems }}</td>
            <td class="amount" style="width: 12%">{{ $label('item_ng') }}</td>
            <td class="amount" style="width: 8%">{{ $ngItems }}</td>
        </tr>
    </table>

    <table class="form-notes" style="margin-top: 4mm">
        <tr>
            <td class="note-label">{{ $label('conclusion') }}</td>
            <td>@foreach(FormDocument::wrap($inspection->status === 'ok' ? $label('conclusion_ok') : $label('conclusion_ng', ['count' => $ngItems]), 144, 8) as $line)<div class="note-line">{{ $line }}</div>@endforeach</td>
        </tr>
    </table>

    <div class="spacer-5"></div>

    <table class="signatures">
        <thead>
            <tr>
                <th style="width: 50%">{{ $label('inspected_by') }}</th>
                <th style="width: 50%">{{ $label('acknowledged_by') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><div class="signature-role">( {{ $label('signature_inspector') }} )</div></td>
                <td><div class="signature-role">( {{ $label('signature_production') }} )</div></td>
            </tr>
        </tbody>
    </table>
@endsection
