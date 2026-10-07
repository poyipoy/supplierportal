<?php

$isProduction = env('APP_ENV') === 'production';

return [
    /*
    |--------------------------------------------------------------------------
    | Official ADASI Refund Account Configuration
    |--------------------------------------------------------------------------
    |
    | Used across the supplier portal to present official ADASI bank account
    | details for overpayment refund transfers and finance contact.
    | In production, explicit environment variables are required.
    |
    */
    'adasi_refund_account' => [
        'bank_name' => env('ADASI_REFUND_BANK', 'Bank Central Asia (BCA)'),
        'account_number' => env('ADASI_REFUND_ACCOUNT_NO', env('ADASI_REFUND_ACCOUNT_NUMBER', $isProduction ? null : '5220310053')),
        'account_holder' => env('ADASI_REFUND_ACCOUNT_HOLDER', 'PT Astra Daido Steel Indonesia'),
        'finance_contact' => env('ADASI_FINANCE_CONTACT', env('ADASI_REFUND_CONTACT', 'finance@adasi.co.id')),
        'finance_email' => env('ADASI_FINANCE_EMAIL', env('ADASI_REFUND_EMAIL', null)),
        'finance_wa' => env('ADASI_FINANCE_WA', env('ADASI_REFUND_WA', null)),
    ],

    /*
    |--------------------------------------------------------------------------
    | BCA Transfer Debit Account
    |--------------------------------------------------------------------------
    |
    | ADASI's BCA debiting account number used in the TARIKAN TRANSFER export
    | (columns D "Debited Acc." and L "Charges Acc.").
    | In production, explicit environment variables are required.
    |
    */
    'transfer_debit_account' => env('ADASI_TRANSFER_DEBIT_ACCOUNT', $isProduction ? null : '5220310053'),
];
