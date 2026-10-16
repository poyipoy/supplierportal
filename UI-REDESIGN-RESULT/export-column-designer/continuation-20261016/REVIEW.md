# Review lanjutan Gate 2 — 16 Oktober 2026

Review terbatas pada Excel-style column designer dan boundary export yang dipertahankan. Tidak ada audit seluruh repository atau persetujuan produksi.

## Review kode dan falsifikasi

Reviewer terpisah membaca instruksi proyek, state/view/controller, Blade/CSS, dan tes. Satu temuan P2 direproduksi di Chrome: document capture scroll menutup menu ketika isi menu itu sendiri digulir. Pada viewport coarse 390×180, menu mempunyai clientHeight 162 dan scrollHeight 236; scroll langsung menutupnya. Resize/ancestor scroll juga tidak memulihkan fokus ke invoker.

Perbaikan di `resources/js/export-column-designer.js` mengabaikan scroll yang berasal dari layer aktif dan memulihkan fokus ketika layer yang memiliki fokus ditutup oleh scroll luar/resize. [RED](browser-regression-red.json) dan [GREEN](browser-regression-green.json), serta [26 kasus pada aplikasi penuh](full-app-browser-flow.json), menyimpan bukti. Tidak ditemukan defect tambahan yang dapat direproduksi pada state, serializer, async fencing, preset, drag generation, atau Bootstrap disposal dalam cakupan review tersebut.

Fixture CSRF juga dikoreksi: regex pengganti token dummy kini menerima value kosong. Sebelumnya fixture tanpa session dapat meninggalkan token kosong. Payload browser setelah koreksi membuktikan header CSRF dan credentials same-origin; ini tidak mengganti proof middleware/HTTP dari feature tests.

Worksheet memakai `contain: layout paint` setelah eksperimen terisolasi menunjukkan penurunan readiness/komputasi pada katalog 24. Ini tidak mengubah scope menu yang tetap sibling dialog; drag/auto-scroll diuji ulang. Angka eksperimen bukan hasil acceptance dan tidak dipilih untuk menutupi kegagalan berikutnya.

## Review keamanan sumber

Reviewer keamanan terpisah tidak menemukan kandidat kerentanan baru yang reachable pada jalur yang diperiksa. Label katalog, nama preset, warnings, filter, dan error memakai `textContent`, `.value`, atau atribut ARIA. Key adalah identifier tervalidasi, CSS menerima angka, URL berasal dari Blade, dan ID preset di-encode. Blade `@json` memakai escaping framework yang terpasang.

Server tetap menentukan export key/audience/class. FormRequest/resolver/sanitizer mempertahankan allowed/required/distinct/order. Supplier dipaksa ke actor, preset di-owner-scope dan policy diulang di bawah lock. CSRF, private output/download, atomic queue handoff, worker reauthorization, row limit, dan formula sanitization tetap pada implementasi existing. Sebanyak 24 boundary files yang dibandingkan tidak berubah dari baseline Gate 2; export route declarations tidak berubah.

Ini **review sumber**, bukan runtime security approval. Skill security-audit dipakai dalam guidance mode; sandbox khusus audit eksploit tidak tersedia, sehingga reviewer tidak menjalankan PoC, parser, database, browser, atau network probe. Tes fitur normal yang diminta objective dijalankan root secara serial dalam database test milik task; hasilnya dilaporkan terpisah.

## Review ukuran bundle

Trigger numerik Gate 1 tetap **terlampaui**. [Frozen paired assets](paired-assets.json) dan [final assets](../final-assets.json) menunjukkan:

| Ukuran | Legacy | Designer | Delta |
|---|---:|---:|---:|
| Main JS raw | 164.141 | 180.004 | +15.863 bytes |
| Main JS gzip | 50.705 | 56.201 | **+5.496 bytes** |
| Seluruh lima JS assets gzip | 70.506 | 76.002 | +5.496 bytes |

Delta melewati review trigger 3.657 bytes sebesar 1.839 bytes, main JS gzip +10,84%. Manifest mempertahankan entry/chunk yang sama; tidak ada package, CDN atau request asset baru.

Reviewer mengukur region pada minified artifact: model/invariants/transitions/serializer/letters 3.372 raw bytes; view/interaksi/cleanup 11.634; controller/format/boot net +726; integrasi/rename bundle +131. Jumlahnya sama dengan delta raw 15.863. Kontribusi gzip per fungsi tidak dijumlahkan karena compression context dibagi.

**Disposisi technical size review: diterima dengan alasan terukur.** Region terbesar melaksanakan requirement eksplisit: keyed projection, Unhide, keyboard/typeahead, menu/focus, drag/auto-scroll, revision cancellation, dan cleanup. Tidak ditemukan subsystem unused yang material atau state alternatif ganda. Menghapus validasi atau akses keyboard/cleanup akan mengurangi requirement. `operation.kind` yang tidak dipakai hanya memberi penghematan kecil dan dibiarkan untuk menghindari churn tanpa dampak material. Ini bukan klaim under-budget, waiver latency, atau persetujuan Gate E penuh.

