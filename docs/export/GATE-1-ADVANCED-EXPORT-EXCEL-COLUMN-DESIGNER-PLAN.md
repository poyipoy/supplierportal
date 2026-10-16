# Gate 1 — Advanced Export Excel-Style Column Designer

Tanggal audit: 15 Oktober 2026. Workspace: C:/laragon/www/adasi_portal_supplier. Branch: feature/material-procurement-revision-gate2; HEAD: 78e8bf4. Referensi baris menunjuk working tree yang diinspeksi, termasuk perubahan pengguna yang belum di-commit.

**Keputusan:** pilih alternatif B: satu modul kecil khusus column designer, terintegrasi dengan controller frontend existing. Sumber state adalah JavaScript, bukan DOM. Backend, struktur preset, dan options.columns tetap memakai kontrak existing. Tidak diperlukan spreadsheet library.

**Batas Gate 1:** audit, riset sumber primer, keputusan, dan rencana. Tidak ada implementasi PHP/Blade/JS/CSS, instalasi dependency, perubahan database, atau operasi Git mutatif. Dokumen ini memakai lokasi yang diminta eksplisit dalam objective, sebagai pengecualian terhadap folder dokumentasi umum di AGENTS.md.

## A. Primary Evidence

### A1. Pemahaman yang dikunci

- Pengguna operasional mengatur kolom ekspor melalui worksheet horizontal di Bootstrap modal existing.
- Worksheet berisi huruf posisi, business headings, dan tiga baris visual kosong. Semua kolom yang tampak ikut diekspor.
- Mendukung pemilihan target kolom, Hide/Unhide, Move Left/Right, drag horizontal, Reset, ribbon ringkas, dan context menu.
- Filters, Format, personal presets, Quick Export, dan export async tetap tersedia.
- Tidak ada data record, sample data, edit sel, formula, resize kolom, atau request preview tambahan.
- Hanya sembilan kombinasi key/audience Tier 1 yang memakai designer. Supplier Price History tetap format-only.

Kebutuhan produk di atas sudah disetujui dalam objective; tidak dibuka ulang oleh workflow brainstorming. Risiko desain **sedang**: perubahan reversible di frontend, tetapi beberapa mekanisme interaksi dapat mengubah urutan file secara diam-diam bila memiliki state terpisah. Asumsi: katalog tetap dibatasi kontrak 64 key; maintenance tetap di tim/aplikasi Blade dan vanilla JS existing. Besaran record ekspor tidak menentukan ukuran worksheet.

AGENTS.md, CLAUDE.md, framework kognitif Laravel, panduan Advanced Export, dan kontrak visual telah dibaca. Tidak ditemukan AGENTS.md yang lebih spesifik. Skill eksplisit boost, deep-research, search-first, accessibility, make-interfaces-feel-better, orch-change-feature, dan design-orchestration dipakai; panduan frontend/design-system, Laravel/security/verification dan browser QA juga ditinjau. Pola React atau pipeline deployment generik tidak diterapkan ke aplikasi ini.

Label bukti di seluruh dokumen:

- **VERIFIED-SOURCE:** langsung ditetapkan dari sumber saat audit; bukan bukti browser.
- **VERIFIED-EXECUTION:** perintah benar-benar dijalankan pada audit ini.
- **INFERRED:** kesimpulan desain dari bukti; masih perlu pembuktian implementasi.
- **UNVERIFIED:** belum dieksekusi atau membutuhkan browser/pengguna/lingkungan lain.

### A2. Jalur eksekusi dan coupling aktual

