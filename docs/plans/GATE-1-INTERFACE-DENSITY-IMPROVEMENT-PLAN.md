# GATE 1 — Interface Density Slider & Live Preview

Tanggal audit: **19 Oktober 2026**. Workspace: `C:\laragon\www\adasi_portal_supplier`; repository `poyipoy/supplierportal`, branch `master`.

**Otorisasi: PLANNING ONLY. Status: PENDING USER APPROVAL FOR GATE 2.**

## 1. Ringkasan eksekutif dan batas bukti

Rekomendasi: **C — token density semantik terpusat dengan adopsi komponen bersama dan pengecualian sempit**. Pertahankan bootstrap `AdasiPreferences`, form PATCH existing, string kanonik, dan default `comfortable`. Gunakan native range lima stop, form sebagai sumber draft, dan `html[data-density]` sebagai proyeksi efektif. Mini preview mewarisi token yang sama; tidak mempunyai density, penyimpanan, atau renderer terpisah.

Penyebab utama efek density lemah adalah padding tabel tetap `!important`, default komponen tabel yang sudah bernama compact, spacing kartu/form/layout yang belum memakai density, serta tinggi minimum kontrol/konten. Ini bukan bukti bahwa penyimpanan preferensi gagal.

Tidak ada PHP/Blade/CSS/JS aplikasi, package, environment, skema, atau record yang diubah pada Gate 1. Satu-satunya deliverable yang ditulis adalah dokumen ini. Tidak ada stage/commit/push/deploy. Dokumen sebelumnya pada path ini tidak ada saat diperiksa.

### Klasifikasi yang digunakan

| Label | Arti |
|---|---|
| VERIFIED-SOURCE | Dibaca pada source working tree; bukan klaim rendered/browser |
| VERIFIED-EXECUTION | Perintah atau tool benar-benar dijalankan; batas observasinya disebutkan |
| INFERRED | Kesimpulan/perhitungan dari source atau rancangan yang belum diimplementasikan |
| UNVERIFIED | Memerlukan browser, perangkat bantu, data uji terisolasi, atau peserta |

Semua tabel **usulan**, ADR, tahap Gate 2, dan acceptance di bawah adalah **INFERRED/rencana**, bukan hasil implementasi. Ukuran CSS sumber dibedakan dari ukuran rendered.

### Konteks dan kondisi tree

- VERIFIED-SOURCE: `AGENTS.md`, `CLAUDE.md`, framework kognitif Laravel, `context.md`, `resources/views/components/ui/CLAUDE.md`, `app/Http/Middleware/CLAUDE.md`, serta kontrak visual Phase 2 dibaca. Tidak ditemukan nested `AGENTS.md` pada inventaris repository. File wajib tersedia.
- Skill ECC yang ditinjau: `.agents/skills/design-system/SKILL.md`, `accessibility/SKILL.md`, `ui-ux-pro-max/SKILL.md`, `laravel-patterns/SKILL.md`, `laravel-tdd/SKILL.md`, `laravel-verification/SKILL.md`, `frontend-patterns/SKILL.md`; workflow lokal `.claude/skills/verify/SKILL.md`. Terapkan audit spacing, native semantics, state ownership, pengujian perilaku, dan biaya reflow. Contoh React/React Native, pembuatan design system baru, factory baru, SQLite, package, dan pipeline deployment generik tidak diadopsi. Instruksi task planning dan kontrak proyek mengendalikan scope.
- VERIFIED-EXECUTION: awal audit mempunyai perubahan tracked/untracked pengguna, termasuk sidebar, routes, Tailwind, dokumentasi, dan fitur kontak supplier. Di tengah audit HEAD berubah di luar tindakan agent menjadi `65e61f15cccdb562d5ae2616f6835efd31451561` (`feat(suppliers): implement import supplier management, company profile, and contact review workflows`); status kemudian bersih. Audit source menggunakan tree terbaru tersebut. Agent tidak melakukan operasi Git mutatif.
- Kontrak Phase 2 historis memuat English-only dan workflow visual manual untuk mission Phase 2. Task ini mengikuti lokalisasi current `AGENTS.md`, lima label yang dikunci user, dan izin pengukuran aman dalam permintaan sekarang. Path rencana mengikuti instruksi eksplisit user ini; hasil visual Gate 2 dapat diletakkan di `UI-REDESIGN-RESULT/`.

## 2. Keputusan produk/UX yang dikunci

| Stop | Nilai tersimpan | Label |
|---|---|---|
| 1 | `spacious` | Spacious |
| 2 | `comfortable` | Comfortable |
| 3 | `balanced` | Balanced |
| 4 | `compact` | Compact |
| 5 | `dense` | Dense |

Default tetap stop 2. Nilai existing Comfortable/Compact tidak ditulis ulang. Label level mengikuti tepat nama di atas; helper, error, status, dan teks penjelas tersedia berpasangan en/id. `choices.comfortable`/`choices.compact` existing berbahasa Indonesia saat ini adalah Nyaman/Ringkas; sediakan namespace label density khusus untuk memenuhi label terkunci tanpa mengubah label unrelated.

Input langsung memperbarui label, mini preview, dan bagian Customization yang sensitif density. Tidak ada reload, request save, localStorage density, atau persistence sebelum Save Changes. Badge perubahan belum disimpan tetap terlihat. Slider, Save, Batalkan, dan pengontrol appearance mempertahankan ukuran/posisi. Tidak ada perubahan page size, payload data, otorisasi, workflow, atau hasil export.

## 3. Root cause berbasis source

| ID | Temuan dan bukti | Klasifikasi / implikasi |
|---|---|---|
| R1 | `resources/css/app.css:2275–2289`: `.table thead th` dan `.table td` memakai `padding: .625rem .875rem !important`. Selector compact sekitar `874–877` hanya `padding-block: .375rem`, tanpa important. | VERIFIED-SOURCE. Cascade important mengalahkan compact pada `.table`; hasil pixel rendered belum diukur. Perbaiki deklarasi pemilik padding, bukan menambah rantai override. |
| R2 | `components/ui/data-table.blade.php:7,14` default prop `density='compact'` memasang `ui-table--compact`. CSS `2338–2341` memberi height 2.5rem/2.25rem dan padding-block 4px. Selector density noncompact mengecualikan kelas ini. | VERIFIED-SOURCE. Dua konsep compact bercampur: default komponen dan preferensi user. Padding 4px tersebut juga kalah pada sel yang memiliki `.table` karena R1. |
| R3 | Selector density saat ini terbatas pada switch, tabel, toolbar/DataTables controls, dan sidebar (`app.css:851–904`). `card`, `metric-card`, `dashboard-layout`, body form section, page gutter, dan banyak grid memakai angka tetap. | VERIFIED-SOURCE. Pemilihan density tidak menghubungkan sebagian besar ruang antarkomponen. |
| R4 | Card body memakai 16/20px; metric 16px; dashboard-layout gap 16px; form section body gap 16px dan padding 16/20px; halaman sering gap 16/20/24px. | VERIFIED-SOURCE. Tailwind spacing biasa tidak berubah saat atribut density berganti. Jangan mengganti semua utility secara global. |
| R5 | `.ui-toolbar` CSS dasar 12px/16px berbeda dari markup `tw-p-3.5` (14px); form-section juga mempunyai default CSS dan utility margin/padding berbeda. `@tailwind` base/components/utilities dan `@layer` digunakan; Bootstrap dimuat sebelum Vite, page styles sesudahnya. | VERIFIED-SOURCE; computed winner untuk utility setara perlu compiled/browser verification. Urutan load saja tidak mengalahkan important/specificity. |
| R6 | `--ui-control-height-sm=2.125rem` (34px), md=40px; status-chip min 24px; sidebar font 14px × 1.5 dan padding 8px; ada input, badge, wrapping, dan row aksi. | VERIFIED-SOURCE. Height tabel CSS adalah batas bawah layout, bukan jaminan tinggi row aktual. INFERRED: mengurangi row-height saja dapat tidak terlihat. |
| R7 | Sidebar baseline min 40px; compact 36px, tetapi padding 8px + line box sekitar 21px dapat menahan ukuran. Mobile menetapkan min 44px. | VERIFIED-SOURCE/INFERRED. Token sidebar harus mengatur padding vertikal bersama minimum; rail width/ikon tetap. |
| R8 | `preferences.js:7–24` memasang listener theme/accent/density; Blade juga memanggil metode Alpine preview pada change. | VERIFIED-SOURCE. Dua binding untuk input yang sama; efek runtime berapa kali belum diobservasi. Tetapkan satu owner. |
| R9 | `customization-form.js:46–59` tidak mendelegasikan change semua field. Beberapa select/checkbox hanya mengubah DOM; reorder di `preferences.js` tidak memicu recompute form. | VERIFIED-SOURCE. Risiko badge/Save tidak mengikuti perubahan selain appearance; regression test saat ini hanya menguji `sameOrder`/`sameSet`. |
| R10 | `bindBackForwardRestoration` mengembalikan appearance tersimpan lalu mengatur `.checked` radio. Tidak menyinkronkan range, label, atau dirtyCount. POST form juga otomatis masuk tracker global `unsaved-changes.js`, sementara Alpine mempunyai guard beforeunload sendiri. | VERIFIED-SOURCE. Pemulihan dan navigasi perlu satu kontrak, bukan menambahkan handler slider ketiga. |
| R11 | View memakai `old('density')` pada radio, bootstrap memakai saved density. Init menghitung dirty tetapi tidak menerapkan old preview. | VERIFIED-SOURCE. Risiko nilai form dan root berbeda setelah validasi gagal; rendered flash UNVERIFIED. |
| R12 | PR editor mempunyai `min-height:300px`, sticky header 38px, input; quotation editor mempunyai padding penting lokal/sticky groups. Chart memakai area tersendiri. | VERIFIED-SOURCE. Jangan mengejar target penghematan dengan memotong konten atau mengubah constraint editor/chart. |

