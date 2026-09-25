# ADASI Supplier Portal — Implementation Plan
## Bulk Export Transfer DRP Supplier & DRP Paid menggunakan `TARIKAN TRANSFER.xlsx`

**Repository:** `https://github.com/poyipoy/supplierportal`

**Scope:** Bulk export data transfer dari beberapa DRP yang dipilih Finance ke **satu workbook Excel**.

**Output:** Workbook mengikuti template `TARIKAN TRANSFER.xlsx` dan hanya menghasilkan sheet `Data`.

---

## 1. Requirement Utama

Export ini **bukan export per-DRP**.

Model yang diinginkan:

```text
Finance membuka daftar DRP
        ↓
Finance memilih satu atau beberapa DRP
        ↓
Klik Export Transfer
        ↓
Sistem mengambil seluruh transaksi dari DRP yang dipilih
        ↓
Semua transaksi digabung ke satu workbook
        ↓
Workbook hanya memiliki sheet `Data`
```

Contoh:

```text
DRP-2026-00001
DRP-2026-00004
DRP-2026-00007
DRP-2026-00009
```

dipilih bersama, maka hasilnya:

```text
DRP_Transfer_20260924.xlsx
└── Data
      ├── transaksi DRP-2026-00001
      ├── transaksi DRP-2026-00004
      ├── transaksi DRP-2026-00007
      └── transaksi DRP-2026-00009
```

Jangan membuat satu file per DRP.

Jangan membuat satu sheet per DRP.

Jangan mengubah export menjadi workflow current-batch-only.

---

## 2. DRP yang Dapat Dipilih

Format transfer yang sama dipakai untuk DRP Supplier dan DRP Paid.

Karena `PaymentBatch` mempunyai:

```text
SUPPLIER
GA
```

maka exporter transfer ini hanya menerima:

```text
PaymentBatch::TYPE_SUPPLIER
```

DRP GA bukan bagian dari export transfer Supplier.

Pada halaman yang juga menampilkan DRP GA, pilihan GA harus ditolak server-side walaupun checkbox dapat dimanipulasi.

---

## 3. Source of Truth

Setiap transaksi diambil dari struktur DRP yang sudah tersimpan:

```text
PaymentBatch
  └── PaymentGroup
        └── PaymentItem
              └── LocalInvoice
```

Jangan membangun ulang transaksi dengan mencari supplier, rekening, atau grouping dari master vendor ketika export dijalankan.

Untuk data tujuan pembayaran, gunakan snapshot di:

```text
PaymentGroup.payee_name
PaymentGroup.bank_name
PaymentGroup.account_number
PaymentGroup.account_holder_name
```

Hal ini mempertahankan kondisi rekening yang digunakan ketika DRP dibuat.

---

## 4. Existing Architecture yang Dipertahankan

Project sudah memiliki async export:

```text
Finance UI
    ↓
FinanceDrpController / DRP UI
    ↓
ExportDispatcher
    ↓
ProcessExportJob
    ↓
queue: exports
    ↓
PaymentBatchDrpExport
    ↓
PaymentBatchDrpSheetRenderer
    ↓
XLSX
```

Gunakan architecture tersebut.

Yang perlu diubah bukan engine export dari nol, tetapi terutama:

```text
single/current batch selection
        ↓
multi-batch selection
```

`PaymentBatchDrpExport` saat ini sudah menerima `array $batchIds`, sehingga fondasi bulk export sebenarnya sudah tersedia.

Perubahan utama ada pada:

- UI selection;
- validasi daftar batch;
- endpoint request;
- authorization seluruh batch;
- filename untuk bulk export;
- rendering semua batch menjadi satu `Data` sheet;
- test bulk selection.

---

## 5. UI Bulk Selection

Finance harus dapat memilih DRP dari daftar.

Recommended UI:

```text
☐ DRP-2026-00001
☐ DRP-2026-00002
☐ DRP-2026-00004
☐ DRP-2026-00007
```

Kemudian:

```text
[Export Transfer]
```

### Behavior

Jika tidak ada DRP dipilih:

```text
Tidak boleh dispatch export.
```

Jika satu DRP dipilih:

```text
tetap menghasilkan satu file Data,
bukan mengubah workflow menjadi current-batch export.
```

Jika banyak DRP dipilih:

```text
semua masuk ke satu file yang sama.
```

Checkbox harus mengirim identifier DRP yang digunakan aplikasi, bukan raw ID apabila route/UI project menggunakan Hashids.

