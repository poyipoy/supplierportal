# Rencana: Polish UI/UX Supplier Audit (Purchasing & Supplier Local)

**Tanggal:** 2026-10-09 · **Status:** rencana, belum ada kode diubah
**Deliverable setelah disetujui:** salin ke `docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-UX-20261009.md`.
**Prasyarat:** fitur Supplier Audit sudah terimplementasi (`docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md`).

## Context

Fitur Supplier Audit sudah berfungsi dan teruji, tetapi UX-nya masih versi pertama.

**Masalah di sisi supplier:**
- Wizard 16 langkah memaksa banyak klik dan menyembunyikan gambaran keseluruhan.
- Ada ±600 tombol Score yang tampil dalam keadaan nonaktif.
- Penyimpanan hanya manual, sehingga ada risiko isian hilang.
- Tidak ada panduan arti Score.

**Masalah di sisi Purchasing:**
- Daftar audit tidak menonjolkan pekerjaan yang perlu ditindaklanjuti.
- Halaman detail berupa tumpukan 121 jawaban tanpa arahan langkah berikutnya.

Tujuan: alur ERP yang ringkas dan jelas. Bisnis, status, otorisasi, dan kontrak data **tidak berubah**.

## Keputusan dari interview (9 Okt)

| ID | Keputusan |
|---|---|
| U1 | Form supplier: **satu halaman** berisi semua bagian, dengan **navigasi bagian sticky** di kiri (penanda selesai/belum, scroll-spy). Wizard 16 langkah dihapus. |
| U2 | **Autosave otomatis** per perubahan (debounce) dengan indikator "Menyimpan… / Tersimpan HH:MM / Gagal menyimpan — Coba lagi". Tombol Simpan Draft dihapus. Submit tetap terpisah dengan konfirmasi. |
| U3 | **Score muncul setelah memilih Ya.** Memilih Tidak menyembunyikan dan mengosongkan Score. |
| U4 | **Panduan skala Score** (umum, bisa direvisi): 1 Belum ada / sangat kurang · 2 Kurang · 3 Cukup · 4 Baik · 5 Sangat baik / terdokumentasi penuh. Tampil sekali di atas form dan sebagai tooltip/`aria-label` tiap angka. |
| U5 | **Desktop utama, HP harus layak.** Di bawah `lg`, navigasi bagian menjadi bar sticky "Bagian 3/16 ▾" yang membuka daftar; kontrol ditumpuk dengan target sentuh ≥ 44px. |
| U6 | Daftar Purchasing: **tab antrean kerja dengan hitungan**, yaitu Perlu dinilai (default) · Menunggu supplier · Terlambat · Selesai · Semua. Ditambah kolom **progres** dan **deadline relatif**. |
| U7 | Detail Purchasing: **alur status + panel "Langkah berikutnya"** dengan satu aksi utama per status; jawaban diberi **filter cepat** (Semua / Tidak / Score ≤ 2 / Belum dijawab) dan **bagian bisa dilipat**. |
| U8 | Pengingat supplier: **badge di menu Supplier Audit saja**, tanpa kartu dashboard. Banner blokir invoice tetap. |
| U9 | **Pintasan keyboard**: pada baris yang fokus, Y = Ya, T/N = Tidak, 1–5 = Score, ↓/Enter = ke kriteria berikutnya yang belum lengkap. Ditambah tombol "Lompat ke yang belum diisi". |

## Temuan kode yang membentuk rencana

- **[Verified]** Route update supplier memakai `throttle:30,1`. Autosave yang di-debounce bisa melampauinya, sehingga perlu endpoint autosave terpisah dengan limit lebih longgar.
- **[Verified]** `resources/js/async-form-submit.js:261` menangani 419/429 dengan pesan reload. Autosave memakai `fetch` sendiri dan harus menangani 419/422/429 secara eksplisit.
- **[Verified]** Pola badge sidebar: slot `trailing` pada `<x-ui.sidebar-item>` dengan `<span class="chat-badge …" aria-label=…>` (`resources/views/partials/sidebar.blade.php:176,248`).
- **[Verified]** `SupplierAudit::answerSections()`, `progress()`, dan `late()` sudah menyediakan pengelompokan dan hitungan; filter dan lipat bagian bisa di klien atas markup yang sudah dirender.
- **[Verified]** Komposer `$supplierAuditInvoiceBlock` untuk `partials.sidebar` sudah ada di `AppServiceProvider`; hitungan badge ditambahkan di komposer yang sama.
- "Dinilai offline" **bukan status sistem**, sehingga alur status hanya menampilkan status nyata. Penilaian offline muncul sebagai instruksi di panel Langkah berikutnya.

