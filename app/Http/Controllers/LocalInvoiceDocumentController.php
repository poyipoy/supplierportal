<?php

namespace App\Http\Controllers;

use App\Models\LocalInvoiceDocument;
use App\Services\FileSecurity\FileAccessGuard;
use App\Support\Security\InlinePreviewResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class LocalInvoiceDocumentController extends Controller
{
    public function show(Request $request, LocalInvoiceDocument $document)
    {
        Gate::authorize('view', $document);
        app(FileAccessGuard::class)->assertReadable($document);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');
        abort_unless($disk->exists($document->file_path), 404);

        $filename = $document->document_type.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
        $mimeType = $document->mime_type ?: $disk->mimeType($document->file_path);

        return InlinePreviewResponse::diskResponse(
            $disk,
            $document->file_path,
            $filename,
            $mimeType,
            $request->boolean('inline')
        );
    }
}
