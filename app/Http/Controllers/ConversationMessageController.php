<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\ConversationPresenter;
use App\Support\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationMessageController extends Controller
{
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
            ->forUser(auth()->id())
            ->when(request()->filled('q'), function ($query) {
                $keyword = trim((string) request('q'));

                $query->where(function ($q) use ($keyword) {
                    $q->whereHas('purchasingUser', fn ($user) => $user->where('name', 'like', "%{$keyword}%"))
                        ->orWhereHas('supplierUser', fn ($user) => $user
                            ->where('name', 'like', "%{$keyword}%")
                            ->orWhereHas('supplier', fn ($supplier) => $supplier->where('company_name', 'like', "%{$keyword}%")))
                        ->orWhereHasMorph('conversable', [\App\Models\PurchaseRequisition::class], fn ($pr) => $pr->where('pr_number', 'like', "%{$keyword}%"))
                        ->orWhereHasMorph('conversable', [\App\Models\PurchaseOrder::class], fn ($po) => $po->where('po_number', 'like', "%{$keyword}%"));
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
                'messages.sender',
                'latestMessage.sender',
            ])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->load(['messages.sender', 'messages.attachments']);

        return response()->json([
            'conversation' => $this->formatConversation($conversation),
            'context' => ConversationPresenter::context($conversation, auth()->user()),
            'quick_actions' => ConversationPresenter::quickActions($conversation, auth()->user()),
            'templates' => ConversationPresenter::templates($conversation, auth()->user()),
            'messages' => $conversation->messages->map(fn (Message $message) => $this->formatMessage($message)),
        ]);
    }

    /**
     * Send a new message to the conversation.
     */
    public function store(Request $request, $id)
    {
        $validated = $request->validate([
            'body' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx|max:10240',
        ]);

        $body = trim((string) ($validated['body'] ?? ''));
        $hasAttachments = $request->hasFile('attachments');

        if ($body === '' && ! $hasAttachments) {
            throw ValidationException::withMessages([
                'body' => 'Enter a message or attach at least one file.',
            ]);
        }

        $conversation = Conversation::findOrFail($id);
        $this->authorize('message', $conversation);

        $message = DB::transaction(function () use ($conversation, $body, $request) {
            $message = $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'body' => $body,
            ]);

            $this->storeAttachments($message, $request);
            $conversation->markWaitingForPartner(auth()->user());

            return $message->load(['sender', 'attachments']);
        });

        // Send a notification to the other party.
        $partner = $conversation->getPartner(auth()->id());
        
        // Keep the notification behavior simple and always notify the partner.
        if ($partner) {
            $senderName = auth()->user()->name;
            $preview = $message->body !== ''
                ? Str::limit($message->body, 50)
                : 'Sent an attachment in the chat.';
            
            // Determine the correct route for the notification URL based on partner's role
            $routePrefix = $partner->role === 'purchasing' ? 'purchasing' : 'supplier';
            $url = route("{$routePrefix}.conversations.show", $conversation->id);

            $partner->notify(new SystemNotification(
                'New message from ' . $senderName,
                $preview,
                $url,
                'bi-chat-dots',
                ['category' => NotificationCategory::CHAT]
            ));
        }

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

    public function quickAction(Request $request, $id): JsonResponse
    {
        $conversation = Conversation::with(['supplierUser.supplier', 'purchasingUser'])->findOrFail($id);
        $this->authorize('message', $conversation);

        if (auth()->user()->role !== 'purchasing') {
            abort(403, 'Only Purchasing can run negotiation actions.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:request_price_revision,request_validity_extension,request_delivery_confirmation,accept_quotation,reject_quotation',
            'note' => 'nullable|string|max:1000',
        ]);

        $quotation = ConversationPresenter::relatedQuotation($conversation);

        if (! $quotation) {
            throw ValidationException::withMessages([
                'action' => 'No related quotation was found for this conversation.',
            ]);
        }

        $note = trim((string) ($validated['note'] ?? ''));

        if (in_array($validated['action'], ['request_price_revision', 'reject_quotation'], true) && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Notes are required for this action.',
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $quotation, $validated, $note) {
            return match ($validated['action']) {
                'request_price_revision' => $this->requestPriceRevision($conversation, $quotation, $note),
                'request_validity_extension' => $this->sendActionMessage(
                    $conversation,
                    $quotation,
                    'Please extend the quotation validity for PR '
                        . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id) . '.',
                    $note
                ),
                'request_delivery_confirmation' => $this->sendActionMessage(
                    $conversation,
                    $quotation,
                    'Please confirm the latest estimated delivery for PR '
                        . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id) . '.',
                    $note
                ),
                'accept_quotation' => $this->acceptQuotation($conversation, $quotation),
                'reject_quotation' => $this->rejectQuotation($conversation, $quotation, $note),
            };
        });

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

        $afterId = $request->query('after', 0);

        $messages = $conversation->messages()
            ->with('sender')
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
            'context_type' => $conversation->conversable_type === \App\Models\PurchaseRequisition::class ? 'PR' : 'PO',
            'partner_name' => $this->displayName($partner),
            'partner_role' => $partner?->role,
            'latest_preview' => $latestMessage ? Str::limit($latestMessage->body, 70) : 'No messages yet',
            'latest_time' => $latestMessage?->created_at?->diffForHumans(),
            'latest_at' => $latestMessage?->created_at?->toIso8601String(),
            'unread_count' => $conversation->unreadCountFor(auth()->id()),
            'status' => $conversation->status ?? Conversation::STATUS_OPEN,
            'status_label' => $conversation->statusLabelFor(auth()->user()),
            'status_badge_class' => $conversation->statusBadgeClassFor(auth()->user()),
            'sla' => ConversationPresenter::slaMeta($conversation, auth()->user()),
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
            'is_read' => $message->sender_id === auth()->id() && $message->read_at !== null,
            'read_at' => $message->read_at?->toIso8601String(),
            'read_at_display' => $message->read_at?->format('H:i'),
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->file_name,
                'type' => $attachment->file_type,
                'url' => route('attachments.show', $attachment->id),
            ])->values(),
        ];
    }

    private function storeAttachments(Message $message, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('attachments/' . now()->format('Y/m'), 'private');

            $message->attachments()->create([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    private function requestPriceRevision(Conversation $conversation, Quotation $quotation, string $note): Message
    {
        if ($quotation->purchaseRequisition->status === 'completed') {
            throw ValidationException::withMessages([
                'action' => 'The PR is completed. A quotation revision cannot be requested.',
            ]);
        }

        if (! $quotation->canRequestRevision()) {
            throw ValidationException::withMessages([
                'action' => 'A revision can only be requested for submitted quotations that have not been used to create a PO.',
            ]);
        }

        $quotation->update([
            'status' => Quotation::STATUS_REVISION_REQUESTED,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $note,
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => 'Please revise the quotation price for PR '
                . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id)
                . ".\n\nRevision notes: " . $note,
        ]);

        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING_SUPPLIER,
            'resolved_at' => null,
        ])->save();

        $this->notifySupplier($quotation, 'Quotation Revision Requested', 'Purchasing requested a quotation revision for PR :pr_number.');

        return $message;
    }

    private function acceptQuotation(Conversation $conversation, Quotation $quotation): Message
    {
        if (! $quotation->canApproveBy(auth()->user())) {
            throw ValidationException::withMessages([
                'action' => 'This quotation cannot be accepted.',
            ]);
        }

        if ($quotation->isExpired()) {
            throw ValidationException::withMessages([
                'action' => 'This quotation has expired. Ask the supplier to submit a revision before accepting it.',
            ]);
        }

        $quotation->update([
            'status' => Quotation::STATUS_ACCEPTED,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => 'Quotation for PR '
                . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id)
                . ' has been accepted by Purchasing.',
        ]);

        $conversation->markResolved();
        $this->notifySupplier($quotation, 'Quotation Accepted', 'Quotation for PR :pr_number has been accepted by Purchasing.');

        return $message;
    }

    private function rejectQuotation(Conversation $conversation, Quotation $quotation, string $note): Message
    {
        if (! $quotation->canApproveBy(auth()->user())) {
            throw ValidationException::withMessages([
                'action' => 'This quotation cannot be rejected.',
            ]);
        }

        $quotation->update([
            'status' => Quotation::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $note,
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => 'Quotation for PR '
                . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id)
                . " was rejected by Purchasing.\n\nNotes: " . $note,
        ]);

        $conversation->markResolved();
        $this->notifySupplier($quotation, 'Quotation Rejected', 'Quotation for PR :pr_number was rejected by Purchasing.');

        return $message;
    }

    private function sendActionMessage(Conversation $conversation, Quotation $quotation, string $body, string $note = ''): Message
    {
        if ($note !== '') {
            $body .= "\n\nNotes: " . $note;
        }

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $body,
        ]);

        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING_SUPPLIER,
            'resolved_at' => null,
        ])->save();

        $this->notifySupplier($quotation, 'New Negotiation Message', 'Purchasing sent a negotiation message for PR :pr_number.');

        return $message;
    }

    private function notifySupplier(Quotation $quotation, string $title, string $message): void
    {
        $quotation->loadMissing(['supplier', 'purchaseRequisition']);

        $quotation->supplier?->notify(new SystemNotification(
            $title,
            $message,
            route('supplier.quotations.show', $quotation->id),
            'bi-chat-dots text-primary',
            [
                'category' => NotificationCategory::CHAT,
                'quotation_id' => $quotation->id,
                'pr_id' => $quotation->pr_id,
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
            ],
            [
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
            ]
        ));
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
