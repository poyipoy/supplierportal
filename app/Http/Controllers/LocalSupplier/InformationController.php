<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\Announcement;

class InformationController extends Controller
{
    public function index()
    {
        return view('local-supplier.information', ['announcements' => Announcement::whereNotNull('published_at')->latest('published_at')->paginate(10)]);
    }
}
