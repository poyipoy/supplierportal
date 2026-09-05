---
name: boost
description: High-effort reasoning and adaptive engineering protocol. Enforces evidence-first investigation, complexity-proportional reasoning, root-cause diagnosis, minimal necessary change, and rigorous verification.
category: engineering
risk: safe
source: local
tags: "[deep-think, adaptive-engineering, evidence-first, minimal-change, verification]"
---

# Boost: High-Effort Reasoning & Adaptive Engineering

Skill ini mengadopsi prinsip inti dari **Gemini High-Effort Reasoning Rules (Deep Think — Adaptive Engineering)**. Tujuannya adalah memastikan setiap keputusan teknis didasarkan pada bukti nyata di codebase (*evidence-based*), proporsional terhadap kompleksitas, minim risiko regresi, dan terverifikasi secara jujur.

---

## 🎯 Core Principle

> **Evidence → Correctness → Verification → Simplicity → Maintainability**  
> **Understand first → Change minimally → Verify explicitly.**

---

## 0. Scope & Complexity Trigger

Gunakan reasoning mendalam secara **proporsional** terhadap kompleksitas dan risiko task.

### Complexity Test
Perlakukan task sebagai **kompleks** jika setidaknya salah satu kondisi berikut terpenuhi:
1. Ada beberapa pendekatan yang *genuinely valid*.
2. Kesalahan dapat menimbulkan bug, regresi, celah keamanan, kehilangan data, atau masalah arsitektural.
3. Solusi berisiko gagal secara diam-diam (*silent failure*).
4. Terdapat ketergantungan/interaksi antar-komponen yang perlu dipahami secara mendalam.
5. Perubahan memengaruhi beberapa bagian sistem yang saling terhubung.

* **Jika Kompleks:** Terapkan protokol penuh (Fase 1 s.d. 6).
* **Jika Sederhana:** Jawab langsung, lugas, dan natural. **Jangan membuat masalah sederhana terlihat kompleks.**

---

## 1. Evidence Before Assumption

Prioritaskan bukti (*evidence*) di atas asumsi. Jangan menebak apa yang bisa diperiksa langsung.

Selalu klasifikasikan pemahaman dalam 3 tingkat kepastian:
* **[Verified]** — Telah diperiksa dan dibuktikan langsung dari codebase, log, skema DB, atau hasil eksekusi tes.
* **[Inferred]** — Kesimpulan logis yang kuat dan ditarik langsung dari fakta terverifikasi.
* **[Assumed]** — Hipotesis kerja yang belum diverifikasi; wajib diuji sebelum dijadikan dasar keputusan.

---

## 2. Codebase-First Engineering

Untuk existing project, jangan mengandalkan generic best practice jika bertentangan dengan implementasi riil:
1. **Periksa Implementasi Eksisting:** Buka dan telusuri controller, model, migrasi, routes, request, dan policy terkait sebelum merancang solusi.
2. **Pahami Arsitektur & Alur Data:** Ketahui siapa pemanggil method, efek samping database transaction, event, listener, dan job antrean.
3. **Patuhi Konvensi Proyek:** Gunakan helper, trait, enum, naming convention, dan status manager yang sudah ada di repositori.
4. **Cari Reusable Logic:** Gunakan komponen atau logic yang sudah tersedia sebelum membuat fungsi atau abstraksi baru.

---

## 3. Conditional Alternative Analysis

Jika terdapat lebih dari satu pendekatan yang valid:
* Evaluasi alternatif nyata yang relevan (hindari membuat *straw-man alternative* hanya demi formalitas).
* Bandingkan trade-off material secara objektif:
  * **Correctness & Safety**
  * **Maintainability & Cognitive Overhead**
  * **Performance & Resource Footprint** (hindari N+1 query, unbounded payload, lock contention)
  * **Blast Radius & Regression Risk**
  * **Implementation Effort & Simplicity**
* Jika hanya ada satu solusi yang realistis dan tepat, lanjutkan tanpa memaksakan komparasi buatan.

---

## 4. Material Edge Cases & Failure Modes

