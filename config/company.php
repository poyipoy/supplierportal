<?php

/*
|--------------------------------------------------------------------------
| Company Identity for Printed Forms
|--------------------------------------------------------------------------
|
| Single source for the letterhead printed on PDF forms. Before this file the
| QC report, the payment voucher and the PO form each carried their own
| hardcoded address, and the three disagreed. The PO form (F-PC-01-01-01:00,
| reference PNR261178) is the only one backed by a controlled document, so its
| address is the default here.
|
| `forms.*.code` is the controlled-document number printed at the top right of
| each form. Only the PO number is confirmed. The others stay null on purpose:
| an invented document number on an official form is worse than a blank one.
| Fill them from the document owner, not by guessing.
|
*/

return [
    'name' => env('COMPANY_NAME', 'PT ASTRA DAIDO STEEL INDONESIA'),

    'address' => env(
        'COMPANY_ADDRESS',
        'Kawasan Industri Green Land Cluster Batavia Blok AG/12, Delta Mas Cikarang Pusat'
    ),

    'phone' => env('COMPANY_PHONE', '021-899 73 241,242'),

    'email' => env('COMPANY_EMAIL'),

    'website' => env('COMPANY_WEBSITE', 'www.astra-daido.co.id'),

    // Scanned letterhead block used by the PO form; shared by the other forms.
    'header_image' => 'assets/images/po-company-header.png',

    'forms' => [
        'purchase_order' => ['code' => 'F-PC-01-01-01:00'],
        'qc_inspection' => ['code' => env('COMPANY_FORM_CODE_QC')],
        'payment_voucher' => ['code' => env('COMPANY_FORM_CODE_VOUCHER')],
        'invoice_receipt' => ['code' => env('COMPANY_FORM_CODE_INVOICE_RECEIPT')],
        'ga_claim_receipt' => ['code' => env('COMPANY_FORM_CODE_GA_RECEIPT')],
    ],
];
