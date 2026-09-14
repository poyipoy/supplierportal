<?php

namespace App\Http\Controllers;

use App\Models\GaClaimDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class GaClaimDocumentController extends Controller
{
    public function show(GaClaimDocument $document)
    {
        Gate::authorize('view', $document);
        abort_unless(Storage::disk('private')->exists($document->file_path), 404);

        $filename = $document->document_type.'_'.($document->original_filename ?: basename($document->file_path));

        return Storage::disk('private')->download(
            $document->file_path,
            $filename,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']
        );
    }
}
