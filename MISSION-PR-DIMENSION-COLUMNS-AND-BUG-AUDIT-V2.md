# MISSION: Revisi Portal Supplier — Fix 5 Kolom Dimensi & Audit Bug V2

**Repo:** `poyipoy/supplierportal`  
**Branch acuan:** `master`  
**Tanggal review:** 2026-09-01  
**Scope utama:** Halaman create/edit PR (Purchasing), kompatibilitas Import Excel, baseline Quotation Supplier, serta audit bug/performa yang terkait alur Supplier & Purchasing.  
**Status dokumen:** **Implementation-ready setelah revisi deep review**

---

## 0. Ringkasan Eksekutif

Investigasi codebase mengonfirmasi bahwa backend sudah memakai lima field dimensi kanonik:

```text
thickness
 d_inner
 d_outer
 width
 length
```

Sumber kebenaran saat ini berada di `App\Models\PrItem`, terutama:

- `DIMENSION_FIELDS`
- `DIMENSION_LABELS`
- `RELEVANT_DIMENSIONS`
- `PRESENTATION_DIMENSIONS`
- `relevantDimensionFields()`
- `presentationDimensionFields()`

Karena itu **tidak diperlukan migration atau perubahan skema database** untuk target lima kolom dimensi.

Masalah utamanya berada di presentation/input contract sisi Purchasing:

- Purchasing masih menggunakan **3 slot dimensi dinamis**.
- Tiap slot mengganti field berdasarkan shape.
- Nilai canonical disimpan melalui hidden mirror input.
- Header tabel direwrite oleh JavaScript.
- Mixed-shape PR membuat header berubah menjadi `Dimension 1/2/3`.

Sisi Supplier sudah memakai pendekatan visual yang lebih baik: lima kolom statis dengan urutan:

```text
Thickness | Outer D. | Inner D. | Width | Length
```

Namun implementasi Supplier **tidak boleh dicopy 1:1** ke Purchasing. Di Supplier shape adalah data read-only dari PR, sedangkan di Purchasing user dapat mengganti shape saat form sedang aktif.

### Keputusan desain final V2

Purchasing akan memakai lima kolom statis dengan prinsip:

> **Kelima canonical input harus selalu ada di DOM untuk setiap row, termasuk row template yang shape-nya belum dipilih.**

Shape hanya menentukan:

- input mana yang terlihat/aktif;
- input mana yang disabled;
- field irrelevant mana yang dikosongkan;
- sel mana yang menampilkan `—`.

Dengan kontrak ini:

- shape dapat diganti tanpa membuat ulang input;
- Import Excel tetap dapat mengisi canonical `name`;
- material preview tetap dapat membaca semua field;
- hidden canonical mirror dapat dihapus;
- tidak ada lagi mapping slot → canonical field;
- mixed-shape PR tidak memengaruhi header.

---

# 1. Konteks Codebase dan Source of Truth

## 1.1 Canonical dimension contract

File:

```text
app/Models/PrItem.php
```

Current canonical fields:

```php
public const DIMENSION_FIELDS = [
    'thickness',
    'd_inner',
    'd_outer',
    'width',
    'length',
];
```

Relevance per shape:

```text
Flat   -> thickness, width, length
Round  -> d_outer, length
Hollow -> d_inner, d_outer, length
```

Human-facing presentation lama:

```text
Flat   -> thickness, width, length
Round  -> d_outer, length
Hollow -> d_outer, d_inner, length
```

Backend processor juga sudah menormalisasi irrelevant fields menjadi `null`, sehingga server tetap menjadi safety net walaupun client-side JavaScript gagal membersihkan input.

---

## 1.2 Current Purchasing architecture

File utama:

```text
resources/views/purchasing/pr/_item_row.blade.php
resources/views/purchasing/pr/_material_shape_script.blade.php
resources/views/purchasing/pr/create.blade.php
resources/views/purchasing/pr/edit.blade.php
resources/views/purchasing/pr/_form_table_styles.blade.php
resources/views/purchasing/pr/_import.blade.php
```

Current `_item_row.blade.php`:

1. Membuat 3 slot berdasarkan `presentationDimensionFields($shape)`.
2. Mem-padding slot menjadi 3.
3. Membuat hidden input untuk kelima canonical dimension.
4. Visible slot input tidak punya canonical `name`.
5. JS menyinkronkan visible slot dengan hidden canonical input.

Current `_material_shape_script.blade.php`:

- memiliki `materialDimensionFields`;
- memiliki `allMaterialDimensions`;
- memiliki `materialDimensionSlotCount = 3`;
- memiliki `canonicalDimensionInput()`;
- memiliki `updateMaterialDimensionHeaders()`;
- melakukan mapping canonical → slot pada `applyMaterialShapeRules()`;
- melakukan mapping slot → canonical pada event `.dimension-input`;
- `materialPreviewPayload()` membaca hidden canonical inputs.

Arsitektur ini bekerja, tetapi terlalu banyak indirection untuk data yang pada backend sebenarnya sudah canonical.

---

# 2. Keputusan UX Final

## 2.1 Urutan kolom

Gunakan urutan yang sudah dipakai Supplier:

```text
Thickness | Outer D. | Inner D. | Width | Length
```

Canonical display order:

```php
[
    'thickness',
    'd_outer',
    'd_inner',
    'width',
    'length',
]
```

Alasan:

- konsisten antara Purchasing PR dan Supplier Quotation;
- Hollow terbaca natural sebagai Outer → Inner;
- mengurangi cognitive switching user antar tahap procurement;
- tidak mengubah backend relevance/sanitization order.

---

## 2.2 Prinsip DOM yang WAJIB

### Jangan gunakan pola ini di Purchasing

```blade
@if($isRelevant)
    <input ...>
@else
    &mdash;
@endif
```

Pola tersebut aman di Supplier tetapi **tidak aman di Purchasing**, karena row baru dapat mempunyai `shape = null`.

Jika input hanya dirender ketika relevant, maka row template baru dapat mempunyai **zero dimension inputs**. Setelah user memilih shape, JS tidak memiliki elemen input yang dapat diaktifkan.

### Gunakan pola ini

Untuk setiap row, selalu render:

```text
thickness input
 d_outer input
 d_inner input
 width input
 length input
```

Semua input langsung menggunakan canonical request name:

```text
items[index][thickness]
items[index][d_outer]
items[index][d_inner]
items[index][width]
items[index][length]
```

State UI diatur oleh JS:

```text
Relevant:
- input enabled
- input visible
- dash hidden

Irrelevant:
- value cleared jika clearIrrelevant = true
- input disabled
- input hidden
- dash visible
```

Dengan demikian DOM contract stabil sebelum dan sesudah shape berubah.

---

# 3. BAGIAN A — Implementasi 5 Kolom Dimensi Purchasing

## 3.1 Opsional tetapi direkomendasikan — centralize display order

### File

```text
app/Models/PrItem.php
```

Tambahkan constant:

```php
public const FIXED_DIMENSION_ORDER = [
    'thickness',
    'd_outer',
    'd_inner',
    'width',
    'length',
];
```

Tujuan:

- jangan hardcode order yang sama di Blade dan JavaScript secara terpisah;
- bedakan canonical storage order dari display order;
- Supplier/Purchasing dapat diarahkan ke contract yang sama pada follow-up berikutnya.

`DIMENSION_FIELDS` tetap tidak diubah karena itu canonical backend contract.

`RELEVANT_DIMENSIONS` tetap tidak diubah.

`PRESENTATION_DIMENSIONS` boleh dipertahankan untuk caller lama sampai terbukti tidak lagi dipakai di seluruh codebase. Jangan hapus dalam mission ini tanpa repository-wide usage check.

---

## 3.2 File 1 — `_item_row.blade.php`

### File

```text
resources/views/purchasing/pr/_item_row.blade.php
```

### Hapus

1. `$dimensionSlots = array_pad(...)`.
2. Local `$dimensionLabels` yang duplikatif jika sudah memakai `PrItem::DIMENSION_LABELS`.
3. Seluruh hidden canonical mirror:

```blade
@foreach(PrItem::DIMENSION_FIELDS as $dimensionField)
    <input type="hidden" ... class="dimension-canonical-input">
@endforeach
```

4. Seluruh loop 3 dynamic slot.
5. Attribute lama:

```text
data-dimension-slot
data-dimension-slot-cell
data-dimension-slot-label
```

### Ganti dengan 5 canonical controls yang SELALU dirender

Contoh contract:

```blade
@php
    $fixedDimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
    $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($shapeValue);
@endphp

@foreach($fixedDimensionOrder as $dimensionField)
    @php
        $isRelevant = in_array($dimensionField, $relevantDimensions, true);
        $dimensionLabel = \App\Models\PrItem::DIMENSION_LABELS[$dimensionField]
            ?? ucfirst(str_replace('_', ' ', $dimensionField));
    @endphp

    <td
        class="pr-dimension-cell {{ $isRelevant ? '' : 'is-disabled' }}"
        data-dimension-field-cell="{{ $dimensionField }}"
    >
        <div class="pr-dimension-control">
            <input
                id="item-{{ $index }}-dimension-{{ $dimensionField }}"
                type="number"
                step="0.0001"
                min="0.0001"
                name="items[{{ $index }}][{{ $dimensionField }}]"
                class="form-control form-control-sm text-end dimension-input"
                data-dimension-field="{{ $dimensionField }}"
                aria-label="{{ $dimensionLabel }} in millimeters"
                value="{{ $itemData[$dimensionField] ?? '' }}"
                {{ $isRelevant ? '' : 'disabled' }}
                {{ $isRelevant ? '' : 'hidden' }}
            >

            <span
                class="pr-dimension-na {{ $isRelevant ? 'd-none' : '' }}"
                data-dimension-na="{{ $dimensionField }}"
                aria-hidden="{{ $isRelevant ? 'true' : 'false' }}"
            >&mdash;</span>
        </div>

        @error("items.{$index}.{$dimensionField}")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
@endforeach
```

### Catatan penting

`hidden` di sini adalah **HTML state property pada visible canonical input**, bukan hidden mirror input terpisah.

Canonical input tetap berada di DOM sehingga:

- JS dapat mengaktifkannya setelah shape berubah;
- Import Excel dapat `.find([name="items[index][field]"])`;
- preview dapat membacanya langsung;
- old value/edit value tetap punya satu sumber nilai.

### Jangan render `name` conditionally

Setiap canonical input harus selalu punya canonical `name`, termasuk ketika disabled.

Saat irrelevant, `disabled` memastikan field tersebut tidak dikirim browser. Server processor tetap men-sanitize irrelevant dimensions ke `null` sebagai defense-in-depth.

---

## 3.3 File 2 — `create.blade.php`

### File

```text
resources/views/purchasing/pr/create.blade.php
```

### `<colgroup>`

Ganti 3 dimension slot column menjadi 5 fixed columns.

Contoh:

```html
<col style="width: 100px; min-width: 100px;">
<col style="width: 100px; min-width: 100px;">
<col style="width: 100px; min-width: 100px;">
<col style="width: 100px; min-width: 100px;">
<col style="width: 100px; min-width: 100px;">
```

Lebar final boleh di-tune setelah visual QA, tetapi jumlah dan urutan kolom harus fixed.

### `<thead>`

Ganti:

```text
Dimension 1 (mm)
Dimension 2 (mm)
Dimension 3 (mm)
```

menjadi:

```html
<th scope="col">Thickness (mm)</th>
<th scope="col">Outer D. (mm)</th>
<th scope="col">Inner D. (mm)</th>
<th scope="col">Width (mm)</th>
<th scope="col">Length (mm)</th>
```

Hapus seluruh `data-dimension-slot-header`.

### Row template

Pastikan `rowTemplate` yang memakai `_item_row.blade.php` dengan `shape = null` tetap menghasilkan **kelima canonical input**.

Ini acceptance criterion penting dan harus diuji otomatis.

---

## 3.4 File 3 — `edit.blade.php`

### File

```text
resources/views/purchasing/pr/edit.blade.php
```

Lakukan perubahan identik dengan create:

- 5 `<col>`;
- 5 fixed headers;
- tidak ada `data-dimension-slot-header`;
- row lama tetap memuat nilai dari 5 field canonical.

Pastikan legacy PR yang sudah menyimpan:

```text
thickness
 d_inner
 d_outer
 width
 length
```

langsung muncul pada canonical field masing-masing tanpa remapping slot.

