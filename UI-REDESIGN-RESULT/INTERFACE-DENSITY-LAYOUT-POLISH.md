# Interface Density — perapihan layout Customization

Tanggal:19 Oktober2026. Implementasi sesuai susunan yang disetujui: Theme / Sidebar Default / Rows per page di atas; Density baris penuh dengan slider kiri dan mini preview kanan; Accent setelahnya.

## Perubahan

- Runtime hanya `resources/views/profile/customization.blade.php` dan aturan CSS scoped `#customizationForm` di `resources/css/app.css`.
- Grid pengaturan singkat: auto-fit, minimum kolom16rem, otomatis3/2/1 kolom sesuai ruang. Density memakai container query640px, dua kolom sama lebar; di bawahnya ditumpuk.
- Border segmented Theme/Sidebar/Language memakai fit-content/max-width100%/justify-self:start. Desktop minimum36px existing dipertahankan, any-pointer coarse minimum44px.
- Label/ID/name/native range/select canonical, event adapter, token operational dan persistence tidak diubah. Mini tetap metric+toolbar+tabel3 row dan reserve Spacious; tanpa scale, clipping, font shrink atau scroll baru.
- Pekerjaan Dashboard Editor dan seluruh dirty files lain dipertahankan.

## Bukti rendered

Chrome155, loopback QA8129, actor admin sintetis; live database test diverifikasi `adasi_portal_test`, density varchar20. Login normal. Tidak membuat schema atau memodifikasi database aplikasi; pergantian locale melalui kontrol existing hanya actor test. Server dan tab QA milik task ditutup sesudah pengukuran.

| Viewport | Density sebelum | Sesudah | Hasil |
|---|---:|---:|---|
| 1440×900 | 652.8px | 398.8px | 38.9% lebih pendek |
| 1280×800 | 652.8px | 398.8px | 38.9% lebih pendek |
| 768×1024 | 616.8px | 398.8px | 35.3% lebih pendek |
| 390×844 | 652.8px | 656.8px | -0.6% lebih pendek |
| 320×844 | 692.8px | 696.8px | -0.6% lebih pendek |

Target≥30% desktop **PASS**:38,9%. Mobile mempertahankan susunan bertumpuk dan ukuran konten; tinggi sedikit bertambah4px karena gap antarkolom saat ditumpuk, bukan target pengurangan30%.

Border Theme desktop1440 sebelum menyisakan203,6px sesudah opsi terakhir; sesudah2,8px. Sidebar dan Language juga mengikuti isi. Overflow halaman0 pada1440/1280/768/390/320, en/id light/dark yang diuji. Touch option44px; semua5level minimum Save/Discard44px.

Five-level measurement pada seluruh5 viewport: max delta x/y/width/height slider/output/frame/Save/Discard/actionbar **0px**; p95 maksimum34ms;0 fetch/XHR,0 storage write,0 DataTables draw; focus retained. Reserve preview tetap369,8px pada sampel. Native End→Dense5, Home→Spacious1 lalu Right→Comfortable2/dirty0; aria-valuetext dan focus2,4px terukur. Ini bukan screenreader proof.

## Artifact

- `interface-density-layout/before.json`, `after.json`, `five-levels.json`, `indonesian-responsive.json`.
- [Desktop light](interface-density-layout/desktop-light.png), [mobile Bahasa Indonesia dark320px](interface-density-layout/mobile-id-dark.png).
- Source/stability inspection tidak menggantikan AT/actual zoom/pilot yang masih terbuka pada Gate2 sebelumnya. Perapihan ini tidak mengklaim menuntaskan acceptance Gate2 menyeluruh.

## Pemeriksaan

- JS preferences/customization/density contract: **PASS39 tests**.
- Database safety: **PASS2 tests/4 assertions**, live database test diverifikasi sebelum feature suite serial.
- Targeted PHP Customization+DashboardCustomization: **PASS36 tests/398 assertions**,113,12s. Tidak ada lock test DB pada pemeriksaan read-only saat proses berjalan; tidak menghentikan atau mengulang suite.
- Build final **PASS**, Vite7.3.6/33 modules/7,73s; CSS app-DELJjedA.css185,01kB, JS app-BFbr59ld.js195,26kB (JS unchanged). Blade cache final **PASS** setelah perapihan indentasi.
- Diff whitespace check lulus; tidak stage/commit/push/deploy.
