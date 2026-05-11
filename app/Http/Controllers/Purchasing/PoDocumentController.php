<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoDocument;
use Illuminate\Http\Request;

class PoDocumentController extends Controller
{
    /**
     * Update status dokumen via AJAX.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string',
        ]);

        $doc = PoDocument::findOrFail($id);
        $doc->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => __('Status dokumen berhasil diperbarui.'),
            'doc' => [
                'id' => $doc->id,
                'doc_type' => $doc->doc_type,
                'status' => $doc->status,
                'updated_at' => $doc->updated_at->format('d M Y, H:i'),
            ]
        ]);
    }
}
