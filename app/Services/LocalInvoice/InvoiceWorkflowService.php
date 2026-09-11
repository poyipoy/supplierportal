<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceWorkflowService
{
    private const ACTIONS = [
        'verifyPhysical' => ['WAITING_PHYSICAL_DOCUMENT', 'UNDER_REVIEW', 'physical_verified'],
        'startReview' => ['UNDER_REVIEW', 'UNDER_REVIEW', 'review_started'],
        'requestRevision' => ['UNDER_REVIEW', 'NEED_REVISION', 'revision_requested'],
        'reject' => ['UNDER_REVIEW', 'REJECTED', 'rejected'],
        'approve' => ['UNDER_REVIEW', 'APPROVED', 'approved'],
        'schedulePayment' => ['APPROVED', 'PAYMENT_SCHEDULED', 'payment_scheduled'],
        'completePayment' => ['PAYMENT_SCHEDULED', 'COMPLETED', 'completed'],
    ];

    public function __construct(private InvoiceNotificationService $notifications) {}

    public function act(User $actor, LocalInvoice $invoice, string $action, array $data = []): LocalInvoice
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);
        Gate::forUser($actor)->authorize($action, $invoice);

        return DB::transaction(function () use ($actor, $invoice, $action, $data) {
            $invoice = LocalInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize($action, $invoice);
            [$from, $to, $event] = self::ACTIONS[$action];
            if ($invoice->status !== $from || ($action === 'startReview' && $invoice->review_started_at)) {
                throw ValidationException::withMessages(['workflow' => 'This action is no longer available. Refresh the invoice.']);
            }
            Validator::make($data, [
                'notes' => [in_array($action, ['requestRevision', 'reject']) ? 'required' : 'nullable', 'string', 'max:5000'],
                'scheduled_payment_date' => [$action === 'schedulePayment' ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            ])->validate();
            if ($from === 'UNDER_REVIEW') {
                if (! $invoice->physical_verified_at || ! $invoice->physicalVerifications()->where('revision_number', $invoice->revision_number)->where('status', 'matched')->exists()
                    || $invoice->physicalVerifications()->where('revision_number', $invoice->revision_number)->where('status', 'invalidated')->exists()) {
                    throw ValidationException::withMessages(['workflow' => 'The current revision requires physical verification.']);
                }
            }
            if (in_array($action, ['verifyPhysical', 'approve'])) {
                $this->requireDocuments($invoice);
            }
            $changes = ['status' => $to];
            if ($action === 'verifyPhysical') {
                $changes['physical_verified_at'] = now();
                $invoice->physicalVerifications()->create(['revision_number' => $invoice->revision_number, 'status' => 'matched', 'verified_by' => $actor->id, 'verified_at' => now(), 'created_at' => now(), 'notes' => $data['notes'] ?? null]);
            }
            if ($action === 'startReview') {
                $changes['review_started_at'] = now();
            }
            if ($action === 'approve') {
                $approvedAt = now();
                $changes['approved_at'] = $approvedAt;
                $changes['due_date'] = $approvedAt->copy()->startOfDay()->addDays($invoice->payment_term_days_snapshot);
            }
            if ($action === 'schedulePayment') {
                $changes['payment_scheduled_at'] = now();
                $changes['scheduled_payment_date'] = $data['scheduled_payment_date'];
            }
            if ($action === 'completePayment') {
                $changes['completed_at'] = now();
            }
            $invoice->fill($changes)->save();
            $history = $invoice->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'event' => $event, 'actor_id' => $actor->id, 'notes' => $data['notes'] ?? null, 'created_at' => now()]);
            $this->notifications->send($invoice, $history);

            return $invoice;
        });
    }

    private function requireDocuments(LocalInvoice $invoice): void
    {
        $revision = $invoice->revisions()->where('revision_number', $invoice->revision_number)->firstOrFail();
        $documents = $revision->documents()->whereIn('document_type', ['invoice', 'tax_invoice'])->get();
        if ($documents->count() !== 2 || $documents->contains(fn ($document) => ! Storage::disk('private')->exists($document->file_path))) {
            throw ValidationException::withMessages(['documents' => 'Invoice and Faktur Pajak files must be available for this revision.']);
        }
    }
}
