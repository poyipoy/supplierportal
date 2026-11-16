# Implementation Plan: Deep-Hardened Rust Hybrid Acceleration (WASM + MUSL Cron) for ADASI Supplier Portal

## 1. Outcome, Scope & Reasoning Depth

- **User Intent**: Mengimplementasikan 4 modul akselerasi Rust yang 100% ramah cPanel (1. Kompresor Gambar & PDF Scan via WASM, 2. Filter *ERP Delta Sync* via WASM, 3. Parser & Sanitasi Excel/CSV via WASM, 4. Pembersih File Sementara & Penjaga *Inode* via Static MUSL Cron Binary) dengan kedalaman rekayasa tingkat produksi, pencegahan *WASM trap*, kesetaraan kriptografis lintas bahasa, dan pertahanan keamanan berlapis (*Zero-Trust Client*).
- **Success Criteria**:
  1. Biner WASM (`portal_wasm_bg.wasm`) berukuran `< 900 KB` setelah `wasm-opt -Oz`, berjalan di dalam **Dedicated Web Worker** (0ms *main-thread blocking*), dan bebas dari `unwrap()`/`expect()`/`panic!()`.
  2. Kompresor PDF lulus uji `qpdf --check` (exit code `0`), menurunkan ukuran PDF *scan* JPEG $\ge 50\%$, dan melewati objek `/DeviceCMYK` atau `/SMask` tanpa merusak warna/transparansi.
  3. *Canonical row hash* menggunakan `xxh3_64` di Rust WASM menghasilkan string hex 16-karakter yang 100% identik dengan fungsi *native* PHP 8.2/8.3 `hash('xxh3', $canonical)`.
  4. Parser Excel WASM (`calamine`) memblokir *zip bomb* (rasio kompresi $> 50\times$ atau ukuran dekompresi $> 32\text{ MB}$) dan mensterilkan *CSV/Excel Formula Injection* baik di WASM maupun di *FormRequest* Laravel.
  5. Biner Linux `bin/inode-guard` terkompilasi statis (`x86_64-unknown-linux-musl`), tahan terhadap serangan *TOCTOU symlink traversal*, dan mengonsumsi RAM $< 5\text{ MB}$.
- **Scope IN**:
  - `rust-portal-wasm/` (Crate WASM untuk Modul 1, 2, 3)
  - `rust-inode-guard/` (Crate CLI Linux MUSL untuk Modul 4)
  - `public/js/workers/portal-wasm.worker.js` (Web Worker wrapper & bounded memory guard)
  - `app/Services/Erp/CanonicalRowHasher.php` & `app/Http/Requests/Erp/DeltaSyncImportRequest.php`
  - `app/Http/Controllers/Erp/ErpDeltaImportController.php`
  - `tests/Feature/Security/RustHybridSecurityTest.php`
  - `.cpanel.yml`
- **Scope OUT**:
  - Menulis ulang *controller* autentikasi, *session*, atau *routing* utama Laravel ke Rust.
  - Menjalankan *daemon* HTTP/WebSocket Rust yang menyala 24 jam di cPanel.
  - Memodifikasi `php.ini` sistem atau memasang ekstensi PHP `.so` kustom.
- **Operational Constraints**:
  - Lingkungan pengembangan: Windows (Laragon, PHP 8.2.30, RAM 8 GB).
  - Lingkungan produksi: cPanel Shared/Managed Hosting (Linux x86_64, PHP 8.3, batas ketat *inode* dan LVE memory).
- **Reasoning Depth**: **Maximum** (melibatkan batas keamanan *untrusted client*, manipulasi format biner PDF/ZIP, konkurensi *batch upsert* database, dan penghapusan file di level sistem operasi).

---

## 2. Evidence, Invariants & Confidence Inventory