Inventaris source dieksekusi atas **282 Blade files**: `x-ui.data-table` **69 pemakaian/60 file**, `card` **166/59**, `metric-card` **57/14**, `toolbar` **21/20**, `form-section` **31/15**, `dashboard-layout` **8/8**. Terdapat **18 kemunculan `table-sm`**, **7** pemakaian eksplisit prop tabel `density="compact"`, dan **3.779** kecocokan utility spacing numerik pada **236 file** (regex p/gap/space-y/margin tertentu; bukan jumlah defect atau elemen rendered). Ini bukti reuse untuk memprioritaskan shared components, bukan alasan bulk replace.

## 4. Arsitektur preferensi dan lifecycle existing

```text
user_preferences.density (user_id unik)
  → ApplyUserLocale → UserPreferenceService::for(user)
  → request attributes cache per user
  → controller edit + composer layouts.app + dashboard-layout memakai cache sama
  → frontendPayload → inline head bootstrap sebelum CSS/Vite
  → savedDensity (snapshot) + previewDensity (sementara/null)
  → html.dataset.density → selector CSS
  → radio form old()/saved + listener JS/Alpine
  → PATCH profile.customization.update → FormRequest Rule::in(config)
  → save(): lock user, transaksi, preference revision++, cache request dibuang
  → redirect GET baru → bootstrap saved baru
```

VERIFIED-SOURCE:

- `config/user_preferences.php` hanya mengizinkan `comfortable`, `compact`; default Comfortable. `UserPreference` fillable density berupa string, tanpa enum cast.
- `UserPreferenceService::for()` membaca satu preference lalu menyimpannya dalam request attributes. Belum menormalisasi density invalid dari DB. `UserCustomizationTest::test_layout_resolves_the_preference_relation_once_per_request` mengunci **1 query preference**.
- `UpdateUserPreferenceRequest` mengotorisasi user login dan memvalidasi config; controller update hanya mengambil safe fields. Resource owner berasal dari authenticated user, bukan user_id kiriman.
- `save()` dan `reset()` mempertahankan transaksi/lock/revision existing. Tidak perlu jalur simpan baru.
- Bootstrap membedakan saved appearance dan temporary preview. Hanya sidebar yang mempunyai cache localStorage ber-key akun dan sidebar revision; density tidak memakai cache itu.
- `resetSection('appearance')` dan Reset all pada UI adalah **draft client**; baru tersimpan saat Save. Endpoint DELETE existing adalah **reset server langsung**, juga mempunyai scope dashboard. Jangan menukar semantik keduanya.
- `restoreSaved()` mengembalikan theme/density/accent bersamaan. Pageshow persisted memulihkan snapshot appearance dari bootstrap halaman lama, bukan membaca DB terbaru.

## 5. Kontrak density lima level dan precedence

Urutan otoritas presentasi:

1. Minimum aksesibilitas/fungsi: ukuran target, focus outline, line-height, validation, wrapping, editor/sticky geometry, mobile.
2. Exception komponen yang dinyatakan dan diuji: editor input, action rail, splash, Column Designer, print.
3. Density efektif pilihan user/draft pada `html[data-density]`.
4. Default spacing komponen, dengan fallback Comfortable bila atribut/JS tidak tersedia.

Semua ukuran harus **nonincreasing** dari Spacious ke Dense. Tidak diwajibkan setiap komponen mengecil pada setiap stop ketika mencapai minimum aman; kelima level tetap harus dapat dibedakan pada fixture tabel teks, metric, serta spacing section. Font, bobot font, jumlah kolom, isi, visibility, sorting, pagination, dan authorization tidak menjadi alat density.

### Penyelesaian `ui-table--compact`

Ubah default prop tabel menjadi `auto` (mengikuti root). Pertahankan prop/string `compact` dan kelas lama sebagai compatibility alias/default fallback, **bukan override terhadap pilihan user**. Tujuh caller eksplisit existing di Purchasing periods/PO/PR/quotations/reference-prices/shipments dan Supplier shipments tidak perlu diedit satu per satu. Tidak ada kebutuhan fixed compact yang dibuktikan pada daftar tersebut.

Pindahkan aturan padding/row-min ke satu pemilik CSS dengan variable. Jika suatu editor membutuhkan exception, gunakan scope editor existing yang spesifik; jangan menyalahgunakan prop `compact` untuk menonaktifkan preference. Row height merupakan minimum dan content boleh memperbesar. Tidak ada height/max-height yang memotong teks.

## 6. Model token dan baseline ukuran

### 6.1 Baseline yang benar-benar tersedia

| Komponen | Baseline deklarasi source, asumsi root 16px | Status |
|---|---|---|
| Page gutter desktop/mobile | 20px vertikal/24px horizontal; mobile 16px semua sisi | VERIFIED-SOURCE (`app.css:1579`, `3627`) |
| Spacing primitive | 4, 8, 12, 16, 20, 24, 32px; utility setengah-step juga dipakai | VERIFIED-SOURCE |
| Table `.table` | Padding Y10/X14px important; font body14px; header12px | VERIFIED-SOURCE |
| Shared compact table | height40px, preference compact36px; deklarasi padding Y4px kalah pada `.table` | VERIFIED-SOURCE; bukan row aktual |
| Metric/card | Metric16px; card md16px mobile/20px desktop; header Y14/X16px | VERIFIED-SOURCE |
| Toolbar | Markup14px semua sisi, gap12px; compact CSS Y8/X12px, gap8px | VERIFIED-SOURCE; computed belum diukur |
| Form | Body gap16px, padding16/20px; label/helper spacing6px; input min40px | VERIFIED-SOURCE |
| Sidebar | Desktop min40px, compact36px, padding Y8px, margin Y2px; mobile min44px | VERIFIED-SOURCE |
| Action/minimum | sm34px, md40px, chip24px, modal tertentu footer/close44px | VERIFIED-SOURCE |
| Rows terlihat, panel/dashboard height, scrollHeight, toolbar wrapping | Belum ada hasil pixel/browser | UNVERIFIED |

VERIFIED-EXECUTION: metadata database lokal dibaca dengan SELECT `INFORMATION_SCHEMA.COLUMNS` untuk kolom density saja: **`varchar(20)`, panjang 20, `NOT NULL`**. Tidak membaca/menulis preference records. Tidak menyimpulkan kondisi staging/produksi dari hasil lokal ini.

### 6.2 Tabel kandidat token

**Kandidat terikat baseline source, belum angka final terkalibrasi browser.** Gate 2 tahap 0 wajib mengukur computed style dan dimensi sebelum menetapkan nilai final. Tidak mengklaim telah menentukan angka dari rendered measurement yang belum tersedia. Gunakan rem; tabel px memudahkan perbandingan pada root16px.

Prefix variable: `--ui-density-*`; lima blok root hanya mengisi variable. Jangan mengganti primitive `--ui-space-*`, token font, warna, atau `--ui-control-height-*` global, karena digunakan calendar, security, splash, dan export designer.

