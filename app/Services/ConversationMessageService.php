<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\Procurement\QuotationStateEngine;
use App\Support\ConversationPresenter;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationMessageService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly FileInspectionService $fileInspection,
        private readonly QuotationStateEngine $quotationStateEngine,
    ) {}

    public function sendMessage(Conversation $conversation, Request $request, string $body): Message
    {
        $written = [];
        try {
            $message = DB::transaction(function () use ($conversation, $body, $request, &$written) {
                $message = $conversation->messages()->create([
                    'sender_id' => auth()->id(),
                    'body' => $body,
                ]);

                $this->storeAttachments($message, $request, $written);
                $conversation->markWaitingForPartner(auth()->user());

                return $message->load(['sender', 'attachments']);
            });
        } catch (\Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }

        $partner = $conversation->getPartner(auth()->id());

        if ($partner) {
            $senderName = auth()->user()->name;
            $preview = $message->body !== ''
                ? Str::limit($message->body, 50)
                : '';

            $routePrefix = $partner->role === 'purchasing' ? 'purchasing' : 'supplier';
            $url = route("{$routePrefix}.conversations.show", $conversation);

            $this->notifications->send(
                $partner,
                'conversation.message_created',
                "conversation.message_created:{$message->id}",
                'notifications.chat.message_title',
                $message->body !== '' ? 'notifications.chat.message_body' : 'notifications.chat.attachment_body',
                $url,
                'message-circle-more',
                [
                    'category' => NotificationCategory::CHAT,
                    'conversation_id' => $conversation->id,
                ],
                ['sender' => $senderName, 'preview' => $preview],
            );
        }

        return $message;
    }

    /**
     * @param  array{action: string, note: string}  $validated
     */
    public function executeQuickAction(Conversation $conversation, array $validated): Message
    {
        $quotation = ConversationPresenter::relatedQuotation($conversation);

        if (! $quotation) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.no_quotation'),
            ]);
        }

        $note = $validated['note'];

        if (in_array($validated['action'], ['request_price_revision', 'reject_quotation'], true) && $note === '') {
            throw ValidationException::withMessages([
                'note' => __('notifications.conversation.note_required'),
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $quotation, $validated, $note) {
            $pr = PurchaseRequisition::whereKey($quotation->pr_id)->lockForUpdate()->firstOrFail();
            $quotation = Quotation::whereKey($quotation->id)->where('pr_id', $pr->id)
                ->lockForUpdate()->firstOrFail();
            $quotation->setRelation('purchaseRequisition', $pr);

            if (in_array($validated['action'], ['request_price_revision', 'accept_quotation', 'reject_quotation'], true)
                && $quotation->isReferencedByAwardOrPurchaseOrder()) {
                throw ValidationException::withMessages([
                    'action' => __('notifications.conversation.quotation_in_use'),
                ]);
            }

            return match ($validated['action']) {
                'request_price_revision' => $this->requestPriceRevision($conversation, $quotation, $note),
                'request_validity_extension' => $this->sendActionMessage(
                    $conversation,
                    $quotation,
                    __('notifications.conversation.extend_validity', ['pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id]),
                    $note
                ),
                'request_delivery_confirmation' => $this->sendActionMessage(
                    $conversation,
                    $quotation,
                    __('notifications.conversation.confirm_delivery', ['pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id]),
                    $note
                ),
                'accept_quotation' => $this->acceptQuotation($conversation, $quotation),
                'reject_quotation' => $this->rejectQuotation($conversation, $quotation, $note),
            };
        });

        $notification = match ($validated['action']) {
            'request_price_revision' => ['quotation.revision_requested', 'notifications.negotiation.revision.title', 'notifications.negotiation.revision.message', NotificationCategory::QUOTATION],
            'accept_quotation' => ['quotation.accepted', 'notifications.negotiation.accepted.title', 'notifications.negotiation.accepted.message', NotificationCategory::QUOTATION],
            'reject_quotation' => ['quotation.rejected', 'notifications.negotiation.rejected.title', 'notifications.negotiation.rejected.message', NotificationCategory::QUOTATION],
            default => ['quotation.negotiation_message', 'notifications.negotiation.message.title', 'notifications.negotiation.message.message', NotificationCategory::CHAT],
        };

        $this->notifySupplier(
            $conversation,
            $quotation,
            $notification[0],
            $notification[1],
            $notification[2],
            $notification[3],
            "{$notification[0]}:{$message->id}",
        );

        return $message;
    }

    /**
     * @param  array<int, string>  $written
     */
    private function storeAttachments(Message $message, Request $request, array &$written): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path
            $stored = $this->fileInspection->storeUpload($file, 'chat', $path, 'attachments');
            $written[] = $stored['file_path'];

            $message->attachments()->create([
                'file_path' => $stored['file_path'],
                'file_name' => $stored['file_name'],
                'file_type' => $stored['file_type'],
                'file_inspection_id' => $stored['file_inspection_id'],
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    private function requestPriceRevision(Conversation $conversation, Quotation $quotation, string $note): Message
    {
        if ($quotation->purchaseRequisition->status === 'completed') {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.completed_pr'),
            ]);
        }

        if (! $quotation->canRequestRevision()) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.revision_ineligible'),
            ]);
        }

        $this->quotationStateEngine->transition(
            model: $quotation,
            target: QuotationStatus::REVISION_REQUESTED,
            actor: auth()->user(),
            metadata: [
                'attributes' => [
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewer_notes' => $note,
                ],
            ]
        );

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => __('notifications.conversation.revise_price', [
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id,
                'note' => $note,
            ]),
        ]);

        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING_SUPPLIER,
            'resolved_at' => null,
        ])->save();

        return $message;
    }

    private function acceptQuotation(Conversation $conversation, Quotation $quotation): Message
    {
        if (! $quotation->canApproveBy(auth()->user())) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.cannot_accept'),
            ]);
        }

        if (! $quotation->hasAvailableItems()) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.all_unavailable'),
            ]);
        }

        if ($quotation->isExpired()) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.expired'),
            ]);
        }

        $this->quotationStateEngine->transition(
            model: $quotation,
            target: QuotationStatus::ACCEPTED,
            actor: auth()->user(),
            metadata: [
                'attributes' => [
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                ],
            ]
        );

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => __('notifications.negotiation.accepted.message', ['pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id]),
        ]);

        $conversation->markResolved();

        return $message;
    }

    private function rejectQuotation(Conversation $conversation, Quotation $quotation, string $note): Message
    {
        if (! $quotation->canApproveBy(auth()->user())) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.cannot_reject'),
            ]);
        }

        if (! $quotation->hasAvailableItems()) {
            throw ValidationException::withMessages([
                'action' => __('notifications.conversation.no_available_items'),
            ]);
        }

        $this->quotationStateEngine->transition(
            model: $quotation,
            target: QuotationStatus::REJECTED,
            actor: auth()->user(),
            metadata: [
                'attributes' => [
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                    'reviewer_notes' => $note,
                ],
            ]
        );

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => __('notifications.conversation.rejected_with_note', [
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '#'.$quotation->pr_id,
                'note' => $note,
            ]),
        ]);

        $conversation->markResolved();

        return $message;
    }

    private function sendActionMessage(Conversation $conversation, Quotation $quotation, string $body, string $note = ''): Message
    {
        if ($note !== '') {
            $body = __('notifications.conversation.with_note', ['message' => $body, 'note' => $note]);
        }

        $message = $conversation->messages()->create([
            'sender_id' => auth()->id(),
            'body' => $body,
        ]);

        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING_SUPPLIER,
            'resolved_at' => null,
        ])->save();

        return $message;
    }

    private function notifySupplier(
        Conversation $conversation,
        Quotation $quotation,
        string $event,
        string $title,
        string $message,
        string $category,
        string $eventKey,
    ): void {
        $quotation->loadMissing(['supplier', 'purchaseRequisition']);

        $this->notifications->send(
            $quotation->supplier,
            $event,
            $eventKey,
            $title,
            $message,
            route('supplier.quotations.show', $quotation, absolute: false),
            'message-circle-more text-primary',
            [
                'category' => $category,
                'conversation_id' => $conversation->id,
                'quotation_id' => $quotation->id,
                'pr_id' => $quotation->pr_id,
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
            ],
            [
                'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
            ],
        );
    }
}