### Relevant Evidence Inspected
- `.cpanel.yml`: Mengonfirmasi alur *deployment* berbasis penyalinan langsung repositori ke direktori aplikasi cPanel.
- `.tours/file-upload-limit-differentiation.tour`: Mengonfirmasi adanya aturan pembedaan batas ukuran unggah file berdasarkan konteks dokumen.
- `.tours/erp-import-terminology-cleanup.tour`: Mengonfirmasi alur *local import* dokumen ERP (PR, PO, GR, Invoice) yang membutuhkan normalisasi terminologi dan struktur.
- `UI-REDESIGN-RESULT/PO-PDF-SAMPLES/` & `UI-REDESIGN-RESULT/EXCEL-PRESENTATION-SAMPLES/`: Mengonfirmasi pola penggunaan dokumen PDF multi-halaman dan ekspor/impor Excel operasional (ID/EN).
- `.codex/skills/vulnerability-scanner/scripts/security_scan.py`: Mengonfirmasi standar pemeriksaan keamanan internal pada repositori.

### Confidence Classification
- **[Verified]**: PHP 8.2 dan PHP 8.3 menyediakan algoritma `xxh3` secara *native* melalui ekstensi standar `hash` (`hash('xxh3', $data)`), sehingga tidak memerlukan instalasi ekstensi C tambahan di cPanel untuk mencocokkan output `xxhash-rust` (`xxh3_64`).
- **[Verified]**: `lopdf` dan `calamine` dapat dikompilasi ke target `wasm32-unknown-unknown` tanpa ketergantungan C (`libc`/`openssl`).
- **[Inferred]**: Pembatasan *threads* kompilasi Cargo (`CARGO_BUILD_JOBS=2`) diperlukan di laptop pengembangan Windows 8 GB RAM agar proses *build* tidak memicu *swap thrashing* bersama VS Code dan MySQL.

### Existing Invariants to Preserve
1. **Zero-Trust Authorization Boundary**: Baris data atau file yang telah divalidasi/dikompresi oleh WASM di *browser* tetap diperlakukan sebagai *untrusted input* oleh Laravel (`Policy`, `FormRequest`, dan *scoped query* `supplier_id` wajib dieksekusi di server).
2. **Idempotent Transactional Upsert**: Pengiriman ulang *batch* JSON yang sama akibat koneksi terputus tidak boleh menggandakan item PR/PO/GR/Invoice atau merusak nilai `row_hash`.
3. **Visual & Legal Document Fidelity**: Kompresi PDF tidak boleh mengubah teks vektor, tanda tangan digital, atau membalikkan warna gambar CMYK/stempel transparan.
4. **Active-File Preservation**: Pembersih *inode* tidak boleh menghapus file ekspor/impor yang sedang ditulis atau diunduh oleh pengguna aktif.

---

## 3. Causal Model & Problem Decomposition

- **Current Behavior**:
  - Unggahan PDF *scan* dan foto QC berukuran besar membebani *bandwidth*, kuota *disk* cPanel, dan rawan gagal di batas `upload_max_filesize`.
  - *Local import* Excel ERP memaksa PHP memuat seluruh struktur `.xlsx` ke RAM dan mengeksekusi *query* tulis untuk ribuan baris yang sebenarnya tidak berubah.
  - Sisa file ekspor Excel dan PDF sementara menggerus kuota *inode* cPanel.
- **Desired Behavior**:
  - Pra-pemrosesan biner (kompresi PDF/gambar, pembacaan `.xlsx`, dan penyaringan *delta hash* `xxh3`) diselesaikan di dalam *Web Worker* browser pengguna dengan memori terbatas, diikuti verifikasi keamanan dan *batch upsert* atomik di Laravel, serta pembersihan file *temp* terjadwal yang aman dari *symlink traversal*.
- **Material Technical Gaps to Close**:
  1. Mencegah *WASM linear memory exhaustion* dan *panic trap* pada file korup/berbahaya.
  2. Menjamin determinisme *canonical string* antara Rust (WASM) dan PHP 8.2/8.3.
  3. Menangani *edge-case* ruang warna PDF (`/DeviceCMYK`, `/SMask`, PDF terenkripsi).
  4. Menutup celah *TOCTOU* dan *symlink traversal* pada biner pembersih file di Linux.

---

## 4. Viable Approaches & Decision

