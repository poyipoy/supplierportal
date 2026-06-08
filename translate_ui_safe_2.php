<?php

$viewsDir = __DIR__ . '/resources/views';
$appDir = __DIR__ . '/app';

$replacements = [
    "'Simpan Revisi Sementara'" => "'Save Revision'",
    "'Simpan Draft'" => "'Save Draft'",
    "'Simpan Draft  '" => "'Save Draft  '",
    "@json('Batal')" => "@json('Cancel')",
    "'Batal'" => "'Cancel'",
    '"Batal"' => '"Cancel"',
    "'Ya, Simpan!'" => "'Yes, Save!'",
    "@json('Ya, Simpan!')" => "@json('Yes, Save!')",
    "'Ya, hapus!'" => "'Yes, delete!'",
    "@json('Ya, hapus!')" => "@json('Yes, delete!')",
    "Hapus Pilihan" => "Delete Selection",
    "Simpan Pilihan" => "Save Selection",
    "Simpan Periode" => "Save Period",
    "Tambah Material" => "Add Material",
    "Tambah Foto Bukti NG" => "Add NG Evidence Photo",
    "Tambah Periode" => "Add Period",
    "Tambah Periode Baru" => "Add New Period",
    "Catatan Tambahan" => "Additional Notes",
    "Catatan / Keterangan Tambahan" => "Additional Notes / Remarks",
    "Informasi Tambahan" => "Additional Information",
    "'Yakin ingin menghapus?'" => "'Are you sure you want to delete?'",
    "@json('Yakin ingin menghapus?')" => "@json('Are you sure you want to delete?')",
    "'Hapus baris ini?'" => "'Delete this row?'",
    "'Minimal 1 material wajib ditambahkan.'" => "'At least 1 material must be added.'",
    "'Berhasil!'" => "'Success!'",
    "@json('Berhasil!')" => "@json('Success!')",
    "'Gagal menandai notifikasi.'" => "'Failed to mark notification.'",
    "'Gagal memperbarui status dokumen.'" => "'Failed to update document status.'",
    "'Belum Ada'" => "'Not Available'",
    "@json('Belum Ada')" => "@json('Not Available')",
    "'Sudah Diterbitkan'" => "'Issued'",
    "@json('Sudah Diterbitkan')" => "@json('Issued')",
    "'Diverifikasi'" => "'Verified'",
    "@json('Diverifikasi')" => "@json('Verified')",
    "'Diterima'" => "'Accepted'",
    "@json('Diterima')" => "@json('Accepted')",
    "Penawaran supplier lain pada PR yang sama akan otomatis <strong>ditolak</strong>." => "Quotations from other suppliers on the same PR will automatically be <strong>rejected</strong>.",
    "Kurs baru disimpan sebagai histori baru, bukan menimpa kurs lama." => "New exchange rate is saved as new history, not overwriting the old one.",
    "QUOTATIONS SUBMITTED" => "QUOTATIONS SUBMITTED",
    "PO RECEIVED" => "PO RECEIVED",
    "PENAWARAN TERKIRIM" => "SUBMITTED QUOTATIONS",
    "PO DITERIMA" => "RECEIVED PO",
];

function processDirectory($dir, $replacements) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php'])) {
            $content = file_get_contents($file->getPathname());
            $newContent = strtr($content, $replacements);
            if ($content !== $newContent) {
                file_put_contents($file->getPathname(), $newContent);
                $count++;
            }
        }
    }
    return $count;
}

echo "Starting SAFE translation bulk script phase 2...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

$appChanged = processDirectory($appDir, $replacements);
echo "App (Controllers/Exports/Models) changed: $appChanged files.\n";

echo "Done.\n";
