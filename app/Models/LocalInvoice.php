<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoice extends Model
{
    use HasHashids;

    public const STATUSES = ['WAITING_PHYSICAL_DOCUMENT', 'UNDER_REVIEW', 'NEED_REVISION', 'REJECTED', 'APPROVED', 'PAYMENT_SCHEDULED', 'COMPLETED'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'invoice_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'payment_term_days_snapshot' => 'integer', 'revision_number' => 'integer', 'submitted_at' => 'datetime', 'physical_verified_at' => 'datetime', 'review_started_at' => 'datetime', 'approved_at' => 'datetime', 'due_date' => 'date', 'payment_scheduled_at' => 'datetime', 'scheduled_payment_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function revisions()
    {
        return $this->hasMany(LocalInvoiceRevision::class);
    }

    public function documents()
    {
        return $this->hasMany(LocalInvoiceDocument::class);
    }

    public function receipt()
    {
        return $this->hasOne(LocalInvoiceReceipt::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(LocalInvoiceStatusHistory::class);
    }

    public function physicalVerifications()
    {
        return $this->hasMany(LocalInvoicePhysicalVerification::class);
    }

    public function paymentCategory(): string
    {
        if ($this->status === 'COMPLETED') {
            return 'Completed';
        }
        if ($this->due_date && $this->due_date->lt(today())) {
            return 'Overdue';
        }
        if ($this->due_date && $this->due_date->lt(today()->addDays(7))) {
            return 'Due < 7 Days';
        }

        return $this->status === 'PAYMENT_SCHEDULED' ? 'Scheduled' : 'Unscheduled';
    }

    public function remainingDays(): ?int
    {
        return $this->due_date ? (int) today()->diffInDays($this->due_date, false) : null;
    }
}
