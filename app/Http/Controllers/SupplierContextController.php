<?php

namespace App\Http\Controllers;

use App\Support\PortalContext;
use Illuminate\Http\Request;

class SupplierContextController extends Controller
{
    public function index(Request $request)
    {
        return view('local-supplier.context', ['scopes' => $request->user()->supplierScopes()->pluck('scope')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['context' => 'required|in:import,local']);
        abort_unless($request->user()->hasSupplierScope($data['context']), 403);
        $request->session()->put('supplier_context', $data['context']);

        return redirect(PortalContext::dashboard($request->user()));
    }
}