Tetapi authorization server-side tetap menggunakan record database setelah identifier di-resolve.

---

## 6. Sumber Selection

Selection dapat tersedia pada:

```text
Finance → DRP Supplier
Finance → DRP Paid
```

Keduanya menggunakan exporter yang sama.

### DRP Supplier

Menampilkan batch Supplier yang dapat dipilih.

### DRP Paid

Menampilkan daftar DRP yang sudah dibayar maupun belum sesuai filter/page yang ada.

Hanya batch:

```text
batch_type = SUPPLIER
```

yang dapat dipilih untuk export transfer.

Jangan mengubah filter/status DRP Paid yang sudah ada hanya demi exporter.

---

## 7. Pemilihan Batch

Request harus menerima daftar:

```php
batch_ids[]
```

Setelah diterima:

1. normalize identifier;
2. resolve seluruh batch;
3. pastikan semua batch valid;
4. pastikan semuanya `SUPPLIER`;
5. pastikan tidak ada `CANCELLED`;
6. pastikan actor Finance/Admin;
7. pastikan setiap batch memiliki data pembayaran aktif;
8. hanya setelah seluruh daftar lolos validasi, dispatch satu export job.

**Atomicity selection:**

Jika satu batch invalid, jangan export sebagian.

Contoh:

```text
Selected:
DRP-00001 ✓
DRP-00004 ✓
DRP-00007 ✗ GA

Result:
EXPORT DITOLAK SELURUHNYA
```

Jangan diam-diam membuang batch invalid dan tetap mengekspor sisanya karena user memilih seluruh daftar secara eksplisit.

---

## 8. Eligibility Status

Batch Supplier yang dapat diekspor:

```text
DRAFT
FINALIZED
PARTIALLY_PAID
PAID
```

Tidak termasuk:

```text
CANCELLED
```

Satu atau lebih batch tanpa active Supplier payment item harus menyebabkan request ditolak dengan informasi batch yang bermasalah.

---

## 9. Granularity Output

**Satu PaymentGroup = satu transaksi transfer.**

Contoh:

```text
DRP-00001
 ├── Supplier A / rekening X
 ├── Supplier B / rekening Y
 └── Supplier C / rekening Z
```

menghasilkan tiga transaksi.

Jika dua DRP memiliki Supplier/rekening yang sama:

```text
DRP-00001 → Supplier A → Rp 5.000.000
DRP-00004 → Supplier A → Rp 7.000.000
```

keduanya tetap menjadi dua transaksi karena berasal dari dua PaymentGroup/DRP berbeda.

Jangan melakukan consolidation lintas-DRP kecuali ada business rule terpisah yang secara eksplisit meminta penggabungan.

---

## 10. Urutan Transaksi

Karena Finance dapat memilih DRP secara arbitrary, urutan harus deterministic.

Gunakan:

```text
PaymentBatch.created_at ASC
PaymentBatch.id ASC
PaymentGroup.id ASC
```

Artinya:

```text
DRP yang lebih dahulu dibuat
    ↓
group di dalam DRP
    ↓
transaction row
```

Jangan mengikuti urutan checkbox browser sebagai satu-satunya source of truth.

---

## 11. Transaction ID

Transaction ID harus unique di dalam file bulk export dan deterministic.

Recommended:

```text
DRP-{batch_number}-{group_sequence}
```

Contoh:

```text
DRP-DRP-2026-00001-001
DRP-DRP-2026-00001-002
DRP-DRP-2026-00004-001
```

Karena `batch_number` sendiri sudah diawali `DRP-`, implementasi boleh menggunakan bentuk yang lebih bersih:

```text
2026-00001-001
2026-00001-002
2026-00004-001
```

Pilihan final harus mengikuti format yang diharapkan sistem bank/template.

Yang wajib:

- unique dalam satu file;
- deterministic;
- tidak random;
- export ulang dengan selection yang sama menghasilkan ID yang sama.

Jika template/bank memiliki batas panjang field Transaction ID, validasi length sebelum membuat workbook.

---

## 12. Template Workbook

Gunakan:

```text
resources/templates/drp/TARIKAN TRANSFER.xlsx
```

Template berisi sheet referensi lain, tetapi output final:

```text
Data
```

saja.

Jangan membuat workbook baru dari blank spreadsheet jika template dibutuhkan sebagai acuan struktur.

