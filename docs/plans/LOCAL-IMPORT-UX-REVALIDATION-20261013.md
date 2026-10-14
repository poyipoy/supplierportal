# Modal import PO/GR: informasi dan pemeriksaan ulang

Rencana pengguna: memperjelas status/error/tindakan/sukses, menyediakan pemeriksaan ulang file private existing tanpa memilih file lagi, daftar berkelompok Sedang diproses/Perlu tindak lanjut, dan expiry tetap 24 jam sejak upload. Ringkasan/error ditampilkan lebih dahulu, preview lengkap setelahnya. Memakai style/tokens/Inter/Lucide/Bootstrap existing.

## Urutan implementasi dan kriteria

1. Tes HTTP dan service untuk revalidation owner-only, current eligibility, expected attempt, file/expiry/capacity, token lama, metadata, legacy token, dan whole-file no-write-before-confirm.
2. POST revalidate Finance/Purchasing via FormRequest/policy/CSRF/throttle; user/import/PO-GR locking selaras; queue handoff atomik; owner-job fencing dan original expiry; generation token plus fallback legacy sampai revalidation pertama.
3. Status/list additive: validation_attempt, can_revalidate, revalidate_url, expiry; opt-in recoverable list tanpa mengubah active=1. Counters memakai data baru valid dan baris error unik; error metadata terstruktur dan link remediation role-aware dengan batch lookup.
4. UI: context/progress selalu terlihat, kelompok proses, error-first summary, next-step copy, primary button state, recheck stored file versus replace changed file, one cancel per file, close does not cancel, existing slots/polling/limits unchanged.
5. Uji request bersamaan, stale tab/token/worker/response, commit/cleanup/cancel races; query/page/payload/memory bounded; EN/ID, keyboard/focus, dark/reduced motion/reflow. Tests DB serial; Pint, Blade, build, copy/translation, scoped diff; production verification separate.

Tidak ada package/migration/master overwrite/partial commit/infinite expiry extension. Tidak commit/push/merge/reset/clean atau migrasi DB aplikasi. Perubahan working tree sebelumnya dipertahankan. Security review dibatasi pada boundary yang berubah, bukan full-codebase audit.
