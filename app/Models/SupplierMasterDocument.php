<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierMasterDocument extends Model
{
    use HasHashids;
    public const TYPE_NIB = 'NIB';
    public const TYPE_NPWP = 'NPWP';
    public const TYPE_SPPKP = 'SPPKP';
    public const TYPE_SURAT_PERNYATAAN_REKENING = 'SURAT_PERNYATAAN_REKENING';
    public const TYPE_OTHER = 'OTHER';

    protected $fillable = [
        'supplier_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    // ─── Relationships ───

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