Pipeline renderer:

```text
Load TARIKAN TRANSFER.xlsx
        ↓
ambil sheet Data
        ↓
hapus sample transaction rows
        ↓
hapus/abaikan sheet reference
        ↓
populate transaksi
        ↓
save
```

---

## 13. Header `Data`

Kolom harus mempertahankan posisi:

```text
A  No
B  Transaction ID
C  Transfer Type
D  Debited Acc.
E  Beneficiary ID
F  Credited Acc.
G  Amount
H  Eff. Date
I  Transaction Purpose
J  Currency
K  Charges Type
L  Charges Acc.
M  Remark 1
N  Remark 2
O  Receiver Bank Cd
P  Receiver Bank Name
Q  Receiver Name
R  Receiver Cust. Type
S  Receiver Cust. Residen
T  Transaction Cd
U  Beneficiary Email
```

Jangan rename, reorder, atau mengurangi kolom.

---

## 14. Mapping Data

| Kolom | Source / Rule |
|---|---|
| A `No` | Sequential global `1..N` dalam file export |
| B `Transaction ID` | Deterministic ID berdasarkan DRP + group |
| C `Transfer Type` | BCA / LLG berdasarkan bank mapping |
| D `Debited Acc.` | Rekening debet ADASI yang dikonfigurasi |
| E `Beneficiary ID` | Sesuai template; kosong bila tidak ada source authoritative |
| F `Credited Acc.` | `PaymentGroup.account_number` |
| G `Amount` | `PaymentGroup.net_payment_amount` |
| H `Eff. Date` | Mengikuti sumber tanggal transfer yang ditentukan template/business rule |
| I `Transaction Purpose` | Kosong bila tidak ada source authoritative |
| J `Currency` | `IDR` |
| K `Charges Type` | `OUR` |
| L `Charges Acc.` | Rekening debet ADASI |
| M `Remark 1` | Invoice reference utama |
| N `Remark 2` | Invoice reference lanjutan |
| O `Receiver Bank Cd` | SANDI BIC dari mapping bank |
| P `Receiver Bank Name` | Nama bank dari mapping bank |
| Q `Receiver Name` | `PaymentGroup.account_holder_name` |
| R `Receiver Cust. Type` | Mengikuti keterangan template untuk supplier |
| S `Receiver Cust. Residen` | `1` |
| T `Transaction Cd` | `99` |
| U `Beneficiary Email` | `Supplier.pic_email` |

Field yang tidak memiliki source authoritative tidak boleh diisi dengan tebakan.

---

## 15. Amount

Untuk transfer bank gunakan:

```text
PaymentGroup.net_payment_amount
```

Bukan:

```text
PaymentGroup.subtotal_amount
```

Bukan:

```text
PaymentItem.amount
```

dan bukan total seluruh batch.

Dalam satu row:

```text
Amount = nominal transfer untuk PaymentGroup tersebut
```

Harus ditulis sebagai numeric Excel value.

---

## 16. Bank Mapping

Gunakan SANDI BIC / bank reference dari template.

Mapping harus deterministic.

Contoh conceptual:

```text
PaymentGroup.bank_name
        ↓
normalized / controlled mapping
        ↓
SANDI BIC + canonical bank name
```

Jangan:

- fuzzy match;
- menebak kode;
- mengambil substring acak;
- membuat kode baru.

Jika bank tidak dapat di-resolve:

```text
EXPORT DITOLAK
```

dan Finance harus mengetahui DRP/group mana yang menyebabkan kegagalan.

---

## 17. Account Number

`Credited Acc.` harus berasal dari:

```text
PaymentGroup.account_number
```

Tulis sebagai string.

Tujuannya agar:

```text
00123456789
```

tidak berubah menjadi:

```text
123456789
```

Leading zero wajib dipertahankan.

---

## 18. Invoice Remarks

Ambil invoice dari active:

```text
PaymentGroup
  → PaymentItem(status = ACTIVE)
  → LocalInvoice
  → invoice_number
```

Invoice tidak boleh diduplikasi hanya karena beberapa DRP dipilih.

Packing `Remark 1` dan `Remark 2` harus mengikuti batas karakter dan instruksi template.

Jika seluruh invoice reference tidak dapat dimasukkan tanpa melampaui batas yang ditetapkan:

```text
EXPORT DITOLAK
```

Jangan melakukan silent truncation.

---

## 19. Supplier Email

Ambil:

```text
Supplier.pic_email
```

bukan `users.email` sebagai fallback tanpa aturan.

Jika field wajib tetapi email tidak tersedia/invalid, export harus gagal dan memberikan informasi batch/group terkait.

---

## 20. Effective Date

Source tanggal harus mengikuti definisi bisnis/template.

Untuk DRP yang belum dibayar, kandidat source yang tersedia di aplikasi adalah:

```text
LocalInvoice.scheduled_payment_date
```

Untuk DRP Paid, tersedia:

```text
PaymentGroup.transfer_date
```

Namun jangan mencampur keduanya secara silent.

Implementasi harus menetapkan secara eksplisit rule berdasarkan konteks/status export sebelum code selesai.

Jika source tanggal yang diwajibkan tidak tersedia:

```text
EXPORT DITOLAK
```

Jangan menggunakan `now()` sebagai fallback diam-diam.

---

## 21. DRP Paid

DRP Paid bukan exporter kedua.

Gunakan:

```text
PaymentBatchDrpExport
```

yang sama.

Perbedaan hanya pada dataset batch yang dipilih.

Contoh:

```text
DRP Supplier page
    select DRP 1, 3, 5
       ↓
satu file

DRP Paid page
    select DRP 2, 4, 6
       ↓
satu file
```

Format `Data` tetap sama.

---

## 22. Controller / Endpoint

Endpoint bulk harus menerima daftar DRP.

Contoh konsep:

```text
POST /finance/drp/export
```

atau route lain yang mengikuti konvensi project.

Jangan menjadikan:

```text
GET /finance/drp/{batch}/export
```

sebagai satu-satunya workflow.

Route current-batch boleh dipertahankan bila masih dipakai fitur lain, tetapi requirement bulk harus mempunyai endpoint resmi sendiri.

Controller hanya:

1. validate input;
2. resolve seluruh batch;
3. authorize;
4. dispatch satu export job;
5. return standard async export response.

Jangan rendering workbook di controller.

---

## 23. Export Arguments

Queued job harus menerima scalar JSON-safe data:

```php
[
    'actor_id' => 123,
    'batch_ids' => [11, 22, 33, 44],
]
```

sesuai kontrak `PaymentBatchDrpExport` yang existing.

Jangan queue Eloquent model.

---

## 24. Filename

Karena file bisa berisi banyak DRP, filename tidak boleh lagi bergantung pada satu batch.

Gunakan deterministic bulk filename, misalnya:

```text
DRP_TRANSFER_20260924.xlsx
```

atau berdasarkan periode export:

```text
DRP_TRANSFER_20260924_01.xlsx
```

Final naming harus mengikuti kebiasaan export project, tetapi tidak boleh membuat filename seperti:

```text
DRP_<single_batch>.xlsx
```

untuk bulk selection.

Gunakan sanitization existing `ExportDispatcher`.

---

## 25. Authorization

Actor:

```text
Finance
Admin
```

Server harus memvalidasi **setiap batch yang dipilih**, bukan hanya batch pertama.

Checklist:

```text
✓ actor authenticated
✓ actor Finance/Admin
✓ every batch exists
✓ every batch = SUPPLIER
✓ no batch CANCELLED
✓ every selected batch belongs to allowed export domain
✓ every selected batch has active supplier payment item
```

Jika satu saja tidak lolos:

```text
seluruh export dibatalkan
```

Hashids adalah identifier, bukan authorization.

---

## 26. Performance

Bulk export dapat berisi banyak DRP, sehingga query harus eager-load.

Minimal:

```text
PaymentBatch
  └── groups
        └── active payment items
              └── LocalInvoice
                    └── supplier
```

Gunakan query bounded berdasarkan `batch_ids`.

Hindari:

```text
query supplier per row
query invoice per row
query bank per row
query payment item per row
```

Tidak boleh ada N+1 di render loop.

---

## 27. Renderer

`PaymentBatchDrpSheetRenderer` harus berubah dari:

```text
render(Collection<PaymentBatch>)
→ beberapa sheet
```

menjadi:

```text
render(Collection<PaymentBatch>)
→ satu Data sheet
→ seluruh PaymentGroup dari seluruh batch
```

Konseptual:

```php
foreach ($batches as $batch) {
    foreach ($batch->groups as $group) {
        renderTransferRow($group);
    }
}
```

Bukan:

```php
foreach ($batches as $batch) {
    createSheet($batch);
}
```