---

## 3.5 File 4 — `_material_shape_script.blade.php`

### File

```text
resources/views/purchasing/pr/_material_shape_script.blade.php
```

Ini bagian JS paling penting.

### A. Hapus slot architecture

Hapus:

```text
materialDimensionSlotCount
canonicalDimensionInput()
updateMaterialDimensionHeaders()
```

Hapus semua penggunaan:

```text
data-dimension-slot
 data-dimension-slot-cell
 data-dimension-slot-label
 data-dimension-slot-header
```

Hapus seluruh logic yang memindahkan value dari canonical hidden input ke slot visible input.

Hapus seluruh logic yang menyalin `.dimension-input` kembali ke hidden canonical input.

---

### B. Hindari duplicate JS business contract

Direkomendasikan generate relevance dari PHP:

```blade
const materialDimensionFields = @json(\App\Models\PrItem::RELEVANT_DIMENSIONS);
const fixedDimensionOrder = @json(\App\Models\PrItem::FIXED_DIMENSION_ORDER);
```

Dengan ini JS tidak perlu hardcode shape rules sendiri.

Perhatikan bahwa `RELEVANT_DIMENSIONS` berupa array field, bukan object `{field,label}`. Setelah header tidak dinamis, JS tidak membutuhkan label untuk mapping slot lagi.

---

### C. Implementasi `applyMaterialShapeRules()` baru

Gunakan canonical input yang selalu ada:

```js
function applyMaterialShapeRules(row, clearIrrelevant = true) {
    const $row = $(row);
    const shape = $row.find('.material-shape-select').val();
    const relevantFields = materialDimensionFields[shape] || [];

    fixedDimensionOrder.forEach((field) => {
        const isRelevant = relevantFields.includes(field);
        const $cell = $row.find(`[data-dimension-field-cell="${field}"]`);
        const $input = $cell.find(`.dimension-input[data-dimension-field="${field}"]`);
        const $na = $cell.find(`[data-dimension-na="${field}"]`);

        if (!isRelevant && clearIrrelevant) {
            $input.val('');
        }

        $input
            .prop('disabled', !isRelevant)
            .prop('hidden', !isRelevant)
            .attr('aria-disabled', isRelevant ? 'false' : 'true');

        $na
            .toggleClass('d-none', isRelevant)
            .attr('aria-hidden', isRelevant ? 'true' : 'false');

        $cell.toggleClass('is-disabled', !isRelevant);
    });
}
```

### Required behavior

#### Shape kosong

```text
Semua input ada di DOM
Semua disabled + hidden
Semua cell menampilkan —
```

#### Flat

```text
Enabled : thickness, width, length
Disabled: d_outer, d_inner
```

#### Round

```text
Enabled : d_outer, length
Disabled: thickness, d_inner, width
```

#### Hollow

```text
Enabled : d_outer, d_inner, length
Disabled: thickness, width
```

---

### D. Update `materialPreviewPayload()`

Current implementation membaca hidden canonical inputs melalui `canonicalDimensionInput()`.

Setelah hidden mirror dihapus, **jangan lupa mengganti consumer ini**.

Gunakan direct canonical visible control:

```js
fixedDimensionOrder.forEach((field) => {
    payload[field] = $row
        .find(`.dimension-input[data-dimension-field="${field}"]`)
        .val() || '';
});
```

Walaupun field sedang disabled, `.val()` tetap dapat dibaca oleh JS. Nilai irrelevant seharusnya sudah kosong setelah `applyMaterialShapeRules(..., true)`.

Ini wajib karena preview mengendalikan kalkulasi realtime seperti HS code/weight.

---

### E. Simplify `.dimension-input` handler

Ganti handler lama yang menyalin slot ke hidden mirror menjadi:

```js
$(document).on('input change', '.dimension-input', function() {
    const $row = $(this).closest('tr');
    resetManualWeightOverride($row);
    scheduleMaterialPreview($row);
});
```

Tidak ada lagi canonical sync.

Input itu sendiri sudah canonical.

---

### F. Shape change handler

Saat `.material-shape-select` berubah:

1. `applyMaterialShapeRules(row, true)`;
2. reset manual weight override bila logic saat ini mengharuskan;
3. schedule material preview;
4. **jangan panggil `updateMaterialDimensionHeaders()`**.

Mixed rows tidak boleh memengaruhi header.

---

### G. Initialization

Pada `initializeMaterialShapeRows()`:

Tetap:

```text
applyMaterialShapeRules()
renumberPrRows()
preview initialization
```

Hapus:

```text
updateMaterialDimensionHeaders()
```

---

## 3.6 File 5 — `_form_table_styles.blade.php`

### File

```text
resources/views/purchasing/pr/_form_table_styles.blade.php
```

Perubahan di file ini **mandatory**, bukan sekadar review.

Current CSS mempunyai selector khusus slot:

```text
.pr-dimension-slot
.pr-dimension-slot-cell
.pr-dimension-slot-label
```

Selector tersebut akan obsolete.

### Ganti dengan contract baru

Contoh:

```css
#itemsTable.pr-items-table .pr-dimension-cell.is-disabled {
    background: var(--md-surface-container-low) !important;
}

#itemsTable.pr-items-table .pr-dimension-control {
    align-items: center;
    display: flex;
    justify-content: center;
    min-height: 34px;
    position: relative;
}

#itemsTable.pr-items-table .pr-dimension-na {
    color: var(--md-on-surface-variant);
    font-variant-numeric: tabular-nums;
    text-align: center;
    width: 100%;
}

#itemsTable.pr-items-table .dimension-input,
#itemsTable.pr-items-table .material-quantity,
#itemsTable.pr-items-table .weight-unit-display {
    font-variant-numeric: tabular-nums;
}
```

Mobile:

```css
@media (max-width: 767.98px) {
    #itemsTable.pr-items-table .pr-dimension-control {
        min-height: 44px;
    }
}
```

Hapus CSS yang hanya relevan ke 3-slot presentation.

---

## 3.7 File 6 — `_import.blade.php`

### File

```text
resources/views/purchasing/pr/_import.blade.php
```

Tidak seharusnya perlu perubahan business logic besar, **tetapi file ini harus masuk explicit regression scope**.

Current flow penting:

```text
1. clone rowTemplate
2. append ke DOM
3. isi values berdasarkan name="items[index][field]"
4. applyMaterialShapeRules(row, true)
```

Itu berarti new row contract wajib sudah mempunyai input berikut **sebelum shape rules dijalankan**:

```text
items[index][thickness]
items[index][d_inner]
items[index][d_outer]
items[index][width]
items[index][length]
```

Dengan always-render canonical inputs, existing import logic tetap kompatibel.

### Jangan lakukan

Jangan ubah import menjadi mencari field hanya setelah `applyMaterialShapeRules()`. Itu membuat import bergantung lagi pada state UI.

### Acceptance

Import Round:

```text
d_outer terset
length terset
irrelevant fields kosong
```

Import Hollow:

```text
d_outer terset
d_inner terset
length terset
irrelevant fields kosong
```

Import Flat:

```text
thickness terset
width terset
length terset
irrelevant fields kosong
```

---

# 4. Bagian A — Files yang Tidak Perlu Diubah

Untuk mission UI 5 kolom, jangan mengubah tanpa kebutuhan lain:

```text
App\Services\Materials\PrItemProcessor
App\Services\Materials\MaterialWeightCalculator
SavePurchaseRequisitionRequest
SavePrItemRequest
PurchaseRequisitionItemSynchronizer
PrItemsImport
DB schema/migrations
Supplier quotation dimension UI
```

Backend sudah menggunakan canonical fields dan server-side sanitization.

Catatan: `PrItem.php` hanya perlu disentuh jika kita memilih menambahkan `FIXED_DIMENSION_ORDER` untuk centralization. Itu bukan perubahan persistence/domain calculation.

---

# 5. Critical Acceptance Criteria Bagian A

Bagian A belum dianggap selesai hanya karena tabel terlihat lima kolom.

## 5.1 DOM contract

Untuk **setiap row**, bahkan shape kosong:

```text
5 canonical dimension inputs harus ada di HTML DOM.
```

Expected names:

```text
items[i][thickness]
items[i][d_outer]
items[i][d_inner]
items[i][width]
items[i][length]
```

Tidak boleh ada lagi:

```text
dimension-canonical-input
 data-dimension-canonical-field
 data-dimension-slot
 data-dimension-slot-cell
 data-dimension-slot-header
 materialDimensionSlotCount
 updateMaterialDimensionHeaders
```

---

## 5.2 Shape transition matrix

Uji minimal:

```text
blank  -> Flat
blank  -> Round
blank  -> Hollow
Flat   -> Round
Round  -> Hollow
Hollow -> Flat
```

Untuk setiap transition:

- newly relevant field harus menjadi input aktif;
- newly irrelevant value harus dikosongkan;
- irrelevant field tidak terkirim saat submit;
- header tidak berubah;
- material preview tetap bekerja.

---

## 5.3 Mixed shape

Satu PR dengan:

```text
Row 1: Flat
Row 2: Round
Row 3: Hollow
```

Header tetap:

```text
Thickness | Outer D. | Inner D. | Width | Length
```

Tidak pernah berubah menjadi `Dimension 1/2/3`.

---

## 5.4 Draft vs submit

Behavior existing dipertahankan:

```text
Draft     -> shape/dimension incomplete boleh
Submitted -> relevant shape dimensions wajib lengkap
```

Ini adalah business invariant existing, bukan bug UI.

---

## 5.5 Edit existing PR

Existing stored PR harus mapping langsung:

### Flat

```text
thickness -> Thickness
width     -> Width
length    -> Length
```

### Round

```text
d_outer -> Outer D.
length  -> Length
```

### Hollow

```text
d_outer -> Outer D.
d_inner -> Inner D.
length  -> Length
```

Tidak ada remapping melalui slot.

---

# 6. Automated Test Changes — WAJIB

Existing test suite mengunci contract lama, sehingga **tidak cukup hanya menjalankan test existing**.

Beberapa test sekarang secara eksplisit mengharapkan:

```text
Dimension 1 (mm)
Dimension 2 (mm)
Dimension 3 (mm)
data-dimension-slot
data-dimension-slot-header
data-dimension-canonical-field
materialDimensionSlotCount
updateMaterialDimensionHeaders
```

Setelah implementation V2, assertion tersebut harus diubah.

---

## 6.1 `PurchaseRequisitionMaterialAutomationTest.php`

### File

```text
tests/Feature/PurchaseRequisitionMaterialAutomationTest.php
```

Rewrite tests yang menguji adaptive 3-slot contract.

### New assertions create

Assert:

```text
Thickness (mm)
Outer D. (mm)
Inner D. (mm)
Width (mm)
Length (mm)
```

Assert canonical inputs ada pada blank row template:

```text
name="items[{INDEX}][thickness]"
name="items[{INDEX}][d_outer]"
name="items[{INDEX}][d_inner]"
name="items[{INDEX}][width]"
name="items[{INDEX}][length]"
```

Assert old architecture tidak ada:

```text
Dimension 1 (mm)
Dimension 2 (mm)
Dimension 3 (mm)
dimension-canonical-input
data-dimension-slot=
data-dimension-slot-header=
materialDimensionSlotCount
updateMaterialDimensionHeaders
```

### New assertions edit

Untuk Hollow item:

- `d_outer` value existing ada pada canonical `d_outer` input;
- `d_inner` value existing ada pada canonical `d_inner` input;
- order Outer sebelum Inner;
- irrelevant fields tetap ada di DOM tetapi disabled/hidden;
- tidak ada hidden canonical mirror.

### Mixed-row test

Ganti test `dimension_slots_render_shape_labels_for_mixed_rows` menjadi contract fixed-column.

Test harus membuktikan:

- mixed Flat/Round/Hollow tidak membuat header berubah;
- setiap row mempunyai 5 canonical controls;
- relevance state benar per row.

---

## 6.2 `MissionFiveImportTest.php`

### File

```text
tests/Feature/MissionFiveImportTest.php
```

Update assertion lama yang mengharapkan slot/canonical hidden input.

### Tambahkan regression test penting

Pastikan create/import UI contract mempunyai canonical field names sebelum shape dipilih.

Minimal test scenario:

#### Import Flat

Expected post-import row:

```text
shape=Flat
thickness=<value>
width=<value>
length=<value>
d_outer=''
d_inner=''
```

#### Import Round

