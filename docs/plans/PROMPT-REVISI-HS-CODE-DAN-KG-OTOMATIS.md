# Prompt Implementasi - Master Material, HS Code Otomatis, dan Perhitungan KG Otomatis

Salin seluruh prompt di bawah ini ke Codex/AI coding agent yang akan mengerjakan repository.

---

## Peran dan Target

Anda adalah Senior Laravel Engineer yang bertanggung jawab mengimplementasikan revisi production-ready pada:

- Repository: `poyipoy/supplierportal`
- Branch target: `master`
- Baseline yang telah diaudit saat prompt dibuat: commit `12671a5ad1d320d4a69fbe3168237f3d22f6c451`
- Stack aktual: PHP 8.2, Laravel 12, Blade, Bootstrap 5, jQuery/AJAX, MySQL, Maatwebsite Laravel Excel, PHPUnit 11

Catatan: branch `masterr` tidak ada. Gunakan branch `master`. Sebelum mengubah kode, verifikasi kembali HEAD karena repository mungkin sudah berkembang setelah baseline di atas.

Implementasikan fitur, migration, seeder, UI, integrasi, dan test. Jangan berhenti pada analisis atau hanya membuat rencana.

## File Sumber Wajib

Gunakan kedua file berikut sebagai sumber data:

1. `naufal raw material.xlsx`
2. `PENENTUAN HS CODE.pdf`

Jika salah satu file tidak tersedia di environment, tetap lakukan audit kode tetapi jangan mengarang isi master data. Laporkan file yang hilang dan minta file tersebut sebelum menjalankan seeder final.

Jangan membaca XLSX/PDF dari aplikasi pada setiap request. Normalisasikan data sumber menjadi fixture terkontrol di `database/data/`, kemudian seed ke database secara idempotent. File sumber bukan runtime database.

## Fakta Existing yang Harus Dipertahankan

Hasil audit baseline:

- Model material PR adalah `App\Models\PrItem`.
- Tabel `pr_items` sudah mempunyai `hs_code`, `material_name`, `quantity`, `shape`, `thickness`, `d_inner`, `d_outer`, `width`, `length`, dan `weight_needed`.
- Shape existing: `Flat`, `Round`, dan `Hollow`.
- Untuk `Round`, diameter existing disimpan pada `d_outer`; jangan membuat field diameter kedua yang duplikatif.
- `weight_needed` saat ini berarti berat per unit, sedangkan accessor `total_weight` menghitung `weight_needed × quantity`.
- Form PR masih meminta HS Code dan weight per unit secara manual.
- Import PR masih mewajibkan kolom `hs_code` dan `weight_needed`.
- `PurchaseRequisitionController` melakukan sanitasi dan penyimpanan item.
- `PrItem::RELEVANT_DIMENSIONS` sudah menentukan dimensi per shape.
- Perhitungan quotation/PO existing memakai total requested weight. Jangan mengubah semantik harga:

  ```text
  total_weight = weight_needed × quantity
  amount = price_per_kg × total_weight
  ```

- Route dipisahkan berdasarkan role. Semua master-data admin harus dilindungi `auth` + `role:admin`; endpoint kalkulasi PR harus dilindungi `auth` + `role:purchasing`.
- Pertahankan isolasi data supplier dan seluruh test security existing.

## Tujuan Bisnis

1. Membuat master material dan master aturan HS Code dari file sumber.
2. Menentukan HS Code secara otomatis dari material, kategori, shape, proses jika relevan, dan dimensi.
3. Menghitung KG per unit secara otomatis dari shape dan dimensi.
4. Menjadikan backend sebagai sumber kebenaran; nilai dari browser atau Excel tidak boleh dipercaya.
5. Menyimpan snapshot HS Code dan berat pada `pr_items` agar transaksi historis tidak berubah ketika master diperbarui.
6. Memberi fallback aman ketika aturan tidak lengkap atau ambigu, tanpa memilih HS Code secara sembarang.

---

# PHASE 0 - Audit Sebelum Implementasi

Sebelum menulis kode:

1. Baca `AGENTS.md` sampai selesai.
2. Periksa `git status`; jangan menimpa perubahan user yang tidak terkait.
3. Verifikasi branch, commit, struktur migration, model, controller, route, view, import/export, dan test aktual.
4. Audit minimal:
   - `app/Models/PrItem.php`
   - `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php`
   - `app/Http/Controllers/Purchasing/PrItemController.php`
   - `app/Imports/PrItemsImport.php`
   - `app/Exports/PrImportTemplateExport.php`
   - `resources/views/purchasing/pr/_item_row.blade.php`
   - `resources/views/purchasing/pr/_material_shape_script.blade.php`
   - `resources/views/purchasing/pr/create.blade.php`
   - `resources/views/purchasing/pr/edit.blade.php`
   - `resources/views/purchasing/pr/_import.blade.php`
   - `resources/views/partials/sidebar.blade.php`
   - `routes/web.php`
   - test import, PR, quotation, export, dan supplier isolation