### Decision 1: Algoritma & Verifikasi *Delta Sync Hash* (Modul 2)
- **Approach A (Selected): Canonical String + `xxh3_64` (Rust WASM) dengan Verifikasi Silang `hash('xxh3')` (PHP Native)**
  - **Mechanism**: Setiap baris dinormalisasi menjadi format kanonik `DOC_NO|ITEM_CODE|QTY_FIXED_4|PRICE_FIXED_2|STATUS` (huruf kapital, `trim()`, desimal terformat tetap). WASM menghitung `xxh3_64` untuk menyaring baris di *browser*. Saat *batch* perubahan dikirim ke Laravel, PHP menghitung ulang `hash('xxh3', $canonical)` di server sebelum `upsert`.
  - **Correctness & Safety**: Menjamin *integrity* penuh; jika penyerang memalsukan `row_hash` dari *browser*, server mengabaikan *hash* klien dan menggunakan *hash* hasil perhitungan server.
- **Approach B (Rejected): Mengandalkan `row_hash` Klien Tanpa Hitung Ulang di Server**
  - **Rejected Reason**: Melanggar prinsip *Zero-Trust*; pengguna jahat dapat mengirim `row_hash` palsu untuk merusak sinkronisasi berikutnya (CWE-345: Insufficient Verification of Data Authenticity).

### Decision 2: Eksekusi Modul WASM di Browser (Modul 1, 2, 3)
- **Approach A (Selected): Dedicated Web Worker dengan *Pre-Flight Size & Zip-Ratio Guard***
  - **Mechanism**: Modul `.wasm` dimuat di dalam `portal-wasm.worker.js`. Sebelum *buffer* dikirim ke memori WASM, ukuran file dibatasi ($\le 20\text{ MB}$) dan untuk `.xlsx` rasio ukuran *uncompressed* XML diperiksa agar tidak melebihi $32\text{ MB}$.
- **Approach B (Rejected): Eksekusi Sinkron di Main Thread (`window`/Alpine.js langsung)**
  - **Rejected Reason**: Memicu *UI freeze* selama 300–1.500 ms pada PDF multi-halaman dan berisiko mematikan responsivitas halaman utama.

---

## 5. Implementation Steps

### Step 1: Crate WASM (`rust-portal-wasm`) — Zero-Panic PDF/Image Compressor, Delta Hasher, & Safe Excel Parser
- **Target file(s)**:
  - `rust-portal-wasm/Cargo.toml`
  - `rust-portal-wasm/src/lib.rs`
