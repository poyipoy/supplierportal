<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Daftar semua conversation milik purchasing user yang login.
     */
    public function index()
    {
        $conversations = Conversation::with(['conversable', 'supplierUser', 'latestMessage.sender'])
            ->where('purchasing_user_id', auth()->id())
            ->get()
            ->sortByDesc(function ($conv) {
                return $conv->latestMessage?->created_at ?? $conv->created_at;
            });

        return view('purchasing.conversations.index', compact('conversations'));
    }

    /**
     * Tampilkan halaman chat.
     */
    public function show($id)
    {
        $conversation = Conversation::with(['conversable', 'supplierUser', 'purchasingUser', 'messages.sender'])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        // Mark all unread messages as read
        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('conversations.show', compact('conversation'));
    }

    /**
     * Mulai conversation baru atau redirect ke yang sudah ada.
     * Konteks: PR (Purchase Requirement).
     */
    public function startFromPr($pr_id, $supplier_id)
    {
        $pr = PurchaseRequirement::findOrFail($pr_id);

        // Validasi: supplier harus punya quotation pada PR ini
        $hasQuotation = Quotation::where('pr_id', $pr_id)
            ->where('supplier_id', $supplier_id)
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted'])
            ->exists();

        if (!$hasQuotation) {
            return back()->with('error', 'Supplier ini belum memiliki penawaran pada permintaan ini.');
        }

        // Cari conversation yang sudah ada
        $conversation = Conversation::where('conversable_type', PurchaseRequirement::class)
            ->where('conversable_id', $pr_id)
            ->where('purchasing_user_id', auth()->id())
            ->where('supplier_user_id', $supplier_id)
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'conversable_type' => PurchaseRequirement::class,
                'conversable_id' => $pr_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $supplier_id,
            ]);
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
            ]);
        }

        $showParameters = [$conversation->id];
        if (PurchasingNavigation::isSafeUrl(request()->input('return_url'))) {
            $showParameters['return_url'] = request()->input('return_url');
        }

        return redirect()->route('purchasing.conversations.show', $showParameters);
    }

    /**
     * Mulai conversation baru atau redirect ke yang sudah ada.
     * Konteks: PO (Purchase Order).
     */
    public function startFromPo($po_id)
    {
        $po = PurchaseOrder::findOrFail($po_id);
        $supplier_id = $po->supplier_id;

        // Cari conversation yang sudah ada
        $conversation = Conversation::where('conversable_type', PurchaseOrder::class)
            ->where('conversable_id', $po_id)
            ->where('purchasing_user_id', auth()->id())
            ->where('supplier_user_id', $supplier_id)
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'conversable_type' => PurchaseOrder::class,
                'conversable_id' => $po_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $supplier_id,
            ]);
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
            ]);
        }

        $showParameters = [$conversation->id];
        if (PurchasingNavigation::isSafeUrl(request()->input('return_url'))) {
            $showParameters['return_url'] = request()->input('return_url');
        }

        return redirect()->route('purchasing.conversations.show', $showParameters);
    }
}