5. Catat semua perbedaan antara prompt dan HEAD aktual. Kondisi kode aktual menang untuk detail mekanis, tetapi tujuan bisnis dan rumus dalam prompt ini wajib dipenuhi.

Jangan melakukan refactor besar yang tidak diperlukan.

---

# PHASE 1 - Audit dan Normalisasi File Sumber

## Struktur Sumber yang Sudah Ditemukan

Workbook mempunyai lima sheet:

1. `Sheet2`
2. `Backup Kriteria`
3. `backup master material`
4. `master material`
5. `Penentuan HS Code`

Audit awal menemukan:

- 84 baris material nyata pada area utama sheet `master material` setelah mengabaikan baris heading kategori.
- 64 pemetaan material-ke-kategori eksplisit pada area `F:G` sheet `master material`, konsisten dengan daftar kategori pada PDF.
- 30 baris aturan mentah HS Code.
- 19 kelompok kondisi unik jika dikelompokkan berdasarkan kategori + shape + ekspresi dimensi.
- 11 kelompok kondisi duplikat.
- Dua kelompok menghasilkan HS Code berbeda untuk kondisi dimensi yang sama.

## Urutan Otoritas Sumber

Gunakan urutan berikut:

1. Area `F:G` sheet `master material` dan bagian `KATEGORI MATERIAL` pada PDF untuk pemetaan kategori HS yang eksplisit.
2. Area `A:C` sheet `master material` untuk katalog material lebih luas, kategori mentah, dan status Daido/Non.
3. Sheet `Penentuan HS Code` dan bagian `KATEGORI PENENTUAN HS CODE` pada PDF untuk kondisi utama.
4. Sheet `Backup Kriteria` untuk metadata tambahan seperti proses dan referensi pelabuhan.
5. `Sheet2` hanya sebagai catatan kandidat lama yang belum tervalidasi.

Jangan mengaktifkan otomatis kode-kode di `Sheet2` karena:

- Bentuknya tidak konsisten dan tanpa titik.
- Satu material dapat mempunyai beberapa kandidat.
- Tidak ada kondisi dimensi lengkap.
- Tidak ada aturan prioritas.

Simpan temuan `Sheet2` dalam audit report atau fixture `inactive_candidates`, bukan sebagai rule aktif.

## Normalisasi Material

Hasil normalisasi wajib:

- Import 84 material aktual, bukan heading seperti `Cold Work Tool Steel`, `Others`, `Welding Rod`, atau `New`.
- Simpan nama display asli.
- Buat `normalized_code` dengan aturan deterministik:
  - trim;
  - collapse whitespace;
  - case-insensitive;
  - jangan menghapus titik yang merupakan bagian grade seperti `1.2316`;
  - jangan mengubah tanda hubung material seperti `S-Star`.
- Simpan `raw_category` dari workbook.
- Simpan `hs_category` canonical hanya jika didukung sumber eksplisit.
- Simpan status `Daido`, `Non`, atau `Unknown`.
- Tandai semua material yang namanya `Aluminium ...` dengan `density_profile = aluminium`.
- Material lain memakai `density_profile = steel`, kecuali master kemudian diubah admin.

Buat alias minimal dari variasi yang memang ditemukan:

- `Alumunium` -> `Aluminium`
- `SUS304` -> `SUS 304`
- variasi kapitalisasi dan spasi

Autocomplete boleh memberi saran fuzzy, tetapi auto-resolution backend hanya boleh memakai ID master atau exact normalized alias. Jangan auto-match fuzzy karena berisiko memilih grade yang salah.

## Normalisasi Kategori

Gunakan kategori canonical:

- `alloy_steel`
- `high_speed_steel`
- `carbon_steel`
- `honed_tube_steel`
- `strip_steel`
- `other`
- `unmapped`

Normalisasikan sinonim:

- `High Speed Tool Steel` -> `high_speed_steel`
- `High Speed Steel` -> `high_speed_steel`
- `Honedtube` -> `honed_tube_steel`
- `Honed Tube Steel` -> `honed_tube_steel`

Jangan otomatis mengubah seluruh `Tool Steel` atau `Other` menjadi `alloy_steel` tanpa mapping eksplisit. Material yang belum punya kategori HS yang sah tetap boleh masuk katalog, tetapi resolver harus mengembalikan `unmapped`.

## Normalisasi Rule