## Performa dan keabsahan pengukuran

Harness awal menghapus `.fade` setelah Bootstrap instance dibentuk, sementara backdrop animation sudah disimpan instance. [Reproduksi](measurement-backdrop-red.json) membuktikan dialog=false tetapi backdrop=true. Helper diperbaiki dengan dispose instance lama, menghapus fade, lalu membuat instance baru dan memeriksa kedua flag false.

Two-rAF endpoint juga bukan akhir renderer Paint: rAF berjalan sebelum rendering. [Trace diagnostik](paint-trace-comparison.json) menunjukkan waktu Paint Move designer lebih rendah meskipun waktu tunggu dua-rAF dapat lebih panjang. Rujukan primer: [HTML rendering lifecycle](https://html.spec.whatwg.org/multipage/webappapis.html#update-the-rendering), [CDP Tracing](https://chromedevtools.github.io/devtools-protocol/tot/Tracing/).

Seri actual Paint pertama 15/15 PASS telah dihitung ulang reviewer langsung dari 540 row/18 compressed traces: hash, p95, B/J dan median cocok; Paint berada pada main thread dan frame fixture yang sama. Reviewer mengingatkan bahwa last Paint dibatasi observation window, DrawFrame berikutnya tidak dikorelasikan ke frame, dan pengambilan baseline dulu dapat bias host/JIT. Seri checked kemudian memeriksa `dataLossOccurred=false`, mendapatkan 14/15 PASS, dan replay legacy membuktikan drift.

[Protocol final](counterbalanced-trace-protocol.json) dibekukan sebelum Chrome diluncurkan: 18 trial, 30 measured iterations + 3 warmups, urutan legacy/designer dibalik pada batch tengah, loader benar-benar idle, sources/hash tetap. Startup pertama berhenti sebelum trial karena Windows sandbox menolak Chrome IPC. [Blocker startup](counterbalanced-launch-blocker.json) disimpan; run dengan izin eksekusi yang sesuai memakai protocol/threshold yang sama, tanpa mengubah hasil terukur apa pun.

**Hasil final:** [comparison](counterbalanced-trace-comparison.json) mempertahankan fixed threshold **FAIL 1/15**, dan paired diagnostic **PASS 15/15**. Paint 24: actual 9,570 ms vs fixed B+J 7,915 ms. Readiness, command computation, command-to-Paint, dan open-to-Paint lulus semua ukuran. Sebelum/sesudah semua trial: loader inactive, pending 0, 66 metadata calls, 0 leftover layers/backdrops, dan dataLossOccurred=false. Hasil fail dan drift tetap tersimpan; tidak ada capture tambahan untuk mencari hasil lulus.

**Acceptance performa tetap terbuka.** Perlu replay protocol/budget yang sama pada sesi/mesin yang dapat dijaga dari pekerjaan paralel. Capture ini membuktikan kondisi lokal terukur, bukan SLA produksi atau presentation ke layar fisik.

## Browser dan batas remaining

Actual layout zoom 100/200/400 diuji dalam profil Chrome milik task: outer 1280×900 tetap, innerWidth 1262→631→315, DPR 1→2→4, visualViewport.scale=1. [Capture awal](cdp-browser-zoom.json) dan [capture final ID sesudah CSS containment](final-cdp-browser-zoom.json) membuktikan controls/menu/footer reachable. Ini zoom browser aktual; bukan viewport equivalence atau pinch-scale. Mekanisme memakai [native Chromium zoom preference](https://chromium.googlesource.com/chromium/src/+/lkgr/chrome/browser/ui/zoom/chrome_zoom_level_prefs.cc), tanpa mengubah profil pengguna.

[Retained-root proof](cdp-retained-roots.json): sesudah 60 siklus normal fade, destroy/remove/clear global handle dan dua forced GC, tiga calibrated DOM wrappers turun menjadi nol pada actual heap snapshots; seluruh WeakRef clear. Ini finite-run evidence, bukan jaminan universal no leaks.

Kalender filter mempunyai defect existing: panel dipindahkan ke body dan Bootstrap mengembalikan fokus ke close button. [Legacy](calendar-legacy.json) dan [current](calendar-current.json) mereproduksi defect yang sama; pointer Next/Today tetap memperbarui input. Komponen kalender bersama tidak diubah di luar scope.

Actual screen reader, physical touch device, browser lain dan studi operator **NOT TESTED**. Tools yang tersedia tidak menyediakan connected screen reader/partisipan. Bukti keyboard/AX dan browser synthetic tidak diberi label human usability/WCAG conformance.