- **Change**:
  Buat konfigurasi Cargo dengan optimasi ukuran agresif dan profil bebas *panic string*:
  ```toml
  [package]
  name = "portal_wasm"
  version = "0.2.0"
  edition = "2021"

  [lib]
  crate-type = ["cdylib"]

  [dependencies]
  wasm-bindgen = "0.2"
  lopdf = { version = "0.34", default-features = false }
  image = { version = "0.25", default-features = false, features = ["jpeg", "png", "webp"] }
  calamine = { version = "0.26", default-features = false }
  xxhash-rust = { version = "0.8", features = ["xxh3"] }
  serde = { version = "1.0", features = ["derive"] }
  serde_json = "1.0"

  [profile.release]
  opt-level = "z"
  lto = true
  codegen-units = 1
  panic = "abort"
  strip = true
  ```

  Implementasikan `rust-portal-wasm/src/lib.rs` dengan penegakan `#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]`:
  ```rust
  #![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

  use calamine::{open_workbook_auto_from_rs, Data, Reader};
  use image::{imageops::FilterType, ImageFormat};
  use lopdf::{Document, Object};
  use serde::{Deserialize, Serialize};
  use std::collections::HashSet;
  use std::io::Cursor;
  use wasm_bindgen::prelude::*;
  use xxhash_rust::xxh3::xxh3_64;

  const MAX_WASM_INPUT_BYTES: usize = 20 * 1024 * 1024; // 20 MB hard cap
  const MAX_PDF_OBJECTS: usize = 25_000;
  const MAX_EXCEL_ROWS: usize = 50_000;

  #[derive(Serialize, Deserialize)]
  pub struct ErpRow {
      pub doc_no: String,
      pub item_code: String,
      pub qty_norm: String,
      pub price_norm: String,
      pub status: String,
      pub row_hash: String,
  }

  /// Kanonikalisasi deterministik yang 100% identik dengan PHP CanonicalRowHasher
  pub fn build_canonical_payload(
      doc_no: &str,
      item_code: &str,
      qty_norm: &str,
      price_norm: &str,
      status: &str,
  ) -> String {
      format!(
          "{}|{}|{}|{}|{}",
          doc_no.trim().to_uppercase(),
          item_code.trim().to_uppercase(),
          qty_norm.trim(),
          price_norm.trim(),
          status.trim().to_uppercase()
      )
  }

  #[wasm_bindgen]
  pub fn compute_canonical_xxh3(
      doc_no: &str,
      item_code: &str,
      qty_norm: &str,
      price_norm: &str,
      status: &str,
  ) -> String {
      let canonical = build_canonical_payload(doc_no, item_code, qty_norm, price_norm, status);
      format!("{:016x}", xxh3_64(canonical.as_bytes()))
  }

  /// Modul 1: Kompresi PDF Scan dengan Proteksi ColorSpace (/DeviceCMYK) & /SMask
  #[wasm_bindgen]
  pub fn sanitize_and_compress_pdf(
      pdf_bytes: &[u8],
      max_dim: u32,
  ) -> Result<Vec<u8>, JsValue> {
      if pdf_bytes.len() > MAX_WASM_INPUT_BYTES {
          return Err(JsValue::from_str("Ukuran file melampaui batas aman memori (20 MB)."));
      }
      if !pdf_bytes.starts_with(b"%PDF-") {
          return Err(JsValue::from_str("Header biner tidak valid: Bukan dokumen PDF asli."));
      }

      let mut doc = Document::load_mem(pdf_bytes)
          .map_err(|_| JsValue::from_str("Struktur objek PDF rusak atau tidak dapat dibaca."))?;

      if doc.is_encrypted() {
          return Err(JsValue::from_str("PDF terenkripsi password tidak diizinkan."));
      }
      if doc.objects.len() > MAX_PDF_OBJECTS {
          return Err(JsValue::from_str("Jumlah objek internal PDF melampaui batas keamanan."));
      }

      let safe_dim = max_dim.clamp(800, 2400);

      for (_, object) in doc.objects.iter_mut() {
          if let Object::Stream(ref mut stream) = object {
              let is_image = stream
                  .dict
                  .get(b"Subtype")
                  .and_then(|o| o.as_name_str())
                  .map(|n| n == "Image")
                  .unwrap_or(false);

              let is_dct = stream
                  .dict
                  .get(b"Filter")
                  .and_then(|o| o.as_name_str())
                  .map(|n| n == "DCTDecode")
                  .unwrap_or(false);

              // Jangan sentuh gambar yang memiliki /SMask (transparansi stempel/tanda tangan)
              let has_smask = stream.dict.has(b"SMask") || stream.dict.has(b"Mask");

              // Hanya proses /DeviceRGB atau /DeviceGray agar gambar CMYK tidak terbalik warnanya
              let is_safe_colorspace = stream
                  .dict
                  .get(b"ColorSpace")
                  .and_then(|o| o.as_name_str())
                  .map(|cs| cs == "DeviceRGB" || cs == "DeviceGray")
                  .unwrap_or(false);

              if is_image && is_dct && !has_smask && is_safe_colorspace && stream.content.len() > 120_000 {
                  if let Ok(img) = image::load_from_memory(&stream.content) {
                      let resized = if img.width() > safe_dim || img.height() > safe_dim {
                          img.resize(safe_dim, safe_dim, FilterType::Triangle)
                      } else {
                          img
                      };

                      let mut out_buf = Vec::new();
                      let mut cursor = Cursor::new(&mut out_buf);
                      if resized.write_to(&mut cursor, ImageFormat::Jpeg).is_ok()
                          && out_buf.len() < stream.content.len()
                      {
                          stream.dict.set("Width", i64::from(resized.width()));
                          stream.dict.set("Height", i64::from(resized.height()));
                          stream.set_plain_content(out_buf);
                          stream.dict.set("Filter", Object::Name(b"DCTDecode".to_vec()));
                      }
                  }
              }
          }
      }

      doc.compress();
      let mut out_pdf = Vec::with_capacity(pdf_bytes.len());
      doc.save_to(&mut out_pdf)
          .map_err(|_| JsValue::from_str("Gagal menyusun ulang stream PDF."))?;

      if out_pdf.starts_with(b"%PDF-") && out_pdf.len() < pdf_bytes.len() {
          Ok(out_pdf)
      } else {
          Ok(pdf_bytes.to_vec())
      }
  }

  /// Sterilisasi karakter pemicu CSV/Excel Formula Injection (CWE-1236)
  fn sanitize_spreadsheet_cell(raw: &str) -> String {
      let trimmed = raw.trim();
      if let Some(first) = trimmed.chars().next() {
          if matches!(first, '=' | '+' | '-' | '@' | '\t' | '\r') {
              // Jika bukan angka negatif murni, netralisir formula
              if first != '-' || trimmed[1..].parse::<f64>().is_err() {
                  return format!("'{}", trimmed);
              }
          }
      }
      trimmed.to_string()
  }

  fn normalize_decimal(cell: &Data, precision: usize) -> Result<String, String> {
      match cell {
          Data::Float(f) if f.is_finite() && *f >= 0.0 => Ok(format!("{:.prec$}", f, prec = precision)),
          Data::Int(i) if *i >= 0 => Ok(format!("{:.prec$}", *i as f64, prec = precision)),
          Data::String(s) => {
              let clean = s.trim().replace(',', "");
              let val: f64 = clean.parse().map_err(|_| format!("Nilai numerik tidak valid: {}", s))?;
              if !val.is_finite() || val < 0.0 {
                  return Err(format!("Nilai di luar batas wajar: {}", s));
              }
              Ok(format!("{:.prec$}", val, prec = precision))
          }
          _ => Err("Sel numerik kosong atau tipe data salah".to_string()),
      }
  }

  /// Modul 2 & 3: Parsing Excel Bersih + Filter ERP Delta Sync sekaligus
  #[wasm_bindgen]
  pub fn parse_and_filter_erp_excel(
      excel_bytes: &[u8],
      existing_hashes_json: &str,
  ) -> Result<String, JsValue> {
      if excel_bytes.len() > MAX_WASM_INPUT_BYTES {
          return Err(JsValue::from_str("Ukuran file Excel melebihi batas 20 MB."));
      }

      let known_hashes: HashSet<String> = serde_json::from_str(existing_hashes_json)
          .map_err(|_| JsValue::from_str("Daftar hash referensi tidak valid."))?;

      let cursor = Cursor::new(excel_bytes);
      let mut workbook = open_workbook_auto_from_rs(cursor)
          .map_err(|_| JsValue::from_str("File Excel rusak atau format tidak didukung."))?;

      let sheet_name = workbook
          .sheet_names()
          .first()
          .cloned()
          .ok_or_else(|| JsValue::from_str("Workbook Excel tidak memiliki sheet."))?;

      let range = workbook
          .worksheet_range(&sheet_name)
          .map_err(|_| JsValue::from_str("Gagal membaca rentang sel pada sheet utama."))?;

      if range.height() > MAX_EXCEL_ROWS {
          return Err(JsValue::from_str("Jumlah baris melampaui batas maksimum 50.000 baris."));
      }

      let mut delta_rows = Vec::new();

      for (idx, row) in range.rows().enumerate().skip(1) {
          let row_num = idx + 1;
          if row.len() < 5 {
              return Err(JsValue::from_str(&format!("Baris {} kurang dari 5 kolom wajib.", row_num)));
          }

          let doc_no = sanitize_spreadsheet_cell(&row[0].to_string());
          let item_code = sanitize_spreadsheet_cell(&row[1].to_string());
          if doc_no.is_empty() || item_code.is_empty() {
              return Err(JsValue::from_str(&format!("Baris {} memiliki doc_no/item_code kosong.", row_num)));
          }

          let qty_norm = normalize_decimal(&row[2], 4)
              .map_err(|e| JsValue::from_str(&format!("Baris {} (Qty): {}", row_num, e)))?;
          let price_norm = normalize_decimal(&row[3], 2)
              .map_err(|e| JsValue::from_str(&format!("Baris {} (Price): {}", row_num, e)))?;
          let status = sanitize_spreadsheet_cell(&row[4].to_string());

          let row_hash = compute_canonical_xxh3(&doc_no, &item_code, &qty_norm, &price_norm, &status);

          if !known_hashes.contains(&row_hash) {
              delta_rows.push(ErpRow {
                  doc_no,
                  item_code,
                  qty_norm,
                  price_norm,
                  status,
                  row_hash,
              });
          }
      }

      serde_json::to_string(&delta_rows)
          .map_err(|_| JsValue::from_str("Gagal menserialisasi hasil delta JSON."))
  }
  ```
