<?php

namespace App\Models;

use App\Support\BusinessTime;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

class SupplierAudit extends Model
{
    use HasHashids;

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_REVISION_REQUESTED = 'REVISION_REQUESTED';

    public const STATUS_RESULT_PUBLISHED = 'RESULT_PUBLISHED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_REVISION_REQUESTED,
        self::STATUS_RESULT_PUBLISHED,
        self::STATUS_CANCELLED,
    ];

    /** Belum final: satu supplier hanya boleh punya satu audit dalam status ini (D7). */
    public const ACTIVE_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_REVISION_REQUESTED,
    ];

    /** Supplier masih harus mengisi; hanya status ini yang bisa "Terlambat" dan memblokir invoice (D8/D13). */
    public const SUPPLIER_EDITABLE_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_DRAFT,
        self::STATUS_REVISION_REQUESTED,
    ];

    public const EXPORTABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_RESULT_PUBLISHED,
    ];

    public const CANCELLABLE_STATUSES = self::ACTIVE_STATUSES;

    public const RESULT_UPLOADABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_RESULT_PUBLISHED,
    ];

    protected $fillable = [
        'supplier_id',
        'supplier_audit_template_id',
        'period_label',
        'due_date',
        'status',
        'assigned_by',
        'assigned_at',
        'submitted_at',
        'revision_note',
        'revision_requested_at',
        'result_published_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'assigned_at' => 'datetime',
            'submitted_at' => 'datetime',
            'revision_requested_at' => 'datetime',
            'result_published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SupplierAuditTemplate::class, 'supplier_audit_template_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SupplierAuditAnswer::class)->orderBy('sort_order_snapshot');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(SupplierAuditStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** File hasil penilaian, terbaru lebih dulu; baris lama adalah riwayat penggantian (D9). */
    public function resultAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    public function latestResult(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->latestOfMany('id');
    }

    // ─── Scopes ───

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('supplier_id', $user->id);
    }

    /** Terlambat = supplier belum submit dan deadline (tanggal kalender) sudah lewat di zona bisnis. */
    public function scopeLate(Builder $query): Builder
    {
        return $query->whereIn('status', self::SUPPLIER_EDITABLE_STATUSES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', BusinessTime::today()->toDateString());
    }

    /**
     * Tambah `completed_answers_count` & `total_answers_count` tanpa memuat 121 baris per audit.
     * Lengkap = Tidak, atau Ya yang sudah ber-Score (D3).
     */
    public function scopeWithAnswerProgress(Builder $query): Builder
    {
        return $query->withCount([
            'answers as completed_answers_count' => fn ($answers) => $answers->reorder()->where(fn ($row) => $row
                ->where('answer', SupplierAuditAnswer::ANSWER_NO)
                ->orWhere(fn ($yes) => $yes->where('answer', SupplierAuditAnswer::ANSWER_YES)->whereNotNull('score'))),
            'answers as total_answers_count' => fn ($answers) => $answers->reorder(),
        ]);
    }

    // ─── State helpers ───

    /**
     * Selisih hari kalender antara deadline dan hari ini di zona bisnis (negatif = lewat).
     */
    public function daysUntilDue(): ?int
    {
        if ($this->due_date === null) {
            return null;
        }

        return (int) BusinessTime::today()->diffInDays(BusinessTime::parseDate($this->due_date->toDateString()), false);
    }

    /** Label deadline relatif: "lewat 3 hari", "hari ini", "5 hari lagi". */
    public function dueRelativeLabel(): ?string
    {
        $days = $this->daysUntilDue();

        return match (true) {
            $days === null => null,
            $days < 0 => trans_choice('supplier_audit.deadline_relative.overdue', abs($days), ['count' => abs($days)]),
            $days === 0 => __('supplier_audit.deadline_relative.today'),
            default => trans_choice('supplier_audit.deadline_relative.remaining', $days, ['count' => $days]),
        };
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isEditableBySupplier(): bool
    {
        return in_array($this->status, self::SUPPLIER_EDITABLE_STATUSES, true);
    }

    public function isExportable(): bool
    {
        return in_array($this->status, self::EXPORTABLE_STATUSES, true);
    }

    public function isLate(): bool
    {
        return $this->due_date !== null
            && $this->isEditableBySupplier()
            && $this->due_date->toDateString() < BusinessTime::today()->toDateString();
    }

    public function supplierName(): string
    {
        $supplier = $this->supplier;

        return (string) ($supplier?->supplier?->company_name ?: $supplier?->name ?: '-');
    }

    /**
     * Jawaban dikelompokkan per bagian/sub-bagian (urut snapshot), dengan hitungan Ya/Tidak/kosong.
     *
     * @return Collection<int, array{code:string, title:string, parent_code:?string, parent_title:?string, step_code:string, step_title:string, sheet:int, answers:Collection, yes:int, no:int, empty:int}>
     */
    public function answerSections(): Collection
    {
        $answers = $this->relationLoaded('answers') ? $this->answers : $this->answers()->get();

        return $answers
            ->groupBy('section_code_snapshot', preserveKeys: false)
            ->map(function (Collection $rows) {
                /** @var SupplierAuditAnswer $first */
                $first = $rows->first();

                return [
                    'code' => $first->section_code_snapshot,
                    'title' => $first->section_title_snapshot,
                    'parent_code' => $first->parent_section_code_snapshot,
                    'parent_title' => $first->parent_section_title_snapshot,
                    'step_code' => $first->parent_section_code_snapshot ?? $first->section_code_snapshot,
                    'step_title' => $first->parent_section_title_snapshot ?? $first->section_title_snapshot,
                    'sheet' => (int) $first->sheet_number_snapshot,
                    'answers' => $rows->values(),
                    'yes' => $rows->where('answer', SupplierAuditAnswer::ANSWER_YES)->count(),
                    'no' => $rows->where('answer', SupplierAuditAnswer::ANSWER_NO)->count(),
                    'empty' => $rows->whereNull('answer')->count(),
                ];
            })
            ->values();
    }

    /**
     * Langkah wizard = bagian level 1; sub-bagian (2.1, 6.1, …) dirender di dalam langkah induknya.
     *
     * @return Collection<int, array{code:string, title:string, sections:Collection}>
     */
    public function answerSteps(): Collection
    {
        return $this->answerSections()
            ->groupBy('step_code', preserveKeys: false)
            ->map(fn (Collection $sections) => [
                'code' => $sections->first()['step_code'],
                'title' => $sections->first()['step_title'],
                'sections' => $sections->values(),
            ])
            ->values();
    }

    /** @return array{filled:int, total:int} */
    public function progress(): array
    {
        $answers = $this->relationLoaded('answers') ? $this->answers : $this->answers()->get();

        return [
            'filled' => $answers->filter(fn (SupplierAuditAnswer $answer) => $answer->isComplete())->count(),
            'total' => $answers->count(),
        ];
    }
}