Sebelum mengeksekusi solusi kompleks, evaluasi potensi kegagalan nyata:
* **Batas Input & Nilai Ekstrem:** Koleksi kosong (`empty`), nilai `null`, angka negatif, string melebihi limit.
* **Concurrency & Race Conditions:** Double submission, TOCTOU (*Time-of-Check to Time-of-Use*), kebutuhan row-level locking (`lockForUpdate`).
* **Otorisasi & Isolasi Data:** Validasi kepemilikan data pengguna (misal isolasi supplier `supplier_id === auth()->id()`), pencegahan IDOR, dan pengecekan policy/gate.
* **Integritas Data & Kegagalan Parsial:** Penggunaan `DB::transaction()` pada multi-table writes untuk mencegah data korup jika terjadi interupsi.

---

## 5. Root Cause Before Symptom

Untuk tugas debugging:
1. Identifikasi **akar masalah utama (*root cause*)**, bukan hanya meredam gejala (*symptom*).
2. Bedakan antara gejala tampak, faktor pemicu (*contributing factor*), dan cacat desain mendasar.
3. Jangan berhenti pada *workaround* jika akar masalah dapat diperbaiki secara aman dan terukur. Gunakan *workaround* hanya jika ada batasan teknis yang sah atau risiko perubahan akar masalah terlalu tinggi.

---

## 6. Minimal Necessary Change & Preserve Existing Behavior

* **Pilih Perubahan Terkecil:** Buat perubahan seminimal mungkin yang menyelesaikan kebutuhan secara tuntas.
* **Hindari:**
  * Refactoring kosmetik pada file yang tidak berkaitan.
  * *Premature abstraction* (menambah layer Service, Repository, DTO tanpa kebutuhan konkret).
  * Menambah dependency baru jika library bawaan sudah mencukupi.
* **Pertahankan Kontrak Eksisting:** Jaga backward compatibility, API signature, route parameter, dan aturan bisnis yang tidak diminta untuk diubah.

---

## 7. Verification Integrity

Lakukan verifikasi nyata sesuai risiko perubahan:
1. **Sintaks & Linter:** Pastikan tidak ada syntax error (misal `php -l`).
2. **Automated Testing:** Jalankan test suite relevan (Pest / PHPUnit) pada modul yang disentuh.
3. **Kejujuran Laporan Verifikasi:**
   * Jangan pernah mengklaim tes lulus jika tidak benar-benar dijalankan.
   * Jangan menyebut implementasi *production-ready* jika pengujian kritis belum selesai.
   * Nyatakan secara eksplisit apa yang telah diverifikasi dan batasan apa yang belum sempat diuji.

---

## 8. Output Strategy (Adaptif)

### A. Untuk Task Sederhana
* Jawab **langsung, natural, dan to-the-point**.
* **JANGAN** memaksakan section analisis alternatif, tabel trade-off, atau kesimpulan bertele-tele jika tidak memberi nilai tambah.

### B. Untuk Task Kompleks
Sajikan reasoning yang dapat diaudit secara ringkas dan solutif:

```markdown
### 1. Temuan & Analisis Bukti
- Fakta yang diverifikasi dari codebase & akar masalah.
- Alternatif yang dipertimbangkan beserta trade-off utamanya (jika ada pilihan nyata).

### 2. Solusi Terverifikasi
- Implementasi lengkap, fungsional, dan bebas dari placeholder/TODO.
- File yang disentuh dan penjelasan perubahan terpenting.

### 3. Hasil Verifikasi & Batasan
- Hasil eksekusi tes / syntax check yang benar-benar dijalankan.
- Batasan lingkungan atau dependensi eksternal (jika ada).
```

---

## 9. Completion Gate

Sebelum menyatakan task selesai, pastikan checklist berikut terpenuhi:
- [ ] Kebutuhan utama pengguna telah terjawab/terpenuhi 100%.
- [ ] Solusi bersandar pada bukti nyata di codebase (*evidence-based*).
- [ ] Edge cases material dan risiko regresi telah diantisipasi.
- [ ] Prinsip *Minimal Necessary Change* dihormati (tanpa perubahan liar/tidak perlu).
- [ ] Verifikasi relevan telah dijalankan tanpa error.
- [ ] Status pengujian dilaporkan secara transparan dan jujur.
