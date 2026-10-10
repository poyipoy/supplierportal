<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\FileSecurity\FileAccessGuard;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AttachmentController extends Controller
{
    public function show(Attachment $attachment)
    {
        $this->authorize('view', $attachment);
        app(FileAccessGuard::class)->assertReadable($attachment);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, __('common.errors.file_not_found'));
        }

        $fileName = $this->safeDownloadName($attachment->file_name);
        $contentType = str_replace(
            ["\r", "\n"],
            '',
            $attachment->file_type ?: $disk->mimeType($attachment->file_path) ?: 'application/octet-stream'
        );

        return $disk->response(
            $attachment->file_path,
            $fileName,
            [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
            HeaderUtils::DISPOSITION_INLINE
        );
    }

    private function safeDownloadName(?string $fileName): string
    {
        $fileName = str_replace('\\', '/', $fileName ?: 'attachment');
        $fileName = basename($fileName);
        $fileName = str_replace(["\r", "\n"], '', $fileName);

        return trim($fileName) !== '' ? $fileName : 'attachment';
    }
}
