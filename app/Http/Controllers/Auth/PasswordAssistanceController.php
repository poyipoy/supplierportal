<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PasswordAssistanceController extends Controller
{
    public function show(): View
    {
        $configured = config('support.supplier_email');
        $supportEmail = is_string($configured)
            && filter_var($configured, FILTER_VALIDATE_EMAIL)
            && ! preg_match('/[\r\n,]/', $configured) ? $configured : null;
        $subject = 'Supplier Portal - Password Assistance Request';
        $template = "Dear Support Team,\n\nI would like to request assistance resetting my Supplier Portal password.\n\nCompany Name: [Company Name]\nSupplier/Vendor Name: [Supplier/Vendor Name]\nRegistered Email: [Registered Email]\nContact Person: [Contact Person]\n\nPlease assist with the password reset process.\n\nThank you.";
        $mailto = $supportEmail === null ? null : 'mailto:'.str_replace('%40', '@', rawurlencode($supportEmail)).'?'.http_build_query([
            'subject' => $subject,
            'body' => $template,
        ], '', '&', PHP_QUERY_RFC3986);

        return view('auth.forgot-password', compact('supportEmail', 'subject', 'template', 'mailto'));
    }
}
