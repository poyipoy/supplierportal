<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\NotificationMute;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\ConversationMessageService;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use App\Support\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationMessageController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationPreferenceService $preferenceService,
    ) {}

    /**
     * Conversation list for the chat drawer.
     */
    public function drawerIndex()
    {
        $conversations = Conversation::with([
            'conversable',
            'purchasingUser.supplier',
            'supplierUser.supplier',
            'latestMessage.sender',
        ])
            ->withMax('messages', 'created_at')
            ->withCount([
                'messages as unread_messages_count' => fn ($query) => $query
                    ->where('sender_id', '!=', auth()->id())
                    ->whereNull('read_at'),
            ])
            ->forUser(auth()->id())
            ->when(request()->filled('q'), function ($query) {
                $keyword = trim((string) request('q'));

                $query->where(function ($q) use ($keyword) {
                    $q->whereHas('purchasingUser', fn ($user) => $user->where('name', 'like', "%{$keyword}%"))
                        ->orWhereHas('supplierUser', fn ($user) => $user
                            ->where('name', 'like', "%{$keyword}%")
                            ->orWhereHas('supplier', fn ($supplier) => $supplier->where('company_name', 'like', "%{$keyword}%")))
                        ->orWhereHasMorph('conversable', [PurchaseRequisition::class], fn ($pr) => $pr->where('pr_number', 'like', "%{$keyword}%"))
                        ->orWhereHasMorph('conversable', [PurchaseOrder::class], fn ($po) => $po->where('po_number', 'like', "%{$keyword}%"));
                });
            })
            ->orderByDesc(DB::raw('COALESCE(messages_max_created_at, conversations.updated_at, conversations.created_at)'))
            ->limit(50)
            ->get()
            ->values()
            ->map(fn (Conversation $conversation) => $this->formatConversation($conversation));

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    /**
     * Conversation details and messages for the chat drawer.
     */
    public function drawerShow($id)
    {
        $conversation = Conversation::with([
            'conversable',
            'purchasingUser.supplier',
            'supplierUser.supplier',
            'latestMessage.sender',
        ])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $hasMore = $conversation->loadRecentMessages();

        return response()->json([
            'conversation' => $this->formatConversation($conversation),
            'context' => ConversationPresenter::context($conversation, auth()->user()),
            'quick_actions' => ConversationPresenter::quickActions($conversation, auth()->user()),
            'templates' => ConversationPresenter::templates($conversation, auth()->user()),
            'messages' => $conversation->messages->map(fn (Message $message) => $this->formatMessage($message)),
            'has_more_messages' => $hasMore,
        ]);
    }

    /**
     * Send a new message to the conversation.
     */
    public function store(Request $request, $id, ConversationMessageService $messageService)
    {
        $body = StoreConversationMessageRequest::validateMessage($request);

        $conversation = Conversation::findOrFail($id);
        $this->authorize('message', $conversation);

        $message = $messageService->sendMessage($conversation, $request, $body);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $this->formatMessage($message),
                'conversation' => $this->formatConversation($conversation->fresh(['latestMessage.sender'])),
            ]);
        }

        return back();
    }

    /**
     * Mark all messages in the conversation as read.
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

    public function mute(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $this->preferenceService->mute(
            auth()->user(),
            NotificationMute::TYPE_CONVERSATION,
            $conversation->id
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'muted' => true,
                'conversation_id' => $conversation->getRouteKey(),
            ]);
        }

        return back()->with('success', __('notifications.conversation.muted'));
    }

    public function unmute(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $this->preferenceService->unmute(
            auth()->user(),
            NotificationMute::TYPE_CONVERSATION,
            $conversation->id
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'muted' => false,
                'conversation_id' => $conversation->getRouteKey(),
            ]);
        }

        return back()->with('success', __('notifications.conversation.unmuted'));
    }

    public function quickAction(Request $request, $id, ConversationMessageService $messageService): JsonResponse
    {
        $conversation = Conversation::with(['supplierUser.supplier', 'purchasingUser'])->findOrFail($id);
        $this->authorize('message', $conversation);

        if (auth()->user()->role !== 'purchasing') {
            abort(403, __('notifications.conversation.purchasing_only'));
        }

        $validated = StoreConversationMessageRequest::validateQuickAction($request);
        $message = $messageService->executeQuickAction($conversation, $validated);

        return response()->json([
            'success' => true,
            'message' => $this->formatMessage($message->load(['sender', 'attachments'])),
            'conversation' => $this->formatConversation($conversation->fresh(['latestMessage.sender'])),
            'context' => ConversationPresenter::context($conversation->fresh(['conversable', 'supplierUser.supplier', 'purchasingUser']), auth()->user()),
            'quick_actions' => ConversationPresenter::quickActions($conversation, auth()->user()),
        ]);
    }

    /**
     * AJAX endpoint for polling new messages.
     */
    public function latest(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $afterId = max(0, (int) $request->query('after', 0));

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->where('id', '>', $afterId)
            ->get();

        // Mark new messages from the other party as read.
        if ($messages->where('sender_id', '!=', auth()->id())->count() > 0) {
            $conversation->messages()
                ->where('sender_id', '!=', auth()->id())
                ->where('id', '>', $afterId)
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'messages' => $messages->map(fn (Message $message) => $this->formatMessage($message)),
            'read_receipts' => $conversation->messages()
                ->where('sender_id', auth()->id())
                ->whereNotNull('read_at')
                ->latest('read_at')
                ->limit(100)
                ->get(['id', 'read_at'])
                ->map(fn (Message $message) => [
                    'id' => $message->id,
                    'read_at' => $message->read_at?->toIso8601String(),
                    'read_at_display' => $message->read_at?->format('H:i'),
                ]),
        ]);
    }

    /**
     * The page of messages before ?before=<id>, oldest first, for "load earlier".
     */
    public function older(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);
        $before = (int) $request->validate(['before' => ['required', 'integer', 'min:1']])['before'];

        [$messages, $hasMore] = $conversation->messagesBefore($before);

        return response()->json([
            'messages' => $messages->map(fn (Message $message) => $this->formatMessage($message))->values(),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Get global unread chat count for the logged in user.
     */
    public function unreadCount()
    {
        if (session()->isStarted()) {
            session()->save();
        }

        $userId = auth()->id();
        $count = Message::query()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->whereHas('conversation', fn ($query) => $query->forUser($userId))
            ->count();

        return response()->json(['count' => $count]);
    }

    private function formatConversation(Conversation $conversation): array
    {
        $partner = $conversation->getPartner(auth()->id());
        $latestMessage = $conversation->latestMessage;

        return [
            'id' => $conversation->getRouteKey(),
            'context_label' => $conversation->context_label,
            'context_type' => $conversation->conversable_type === PurchaseRequisition::class ? 'PR' : 'PO',
            'partner_name' => $this->displayName($partner),
            'partner_role' => $partner?->role,
            'latest_preview' => $latestMessage ? Str::limit($latestMessage->body, 70) : __('notifications.conversation.no_messages'),
            'latest_time' => $latestMessage?->created_at?->copy()->locale(app()->getLocale())->diffForHumans(),
            'latest_at' => $latestMessage?->created_at?->toIso8601String(),
            'unread_count' => array_key_exists('unread_messages_count', $conversation->getAttributes())
                ? (int) $conversation->unread_messages_count
                : $conversation->unreadCountFor(auth()->id()),
            'status' => $conversation->status ?? Conversation::STATUS_OPEN,
            'status_label' => $conversation->statusLabelFor(auth()->user()),
            'status_badge_class' => $conversation->statusBadgeClassFor(auth()->user()),
            'sla' => ConversationPresenter::slaMeta($conversation, auth()->user()),
            'muted' => auth()->check()
                ? $this->preferenceService->isMuted(auth()->user(), NotificationMute::TYPE_CONVERSATION, $conversation->id)
                : false,
        ];
    }

    private function formatMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name ?? __('notifications.conversation.user'),
            'sender' => [
                'id' => $message->sender_id,
                'name' => $message->sender?->name ?? __('notifications.conversation.user'),
            ],
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'time' => $message->created_at ? BusinessTime::format($message->created_at, 'H:i', false) : null,
            'is_me' => $message->sender_id === auth()->id(),
            'is_read' => $message->sender_id === auth()->id() && $message->read_at !== null,
            'read_at' => $message->read_at?->toIso8601String(),
            'read_at_display' => $message->read_at ? BusinessTime::format($message->read_at, 'H:i', false) : null,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->file_name,
                'type' => $attachment->file_type,
                'url' => route('attachments.show', $attachment),
            ])->values(),
        ];
    }

    private function displayName(?User $user): string
    {
        if (! $user) {
            return __('notifications.conversation.user');
        }

        if ($user->role === 'supplier') {
            return $user->supplier->company_name ?? $user->name;
        }

        return $user->name;
    }
}
