<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Sso\SsoTransactionStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SsoEntryController extends Controller
{
    public function start(Request $request, SsoTransactionStore $transactions): Response
    {
        if (! config('sso.enabled')) {
            return response(app()->call([app(AuthenticatedSessionController::class), 'create']));
        }

        $intended = $request->session()->pull('url.intended', $request->query('intended'));
        try {
            $response = redirect()->to($transactions->begin($request, $transactions->safeIntendedUrl($request, $intended)));
        } catch (Throwable) {
            $response = response()->view('auth.sso-error', ['message' => __('auth.sso.invalid_request')], 503);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
