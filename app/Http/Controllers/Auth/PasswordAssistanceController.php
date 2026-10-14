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
        $configuredWhatsapp = config('support.supplier_whatsapp');
        $configuredWhatsappUrl = config('support.supplier_whatsapp_url');
        $supportWhatsapp = is_string($configuredWhatsapp)
            && trim($configuredWhatsapp) !== ''
            && is_string($configuredWhatsappUrl)
            && preg_match('#^https://wa\.me/[1-9][0-9]{7,14}$#', $configuredWhatsappUrl) === 1
                ? trim($configuredWhatsapp)
                : null;
        $supportWhatsappUrl = $supportWhatsapp === null ? null : $configuredWhatsappUrl;
        $subject = __('auth.password_assistance.subject');
        $template = str_replace(["\r\n", "\r"], "\n", __('auth.password_assistance.template'));
        $mailto = $supportEmail === null ? null : 'mailto:'.str_replace('%40', '@', rawurlencode($supportEmail)).'?'.http_build_query([
            'subject' => $subject,
            'body' => $template,
        ], '', '&', PHP_QUERY_RFC3986);

        return view('auth.forgot-password', compact('supportEmail', 'supportWhatsapp', 'supportWhatsappUrl', 'subject', 'template', 'mailto'));
    }
}