| Suffix token / pemakai | Spacious | Comfortable | Balanced | Compact | Dense |
|---|---:|---:|---:|---:|---:|
| `page-block` | 24 | **20** | 16 | 12 | 8 |
| `page-inline` desktop | 28 | **24** | 20 | 16 | 12 |
| `stack-sm` (baseline gap16) | 20 | **16** | 14 | 12 | 8 |
| `stack-md` (baseline gap20) | 24 | **20** | 18 | 16 | 12 |
| `stack-lg` (baseline gap24) | 28 | **24** | 20 | 16 | 12 |
| `card-padding` md desktop | 24 | **20** | 18 | 16 | 12 |
| `metric-padding` / card md mobile | 20 | **16** | 14 | 12 | 8 |
| `panel-block` (header/footer) | 18 | **14** | 12 | 10 | 8 |
| `panel-inline` | 20 | **16** | 14 | 12 | 10 |
| `toolbar-block` | 18 | **14** | 12 | 8 | 6 |
| `toolbar-inline` | 20 | **14** | 12 | 12 | 10 |
| `toolbar-gap` luar | 16 | **12** | 10 | 8 | 6 |
| `toolbar-gap` grup kiri | 12 | **10** | 8 | 6 | 4 |
| `toolbar-gap` grup kanan | 12 | **8** | 8 | 6 | 4 |
| `table-cell-block` | 12 | **10** | 8 | 6 | 4 |
| `table-cell-inline` | 16 | **14** | 12 | 10 | 8 |
| `table-row-min` | 44 | **40** | 36 | 32 | 28 |
| `form-gap` antarkelompok | 20 | **16** | 14 | 12 | 8 |
| `list-block` untuk list baseline12 | 16 | **12** | 10 | 8 | 6 |
| `sidebar-item-min` desktop | 44 | **40** | 38 | 36 | 34 |
| `sidebar-item-block` | 10 | **8** | 6 | 4 | 2 |
| `modal-body-padding` Bootstrap opt-in | 20 | **16** | 14 | 12 | 10 |

Dasar angka: baseline nyata dari deklarasi current; langkah 4px memakai skala spacing existing, sub-langkah 2px mengikuti `.125rem` yang sudah dipakai dan memungkinkan lima level tanpa menyusutkan font/kontrol. Table Y6px mempertahankan intent selector Compact existing; Y4px mempertahankan intent compact component existing. Semua kandidat menjaga Comfortable yang relevan, termasuk padding tabel **aktual menurut cascade source** 10px, bukan menganggap 4px sebagai baseline.

Variasi komponen tetap semantik: card `padding=none` tetap nol; `padding=sm` memakai panel token; form/header yang baseline20 memakai token card, bukan dipaksa14. Stack sm/md/lg menggantikan utility pada container yang dipilih sesuai baseline awalnya, bukan mengubah semua `.tw-gap-*`. Panel/list memakai alias token tabel ini, tidak membuat skala density kedua.

Mobile/max991.98px atau `any-pointer:coarse`: gutter minimal16px, sidebar target minimal44px, kontrol utama/slider minimal44px; form/toolbar/list gap tidak di bawah8px. Semua target lain memenuhi minimal24×24px dan tidak mengecil dari minimum functional existing. Jika baseline menemukan aksi mobile existing34/40px, floor scoped pada kontrol operasional dapat **membesarkannya** ke44px, konsisten di semua level; jangan mengecilkan Comfortable untuk menyamakan baseline. Gunakan scope content/modal opt-in, bukan mengubah token kontrol global atau stylesheet splash/designer. Breakpoint/sidebar motion tetap existing (jangan membetulkan perbedaan `shell:993px` vs992px dalam task ini). Hasil mobile boleh mencapai plateau demi aksesibilitas.

### 6.3 Prediksi terukur untuk merancang acceptance

INFERRED, **bukan hasil browser**: teks satu baris14px × line-height1.5 =21px; border asumsi1px; row `max(row-min, content + 2×cellY + border)`.

| Fixture | Spacious | Comfortable | Balanced | Compact | Dense |
|---|---:|---:|---:|---:|---:|
| Row teks prediksi px | 46 | 42 | 38 | 34 | 30 |
| Row dengan aksi34px prediksi px | 59 | 55 | 51 | 47 | 43 |
| Row teks penuh pada body480px | 10 | 11 | 12 | 14 | 16 |
| Row aksi penuh pada body480px | 8 | 8 | 9 | 10 | 11 |

Prediksi Comfortable→Dense menghemat sekitar29% tinggi row teks dan22% row aksi. Target konservatif15% pada fixture tabel action standar memberi margin terhadap perbedaan border/font nyata; belum merupakan jaminan semua halaman. Jumlah row terlihat dapat sama pada dua stop walaupun row mengecil. Jangan mengubah pageLength supaya angka terlihat lebih baik.

### 6.4 Integrasi CSS terkecil

- Pertahankan file `resources/css/app.css` sebagai owner token/adapters. Tidak ada lima stylesheet, CSS-in-JS, atau library slider.
- Token level aktif hanya untuk media screen; print memakai baseline stabil. Perubahan screen density tidak boleh bocor ke receipt print, PDF, XLSX/CSV, atau layout export.
- Parameterisasi deklarasi `.table td`/`thead th` existing yang important. Batasi density-aware table ke tabel operasional dalam content area atau modal opt-in; fallback tetap10/14px. Pertahankan kebutuhan important compatibility di satu deklarasi pemilik; jangan menumpuk important density per level. Override editor existing tetap didokumentasikan/diperiksa.
- Untuk komponen shared, pasang class semantik body/header/footer lalu **hapus utility spacing yang digantikannya pada node yang sama**. Hindari pertarungan `.ui-*` vs utility padding/margin, termasuk responsive variants. Pertahankan grid/width/selector JS.
- `.ui-data-table` mengadopsi row/cell/header/footer/control-spacing token, termasuk tabel tanpa class Bootstrap. QC legacy `.table` mendapat adapter scoped tanpa harus diubah menjadi DataTables component baru.
- `.ui-toolbar` men-token-kan padding dan gap; DataTables wrapper/control adapter terbatas pada container tabel operasional, bukan global `.form-control`/`.row`/`.btn`.
- `card`, `metric-card`, `form-section`, `page-header`, `dashboard-layout` menangani reuse utama. Class opt-in `ui-density-stack`, `ui-density-form-grid`, `ui-density-list`, `ui-density-modal` menangani owner legacy yang benar-benar dipilih.
- Jangan menjadikan typography, ukuran button/badge/icon/input, column widths, sticky offsets, atau calendar hitboxes sebagai variabel density. Pertahankan minimum existing; floor aksesibilitas scoped boleh memperbesar target yang kurang. Hentikan pengecilan switch berdasarkan density; casing/hitbox switch tetap stabil.

## 7. Slider: interaction dan aksesibilitas

Gunakan `<input type="range" min="1" max="5" step="1">`, horizontal, dengan nilai awal2 untuk default. Tidak membuat role slider custom atau mengintersepsi Arrow/Home/End. Native Right/Up menaikkan posisi menuju Dense; Left/Down menuju Spacious; Home=1, End=5. Tab satu stop; pointer dapat memilih track tanpa harus drag.

- Label visible Interface Density/Kepadatan Antarmuka dikaitkan memakai label/aria-labelledby. Endpoint helper kiri “More spacious/Lebih lapang”, kanan “Denser/Lebih padat”. Lima tick dekoratif dengan nama yang dapat dibaca; tidak menjadi lima tombol baru.
- Active output menampilkan tepat label level dan penanda “Pratinjau belum disimpan” bila density berbeda dari saved. `aria-valuetext` misalnya `Comfortable, 2 dari 5`; angka native bukan nama bisnis. Jangan membuat live region setiap frame untuk label yang sudah diumumkan slider; live region status dipakai untuk dirty/reset/error agar tidak berulang.
- Range dan select fallback masing-masing mempunyai `aria-describedby` ke helper arah, penjelasan preview, dan error density bila ada; pasang `aria-invalid="true"` saat error server. Error visible tidak cukup hanya ditautkan ke fieldset. Save retry tetap tersedia bila server error walaupun draft kembali sama dengan saved.
- Hit area slider minimal44px tinggi di semua density; thumb visual minimal24px, area fokus jelas dengan outline token2px + offset2px. Track/thumb/focus punya kontras nonteks3:1; teks normal4.5:1, kedua theme dan semua accent existing.
- Native `disabled` saat form benar-benar sedang dikirim, bukan saat dirty=0. Tetap dapat mengubah setting yang sama. Setelah invalid/error pulihkan kemampuan interaksi dan focus ke slider/error terkait. Loading hanya saat Save; tidak ada spinner saat drag.
- Mobile: satu kolom, panjang range mengikuti lebar tersedia, helper membungkus tanpa memotong; nama level tetap tersedia melalui active output. Tidak mengganti control dengan framework lain.
- No-JS: satu select canonical `name="density"` tampil sebagai fallback dengan lima option string. Range awalnya hidden/disabled dan tanpa name. Setelah enhancement berhasil, select disembunyikan secara semantik/visual tetapi tetap enabled untuk submission, range tampil/enabled. Tidak mengirim numeric position dan string sekaligus.

