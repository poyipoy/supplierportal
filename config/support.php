<?php

$supplierWhatsapp = (string) env('SUPPLIER_SUPPORT_WHATSAPP', '082136753958');
$supplierWhatsappDigits = preg_replace('/\D+/', '', $supplierWhatsapp) ?? '';

if (str_starts_with($supplierWhatsappDigits, '0')) {
    $supplierWhatsappDigits = '62'.substr($supplierWhatsappDigits, 1);
} elseif (str_starts_with($supplierWhatsappDigits, '8')) {
    $supplierWhatsappDigits = '62'.$supplierWhatsappDigits;
}

$supplierWhatsappUrl = preg_match('/^[1-9][0-9]{7,14}$/', $supplierWhatsappDigits) === 1
    ? 'https://wa.me/'.$supplierWhatsappDigits
    : null;

return [
    'supplier_email' => env('SUPPLIER_SUPPORT_EMAIL', 'raihan.ramadhan@astra-daido.co.id'),
    'supplier_whatsapp' => $supplierWhatsapp,
    'supplier_whatsapp_url' => $supplierWhatsappUrl,
];
