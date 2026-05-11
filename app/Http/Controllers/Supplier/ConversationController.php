<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Conversation;

class ConversationController extends Controller
{
    /**
     * Daftar semua conversation milik supplier yang login.
     * WAJIB: filter supplier_user_id = auth()->id()
     */
    public function index()
    {
        $conversations = Conversation::with(['conversable', 'purchasingUser', 'latestMessage.sender'])
            ->where('supplier_user_id', auth()->id())
            ->get()
            ->sortByDesc(function ($conv) {
                return $conv->latestMessage?->created_at ?? $conv->created_at;
            });

        return view('supplier.conversations.index', compact('conversations'));
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
}