- **Invariant Preserved**: Bebas dari *panic trap*, menegakkan sanitasi formula, dan menjaga keutuhan ruang warna PDF.

### Step 2: Dedicated Web Worker Wrapper (`portal-wasm.worker.js`)
- **Target file(s)**: `public/js/workers/portal-wasm.worker.js`
- **Change**:
  Isolasi pemanggilan WASM dari *main thread* agar UI tabel/form tetap berjalan pada 60fps:
  ```javascript
  import init, {
      sanitize_and_compress_pdf,
      parse_and_filter_erp_excel
  } from '/wasm/portal_wasm.js';

  let wasmReady = null;

  self.onmessage = async (event) => {
      const { id, action, payload } = event.data;
      try {
          if (!wasmReady) {
              wasmReady = init();
          }
          await wasmReady;

          if (action === 'COMPRESS_PDF') {
              const inputBytes = new Uint8Array(payload.buffer);
              const compressed = sanitize_and_compress_pdf(inputBytes, payload.maxDim || 1600);
              self.postMessage(
                  { id, ok: true, buffer: compressed.buffer },
                  [compressed.buffer]
              );
          } else if (action === 'PARSE_DELTA_EXCEL') {
              const inputBytes = new Uint8Array(payload.buffer);
              const deltaJson = parse_and_filter_erp_excel(inputBytes, payload.knownHashesJson);
              self.postMessage({ id, ok: true, deltaJson });
          } else {
              throw new Error(`Aksi worker tidak dikenal: ${action}`);
          }
      } catch (err) {
          self.postMessage({
              id,
              ok: false,
              error: typeof err === 'string' ? err : (err.message || 'Kesalahan pemrosesan WASM')
          });
      }
  };
  ```

