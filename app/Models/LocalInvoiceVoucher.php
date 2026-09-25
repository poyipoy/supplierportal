<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoiceVoucher extends Model
{
    use HasHashids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_FINAL = 'FINAL';

    public const METHOD_BANK = 'BANK';

    public const METHOD_KAS = 'KAS';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date', 'dpp_snapshot' => 'decimal:2', 'ppn_snapshot' => 'decimal:2',
            'pph_snapshot' => 'decimal:2', 'net_payable_snapshot' => 'decimal:2', 'amount' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function batch()
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function group()
    {
        return $this->belongsTo(PaymentGroup::class, 'payment_group_id');
    }

    public function item()
    {
        return $this->belongsTo(PaymentItem::class, 'payment_item_id');
    }

    public function payment()
    {
        return $this->hasOne(LocalInvoicePayment::class);
    }
}
