<?php

namespace App\Http\Controllers\Admin\Concerns;

/**
 * Decision 28: the Material / HS Code master screens are served to Admin
 * (`admin.*`) and Purchasing (`purchasing.master-data.*`) by the same
 * controllers. Links and redirects follow the route group of the request.
 */
trait ResolvesMasterDataRoutes
{
    protected function masterRoutePrefix(): string
    {
        $name = (string) request()->route()?->getName();

        return str_starts_with($name, 'purchasing.master-data.') ? 'purchasing.master-data' : 'admin';
    }

    protected function masterIndexUrl(string $fragment): string
    {
        return route($this->masterRoutePrefix().'.material-hs-code.index').'#'.$fragment;
    }
}