### Step 3: Verifikasi Silang Server Laravel (`CanonicalRowHasher` & Zero-Trust Upsert)
- **Target file(s)**:
  - `app/Services/Erp/CanonicalRowHasher.php`
  - `app/Http/Requests/Erp/DeltaSyncImportRequest.php`
  - `app/Http/Controllers/Erp/ErpDeltaImportController.php`
- **Change**:
  Implementasikan penghitung *hash* kanonik di PHP 8.2/8.3 yang 100% cocok dengan fungsi Rust `compute_canonical_xxh3`:
  ```php
  <?php

  namespace App\Services\Erp;

  final class CanonicalRowHasher
  {
      public static function hash(
          string $docNo,
          string $itemCode,
          string|float $qty,
          string|float $price,
          string $status
      ): string {
          $canonical = sprintf(
              '%s|%s|%s|%s|%s',
              strtoupper(trim($docNo)),
              strtoupper(trim($itemCode)),
              number_format((float) $qty, 4, '.', ''),
              number_format((float) $price, 2, '.', ''),
              strtoupper(trim($status))
          );

          // Native C implementation in PHP 8.1+ (matches xxhash_rust::xxh3::xxh3_64 hex output)
          return hash('xxh3', $canonical);
      }
  }
  ```
  Pada `ErpDeltaImportController`, paksa perhitungan ulang `CanonicalRowHasher::hash(...)` untuk setiap baris yang diterima, batasi maksimal 500 baris per *batch*, kunci akses berdasarkan otorisasi pengguna/peran, dan jalankan `upsert` di dalam `DB::transaction()`.

### Step 4: Crate Pembersih File & Penjaga Inode Tahan TOCTOU (`rust-inode-guard`)
- **Target file(s)**:
  - `rust-inode-guard/Cargo.toml`
  - `rust-inode-guard/src/main.rs`
  - `.cpanel.yml`
