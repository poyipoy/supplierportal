{{--
    Invoice submission receipt — fixed English form, styled after PNR261178.
    The QR sits at the foot of the sheet, in the space the fields leave free.
--}}
@php
    use App\Support\BusinessTime;
    use App\Support\Money;
    use App\Support\Pdf\FormDocument;

    $label = fn (string $key) => trans('documents.receipt.'.$key, [], 'en');
    $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');

    $receipt = $invoice->receipt;
    $supplierName = $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name;
    $totalAmount = Money::add($invoice->invoice_amount, $invoice->tax_amount);

    $documentTitle = $label('invoice_title');
    $documentNumber = $receipt?->receipt_number;
    $documentNumberLabel = $label('number_heading');
    $formKey = 'invoice_receipt';
@endphp
@extends('pdf.layout')

@section('document')
    {{-- Percentages, not millimetres: dompdf splits the columns equally when a
         fixed-layout table is sized in mm, which leaves a gap after each colon.
         The QR sits in the free column beside the fields, not at the page foot. --}}
    <table class="fields" style="width: 188.6mm; table-layout: fixed">
        <tr>
            <td class="field-label" style="width: 24%">{{ $label('submission_number') }}</td>
            <td style="width: 2%">:</td>
            <td style="width: 44%">{{ $invoice->submission_number ?: '-' }}</td>
            <td rowspan="6" style="width: 30%; text-align: right; vertical-align: top">
                @if(! empty($qrCode))<img src="{{ $qrCode }}" style="width: 28mm; height: 28mm" alt="{{ $documentNumber }}">@endif
            </td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('invoice_number') }}</td>
            <td>:</td>
            <td>{{ $invoice->invoice_number ?: '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('po_number') }}</td>
            <td>:</td>
            <td>{{ $invoice->po_number ?: '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('supplier') }}</td>
            <td>:</td>
            <td>{{ $supplierName ?: '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('submitted_at') }}</td>
            <td>:</td>
            <td>{{ $invoice->submitted_at ? BusinessTime::toBusiness($invoice->submitted_at)->locale('en')->translatedFormat('d F Y, H:i').' '.BusinessTime::label() : '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('revision') }}</td>
            <td>:</td>
            <td>{{ $invoice->revision_number }}</td>
        </tr>
    </table>

    <div class="spacer-5"></div>

    <table class="totals">
        <tr>
            <td class="total-label" style="width: 72%">{{ $label('dpp') }}</td>
            <td class="amount" style="width: 28%">{{ $money($invoice->invoice_amount) }}</td>
        </tr>
        <tr>
            <td class="total-label">{{ $label('ppn') }}</td>
            <td class="amount">{{ $money($invoice->tax_amount) }}</td>
        </tr>
        <tr class="grand total-divider">
            <td class="total-label">{{ $label('total_invoice') }}</td>
            <td class="amount">{{ $money($totalAmount) }}</td>
        </tr>
    </table>

    <table class="form-notes" style="margin-top: 4mm">
        <tr>
            <td class="note-label">{{ $label('note') }}</td>
            <td>@foreach(FormDocument::wrap(trans('local_invoice.receipt.instruction', [], 'en'), 142, 8) as $line)<div class="note-line">{{ $line }}</div>@endforeach</td>
        </tr>
    </table>

@endsection
