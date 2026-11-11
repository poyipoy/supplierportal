<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVoucherDocument extends Model
{
    use HasHashids;

    protected $fillable = [
        'payment_voucher_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'file_inspection_id',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function fileInspection(): BelongsTo
    {
        return $this->belongsTo(FileInspection::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
