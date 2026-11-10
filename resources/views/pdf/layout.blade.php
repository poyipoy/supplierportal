{{--
    Shared chrome for the fixed ADASI print forms (reference PNR261178).
    Monochrome by contract: no brand fills, no rounded corners, no colour as the
    only carrier of meaning. Geometry is millimetres, type is points, and the
    embedded Noto Sans comes from FormDocument so every form measures the same.

    The scanned letterhead already carries the address and phone, so no contact
    line and no full-width rule is drawn under it; PNR261178 has neither.

    Labels are fixed English on every form that extends this layout; do not add
    __() here. The PO form has its own blade and does not extend this file.

    Expected view data (all optional unless noted):
      $documentTitle        string  heading printed under the letterhead (required)
      $documentNumber       string  document number printed beside its label
      $documentNumberLabel  string  e.g. "PURCHASE ORDER NO." (bold; value is not)
      $formKey              string  key in config('company.forms') for the form code
      $stampImage           string  data URI for a barcode block, top right
      $stampCaption         string  caption centred under the barcode
      $stampWidthMm         float   printed barcode width in millimetres
      $copyMark             string  e.g. ORIGINAL / COPY, top right corner
      $letterheadOverrides  array   per-department letterhead, e.g. ['department' => '...']

    Sections: @section('document') and the optional @section('document-footer').
--}}
@php
    use App\Support\Pdf\FormDocument;

    $letterheadOverrides ??= [];
    $letterhead = array_merge(
        FormDocument::letterhead(),
        array_filter($letterheadOverrides, fn ($value) => $value !== null && $value !== '')
    );
    $department = $letterheadOverrides['department'] ?? null;
    $fonts = FormDocument::fonts();

    $documentTitle ??= '';
    $documentNumber ??= null;
    $documentNumberLabel ??= null;
    $formKey ??= null;
    $formCode = $formKey ? FormDocument::formCode($formKey) : null;
    $stampImage ??= null;
    $stampCaption ??= null;
    $stampWidthMm ??= 33.08;
    $copyMark ??= null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ trim($documentTitle.' '.($documentNumber ?? '')) }}</title>
    <style>
        @font-face { font-family: 'PO Noto Sans'; font-weight: normal; src: url('{{ $fonts['font_regular'] }}') format('truetype'); }
        @font-face { font-family: 'PO Noto Sans'; font-weight: bold; src: url('{{ $fonts['font_bold'] }}') format('truetype'); }

        body, div, table, td, th, p, h1, h2, dl, dt, dd { margin: 0; padding: 0; }
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: 'PO Noto Sans', sans-serif; font-size: 9pt; line-height: 4.1mm; color: #000; }

        /* ── Letterhead ── */
        .form-head { width: 190mm; border-collapse: collapse; margin-bottom: 6mm; }
        .form-head td { padding: 0; vertical-align: top; border: 0; }
        .head-identity { width: 112mm; }
        .head-identity img { display: block; width: 98.69mm; }
        .company-name { font-weight: bold; }
        .company-dept { font-size: 8pt; line-height: 3.8mm; font-weight: bold; }
        .company-address { font-size: 8pt; line-height: 3.8mm; }
        .head-stamp { width: 78mm; text-align: right; }
        .copy-mark, .form-code { font-size: 8pt; line-height: 3.8mm; }
        .stamp { display: inline-block; text-align: center; }
        .stamp img { display: block; margin: 0 auto; height: 8.8mm; }
        .stamp-caption { font-size: 6pt; line-height: 3mm; margin-top: 0.3mm; text-align: center; }

        /* ── Document title and number (PNR261178: bold label, plain value) ── */
        .document-title { font-weight: bold; font-size: 11pt; line-height: 5mm; }
        .document-number { width: 188.6mm; border-collapse: collapse; margin-top: 1.5mm; }
        .document-number td { border: 0; padding: 0; line-height: 4.1mm; }
        .doc-number-label { width: 55mm; font-weight: bold; }
        .page-number { text-align: right; white-space: pre; }

        /* ── Field blocks: label, colon, value ──
           Deliberately left on automatic table layout: dompdf ignores mm column
           widths when table-layout is fixed and splits the columns equally
           instead, which pushes the colon away from its value. Views that need
           deterministic columns here must use percentages, not millimetres. */
        .fields { border-collapse: collapse; }
        .fields td { padding: 0 1mm 0 0; border: 0; line-height: 4.1mm; vertical-align: top; }
        .fields .field-label { white-space: nowrap; }
        .fields .field-colon { width: 3mm; }

        /* ── Data tables ── */
        .form-table { width: 188.6mm; border-collapse: collapse; table-layout: fixed; }
        .form-table th { border: 0.35pt solid #555; font-weight: normal; text-align: center; padding: 1.4mm 1.8mm; vertical-align: middle; }
        .form-table td { border: 0.35pt solid #555; padding: 1.4mm 1.8mm; vertical-align: top; }
        .form-table .row-number { text-align: center; padding-left: 0; padding-right: 0; }
        .form-table .numeric, .form-table .right { text-align: right; }
        .form-table .ref-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .form-table .ref-table td { border: 0; padding: 0.35mm 0; line-height: 4.2mm; vertical-align: top; }
        .form-table .ref-table tr:first-child td { padding-top: 0; }
        .form-table .ref-table tr:last-child td { padding-bottom: 1.2mm; }
        .cell-line { height: 4.1mm; line-height: 4.1mm; white-space: pre; }
        .cell-sub { font-size: 7.5pt; line-height: 3.4mm; }

        /* A flagged row is marked by rules and bold text, never by fill alone. */
        .row-flagged td { border-top: 1pt solid #000; border-bottom: 1pt solid #000; }
        .row-flagged .flag-value { font-weight: bold; }

        /* ── Totals box ── */
        .totals { width: 188.6mm; border: 0.35pt solid #555; border-collapse: collapse; table-layout: fixed; }
        .totals td { padding: 1mm 1.5mm; line-height: 4.2mm; border: 0; }
        .totals .total-label { padding-left: 4mm; }
        .totals .amount { text-align: right; }
        .totals .grand td { font-weight: bold; }
        .totals .total-divider td { border-top: 0.35pt solid #555; padding-top: 1.4mm; padding-bottom: 1.4mm; }

        /* ── Notes: same label column as the field blocks ── */
        .form-notes { width: 188.6mm; border-collapse: collapse; table-layout: fixed; font-size: 8pt; }
        .form-notes td { vertical-align: top; line-height: 3.8mm; border: 0; padding: 0 1mm 0 0; }
        .form-notes .note-label { width: 22%; }
        .note-line { height: 3.8mm; line-height: 3.8mm; white-space: pre; }

        /* ── Signature boxes ── */
        .signatures { width: 188.6mm; border-collapse: collapse; table-layout: fixed; }
        .signatures th, .signatures td { border: 1pt solid #000; padding: 0; text-align: center; font-weight: normal; }
        .signatures th { font-size: 8pt; line-height: 3.8mm; height: 5.3mm; }
        .signatures td { height: 24.5mm; vertical-align: bottom; }
        .signature-role { font-size: 8pt; line-height: 3.8mm; padding-bottom: 1.5mm; }

        .spacer-3 { height: 3mm; }
        .spacer-5 { height: 5mm; }

        .sheet { position: relative; width: 190mm; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <table class="form-head">
        <tr>
            <td class="head-identity">
                @if($letterhead['header_image'])
                    <img src="{{ $letterhead['header_image'] }}" alt="{{ $letterhead['name'] }}">
                @else
                    <div class="company-name">{{ $letterhead['name'] }}</div>
                    <div class="company-address">{{ $letterhead['address'] }}</div>
                @endif
                @if($department)
                    <div class="company-dept">{{ $department }}</div>
                @endif
            </td>
            <td class="head-stamp">
                @if($copyMark)<div class="copy-mark">{{ $copyMark }}</div>@endif
                @if($formCode)<div class="form-code">{{ $formCode }}</div>@endif
                @if($stampImage)
                    <div class="stamp">
                        <img src="{{ $stampImage }}" style="width: {{ $stampWidthMm }}mm" alt="{{ $documentNumber }}">
                        @if($stampCaption)<div class="stamp-caption">{{ $stampCaption }}</div>@endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    @if($documentTitle !== '')
        <div class="document-title">{{ $documentTitle }}</div>
    @endif
    @if($documentNumber)
        <table class="document-number">
            <tr>
                <td class="doc-number-label">{{ $documentNumberLabel }}</td>
                <td>{{ $documentNumber }}</td>
                <td class="page-number">{{ trans('documents.po.page', ['current' => $currentPage ?? 1, 'total' => $totalPages ?? 1], 'en') }}</td>
            </tr>
        </table>
    @endif

    <div class="spacer-5"></div>

    @yield('document')

    @yield('document-footer')
</body>
</html>
