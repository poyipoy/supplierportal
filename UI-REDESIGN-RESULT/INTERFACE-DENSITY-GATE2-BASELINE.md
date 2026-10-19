# Interface Density — Gate 2 Stage 0

Tanggal: 19 Oktober 2026. Branch master, HEAD `65e61f15cccdb562d5ae2616f6835efd31451561`.

**VERIFIED-EXECUTION:** baseline fixture komponen1440×900, pasangan mobile390×844, dan halaman Customization authenticated1440×900, dikumpulkan sebelum token/shared-component/slider diubah.

## Lingkungan dan provenance

Chrome155 Windows, zoom visual1, root font16px, locale en/light. Font computed sebenarnya system-ui/Segoe UI, bukan Inter (stylesheet Inter ada tetapi bukan font body aktif). Bootstrap5.3.3 dan Vite CSS aplikasi aktif.

Server QA loopback `127.0.0.1:8129`; bootstrap memverifikasi APP_ENV=testing dan live SELECT DATABASE()=`adasi_portal_test`. Tidak mengubah environment file atau database aplikasi.

Route `/__density-components-public` merender shared Blade produksi dengan label sintetis,25 text rows dan25 action rows identik. Layout metadata fixture bukan bukti operasional lintas-role. Halaman `/profile/customization` memakai login normal actor admin sintetis. ID actor bisa berubah saat suite concurrent mereset database uji.

Collector `tests/Support/interface-density-devtools-collector.js` hanya membaca computed styles/geometry, tanpa cookie/token/storage, nilai bisnis, submit, atau network request. Adapter fixture mengganti root density sementara.

## Hasil sebelum perubahan

| Sampel | Comfortable | Compact | Temuan |
|---|---:|---:|---|
| Text row kedua | 41,8px | 41,8px | Identik |
| Body25 text rows | 1045,4px | 1045,4px | Identik |
| Action row kedua | 54,8px | 54,8px | Konten tombol + padding |
| Body25 action rows | 1370,4px | 1370,4px | Identik |
| Card | 112px | 112px | Body padding20px tetap |
| Metric | 84px | 84px | Padding16px tetap |
| Toolbar | 84,6px | 72,6px | Padding14px versus8px/12px |
| Sidebar desktop | 40px | 37px | Minimum compact36px dibatasi konten |
| Sidebar mobile | 44px | 44px | Minimum mobile |
| Page gutter | 20px/24px | 20px/24px | Belum ikut density |

Row pertama berbeda sekitar0,4px karena collapsed border/subpixel. JSON menyimpan rect asli.

Winning declaration dikonfirmasi DevTools: `.table td` padding important10px/14px mengalahkan selector compact; row minimum tidak mengurangi content+padding. Fixed Tailwind padding/gap pada card, metric, form, dan page belum terhubung dengan density.

Customization lama: radio hidden1×1px, actionbar x528,y835,2,w872,8,h64,8. Ini bukan ukuran slider baru.

## Artifact

Direktori [interface-density](interface-density/):

- `baseline-comfortable-1440.json`, `baseline-compact-1440.json`: computed styles, rect, matched selector metadata.
- `baseline-mobile-390.json`: pasangan mobile.
- `baseline-customization-1440.json`: Customization authenticated sebelum UI baru.
- `baseline-css-winner.txt`: matched/winning declaration DevTools.
- `baseline-comfortable-1440.png`: screenshot shared components.

## Diagnosis browser dan keterbatasan

CUA awal mengembalikan inventory kosong/No browser available. Itu kegagalan provider CUA; Chrome/Edge lokal dan aplikasi sebenarnya tersedia. Integrasi ECC Chrome DevTools kemudian berhasil merender Chrome155 dalam isolated context. Layanan automatic review sempat at capacity, lalu pulih; izin autentikasi user tetap berlaku dan login normal berhasil.

Suite concurrent melakukan RefreshDatabase sehingga sesi fixture kadang hilang. Proses pengguna tidak dihentikan; cookie/session tidak dimanipulasi untuk melewati login.

Baseline ini valid untuk kalibrasi komponen yang diukur. Baseline historis setiap dashboard/role, DataTables berisi data panjang, viewport1280, dan tablet768 **tidak direkam sebelum perubahan**. Verifikasi hasil akhir dilaporkan terpisah dalam [laporan Gate2](INTERFACE-DENSITY-GATE2-VERIFICATION.md).

Ambang Gate1 tidak berubah: adjacent text-row≥2px, Dense25 action-row reduction≥15%, restore/slider/actions/frame±1px, p95≤100ms, nol density-induced request/storage/draw; functional/touch minimum serta AT/pilot tetap berlaku.
