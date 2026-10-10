# Hasil Penyesuaian Cetak PO Impor — PNR261178

Implementasi lokal selesai. Template cetak PO Impor mengikuti formulir referensi A4, dengan Qty pcs dan harga tampilan per pcs, PPN `-`, English, alamat Green Land/Delta Mas, dan kotak tanda tangan kosong sesuai keputusan pengguna. Tidak ada perubahan route, nama download, hashid, skema database, workflow PO Lokal, atau perhitungan komersial authoritative.

## Implementasi

- `resources/views/pdf/po-pdf.blade.php`: formulir lima kolom, artwork header perusahaan dari PDF referensi, barcode dinamis, identitas supplier, nomor halaman, kotak total, catatan, alamat dan kotak tanda tangan.
- `app/Support/PurchaseOrderPdf.php`: payload presentasi, pemetaan `commercialQuotationItems()`, pengukuran teks memakai metrik font aktual, wrapping, dan pembagian halaman. Baris sangat panjang dapat berlanjut tanpa menduplikasi amount. Catatan/termin yang terlalu panjang mendapat halaman lanjutan; total dan tanda tangan tetap hanya sekali pada halaman terakhir.
- Revisi Qty pcs: `fulfillment_quantity` menjadi jumlah cetak; Unit Price = `resolved_amount / pcs` melalui BCMath, dibulatkan maksimum empat desimal tampilan, atau `-` bila pcs nol. Description memuat dimensi, HS Code, dan berat kg. Total tetap authoritative; harga/kg serta atribut model quotation tidak diubah.
- `PdfController`: eager-load profil supplier dan relasi yang dipakai, dengan ownership supplier dan download contract tetap. Profile yang tidak tersedia memakai nama akun dan placeholder `-`.
- Font Noto Sans disimpan lokal bersama OFL; `picqer/php-barcode-generator` dipin tepat `3.3.0`. Cache font di `storage/fonts/` dibuat sesuai kebutuhan dan diabaikan Git. Runtime tidak mengambil font/barcode melalui CDN.
- Key label/catatan tersedia di en/id, tetapi form ini membaca English secara eksplisit tanpa mengganti locale aplikasi. Kontrak pengecualian ini dicatat di AGENTS.md.
- Tes format PO disesuaikan dengan kontrak baru; ekspektasi QC tetap dipertahankan. Perubahan pengguna sebelumnya, termasuk OpenSpout pada Composer dan glossary lama pada `lang/id/documents.php`, dipertahankan.

## Bukti PDF dan visual

Sampel sintetis dibuat dari model di memori, tanpa menulis record ke database aplikasi:

Sampel revisi memakai 6 pcs dengan berat 60 kg, harga tersimpan 10,550.00/kg, amount 633,000.00, dan harga cetak 105,500.00/pcs per item. Basis pcs dan kg sengaja berbeda agar konversi tampilan dapat dibuktikan.

| Artefak | Skenario | Halaman |
|---|---|---:|
| `PO-PDF-SAMPLES/po-reference-layout.pdf` | PNR261178, IDR, satu item | 1 |
| `PO-PDF-SAMPLES/po-multiple-pages.pdf` | USD, 60 item, dua PR/termin | 6 |
| `PO-PDF-SAMPLES/po-long-content.pdf` | CNY, description dan catatan panjang | 5 |

Seluruh halaman sampel dirender dan diinspeksi. PNG tersedia per halaman, berikut `side-by-side.png`, `multi-contact-sheet.png`, dan `long-contact-sheet.png`. Nomor halaman lengkap; 60 material muncul masing-masing sekali; marker akhir description/catatan dan seluruh 150 pengulangan catatan tetap ada. Payment Term dan Ship to Address muncul pada halaman terakhir saja dalam ketiga sampel. Bukti mesin: `layout-measurements.json` dan `content-verification.json`.

| Bagian tetap yang diukur | Selisih maksimum terhadap referensi |
|---|---:|
| Border header tabel | 0,30 mm |
| Batas kolom tabel | 0,36 mm |
| Border horizontal Ship to Address | 0,42 mm |
| Border vertikal Ship to Address | 0,30 mm |
| Border horizontal tanda tangan | 0,72 mm |
| Border vertikal/kolom tanda tangan | 0,24 mm |

Angka tersebut mencakup border/kolom yang diukur, bukan klaim selisih setiap piksel. Metadata dimensi/HS Code/berat kg pada Description, PPN `-`, total tanpa pajak baru, dan kotak tanda tangan kosong memang disengaja. Pola Code 128 diperiksa terhadap simbol referensi secara independen, termasuk checksum dan perubahan nomor PO; belum ada pengujian scanner fisik.

## Verifikasi revisi Qty pcs