```text
shape=Round
d_outer=<value>
length=<value>
thickness=''
d_inner=''
width=''
```

#### Import Hollow

```text
shape=Hollow
d_outer=<value>
d_inner=<value>
length=<value>
thickness=''
width=''
```

Paling tidak automated markup test harus memastikan selector yang dipakai `_import.blade.php` akan menemukan seluruh canonical fields pada blank template.

---

## 6.3 Browser test / manual JS test

PHP feature test tidak benar-benar mengeksekusi jQuery shape transition.

Jika project sudah memiliki Playwright/Dusk/browser infrastructure, tambahkan browser test untuk:

```text
blank -> Flat -> Round -> Hollow -> Flat
```

Jika tidak tersedia, jangan menambahkan testing framework baru hanya untuk mission ini. Gunakan:

- automated markup/HTTP tests;
- explicit manual QA checklist untuk JavaScript behavior.

---

# 7. BAGIAN B — Audit Bug Revisi

Bagian B dipisahkan dari Bagian A agar blast radius lebih kecil.

---

## B.1 — Mixed-shape dimension headers ambigu

### Status

**Valid bug.**

### Root cause

`updateMaterialDimensionHeaders()` hanya menampilkan label spesifik bila semua row mempunyai shape yang sama.

Pada mixed shape:

```text
Flat + Hollow
Flat + Round
Round + Hollow
```

header fallback ke:

```text
Dimension 1
Dimension 2
Dimension 3
```

### Fix

Tidak perlu ticket/implementation terpisah.

**Selesai otomatis melalui Bagian A** ketika header menjadi lima fixed columns dan fungsi header rewriting dihapus.

### Priority

```text
High
```

### Acceptance

Mixed shape tidak pernah mengubah header.

---

# 8. B.2 — GET edit melakukan write + legacy material resolution di Blade

## 8.1 Temuan B.2a — Side effect pada GET

### File

```text
app/Http/Controllers/Purchasing/PurchaseRequisitionController.php
```

`edit()` saat ini:

1. load PR/items;
2. build active material index;
3. resolve legacy `material_name` ketika `material_master_id = null`;
4. bila match, langsung:

```php
$item->material_master_id = $matched->id;
$item->saveQuietly();
```

Ini membuat:

```text
GET /edit
```

menulis database.

### Impact

- GET tidak read-only;
- page view mempunyai side effect;
- automated prefetch/crawler/browser retry theoretically dapat memicu write;
- data legacy berubah tanpa explicit user action;
- sulit membedakan read compatibility dengan data migration/backfill.

### Catatan koreksi terhadap plan lama

Controller menggunakan:

```php
$materialIndex = $resolver->activeIndex();
```

kemudian memberikan index tersebut ke `resolveExact()`.

Karena itu resolution per item di controller **bukan N database query**; lookup memakai in-memory collection setelah active index dibangun.

Masalah utamanya adalah write pada GET, bukan resolver query-per-item di controller tersebut.

---

## 8.2 Temuan B.2b — Material resolution/query di Blade

### File

```text
resources/views/purchasing/pr/_item_row.blade.php
```

View mempunyai fallback resolution sendiri ketika `material_master_id` kosong.

View memanggil:

```text
resolveExact(raw name)
resolveExact(stripped name)
resolveExact(no-space name)
```

Tanpa `materialIndex`.

`MaterialResolver::resolveExact()` tanpa index melakukan DB query.

Dengan banyak unresolved legacy items, Blade dapat melakukan beberapa query per row.

### Impact

- query/database access di presentation layer;
- duplicated legacy resolution antara controller dan view;
- N+1-ish lookup pada unresolved rows;
- sulit dites dan dirawat;
- view rendering bukan lagi pure presentation.

### Priority

```text
Medium-High
```

---

## 8.3 Fix strategy B.2 — jangan sekadar transaction

Plan lama menyarankan membungkus write di `DB::transaction()`.

Itu hanya mengurangi risiko partial write; **tidak memperbaiki GET side effect**.

Transaction dapat dipakai hanya sebagai emergency containment, bukan final solution.

### Target architecture

```text
Legacy stored data
      |
      +--> Explicit backfill/migration command (write path)
      |
      +--> Read-only compatibility resolution (sementara bila masih dibutuhkan)

GET edit
      |
      +--> read only
      +--> no item save
      +--> no DB query from Blade
```

### Recommended implementation

1. Hapus `saveQuietly()` dari `edit()`.
2. Hapus `MaterialResolver` calls dari `_item_row.blade.php`.
3. Jika legacy compatibility masih dibutuhkan di edit:
   - resolve sekali di controller/service menggunakan shared active index;
   - expose resolved display value/object ke view;
   - **jangan persist pada GET**.
4. Buat explicit Artisan command/service untuk backfill existing `material_master_id = null` records bila jumlah legacy data memang perlu diperbaiki permanen.
5. Backfill harus:
   - report matched count;
   - report unmatched rows;
   - ideally support dry-run;
   - write dalam controlled transaction/chunk, bukan page request.

### Jangan langsung memindahkan semuanya ke synchronizer tanpa cek validation

`SavePurchaseRequisitionRequest` saat ini membutuhkan:

```text
items.*.material_master_id = required
```

FormRequest berjalan **sebelum** controller/synchronizer.

Jadi legacy row dengan `material_master_id = null` dapat gagal validation sebelum synchronizer mendapat kesempatan resolve.

Jika ingin synchronizer menjadi final resolver, validation contract juga harus didesain ulang. Itu scope terpisah dan tidak perlu untuk memperbaiki GET side-effect.

---

## 8.4 Automated test B.2

Tambahkan test:

```text
GET edit tidak mengubah database
```

Scenario:

1. buat draft PR legacy dengan `material_master_id = null` dan `material_name` yang resolve-able;
2. snapshot row;
3. GET edit;
4. assert response OK;
5. refresh row;
6. assert persisted `material_master_id` tetap tidak berubah.

Jika temporary display resolution dipertahankan:

- assert form masih menunjukkan resolved material selection/display;
- tetapi database tetap unchanged.

Tambahkan test bahwa view rendering tidak memerlukan query-per-row jika project punya query-count helper.

---

# 9. B.3 — Supplier Quotation Index Query Explosion

## 9.1 Current behavior

### File

