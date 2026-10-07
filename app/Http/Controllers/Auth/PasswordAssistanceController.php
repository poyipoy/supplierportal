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
        $subject = __('auth.password_assistance.subject');
        $template = str_replace(["\r\n", "\r"], "\n", __('auth.password_assistance.template'));
        $mailto = $supportEmail === null ? null : 'mailto:'.str_replace('%40', '@', rawurlencode($supportEmail)).'?'.http_build_query([
            'subject' => $subject,
            'body' => $template,
        ], '', '&', PHP_QUERY_RFC3986);

        return view('auth.forgot-password', compact('supportEmail', 'subject', 'template', 'mailto'));
    }
}