Mapping menggunakan urutan `config('user_preferences.densities')` yang dikirim sebagai `densityKeys` ke bootstrap/form. Nilai DOM select canonical adalah **satu sumber draft**, posisi range/label/root adalah proyeksinya. Tidak menyimpan parallel Alpine `selectedDensity`, object mini-preview state, ataupun localStorage. Bounds/NaN/fraction ditolak pada adapter, dan server tetap memvalidasi string allowlist.

Rujukan primer yang diverifikasi untuk spesifikasi: [W3C APG Slider](https://www.w3.org/WAI/ARIA/apg/patterns/slider/) untuk keyboard/valuetext; [WCAG 2.2 Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) untuk minimum24px (44px di sini adalah kebijakan produk yang lebih besar); [WCAG Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html). Uji assistive technology touch tetap wajib; native control saja bukan bukti lulus.

## 8. Live application, persistence, dirty state, dan restoration

### Satu alur efektif

```text
input range
  → map stop ke canonical string
  → set satu field form density
  → AdasiPreferences.previewDensity(string), divalidasi registry
  → html[data-density]
  → CSS sama untuk aplikasi + mini preview
  → derive range/output/aria-valuetext + recompute(saved vs form)
```

Snapshot `AdasiPreferences.density` dan `customizationForm.saved.density` tetap nilai tersimpan dari request yang sama; preview tidak mengubah keduanya. Closure bootstrap tetap menjadi satu lapisan aplikasi preview. Tidak menulis properti saved dari root dataset.

**Owner event:** Alpine customizationForm. Hilangkan binding appearance ganda di `preferences.js` pada form ini; fungsi reorder/dashboard tetap ada. Gunakan satu delegated input handler untuk range dan change handler untuk field lain; jangan recompute dua kali pada input+change range. Theme/accent memakai owner yang sama. Reorder keyboard/drop mengirim satu event form setelah perubahan order; recompute tidak melakukan re-render seluruh halaman.

**Dirty/navigation:** hitung draft terhadap `saved` pada setiap perubahan relevan, termasuk locale/page size/sidebar/shortcut/dashboard/region. Gunakan `data-track-unsaved="false"` pada form ini untuk menghindari baseline snapshot generic yang bersaing dengan saved, lalu proyeksikan dirtyCount melalui API `AdasiUnsaved.markDirty()/markClean()` existing. Jangan menulis ulang tracker global. Guard beforeunload form membaca izin leave dari `AdasiUnsaved.isDirty()` dan status submitting agar konfirmasi custom yang sudah disetujui tidak diikuti dialog native kedua. Pastikan init API/Alpine terurut dan recompute setelah init; tidak bergantung timeout snapshot generic.

| Skenario | Kontrak Gate 2 |
|---|---|
| Drag/input/keyboard | Satu perubahan root + output + dirty per effective stop; nol HTTP/write density; tidak mengubah theme/accent yang sedang dipreview. |
| Kembali ke nilai saved | Density tidak lagi menyumbang dirty; pending field lain tetap dihitung; Save hanya disabled bila seluruh form bersih dan tidak ada error server. |
| Save Changes | PATCH existing, sekali, canonical string. Bekukan edit slider selama submission agar nilai visual tidak tertinggal dari payload. Success redirect GET baru menjadi saved baseline; tidak ada optimistic local persistence. |
| Reset section appearance / Reset all UI | Memuat default Comfortable sebagai draft; range/output/root sinkron; tetap butuh Save. Reset all mengikuti perilaku field existing; tidak diam-diam mengganti kebijakan reset locale unrelated. |
| Batalkan/Discard | Restore semua field sesuai saved, appearance root, order, visibility; sync range/label dan recompute=0. Bridge navigasi kembali clean. |
| DELETE reset existing | Tetap reset server langsung melalui jalur existing; jangan dipanggil saat drag/Reset draft. Pertahankan scope dashboard dan notification overrides. |
| Validasi gagal | DB tidak berubah. Canonical old density yang valid dipakai sebagai initial preview; saved baseline tetap persisted. Sediakan optional initial density metadata dari controller edit untuk head bootstrap sebelum CSS. Nilai old invalid kembali ke saved untuk slider, error tetap terlihat dan Save retry tersedia. |
| Initial paint | Density root dipasang head sebelum styles, termasuk valid old draft setelah error. Form range, canonical field, active label, dan root sama sejak enhancement. Tidak menampilkan level default2 lalu meloncat ke saved. |
| Error jaringan/submit dibatalkan | Jangan mark saved. Pulihkan disabled/submitting pada `invalid`, recovery/settled jika terjadi, serta pageshow. Reset global submitting flag via API existing lalu recompute. Tidak menambah AJAX save. |
| Navigasi internal/hash/new tab | Konfirmasi existing untuk meninggalkan halaman dirty; Stay mempertahankan draft. Anchor section tanpa unload tidak membuang draft. `_blank` tidak membuang tab asal. Setelah Leave, tujuan memakai saved dari server. |
| Back/forward bfcache | Pertahankan kebijakan existing: restore **appearance saved snapshot halaman itu**, termasuk theme/accent/density; reset range/select/label dan recompute. Field nonappearance yang dipulihkan browser boleh tetap draft dan tetap dihitung. Satu owner restore di preferences.js memancarkan event ke form, bukan dua pemanggilan restore. |
| Refresh/unload | Jika dilanjutkan, draft density hilang; GET memakai saved. Native close/refresh guard best effort browser. Tidak ada jaminan prompt unload pada semua perangkat. |
| Banyak tab | Draft hanya tab itu. Tidak ada storage/BroadcastChannel/polling baru. Tab lain memakai snapshot sampai GET baru; bfcache dapat masih snapshot lama. Save tetap last successful save seperti sekarang; revision existing bukan optimistic concurrency token. Reload untuk melihat perubahan tab lain. |
| Ganti theme/accent saat preview density | Masing-masing preview axis independen; tidak memanggil restoreSaved pada perubahan axis lain. Reset/Discard saja mengembalikan semua appearance. |

Handler restore tidak boleh memicu Save, mengubah localStorage sidebar, atau memanggil loader/splash reset. Existing `submit-guard.js` dan `unsaved-changes.js` hanya diinspeksi dan diuji regresi; perubahan global keduanya tidak direncanakan. Bila kontrak tidak dapat dipenuhi lewat API existing, laporkan bukti sebelum memperluas surface.

## 9. Mini preview dan stabilitas ruang

Tempatkan di fieldset density, setelah range/output/helper. Gunakan markup Blade ringan tanpa data bisnis: satu metric “Items/Item: 24”, toolbar dekoratif “Search/Filter”, tiga baris tabel contoh “Item A/B/C” dan status netral. Tidak menginisialisasi DataTables, chart, polling, export, atau komponen input interaktif. Label contoh berpasangan en/id dan bukan nomor invoice/nama vendor nyata.

Pakai class body/header/cell yang sama dengan komponen produksi. Metric dapat memakai `x-ui.metric-card` tanpa href; toolbar/cell contoh memakai class semantik yang sama tanpa form/search/button sungguhan. Jangan memakai `x-ui.data-table` penuh bila slot/state wrapper itu hanya menambah markup tidak relevan. Tidak menduplikasi nilai padding ke selector preview-only.

Preview noninteraktif: tidak ada tab stop, link, input, button, atau role slider kedua. Deskripsi visible menjelaskan “Contoh tata letak; perubahan belum disimpan sampai Simpan Perubahan.” Bagian dekoratif boleh `aria-hidden="true"`; satu penjelasan teks di luar area itu menjelaskan perubahan spacing. Jangan menambahkan keyboard scroll region hanya untuk dekorasi.

Stabilitas memerlukan **semua ancestor sebelum slider** ikut diperhatikan: pada Customization, gutter, outer gap/page heading, appearance header, area kontrol, dan action-bar menjadi exception spacing tetap. Section/body yang dipilih setelah area kontrol, dashboard settings rows, panel nonkontrol, dan sidebar boleh merespons. Dengan demikian halaman nyata berubah tanpa mendorong range yang sedang dioperasikan.

Frame preview mereservasi tinggi konten Spacious terpanjang pada breakpoint dan locale tersebut; nilai reservasi ditentukan dari tahap 0 browser, bukan angka px rekaan. Preview align-start di dalam frame, tidak di-scale. Saat viewport/zoom berubah frame boleh tumbuh/reflow; perubahan density pada viewport sama tidak mengubah ukuran frame. Tidak memakai overflow hidden/max-height untuk menyembunyikan baris/teks. Action-bar sticky memakai padding/ukuran control tetap dan ruang status yang cukup untuk pesan kedua bahasa.

## 10. Cakupan operasional dan exception

### 10.1 Bukti route dan coverage per audience

VERIFIED-EXECUTION: `php artisan route:list --json` berhasil; route/middleware di bawah terdaftar. VERIFIED-SOURCE: controller→view/include dan markup representatif ditelusuri. **Belum ada GET login lintas role atau browser rendered; ketersediaan route bukan bukti record/akun siap.**

| Audience / route nyata | Sampel dashboard/list/form/detail/modal dan owner | Respons saat ini → rencana | Constraint dan bukti wajib |
|---|---|---|---|
| Admin (`role:admin`) | `admin.dashboard`, `admin.users.index/create/edit`; views admin/dashboard, users/*; modal `kursModal` | Tabel/toolbar/sidebar parsial; dashboard metric/panel/list tetap → shared dashboard/card/table/form/page tokens; list aktivitas hanya adapter bila dipilih | Create/edit memerlukan password.confirm; password/security field dan aksi account tidak diperkecil. Ukur dashboard, users row actions, section form, kurs modal; jangan submit operasi bisnis. |
| Purchasing (`role:purchasing`, navigation) | `purchasing.dashboard`, `purchasing.requisitions.index/create/show`; views purchasing/pr/*; supplier picker modal | Compact prop/tabel penting menahan perubahan → shared + token toolbar/form; picker opt-in | PR matrix input/sticky300px minimum tetap exception; field/row data sama; ukur list dan form shell terpisah dari matrix. |
| Finance (`role:finance,admin`) | `finance.dashboard`, `finance.invoices.index/show`; local-procurement register + PO/GR import modal | Shared invoice table/filter + kartu tetap → shared tokens, verification spacing adapter, operational import body | Approve/reject/settlement/physical receipt dan error/reason tetap ukurannya. Tidak mengirim import/approval saat audit density. |
| Accounting legacy (`role:accounting,finance,admin`) | `accounting.dashboard`, `accounting.invoices.index/show`; shared local-invoices filters/table/detail | Shared shell + invoice family → adopsi sekali pada owner bersama | Jangan menciptakan create form/Finance action yang tidak dimiliki role. Form yang benar-benar tersedia di audience ini: filter GET/Customization. Ukur detail/read-only dan action availability tetap. |
| QC (`role:qc`; show juga Purchasing) | `qc.dashboard`, `qc.inspections.index/create/show`; two DataTables waiting/history | Table Bootstrap di luar ui-data-table masih padding tetap → adapter tabel operasional, toolbar shared, form-section/card | OK/NG, evidence upload, dimension cells/sticky/focus tetap. Ukur kedua tab, filter, create/show; tidak mengubah hasil QC. |
| GA (`role:ga,admin`) | `ga.dashboard`, `ga.claims.index/create/show`, `ga.employees.index`; add/edit employee modal | Card/table ada; raw form/list/modal spacing tetap → shared + adapter form/list/modal body | Bank/employee/claim submit/basic verify tetap. GA tidak mendapat draft DRP baru; form modal bukan aksi yang disubmit saat pengukuran. |
| Supplier Import (`role:supplier`, scope:import) | `supplier.dashboard`, `supplier.quotations.index/create/show`; quotation import modal | Shared + fixed quotation editor → shared tokens di shell/context, editor exception | Matriks offered/requested paired rows, sticky offsets, input/error tetap; modal quotation import boleh tetap ukuran current pada increment pertama. Ownership/scope tidak berubah. |
| Supplier Local (`role:supplier`, scope:local) | `local-supplier.dashboard`, `local-supplier.invoices.index/create/show`; shared local-invoices family | Table/filter partial, wizard/card spacing tetap → shared tokens + form/detail adapters | Wizard, wajib dokumen, whole-GR, upload/error, submit controls tetap; audit hanya spacing. Dual-scope context switch perlu regresi. |

Form/detail record-specific harus memakai fixture yang memang tersedia pada lingkungan uji; raw integer URL tidak boleh dipakai untuk melewati Hashids. Tidak semua role memerlukan modal baru. Modal representatif dipilih dari yang sudah ada; tidak menciptakan modal hanya untuk memenuhi matriks.

### 10.2 Prioritas keluarga komponen

| Prioritas | Keluarga / pemilik | Adopsi dan exception |
|---|---|---|
| P0 | `app.css`, layout bootstrap, Customization | Registry+tokens, slider, draft/saved/restore, no save on input; kontrol stabil |
| P1 | `data-table`, `toolbar`, CSS `.table` dan DataTables adapter | Cell/panel/control container spacing; font/button/minimum tidak berubah, no redraw/network |
| P1 | `card`, `metric-card`, `dashboard-layout`, `page-header`, `form-section` | Padding/gap/section separation untuk semua audience dari komponen yang sama |
| P2 | Shared invoice form/detail, GA raw form/list, Finance verification | Marker semantik hanya container yang masih fixed dan berpengaruh; tidak mengganti workflow/markup data |
| P2 | Sidebar links dan operational modal existing | CSS sidebar height+padding; modal opt-in body/list/form spacing; header/footer/close critical tetap |

**Protected/exceptions:** auth/MFA/password/security controls; splash dan loader termasuk timings/readiness; Advanced Export Column Designer beserta layout/logic; Save/Cancel/approval/rejection/destructive controls; validation/error/help; date-picker hitboxes; chart canvas height/aspect; PR/quotation/QC editor geometry; printed receipts/PDF/export output. Tidak menyusutkan font, outline atau switch untuk mengejar densitas. Panjang konten dan target aman boleh mengalahkan row-min. Exceptions dicatat sebagai exception, bukan disembunyikan sebagai PASS target penghematan.

## 11. Kompatibilitas, storage, dan keputusan arsitektur

### Migration-free

VERIFIED-SOURCE: migrasi `2026_09_28_000001_create_user_preferences_table.php:15` membuat string20, bukan enum; model tidak melakukan enum cast. VERIFIED-EXECUTION: metadata lokal sesuai. Nilai terpanjang `comfortable` adalah11 karakter; `spacious`/`balanced`8, `compact`7, `dense`5. **Tidak diperlukan migrasi maupun rewrite preference**. Staging/produksi perlu inspeksi metadata read-only saat release jika ada drift, bukan DDL otomatis.

Service read dapat menormalisasi density unknown menjadi Comfortable tanpa menulis DB; config menjadi allowlist tunggal. Request menambahkan rule string eksplisit dan mempertahankan Rule::in(config). Frontend menolak preview nilai di luar registry. Existing account Comfortable/Compact tidak berubah nilai; Compact mendapat efek presentasi yang selama ini diblokir CSS, sehingga bukan janji pixel-identik untuk Compact.

Last successful save antartab tetap kebijakan existing. Jangan menambah optimistic-lock protocol, schema, user setting query, endpoint preview, atau cache preference baru.

### ADR-01 — pilih C

| Alternatif | Coupling/cascade | Reuse/performance/maintenance | Keputusan |
|---|---|---|---|
| A. Banyak selector manual per page/level | Bertambah terhadap utility, important, dan inline editor; sulit memastikan precedence | Duplikasi tinggi pada282 view; mudah luput antarrole; selector churn | Ditolak |
| B. Token shared murni tanpa exception/adapters | Baik untuk shared components, gagal menjangkau raw QC/GA/form lama dan dapat mengganggu protected surfaces | Biaya kecil tetapi coverage tidak koheren atau pemaksaan global | Ditolak sebagai solusi lengkap |
| C. Token shared + adapter/exception terbatas | Perbaiki owner important, lepaskan utility konflik hanya node terkait, opt-in legacy | Reuse tinggi, satu attribute write tanpa redraw; surface terbatas dan dapat direview | **Dipilih** |

Risiko: shared component blast radius, konten panjang/aksi menahan row, print leak, dirty lifecycle, layout shift di sekitar range. Mitigasi: stage0 measurement, semantic adoption, screen scope, fixture exceptions, satu event owner, snapshot restore tests.

Pilihan batal/harus dievaluasi ulang bila: adopsi memerlukan bulk replacement sebagian besar view; banyak editor membutuhkan exception berlainan; nyata ada fixed-density component contract di luar tujuh caller; control minimum membuat lima level tak dapat dibedakan; kinerja input melampaui budget setelah duplicate work dihilangkan; atau saved/preview tidak dapat dipisah tanpa persistence baru. Jangan memaksakan target dengan shrink font atau perubahan workflow.

## 12. Peta file Gate 2

Daftar berikut adalah **expected bounded surface**, bukan izin implementasi sekarang. Perubahan di dalam file besar hanya blok density/spacing terkait; fitur lain tetap utuh.

| Kelompok | File exact | Perubahan yang direncanakan |
|---|---|---|
| Registry/read/bootstrap | `config/user_preferences.php`; `app/Services/UserPreferenceService.php`; `app/Http/Requests/UpdateUserPreferenceRequest.php`; `app/Http/Controllers/UserPreferenceController.php`; `resources/views/layouts/app.blade.php` | Lima string/order; fallback read; payload densityKeys; initial valid-old preview metadata; guard preview canonical. Controller hanya edit view metadata, update/reset tetap jalur existing. |
| UX dan state | `resources/views/profile/customization.blade.php`; `resources/js/customization-form.js`; `resources/js/preferences.js`; `lang/en/customization.php`; `lang/id/customization.php` | Native slider+select fallback+mini; satu handler owner; dirty/navigation bridge; restore; copy berpasangan. Dashboard reorder event hanya untuk recompute. |
| Token/adapters | `resources/css/app.css` | Lima token maps, komponen/token adapters, important owner, sidebar, modal opt-in, screen/mobile/protected rules; tidak menyentuh blok splash/designer. |
| Shared ownership | `resources/views/components/ui/data-table.blade.php`; `card.blade.php`; `metric-card.blade.php`; `toolbar.blade.php`; `form-section.blade.php`; `dashboard-layout.blade.php`; `page-header.blade.php` (semuanya di direktori `components/ui`) | Class semantik pada node spacing, hapus utility yang digantikan, auto density table. Tidak mengubah props/slot/data/selectors lain. |
| Legacy adapters terpilih | `resources/views/local-invoices/form.blade.php`; `resources/views/local-invoices/detail.blade.php`; `resources/views/finance/invoices/show.blade.php`; `resources/views/ga/claims/create.blade.php`; `resources/views/ga/claims/show.blade.php`; `resources/views/admin/dashboard.blade.php` | Form grid/stack/list marker; pertahankan baseline variant16/20/24 dan semua input/action/data. Admin hanya list aktivitas/pintasan jika belum tercakup shared. |
| Modal opt-in | `resources/views/ga/employees/index.blade.php`; `resources/views/purchasing/pr/_supplier_picker_modal.blade.php` | Tambah marker modal/body/list, tanpa perubahan handler/CRUD. PO/GR import sudah punya `.local-import-modal`; adopsi CSS existing owner tanpa perubahan JS import. |
| Tes existing | `tests/Feature/UserCustomizationTest.php`; `UserDashboardCustomizationTest.php`; `SidebarShellTest.php`; `RenderedComponentTest.php`; `DataTablesPreferenceTest.php` (di `tests/Feature`); `tests/js/preferences.test.mjs`; `customization-form.test.mjs`; `dashboard-customization.test.mjs`; `unsaved-changes.test.mjs` (di `tests/js`) | Extend behaviour coverage, payload contract, canonical mapping, lifecycle, handler cardinality; jangan mengganti test meaningful dengan regex yang selalu lulus. |

Read-only/no planned edit: `UserPreference.php`, `app.js` (registrasi Alpine sudah ada), middleware/provider/routes, semua migrasi, `tailwind.config.js`, `package*.json`, seluruh domain service/controller lain. Existing scanner sudah mencakup Blade/JS; class semantik defined langsung di CSS tidak memerlukan safelist baru. Bila class dinamis/server-rendered baru benar-benar diperlukan, evaluasi tersendiri; tidak direncanakan.

Tidak ada rencana edit tujuh page caller prop compact, delapan dashboard satu per satu, seluruh table page, auth splash, Advanced Export, submit guard/global unsaved tracker, maupun editor PR/quotation styles. Perluasan daftar harus mempunyai bukti gap setelah shared adoption; bukan pembersihan oportunistik.

## 13. Tahap Gate 2 menurut dependensi

### Tahap 0 — rekam baseline dan bekukan angka

- **Tujuan:** mengubah kandidat source menjadi token final berbasis rendered evidence.
- **File:** belum mengubah runtime; buat bukti `UI-REDESIGN-RESULT/INTERFACE-DENSITY-GATE2-BASELINE.md` serta CSV/screenshot pendamping di folder hasil UI yang sama. Baca semua file pada peta tahap terkait.
- **Dependensi:** persetujuan Gate2; browser tersedia atau user menjalankan pengukuran manual; akun/fixture terisolasi siap. Jangan membuat/mengaktifkan akun atau mengubah app DB untuk membuka halaman.
- **Tes:** ukur lima keluarga baseline Comfortable dan Compact existing di viewport yang sama; computed padding/row/button/line-height, style winner, scrollHeight, wrappers; inspeksi built CSS berasal dari source yang diuji. Lindungi data sensitif pada artifact.
- **Acceptance:** baseline route/role/fixture/locale/theme/viewport/font readiness dicatat; semua ukuran load-bearing mempunyai bukti. Bila browser masih tidak tersedia, tahap0 visual **BLOCKED** dan angka tetap provisional; source-only checks boleh lanjut tetapi jangan menyatakan token/UX final.
- **Rollback:** hanya artifact; tidak ada runtime rollback.

### Tahap 1 — kontrak canonical dan state/persistence

- **Tujuan:** kelima string diterima, saved/preview terpisah, init valid-old benar, tidak menambah query/persistence.
- **File:** lima file kelompok Registry/read/bootstrap; `tests/Feature/UserCustomizationTest.php`, `UserDashboardCustomizationTest.php`; `tests/js/preferences.test.mjs`.
- **Dependensi:** tahap0 konfigurasi/baseline serta isolated test database terverifikasi.
- **Tes:** tulis test gagal dulu untuk5 values/invalid/read fallback/payload/default/querycount; implementasi minimal; PHP lint/Pint scoped, JS bootstrap tests.
- **Acceptance:** legacy values/default valid, all five canonical persisted hanya via Save, invalid numeric/string/array ditolak; request preference tetap1 query; frontend payload/first-paint benar.
- **Rollback:** bundle/server config+bootstrap bersama. Jika nilai baru sudah disimpan, **jangan** kembali ke allowlist2 saja; pertahankan five-value read/validation shim dan render fallback Comfortable sementara, tanpa data rewrite.

### Tahap 2 — token dan shared adoption

- **Tujuan:** lima level menghasilkan perubahan operasional melalui owner CSS yang benar.
- **File:** `resources/css/app.css`; tujuh shared Blade pada peta; `tests/Feature/RenderedComponentTest.php`, `SidebarShellTest.php`, `DataTablesPreferenceTest.php`.
- **Dependensi:** tahap0 angka/floor; tahap1 registry/bootstrap.
- **Tes:** scoped render checks, compile Blade, Vite build, computed-style/geometry per level pada text/action table, card, toolbar, form, sidebar. Tes browser adalah bukti specificity; inspect source saja tidak cukup.
- **Acceptance:** monotonic, baseline Comfortable cocok, important blocker teratasi, safe minima/font/focus tetap, tidak ada network/DataTables redraw saat density berganti, no print/designer/splash leakage.
- **Rollback:** lepaskan adapter/class semantik sebagai satu unit dengan CSS, pertahankan runtime menerima kelima string. Jangan menghapus utility yang belum punya pengganti dalam bundle aktif.

### Tahap 3 — slider, mini, dan lifecycle Customization

- **Tujuan:** native range, mini preview, dirty, save/reset/discard/navigation konsisten.
- **File:** lima file UX/state pada peta; `tests/js/customization-form.test.mjs`, `preferences.test.mjs`, `dashboard-customization.test.mjs`, `unsaved-changes.test.mjs`; `tests/Feature/UserCustomizationTest.php`; optional fixture view test di `RenderedComponentTest.php`.
- **Dependensi:** tahap1+2; baseline frame Spacious/dua bahasa diketahui.
- **Tes:** native keys/pointer/touch+AT; mapping; one handler/root write per stop; returning saved; all reset paths; errors; theme/accent independence; bfcache; two tabs; guard once; no-JS submission; 28-test baseline tetap ditambah coverage lifecycle nyata (bukan helper-only).
- **Acceptance:** mini dan current page memakai root+tokens sama; range/action controls tidak bergeser lebih1px akibat density pada viewport tetap; nol request/write preview; posisi numeric tidak pernah canonical payload; no duplicate listener.
- **Rollback:** pulihkan UI select canonical5 pilihan sementara; tidak menyembunyikan nilai baru dengan radio2 pilihan. Pertahankan saved/preview isolation dan backend five-value compatibility.

### Tahap 4 — adopsi legacy bounded dan lintas audience

- **Tujuan:** melengkapi form/list/detail/modal yang belum tercakup shared.
- **File:** delapan file Legacy adapters+Modal opt-in pada peta; CSS adapters yang sama. Tidak ada edit terpisah pada setiap list/dashboard.
- **Dependensi:** tahap2+3; ukur gap tersisa sesudah shared adoption.
- **Tes:** matriks audience §10, minimum kontrol approval/security, filters/wrapping/long content/validation, modal focus/close/scroll, supplier dual-scope. Static PHP suites dipilih sesuai file yang disentuh dan isolated DB; jangan submit workflow finansial pada app DB untuk tes density.
- **Acceptance:** seluruh keluarga mempunyai respons yang didokumentasikan; semua exception terlihat di matriks. Jika shared adoption sudah cukup untuk suatu file, omit edit file tersebut dengan evidence, jangan menambah pekerjaan.
- **Rollback:** marker/class legacy dapat dilepas per kelompok bersama CSS lokal; shared tokens/contract tetap bekerja.

### Tahap 5 — acceptance independen dan handoff

- **Tujuan:** memisahkan PASS/FAIL/BLOCKED/NOT TESTED dan membuktikan outcome.
- **File:** test files pada peta sesuai perubahan; hasil final `UI-REDESIGN-RESULT/INTERFACE-DENSITY-GATE2-VERIFICATION.md`; tidak ada runtime tambahan kecuali perbaikan defect terbukti dalam scope.
- **Dependensi:** tahap1–4 dan baseline tetap.
- **Tes:** seluruh matriks §14, targeted PHP/JS, lint/Pint, view cache, Vite build, translation parity, diff check; manual/browser+AT/participant outcomes terpisah.
- **Acceptance:** seluruh mandatory evidence lulus; exception yang disetujui tidak dihitung sebagai keberhasilan threshold lain. Jangan menaikkan threshold toleransi untuk membuat hasil hijau. Tidak menyatakan Gate2/release selesai ketika browser/AT wajib belum dilakukan.
- **Rollback:** gunakan aturan compatibility shim di atas; jangan rollback DB karena tidak ada migrasi. Commit/push/deploy tetap memerlukan instruksi terpisah.

## 14. Matriks verifikasi dan ambang observable

### Protokol pengukuran

Desktop **1440×900** dan **1280×800**; tablet **768×1024**; mobile **390×844** dan reflow **320 CSS px**. Desktop ukur sidebar expanded dan collapsed tanpa mengubah preferensi lain. Browser/build/font/device pixel ratio/zoom/locale/theme harus sama dalam paired comparison; tunggu font dan DataTables draw selesai, lalu tahan data/filter/sort/pageLength25 tetap. Gunakan fixture25 row identik (teks, aksi, wrapping panjang), fixture form dengan beberapa section, dashboard8 audience, serta modal representatif.

Simpan `viewport`, `route`, `role/scope`, `density`, `theme`, `locale`, fixture hash/ID sintetis, computed cell padding, row bbox median/range, table/panel/card/dashboard rect, scrollHeight/clientHeight, form gap, toolbar line count/height, range/action bbox. Tabel body480px untuk perbandingan row hanyalah measurement window; tidak mengubah scroll container/page size produksi. Screenshot harus mempunyai data yang sama; jangan menampilkan data supplier nyata dalam artifact.

| Area | PASS observable | FAIL / pembatasan | Evidence Gate1 |
|---|---|---|---|
| Lima level | Urutan canonical persis; setiap adjacent text-row fixture berbeda ≥2px dan tidak membesar menuju Dense; return ke level sebelumnya rect kembali ±1px | Angka berubah tetapi layout identik di fixture yang seharusnya sensitif; reverse progression | Source only; rendered UNVERIFIED |
| Rows/table height | Body480px rows penuh nondecreasing; Dense vs Comfortable total25 action-row fixture berkurang ≥15%; data/pageLength/record IDs tetap | Memperbanyak hasil query/page size untuk lolos; control clipping | Prediksi §6.3, bukan measurement |
| Card/panel/form | Padding/gap computed sesuai token final ±1px; actual height nonincreasing pada konten yang sama; setidaknya metric, panel, form section memberi perubahan visible | Total height tidak turun karena min-height konten boleh exception, harus dilaporkan; jangan label semua page PASS | Baseline CSS tersedia |
| Dashboard/scroll | Shared gap/padding terukur semua8 audience; Dense total operational content height/scroll distance tidak bertambah pada width tetap; chart area invariant | Jangan memaksakan penghematan chart; wrap regressions harus ditangani sebelum PASS | Route/source verified; pixels UNVERIFIED |
| Toolbar | Tidak ada target/label hilang; height nonincreasing desktop fixture; wrapping count tidak bertambah akibat density saja; mobile boleh wrap aman | Form/filters keluar viewport, overlap atau label terpotong | UNVERIFIED |
| Stabilitas | Range/active output dan Save/Cancel bbox posisi/ukuran ±1px antarlevel pada viewport/scroll tetap; frame preview tetap | Slider berpindah saat drag, status menyebabkan action bar lompat | UNVERIFIED; reservasi perlu stage0 |
| Input performance | Output dan root ter-update tanpa menunggu change akhir; input-to-paint p95≤100ms pada lingkungan baseline, tidak ada task baru>50ms yang disebabkan handler density | Tidak ada baseline latency Gate1; budget ini target usability, bukan klaim benchmark | UNVERIFIED |
| HTTP/persistence | Network density input0 request, storage write density0; Save1 PATCH, server read-only GET0 write; preview tidak redraw/refetch tabel | Polling existing dicatat terpisah dari request yang disebabkan density | JS bootstrap test terbatas PASS |
| Query/semantics | Query user_preferences1/request seperti existing test; no extra density query; filter/sort/length/auth/workflow/export byte/data contract unchanged | CSS-only inferensi bukan query/runtime proof | Source test inspected, PHP not run |
| Keyboard | Native arrows stop±1, Home1/End5, Tab urut; range tetap focused; tidak keyboard trap | Custom key handler overriding native tanpa requirement terbukti | UNVERIFIED |
| Screen reader/touch | Nama/role/value label level diumumkan benar pada NVDA+desktop browser serta VoiceOver/iOS atau TalkBack/Android; track tap/adjust gesture bekerja | Pengujian keyboard tidak menggantikan AT touch | UNVERIFIED |
| Target/focus/contrast | Normal targets≥24×24px, slider/essential/mobile≥44px dan minimum existing terjaga; focus2px+2px offset visible; text≥4.5:1, UI≥3:1 | Tidak mengecilkan target/destructive controls atau menghilangkan outline | Existing color tests PASS, new control UNVERIFIED |
| Zoom/reflow | 200% text dan400% zoom/reflow320px: semua text/control/error tersedia; hanya tabel data intrinsik boleh scroll horizontal di region | Tidak memotong mini/critical actions; sticky bar tidak menutup focus | UNVERIFIED |
| Persistence lifecycle | Saved/draft/old/Reset/Discard/refresh/bfcache/two-tabs sesuai §8; returning saved bersih; theme/accent independen | Cache browser diperlakukan sebagai DB terbaru; invalid payload disimpan | Existing JS subset PASS; new lifecycle NOT IMPLEMENTED |
| Comprehension pengguna | Minimal4 dari5 operator representatif menjelaskan arah slider dan status belum tersimpan dengan benar tanpa arahan; dapat memilih/mengembalikan level dan menyebut perbedaannya pada pasangan adjacent | Ambang pilot usability praktis, bukan klaim statistik; level tidak diubah/diwawancara ulang | UNVERIFIED / participant test Gate2 |
| Protected regression | Designer/splash/control geometry/timing tidak berubah; print layout dan export output sama untuk input yang sama | PASS density tidak menghapus kegagalan protected regression | Inspected only |

### Kasus PHP/JS/CSS/browser yang diperlukan

- PHP: akun tanpa preference tidak membuat row saat GET; default Comfortable; stored Comfortable/Compact; save tiap5 nilai ke current user saja; invalid `tiny`, numeric1/2/5, null/array/unknown ditolak; persisted unknown read fallback tanpa write; Save/Reset/default/scope dashboard; notification/locale/security fields tidak rusak; query preference1; payload initial saved vs valid-old; en/id parity.
- JS: test factory `customizationForm` dengan DOM fixture yang benar-benar menjalankan method/listener, bukan hanya sameSet/order. Input1..5, boundary/invalid, select canonical, one preview invocation, dirty+return, unrelated field changes, keyboard reorder/drop, reset/discard, failed validation/invalid event, bfcache sync dan submitting recovery, multi-axis preview, no storage/network side effects, bind idempotent/destroy bila lifecycle ulang.
- CSS: compiled style dan `getComputedStyle` pada fixture nyata membuktikan important/utility precedence, mobile/coarse minimum, safe row growth, responsive variants, screen vs print. Source assertions boleh menjaga registry/token scope tetapi tidak menjadi bukti row-height.
- Browser: semua viewport/theme/locale; semua5 level pada fixture dan cross-role sample; light/dark, seluruh accent existing untuk slider focus/contrast; no-JS fallback; back/forward/refresh; two tabs; pengukuran no HTTP dengan polling existing dipisahkan; AT dan participant pilot.

### Database test safety dan perintah Gate2

Sebelum feature tests, periksa `phpunit.xml`, active testing config/cached config/DB_URL, guard AppServiceProvider, dan koneksi live harus database `_test`/`_testing` yang didedikasikan. Jalankan `TestingEnvironmentDatabaseSafetyTest` dahulu; jangan paralel dengan suite lain pada DB sama. Jangan menjalankan migration roundtrip test sebagai pengukuran UI Gate1. PHP feature suites yang menulis menggunakan isolated test environment terverifikasi saja.

Perintah Gate2 setelah approval (belum dijalankan kecuali JS baseline §15):

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=UserCustomizationTest
php artisan test --filter=UserDashboardCustomizationTest
php artisan test --filter=SidebarShellTest
php artisan test --filter=RenderedComponentTest
php artisan test --filter=DataTablesPreferenceTest
php artisan test --filter=TranslationParityTest
php artisan test --filter=FrontendAssetLoadingTest
node --test --experimental-test-isolation=none tests/js/preferences.test.mjs tests/js/customization-form.test.mjs tests/js/dashboard-customization.test.mjs tests/js/unsaved-changes.test.mjs
php artisan view:cache
npm.cmd run build
git diff --check
```

Tambahkan `php -l` dan Pint `--test` hanya PHP yang berubah. Regresi auth splash/Advanced Export memakai checks existing yang relevan tanpa edit logic/test threshold unrelated. Tes domain/isolation dijalankan bila shared markup nyata memengaruhi boundary atau form tersebut, bukan otomatis menjalankan seluruh DB suite untuk dokumen.

## 15. Eksekusi Gate 1 dan keterbatasan

| Pemeriksaan | Hasil aktual |
|---|---|
| Mandatory context, git status/diff, lifecycle files, migrations, config, Tailwind, shared components, translations, tests, representative markup/controller mapping | VERIFIED-SOURCE; tidak dianggap browser QA |
| Route registration | VERIFIED-EXECUTION: `php artisan route:list --json` exit0; representative routes/middleware tersedia |
| Kolom density lokal | VERIFIED-EXECUTION: SELECT metadata saja; varchar20 NOT NULL; tidak ada write/schema operation |
| Reuse/spacing inventory | VERIFIED-EXECUTION: Python stdlib membaca Blade; angka dan batas regex di §3 |
| JS default test invocation | BLOCKED eksekusi child process: `spawn EPERM`, empat file runner gagal start; bukan empat assertion defect aplikasi |
| JS fallback tanpa proses terisolasi | **PASS:28 tests,0 fail**, Node v24.14.0, command di atas; subset source/runtime mock, bukan real DOM/browser/concurrency proof |
| Browser baseline | **BLOCKED/UNVERIFIED:** `cua.getState()` memberi apps/browsers kosong; membuka local Customization via iab mengembalikan `Browser is not available: iab`. Tidak login atau mengubah preference lewat browser. |
| Feature tests/DB tests/build | **NOT RUN** pada Gate1; tidak ada perubahan runtime, tidak perlu menulis test DB/build untuk menyelesaikan dokumen |
| Rendered five-level visual, viewport, keyboard native, AT, participant test, staging/production | **NOT TESTED / UNVERIFIED** |
| Pemeriksaan artifact | Path sumber dan link lokal rencana diperiksa; whitespace dan diff akhir diperiksa sebelum handoff |

Tes existing yang lulus belum memverifikasi lima-level contract karena fiturnya belum diimplementasikan. Tes `customization-form` saat ini hanya dua helper; tes unsaved sebagian memakai simulasi snapshot sendiri. Coverage nyata method form, duplicate handlers, dan rendered CSS harus ditambah di Gate2.

## 16. Risiko, rollback, dan keputusan yang masih memerlukan persetujuan

| Risiko | Mitigasi / rollback |
|---|---|
| Angka source berbeda dari computed/rendered karena fonts, content, important, utility order | Tahap0 mandatory; simpan ukuran dan selector winner. Angka usulan jangan dilabel final sebelum itu. |
| Shared component berdampak luas | Urutan tahap2 dahulu, audit neighbor/cross-role; marker baru hanya node spacing; bundle CSS+Blade rollback bersama. |
| Lima level mencapai control minimum | Pertahankan minimum dan laporkan plateau; gunakan spacing nonkontrol/table text untuk membedakan. Tidak memaksakan row cap. |
| Draft tersimpan tak sengaja atau root/form berbeda | Canonical field tunggal, saved immutable, server-only persistence, errors/bfcache/submission recovery tests. |
| Leave dialog dua kali atau dirty palsu | Exclude generic snapshot form ini; bridge API existing; sebelum unload hormati izin Leave yang sudah diberikan. |
| Initial paint atau slider bergerak | Early valid-old bootstrap; area ancestor kontrol fixed; preview frame maximum measured; tidak animasi padding/height. |
| Rollback dua-value mengabaikan akun yang sudah memakai level baru | Pertahankan five-value compatibility shim dan fallback visual; no DB rewrite. |
| Drift kerja pengguna/commit selama implementasi | Verifikasi HEAD/status/diff lagi saat Gate2; preserve perubahan di luar scope; jangan mengasumsikan snapshot Gate1 masih current. |

**Keputusan terbuka yang benar-benar membutuhkan otorisasi:** persetujuan memulai **Gate2** dengan batas perubahan di §12 dan tahap kalibrasi di §13. Tidak ada pertanyaan ulang tentang jumlah level, default, arah urutan, atau Live Application + Mini Preview.

**Blokir bukti, bukan keputusan produk:** browser/provider atau jalur manual untuk baseline rendered; perangkat/AT dan peserta pilot; isolated test database sebelum feature tests; konfirmasi metadata staging/produksi bila nanti diperlukan untuk release. Tidak perlu memblokir penulisan rencana atau meminta migrasi untuk mengatasi hal-hal ini.

Jika pengukuran Gate2 menunjukkan kebutuhan memperkecil protected controls, mengubah workflow, menambah dependency/schema, atau merombak editor/designer/splash, hentikan perluasan itu dan ajukan perubahan scope dengan bukti. Penyesuaian angka spacing setelah measurement yang tetap memenuhi kontrak ini merupakan kalibrasi engineering; jangan mengubah acceptance untuk menyembunyikan FAIL.

**FINAL STATUS: PENDING USER APPROVAL FOR GATE 2.**
