<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
use App\Support\JsTranslations;
use App\Support\PortalContext;
use App\Support\PortalPath;
use App\Support\WorkspaceShell;
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
            $payload = [
                'status' => 'success',
                'locale' => $validLocale,
                'pagination_label' => __('pagination.navigation'),
                'i18n' => JsTranslations::payload(),
                'datatables' => __('datatables'),
                'session_copy' => [
                    'restored' => __('security.session_reconnect.restored'),
                    'failed' => __('security.session_reconnect.failed'),
                ],
            ];

            if (
                $request->isMethod('post')
                && $user instanceof User
                && $request->boolean('include_shell')
                && WorkspaceShell::enabled()
            ) {
                $shellPayload = $this->buildShellChromePayload($request, $user, $preferences);
                $payload['shell_html'] = $shellPayload['shell_html'];
                $payload['workspace_config'] = $shellPayload['workspace_config'];
            }

            return response()
                ->json($payload)
                ->header('Cache-Control', 'no-store, private');
        }

        $returnTo = $this->safeReturnPath($request->input('return_to'));
        if ($returnTo !== null) {
            return redirect()->to(PortalPath::stripBaseUrl($returnTo, $request));
        }

        return $user instanceof User
            ? redirect()->to(PortalContext::dashboard($user))
            : redirect()->route('supplier.register');
    }

    /**
     * Build lightweight, script-free server-rendered shell chrome and localized config for
     * single-round-trip Workspace Shell locale switching. Strictly derived from authenticated user.
     *
     * @return array{shell_html: string, workspace_config: array<string, mixed>}
     */
    private function buildShellChromePayload(Request $request, User $user, UserPreferenceService $preferences): array
    {
        $workspaceConfig = WorkspaceShell::localeShellConfig($user, $request);
        $userPrefs = $preferences->for($user);
        $quickAccess = app(QuickAccessService::class);
        $quickAccessItems = $quickAccess->selectedFor(
            $user,
            $userPrefs['quick_access'] ?? [],
            $quickAccess->contextFor($user),
        );
        $dashboardUrl = PortalContext::dashboard($user, absolute: true);

        $rendered = view('partials.locale-shell-chrome', [
            'isWorkspaceShell' => true,
            'initialMode' => 'shortcuts',
            'isShortcutsInitial' => true,
            'initNotifCount' => 0,
            'initChatCount' => 0,
            'dashboardUrl' => $dashboardUrl,
            'quickAccessItems' => $quickAccessItems,
            'workspaceConfig' => $workspaceConfig,
        ])->render();

        // morphDomTree ignores <script> and <style>; stripping them cuts payload size by ~75%.
        $stripped = preg_replace('/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/i', '', $rendered);

        return [
            'shell_html' => is_string($stripped) ? $stripped : $rendered,
            'workspace_config' => $workspaceConfig,
        ];
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