```text
app/Http/Controllers/Supplier/QuotationController.php
```

Untuk setiap period, current controller menjalankan:

```text
active PR query
quoted PR IDs query
responded count query
rejected count query
```

Kemudian untuk `unresponded_prs`, melakukan:

```text
Quotation::exists()
```

untuk setiap active PR.

Jadi complexity bukan hanya kira-kira `3N+1`.

Lebih tepat:

```text
Base query
+ O(number of periods)
+ O(number of active PRs)
```

Contoh kasar:

```text
10 period
100 active PR
```

bisa menghasilkan lebih dari seratus query tambahan tergantung distribusi data.

### Priority

```text
Medium-High untuk scalability/performance
```

Correctness impact rendah selama query menghasilkan angka benar, tetapi latency akan memburuk seiring data bertambah.

---

## 9.2 Refactor strategy

Tujuan:

> Tidak ada database query di dalam `foreach ($periods as $period)`.

### Step 1 — ambil period IDs

```php
$periodIds = $periods->pluck('id');
```

### Step 2 — prefetch visible active PRs sekali

Query semua PR yang:

```text
period_id IN periodIds
status IN submitted,bidding
visibleToSupplier(supplierId)
```

Select minimal fields:

```text
id
period_id
```

Group:

```php
$activePrsByPeriod = $activePrs->groupBy('period_id');
```

### Step 3 — prefetch supplier quotations sekali

Query quotations milik current supplier yang PR-nya berada di period tersebut.

Load minimal data:

```text
id
pr_id
supplier_id
status
```

serta `purchaseRequisition:id,period_id` atau equivalent join/select strategy.

Group by period.

### Step 4 — derive metrics in memory

Untuk setiap period hitung:

```text
total_prs
responded_prs
rejected_prs
unresponded_prs
```

Tanpa query baru.

Semantics existing harus dipertahankan.

---

## 9.3 Important semantic lock sebelum refactor

Current period query:

```php
Period::where('status', 'open')
    ->whereHas(...)
    ->orWhereHas(...supplier quotation...)
```

Secara logical dapat berarti:

```text
(open AND visible PR exists)
OR
supplier has quotation
```

Ini memungkinkan period non-open tetap muncul bila supplier mempunyai quotation historis.

Method comment mengatakan:

```text
Display open quotation periods.
```

Tetapi historical access mungkin memang disengaja.

### Sebelum refactor

Lock behavior dengan test:

```text
Closed period + existing supplier quotation
```

Tentukan existing expected behavior dari code/current product usage dan **pertahankan dalam performance refactor** kecuali ada explicit product decision untuk mengubahnya.

Jangan mengubah semantics visibility sambil melakukan query optimization.

---

## 9.4 Automated tests B.3

### Correctness snapshot

Buat dataset dengan:

```text
2+ periods
active visible PR
active invisible PR
quotation draft
quotation submitted
quotation rejected
PR tanpa quotation
closed period dengan quotation
```

Assert metric sebelum/expected:

```text
total_prs
responded_prs
rejected_prs
unresponded_prs
```

### Query-count regression

Jika memungkinkan:

1. capture query count untuk 1 period / few PR;
2. capture setelah jumlah PR meningkat signifikan;
3. assert query count tidak bertambah linear per PR.

Target bukan angka absolut yang terlalu brittle; target utamanya memastikan tidak ada query `exists()` per PR.

---

# 10. B.4 — Draft Dimension Validation

## Classification

**Bukan bug.**

Current architecture secara eksplisit mendukung:

```text
Draft     -> incomplete shape/dimensions allowed
Submitting -> relevant dimensions required
```

`PrItemProcessor::process()` menerima parameter:

```text
$submitting
```

kemudian hanya mewajibkan shape/dimensions ketika `$submitting = true`.

Controller juga secara eksplisit menentukan flag tersebut berdasarkan action.

### Action

Jangan ubah FormRequest menjadi `requiredIf` dalam mission ini.

Tambahkan komentar/documentation bila perlu agar developer berikutnya memahami alasan field dimensi `nullable`.

### Recommended test

Pastikan tetap ada automated coverage:

```text
Draft incomplete -> accepted
Submit incomplete -> validation failure
```

### Priority

```text
Documentation / regression guard
```

---

# 11. B.5 — Hollow Inner < Outer Rule Duplication

## Temuan

Rule:

```text
Hollow: inner diameter < outer diameter
```

berada di beberapa lokasi:

```text
PrItemProcessor
MaterialWeightCalculator
Supplier Quotation validation
```

Ini merupakan technical debt nyata karena business predicate yang sama dapat drift.

---

## 11.1 Jangan centralize error message di model

Hindari desain:

```php
PrItem::validateHollowDimensions(...): ?string
```

karena message context berbeda:

```text
Purchasing validation
Supplier offered dimensions
Weight calculation failure
```

Domain predicate boleh sama, presentation error message tidak harus sama.

---

## 11.2 Recommended design

Buat rule/helper kecil, misalnya:

```text
App\Support\Materials\MaterialDimensionRules
```

Contoh:

```php
final class MaterialDimensionRules
{
    public static function hasValidHollowDiameterPair(
        mixed $inner,
        mixed $outer,
    ): bool {
        if ($inner === null || $inner === '' || $outer === null || $outer === '') {
            return true;
        }

        if (! is_numeric($inner) || ! is_numeric($outer)) {
            return false;
        }

        return (float) $inner < (float) $outer;
    }
}
```

Caller tetap menentukan error/result masing-masing.

### Processor

```text
false -> field validation error d_inner
```

### Calculator

```text
false -> invalid WeightCalculationResult
```

### Supplier validation

```text
false -> quotation dimension validation error
```

### Priority

```text
Low-Medium / tech debt
```

### Rollout

Kerjakan terpisah dari UI five-column change.

---

# 12. Scope Separation / Rollout Plan Final

Jangan gabungkan seluruh A + B.2 + B.3 + B.5 menjadi satu giant change.

## PR / Commit A — Fixed 5 Dimension Columns

Scope:

```text
PrItem FIXED_DIMENSION_ORDER (optional/recommended)
_item_row.blade.php
create.blade.php
edit.blade.php
_material_shape_script.blade.php
_form_table_styles.blade.php
_import.blade.php regression compatibility
PurchaseRequisitionMaterialAutomationTest
MissionFiveImportTest
```

