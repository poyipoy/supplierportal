<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class PortalPageController extends Controller
{
    public function __invoke(string $pageUuid): RedirectResponse
    {
        $routeName = config('page_registry.'.strtolower($pageUuid));
        abort_unless(is_string($routeName), 404);

        return redirect()->route($routeName);
    }
}
