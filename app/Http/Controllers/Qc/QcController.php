<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;

class QcController extends Controller
{
    public function dashboard()
    {
        return view('qc.dashboard');
    }
}
