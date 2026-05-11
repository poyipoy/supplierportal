<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show($id)
    {
        $attachment = Attachment::findOrFail($id);

        if (!Storage::disk('private')->exists($attachment->file_path)) {
            abort(404, 'File not found.');
        }

        return response()->file(storage_path('app/private/' . $attachment->file_path));
    }
}
