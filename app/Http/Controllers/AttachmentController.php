<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show($id)
    {
        $attachment = Attachment::findOrFail($id);
        $disk = Storage::disk('private');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, 'File not found.');
        }

        return response()->file($disk->path($attachment->file_path), [
            'Content-Type' => $attachment->file_type ?: $disk->mimeType($attachment->file_path),
            'Content-Disposition' => 'inline; filename="' . addslashes($attachment->file_name) . '"',
        ]);
    }
}
