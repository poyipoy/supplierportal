<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserPreferenceService;
use App\Support\PortalContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocaleController extends Controller
{
    /**
     * Switch the application locale between 'en' and 'id'.
     */
    public function switch(Request $request, UserPreferenceService $preferences, string $locale = ''): Response
    {
        $targetLocale = $locale !== '' ? $locale : $request->input('locale');
        $validLocale = UserPreferenceService::normalizeLocale($targetLocale);
        $user = $request->user();
        if ($user instanceof User) {
            $validLocale = $request->isMethod('post')
                ? $preferences->saveLocale($user, $validLocale)['locale']
                : $preferences->for($user)['locale'];
        }

        $request->session()->put('locale', $validLocale);
        app()->setLocale($validLocale);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'locale' => $validLocale,
            ]);
        }

        $returnTo = $this->safeReturnPath($request->input('return_to'));
        if ($returnTo !== null) {
            return redirect()->to($returnTo);
        }

        return $user instanceof User
            ? redirect()->to(PortalContext::dashboard($user))
            : redirect()->route('supplier.register');
    }

    private function safeReturnPath(mixed $returnTo): ?string
    {
        if (! is_string($returnTo) || ! str_starts_with($returnTo, '/')) {
            return null;
        }

        $decoded = rawurldecode($returnTo);
        if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\r\n]/', $decoded)) {
            return null;
        }

        $parts = parse_url($decoded);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $returnTo;
    }
}