## Desain per layar

### A. Supplier — form pengisian (`local-supplier/supplier-audits/edit.blade.php`, ditulis ulang)

**Desktop (≥ lg):** grid `[15rem | 1fr]`.
- **Kiri — `<nav>` sticky:**
  - 16 bagian (kode + judul terpotong + `n/N`) dengan status ikon + teks (✓ / ● sebagian / ○ / ! error), sehingga status tidak hanya dibedakan lewat warna;
  - `aria-current="location"` untuk bagian yang sedang terlihat, via IntersectionObserver;
  - di bawahnya: progres total `87/121` + bar, indikator autosave, tombol "Lompat ke yang belum diisi".
- **Kanan:**
  - kotak "Panduan Score" (U4, bisa dilipat, state di `localStorage`);
  - alert revisi atau blokir invoice (yang sudah ada);
  - semua bagian sebagai `<section id="bagian-{kode}">`; sub-bagian 2.1/6.1/6.2/12.1 tampil di bawah induknya.

**Baris kriteria:**
- Nomor + teks (`text-wrap: pretty`), lalu `radiogroup` Ya/Tidak.
- Score 1–5 muncul hanya setelah Ya (transisi opacity/translate 150 ms, menghormati `prefers-reduced-motion`).
- Baris aktif (focus-within) diberi border kiri primary untuk **state fokus nyata**.
- Baris error mendapat latar `error-container` + pesan teks.

**Footer sticky (`x-ui.action-bar`):** status autosave (`aria-live="polite"`), progres, lalu **Submit**.

**Submit:**
- Bila belum lengkap: tidak membuka dialog. Halaman scroll ke kriteria kosong pertama, semua yang kosong disorot, dan muncul toast "Masih ada 7 kriteria yang belum lengkap".
- Bila lengkap: `AdasiAlert.confirm` berisi ringkasan "Ya 112 · Tidak 9".

**HP (< lg):**
- Bar sticky atas "Bagian 3/16 · Peraturan… ▾" membuka `x-ui.dialog` berisi daftar bagian.
- Baris ditumpuk: teks, lalu Ya/Tidak (2 kolom penuh), lalu Score (5 kolom penuh, tinggi 44px).
- Footer menampilkan progres ringkas + Submit.

**Keyboard (U9):** handler `keydown` pada container baris (`tabindex="0"`):
- Y / T / N / 1–5, dengan Score diabaikan bila jawabannya bukan Ya.
- ↓/Enter menuju kriteria berikutnya yang belum lengkap.
- Diabaikan saat fokus di input teks atau ada modifier (Ctrl/Alt/Meta).
- Legenda pintasan berupa teks kecil yang bisa dilipat. Navigasi Tab native tetap bekerja.

### B. Supplier — autosave (backend kecil)

- **Route baru:** `PATCH /local-supplier/supplier-audits/{supplierAudit}/answers` → `LocalSupplier\SupplierAuditController@autosave`, nama `local-supplier.supplier-audits.autosave`, `throttle:120,1`.
- **Request:** `SaveSupplierAuditAnswersRequest` dipakai ulang dengan `action=draft` (aturan D3 draft sama). Payload hanya berisi baris yang berubah.
- **Service:** `SupplierAuditAnswerService::save(..., submit: false)` yang sudah ada; ASSIGNED → DRAFT tetap tercatat sekali.
- **Respons JSON:** `{saved_at, saved_label (RegionalDisplayFormatter), progress: {filled,total}, status}`.
- **Klien:**
  - antrean perubahan per `criterion_id`, debounce 800 ms, satu request in-flight, perubahan baru digabung;
  - backoff 2s/5s/15s untuk error jaringan/5xx;
  - 422: tandai baris dan tampilkan pesan;
  - 419: "Sesi berakhir — muat ulang halaman", perubahan dipertahankan di memori;
  - 429: tunda lalu kirim ulang;
  - `beforeunload` memperingatkan bila ada perubahan yang belum tersimpan.
