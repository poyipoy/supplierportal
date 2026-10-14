# Perbaikan informasi error nomor faktur pajak

Tanggal: 13 Oktober 2026.

## Penyebab dan perbaikan

`StoreLocalInvoiceRequest::prepareForValidation()` sebelumnya selalu mengganti nomor invoice/faktur pajak yang diketik dengan hasil parsing nama lampiran. Rule unique kemudian memeriksa nomor dari filename, sehingga field berisi nomor baru dapat menerima pesan duplikat untuk nomor lama dari lampiran. Pesan lama pada UI juga belum dibersihkan ketika nomor diedit, dan badge `From Filename` tetap tampil meskipun nilainya sudah berubah.

Sekarang autofill server hanya mengisi field yang kosong. Nilai yang diisi user tetap dinormalisasi dan diperiksa, tanpa ditimpa filename. Perbandingan field dengan nomor dari nama berkas tetap wajib; jika berbeda, pesan menyebut nomor yang diisi dan nomor yang dibaca dari nama lampiran, dengan tindakan koreksi. Pesan unique menyebut nomor normalized yang benar-benar diperiksa dan tetap dibatasi pada invoice supplier yang sama; revisi tetap mengecualikan invoice sendiri. Nilai `_original_*` ditetapkan server dari field aktual, bukan dipercaya dari request client.

Badge asal filename mengikuti kecocokan nilai saat ini. Ketik/paste nomor atau penggantian lampiran memperbarui badge serta membersihkan pesan field yang sudah kedaluwarsa. Error kolom lain dan file yang dipilih dipertahankan. Nomor invoice/faktur pajak tidak boleh berbeda dari identitas lampiran; perbaikan ini tidak menghapus aturan tersebut.

## Dieksekusi

- Safety database: 2 passed, 4 assertions.
- Baseline reproduksi: 16 kasus gagal karena menerima pesan duplikat yang tidak sesuai; mencakup 16/17 digit, EN/ID, submit/revisi, serta request dengan/tanpa nilai `_original_*` dari client.
- InvoiceFilenameAutoFillTest, LocalInvoiceSubmissionV2Test, LocalInvoiceTaxConsistencyTest: 60 passed, 369 assertions. Selain pesan mismatch, kasus yang sama berhasil setelah filename diperbaiki dan duplikat nyata tetap ditolak dengan nomor yang tepat.
- LocalInvoiceTest: 32 passed, 235 assertions, termasuk reuse/replacement dokumen revisi.
- JavaScript feedback filename + async submit: 10 passed; ketik/paste, badge asal nomor, pembersihan pesan lama, serta file tetap tersedia setelah 413/422.
- TranslationParityTest, TranslationContentTest, UserFacingCopyInventoryTest: 48 passed, 181443 assertions, final setelah feature tests selesai.
- PHP syntax, scoped Pint, Blade cache, build, dan diff check lulus.
- Reconcile read-only: 0 issues.

## Diinspeksi / belum diverifikasi

Jalur FormRequest, parser filename, service submit/resubmit, pemilihan error inline, dan aturan unique diinspeksi. Tidak ada perubahan data aplikasi, package, route, migrasi, atau pemeriksaan isi PDF baru. Produksi dan pengujian browser manual belum diverifikasi langsung; HTTP/JavaScript lokal menjadi bukti perbaikan.

## File production untuk revisi ini

```text
app/Http/Requests/LocalInvoice/StoreLocalInvoiceRequest.php
resources/views/local-invoices/form.blade.php
lang/en/local_invoice.php
lang/id/local_invoice.php
```

Upload ke release aplikasi yang sama dan refresh halaman agar JavaScript inline baru termuat. Perubahan ini tidak menambah aset entry baru; build telah diperiksa. Commit/push/merge belum dilakukan karena pilihan cakupan perubahan pada working tree masih belum dijawab.