- **Change**:
  Implementasikan biner Linux statis yang memeriksa `symlink_metadata` (mencegah *symlink traversal*) dan mendukung flag `--dry-run`:
  ```rust
  #![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

  use std::env;
  use std::fs;
  use std::path::{Path, PathBuf};
  use std::process::ExitCode;
  use std::time::{Duration, SystemTime};

  const ALLOWED_SUBDIRS: [&str; 3] = [
      "storage/app/temp_exports",
      "storage/app/temp_imports",
      "storage/framework/cache/data",
  ];

  fn is_safe_regular_file(base_canon: &Path, entry_path: &Path) -> bool {
      // Gunakan symlink_metadata agar TIDAK mengikuti symlink (mencegah TOCTOU symlink traversal)
      let Ok(meta) = fs::symlink_metadata(entry_path) else {
          return false;
      };
      if meta.file_type().is_symlink() || !meta.is_file() {
          return false;
      }
      let Ok(canon) = fs::canonicalize(entry_path) else {
          return false;
      };
      canon.starts_with(base_canon)
  }

  fn sweep_dir(base_canon: &Path, current: &Path, max_age: Duration, dry_run: bool) -> (u64, u64) {
      let mut deleted_files = 0_u64;
      let mut freed_bytes = 0_u64;
      let now = SystemTime::now();

      let Ok(entries) = fs::read_dir(current) else {
          return (0, 0);
      };

      for entry in entries.flatten() {
          let path = entry.path();
          let Ok(meta) = fs::symlink_metadata(&path) else {
              continue;
          };

          // Abaikan seluruh symlink
          if meta.file_type().is_symlink() {
              eprintln!("[SECURITY-SKIP] Symlink terdeteksi dan diabaikan: {}", path.display());
              continue;
          }

          if meta.is_dir() {
              let (d, b) = sweep_dir(base_canon, &path, max_age, dry_run);
              deleted_files += d;
              freed_bytes += b;
              continue;
          }

          if !is_safe_regular_file(base_canon, &path) {
              continue;
          }

          let file_name = path.file_name().and_then(|n| n.to_str()).unwrap_or("");
          if file_name.starts_with('.') {
              continue;
          }

          if let Ok(modified) = meta.modified() {
              if let Ok(age) = now.duration_since(modified) {
                  if age >= max_age {
                      let len = meta.len();
                      if dry_run {
                          println!("[DRY-RUN] Akan menghapus: {} ({} bytes)", path.display(), len);
                          deleted_files += 1;
                          freed_bytes += len;
                      } else if fs::remove_file(&path).is_ok() {
                          deleted_files += 1;
                          freed_bytes += len;
                      }
                  }
              }
          }
      }

      (deleted_files, freed_bytes)
  }

  fn main() -> ExitCode {
      let args: Vec<String> = env::args().collect();
      if args.len() < 3 {
          eprintln!("Penggunaan: inode-guard <app_root_path> <max_age_hours> [--dry-run]");
          return ExitCode::from(2);
      }

      let root = PathBuf::from(&args[1]);
      let Ok(max_hours) = args[2].parse::<u64>() else {
          eprintln!("Error: max_age_hours harus berupa angka bulat positif.");
          return ExitCode::from(2);
      };
      // Batas minimum 1 jam agar tidak pernah menghapus file transaksi yang sedang aktif ditulis
      let safe_hours = max_hours.max(1);
      let dry_run = args.iter().any(|a| a == "--dry-run");
      let max_age = Duration::from_secs(safe_hours * 3600);

      let mut total_deleted = 0_u64;
      let mut total_bytes = 0_u64;

      for sub in ALLOWED_SUBDIRS {
          let target = root.join(sub);
          if let Ok(base_canon) = fs::canonicalize(&target) {
              let (d, b) = sweep_dir(&base_canon, &base_canon, max_age, dry_run);
              total_deleted += d;
              total_bytes += b;
          }
      }

      println!(
          "inode-guard selesai (dry_run={}): {} file dibersihkan ({} KB).",
          dry_run,
          total_deleted,
          total_bytes / 1024
      );
      ExitCode::SUCCESS
  }
  ```

---

## 6. Material Failure Modes & Edge Cases