| Bukti saat ini | Konsekuensi untuk designer |
|---|---|
| [ExportDefinitions.php:17](../../app/Support/Export/ExportDefinitions.php#L17) meng-allowlist 10 export keys; allowedColumns menyaring audience (:48). | Browser tidak menentukan class/audience. Ambil hanya katalog dari endpoint existing. |
| [ExportDefinitionController.php:20](../../app/Http/Controllers/ExportDefinitionController.php#L20) mengirim key, formats, support flags, kolom key/label/default/required, dan filters. | Lebar/tipe data backend tidak tersedia untuk UI. Gunakan lebar tampilan sendiri; jangan menambah kontrak metadata. |
| [advanced-modal.blade.php:8](../../resources/views/components/export/advanced-modal.blade.php#L8) memberi URL, copy, initial filters, selectors dan table; modal :17–26 memakai modal-lg, modal-dialog-scrollable dan form ber-overflow-hidden. | Pertahankan root, form, endpoint, filter/calendar hooks, trigger ID dan integrasi Bootstrap. Overflow/rendered layout harus diuji. |
| [app.js:16](../../resources/js/app.js#L16) meng-import advanced-export; [layout:210](../../resources/views/layouts/app.blade.php#L210), :281 memuat Bootstrap 5.3.3 CDN+SRI; :643 memuat SDK export. | Tidak perlu framework, Vite entry, atau import app.js baru. |
| [advanced-export.js:75–106](../../resources/js/advanced-export.js#L75) membaca key dari checkbox tercentang dalam urutan DOM. columns(selected) membentuk selected + sisa katalog; required checked/disabled. | DOM saat ini merupakan state. Tidak boleh mempertahankan jalur pembacaan checkbox paralel setelah migrasi. |
| [advanced-export.js:97–103](../../resources/js/advanced-export.js#L97) memasang listener per row; drag memasukkan source sebelum target. | Belum ada perhitungan midpoint horizontal, indikator insertion, auto-scroll atau preview transaksi. Ketiadaan ini ditetapkan dari source, bukan uji drag browser. |
| [advanced-export.js:108–143](../../resources/js/advanced-export.js#L108) memuat katalog/preset dengan dua GET paralel, membuka modal dengan default server, dan Quick memakai personal default. Reset hanya memanggil columns(defaultKeys). | Normal Open berbeda dari Quick; Reset Columns tidak boleh mereset filter/format/preset. |
| [advanced-export.js:127](../../resources/js/advanced-export.js#L127), :151 mengirim key yang sama untuk export dan save preset. Save kemudian reload dan apply hasil (:154). | Satu serialize() wajib dipakai kedua jalur; save/reload memang membangun ulang konfigurasi. |
| [AdvancedExportRequest.php:20](../../app/Http/Requests/Export/AdvancedExportRequest.php#L20) mengizinkan hanya options.columns/format; kolom 1–64, distinct, stable key. [resolver:16](../../app/Support/Export/ExportOptionsResolver.php#L16) memberi default saat columns absen. | Payload designer tetap ordered visible keys. Jangan kirim hidden, letters, activeKey, layout atau audience. |
| [ExportDefinitions.php:64](../../app/Support/Export/ExportDefinitions.php#L64) menolak kosong, key asing, duplikat, atau required hilang; mengembalikan urutan tanpa diubah. | Required harus tetap visible tetapi boleh dipindah, termasuk bukan posisi pertama. |
| [ExportPresetService.php:137–156](../../app/Services/Export/ExportPresetService.php#L137) menyesuaikan stale keys, memulihkan required/default, dan memberi warnings. [migrasi:14](../../database/migrations/2026_10_07_000002_create_export_presets_table.php#L14) hanya menyimpan selected ordered columns. | Konsumsi hasil present() server. Riwayat posisi hidden tidak persisten; jangan menambah semantik penyimpanan baru. |
| [ExportPresetController.php:13](../../app/Http/Controllers/ExportPresetController.php#L13), [policy:12](../../app/Policies/ExportPresetPolicy.php#L12), [service:43](../../app/Services/Export/ExportPresetService.php#L43) mempertahankan owner scope, policy, user/row locks. | Preset CRUD, CSRF, ownership dan validasi tidak dipindah ke client. |
| [Purchasing ExportController:56](../../app/Http/Controllers/Purchasing/ExportController.php#L56) memberi konfirmasi pada GET dan dispatch pada POST; [Supplier:65](../../app/Http/Controllers/Supplier/ExportController.php#L65) memaksakan supplier auth. | Pertahankan metode dan boundary ini. Wording panduan lama tentang GET tidak berarti GET boleh langsung dispatch. |
| [ExportDispatcher.php:84–135](../../app/Support/ExportDispatcher.php#L84) memvalidasi ulang, menerapkan options sebelum count, lalu transaksi atomic queue handoff; [ProcessExportJob:60](../../app/Jobs/ProcessExportJob.php#L60), :110 memilih writer/finalizer. | Lifecycle async, locale, private disk, limit dan reauthorization tidak berubah. |
| [UsesColumnCatalog.php:21–56](../../app/Exports/Advanced/Concerns/UsesColumnCatalog.php#L21) menggunakan urutan key yang sama untuk headings/map; :73 eager loads; [download:50](../../app/Http/Controllers/ExportDownloadController.php#L50) mengotorisasi output. | File XLSX/CSV adalah otoritas akhir. Pertahankan sanitasi formula dan mapping; jangan percaya worksheet sebagai bukti output. |

Semua baris tabel di atas berstatus **VERIFIED-SOURCE**. Pemanggil komponen ditemukan pada Purchasing PO/PR/quotation/shipment, Supplier PO/quotation (termasuk period), QC inspections, Finance master invoices dan Accounting reports. Tidak perlu mengedit masing-masing halaman untuk mengganti selector bersama. Format-only memakai form tersendiri, [historical.blade.php:51](../../resources/views/supplier/price-history/historical.blade.php#L51), dan [advanced-export.js:168](../../resources/js/advanced-export.js#L168).

### A3. Katalog aktual dan baseline aman

Probe PHP yang hanya memuat vendor/autoload.php, tanpa boot Laravel/akses DB, mengeksekusi defaultKeys/sanitizeKeys dan roundtrip ExportOptions. **VERIFIED-EXECUTION:** required yang dipindah tetap diterima; empat kasus invalid ditolak: kosong, duplikat, key asing, required hilang.

| Keluarga key | Available/default | Required |
|---|---:|---|
| purchasing.po / supplier.po | 12 / 10 | po_number |
| purchasing.quotations / supplier.quotations | 24 / 24 | pr_number |
| purchasing.pr | 11 / 11 | pr_number |
| purchasing.shipments | 11 / 11 | shipment_number |
| qc.inspections | 8 / 8 | po_number |
| finance.local-invoices / accounting.local-invoices | 16 / 16 | submission |
| supplier.price-history | 0 / 0 | Tidak ada; format-only |

PO mempunyai port_of_discharge dan incoterm nondefault, [PurchaseOrderDefinition.php:62](../../app/Exports/Advanced/Definitions/PurchaseOrderDefinition.php#L62). Reset ≠ semua kolom. Katalog terbesar saat ini 24; 64 adalah batas kontrak/stress case, bukan jumlah kolom yang diamati.

**VERIFIED-EXECUTION:** perintah berikut lulus 11 tes, 0 gagal:

    node --test tests/js/advanced-export-format.test.mjs tests/js/advanced-export-sdk.test.mjs tests/js/advanced-export-csv-timeout.test.mjs

Coverage ini membuktikan transport/format-only/monitoring yang diuji, bukan state worksheet, fokus, atau browser. [AdvancedExportUiTest.php:22](../../tests/Feature/AdvancedExportUiTest.php#L22) menguji markup/POST dan masih mengunci data-column-up/down. [AdvancedPurchaseOrderExportTest.php:68](../../tests/Feature/AdvancedPurchaseOrderExportTest.php#L68) dan [ExportPresetTest.php:56](../../tests/Feature/ExportPresetTest.php#L56) sudah memiliki order/output/preset cases; hanya diinspeksi, tidak dieksekusi ulang di Gate 1.

**Browser UNVERIFIED:** Chrome DevTools gagal karena profile sedang digunakan sesi lain. CUA melaporkan daftar browser kosong; upaya membuka iab dan chrome gagal dengan “Browser is not available”. Tidak ada rendered-modal, screenshot, focus, overflow, interaction timing atau usability baseline yang diklaim. Server/harness lain tidak dihentikan atau diubah. Static HTML/CSS bukan pengganti bukti ini.

Inventaris ukuran diukur dengan Node fs + gzipSync; build existing hanya dibaca, tidak dibangun ulang:

| Asset | Raw bytes | Gzip bytes |
|---|---:|---:|
| resources/js/advanced-export.js | 14.153 | 3.657 |
| resources/css/app.css | 133.234 | 21.052 |
| public/build/assets/app-D_TWodPa.js | 164.141 | 50.705 |
| public/build/assets/app-oxZ5b9pC.css | 146.364 | 25.501 |

Ini inventaris asset, bukan ukuran transfer aktual atau bukti build cocok dengan seluruh dirty tree. Baseline fingerprint 254 file modified/untracked existing: SHA-256 caa45244368a7427da7d4e40e8bcda101a22fc5f1181c1bc6ea13d276f91f96e, dihitung dari path terurut dan byte file. Perubahan existing pada app.js/app.css/lang/export definitions/tests serta auth splash diperlakukan sebagai milik pengguna.

## B. Architectural Decision Record

### B1. Risiko utama dan pilihan state

Risiko utama adalah divergensi antara drag, ribbon, context menu, preset dan serialization. Semua command harus melalui transisi yang sama sebelum render; tidak ada “state kedua” di checkbox, CSS visibility atau posisi DOM.

| Model | Kelebihan | Biaya/kegagalan | Keputusan |
|---|---|---|---|
| Total order semua key + visible set (O + V) | Hide tidak kehilangan slot; Unhide dan serialize berupa projection; mudah diuji tanpa DOM. | Posisi hidden mengikuti total order saat ini; harus dijelaskan ketika kolom lain melintasinya. | **Dipilih.** |
| Visible order + hidden records berisi index/anchor sebelum hide | Visible drag tampak sederhana. | Index menjadi basi; anchor dapat ikut hidden/dipindah; perlu fallback dan aturan pemulihan berantai. | Ditolak: menambah state dan aturan untuk hasil yang sama. |

### B2. Alternatif frontend

| Kriteria | A — perluas advanced-export.js | B — modul designer kecil |
|---|---|---|
| Coupling/state ownership | Kolom, network, filters, preset dan SDK tetap satu closure 192 baris yang sudah DOM-dependent. | State/render/interaksi kolom terisolasi; controller tetap memiliki I/O existing. |
| Isolasi tes | Perlu mengemulasi modal/form/fetch untuk menguji aturan order. | Pure exports dapat di-import Node tanpa document/window; integrasi diuji terpisah. |
| Lifecycle/memory | Menambah menu, drag dan scroll ke listener per-row existing. | Satu instance/root, delegated listeners, cleanup eksplisit dan rAF terbatas. |
| Asset | Tidak menambah file/module; tetap memerlukan kode interaksi baru. | File baru bukan request baru bila static import masuk entry existing; delta bytes harus diukur. |
| Maintenance | Paling sedikit file tetapi responsibility makin bercampur. | Satu boundary internal sempit, tanpa event bus/store framework/plugin API. |
| Kontrak backend/preset | Bisa dipertahankan. | Bisa dipertahankan dengan serialize() tunggal. |

**Pilih tepat B.** Modul resources/js/export-column-designer.js mengekspor pure state operations serta mount/destroy view tanpa side effect DOM saat import. advanced-export.js mengimpor modul itu secara statis; app.js tidak perlu berubah. Tidak perlu memecah core/view menjadi banyak layer sebelum ada tekanan nyata. Alasan pemisahan adalah state correctness dan test isolation, bukan ukuran file semata.

Dependency decision: native HTML drag desktop existing + tombol untuk semua input cukup sebagai kandidat awal. SortableJS belum terpasang; [upstream](https://github.com/SortableJS/Sortable) menyediakan handles/touch/auto-scroll tetapi tidak menyelesaikan model state atau keyboard. [Handsontable column moving](https://handsontable.com/docs/javascript-data-grid/column-moving/) menyediakan grid instance untuk kebutuhan lebih luas daripada header configurator ini. Tidak ada kebutuhan terukur untuk menambah keduanya. Ini kesimpulan arsitektur, bukan klaim perbandingan bundle yang belum diukur.

### B3. Decision log dan kondisi pembalikan

| ID | Keputusan | Bukti/alasan | Bukti yang dapat membatalkan |
|---|---|---|---|
| D1 | O + V authoritative; DOM hanya projection | keys()/columns() existing dan kebutuhan Unhide | Sequence tests menemukan reinsertion ambigu yang tidak dapat dispesifikasikan sederhana. |
| D2 | Dedicated module B; tidak mengubah backend | Semua opsi yang diperlukan tersedia pada metadata/ordered columns | Output contract membutuhkan metadata yang benar-benar belum ada; laporkan sebelum memperluas API. |
| D3 | Single active header; hide beberapa kolom dengan aksi berulang | Locked requirement tidak mensyaratkan multiselect | Studi pengguna menunjukkan tugas Hide gagal/lambat secara material; bahas perubahan scope, jangan diam-diam menambah selection ranges. |
| D4 | Tidak menyimpan hidden history | Skema preset hanya ordered selected keys | Persetujuan bisnis terpisah untuk persistence baru; bukan bagian rencana ini. |
| D5 | Bootstrap tetap pemilik modal/focus trap | Versi 5.3.3 existing; menu dapat tinggal di dalam modal | Browser menunjukkan constraint yang tidak dapat diatasi lewat scoped layout/layer. |
| D6 | Native drag desktop; pan touch + tombol | Interaksi existing dan WCAG dragging alternative | Uji drag/scroll gagal berulang; evaluasi library kecil dengan bukti dan persetujuan dependency. |

Review independen arsitektur mengembalikan REVISE pada spesifikasi awal: validasi sebelum commit, fokus deterministik, listbox semantics, async generations, cancel dan Unhide. Semua kondisi tersebut dimasukkan ke C/D/G di bawah. Review ulang dokumen akhir menghasilkan **APPROVED untuk spesifikasi Gate 1**, tanpa kontradiksi material; bukan persetujuan implementasi atau bukti runtime. Persetujuan pengguna untuk Gate 2 tetap terpisah.

## C. State Model

### C1. Schema dan invariants

    catalog C = ordered immutable [{ key, label, default, required }]
    D = C.filter(default).map(key)       // urutan canonical server
    R = set(C.filter(required).map(key))
    state = { order: O, visible: V, activeKey, revision }
    W = O.filter(key => V.has(key))      // satu-satunya urutan worksheet/export
    hidden = O.filter(key => !V.has(key))

Controller memegang load epoch/AbortController dan busy-operation identity. View memegang transient menu/focus origin/drag candidate/rAF; transient bukan konfigurasi ekspor. Catalog harus key-unique, valid, 1–64 untuk Tier 1; D nonempty dan R subset D. defaultKeys() server tidak otomatis menggabungkan R: bila metadata tidak memenuhi invariant itu, blokir interaksi/serialization dan tampilkan retry, jangan menebak default baru.

Invariants setelah SETUP dan setiap command committed:

1. O adalah permutasi tepat semua key C: tidak duplikat, tidak key asing, tidak key hilang.
2. V subset C; R subset V; 1 ≤ size(V) ≤ 64.
3. activeKey ada dalam W; hanya loading/uninitialized yang boleh null dan tidak dapat diekspor.
4. Move/drag hanya mengubah O; membership V tidak berubah. Hide/Unhide hanya mengubah V, bukan O.
5. Urutan W adalah urutan visual header dan urutan serialization yang sama.
6. Huruf A…Z, AA…BL dihitung dari index W, tidak pernah menjadi identity/key/preset. Hidden tidak diberi huruf.
7. Semua input command divalidasi sebelum perubahan; invalid command = no-op beralasan, bukan partial mutation.
8. Revision naik pada perubahan konfigurasi; setiap perubahan membatalkan menu/drag yang memakai revision lama. Navigasi fokus saja tidak mengubah order.
9. Satu instance per root. Modal/root lain tidak berbagi catalog, state atau drag session.
10. Tidak ada localStorage/server write untuk menyimpan draft/hidden position. Reopen membangun state baru dari respons yang berlaku.

### C2. Transisi persis

| Command | Transisi committed | Fokus/guard |
|---|---|---|
| Init / Reset Columns | O = D + (C minus D dalam urutan C); V = set(D). | activeKey = D[0]. Reset hanya kolom; filter/format/preset form tetap. |
| Apply presented preset P | Validasi P nonempty, distinct, subset C, mencakup R; O = P + (C minus P); V = set(P). | activeKey = P[0]; batalkan transient. Jangan sort P atau memindahkan required ke depan sendiri. |
| Select k | activeKey = k. | Hanya k visible; selection berarti target tindakan, bukan include/exclude export. |
| Hide k | V = V minus k; O tetap. | Tolak required/kolom terakhir. Pilih visible berikutnya di index lama, atau sebelumnya bila terakhir. |
| Unhide h | V = V union h; O tetap. | Hanya hidden authorized key; h menjadi active, reveal dan focus. Tidak otomatis dipindah ke ujung. |
| Move Left k | Hapus k dari O, sisipkan tepat sebelum previous visible key dalam W lama. | No-op pada ujung kiri; active tetap k. |
| Move Right k | Hapus k dari O, sisipkan tepat sesudah next visible key dalam W lama. | No-op pada ujung kanan; active tetap k. |
| Drop k before/after t | Hapus k dari O, sisipkan sebelum/sesudah visible target t; ujung kanan mendukung after-last. | Sama guard Move; drop harus current epoch/revision/root. |
| Cancel drag/menu | Tidak mengubah O/V. | Bersihkan indicator/rAF; pulihkan fokus bila masih relevan. |
| Serialize / Save | Salinan W saja, dengan format dari controller existing. | Reassert invariants; format-only tidak memanggil designer/columns. |

Urutan hidden relatif satu sama lain tetap saat Move. Slotnya dapat berpindah relatif terhadap kolom visible yang melintasinya; tidak dijanjikan kembali ke index absolut saat pertama kali di-hide. Panel Unhide menampilkan label dan posisi prospektif, misalnya “Supplier — sebelum Status”, dihitung dari W setelah restore. Karena itu hasil dapat diprediksi sebelum dikonfirmasi.

### C3. Sequence dan persistence boundary

A wajib, B/C/D optional; default semua kecuali dinyatakan lain. Simbol hanya key state, bukan sample record.

| Sequence | Hasil yang wajib dipatok dalam tes |
|---|---|
| O=[A,B,C,D]; Hide B; Move C Left; Unhide B | Setelah Hide: W=[A,C,D]. Setelah Move: O=[C,A,B,D], W=[C,A,D]. Restore: W=[C,A,B,D]. A boleh bergeser meski required. |
| Apply P=[D,A]; Move A Left; Reset | Apply: O=[D,A,B,C], W=[D,A]. Move: W=[A,D]. Reset mengembalikan O/W=[A,B,C,D]. |
| Hide seluruh optional; Restore C | W=[A], Hide A ditolak; Unhide C menghasilkan W=[A,C] menurut slot O. |
| Drag D ke depan; Escape/outside drop; Cancel modal; Reopen | Sebelum drop sukses W tidak berubah. Reopen normal memuat default server satu kali, bukan preview drag/draft lama. |
| Simpan W=[C,A,D] dengan B hidden; reload preset | P=[C,A,D]; O=[C,A,D,B]. Restore B sekarang di akhir. Riwayat slot B sebelum save tidak tersimpan dan tidak direkonstruksi fiktif. |
| Stored P=[retired,status], PO katalog baru | Server present() memberi [po_number,status] + warning. Client memakai tepat hasil ini; kolom baru optional tetap hidden. Semua key retired → server defaults. |
| Respons P/C tidak konsisten atau respons load lama tiba setelah reopen | Pertahankan state valid terakhir untuk tampilan, blokir Export/Save, tampilkan retry. Reload pasangan catalog/preset baru; respons stale tidak boleh menimpa state. |

Backend present() sudah memulihkan required dengan aturan server yang existing. Jangan menduplikasi repair itu di reducer. Katalog tanpa otorisasi kini menghasilkan error server; client tidak memakai katalog lama untuk ekspor. Copy singkat yang berguna bagi pengguna: “Preset menyimpan kolom yang ditampilkan dan urutannya.”

### C4. Modal/async lifecycle

- Normal show: epoch baru, batalkan GET lama, disable aksi sampai catalog/preset valid, init default. shown.bs.modal mengatur initial focus setelah Bootstrap siap. Jangan mencuri fokus lagi ketika load selesai bila pengguna sudah berpindah.
- Quick: memakai init/serialize yang sama, lalu personal default existing. Gagal sebelum dispatch membuka modal dengan state/error dari attempt yang masih current; tidak menjalankan show-load yang menimpa error itu.
- Save/update: serialize W; gunakan presented result dari server melalui aturan Apply. Reload existing membentuk ulang posisi hidden; format/filter tetap mengikuti respons preset existing.
- Setiap continuation load, Quick→execute, save→reload→apply, catch dan finally memeriksa epoch/operation identity. Stale completion tidak boleh mengaktifkan tombol operasi baru, menampilkan error lama, atau membuka ulang modal yang ditutup pengguna.
- Hide: cancel drag/menu/type-ahead/rAF dan GET; invalidate UI epoch. POST yang sudah terkirim atau export yang telah diterima SDK tidak dianggap dibatalkan hanya karena modal ditutup. Jangan menambah cancellation job.
- Hidden: restore opener yang benar (Open atau Quick), dengan fallback trigger existing. Boot idempotent; listeners stabil didelegasikan pada root. destroy() melepas listeners/overlay/referensi bila root dibuang; tidak menambah MutationObserver global. Pemanggil yang diperiksa saat ini bukan root dalam server-tabs fragment; bila boundary ini berubah, gunakan event swap existing, bukan observer baru.

## D. Interaction Specification

### D1. Worksheet dan modal

    Advanced Export                                      [Tutup]
    Filters: [kontrol existing, dapat membungkus]
    Kolom dan Urutan   [Sembunyikan] [Tampilkan (n)] [←] [→] [Menu ⋮] [Reset]
    [satu kolom aktif; petunjuk singkat; jumlah tampil/tersembunyi]
    ┌─────────────┬──────────────┬──────────────┬──── → scroll
    │ A           │ B            │ C            │
    │ Nomor PO *  │ Supplier     │ Status       │   header dapat dipilih
    ├─────────────┼──────────────┼──────────────┤
    │             │              │              │   3 baris visual kosong
    │             │              │              │   aria-hidden, tanpa input
    │             │              │              │
    └─────────────┴──────────────┴──────────────┘
    Format: [XLSX/CSV]     Presets: [kontrol existing]
                                            [Batal] [Export]

Desktop: scoped class pada modal Tier 1, lebar hampir viewport (margin kira-kira 1rem), max-width tidak dibatasi modal-lg, tinggi memakai 100dvh dengan fallback. Di bawah breakpoint Bootstrap sm menjadi fullscreen. Pertahankan modal-dialog-scrollable; content/form flex dengan min-height/min-width:0 dan form flex-grow agar footer tetap terjangkau. Modal body scroll vertikal; hanya worksheet scroll horizontal. Ribbon membungkus, bukan memaksa body page melebar. Tidak menambah tab/ribbon multi-page atau menyembunyikan Filters/Format/Presets di workflow baru.

Gunakan tokens [app.css:127](../../resources/css/app.css#L127), :201, :246–251, :268–269; dark overlays existing :708–725. Border/radius kecil dan .ui-focus-ring (:1987), tanpa dekorasi/animasi spreadsheet. Header wrap, full business label tetap accessible; bila visual dipadatkan, label aktif lengkap tampil sebagai teks, bukan tooltip-only. Minimum target AA 24×24 CSS px; aim 40–44 px pada coarse pointer jika ruang memungkinkan. Pan horizontal touch tetap native.

### D2. Semantics dan perilaku yang dapat diuji

Header memakai **horizontal single-select listbox**, plain role=option dengan aria-selected, satu roving tabindex=0; tombol tindakan berada di luar option. Label listbox menjelaskan “Pilih kolom untuk tindakan”; petunjuk menyatakan semua header yang tampak ikut diekspor. Empty rows aria-hidden; tidak menggunakan ARIA data grid/sel editable. Pilihan ini mengikuti [APG Listbox](https://www.w3.org/WAI/ARIA/apg/patterns/listbox/); options tidak berisi tombol/checkbox bersarang.

| Interaksi | Trigger → state | Feedback / cancel | Keyboard/touch dan acceptance |
|---|---|---|---|
| Select/navigate | Klik/tap header; Arrow Left/Right, Home/End; type-ahead business label → activeKey. | Border/state selected + full label; scrollIntoView nearest hanya pada perubahan fokus. Tidak mengubah V/W. | Satu Tab masuk header, Tab berikut keluar; Space/Enter select. Assert serialization identik sebelum/sesudah navigasi. |
| Hide | Ribbon/menu → Hide(activeKey). | Header hilang, letters/count diperbarui; live region menyebut label dan hasil. Required/last disabled + alasan tersedia. | Tap atau keyboard tombol sama; required tetap bisa dipilih/dipindah. Assert R tetap visible, fokus fallback C2. |
| Unhide | Ribbon selalu tersedia; panel native select hidden labels dalam urutan O + Restore. | Posisi restore diperlihatkan sebelum aksi; Escape/Cancel menutup tanpa perubahan. Bila kosong, command disabled dengan teks “Tidak ada kolom tersembunyi”. | Focus select saat buka, restore memilih/reveal h. Dapat dilakukan tanpa target header tersembunyi. Assert reinsertion tepat W baru. |
| Move | Ribbon/menu Left/Right → transisi C2. | Selected key tetap, header/letters berpindah; boundary disabled, live status posisi baru. | Tombol menyediakan alternatif single-pointer non-drag dan keyboard. Assert V identik dan hasil sama dengan drop ekuivalen. |
| Context menu | Right-click header memilih target lalu membuka; Menu ribbon atau Shift+F10/ContextMenu key memakai activeKey. | Menu berisi Hide, Unhide…, Left, Right, Reset; tidak ada aksi baru. Escape/outside click menutup. | Arrow Up/Down/Home/End, Enter/Space; Tab menutup dan keluar tanpa trap; Escape restore invoker/fallback. Buka via keyboard beranchor rect header, bukan koordinat pointer 0,0. |
| Drag desktop | dragstart menyimpan key/root/epoch/revision; dragover memilih before/after midpoint; drop valid melakukan satu commit. | Source tetap, insertion line + target posisi terlihat. Preview tidak memodifikasi O/V. Escape, dragend tanpa valid drop, outside drop, modal hide atau revision berubah membatalkan. | Touch menggunakan tap+Move; tidak diwajibkan native touch drag. Assert drag cancel sama persis dengan pre-drag serialized list. |
| Auto-scroll | Drag dekat tepi horizontal worksheet → rAF mengubah scrollLeft dalam batasnya. | Candidate dihitung ulang setelah scroll; berhenti di batas/dragend/hide. Tidak scroll seluruh modal/page. | Uji start/middle/end, both directions, before-first/after-last; hidden/non-header row tidak jadi target. |
| Reset / Preset | Tombol Reset / select preset → C2; cancel transient lebih dulu. | Default/preset visible order dan count segera sinkron; preset warning tetap teks. | Reset kolom tidak mengubah filter/format; Apply preset mengikuti filter/format existing. Uji delayed fetch tidak menimpa hasil intent terbaru. |
| Export / Save | Controller membaca serialize() yang sama; tidak scan DOM. | Busy/validation existing; 422 menjaga pilihan untuk koreksi. Accepted export tetap SDK existing. | Payload hanya options.columns/format; bandingkan header file dengan W, bukan letters/decorative rows. |

Untuk drag gunakan clientX dan getBoundingClientRect() dalam coordinate space viewport yang sama; hit-test ulang setelah scroll, jangan mencampur pageX dengan rect lama. Ini mengikuti [CSSOM View](https://www.w3.org/TR/cssom-view-1/). Native HTML drag dipertahankan untuk desktop; tidak membuat implementasi pointer-drag kedua. Bila kelak memakai pointer implementation, pointercancel/lost capture harus cancel, sesuai [Pointer Events](https://www.w3.org/TR/pointerevents3/).

Menu/panel layer menjadi child langsung .modal, sibling .modal-dialog, di luar worksheet dan form yang clipped; bukan portal document.body. Posisi fixed dihitung/clamp ke viewport usable (termasuk visualViewport bila tersedia), dengan batas tinggi/scroll menu. Tutup saat scroll/resize agar tidak tertinggal dari anchor. Ini menjaga layer di dalam focus trap Bootstrap. [Bootstrap v5.3.3 modal source](https://github.com/twbs/bootstrap/blob/v5.3.3/js/src/modal.js) mengikat trap ke modal root dan Escape ke modal: handler capture hanya mengonsumsi Escape ketika menu/drag aktif, sebelum Bootstrap; Escape berikutnya menutup modal. Pembuktian geometry/focus tetap harus di browser.

Initial focus mengikuti [APG Dialog](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/): judul/help yang dapat difokuskan untuk konten panjang, logical tab order, focus tetap di modal, close mengembalikan opener. Menu mengikuti [APG Menu](https://www.w3.org/WAI/ARIA/apg/patterns/menubar/); Unhide panel bukan submenu/modal bertingkat. Calendar filter existing dipertahankan; [calendar.js:519](../../resources/js/calendar.js#L519) mem-portalkan panel ke body, sehingga interaksi kalender + focus trap wajib diuji tersendiri, tanpa refactor kalender global.

Standar normatif [WCAG 2.2](https://www.w3.org/TR/WCAG22/): 2.1.1 keyboard, 2.4.7 focus visible, 2.4.11 focus not entirely obscured, 2.5.7 alternatif single-pointer untuk drag, 2.5.8 target size, 1.4.3/1.4.11 contrast, 1.4.10 reflow. Focus Appearance 2.4.13 dan 44px target 2.5.5 adalah AAA, bukan klaim AA. Local accessibility skill mencampur beberapa nomor ini; dokumen memakai klasifikasi normatif. Tidak ada klaim aplikasi telah conformant dari inspeksi ini.

## E. Integration Map

| File Gate 2 | Perubahan terkecil / boundary |
|---|---|
| Baru resources/js/export-column-designer.js | Pure initialization/validation/transitions/serialization + scoped view/lifecycle. Tidak fetch, mengetahui route, menyimpan preset, atau dispatch. Tidak mengakses DOM di top-level module. |
| resources/js/advanced-export.js | Ganti keys()/columns()/per-row listeners dengan API module; root lifecycle, epochs/busy tokens; tetap owner filters, format, preset transport dan SDK. bootFormats terpisah tetap berlaku. |
| resources/views/components/export/advanced-modal.blade.php | Ganti list/template kolom dengan ribbon/listbox/empty rows/hidden panel/menu layer; scoped modal sizing. Preserve form/root/trigger/filter/format/preset hooks. Semua text via copy berpasangan. |
| resources/css/app.css | Satu blok scoped pada designer/Tier-1 modal untuk worksheet, states, layer dan responsive. Jangan mengubah global Bootstrap/modal/calendar styles atau dirty hunks existing. |
| lang/en/exports.php dan lang/id/exports.php | Tambah key action/hints/status/disabled/cancel/reinsertion; perluas payload copy Blade existing. Tidak membuat kamus/global i18n paralel. |
| Baru tests/js/export-column-state.test.mjs | Pure invariants, literal difficult sequences, letters dan generated sequences reproducible. |
| Baru tests/js/advanced-export-columns.test.mjs | Integrasi module/controller dengan controlled responses: serialization, dual roots, race, cancel, cleanup. DOM doubles tidak dihitung sebagai bukti browser. |
| tests/js/advanced-export-format.test.mjs | Adaptasi harness agar meng-import ES module nyata dengan globals terisolasi (misalnya child process), karena static import baru tidak dapat diparse oleh vm.runInNewContext existing. Jangan menghapus import dari source agar tes lolos. |
| tests/Feature/AdvancedExportUiTest.php | Ubah assertion vertical up/down yang sengaja diganti, uji structure/copy/hooks baru en/id tanpa menimpa perubahan count 12 milik pengguna. |
| tests/Feature/AdvancedPurchaseOrderExportTest.php | Bila coverage existing belum menyamakan kedua format, tambah ordered fixture yang sama untuk actual XLSX/CSV headings + mapped cells. Reuse preset/security/regression suites existing. |

Tidak diperlukan perubahan app.js, Vite/package manifests, route, controller PHP, definition/resolver, service preset, writer/job, skema, maupun auth splash. data-export-columns dapat dipertahankan sebagai host hook; child structure lama memang diganti dan seluruh consumer yang ditemukan harus diperbarui bersama. ID/selector lain tetap kompatibel. Source labels/warnings/preset names dirender melalui textContent, bukan interpolasi HTML; stable key yang tervalidasi dipakai untuk identity.

## F. Implementation Sequence

Urutan ini adalah proposal sesudah persetujuan Gate 2, bukan pekerjaan yang sudah dilakukan.

| Tahap | Pekerjaan | Kondisi selesai terukur |
|---|---|---|
| 0. Freeze + baseline | Recheck dirty tree/hash, jalur browser dan actor test. Ambil baseline existing sesuai G3 sebelum edit. Pastikan lingkungan DB test aman/eksklusif sebelum feature suite. | Baseline/artifact tersimpan; limitation yang tersisa eksplisit; tidak menyentuh auth splash atau data aplikasi. |
| 1. State contract | Tulis tes C1–C3 terlebih dahulu, kemudian pure model dalam modul. | Semua prefix sequence memenuhi invariants; literal expected outputs dan serialize identik; 0 DOM/network dependency. |
| 2. Worksheet non-drag end-to-end | Integrasikan host, keyboard/ribbon/Unhide/Reset/preset/serialization dengan controller existing. | Hide/Move/Unhide/Save/Quick menggunakan state sama; format-only/filters/422 tetap bekerja; integration race tests lulus. |
| 3. Drag + layers/lifecycle | Tambah drag preview, midpoint/auto-scroll/menu dan cleanup scoped. | Browser drag/drop/cancel/scroll, first-Escape, zoom/menu clipping, dua root dan repeated open/close memenuhi G. |
| 4. Output + regression + review | Jalankan suite relevan secara serial, actual files, localization/build/view checks, perf comparison dan pilot usability bila tersedia. | Semua correctness/security/output gates lulus; bukti browser disimpan; pengguna menerima trade-off usability yang tersisa. Tidak mengklaim studi yang belum dilakukan. |

Apabila browser masih tidak tersedia pada tahap 0, model murni dapat dikerjakan setelah persetujuan, tetapi timing/layout/interaction gate tetap terbuka dan tidak boleh dianggap lulus sebelum bukti diperoleh. Tidak perlu menunggu studi pengguna untuk menyusun Gate 1 ini.

## G. Independent Verification Matrix

### G1. Correctness, backend dan interaction

Semua acceptance berikut adalah proposal. Status saat ini hanya “source inspected”, “executed baseline”, atau “UNVERIFIED”; belum ada implementasi designer untuk diluluskan.

| Requirement/decision | Metode dan observable expected result | Artifact / pass-fail | Status kini |
|---|---|---|---|
| O+V invariants | Pure Node tests setelah setiap operasi; unknown/duplicate/required hide/last hide ditolak sebelum commit. | TAP + literal snapshots C3; satu invariant gagal = fail. | UNVERIFIED designer |
| Sequences/reset/letters | Exhaustive short sequences pada katalog kecil; seeded generated operations pada 24/64 key; A/Z/AA/BL; required-default inconsistency dan empty catalog. | Seed/prefix gagal dapat direplay; O permutation, R⊆V, serialize=W pada semua prefix. | UNVERIFIED |
| Hidden persistence | Hide/move/save/apply/Unhide diuji dengan O dan hasil exact C3. | Visible roundtrip identik; hidden order canonicalized eksplisit, tidak ada field baru dalam preset JSON. | Backend source inspected |
| Async races | Deferred GET/save/Quick: close/reopen, respons terbalik, invalid catalog/preset, dua roots, failure/finally lama. | Hanya intent current mengubah UI; tidak ada dispatch ganda, stale reopen atau enable baru. | UNVERIFIED |
| Authorized columns/required | Existing AdvancedPurchaseOrderExportTest, ExportPresetTest, AdvancedExportRollout/PhaseFiveB/PhaseSix; forged audience/key/foreign preset. | 403/404/422 existing sesuai boundary; urutan required yang dipindah tetap valid. | Pure PHP probe passed; HTTP suites belum dijalankan |
| Default vs all | PO actual 12/10; Reset mempertahankan dua opt-in hidden; filters/format tetap. | Exact W=defaultKeys, bukan availableKeys. | Catalog probe passed; UI UNVERIFIED |
| Format-only/SDK | Ulang tiga suite JS baseline dan PhaseSix test. | Tidak ada worksheet/preset/columns pada format-only; existing request/monitoring tetap. | 11 JS baseline passed |
| Output authority | Dari browser rekam W dan business labels yang tampil saat dispatch. Download file melalui lifecycle existing; baca row 1 XLSX dengan PhpSpreadsheet, CSV via parser (hapus hanya BOM). Uji fixture dengan mapped cell unik. | Exact heading count/order/labels = UI W mapping dan golden literal; values sejajar dengan headers; bukan sekadar inspecting payload. | UNVERIFIED actual file bridge |
| Semua audience/locale/format | Matrix 9 Tier-1 keys × en/id × XLSX/CSV; reuse golden tests existing. Browser journeys boleh representatif PO/24-column quotations/16-column local invoices, backend seluruh matrix. | Required/order/default/locale benar pada setiap kombinasi; tidak mengandalkan locale worker sebelumnya. | Source coverage inspected |
| Drag + scroll | Browser dari start/middle/end, before/after-last, kedua arah, outside drop, Escape, reset saat drag, reopen. | W committed berubah tepat sekali hanya pada drop sah; cancel identik dengan before; V tetap. Video + state/payload assertion. | UNVERIFIED |
| Keyboard/touch | Listbox arrows/Home/End/type-ahead; ribbon; context key; pan mobile; tap Move/Unhide; required disabled reason. | Lima representative tasks selesai tanpa right-click/drag; focus dan selected state diumumkan NVDA/Chrome. | UNVERIFIED; bukan lulus dari ARIA source |
| Menu/focus/modal | Buka menu di tepi viewport/scroll, first Escape, Tab exit, close→opener, Quick failure, kalender filter existing. | Menu tidak clipped/di luar trap; no orphan focus/backdrop; second Escape close. Screenshots + focus logs. | UNVERIFIED |
| Theme/reflow | Light/dark, 1440px/768px/390px, 1280px pada browser zoom 200%/400% (viewport efektif ~320px). | Semua aksi reachable, full labels available, footer/focus tidak hilang; scroll horizontal hanya worksheet, control area reflows. Ukur contrast terhadap surface aktual. | UNVERIFIED |

Output test tidak boleh berhenti pada headings() PHP atau snapshot frontend. CSV menggunakan koma/BOM sesuai config, XLSX dan CSV harus menghasilkan urutan sama untuk W yang sama, dan empty rows visual tidak ikut output. Security/row limit/queue lifecycle existing tetap diuji; tidak perlu benchmark 100.000 record hanya untuk membuktikan header interaction.

### G2. Perintah verifikasi Gate 2

Sebelum DB-backed tests: inspeksi effective testing config/cached config/DB_URL dan proses/lock lain; lalu jalankan TestingEnvironmentDatabaseSafetyTest (live SELECT DATABASE()). Jangan mengganggu server/suite test sesi lain. Jalankan feature suite hanya serial pada database test yang sudah disetujui; tidak menjalankan migrate pada DB aplikasi.

    php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
    php artisan test --filter='AdvancedExport|AdvancedPurchaseOrderExport|ExportPreset'
    php artisan test --filter=TranslationParityTest
    node --test tests/js/export-column-state.test.mjs tests/js/advanced-export-columns.test.mjs tests/js/advanced-export-format.test.mjs tests/js/advanced-export-sdk.test.mjs tests/js/advanced-export-csv-timeout.test.mjs
    npm.cmd run build
    php artisan view:cache
    git diff --check -- <file yang benar-benar disentuh>

Filter gabungan menghindari menjalankan suite export yang sama dua kali. Jalankan syntax/Pint scoped untuk PHP test yang berubah. SupplierDataIsolation/LocalInvoiceScopeIsolation dipilih bila pemeriksaan diff menemukan perubahan boundary, bukan otomatis mengubah boundary tersebut. Full suite/staging/production bukan klaim dari focused checks ini.

### G3. Performance: baseline, metode dan ambang

Timing browser belum tersedia; tidak ada angka modal-open/reorder yang boleh dikarang. Sebelum implementasi, rekam tiga batch × 30 warm iterations sesudah warm-up pada mesin/browser/viewport/catalog yang sama: catalog PO 12, quotation 24, dan fixture metadata 64 tanpa records. Catat cold network terpisah. Ukur click→shown, show→data-ready, data-ready→paint, command/drop→paint, DOM descendants, requests, event listeners dan heap/retained nodes. Pisahkan transisi Bootstrap dan latensi dua GET dari waktu designer.

| Area | Pass/fail repeatable untuk Gate 2 |
|---|---|
| Init/open/reorder | Untuk setiap metrik, B = median p95 tiga batch baseline; J = rentang p95 antar batch baseline. Gate 2 p95 ≤ B+J dalam run berpasangan; bila noise terlalu besar untuk keputusan, stabilkan run dan jangan klaim pass. Kenaikan melampaui envelope harus dijelaskan/diperbaiki, bukan disembunyikan oleh waktu network. Baseline ms dan ambang numerik dibekukan sebelum edit. |
| Requests | Opening normal tetap 1 definition GET + 1 preset GET per intent, tidak bertambah oleh designer. Hide/Move/Unhide/Reset/drag = 0 request. Tidak ada data-preview request. Preset CRUD dan dispatch tetap endpoint existing. |
| DOM | N adalah jumlah authorized keys. Dengan satu option + maksimal 3 child label spans/header, tiga empty cells/visible column, dan satu native option/hidden key: descendants designer ≤ B_fixed + 7N. B_fixed diukur dari chrome/ribbon/layer tetap. Tidak bertumbuh pada reopen; tidak butuh virtualization untuk 24/64. |
| Bundle | Catat total raw/gzip build JS/CSS sebelum/sesudah, termasuk semua chunks. Tidak ada package/CDN/request asset baru akibat static module. Delta gzip JS yang melebihi ukuran seluruh controller existing (3.657 bytes) menjadi fail untuk review ukuran sampai ada alasan terukur; angka ini budget review relatif fitur existing, bukan SLA transfer/performance. CSS harus scoped dan delta dilaporkan. |
| Listener/memory | Setelah 30 dan 60 open/close: listener count root/global kembali baseline, 0 open layers dan 0 active auto-scroll rAF saat hidden, hanya 1 instance/root. Setelah destroy: 0 retained designer roots/menus pada heap snapshot setelah GC. Byte heap noise dicatat; finite-run evidence tidak disebut bukti universal “no leaks”. |

Untuk pengukuran responsive/dark, gunakan user preference test actor atau preview ephemeral, tanpa mengubah preferensi/data user aplikasi. Browser screenshots/trace/JSON/TAP hasil Gate 2 disimpan di UI-REDESIGN-RESULT/export-column-designer/ (sesuai aturan hasil UI), dengan viewport, zoom, locale, theme, catalog size dan commit/tree fingerprint. Baseline bytes A3 sudah **VERIFIED-EXECUTION**; semua latency/DOM/listener/memory thresholds di atas masih **UNVERIFIED** sampai baseline browser diisi.

### G4. Representative-user task study

Pilot within-subject 4–6 operator representatif Purchasing, Supplier dan Finance/QC; counterbalance urutan existing/redesigned, catalog serta tugas sama. Tidak ada prototype/partisipan yang tersedia pada Gate 1, sehingga studi **belum dilakukan**.

| Tugas | Baseline konseptual existing → hipotesis designer |
|---|---|
| Hide 3 optional tersebar | 3 checkbox toggles → repeated select+Hide bisa membutuhkan lebih banyak aksi. Kemiripan Excel bukan bukti lebih cepat. |
| Reorder beberapa visible | Vertical drag/Up/Down → horizontal drag/Move dengan risiko auto-scroll/precision. |
| Restore hidden ke posisi yang diperkirakan | Checkbox pada row existing → Unhide picker dengan posisi prospektif; minta pengguna menyebut posisi sebelum Restore. |
| Save lalu apply preset | Alur form existing dipertahankan; hasil W harus identik setelah apply. |
| Export dan periksa urutan file | Download actual file dan bandingkan business headings; catat configuration time, queue wait dan file-verification time terpisah. |

Ukur success tanpa bantuan, completion time per tugas, salah-target/salah-posisi, retry/cancel, jumlah aksi dan kebingungan hidden vs selected. Salah output, required hilang, atau dead end keyboard/touch = fail correctness. Untuk usability, bandingkan paired success dan median waktu/error dengan existing; penurunan success atau kenaikan waktu di luar variasi pengulangan baseline menuntut revisi interaksi sebelum acceptance. Laporkan per tugas, jangan menutupi regresi Hide dengan rata-rata tugas lain. Pilot kecil memberi sinyal desain, bukan klaim signifikansi statistik.

## H. Risks and Open Decisions

| Risiko material | Dampak | Resolusi rekomendasi dan bukti penentu |
|---|---|---|
| Browser baseline tidak tersedia pada audit | Actual clipping/focus/timing belum diketahui; pilihan CSS/layer bisa perlu koreksi. | Tahap 0 merekam modal existing, kemudian G1/G3. Tidak mengklaim browser pass dari source. |
| Hidden positions tidak persisten | Restore setelah save/reload dapat berbeda dari sebelum save. | Pertahankan kontrak, canonical suffix + teks preset singkat dan tes C3; perubahan persistence memerlukan scope baru. |
| Repeated Hide mungkin lebih lambat daripada checkbox | Excel appearance dapat menurunkan efisiensi tugas tertentu. | Pilot G4 menentukan apakah single selection cukup; multiselect bukan default tambahan di Gate 1. |
| Async response/drag stale | Order/visibility salah atau modal terbuka kembali. | Epoch/revision/operation ownership dan deferred-response tests sebelum browser polish. |
| Native drag pada scroll/browser tertentu | Drop sulit atau target tidak tepat. | Midpoint+single rAF, tombol ekuivalen; keputusan dependency hanya berubah setelah kegagalan terukur. |
| Filter calendar memiliki layer/focus sendiri | Regressi fokus dapat muncul meski worksheet benar. | Bandingkan kalender modal existing vs hasil baru; pertahankan behavior, laporkan baseline defect terpisah bila ditemukan. |

Tidak ada pertanyaan bisnis yang menghalangi keputusan arsitektur sekarang. Kesiapan: keputusan state/integrasi cukup konkret untuk implementasi setelah persetujuan; browser/output/performance/usability tetap memerlukan bukti Gate 2. Pemeriksaan dokumen memvalidasi 31 referensi lokal beserta batas nomor baris, tidak menemukan replacement character, dan lulus diff whitespace check termasuk file baru. Fingerprint 254 file existing tetap identik dengan baseline A3. Gate 1 berhenti pada dokumen ini.

**FINAL STATUS: PENDING USER APPROVAL FOR GATE 2.**
