<?php

$viewsDir = __DIR__ . '/resources/views';
$appDir = __DIR__ . '/app';

$replacements = [
    // Phrases
    "'received' => 'Diterima'" => "'received' => 'Received'",
    "Semua material sesuai spesifikasi." => "All materials meet specifications.",
    "Terdapat material yang tidak sesuai spesifikasi. Harap unggah foto bukti." => "There are materials that do not meet specifications. Please upload evidence photos.",
    "Simpan Perubahan" => "Save Changes",
    "Quotation supplier lain pada PR yang sama akan otomatis <strong>ditolak</strong>." => "Other suppliers' quotations on the same PR will be automatically <strong>rejected</strong>.",
    "PERMINTAAN AKTIF" => "ACTIVE REQUIREMENTS",
    "Update kurs terbaru:" => "Latest exchange rate update:",
    "Exchange Rate baru disimpan sebagai histori baru, bukan menimpa kurs lama." => "New Exchange Rate is saved as new history, not overwriting the old one.",
    "Exchange Rate terbaru dipakai untuk input baru. Histori penawaran dan PO tetap memakai kurs snapshot masing-masing." => "Latest Exchange Rate is used for new inputs. Quotation and PO history keep using their respective snapshot rates.",
    "'Belum Ada'" => "'Not Available'",
    "'Sudah Diterbitkan'" => "'Issued'",
    "'Diverifikasi'" => "'Verified'",
    "Belum Ada" => "Not Available",
    "Sudah Diterbitkan" => "Issued",
    "Diverifikasi" => "Verified",
    "Gagal memperbarui status dokumen." => "Failed to update document status.",
    "Form Pengajuan Klaim" => "Claim Submission Form",
    "Beri waktu wajar untuk supplier merespons klaim ini." => "Give reasonable time for the supplier to respond to this claim.",
    "Kirim Klaim ke Supplier" => "Send Claim to Supplier",
    "Tgl Inspeksi" => "Inspection Date",
    "Aksi Klaim" => "Claim Action",
    "Supplier telah memberikan tanggapan. Apakah solusi bisa diterima?" => "Supplier has provided a response. Is the solution acceptable?",
    "Klaim ini telah dinyatakan selesai dan terselesaikan." => "This claim has been declared completed and resolved.",
    "Daftar PO di bawah ini telah diinspeksi oleh QC dan berstatus NG (Not Good). Silakan ajukan klaim kepada supplier terkait." => "The PO list below has been inspected by QC and has an NG (Not Good) status. Please submit a claim to the relevant supplier.",
    "Pembanding memakai harga IDR/kg setelah konversi kurs. Status kompetitif aman jika selisih maksimal" => "Comparison uses IDR/kg price after exchange rate conversion. Competitive status is safe if maximum difference is",
    "Gagal memuat material" => "Failed to load material",
    "Gagal memuat daftar material. Coba pilih supplier kembali." => "Failed to load material list. Try selecting a supplier again.",
    "Total perubahan (awal → terbaru)" => "Total change (initial → latest)",
    "Total perubahan (awal â†’ terbaru)" => "Total change (initial → latest)",
    "Inspeksi Quality Control" => "Quality Control Inspection",
    "Hasil Inspeksi" => "Inspection Result",
    "KESIMPULAN: Ditemukan" => "CONCLUSION: Found",
    "item TIDAK SESUAI SPESIFIKASI (NG). Material memerlukan tindak lanjut klaim." => "items DO NOT MEET SPECIFICATION (NG). Material requires claim follow-up.",
    "Diterima Oleh" => "Received By",
    "Gagal memuat daftar chat." => "Failed to load chat list.",
    "Gagal memuat daftar chat. Coba buka kembali beberapa saat lagi." => "Failed to load chat list. Please try again later.",
    "Terkirim, belum dibaca" => "Sent, unread",
    "Gagal membuka chat." => "Failed to open chat.",
    "Gagal memuat detail chat. Coba buka kembali beberapa saat lagi." => "Failed to load chat details. Please try again later.",
    "PO sedang menunggu inspeksi QC." => "POs are waiting for QC inspection.",
    "PO sedang menunggu" => "POs are waiting",
    "sedang menunggu inspeksi QC." => "are waiting for QC inspection.",
    "sedang menunggu" => "are waiting",
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

echo "Starting phase 4 bulk script...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

$appChanged = processDirectory($appDir, $replacements);
echo "App changed: $appChanged files.\n";

echo "Done.\n";