- **Submit tetap lewat route `update` (`action=submit`)** dengan seluruh jawaban, setelah antrean autosave kosong.
- Tombol Simpan Draft dihapus dari view. Route `update` tetap menerima `draft` untuk kompatibilitas.

### C. Supplier — index, show, badge

- **Index:** kartu audit aktif dengan alur status ringkas, deadline relatif ("5 hari lagi" / "lewat 3 hari", via `BusinessTime`), dan satu CTA jelas (Isi / Lanjutkan / Lihat / Download hasil); riwayat tetap tabel.
- **Show:** alur status yang sama; kartu "Hasil penilaian" di atas saat `RESULT_PUBLISHED`; jawaban read-only memakai partial yang sama dengan Purchasing (lengkap dengan filter).
- **Badge (U8):** menu "Supplier Audit" mendapat badge `1` (pola `chat-badge`, `aria-label` "1 audit perlu diisi") selama ada audit `ASSIGNED/DRAFT/REVISION_REQUESTED`. Warna `bg-error` bila terlambat, `bg-primary` bila belum.

### D. Purchasing — index

- **Tab** (link GET `?queue=…`, `aria-current="page"` pada tab aktif):

  | Tab | Isi |
  |---|---|
  | Perlu dinilai | SUBMITTED |
  | Menunggu supplier | ASSIGNED + DRAFT + REVISION_REQUESTED |
  | Terlambat | `late()` |
  | Selesai | RESULT_PUBLISHED + CANCELLED |
  | Semua | semua audit |

  - Hitungan dari satu query `groupBy status` + satu `count` late.
  - Default `review`; bila kosong, tampilkan empty state "Belum ada audit yang perlu dinilai" + tautan ke "Menunggu supplier".
  - Dropdown status dan checkbox Terlambat diganti tab; filter periode & supplier tetap.
- **Kolom:** Supplier (nama + email) · Periode · Progres (`87/121` + bar mini, `tabular-nums`) · Deadline (tanggal + relatif; chip "Terlambat · Invoice diblokir") · Status · Disubmit · satu aksi utama per baris ("Nilai" untuk Disubmit, selain itu "Lihat").
- **Progres tanpa N+1:** subquery `withCount` jawaban lengkap (`answer='NO' OR (answer='YES' AND score IS NOT NULL)`) via scope baru `SupplierAudit::scopeWithCompletedAnswersCount()`.

### E. Purchasing — detail

- **Partial alur status** `resources/views/supplier-audits/_status-flow.blade.php` (dipakai kedua role):
  - langkah: Ditugaskan → Diisi supplier → Disubmit → Hasil terbit;
  - REVISION_REQUESTED tampil pada langkah "Diisi supplier" berlabel "Revisi diminta";
  - CANCELLED tampil sebagai banner;
  - `<ol>` + `aria-current="step"`.
- **Panel "Langkah berikutnya"** di posisi teratas:

  | Status | Isi panel |
  |---|---|
  | ASSIGNED/DRAFT/REVISION_REQUESTED | Menunggu supplier · progres · deadline; sekunder: Ubah deadline, Batalkan |
  | SUBMITTED | ① **Export Excel** (primer) → ② Nilai offline (instruksi) → ③ **Upload hasil** (form inline); sekunder: Minta revisi, Batalkan |
  | RESULT_PUBLISHED | File hasil terbaru + "Ganti hasil" (alasan wajib) + riwayat file |
  | CANCELLED | Alasan pembatalan |

- **Ringkasan** dipadatkan menjadi strip `Ya 112 · Tidak 9 · Kosong 0`; hitungan per bagian dipindah ke header bagian yang bisa dilipat (tidak dobel).
- **Jawaban:**
  - toolbar filter `[Semua 121] [Tidak 9] [Score ≤ 2 4] [Belum dijawab 0]` (`aria-pressed`, filter klien via `data-answer`/`data-score`; bagian kosong disembunyikan; pesan "Tidak ada kriteria yang cocok");
  - tombol "Buka semua / Lipat semua";
  - `<details open>` per bagian, sehingga tetap berfungsi tanpa JS.
- **Kolom kanan:** riwayat status ringkas + info penugasan (oleh/kapan, versi template).

### F. Purchasing — create (polish kecil)

- Pintasan deadline: [+14 hari] [+30 hari] [Tanpa deadline] di samping date-picker.
- Ringkasan di action bar: "3 supplier dipilih · 1 dilewati karena audit aktif".
- Kolom "Audit terakhir" (periode + status) pada daftar supplier, diambil dengan satu query.

