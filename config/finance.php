<?php

$isProduction = env('APP_ENV') === 'production';

return [
    'maker_checker' => [
        'physical_receipt' => env('FINANCE_MAKER_CHECKER_PHYSICAL_RECEIPT', true),
        'section_verification' => env('FINANCE_MAKER_CHECKER_SECTION_VERIFICATION', true),
        'ready_to_pay' => env('FINANCE_MAKER_CHECKER_READY_TO_PAY', true),
        'batch_finalize' => env('FINANCE_MAKER_CHECKER_BATCH_FINALIZE', true),
        'voucher_settlement' => env('FINANCE_MAKER_CHECKER_VOUCHER_SETTLEMENT', true),
        'ga_basic_verify' => env('FINANCE_MAKER_CHECKER_GA_BASIC_VERIFY', true),
        'allow_admin_override' => env('FINANCE_MAKER_CHECKER_ALLOW_ADMIN_OVERRIDE', true),
    ],

    'limits' => [
        'ga_claim_max_amount' => env('FINANCE_GA_CLAIM_MAX_AMOUNT', $isProduction ? null : '100000000.00'),
        'bank_fee_max_amount' => env('FINANCE_BANK_FEE_MAX_AMOUNT', $isProduction ? null : '100000.00'),
    ],

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
        'finance_contact' => env('ADASI_FINANCE_CONTACT', env('ADASI_REFUND_CONTACT', 'raihan.ramadhan@astra-daido.co.id')),
        'finance_email' => env('ADASI_FINANCE_EMAIL', env('ADASI_REFUND_EMAIL', 'raihan.ramadhan@astra-daido.co.id')),
        'finance_wa' => env('ADASI_FINANCE_WA', env('ADASI_REFUND_WA', '082136753958')),
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
