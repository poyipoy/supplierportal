<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationMessageController extends Controller
{
    /**
     * Daftar conversation untuk chat drawer.
     */
    public function drawerIndex()
    {
        $conversations = Conversation::with([
                'conversable',
                'purchasingUser.supplier',
                'supplierUser.supplier',
                'latestMessage.sender',
            ])
            ->forUser(auth()->id())
            ->get()
            ->sortByDesc(function ($conversation) {
                return $conversation->latestMessage?->created_at ?? $conversation->created_at;
            })
            ->values()
            ->map(fn (Conversation $conversation) => $this->formatConversation($conversation));

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    /**
     * Detail conversation + pesan untuk chat drawer.
     */
    public function drawerShow($id)
    {
        $conversation = Conversation::with([
                'conversable',
                'purchasingUser.supplier',
                'supplierUser.supplier',
                'messages.sender',
                'latestMessage.sender',
            ])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'conversation' => $this->formatConversation($conversation),
            'messages' => $conversation->messages->map(fn (Message $message) => $this->formatMessage($message)),
        ]);
    }

    /**
     * Kirim pesan baru ke conversation.
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $conversation = Conversation::findOrFail($id);
        $this->authorize('message', $conversation);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $request->body,
        ]);

        // Kirim notifikasi ke lawan bicara jika dia tidak sedang membuka chat
        $partner = $conversation->getPartner(auth()->id());
        
        // Cek apakah pesan terakhir dari partner sudah dibaca dalam 30 detik terakhir
        // Atau asumsikan partner offline dan kirim notifikasi selalu (simplifikasi)
        if ($partner) {
            $senderName = auth()->user()->name;
            $preview = Str::limit($message->body, 50);
            
            // Determine the correct route for the notification URL based on partner's role
            $routePrefix = $partner->role === 'purchasing' ? 'purchasing' : 'supplier';
            $url = route("{$routePrefix}.conversations.show", $conversation->id);

            $partner->notify(new SystemNotification(
                'Pesan baru dari ' . $senderName,
                $preview,
                $url,
                'bi-chat-dots'
            ));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message->load('sender'),
            ]);
        }

        return back();
    }

    /**
     * Tandai semua pesan di conversation sebagai sudah dibaca (untuk event scroll dsb).
     */
    public function markRead($id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Endpoint AJAX untuk polling pesan baru.
     */
    public function latest(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $afterId = $request->query('after', 0);

        $messages = $conversation->messages()
            ->with('sender')
            ->where('id', '>', $afterId)
            ->get();

        // Tandai sebagai sudah dibaca jika ada pesan baru dari lawan bicara
        if ($messages->where('sender_id', '!=', auth()->id())->count() > 0) {
            $conversation->messages()
                ->where('sender_id', '!=', auth()->id())
                ->where('id', '>', $afterId)
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'messages' => $messages->map(fn (Message $message) => $this->formatMessage($message)),
        ]);
    }

    /**
     * Get global unread chat count for the logged in user.
     */
    public function unreadCount()
    {
        $count = Conversation::forUser(auth()->id())
            ->withCount(['messages' => function($q) {
                $q->where('sender_id', '!=', auth()->id())->whereNull('read_at');
            }])
            ->get()
            ->sum('messages_count');

        return response()->json(['count' => $count]);
    }

    private function formatConversation(Conversation $conversation): array
    {
        $partner = $conversation->getPartner(auth()->id());
        $latestMessage = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'context_label' => $conversation->context_label,
            'context_type' => $conversation->conversable_type === \App\Models\PurchaseRequirement::class ? 'PR' : 'PO',
            'partner_name' => $this->displayName($partner),
            'partner_role' => $partner?->role,
            'latest_preview' => $latestMessage ? Str::limit($latestMessage->body, 70) : 'Belum ada pesan',
            'latest_time' => $latestMessage?->created_at?->diffForHumans(),
            'latest_at' => $latestMessage?->created_at?->toIso8601String(),
            'unread_count' => $conversation->unreadCountFor(auth()->id()),
        ];
    }

    private function formatMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name ?? 'User',
            'sender' => [
                'id' => $message->sender_id,
                'name' => $message->sender?->name ?? 'User',
            ],
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'time' => $message->created_at?->format('H:i'),
            'is_me' => $message->sender_id === auth()->id(),
        ];
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'User';
        }

        if ($user->role === 'supplier') {
            return $user->supplier->company_name ?? $user->name;
        }

        return $user->name;
    }
}
