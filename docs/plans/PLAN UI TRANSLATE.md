# Full English UI Standardization Plan

## Summary
Convert all user-facing hardcoded Indonesian text to English across the application using direct replacement only. No new localization/lang-file system will be introduced. The standard term for `Permintaan Material` is **Purchase Requirement**.

## Key Changes
- Replace Indonesian text in all visible surfaces: Blade pages, sidebar/navbar, modals, SweetAlert prompts, chat drawer/full chat, notification popover/page, dashboard cards, table headers, badges/tooltips, empty states, buttons, placeholders, and helper text.
- Replace Indonesian server-side output: flash messages, validation messages, JSON error messages, notification titles/messages, status labels/descriptions, chat action labels/templates, and controller-generated DataTables HTML.
- Replace Indonesian document/export output: PDF labels, Excel headings/sheet titles, report labels, and export confirmation text.
- Replace DataTables Indonesian locale references (`i18n/id.json`) with English/default DataTables language so pagination/search/table labels are English.
- Keep technical identifiers unchanged: routes, method names, enum/status stored values, DB column names, model names, and role names.
- Do not translate user-entered or business data: supplier names, material names, notes, uploaded file names, announcement content already stored in DB, PR/PO numbers. For system-generated period labels, render English display text from month/year where possible instead of relying on Indonesian stored names.

## Implementation Details
- Apply direct string replacements in:
  - `resources/views/**` for visible UI and JavaScript text.
  - `app/Http/**` for flash, validation, JSON, notification, and DataTables strings.
  - `app/Support/**` for shared status/notification/chat labels.
  - `app/Exports/**` and `resources/views/pdf/**` for Excel/PDF output.
- Standardize core terminology:
  - `Permintaan Material` -> `Purchase Requirement`
  - `Penawaran` -> `Quotation`
  - `Klaim Material` -> `Material Claim`
  - `Inspeksi QC` -> `QC Inspection`
  - `Pengumuman` -> `Announcement`
  - `Kurs` -> `Exchange Rate`
  - `Riwayat Harga` -> `Price History`
  - `Perbandingan Harga` -> `Price Comparison`
  - `Masa Berlaku Penawaran` -> `Quotation Valid Until`
  - `Estimasi Pengiriman/Kedatangan` -> `Estimated Delivery/Arrival`
- Standardize common actions:
  - `Tambah` -> `Add`, `Simpan` -> `Save`, `Batal` -> `Cancel`, `Hapus` -> `Delete`, `Edit` -> `Edit`, `Detail` -> `Details`, `Kirim` -> `Send`, `Ajukan` -> `Submit`, `Konfirmasi` -> `Confirm`, `Tandai Semua Dibaca` -> `Mark All as Read`.
- Standardize statuses and badges:
  - `Diterima` -> `Accepted/Received` depending context.
  - `Ditolak` -> `Rejected`.
  - `Selesai` -> `Completed`.
  - `Menunggu QC` -> `Waiting for QC`.
  - `Kadaluarsa` -> `Expired`.
  - `Akan Kadaluarsa` -> `Expiring Soon`.
- After replacement, run targeted `rg` scans for remaining Indonesian UI terms and fix any remaining display strings.

## Test Plan
- Run syntax and compile checks:
  - `php -l` for changed PHP controllers/support/export files.
  - `php artisan view:cache`.
  - `php artisan test`.
  - `php artisan optimize:clear`.
- Run static text scan:
  - Search `resources/views`, `app/Http`, `app/Support`, `app/Exports`, and PDF views for Indonesian UI terms such as `Permintaan`, `Penawaran`, `Klaim`, `Inspeksi`, `Dokumen`, `Berhasil`, `Gagal`, `Batal`, `Simpan`, `Hapus`, `Tandai`, `Diterima`, `Ditolak`.
- Manual role sweep:
  - Admin: dashboard, users, exchange rates, announcements.
  - Purchasing: dashboard, periods, purchase requirements, quotations, comparison tabs, PO, claims, reports, chat, notifications.
  - Supplier: dashboard, quotation flow, PO, claims, price history, announcements, chat.
  - QC: dashboard, inspection create/index/show.
  - Auth/profile/password pages.
- Export checks:
  - Generate at least one Excel export and one PDF output to confirm headings and labels are English.

## Assumptions
- Use direct replacement only, per user choice; do not introduce `lang/` files.
- “All UI + outputs” includes screens, modals, JS prompts, notifications, PDF, and Excel exports.
- Existing user-generated database content is not rewritten; only system-owned labels and generated display text are standardized.
- `Purchase Requirement` is the official English label for `Permintaan Material`.
