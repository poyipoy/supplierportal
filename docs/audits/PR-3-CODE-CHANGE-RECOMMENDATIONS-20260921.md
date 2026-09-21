# PR #3 Code Change Recommendations (2026-09-21)

Sumber: Review komentar pada pull request **#3 – Local supplier update**.

## Rekomendasi Perubahan

- [ ] **Batasi akses detail vendor finance hanya untuk supplier local aktif**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Http/Controllers/Finance/FinanceVendorController.php`  
  **Saran:** Tolak akses jika user target bukan role `supplier`, tidak aktif, atau tidak punya scope `local`.

- [ ] **Hindari mutasi data pada endpoint GET detail PO supplier**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`  
  **Saran:** Pindahkan pemanggilan `reconcileCustomsDocumentationStatus()` dari action `show()` ke action write (POST/PATCH) yang relevan.

- [ ] **Perketat validasi `supplier_id` untuk local PO**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Http/Requests/LocalInvoice/SaveLocalPurchaseOrderRequest.php`  
  **Saran:** Validasi harus memastikan supplier adalah user `supplier` aktif dengan scope `local`, bukan sekadar user yang ada.

- [ ] **Tambah guard scope local saat approve/reject vendor change request**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Services/VendorMaster/VendorChangeRequestService.php`  
  **Saran:** Tolak proses jika supplier terkait tidak memiliki scope `local`.

- [ ] **Perbaiki rollback migration role agar aman dan konsisten**  
  **File:** `/home/runner/work/supplierportal/supplierportal/database/migrations/2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga.php`  
  **Saran:** `down()` harus memulihkan enum lama dan menangani data role `finance/ga` secara eksplisit (migrasi balik atau guard throw).

- [ ] **Perbaiki rollback migration status `CANCELLED` payment groups**  
  **File:** `/home/runner/work/supplierportal/supplierportal/database/migrations/2026_09_14_000003_add_cancelled_status_to_payment_groups.php`  
  **Saran:** Tambahkan guard/migrasi data sebelum menghapus `CANCELLED` dari enum saat rollback.

- [ ] **Cegah pengiriman reminder invoice berulang**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Console/Commands/SendLocalInvoiceDeliveryReminders.php`  
  **Saran:** Gunakan idempotency marker (mis. `delivery_reminder_sent_at`) atau kirim hanya pada hari reminder yang tepat.

- [ ] **Batasi resource route GA hanya ke action yang tersedia**  
  **File:** `/home/runner/work/supplierportal/supplierportal/routes/ga.php`  
  **Saran:** Gunakan `->only([...])` untuk action yang memang diimplementasikan pada `EmployeeController`, dan route terpisah untuk `toggleStatus`.

- [ ] **Perbaiki mapping tone status local invoice**  
  **File:** `/home/runner/work/supplierportal/supplierportal/app/Support/StatusHelper.php`  
  **Saran:** Definisikan tiap status sebagai key array/match yang terpisah agar tone untuk `UNDER_REVIEW`, `REJECTED`, `CANCELLED`, `APPROVED`, `READY_TO_PAY`, `PAID` tidak jatuh ke default.

## Catatan

- Daftar ini adalah rekomendasi berbasis review komentar dan belum otomatis berarti seluruh item harus diubah tanpa verifikasi lanjutan.
- Prioritas awal yang disarankan: isu otorisasi/scope supplier dan mutasi data di endpoint GET.