- **Lulus:** 37 tes, 36.274 assertions, serial pada `adasi_portal_test`: database safety, PDF, item-level PO generation, regional scope, presentation timezone, dan translation parity.
- **Lulus:** 9 tes PDF, termasuk jumlah pcs berbeda dari berat kg, harga per pcs, pembagian berulang dengan empat desimal, quantity nol, fallback legacy, award parsial, total authoritative, dan atribut quotation tidak berubah. Tes perubahan Qty terbukti gagal pada implementasi kg sebelumnya sebelum diperbaiki.
- **Lulus:** syntax check dan scoped Pint pada lima file PHP revisi, Blade cache, serta scoped diff check. Tidak menambah dependency/migrasi atau melakukan commit/push.
- **Lulus:** ketiga PDF/PNG diperbarui dan diinspeksi pada skala yang sama. Jumlah halaman tetap 1/6/5; pengukuran border/kolom maksimum tetap 0,72 mm; ekstraksi konten membuktikan `6.00 pcs`, `Weight: 60.00 kg`, dan harga cetak `105,500.00` tanpa menampilkan harga tersimpan `10,550.00/kg` sebagai Unit Price.
- **Belum diverifikasi:** browser download manual, cetak fisik, staging/production, dan full automated suite. Layout/bundle frontend tidak berubah.

## Verifikasi awal layout (sebelum revisi Qty pcs)

- **Lulus:** suite akhir setelah perubahan pagination/CSS: 52 tes, 36.397 assertions, serial pada `adasi_portal_test`: database safety, PDF baru, item-level PO generation, supplier isolation, hashid, regional scope, presentation timezone, dan translation parity.
- **Lulus:** 7 tes khusus PDF dalam suite tersebut. Meliputi award parsial, legacy, nilai snapshot, profile kosong, zero items, IDR/USD/JPY/CNY, teks panjang, nomor/barcode, urutan item sebelum halaman lanjutan, serta locale aplikasi tetap.
- **Lulus:** PHP syntax check pada sembilan file PHP task, scoped Pint `--test`, `php artisan view:cache`, dan `git diff --check` pada file task.
- **Lulus:** rendering 1/6/5 halaman, inspeksi visual seluruh halaman, pengukuran layout, dan ekstraksi/verifikasi konten PDF.
- **Global diff check:** gagal pada trailing whitespace di `context.md:3,5,6`, yang sudah termasuk perubahan sebelumnya. File tersebut tidak diedit dalam task ini.
- **Tidak dijalankan:** browser download manual, cetak fisik, staging/production, full automated suite, dan frontend build. Template menggunakan CSS PDF mandiri; tidak ada perubahan bundle Vite/CSS/JS aplikasi.

Tes memakai database test; tidak menjalankan migrasi terhadap database aplikasi, commit, atau push. Warning cache hasil PHPUnit pada salah satu run awal diatasi dengan `--do-not-cache-result` pada run final; tes final lulus tanpa warning tersebut.

## Reproduksi dan provenance

```powershell
php tests/Support/render-purchase-order-pdf-samples.php
$renderer = [ScriptBlock]::Create((Get-Content -Raw tests/Support/render-po-pdf-pages.ps1))
& $renderer -InputPdf PNR261178.pdf -OutputPrefix UI-REDESIGN-RESULT/PO-PDF-SAMPLES/reference
& $renderer -InputPdf UI-REDESIGN-RESULT/PO-PDF-SAMPLES/po-reference-layout.pdf -OutputPrefix UI-REDESIGN-RESULT/PO-PDF-SAMPLES/single
& $renderer -InputPdf UI-REDESIGN-RESULT/PO-PDF-SAMPLES/po-multiple-pages.pdf -OutputPrefix UI-REDESIGN-RESULT/PO-PDF-SAMPLES/multi
& $renderer -InputPdf UI-REDESIGN-RESULT/PO-PDF-SAMPLES/po-long-content.pdf -OutputPrefix UI-REDESIGN-RESULT/PO-PDF-SAMPLES/long
& 'C:\Users\BAHRIALGI\AppData\Local\Programs\Python\Python310\python.exe' tests/Support/verify-po-pdf-layout.py
```

Renderer menggunakan Windows.Data.Pdf yang tersedia karena Poppler renderer tidak terpasang; ukuran PNG aktual 1750 × 2475, sama untuk referensi dan hasil. Skrip Python QA memakai Pillow yang sudah tersedia dan `pdftotext`; keduanya hanya alat verifikasi, bukan dependency runtime aplikasi.

- [Barcode generator v3.3.0](https://github.com/picqer/php-barcode-generator/tree/v3.3.0).
- [Font regular resmi](https://github.com/notofonts/noto-fonts/blob/main/hinted/ttf/NotoSans/NotoSans-Regular.ttf), [font bold resmi](https://github.com/notofonts/noto-fonts/blob/main/hinted/ttf/NotoSans/NotoSans-Bold.ttf), dan [OFL](https://github.com/notofonts/noto-fonts/blob/main/LICENSE).
- SHA-256 sumber PDF: `BC670C890F71B4633D4C71481594DF1758FB29381BB5AAA438EDE63410F9A0BE`.
- SHA-256 artwork header: `A2AF42EB2BA93BCFBC48F3D98AD64CE413F0ECE745A0076102FD223E6DC8B186`.
- SHA-256 font regular: `B85C38ECEA8A7CFB39C24E395A4007474FA5A4FC864F6EE33309EB4948D232D5`.
- SHA-256 font bold: `C976E4B1B99EDC88775377FCC21692CA4BFA46B6D6CA6522BFDA505B28FF9D6A`.
