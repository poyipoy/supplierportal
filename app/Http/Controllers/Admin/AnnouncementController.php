<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::with('creator')->orderBy('created_at', 'desc')->paginate(15);
        return view('admin.announcements.index', compact('announcements'));
    }

    public function create() { return view('admin.announcements.create'); }

    public function store(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255', 'content' => 'required|string']);
        Announcement::create([
            'title' => $request->title, 'content' => $request->content,
            'created_by' => auth()->id(),
            'published_at' => $request->has('is_published') ? now() : null,
        ]);
        return redirect()->route('admin.announcements.index')->with('success', __('Pengumuman berhasil dibuat.'));
    }

    public function edit(Announcement $announcement) { return view('admin.announcements.edit', compact('announcement')); }

    public function update(Request $request, Announcement $announcement)
    {
        $request->validate(['title' => 'required|string|max:255', 'content' => 'required|string']);
        $announcement->update([
            'title' => $request->title, 'content' => $request->content,
            'published_at' => $request->has('is_published') ? ($announcement->published_at ?? now()) : null,
        ]);
        return redirect()->route('admin.announcements.index')->with('success', __('Pengumuman berhasil diperbarui.'));
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return redirect()->route('admin.announcements.index')->with('success', __('Pengumuman berhasil dihapus.'));
    }

    public function togglePublish(Announcement $announcement)
    {
        $announcement->update(['published_at' => $announcement->published_at ? null : now()]);
        return back()->with('success', $announcement->published_at ? __('Pengumuman dipublish.') : __('Pengumuman ditarik.'));
    }
}
