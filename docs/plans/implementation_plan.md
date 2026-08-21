# Revamp Login Page UI - Glassmorphism V2 (Enhanced)

Tujuan dari rencana revisi ini adalah memaksimalkan efek *Glassmorphism*. Sebelumnya background hanya berupa gradien biru yang relatif polos, sehingga efek tembus pandang (kaca) pada kartu login tidak begitu terlihat. 

Untuk membuat *glassmorphism* benar-benar "keluar" dan memukau, kita membutuhkan *background* dengan elemen warna-warni yang kontras (seperti *glowing orbs* atau *blobs* warna) yang bergerak di belakang kartu transparan.

## User Review Required

> [!IMPORTANT]
> **Skema Warna Terang vs Gelap**
> Rencana ini akan menggunakan tema **Terang (Light Theme)** dengan *background* berwarna abu-abu sangat muda/putih, ditambah *blobs* (bola cahaya) besar berwarna Biru ADASI dan Merah ADASI yang melayang di belakang layar. Kartu login akan dibuat jauh lebih transparan (`opacity 0.25` s/d `0.4`) sehingga pergerakan warna di belakangnya akan terkena efek *blur* frosted-glass. Tema terang dipilih agar logo ADASI dan teks tetap terbaca dengan jelas.

## Open Questions

> [!TIP]
> Apakah penambahan warna *blob* aksen ketiga (misalnya warna biru muda yang cerah atau kuning keemasan) diperbolehkan untuk menambah estetika? (Jika tidak, saya hanya akan murni menggunakan Biru dan Merah ADASI).

## Proposed Changes

### Tampilan Utama & Tata Letak (Layout)

#### [MODIFY] [auth.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/layouts/auth.blade.php)
- **Background Utama**: Mengubah warna dasar menjadi warna netral terang (misal `#e2e8f0`).
- **Animated CSS Blobs**: Mengganti *floating shapes* yang lama menjadi 3 buah elemen *orb/blob* berukuran sangat besar (500px - 700px) menggunakan CSS murni (tanpa gambar). 
    - Blob 1: Biru ADASI (`#1F5FA6`) di kiri atas.
    - Blob 2: Merah ADASI (`#C0392B`) di kanan bawah.
    - Blob 3: Warna Biru Muda/Cyan cerah di tengah yang bergerak dinamis.
- **Glassmorphism Card CSS (Enhanced)**: 
    - Menurunkan nilai *opacity* background dari `0.88` menjadi `0.35` (`rgba(255, 255, 255, 0.35)`).
    - Mempertebal border menjadi `1px solid rgba(255, 255, 255, 0.8)` agar efek pinggiran kaca (*glass edge*) terlihat tegas.
    - *Backdrop-filter* tetap di `blur(16px)` untuk efek buram yang mewah.
- **Input CSS**: Karena kartu sekarang transparan, warna *background* input juga akan disesuaikan menjadi semi-transparan putih (`rgba(255, 255, 255, 0.6)`) agar menyatu sempurna dengan kartu kaca.

---

## Verification Plan

### Manual Verification
1. **Visual Check**: Membuka halaman `/login` dan memastikan efek warna-warni dari *blob* di *background* terlihat menembus kartu login dan menjadi buram (*blurred*).
2. **Keterbacaan**: Memastikan teks, ikon, dan logo ADASI tetap dapat dibaca dengan jelas di atas kartu yang transparan.
