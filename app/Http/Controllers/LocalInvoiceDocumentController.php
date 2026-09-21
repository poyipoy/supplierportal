<?php

namespace App\Http\Controllers;

use App\Models\LocalInvoiceDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class LocalInvoiceDocumentController extends Controller
{
    public function show(LocalInvoiceDocument $document)
    {
        Gate::authorize('view', $document);
        abort_unless(Storage::disk('private')->exists($document->file_path), 404);

        return Storage::disk('private')->download($document->file_path, $document->document_type.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