---

## 28. Data Row Cleaning

Template memiliki sample rows.

Sebelum populate:

```text
hapus/clear seluruh sample transaction rows
```

Kemudian tulis ulang:

```text
A8:U...
```

sesuai jumlah transaksi aktual.

Tidak boleh ada supplier/sample/reference dari template yang tertinggal.

---

## 29. Sheet Requirement

Final workbook harus memenuhi:

```text
Sheet count = 1
Sheet name  = Data
```

Tidak boleh ada:

```text
Legend
bank code
Swift Code
Form Responses 1
```

atau sheet lain.

Reference workbook hanya digunakan untuk membangun output.

---

## 30. Atomic Bulk Export

Tidak boleh menghasilkan file partial.

Scenario:

```text
10 DRP dipilih
8 valid
1 bank invalid
1 cancelled
```

result:

```text
NO FILE GENERATED
NO EXPORT JOB SUCCESS
```

Finance harus menerima validation result mengenai batch yang bermasalah.

---

## 31. Tests

### Selection

```text
✓ one batch selected
✓ multiple batches selected
✓ arbitrary batch order is normalized
✓ duplicate batch IDs handled safely
✓ empty selection rejected
```

### Authorization

```text
✓ Finance allowed
✓ Admin allowed
✓ unauthorized role rejected
✓ GA batch rejected
✓ cancelled batch rejected
✓ mixed valid + invalid selection rejects whole export
```

### Data

```text
✓ one PaymentGroup = one transfer row
✓ multiple DRPs populate one Data sheet
✓ same supplier across different DRPs remains separate transactions
✓ removed PaymentItem excluded
✓ cancelled PaymentGroup excluded
✓ Amount = net_payment_amount
✓ account number preserves leading zero
✓ bank code resolves correctly
✓ receiver name correct
✓ PIC email correct
✓ invoice remarks correct
✓ Transaction ID unique and deterministic
✓ No sample rows remain
```

### Workbook

```text
✓ exactly one Data sheet
✓ columns A:U preserved
✓ numeric amount values
✓ correct row count
✓ header preserved
✓ XLSX can be opened after generation
```

### Regression

```text
✓ existing DRP creation unchanged
✓ finalization unchanged
✓ DRP Paid unchanged
✓ voucher/settlement unchanged
✓ existing export framework tests remain green
```

---

## 32. Actual XLSX Verification

Jangan menganggap export berhasil hanya karena HTTP/job menghasilkan status success.

Ambil file hasil dan verify:

```text
Workbook
  ├── sheet count
  ├── sheet name
  ├── header A:U
  ├── transaction count
  ├── Transaction ID uniqueness
  ├── Amount values
  ├── account values
  ├── bank code
  ├── receiver
  ├── remarks
  └── email
```

Gunakan fixture dengan minimal:

```text
DRP A → 2 groups
DRP B → 1 group
DRP C → 3 groups
```

Expected:

```text
6 transfer rows
1 workbook
1 sheet = Data
```

---

## 33. Completion Criteria

Implementasi selesai jika:

```text
✓ Finance dapat memilih banyak DRP
✓ Finance dapat memilih DRP mana saja yang diperlukan
✓ semua DRP terpilih masuk ke SATU file
✓ tidak ada satu sheet per DRP
✓ tidak ada satu file per DRP
✓ hanya sheet Data
✓ format A:U mengikuti TARIKAN TRANSFER
✓ source pembayaran menggunakan PaymentBatch/PaymentGroup
✓ bank/account menggunakan payment snapshot
✓ invalid batch menggagalkan seluruh bulk export
✓ DRP Supplier dan DRP Paid memakai exporter yang sama
✓ GA tidak ikut export transfer Supplier
✓ export tetap asynchronous
✓ no Browser QA
✓ workbook hasil benar-benar diverifikasi
✓ regression test tidak rusak
```

---

## 34. Prinsip Implementasi

```text
Selected DRPs
     ↓
Validate ALL
     ↓
Load ALL
     ↓
Flatten ALL PaymentGroups
     ↓
Map A:U
     ↓
One Data Sheet
     ↓
One XLSX
     ↓
Verify actual workbook
```

**Inti requirement:**

```text
DRP bukan unit file.
DRP hanya unit selection.

Unit output = satu file transfer gabungan
dari semua DRP yang dipilih Finance.
```

Jangan mengubah requirement ini menjadi current-DRP export.
