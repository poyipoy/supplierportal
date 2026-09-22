# PR #3 Code Change Recommendations (2026-09-21)

Sumber: Review komentar pada pull request **#3 – Local supplier update**.

## Rekomendasi Perubahan

- [x] **Batasi akses detail vendor finance hanya untuk supplier local aktif**  
  **File:** `app/Http/Controllers/Finance/FinanceVendorController.php`  
  **Status:** Diimplementasikan dengan guard `abort_unless($vendor->isLocalEligible(), 404)` pada `show()` dan filter scope pada `index()`.

- [x] **Hindari mutasi data pada endpoint GET detail PO supplier**  
  **File:** `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`  
  **Status:** Diimplementasikan. Pemanggilan `reconcileCustomsDocumentationStatus()` dihapus dari `show()`; kalkulasi status bersifat murni read-only.

- [x] **Perketat validasi `supplier_id` untuk local PO**  
  **File:** `app/Http/Requests/LocalInvoice/SaveLocalPurchaseOrderRequest.php`  
  **Status:** Dipertahankan pada Service Layer (`LocalProcurementMasterService::createPurchaseOrder/updatePurchaseOrder`), di mana query `User::localEligible()->whereKey($data['supplier_id'])` melempar `ValidationException` (422). Tidak diduplikasi di FormRequest untuk mencegah query ganda.

- [x] **Tambah guard scope local saat approve/reject vendor change request**  
  **File:** `app/Services/VendorMaster/VendorChangeRequestService.php`  
  **Status:** Diimplementasikan dengan guard `isLocalEligible()` sebelum mengeksekusi `approve()` dan `reject()`.

- [x] **Perbaiki rollback migration role agar aman dan konsisten**  
  **File:** `database/migrations/2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga.php`  
  **Status:** Diimplementasikan. Jejak migrasi dicatat di `auth_audit_logs`, dan `down()` menolak rollback jika terdapat user finance/ga baru untuk mencegah kerusakan role.

- [x] **Perbaiki rollback migration status `CANCELLED` payment groups**  
  **File:** `database/migrations/2026_09_14_000003_add_cancelled_status_to_payment_groups.php`  
  **Status:** Diimplementasikan. Ditambahkan guard `down()` yang melempar exception jika terdapat baris berstatus `CANCELLED`.

- [x] **Cegah pengiriman reminder invoice berulang**  
  **File:** `app/Console/Commands/SendLocalInvoiceDeliveryReminders.php`  
  **Status:** Diimplementasikan. Ditambahkan kolom `delivery_reminder_sent_at` dan pengecekan idempotensi reminder pada query jadwal pengiriman fisik.

- [x] **Batasi resource route GA hanya ke action yang tersedia**  
  **File:** `routes/ga.php`  
  **Status:** Diimplementasikan menggunakan `Route::resource('employees')->only(['index', 'store', 'update'])` dan rute eksplisit `toggle-status`.

- [x] **Perbaiki mapping tone status local invoice**  
  **File:** `app/Support/StatusHelper.php`  
  **Status:** Diimplementasikan dengan associative array mapping eksplisit untuk seluruh 13 status canonical.

## Catatan

- Seluruh 8 temuan review independen telah diremediasi dan diverifikasi penuh melalui pengujian otomatis (806 passing tests, 6.597 assertions).