Ubah string kondisi menjadi nilai numerik dan operator eksplisit. Jangan mengevaluasi string natural-language dengan `eval`, regex pada setiap request, atau SQL mentah.

Setiap batas harus menyimpan:

- nilai min/max;
- inclusive/exclusive;
- satuan `mm`;
- dimensi yang dipakai.

Aturan yang sama dan menghasilkan HS Code sama boleh dikonsolidasikan, sambil mempertahankan semua `source_refs`/pelabuhan pada metadata.

## Konflik Sumber Wajib Ditangani

### Konflik A - Honed Tube

Kondisi identik:

```text
Kategori: Honed Tube Steel
Shape: Hollow
Outer Diameter >= 140 mm
Inner Diameter > 50 mm
```

memiliki dua kandidat:

- `7304.31.90`
- `7304.31.40`

Jangan memilih salah satu berdasarkan urutan row atau ID. Seed keduanya sebagai `conflict`/nonaktif dan tampilkan pada halaman Data Quality. Untuk ST52 yang memenuhi kondisi ini, resolver harus mengembalikan `ambiguous` beserta dua kandidat hingga admin menyelesaikan rule.

### Konflik B - Alloy Hollow

Kondisi dimensi identik mempunyai:

- `7304.51.90` untuk `Cold Drawn`
- `7304.59.90` untuk `Hot Drawn`

Sheet `Backup Kriteria` memberi pembeda proses, sedangkan PDF ringkas tidak mencantumkannya. Tambahkan field `material_process` pada item PR dan gunakan proses sebagai kriteria:

- Cold Drawn -> `7304.51.90`
- Hot Drawn -> `7304.59.90`
- proses kosong/tidak dikenal -> `insufficient_data` atau `ambiguous`

### Overlap Carbon Flat

Rule `Carbon Steel, Flat, Thickness > 10, Width > 600` overlap dengan rule yang lebih spesifik `Thickness >= 250, Width > 600`.

Set priority eksplisit agar rule lebih spesifik menghasilkan `7214.10.19`. Jangan bergantung pada urutan database.

### Gap dan Unreachable Rule

Audit dan laporkan minimal:

- Material Aluminium memiliki formula berat tetapi belum mempunyai rule HS Code definitif pada PDF.
- `SK5` tercatat `Other`, sementara ada rule `Strip Steel`; jangan mengubah kategori tanpa keputusan bisnis.
- Rule yang tidak mempunyai material mapping atau rentang dimensi yang menyisakan gap.

Buat `../audits/HS-CODE-MASTER-DATA-AUDIT.md` berisi jumlah source row, jumlah record hasil normalisasi, duplikat, konflik, gap, keputusan, dan item yang masih memerlukan keputusan bisnis.

---

# FUNCTIONAL REQUIREMENT 1 - Master Material

Buat tabel dan model master material. Nama final boleh disesuaikan konvensi repository, tetapi tanggung jawabnya harus jelas.

Rekomendasi tabel `material_masters`:

- `id`
- `material_code` string
- `normalized_code` string unique
- `raw_category` nullable string
- `hs_category` nullable string/index
- `density_profile` string: `steel|aluminium`
- `manufacturer_scope` nullable string: `daido|non_daido|unknown`
- `is_active` boolean default true
- `source_file`
- `source_sheet`
- `source_row`
- timestamps

Rekomendasi tabel `material_aliases`:

- `id`
- `material_master_id` FK
- `alias`
- `normalized_alias` unique
- `source_note`
- timestamps

Ketentuan:

- Gunakan model Eloquent dan relationship.
- Jangan hard-delete material yang pernah dipakai. Gunakan `is_active` atau soft delete dan lindungi referensi.
- Search endpoint hanya mengembalikan field yang diperlukan.
- Index `normalized_code`, `hs_category`, dan `is_active`.
- Seeder harus idempotent dengan `updateOrCreate`, bukan membuat duplikat setiap run.

---

# FUNCTIONAL REQUIREMENT 2 - Master Rule HS Code

Buat tabel/model `hs_code_rules`.

Kolom minimal:

- `id`
- `rule_key` unique dan stabil
- `hs_code` string; simpan canonical dengan titik, contoh `7228.30.10`
- `material_category`
- `shape`: `Flat|Round|Hollow`
- `material_process` nullable
- batas min/max untuk dimensi yang relevan atau `conditions` JSON terstruktur
- inclusive/exclusive untuk setiap batas
- `priority` integer; angka lebih kecil berarti prioritas lebih tinggi
- `status`: `active|inactive|conflict`
- `source_refs` JSON
- `notes`
- timestamps

Jika memakai `conditions` JSON, gunakan struktur konsisten:

