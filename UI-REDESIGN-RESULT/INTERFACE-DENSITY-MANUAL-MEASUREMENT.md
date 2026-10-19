# Interface Density — prosedur verifikasi lanjutan

Izin user untuk login actor QA dan pengujian preferensi sudah diberikan. Jangan meminta izin yang sama lagi. Semua pengujian tulis tetap terbatas pada database test; tidak menjalankan business action.

## Menyiapkan sesi stabil

1. Pastikan tidak ada suite RefreshDatabase lain memakai `adasi_portal_test`. Pemeriksaan read-only: `Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'phpunit|artisan test' }`. Jangan menghentikan proses pengguna.
2. Jalankan `php tests/Support/density-browser-fixture.php`: effective/live DB harus `adasi_portal_test`, density column varchar20. Jika identitas berbeda, berhenti.
3. Setelah DB idle, `php tests/Support/density-browser-fixture.php --prepare` menyiapkan9 actor sintetis pada test DB. Kredensial disposable tercantum di helper test tersebut; jangan memakai akun/data operasional.
4. Server QA existing memakai `php -S 127.0.0.1:8129 -t public tests/Support/density-browser-router.php`. Periksa port sebelum menjalankan; jangan membuat server konflik. Environment file tidak perlu diubah. Helper hanya untuk loopback QA, bukan route produksi.

## Mengukur browser

Collector read-only: `tests/Support/interface-density-devtools-collector.js`. Buka `/__density-components-public` (metadata komponen) atau login normal dan buka `/profile/customization`/`/__density-components`.

- Gunakan Chrome,1440×900 lalu1280×800,768×1024,390×844,320 CSS px; catat DPR, zoom, locale/theme, build dan actor. Tunggu `document.fonts.ready`; catat font computed sebenarnya, jangan menganggap Inter aktif.
- Pertahankan25 row fixture, data, filter, sort, dan page size. Pilih tiap density melalui UI tanpa Save; jalankan collector dan simpan JSON baru, jangan menimpa baseline historis.
- Di Elements/Styles, periksa sel teks/aksi, toolbar, card body, form body, sidebar. Rekam winner padding/gap/height serta stylesheet. Collector matched declarations tidak selalu menentukan winner stylesheet cross-origin.
- Screenshot slider beserta mini preview dan aksi. Jangan merekam password, token, rekening atau data bisnis nyata.
- `tests/Support/density-browser-measure.js` adalah fungsi async untuk evaluasi pada Customization **QA8129 saja**. Berbeda dari collector read-only, fungsi ini menggerakkan preview melalui adapter produksi, mengukur30 input, memantau fetch/XHR/storage/draw/longtask, lalu mengakhiri pada Comfortable. Tidak submit/persist. Lakukan Discard setelahnya bila saved bukan Comfortable.
- Hindari smooth scroll yang masih berjalan saat memulai pengukuran. Helper measurement menstabilkan scroll dan merekam scrollY. Bandingkan posisi/ukuran pada scroll yang identik.
- Ambang: adjacent text-row≥2px; body25 action rows Dense vs Comfortable≥15%; slider/output/Save/Discard/frame±1px; p95≤100ms; nol request/storage/draw density; tidak ada longtask baru>50ms.

## Bukti yang tetap memerlukan pengujian lanjutan

- Login8 audience: admin, purchasing, finance, accounting, qc, ga, supplier import, supplier local. Buka dashboard+list, lalu form/detail/modal representatif dengan data sintetis. Ukur kelima level melalui preview API/UI; jangan submit approval/destructive/business actions. DataTables: setelah initial draw selesai, monitor draw.dt dan Network saat density berubah; record/pageLength/filter/sort harus tetap.
- Native Home/End/arrows, Tab/Shift+Tab, tap/drag pada perangkat sentuh. Periksa range44px dan essential mobile44px, critical controls tidak mengecil.
- NVDA+Chrome desktop dan VoiceOver/iOS atau TalkBack/Android: nama, role, level/posisi terucap satu kali, gesture mengubah5 stop, tidak terjebak keyboard. DOM aria-valuetext atau AX snapshot saja tidak membuktikan ucapan AT.
- Chrome tanpa JavaScript: select5 string tampil dan submit canonical; range disabled/hidden. Save melalui actor test; jangan menyamakan assertion HTML dengan pengujian browser JS-off.
- Zoom200% dan400%, reflow320px, pesan error panjang, semua aksen light/dark, fokus di atas sticky bar; scroll horizontal hanya tabel intrinsik.
- Bfcache nyata harus dibuktikan dengan pageshow.persisted=true. History biasa back_forward sudah punya coverage terpisah; jangan melabelnya bfcache.
- Bandingkan print preview/export dengan input sintetis identik serta protected editor, Advanced Export Designer dan auth splash. Jangan mengubah logika/desainnya.
- Pilot5 operator: setidaknya4 memahami arah, status belum disimpan, memilih dan mengembalikan level serta mengenali perbedaannya tanpa arahan.

Catat PASS / FAIL / BLOCKED / NOT TESTED per kriteria dalam laporan Gate2. Hasil JSON kosong/login, mock DOM, atau prediksi source bukan rendered acceptance.
