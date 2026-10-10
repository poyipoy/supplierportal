<?php

namespace App\Services\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierAuditReviewService
{
    public const RESULT_EXTENSIONS = ['pdf', 'xlsx', 'jpg', 'jpeg', 'png'];

    /** finfo kadang mendeteksi xlsx sebagai application/zip; ekstensi tetap harus xlsx. */
    public const RESULT_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    public const RESULT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly SupplierAuditNotifier $notifier) {}

    /** D14: tetapkan, ubah, atau hapus deadline audit aktif. */
    public function changeDeadline(User $actor, SupplierAudit $audit, ?string $dueDate, ?string $reason = null): SupplierAudit
    {
        [$locked, $history] = DB::transaction(function () use ($actor, $audit, $dueDate, $reason) {
            $locked = $this->lock($audit);
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['due_date' => __('supplier_audit.errors.invalid_status')]);
            }

            return [$locked, $this->applyDeadline($locked, $actor, $dueDate, $reason)];
        });

        if ($history !== null) {
            $this->notifier->deadlineChanged($locked, $history);
        }

        return $locked;
    }

    public function requestRevision(User $actor, SupplierAudit $audit, string $note, bool $changeDueDate = false, ?string $dueDate = null): SupplierAudit
    {
        [$locked, $history] = DB::transaction(function () use ($actor, $audit, $note, $changeDueDate, $dueDate) {
            $locked = $this->lock($audit);
            if ($locked->status !== SupplierAudit::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['note' => __('supplier_audit.errors.invalid_status')]);
            }

            if ($changeDueDate) {
                $this->applyDeadline($locked, $actor, $dueDate, null);
            }

            $locked->update([
                'status' => SupplierAudit::STATUS_REVISION_REQUESTED,
                'revision_note' => $note,
                'revision_requested_at' => now(),
            ]);

            $history = $locked->statusHistories()->create([
                'from_status' => SupplierAudit::STATUS_SUBMITTED,
                'to_status' => SupplierAudit::STATUS_REVISION_REQUESTED,
                'event' => SupplierAuditStatusHistory::EVENT_REVISION_REQUESTED,
                'actor_id' => $actor->id,
                'notes' => $note,
            ]);

            return [$locked, $history];
        });

        $this->notifier->revisionRequested($locked, $history);

        return $locked;
    }

    public function cancel(User $actor, SupplierAudit $audit, string $reason): SupplierAudit
    {
        $locked = DB::transaction(function () use ($actor, $audit, $reason) {
            $locked = $this->lock($audit);
            if (! in_array($locked->status, SupplierAudit::CANCELLABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['reason' => __('supplier_audit.errors.invalid_status')]);
            }

            $from = $locked->status;
            $locked->update([
                'status' => SupplierAudit::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            $locked->statusHistories()->create([
                'from_status' => $from,
                'to_status' => SupplierAudit::STATUS_CANCELLED,
                'event' => SupplierAuditStatusHistory::EVENT_CANCELLED,
                'actor_id' => $actor->id,
                'notes' => $reason,
            ]);

            return $locked;
        });

        $this->notifier->cancelled($locked);

        return $locked;
    }

    /** D9: upload pertama menerbitkan hasil; upload berikutnya mengganti file dengan alasan wajib. */
    public function publishResult(User $actor, SupplierAudit $audit, UploadedFile $file, ?string $reason = null): SupplierAudit
    {
        $this->assertResultFile($file);

        $path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path
        $stored = app(FileInspectionService::class)->storeUpload($file, 'audit', $path, 'result_file');

        try {
            [$locked, $attachment] = DB::transaction(function () use ($actor, $audit, $stored, $reason) {
                $locked = $this->lock($audit);
                $replacing = $locked->status === SupplierAudit::STATUS_RESULT_PUBLISHED;

                if (! $replacing && $locked->status !== SupplierAudit::STATUS_SUBMITTED) {
                    throw ValidationException::withMessages(['result_file' => __('supplier_audit.errors.invalid_status')]);
                }
                if ($replacing && trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['reason' => __('supplier_audit.errors.replace_reason_required')]);
                }

                $attachment = $locked->resultAttachments()->create([
                    'file_path' => $stored['file_path'],
                    'file_name' => $stored['file_name'],
                    'file_type' => $stored['file_type'],
                    'file_inspection_id' => $stored['file_inspection_id'],
                    'uploaded_by' => $actor->id,
                ]);

                if (! $replacing) {
                    $locked->update([
                        'status' => SupplierAudit::STATUS_RESULT_PUBLISHED,
                        'result_published_at' => now(),
                    ]);
                }

                $locked->statusHistories()->create([
                    'from_status' => $replacing ? SupplierAudit::STATUS_RESULT_PUBLISHED : SupplierAudit::STATUS_SUBMITTED,
                    'to_status' => SupplierAudit::STATUS_RESULT_PUBLISHED,
                    'event' => $replacing ? SupplierAuditStatusHistory::EVENT_RESULT_REPLACED : SupplierAuditStatusHistory::EVENT_RESULT_PUBLISHED,
                    'actor_id' => $actor->id,
                    'notes' => $replacing ? trim((string) $reason) : null,
                ]);

                return [$locked, $attachment];
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        $this->notifier->resultPublished($locked, $attachment);

        return $locked;
    }

    private function lock(SupplierAudit $audit): SupplierAudit
    {
        return SupplierAudit::whereKey($audit->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Harus dipanggil di dalam transaksi dengan audit terkunci. Mengembalikan null bila tidak berubah. */
    private function applyDeadline(SupplierAudit $locked, User $actor, ?string $dueDate, ?string $reason): ?SupplierAuditStatusHistory
    {
        $dueDate = $dueDate !== null && $dueDate !== '' ? $dueDate : null;
        $old = $locked->due_date?->toDateString();
        if ($old === $dueDate) {
            return null;
        }

        $locked->update(['due_date' => $dueDate]);

        $notes = ($old ?? '—').' → '.($dueDate ?? '—');
        if (trim((string) $reason) !== '') {
            $notes .= ' · '.trim((string) $reason);
        }

        return $locked->statusHistories()->create([
            'from_status' => $locked->status,
            'to_status' => $locked->status,
            'event' => SupplierAuditStatusHistory::EVENT_DEADLINE_CHANGED,
            'actor_id' => $actor->id,
            'notes' => $notes,
        ]);
    }

    private function assertResultFile(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (! $file->isValid()
            || $file->getSize() > self::RESULT_MAX_BYTES
            || ! in_array($extension, self::RESULT_EXTENSIONS, true)
            || ! in_array($mime, self::RESULT_MIME_TYPES[$extension], true)) {
            throw ValidationException::withMessages(['result_file' => __('supplier_audit.errors.result_file')]);
        }
    }
}