```json
{
  "thickness": {"min": 3, "min_inclusive": true, "max": 135, "max_inclusive": true},
  "width": {"min": 400, "min_inclusive": false, "max": 600, "max_inclusive": false}
}
```

Validasi JSON melalui DTO/value object dan FormRequest. Jangan menyebarkan akses array mentah ke banyak class.

## Resolver

Buat service tunggal, misalnya:

```text
App\Services\Materials\HsCodeResolver
```

Input:

- material master/ID;
- shape;
- process jika ada;
- thickness;
- d_inner;
- d_outer;
- width;
- length.

Output terstruktur:

```text
status: matched | ambiguous | insufficient_data | no_rule | unmapped_material
hs_code: nullable
rule_id: nullable
candidates: []
message: string
matched_inputs: {}
```

Algoritma:

1. Resolve material dengan ID atau exact normalized alias.
2. Ambil kategori canonical.
3. Filter rule aktif berdasarkan category + shape.
4. Evaluasi batas numeric dan inclusive/exclusive.
5. Evaluasi process hanya bila rule memerlukannya.
6. Pilih priority tertinggi (angka terkecil).
7. Jika beberapa rule pada priority yang sama menghasilkan kode sama, anggap satu hasil dan simpan source refs.
8. Jika menghasilkan kode berbeda, kembalikan `ambiguous`.
9. Tidak boleh menggunakan `first()` tanpa validasi ambiguity.

Buat conflict detector yang:

- mendeteksi exact duplicate;
- mendeteksi interval overlap;
- menolak aktivasi dua rule overlapping dengan priority sama dan HS Code berbeda;
- menampilkan konflik pada Admin.

## Snapshot pada PR Item

Tetap simpan `pr_items.hs_code` sebagai snapshot transaksi. Tambahkan metadata minimal:

- `material_master_id` nullable FK
- `hs_code_rule_id` nullable FK
- `hs_code_source`: `auto|manual|legacy`
- `hs_code_override_reason` nullable text
- `material_process` nullable string

Record existing harus mendapat default `legacy`. Jangan mengubah HS Code historis saat master diperbarui.

---

# FUNCTIONAL REQUIREMENT 3 - Perhitungan KG Otomatis

Semua dimensi menggunakan milimeter. Hasil adalah kilogram per unit dan disimpan ke kolom existing `weight_needed`.

Gunakan rumus persis:

## Flat - Steel/Non-Aluminium

```text
KG/unit = Thickness × Width × Length × 0.00785 / 1000
```

## Flat - Aluminium

```text
KG/unit = Thickness × Width × Length × 0.00273 / 1000
```

Gunakan profil material `aluminium`, bukan pencarian string ad-hoc di controller.

## Round

Diameter memakai `d_outer` existing:

```text
KG/unit = Diameter × Diameter × Length × 0.006167 / 1000
```

## Hollow

```text
Outer KG = Outer Diameter × Outer Diameter × Length × 0.006167 / 1000
Inner KG = Inner Diameter × Inner Diameter × Length × 0.006167 / 1000
KG/unit = Outer KG - Inner KG
```

Formula aluminium khusus hanya dinyatakan untuk Flat. Untuk Round dan Hollow tetap gunakan konstanta `0.006167` sesuai requirement; jangan mengarang konstanta aluminium baru.

## Ketentuan Kalkulasi

- Buat pure service, misalnya `MaterialWeightCalculator`.
- Controller, Blade, import class, dan JavaScript tidak boleh mempunyai versi formula masing-masing.
- Backend wajib menghitung ulang ketika preview dan ketika store/update.
- Abaikan nilai `weight_needed` dari client sebagai sumber kebenaran.
- Round: `d_outer > 0`, `length > 0`.
- Hollow: `d_outer > 0`, `d_inner > 0`, `d_inner < d_outer`, `length > 0`.
- Flat: `thickness > 0`, `width > 0`, `length > 0`.
- Nilai tidak relevan harus disanitasi menjadi null memakai pola `PrItem::sanitizeMaterialData`.
- Bulatkan snapshot ke 4 decimal dengan aturan konsisten.
- `quantity` tidak masuk rumus KG/unit.
- Total KG tetap:

  ```text
  total_weight = rounded KG/unit × quantity
  ```

Tambahkan metadata audit opsional tetapi direkomendasikan:

- `weight_formula_key`: `flat_steel_v1|flat_aluminium_v1|round_v1|hollow_v1`
- `weight_factor`
- `weight_calculated_at`

Jangan rename `weight_needed` pada revisi ini karena banyak relasi/export memakai field tersebut. Ubah label UI menjadi `KG / Unit (Auto)`.

---

# FUNCTIONAL REQUIREMENT 4 - Integrasi Form PR

Ubah form Create dan Edit PR.