Outcome:

```text
5 fixed columns
5 always-present canonical inputs
no hidden mirror
no dynamic slot mapping
no dynamic header rewrite
shape switching works
import still works
preview still works
B.1 closed
```

Risk:

```text
Medium
```

because it changes UI/JS/input contract, but persistence schema remains unchanged.

---

## PR / Commit B — Read-only Edit & Legacy Material Resolution

Scope:

```text
PurchaseRequisitionController::edit
_item_row legacy resolver block
MaterialResolver usage
optional backfill command/service
feature tests
```

Outcome:

```text
GET edit has no DB writes
no MaterialResolver DB query in Blade
legacy resolution explicitly managed
```

Risk:

```text
Medium
```

because it touches legacy compatibility.

---

## PR / Commit C — Supplier Index Performance

Scope:

```text
Supplier\QuotationController::index
feature correctness tests
query-count regression
```

Outcome:

```text
no query inside period loop
no Quotation::exists per active PR
same visible periods and metrics
```

Risk:

```text
Medium
```

because aggregation semantics must remain identical.

---

## PR / Commit D — Dimension Domain Rule Cleanup

Scope:

```text
MaterialDimensionRules helper
PrItemProcessor
MaterialWeightCalculator
Supplier quotation validator
unit/feature tests
```

Outcome:

```text
single hollow diameter predicate
caller-specific messages remain local
```

Risk:

```text
Low-Medium
```

---

# 13. Detailed QA Checklist — PR/Commit A

## 13.1 Create — blank initial row

- [ ] Header = Thickness / Outer D. / Inner D. / Width / Length.
- [ ] DOM mempunyai 5 canonical dimension inputs walaupun shape blank.
- [ ] Kelima input disabled/hidden saat shape blank.
- [ ] Kelima cell menunjukkan `—`.
- [ ] Tidak ada `Dimension 1/2/3`.

## 13.2 Select Flat

- [ ] Thickness aktif.
- [ ] Width aktif.
- [ ] Length aktif.
- [ ] Outer D. = `—`.
- [ ] Inner D. = `—`.
- [ ] Preview weight tetap jalan.

## 13.3 Select Round

- [ ] Outer D. aktif.
- [ ] Length aktif.
- [ ] Thickness = `—`.
- [ ] Inner D. = `—`.
- [ ] Width = `—`.

## 13.4 Select Hollow

- [ ] Outer D. aktif.
- [ ] Inner D. aktif.
- [ ] Length aktif.
- [ ] Thickness = `—`.
- [ ] Width = `—`.

## 13.5 Shape switching

- [ ] Flat → Round members yang irrelevant dikosongkan.
- [ ] Round → Hollow Inner field dapat muncul/aktif meskipun awalnya irrelevant.
- [ ] Hollow → Flat Thickness dan Width dapat muncul/aktif.
- [ ] Repeated switching tidak kehilangan DOM input.
- [ ] Repeated switching tidak meninggalkan stale hidden value.

## 13.6 Mixed shape rows

- [ ] Add 3 rows: Flat, Round, Hollow.
- [ ] Header tidak berubah.
- [ ] Tiap row menunjukkan input/`—` sesuai shape masing-masing.

## 13.7 Draft

- [ ] Draft dapat disimpan dengan shape/dimension incomplete sesuai behavior existing.
- [ ] Canonical values relevan tersimpan.
- [ ] Irrelevant field tidak menyimpan stale value.

## 13.8 Submit

- [ ] Submit incomplete ditolak.
- [ ] Error field tampil pada canonical column yang benar.
- [ ] Submit complete berhasil.

## 13.9 Edit legacy/existing PR

- [ ] Existing Flat values benar.
- [ ] Existing Round values benar.
- [ ] Existing Hollow values benar.
- [ ] Tidak ada remapping slot.

## 13.10 Import Excel — append

- [ ] Flat import values muncul benar.
- [ ] Round import values muncul benar.
- [ ] Hollow import values muncul benar.
- [ ] Relevance state benar setelah `applyMaterialShapeRules()`.

## 13.11 Import Excel — replace/current behavior

Jika import UI mendukung replace/reset flow:

- [ ] Existing rows ditangani sesuai behavior current.
- [ ] Imported canonical values tidak hilang.
- [ ] Row numbering tetap benar.

## 13.12 Calculation preview

- [ ] Material change memicu preview normal.
- [ ] Shape change memicu preview.
- [ ] Dimension change memicu preview.
- [ ] Payload membawa relevant canonical fields.
- [ ] Weight value tetap sama dengan baseline untuk input yang sama.
- [ ] HS resolution tetap sama dengan baseline.

## 13.13 Responsive visual

- [ ] Desktop table masih readable.
- [ ] Horizontal scroll tidak rusak.
- [ ] Sticky number/material/action columns tetap bekerja.
- [ ] Mobile control height tetap usable.
- [ ] Dash `—` centered dan jelas.

---

# 14. Detailed QA Checklist — B.2

- [ ] Open edit tidak mengubah DB.
- [ ] Refresh edit berkali-kali tidak mengubah DB.
- [ ] Legacy material yang resolve-able tetap dapat ditampilkan secara kompatibel bila compatibility dibutuhkan.
- [ ] Blade tidak memanggil MaterialResolver/DB.
- [ ] Unmatched legacy material punya behavior/error yang jelas.
- [ ] Backfill command, bila dibuat, dapat dry-run.
- [ ] Backfill melaporkan matched/unmatched.

---

# 15. Detailed QA Checklist — B.3

- [ ] Supplier index menampilkan period yang sama dengan baseline.
- [ ] `total_prs` sama.
- [ ] `responded_prs` sama.
- [ ] `rejected_prs` sama.
- [ ] `unresponded_prs` sama.
- [ ] Closed period + historical quotation mengikuti existing expected semantics.
- [ ] Tidak ada query DB di loop period.
- [ ] Tidak ada `Quotation::exists()` per PR.
- [ ] Query count tidak tumbuh linear terhadap jumlah active PR.

---

# 16. Detailed QA Checklist — B.5

