<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AttachmentController extends Controller
{
    public function show($id)
    {
        $attachment = Attachment::findOrFail($id);

        $this->authorize('view', $attachment);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        if (! $disk->exists($attachment->file_path)) {
            abort(404, 'File not found.');
        }

        $fileName = $this->safeDownloadName($attachment->file_name);
        $fallbackName = $this->asciiFallbackName($fileName);
        $contentType = str_replace(
            ["\r", "\n"],
            '',
            $attachment->file_type ?: $disk->mimeType($attachment->file_path) ?: 'application/octet-stream'
        );

        return response()->file($disk->path($attachment->file_path), [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $fileName,
                $fallbackName
            ),
        ]);
    }

    private function safeDownloadName(?string $fileName): string
    {
        $fileName = str_replace('\\', '/', $fileName ?: 'attachment');
        $fileName = basename($fileName);
        $fileName = str_replace(["\r", "\n"], '', $fileName);

        return trim($fileName) !== '' ? $fileName : 'attachment';
    }

    private function asciiFallbackName(string $fileName): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'attachment';
        $fallback = trim($fallback, '._');

        return $fallback !== '' ? $fallback : 'attachment';
    }
}