## Material

- Ganti free-text biasa menjadi searchable autocomplete/select master material.
- Simpan `material_master_id`.
- Tetap simpan `material_name` sebagai snapshot display.
- Izinkan pencarian case-insensitive dan alias.
- Material inactive tidak boleh dipilih untuk item baru, tetapi record lama tetap dapat ditampilkan.

## Shape dan Dimensi

- Pertahankan `Flat|Round|Hollow`.
- Flat menampilkan Thickness, Width, Length.
- Round menampilkan Diameter, dipetakan ke `d_outer`, serta Length.
- Hollow menampilkan Outer Diameter, Inner Diameter, Length.
- Tampilkan satuan `mm` pada label.
- Tambahkan Process hanya jika relevan; opsi minimal `Rolled`, `Forged`, `Cold Drawn`, `Hot Drawn`, `Other`.

## HS Code

- Field utama read-only secara default.
- Tampilkan badge:
  - `Auto matched`
  - `Needs more data`
  - `Ambiguous`
  - `No rule`
  - `Manual override`
- Tampilkan alasan dan kandidat pada ambiguity.
- Manual override hanya tersedia saat auto tidak menghasilkan satu kode.
- Manual override memerlukan alasan.
- Normalisasi input 8 digit seperti `72283010` menjadi `7228.30.10`; validasi format canonical.
- Jangan izinkan user mengganti hasil auto yang valid tanpa masuk mode override dan memberi alasan.

## KG

- Tampilkan `KG / Unit (Auto)` read-only.
- Tampilkan `Total KG = KG/unit × Qty`.
- Perbarui preview setelah material/shape/process/dimensi/quantity berubah.
- Gunakan AJAX endpoint backend dengan debounce dan pembatalan stale request.
- JavaScript hanya menampilkan hasil service; backend store/update tetap menghitung ulang.
- Tampilkan loading dan error per baris.

## Draft vs Submit

- Draft boleh disimpan jika HS Code belum resolved, tetapi beri status jelas.
- Submit wajib:
  - material dan shape valid;
  - seluruh dimensi relevan valid;
  - weight berhasil dihitung dan > 0;
  - HS Code auto matched atau manual override valid dengan alasan.
- Gunakan FormRequest/action-aware validation. Jangan memakai satu ruleset statis yang memaksa nilai palsu pada draft.

## Endpoint Preview

Rekomendasi:

```text
GET  /purchasing/material-masters/search
POST /purchasing/material-calculations/preview
```

Response preview per item:

```json
{
  "success": true,
  "material": {
    "id": 1,
    "code": "SCM440",
    "category": "alloy_steel",
    "density_profile": "steel"
  },
  "hs_code": {
    "status": "matched",
    "code": "7228.30.10",
    "rule_id": 2,
    "candidates": [],
    "message": "Matched automatically"
  },
  "weight": {
    "status": "calculated",
    "unit_kg": 61.67,
    "total_kg": 123.34,
    "formula_key": "round_v1"
  }
}
```

Endpoint preview tidak boleh menulis database.

---

# FUNCTIONAL REQUIREMENT 5 - Integrasi Import PR

Update `PrItemsImport`, `PrImportTemplateExport`, modal import, preview, dan test.

Template baru minimal:

```text
material_name
material_process
shape
quantity
thickness
d_inner
d_outer
width
length
remark
```

`hs_code` dan `weight_needed` bukan lagi input wajib.

Backward compatibility:

- File lama yang memiliki `hs_code` dan `weight_needed` tetap boleh dibaca.
- Nilai lama tersebut tidak dipercaya.
- Recompute menggunakan service yang sama.
- Jika berbeda, berikan warning yang menunjukkan supplied value dan calculated value.

Preview import wajib:

- resolve material master/alias;
- sanitize dimensi sesuai shape;
- hitung KG/unit dan total KG;
- resolve HS Code;
- menampilkan error/warning per row;
- tidak menulis database;
- tidak mengubah form existing jika file mempunyai error fatal;
- mempertahankan limit 1.000 row, max 10 MB, MIME/extension validation, formula rejection, first-sheet policy, temporary-file cleanup, dan role guard existing.

Jangan menaruh parsing dan business logic baru seluruhnya di Controller atau Blade.

---

# FUNCTIONAL REQUIREMENT 6 - Admin Master Data

Tambahkan menu `Master Material & HS Code` pada sidebar Admin.

Minimal dua tab:

1. Materials
2. HS Code Rules

Tambahkan panel `Data Quality`.

## Materials

- DataTables/pagination server-side.
- Search by material code, alias, raw category, HS category.
- Filter active, category, density profile, Daido/Non.
- Create/edit/activate/deactivate.
- Kelola alias.
- Tampilkan source file/sheet/row.
- Material referenced tidak boleh di-hard-delete.

