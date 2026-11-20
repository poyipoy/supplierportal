<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PageRegistry;
use App\Support\PortalContext;
use App\Support\PortalContextCodec;
use App\Support\PortalSessionAuthorizer;
use App\Support\WorkspaceShell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PortalPageController extends Controller
{
    public function __invoke(Request $request, string $pageUuid): Response|RedirectResponse
    {
        // SEC-07: portal.page is strictly top-level (DENY / frame-ancestors 'none'); never allow iframe embedding.
        abort_if(WorkspaceShell::isEmbedRequest($request), 403);

        $normalizedUuid = strtolower($pageUuid);
        $contextAppUuid = strtolower((string) config('portal_urls.context_urls.app_uuid', ''));

        if ($contextAppUuid !== '' && $normalizedUuid === $contextAppUuid) {
            abort_unless(config('portal_urls.context_urls.enabled', false), 404);

            $user = $request->user();
            abort_unless($user instanceof User && $user->is_active, 404);

            if (! $request->query->has('favoriteContext') && ! $request->query->has('LogicalId')) {
                abort_if($request->query() !== [], 400);

                if (! WorkspaceShell::enabled()) {
                    return redirect()->to(PortalContext::dashboard($user));
                }

                if ($user->isSupplier() && PortalContext::current($user) === null) {
                    return redirect()->route('supplier-context.index');
                }

                if ($request->hasSession()) {
                    $request->session()->reflash();
                }

                return response()->view(
                    'layouts.workspace-shell',
                    WorkspaceShell::shellViewDataForShortcuts($request)
                );
            }

            $resolved = PortalContextCodec::resolve($request, $user);

            if (! WorkspaceShell::enabled()) {
                return redirect()->to($resolved['internalUrl']);
            }

            if ($resolved['requiredSupplierScope'] !== null && $request->hasSession()) {
                $request->session()->put('supplier_context', $resolved['requiredSupplierScope']);
            }

            if ($request->hasSession()) {
                $request->session()->reflash();
            }

            return response()->view(
                'layouts.workspace-shell',
                WorkspaceShell::shellViewDataForResolved($request, $resolved)
            );
        }

        $routeName = config('page_registry.'.$normalizedUuid);
        abort_unless(is_string($routeName), 404);
        abort_unless(PortalSessionAuthorizer::canAccess($request->user(), $routeName), 404);

        if (config('portal_urls.context_urls.enabled', false) && WorkspaceShell::enabled()) {
            $contextUrl = PageRegistry::contextUrl($routeName);
            if ($contextUrl !== null) {
                return redirect()->to($contextUrl);
            }
        }

        return redirect()->route($routeName);
    }
}