- [ ] Hollow inner < outer diterima.
- [ ] Hollow inner = outer ditolak.
- [ ] Hollow inner > outer ditolak.
- [ ] Processor menghasilkan error field yang sama seperti baseline.
- [ ] Calculator menghasilkan invalid result yang sama seperti baseline.
- [ ] Supplier quotation validation tetap menolak invalid pair.
- [ ] Flat/Round tidak terpengaruh.

---

# 17. Final Regression Test Suite

Minimal jalankan relevant existing tests setelah assertion lama direvisi:

```text
tests/Feature/PurchaseRequisitionMaterialAutomationTest.php
tests/Feature/MaterialCalculationTest.php
tests/Feature/ProcurementRevisionTest.php
tests/Feature/MissionFiveImportTest.php
```

Tambahkan test baru untuk B.2/B.3/B.5 pada file paling sesuai atau dedicated test file bila lebih bersih.

Setelah targeted tests hijau, jalankan broader test suite bila environment memungkinkan.

Important:

> Jangan menganggap test lama harus langsung hijau tanpa modification. Sebagian assertion lama memang mendeskripsikan architecture 3-slot dan wajib direwrite agar sesuai contract baru.

---

# 18. Definition of Done

Mission dianggap selesai hanya bila semua kondisi berikut terpenuhi.

## Bagian A

- [ ] Purchasing create memakai 5 fixed columns.
- [ ] Purchasing edit memakai 5 fixed columns.
- [ ] Fixed order = Thickness / Outer D. / Inner D. / Width / Length.
- [ ] Kelima canonical input selalu ada pada setiap row/template.
- [ ] Shape hanya mengontrol visibility/enabled state.
- [ ] Hidden canonical mirror dihapus.
- [ ] 3-slot mapping dihapus.
- [ ] Dynamic header rewriting dihapus.
- [ ] Material preview membaca canonical inputs langsung.
- [ ] Import Excel tetap kompatibel.
- [ ] Mixed shape tidak memengaruhi header.
- [ ] Automated tests telah direwrite untuk new contract.
- [ ] Manual JS regression shape switching lulus.

## B.2

- [ ] GET edit tidak menulis DB.
- [ ] Material resolution tidak dilakukan dari Blade.
- [ ] Legacy compatibility memiliki explicit strategy.

## B.3

- [ ] Supplier index tidak melakukan query di per-period/per-PR loop.
- [ ] Metrics dan visibility semantics tetap sama.

## B.4

- [ ] Draft-vs-submit behavior terdokumentasi/dilindungi test.
- [ ] Tidak ada perubahan validation semantics tanpa product requirement.

## B.5

- [ ] Hollow diameter predicate terpusat bila item ini dikerjakan.
- [ ] Error/result messages tetap context-specific.

---

# 19. Guardrails untuk Coding Agent

Agent yang mengeksekusi plan ini **WAJIB** mengikuti guardrails berikut:

1. Jangan membuat DB migration untuk Bagian A.
2. Jangan mengubah formulas weight/HS code sebagai bagian dari UI refactor.
3. Jangan render dimension input secara conditional berdasarkan initial shape.
4. Jangan membuat ulang hidden mirror architecture dengan nama berbeda.
5. Jangan memindahkan canonical value antar generic slot.
6. Jangan mengubah import contract agar bergantung pada visible slot.
7. Jangan mengubah draft-vs-submit semantics.
8. Jangan mengubah Supplier quotation UI pada PR/Commit A kecuali diperlukan untuk compile/test compatibility.
9. Jangan menggabungkan Supplier index performance refactor ke PR UI bila tidak diperlukan.
10. Jangan menganggap `DB::transaction()` menyelesaikan B.2; final state harus GET read-only.
11. Jangan mengubah period visibility semantics saat B.3 tanpa explicit decision.
12. Jangan menghapus `PRESENTATION_DIMENSIONS` sebelum repository-wide usage check membuktikan tidak ada caller lain.
13. Setelah tiap logical change, jalankan targeted tests sebelum melanjutkan.
14. Jika test gagal karena masih mengharapkan 3-slot contract, rewrite assertion dengan sengaja; jangan mempertahankan architecture lama hanya demi membuat test lama hijau.

---

# 20. Recommended Execution Order

```text
Phase 1
  Fixed five-column DOM + direct canonical inputs

Phase 2
  JS shape relevance + direct preview payload

Phase 3
  Import compatibility verification

Phase 4
  CSS cleanup

Phase 5
  Rewrite automated tests for new DOM contract

Phase 6
  Manual/automated regression Bagian A

--- deploy/review boundary ---

Phase 7
  B.2 read-only GET + remove resolver from Blade

--- deploy/review boundary ---

Phase 8
  B.3 Supplier index query optimization

--- deploy/review boundary ---

Phase 9
  B.5 domain predicate cleanup
```

B.4 hanya documentation/regression guard kecuali Product secara eksplisit meminta perubahan behavior.

---

# 21. Final Technical Decision

## Approved architecture

```text
                     PrItem
        +------------------------------+
        | DIMENSION_FIELDS             |
        | RELEVANT_DIMENSIONS          |
        | DIMENSION_LABELS             |
        | FIXED_DIMENSION_ORDER        |
        +---------------+--------------+
                        |
                        v
       Purchasing fixed 5-column table
                        |
        +---------------+---------------+
        |               |               |
        v               v               v
 canonical names   shape enablement   preview payload
        |               |               |
        +---------------+---------------+
                        |
                        v
              existing backend
              PrItemProcessor
```

## Rejected architecture

```text
canonical hidden fields
        |
        v
3 dynamic presentation slots
        |
        v
JS bidirectional synchronization
        |
        v
shape-dependent header rewriting
```

## Final assessment

Setelah revisi V2 ini, plan dianggap **layak dieksekusi** dengan satu syarat utama:

> Implementasi Bagian A harus mempertahankan lima canonical inputs di DOM untuk setiap row sejak awal, bukan membuat input hanya ketika shape saat render menganggap field tersebut relevant.

Prinsip tersebut menutup tiga risiko terbesar dari plan awal:

1. runtime shape switching gagal;
2. Import Excel kehilangan dimension values;
3. preview/kalkulasi kehilangan canonical input source setelah hidden mirror dihapus.

Dengan rollout yang dipisah, perubahan UI, legacy data compatibility, query optimization, dan domain-rule cleanup dapat direview serta diregresikan secara independen sehingga risiko production jauh lebih rendah.