## Rules

- Search/filter HS Code, category, shape, process, status.
- Form bounds numeric dengan inclusive/exclusive.
- Priority eksplisit.
- Preview contoh input.
- Validasi overlap sebelum activate.
- Conflict tidak dapat diaktifkan sampai diselesaikan.
- Tampilkan source refs.

## Data Quality

Tampilkan minimal:

- jumlah material;
- jumlah material tanpa canonical HS category;
- jumlah active/inactive/conflict rules;
- exact duplicate;
- overlapping rules;
- unreachable category/rule;
- kandidat legacy dari `Sheet2`;
- konflik ST52;
- material/rentang tanpa rule.

Gunakan Bootstrap 5 + Bootstrap Icons sesuai desain existing.

---

# DATABASE DAN MIGRATION

Buat migration additive dan reversible. Jangan mengubah migration lama yang mungkin sudah dijalankan di production.

Minimal:

1. create `material_masters`
2. create `material_aliases`
3. create `hs_code_rules`
4. add reference/audit fields to `pr_items`

Migration harus:

- memiliki `down()` lengkap;
- mempertahankan data existing;
- memakai FK behavior aman;
- tidak hard-delete snapshot;
- berhasil migrate dan rollback di testing database;
- menghindari enum database jika menyulitkan perubahan lintas MySQL/SQLite test; string + application validation boleh dipilih.

Backfill:

- Set `hs_code_source = legacy` untuk record existing.
- Jangan menghitung ulang record historis secara otomatis.
- Jangan mengubah amount quotation, PO, atau laporan historis.
- Draft/rejected PR boleh dihitung ulang ketika user membuka dan menyimpan ulang.
- Jika membuat command backfill, wajib punya `--dry-run`, summary, dan scope aman. Default tidak boleh memodifikasi submitted/completed/PO-linked data.

---

# ARSITEKTUR YANG DIHARAPKAN

Nama class boleh disesuaikan, tetapi pisahkan tanggung jawab:

```text
app/
├── Data/Materials/
│   ├── HsCodeResolutionResult.php
│   └── WeightCalculationResult.php
├── Http/
│   ├── Controllers/Admin/
│   │   ├── MaterialMasterController.php
│   │   └── HsCodeRuleController.php
│   ├── Controllers/Purchasing/
│   │   └── MaterialCalculationController.php
│   └── Requests/
│       ├── MaterialCalculationRequest.php
│       ├── StoreMaterialMasterRequest.php
│       └── StoreHsCodeRuleRequest.php
├── Models/
│   ├── MaterialMaster.php
│   ├── MaterialAlias.php
│   └── HsCodeRule.php
├── Services/Materials/
│   ├── MaterialResolver.php
│   ├── HsCodeResolver.php
│   ├── HsCodeRuleConflictDetector.php
│   └── MaterialWeightCalculator.php
└── Imports/
    └── PrItemsImport.php

database/
├── data/
│   ├── material_masters.json
│   └── hs_code_rules.json
└── seeders/
    └── MaterialHsCodeMasterSeeder.php
```

Larangan:

- Jangan menaruh query di Blade.
- Jangan menduplikasi formula di Controller, Import, dan JavaScript.
- Jangan memakai floating string comparison untuk boundary.
- Jangan menaruh seluruh rule sebagai rangkaian `if/elseif` hardcoded.
- Jangan memilih conflict dengan `first()`.
- Jangan mengambil HS Code/weight client sebagai nilai final.
- Jangan membuat request ke internet untuk menentukan HS Code.

Cache active master/rules boleh digunakan, tetapi invalidasi cache setelah perubahan admin.

---

# ROUTE DAN AUTHORIZATION

Rekomendasi route:

```text
Admin:
GET/POST/... /admin/material-masters
GET/POST/... /admin/hs-code-rules
GET          /admin/master-data-quality

Purchasing:
GET  /purchasing/material-masters/search
POST /purchasing/material-calculations/preview
```

Ketentuan:

- Admin CRUD: `auth` + `role:admin`.
- Search/preview PR: `auth` + `role:purchasing` dan middleware navigasi existing bila sesuai.
- Tempatkan route statis sebelum wildcard yang berpotensi collision.
- Tambahkan rate limiting wajar untuk autocomplete/preview bila pola aplikasi mendukung.
- Supplier tidak boleh mengakses endpoint admin atau calculation purchasing.
- Supplier tetap dapat melihat snapshot HS Code dan berat dari PR yang memang berhak dilihat.

---

# VALIDATION, SECURITY, DAN DATA INTEGRITY