## Prinsip visual

- Hanya token yang ada (`--md-*` / `tw-*`). Tanpa gradient, glow, bayangan tebal, emoji, atau dot dekoratif. Warna dipakai untuk state nyata saja (selesai, error, terlambat, fokus).
- `tabular-nums` untuk progres, hitungan, dan tanggal; `text-wrap: balance` untuk judul; `text-wrap: pretty` untuk teks kriteria.
- Transisi hanya opacity/transform/background-color (≤ 200 ms), tanpa animasi berulang, menghormati `prefers-reduced-motion`.
- Target sentuh ≥ 44px di HP; focus ring di semua kontrol; status selalu punya teks/ikon selain warna.
- Empty / loading / error menyebut penyebab dan aksi berikutnya.

## File yang disentuh

| Area | File |
|---|---|
| Supplier form | `resources/views/local-supplier/supplier-audits/edit.blade.php` (rewrite); `resources/js/supplier-audit-form.js` (baru: autosave queue, scroll-spy, keyboard), di-import di `resources/js/app.js` |
| Supplier lain | `local-supplier/supplier-audits/{index,show}.blade.php`; `partials/sidebar.blade.php` (badge); komposer `app/Providers/AppServiceProvider.php` |
| Shared partial | `resources/views/supplier-audits/_status-flow.blade.php` (baru), `_answers-readonly.blade.php` (filter + `<details>`) |
| Purchasing | `purchasing/supplier-audits/{index,show,create}.blade.php`; `app/Http/Controllers/Purchasing/SupplierAuditController.php` |
| Backend autosave | `routes/web.php` (1 route), `LocalSupplier\SupplierAuditController@autosave`, `app/Models/SupplierAudit.php` (scope progres) |
| i18n | `lang/{en,id}/supplier_audit.php` (score scale, queue tabs, deadline relatif, autosave state, legend pintasan, copy langkah berikutnya), `lang/{en,id}/js.php` bila dipakai di modul JS |
| Docs | AGENTS.md bagian Supplier Audit (endpoint autosave) |

## Reuse

- `SupplierAuditAnswerService::save()`, `SaveSupplierAuditAnswersRequest`, `SupplierAudit::late()/progress()/answerSections()`, `StatusHelper::supplierAuditTone/Label()`.
- `RegionalDisplayFormatter`, `BusinessTime`, `x-ui.action-bar`, `x-ui.dialog`, `x-ui.status-chip`, `x-ui.empty-state`, `x-ui.file-upload`.
- `AdasiAlert.confirm`, `AdasiToast`, pola `chat-badge`, `async-form-submit.js` (submit & upload), `async-export.js`.

## Verifikasi

- **Tes `tests/Feature/SupplierAudit/`** (diperbarui/ditambah):
  - edit page merender 16 `section` + 121 baris tanpa `data-wizard-step` (asersi lama diganti);
  - autosave: JSON progres/`saved_at`, 403 supplier lain, ditolak saat terkunci, D3 draft, ASSIGNED → DRAFT sekali;
  - index: hitungan tab, default tab, progres tanpa N+1 (hitung query via `DB::listen`);
  - show: panel Langkah berikutnya per status, markup filter (`data-answer`);
  - badge sidebar muncul/hilang sesuai status.
- **Guard** (dijalankan serial setelah memastikan tidak ada sesi lain yang menjalankan tes): `TranslationParityTest`, `UserFacingCopyInventoryTest` (regenerasi ledger), `SidebarShellTest`, `RenderedComponentTest`, `SurfaceHierarchyTest`, `FrontendAssetLoadingTest`, `tests/Feature/SupplierAudit`, `tests/Feature/LocalInvoice`.
- **Build & statis:** `npm.cmd run build`, `php artisan view:cache`, `php -l`, Pint, `git diff --check`.
- **Manual di browser** (dilaporkan terpisah):
  - isian bertahan setelah tab ditutup dan dibuka lagi;
  - pintasan Y/T/1–5/Enter;
  - Score muncul/hilang;
  - submit tidak lengkap melompat ke kriteria kosong;
  - HP 360px dan tablet;
  - Purchasing: tab antrean, export → upload dari panel, filter jawaban;
  - navigasi keyboard-only dan screen reader pada radiogroup dan nav bagian.
