{{--
    Reimbursement claim receipt — fixed English form, styled after PNR261178.

    The employee bank account is printed masked except for the last four digits.
    This is an acknowledgement of submission that can be downloaded and shared;
    Finance takes the full account details from the DRP transfer export, not
    from this receipt. The on-screen receipt page is unchanged.
--}}
@php
    use App\Support\BusinessTime;
    use App\Support\Pdf\FormDocument;

    $label = fn (string $key) => trans('documents.receipt.'.$key, [], 'en');
    $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');

    $maskAccount = function (?string $number): string {
        $digits = trim((string) $number);
        if ($digits === '') {
            return '-';
        }

        return mb_strlen($digits) <= 4
            ? $digits
            : str_repeat('*', mb_strlen($digits) - 4).mb_substr($digits, -4);
    };

    $employee = $claim->employee;
    $receipt = $claim->receipt;

    $documentTitle = $label('ga_title');
    $documentNumber = $receipt?->receipt_number;
    $documentNumberLabel = $label('number_heading');
    $formKey = 'ga_claim_receipt';
@endphp
@extends('pdf.layout')

@section('document')
    {{-- See the invoice receipt: percentage columns, QR beside the fields. --}}
    <table class="fields" style="width: 188.6mm; table-layout: fixed">
        <tr>
            <td class="field-label" style="width: 24%">{{ $label('claim_number') }}</td>
            <td style="width: 2%">:</td>
            <td style="width: 44%">{{ $claim->claim_number ?: '-' }}</td>
            <td rowspan="6" style="width: 30%; text-align: right; vertical-align: top">
                @if(! empty($qrCode))<img src="{{ $qrCode }}" style="width: 28mm; height: 28mm" alt="{{ $documentNumber }}">@endif
            </td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('employee') }}</td>
            <td>:</td>
            <td>{{ $employee?->name ?: '-' }}@if($employee?->department) ({{ $employee->department }})@endif</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('claim_type') }}</td>
            <td>:</td>
            <td>{{ $claim->claim_type ?: '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('claim_date') }}</td>
            <td>:</td>
            <td>{{ $claim->claim_date ? $claim->claim_date->locale('en')->translatedFormat('d F Y') : '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('bank_account') }}</td>
            <td>:</td>
            <td>{{ $employee?->bank_name ?: '-' }} {{ $maskAccount($employee?->account_number) }} / {{ $employee?->account_holder_name ?: '-' }}</td>
        </tr>
        <tr>
            <td class="field-label">{{ $label('submitted_at') }}</td>
            <td>:</td>
            <td>{{ $claim->created_at ? BusinessTime::toBusiness($claim->created_at)->locale('en')->translatedFormat('d F Y, H:i').' '.BusinessTime::label() : '-' }}</td>
        </tr>
    </table>

    <div class="spacer-5"></div>

    <table class="totals">
        <tr class="grand">
            <td class="total-label" style="width: 72%">{{ $label('amount') }}</td>
            <td class="amount" style="width: 28%">{{ $money($claim->amount) }}</td>
        </tr>
    </table>

    <table class="form-notes" style="margin-top: 4mm">
        <tr>
            <td class="note-label">{{ $label('note') }}</td>
            <td>@foreach(FormDocument::wrap(trans('ga.receipt.instruction', [], 'en').' '.$label('masked_account_hint'), 142, 8) as $line)<div class="note-line">{{ $line }}</div>@endforeach</td>
        </tr>
    </table>

@endsection