- Gunakan FormRequest atau validator terpusat.
- Escape seluruh output user pada Blade.
- Validasi mass assignment.
- Gunakan transaksi pada store/update PR.
- Server recompute HS/weight tepat sebelum persist.
- Lindungi dari request tampering terhadap `material_master_id`, `hs_code_rule_id`, dan weight.
- Jangan percaya `hs_code_rule_id` dari client; resolver yang menentukan.
- Manual HS Code wajib canonical dan alasan wajib.
- Log perubahan master/rule minimal dengan `updated_by` atau audit trail yang sesuai pola repo.
- Tidak ada file sumber yang disimpan di `public/`.
- Seeder fixture harus dapat diaudit kembali ke file/sheet/row.
- Gunakan eager loading dan cache untuk mencegah query per item.
- Import 1.000 row tidak boleh melakukan query satu per satu; preload material/alias/rule dan index collection.

---

# TEST WAJIB

Ikuti pola PHPUnit/RefreshDatabase existing.

## Unit - Weight Calculator

Gunakan exact cases:

1. Flat steel, T=10, W=100, L=1000:

   ```text
   10 × 100 × 1000 × 0.00785 / 1000 = 7.8500 KG
   ```

2. Flat Aluminium, dimensi sama:

   ```text
   10 × 100 × 1000 × 0.00273 / 1000 = 2.7300 KG
   ```

3. Round, D=100, L=1000:

   ```text
   100 × 100 × 1000 × 0.006167 / 1000 = 61.6700 KG
   ```

4. Hollow, OD=100, ID=60, L=1000:

   ```text
   61.6700 - 22.2012 = 39.4688 KG
   ```

5. Reject zero/negative dimensions.
6. Reject Hollow jika ID >= OD.
7. Verify rounding 4 decimal.
8. Verify quantity hanya memengaruhi total weight, bukan unit weight.

## Unit - HS Resolver

- Exact material and alias normalization.
- Category synonym `High Speed Tool Steel` -> `High Speed Steel`.
- Inclusive/exclusive boundaries pada 3, 4.75, 10, 135, 150, 165, 250, 400, 600, 140, dan 2000.
- Same-code duplicates collapse.
- Different-code match returns ambiguous.
- Priority-specific rule wins pada Carbon Flat thickness >= 250.
- No rule returns `no_rule`, bukan exception/asal pilih.
- Inactive/conflict rule tidak dipilih.
- Cold Drawn vs Hot Drawn.

## Feature - PR Form

- Autocomplete hanya role purchasing.
- Preview tidak menulis DB.
- SCM440 Round D=100 L=1000 Qty=2:
  - HS `7228.30.10`
  - unit `61.6700`
  - total `123.3400`
- Aluminium 7075 Flat:
  - weight memakai faktor aluminium;
  - HS tidak diambil dari kandidat `Sheet2`;
  - status no-rule/needs manual decision.
- ST52 Hollow:
  - dua kandidat ditampilkan;
  - sistem tidak auto memilih.
- Manual override memerlukan alasan.
- Client yang memanipulasi HS/weight tetap tersimpan dengan hasil backend.
- Edit draft recompute saat material/dimensi berubah.
- Record historis tidak berubah hanya karena master diubah.

## Feature - Import

- Template baru mempunyai heading benar.
- Legacy columns diterima tetapi tidak dipercaya.
- Per-row calculation dan resolution benar.
- Invalid alias/material, dimensi, conflict, no rule, dan formula injection memiliki pesan row+column.
- Preview tetap read-only.
- Limit, MIME, max size, extra sheet, XLS/XLSX/CSV, cleanup, role guard existing tetap lulus.

## Feature - Admin

- Non-admin 403.
- CRUD/activate/deactivate.
- Duplicate normalized material ditolak.
- Conflicting overlapping rule tidak dapat diaktifkan.
- Material referenced tidak dapat hard-delete.
- Seeder idempotent.
- Data quality menampilkan konflik sumber.

## Regression

Jalankan seluruh test suite existing, terutama:

- PR item/remark;
- Mission Five import;
- quotation availability;
- purchase order reference/remark;
- supplier data isolation;
- export security;
- notification delivery.

---

# ACCEPTANCE SCENARIOS

## Scenario A - Alloy Round

Material SCM440, Round, D=100, L=1000, Qty=2.

Expected:

- kategori `alloy_steel`;
- HS `7228.30.10`;
- KG/unit `61.6700`;
- Total KG `123.3400`;
- source `auto`.

## Scenario B - Alloy Round Forged Boundary

SCM440, Round, D=166, L=1000.

Expected HS: `7228.40.10`.

## Scenario C - Aluminium Flat

Aluminium 7075, Flat, T=10, W=100, L=1000.