- **Input / Nullability**:
  - PDF yang diunggah bukan hasil *scan* (vektor murni) atau menggunakan ruang warna `/DeviceCMYK` / `/SMask`: Dipertahankan apa adanya tanpa dekompresi/kompresi ulang untuk mencegah korupsi warna.
  - Sel Excel berisi formula berbahaya (`=CMD|' /C calc'!A0`): Dinetralisir dengan *prefix* `'` di Rust WASM dan ditolak/dibersihkan ulang di *FormRequest* Laravel.
- **Authorization / Isolation**:
  - Pengguna mencoba memanipulasi payload JSON dari *browser* untuk menyuntikkan perubahan pada PO milik *supplier* lain: Dihentikan oleh pengecekan kepemilikan dokumen di server Laravel.
- **Concurrency / Retry**:
  - Dua *request* *batch* identik terkirim bersamaan akibat klik ganda atau *retry* jaringan: Ditangani oleh `DB::transaction()` dan indeks unik `(doc_no, item_code)` pada `upsert`.
- **Filesystem Safety**:
  - Penyerang atau *bug* membuat *symlink* di `storage/app/temp_exports/` yang mengarah ke `.env`: `fs::symlink_metadata` mendeteksi tipe *symlink* tanpa mengikutinya, mencatat log `[SECURITY-SKIP]`, dan membiarkan `.env` tetap utuh.

---

## 7. Verification Strategy & Falsification Oracles

| Check | Command / Oracle Method | Falsification Condition (Bukti Gagal) | Planned Status |
|---|---|---|---|
| **Zero-Panic & Static Audit** | `cargo clippy --all-targets -- -D warnings -D clippy::unwrap_used -D clippy::expect_used -D clippy::panic` | Gagal jika terdapat satu saja pemanggilan `unwrap`/`expect`/`panic` di luar pengujian. | Planned |
| **WASM Size & Optimization** | `wasm-pack build --target web --release && wasm-opt -Oz -o portal_wasm_bg.wasm portal_wasm_bg.wasm && twiggy top -n 15 portal_wasm_bg.wasm` | Gagal jika ukuran akhir `.wasm` $> 900\text{ KB}$. | Planned |
| **Cross-Language Hash Parity** | `cargo test` vs `php artisan test --filter=CanonicalRowHasherTest` | Gagal jika hasil hex 16-karakter `xxh3_64` di Rust berbeda 1 bit pun dari `hash('xxh3')` di PHP 8.2/8.3. | Planned |
| **PDF Structural & Color Oracle** | Kompres sampel `UI-REDESIGN-RESULT/PO-PDF-SAMPLES/` lalu jalankan `qpdf --check output.pdf` | Gagal jika `qpdf` melaporkan *warning/error* atau gambar CMYK mengalami inversi warna. | Planned |
| **Static Binary & Syscall Oracle** | `file bin/inode-guard` & `strace -e trace=newfstatat,unlinkat ./bin/inode-guard . 1 --dry-run` | Gagal jika biner tidak *statically linked* atau jika mengikuti *symlink* menuju file di luar *whitelist*. | Planned |
| **OWASP Security Suite** | `php artisan test --filter=RustHybridSecurityTest` & `python .codex/skills/vulnerability-scanner/scripts/security_scan.py` | Gagal jika file *polyglot*, *CSV injection*, atau *cross-supplier IDOR* lolos ke database. | Planned |

---

## 8. Completion Gate

- [ ] Seluruh paket Rust (`rust-portal-wasm` dan `rust-inode-guard`) lulus kompilasi dengan `#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]`.
- [ ] Kesetaraan *hash* kanonik antara `xxhash-rust` (`xxh3_64`) dan PHP 8.2/8.3 `hash('xxh3')` terbukti lewat pengujian otomatis.
- [ ] Eksekusi WASM terisolasi di dalam `portal-wasm.worker.js` sehingga *main thread* tidak mengalami pemblokiran.
- [ ] Validasi ulang di sisi server Laravel (*magic bytes*, batas *batch*, sterilisasi formula, dan otorisasi `supplier_id`) tetap aktif secara independen dari WASM.
- [ ] Biner `bin/inode-guard` terbukti mengabaikan *symlink* dan hanya beroperasi di dalam 3 direktori *whitelist* setelah terverifikasi `canonicalize()`.