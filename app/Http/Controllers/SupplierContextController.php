<?php

namespace App\Http\Controllers;

use App\Support\PortalContext;
use Illuminate\Http\Request;

class SupplierContextController extends Controller
{
    public function index(Request $request)
    {
        return view('local-supplier.context', [
            'scopes' => $request->user()->supplierScopes()->pluck('scope'),
            'currentContext' => PortalContext::current($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'context' => ['required', 'string', 'in:'.PortalContext::SCOPE_IMPORT.','.PortalContext::SCOPE_LOCAL],
        ]);

        PortalContext::switchTo($request, $data['context']);

        return redirect(PortalContext::dashboard($request->user()));
    }
}