Expected:

- KG/unit `2.7300`;
- density profile `aluminium`;
- HS tidak ditebak dari `Sheet2`;
- user melihat status unresolved dan fallback manual yang diaudit.

## Scenario D - Alloy Hollow

SCM440, Hollow, OD=200, ID=150, L=1000.

Expected KG/unit:

```text
(200² - 150²) × 1000 × 0.006167 / 1000 = 107.9225 KG
```

- Cold Drawn -> `7304.51.90`
- Hot Drawn -> `7304.59.90`
- Tanpa process -> needs more data/ambiguous

## Scenario E - ST52 Conflict

ST52, Hollow, OD=200, ID=100, L=1000.

Expected:

- KG/unit `185.0100`;
- kandidat `7304.31.90` dan `7304.31.40`;
- tidak ada auto-selection;
- submit memerlukan rule yang sudah diselesaikan admin atau manual override+reason.

## Scenario F - Carbon Flat Specific Rule

S45C, Flat, T=250, W=700, L=1000.

Expected:

- HS `7214.10.19` dari rule lebih spesifik;
- bukan `7208.51.00`;
- KG/unit `1373.7500`.

## Scenario G - Tampered Import

Excel mengirim HS dan weight yang salah.

Expected:

- preview memperingatkan mismatch;
- backend menyimpan hasil resolver/calculator;
- supplied HS/weight tidak dipercaya;
- preview tidak menulis DB.

---

# COMMAND VALIDATION

Sesuaikan dengan environment, lalu jalankan minimal:

```bash
composer install
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=MaterialHsCodeMasterSeeder
php artisan route:list
php artisan test
vendor/bin/pint --test
npm ci
npm run build
```

Migration rollback harus diuji pada testing database, bukan database production:

```bash
php artisan migrate:rollback --step=<jumlah_migration_baru>
php artisan migrate
```

Jangan menjalankan `migrate:fresh` pada database user/production.

Jika ada command yang tidak dapat dijalankan, sebutkan command, error, dan dampaknya. Jangan mengklaim test/build sukses tanpa benar-benar menjalankannya.

---

# REQUIRED DELIVERABLES

Setelah implementasi, berikan:

1. Analysis Summary.
2. Source Data Audit:
   - 84 material source rows;
   - 64 explicit category mappings;
   - 30 raw rule rows;
   - hasil dedupe/normalisasi;
   - konflik/gap/unreachable rules.
3. Implementation Summary.
4. Database Impact dan rollback behavior.
5. Route Map + middleware.
6. Master Data Import/Seeder Report.
7. HS Rule Resolution Matrix.
8. Weight Formula Specification.
9. Test Report dengan command dan pass/fail.
10. Changed Files lengkap.
11. Remaining Risks dan keputusan bisnis yang belum tersedia.
12. `../audits/HS-CODE-MASTER-DATA-AUDIT.md`.

---

# DEFINITION OF DONE

- [ ] Branch `master` dan HEAD aktual diaudit.
- [ ] 84 material dinormalisasi tanpa mengimpor heading sebagai material.
- [ ] 64 mapping eksplisit dapat ditelusuri ke source.
- [ ] Rule tidak dieksekusi dari string natural-language saat request.
- [ ] Duplicate same-code ditangani deterministik.
- [ ] ST52 conflict tidak dipilih otomatis.
- [ ] Cold/Hot Drawn menghasilkan kode berbeda yang benar.
- [ ] Overlap Carbon Flat memilih rule spesifik.
- [ ] Material Aluminium memakai faktor Flat `0.00273`.
- [ ] Flat non-Aluminium memakai `0.00785`.
- [ ] Round/Hollow memakai `0.006167`.
- [ ] `weight_needed` tetap KG/unit dan `total_weight` tetap dikali quantity.
- [ ] Backend menghitung ulang HS dan KG saat save.
- [ ] Nilai client/Excel tidak dipercaya.
- [ ] Draft dan submit memiliki validasi yang tepat.
- [ ] Historical PR/quotation/PO tidak direcalculate diam-diam.
- [ ] Admin dapat mengelola master dan melihat data quality.
- [ ] Route role-protected.
- [ ] Import preview tetap read-only dan aman.
- [ ] Supplier isolation dan test existing tetap lulus.
- [ ] Migration dan rollback berhasil di testing DB.
- [ ] Seluruh PHPUnit test dan frontend build dijalankan.
- [ ] Semua konflik/gap yang belum selesai dilaporkan jujur.

Implementasi dianggap gagal jika memilih HS Code pertama secara arbitrer, hanya menghitung di JavaScript, masih mempercayai `weight_needed`/HS Code dari request, atau mengubah transaksi historis tanpa audit.
