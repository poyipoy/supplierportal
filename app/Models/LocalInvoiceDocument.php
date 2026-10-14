<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoiceDocument extends Model
{
    use HasHashids;

    /**
     * Batas berkas per tipe dokumen dalam satu revisi invoice. Invoice asli dan
     * faktur pajak dibatasi satu berkas karena nomor dokumennya diturunkan dari
     * nama berkas; tipe lain masih boleh berlembar-lembar.
     */
    public const MAX_FILES_PER_TYPE = [
        'invoice' => 1,
        'tax_invoice' => 1,
        'delivery_note' => 5,
        'surat_jalan' => 5,
        'supporting' => 5,
    ];

    public const DEFAULT_MAX_FILES = 5;

    protected $guarded = ['id'];

    public static function maxFilesFor(string $type): int
    {
        return self::MAX_FILES_PER_TYPE[$type] ?? self::DEFAULT_MAX_FILES;
    }

    /**
     * Tipe berkas tunggal: unggahan baru menggantikan berkas tersimpan, bukan
     * ditambahkan di sampingnya.
     */
    public static function replacesOnUpload(string $type): bool
    {
        return self::maxFilesFor($type) === 1;
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function revision()
    {
        return $this->belongsTo(LocalInvoiceRevision::class, 'local_invoice_revision_id');
    }

    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->file_size) {
            return '';
        }
        if ($this->file_size >= 1048576) {
            return number_format($this->file_size / 1048576, 1).' MB';
        }

        return number_format($this->file_size / 1024, 1).' KB';
    }
}
