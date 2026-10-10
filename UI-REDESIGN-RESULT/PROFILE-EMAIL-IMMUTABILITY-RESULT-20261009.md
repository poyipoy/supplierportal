# Profile dan Email Login Tetap — Hasil Implementasi

Tanggal: 9 Oktober 2026. Lingkungan: working tree lokal; belum diverifikasi melalui browser, staging, atau produksi.

## Perubahan

- Email login ditetapkan saat pembuatan akun. Profile dan admin tidak dapat menggantinya. Request update tanpa email atau dengan email tersimpan yang identik tetap diterima; email berbeda ditolak sebelum perubahan lain disimpan, baik untuk respons HTML maupun JSON 422.
- Profile hanya menyimpan Nama Tampilan. Nama tidak mengubah master perusahaan/PIC, role, scope, password, metadata verifikasi email, atau sesi.
- Guard `User::updating` menolak perubahan email melalui update/save model, termasuk `forceFill()->save()`. Pembuatan akun tetap menetapkan email. Tidak ada migrasi, package, atau perubahan route.
- Profile menampilkan Email Login, role, tanggal akun dibuat menurut preferensi regional, scope Import/Local khusus supplier, dan status 2FA dengan tautan ke Security. Ringkasan memakai identitas akun tersimpan, bukan email dari request gagal.
- Form admin menampilkan email sebagai informasi baca saja. Form Profile memakai komponen tombol, submit/loading guard, toast penyimpanan, dan pengingat perubahan belum disimpan yang sudah tersedia. Teks baru berpasangan pada `lang/en/profile.php` dan `lang/id/profile.php`.
- Aturan dicatat pada bagian Auth di `AGENTS.md`. Shell pengaturan akun dan perubahan pengguna yang sudah ada dipertahankan.

## Bukti verifikasi

| Pemeriksaan | Hasil |
|---|---|
| `TestingEnvironmentDatabaseSafetyTest` | Lulus: 2 tes, 4 assertion; koneksi aktual ke `adasi_portal_test` |
| Regresi Profile, email tetap, Security, account navigation, session security, verification/reset route compatibility, seluruh SupplierRegistration, SupplierVendorProfile | Lulus: 120 tes, 991 assertion; serial pada database test, 51,83 detik |
| TranslationParity, TranslationContent, UserFacingCopyInventory | 46 tes lulus, 1 gagal; kegagalan hanya pencocokan ledger historis Phase 7 terhadap working tree saat ini |
| JavaScript unsaved changes | Lulus: 2 tes dengan `node --test --test-isolation=none tests/js/unsaved-changes.test.mjs` |
| PHP lint | Lulus pada seluruh PHP/translation/test yang disentuh, termasuk controller admin |
| Scoped Pint | Lulus pada model, request, Profile controller, provider, terjemahan, dan tes. Controller admin memiliki temuan formatting yang juga direproduksi pada sumber HEAD yang belum diubah |
| `php artisan view:cache` | Lulus |
| `npm.cmd run build` | Lulus setelah menjalankan build dengan izin proses yang sesuai; sandbox awal memblokir esbuild dengan `spawn EPERM`. Warning existing asset logo tidak diselesaikan saat build |
| `route:list --path=profile` | Berhasil; endpoint Profile/akun existing tetap tersedia. File route tidak diedit oleh task ini |
| Scoped `git diff --check` | Lulus pada seluruh file task |
| Global `git diff --check` | Gagal pada whitespace `context.md` dan baris kosong akhir `resources/css/app.css`, di luar perubahan task ini; dibiarkan utuh |

Regresi mencakup request lama dengan email identik, request tanpa email, tampering HTML/JSON tanpa penyimpanan sebagian, guard save/update/forceFill, pembuatan user oleh admin, pemisahan Nama Tampilan dari perusahaan/PIC, locale en/id, supplier import/local/dual/tanpa scope, status 2FA, validasi nama, dan akses guest/non-admin.

Log eksekusi berada di `storage/logs/profile-regression-test.log` dan `storage/logs/profile-translation-test.log`. Run awal mengungkap fixture test yang perlu memakai nilai hasil refresh MySQL, default preferensi, dan memperhitungkan scope import otomatis dari UserFactory; fixture diperbaiki sebelum run regresi yang lulus. Run antara juga mengalami gangguan tabel migrations pada database test; hasil tersebut tidak dipakai sebagai bukti kelulusan.

## Batas dan pemeriksaan lanjutan

- Browser QA belum diverifikasi: `cua.getState()` mengembalikan daftar browser kosong. Reflow desktop/mobile, navigasi anchor 2FA, loading tombol, dan dialog perubahan belum disimpan masih membutuhkan pemeriksaan browser. HTTP rendering kedua bahasa telah diuji.
- Snapshot `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl` tidak ditulis ulang karena `AGENTS.md` melarang perubahan snapshot audit historis sebagai efek samping task lain. Satu tes ledger tetap gagal; tidak diklaim sebagai kelulusan dan tidak diasumsikan sebagai failure baseline yang sudah dibuktikan.
- Email PIC tetap mengikuti workflow Vendor Master. SQL langsung, query-builder bulk update, atau save yang menonaktifkan model events dapat melewati guard model; perlindungan ini berada pada jalur aplikasi yang diinspeksi dan save model dengan events aktif, bukan trigger database. `AGENTS.md` melarang penambahan jalur aplikasi yang melewati guard email.
- Tidak ada commit, push, atau mutasi database aplikasi. Pengujian menggunakan database test; tidak ada klaim kesiapan staging/produksi.
