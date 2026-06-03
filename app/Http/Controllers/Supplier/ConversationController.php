<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\ConversationPresenter;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * Daftar semua conversation milik supplier yang login.
     * WAJIB: filter supplier_user_id = auth()->id()
     */
    public function index()
    {
        $conversations = Conversation::with(['conversable', 'purchasingUser', 'latestMessage.sender'])
            ->withMax('messages', 'created_at')
            ->where('supplier_user_id', auth()->id())
            ->orderByDesc(DB::raw('COALESCE(messages_max_created_at, conversations.updated_at, conversations.created_at)'))
            ->paginate(25);

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

        $conversation->load(['messages.sender', 'messages.attachments', 'latestMessage.sender']);

        $chatContext = ConversationPresenter::context($conversation, auth()->user());
        $quickActions = ConversationPresenter::quickActions($conversation, auth()->user());
        $messageTemplates = ConversationPresenter::templates($conversation, auth()->user());

        return view('conversations.show', compact('conversation', 'chatContext', 'quickActions', 'messageTemplates'));
    }
}
