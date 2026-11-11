<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\FileSecurity\FileAccessGuard;
use App\Support\Security\InlinePreviewResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment)
    {
        $this->authorize('view', $attachment);
        app(FileAccessGuard::class)->assertReadable($attachment);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, __('common.errors.file_not_found'));
        }

        $mimeType = $attachment->file_type ?: $disk->mimeType($attachment->file_path);

        return InlinePreviewResponse::diskResponse(
            $disk,
            $attachment->file_path,
            $attachment->file_name ?: 'attachment',
            $mimeType,
            ! $request->boolean('download')
        );
    }
}
