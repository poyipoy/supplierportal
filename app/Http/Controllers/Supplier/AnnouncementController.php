<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Announcement;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::whereNotNull('published_at')->orderBy('published_at', 'desc')->paginate(10);
        return view('supplier.announcements.index', compact('announcements'));
    }

    public function show(Announcement $announcement)
    {
        if (!$announcement->published_at) abort(404);
        return view('supplier.announcements.show', compact('announcement'));
    }
}
