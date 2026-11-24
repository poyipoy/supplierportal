# Rencana Implementasi Optimasi PageSpeed Insights
**Target:** `https://adasi-supplierportal.my.id` (Halaman Login OAuth `/oauth/authorize`)  
**Cakupan Audit:** Mobile & Desktop  
**Fokus Metrik:** LCP (*Largest Contentful Paint*), FCP (*First Contentful Paint*), CLS (*Cumulative Layout Shift*), dan INP (*Interaction to Next Paint*)

---

## 1. Ringkasan Audit & Prioritas Dampak

Berdasarkan hasil analisis PageSpeed Insights (Mobile dan Desktop), berikut peta bottleneck utama dan urutan prioritas eksekusi:

| Prioritas | Fokus Optimasi | Target Metrik | Temuan Saat Ini | Estimasi Dampak |
| :--- | :--- | :--- | :--- | :--- |
| **P0** | Optimasi gambar, splash screen & prioritas LCP | LCP, FCP, CLS | Total gambar `1.461,4 KiB`; *Element Render Delay* Mobile `3.080 ms`; LCP Desktop tanpa `fetchpriority="high"`; logo tanpa `width`/`height` | Hemat **~1.323 KiB** payload gambar & percepat render awal secara drastis |
| **P1** | Eliminasi *render-blocking* & pemisahan bundle Auth | LCP, FCP | 5 resource memblokir render; `94–95%` `app.css`, `98%` `bootstrap.min.css`, dan `79%` `app.js` tidak terpakai | Hemat **~620 ms** blocking, **~61–62 KiB** CSS, dan **~44 KiB** JS |
| **P1** | Investigasi *stall* `adasi-alert.js` & optimasi jaringan | LCP, Reliability | `adasi-alert.js` menggantung `10.600 ms` (`0,00 KiB`) di Mobile; `adasi-alert.css` menjadi rantai kritis terlama di Desktop (`526 ms`) | Hilangkan latensi ekstrem di Mobile & pendekkan *critical path* |
| **P2** | Perbaikan *forced reflow* pada inline script | INP, TBT | Inline script `/oauth/authorize` baris `242:24` memicu *forced reflow* `67 ms` (baris `391:67` & `408:14`) | Pangkas **67 ms** *layout thrashing* di *main thread* |

---

## 2. Fase 1 (P0): Optimasi Aset Gambar, Splash Screen & Prioritas LCP

### 2.1. Ganti `logo-adasi.gif` dan Evaluasi Splash Screen
**Masalah:**
- `logo-adasi.gif` berukuran **1.043,7 KiB** dengan dimensi asli `1536×612` padahal hanya ditampilkan `136×54`.
- Tag `<img>` splash diberi `fetchpriority="high"`, `loading="eager"`, dan `decoding="sync"`, sehingga berebut bandwidth dan *main thread* dengan elemen konten utama.
- Di Mobile, *Element Render Delay* pada elemen LCP (`<h2 class="tw:m-0 ...">Sign in to your account</h2>`) mencapai **3.080 ms** meski TTFB `0 ms`—indikasi kuat bahwa splash overlay dan resource pemblokir menahan kemunculan konten utama.

**Langkah Implementasi:**
- [ ] **Opsi paling direkomendasikan:** Hapus *splash screen* animasi pada halaman login agar form dan judul *Sign in to your account* langsung ter-render pada *initial paint*.
- [ ] **Jika splash animasi wajib dipertahankan:**
  - [ ] Konversi `logo-adasi.gif` menjadi video ringan (`WebM`/`MP4`, estimasi hemat `~782,8 KiB`) atau **Animated WebP** dengan dimensi tepat `272×108` (2× Retina dari `136×54`, estimasi hemat `~1.035,5 KiB`).
  - [ ] Hapus atribut `fetchpriority="high"` dan `decoding="sync"` dari elemen splash; ganti dengan `decoding="async"` dan `fetchpriority="low"`.
  - [ ] Pastikan elemen konten utama (`<h2>Sign in to your account</h2>` dan form login) tidak tertahan oleh `display: none` atau `opacity: 0` terlalu lama.

### 2.2. Optimasi Gambar LCP Desktop (`adasi-login-bg.jpg`)
**Masalah:**
- `adasi-login-bg.jpg` (`386,8 KiB`) merupakan elemen LCP di Desktop, masih berformat JPEG (bisa hemat **250,5 KiB**), dan belum memakai `fetchpriority="high"`.

**Langkah Implementasi:**
- [ ] Konversi `adasi-login-bg.jpg` ke format modern **AVIF** dan **WebP** dengan fallback JPEG.
- [ ] Tambahkan atribut `fetchpriority="high"`, `decoding="async"`, serta dimensi eksplisit `width` dan `height`.
- [ ] Gunakan elemen `<picture>` dengan `media="(min-width: 1024px)"` (sesuaikan dengan *breakpoint* CSS panel `aside.auth-brand-panel`) agar perangkat Mobile tidak mengunduh gambar background yang disembunyikan.

```html
<picture>
  <source
    media="(min-width: 1024px)"
    srcset="/assets/images/adasi-login-bg.avif"
    type="image/avif"
  >
  <source
    media="(min-width: 1024px)"
    srcset="/assets/images/adasi-login-bg.webp"
    type="image/webp"
  >
  <img
    src="/assets/images/adasi-login-bg.jpg"
    alt=""
    width="960"
    height="1080"
    loading="eager"
    decoding="async"
    fetchpriority="high"
    draggable="false"
  >
</picture>
```

### 2.3. Resize & Tambahkan Dimensi Eksplisit pada `logo-adasi.png`
**Masalah:**
- `logo-adasi.png` berukuran **30,8 KiB** (`320×297`) padahal hanya ditampilkan `34×32` (bisa hemat **30,6 KiB**).
- Elemen `<img>` logo di Mobile (`section.auth-form-panel > div.auth-form-surface > a.auth-mobile-brand > img`) dan Desktop (`alt="ADASI logo"`) tidak memiliki atribut `width` dan `height`, sehingga berisiko memicu CLS.

**Langkah Implementasi:**
- [ ] Ekspor ulang logo menjadi **SVG** (paling optimal) atau **WebP/PNG** berukuran `68×64` (2× Retina).
- [ ] Tambahkan atribut `width="34" height="32"` pada kedua tag `<img>` logo tersebut.

```html
<img
  src="/assets/images/logo-adasi.webp"
  alt="ADASI logo"
  width="34"
  height="32"
  decoding="async"
  draggable="false"
>
```

---

## 3. Fase 2 (P1): Eliminasi Render-Blocking, Pemangkasan Bundle & Stabilisasi Jaringan

### 3.1. Pisahkan Entrypoint CSS & JS Khusus Halaman Auth
**Masalah:**
- `app-CErnthaO.css` (`42,5–42,9 KiB`) memblokir render selama `1.280 ms` di Mobile, sementara **39,8–40,2 KiB (~94–95%)** tidak terpakai di halaman login.
- `app-Dx1cFkxQ.js` (`55,5 KiB`) memiliki **43,6 KiB (~79%)** kode JavaScript yang tidak terpakai.

**Langkah Implementasi:**
- [ ] Buat layout terpisah khusus autentikasi (misalnya `layouts/auth.blade.php`) yang terpisah dari layout dashboard utama.
- [ ] Buat entrypoint Vite khusus (`resources/css/auth.css` dan `resources/js/auth.js`) agar hanya class Tailwind (`tw:*`) dan skrip esensial login yang di-bundle.
- [ ] Pastikan seluruh skrip non-kritis menggunakan `defer` atau ES Module (`type="module"`).

### 3.2. Hapus atau Lazy-Load Bootstrap & SweetAlert2 (JSDelivr CDN)
**Masalah:**
- `bootstrap.min.css` (`22,1–23,3 KiB`) memblokir render `1.050 ms` di Mobile, padahal **21,6 KiB (~98%)** tidak terpakai karena styling sudah berbasis Tailwind (`tw:*`).
- `sweetalert2.min.css` (`4,3 KiB`) memblokir render `750 ms` di Mobile, sementara `bootstrap.bundle.min.js` (`21,76 KiB`) dan `sweetalert2.all.min.js` (`17,21 KiB`) ikut masuk ke rantai request awal.
- Belum ada `<link rel="preconnect">` ke origin pihak ketiga.

**Langkah Implementasi:**
- [ ] **Bootstrap:** Audit penggunaan class/komponen Bootstrap pada `/oauth/authorize`. Ganti sisa styling kecil dengan utility Tailwind yang sudah ada, lalu hapus `bootstrap.min.css` dan `bootstrap.bundle.min.js` dari halaman Auth.
- [ ] **SweetAlert2:** Ubah pemuatan SweetAlert2 (CSS & JS) menjadi *lazy-load* / *dynamic import* saat `adasi-alert` benar-benar dipanggil (misalnya ketika terjadi error autentikasi).
- [ ] **Alternatif sementara jika SweetAlert2 tetap dimuat di awal:**
  - Muat CSS secara non-blocking:
    ```html
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"></noscript>
    ```
  - Tambahkan atribut `defer` pada `sweetalert2.all.min.js`.
- [ ] **Preconnect:** Jika CDN `cdn.jsdelivr.net` masih dipertahankan di halaman login, tambahkan di bagian paling atas `<head>`:
  ```html
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  ```
  *(Hapus tag preconnect ini jika seluruh dependensi JSDelivr berhasil dihilangkan atau di-self-host.)*

### 3.3. Optimasi Aset Custom (`adasi-i18n.js`, `adasi-alert.css`) & Investigasi Stall `adasi-alert.js`
**Masalah:**
- `adasi-i18n.js` (`1,0 KiB`) memblokir render selama `640 ms` di Mobile.
- `adasi-alert.css` (`2,2 KiB`) memblokir render `210 ms` di Mobile dan menjadi rantai kritis terlama di Desktop (`526 ms`).
- Di Mobile, `adasi-alert.js` tercatat menggantung **10.600 ms dengan ukuran 0,00 KiB** (di Desktop normal: `473 ms`, `2,62 KiB`).

**Langkah Implementasi:**
- [ ] **Optimasi `adasi-i18n.js`:**
  - Idealnya, lakukan translasi teks langsung di sisi server (*server-side rendering*) agar tidak memerlukan blocking JS.
  - Jika tetap diperlukan di client-side, tambahkan `defer`, atau karena ukurannya hanya `~1 KiB`, pertimbangkan *inline* skrip mini tersebut ke HTML untuk memangkas 1 *round-trip* jaringan.
- [ ] **Optimasi `adasi-alert.css`:** Gabungkan ke `auth.css` (atau muat secara *lazy* bersama modul SweetAlert2).
- [ ] **Investigasi anomali `adasi-alert.js` (`10.600 ms`, `0,00 KiB`):**
  - [ ] Cek *access log* dan *error log* web server (Nginx/Apache/Cloudflare) untuk endpoint `/js/adasi-alert.js` terkait potensi *rate limiting*, *connection reset*, HTTP/2 stream error, atau timeout.
  - [ ] Pastikan server mengaktifkan **HTTP/2 atau HTTP/3**, kompresi **Brotli/Gzip**, dan header cache statis:
    ```http
    Cache-Control: public, max-age=31536000, immutable
    ```
    (dengan *cache-busting* melalui hash Vite atau parameter `?v=`).
  - [ ] Pastikan `adasi-alert.js` dimuat dengan atribut `defer` (atau di-bundle ke `auth.js`).

---

## 4. Fase 3 (P2): Eliminasi Forced Reflow (67 ms)

**Masalah:**
- Audit Desktop mendeteksi *forced reflow* sebesar **67 ms** pada inline script di halaman `/oauth/authorize`:
  - Top function call: `/oauth/authorize?client_id=...:242:24` (`67 ms`)
  - Sumber utama: baris `391:67` (`20 ms`) dan baris `408:14` (`47 ms`)

**Langkah Implementasi:**
- [ ] Inspeksi inline `<script>` pada template `/oauth/authorize` di sekitar baris `242`, `391`, dan `408`.
- [ ] Identifikasi pola **DOM Write → Layout Read** dalam satu *frame* (contoh: mengubah `style` atau `classList` lalu langsung membaca `offsetWidth`, `offsetHeight`, `clientHeight`, `getBoundingClientRect()`, atau `getComputedStyle()`).
- [ ] Lakukan refactoring dengan pola berikut:
  - [ ] Pisahkan seluruh pembacaan geometri DOM (*Read*) di awal sebelum melakukan mutasi style/DOM (*Write*), dan bungkus mutasi di dalam `requestAnimationFrame(...)`.
  - [ ] Jika ditemukan trik `void element.offsetWidth;` untuk me-restart animasi CSS (misalnya pada splash/transisi), ganti menggunakan *Web Animations API* (`element.animate(...)`) atau transisi class CSS yang dijadwalkan di frame berikutnya.

```javascript
// Contoh pola yang aman dari forced reflow (Read dulu, baru Write)
const rect = element.getBoundingClientRect();

requestAnimationFrame(() => {
  element.style.transform = `translateY(${rect.height}px)`;
  element.classList.add('is-ready');
});
```

---

## 5. Rencana Verifikasi & Kriteria Keberhasilan (*Definition of Done*)

Setelah setiap fase di-deploy ke *staging* atau *production*, lakukan pengujian ulang menggunakan **Chrome DevTools** dan **PageSpeed Insights (Mobile & Desktop)**:

### Checklist Verifikasi Teknis
- [ ] **Payload Gambar (DevTools → Network):** Total ukuran gambar halaman login turun dari `1.461,4 KiB` menjadi **< 200 KiB**.
- [ ] **LCP Desktop:** `adasi-login-bg` menggunakan AVIF/WebP dengan *Priority: High*, dan tidak diunduh sama sekali pada viewport Mobile jika panel brand tersembunyi.
- [ ] **Stabilisasi Aset:** `adasi-alert.js` selalu mengembalikan HTTP `200`/`304` dengan cepat di bawah simulasi *Slow 4G* tanpa *stall*.
- [ ] **Coverage (DevTools → Coverage):** `bootstrap.min.css` hilang dari halaman Auth (jika sudah dipindah ke Tailwind), dan rasio *unused bytes* pada `auth.css` / `auth.js` berada di batas wajar.
- [ ] **Main Thread (DevTools → Performance):** Tidak ada lagi peringatan *Forced reflow* pada inline script `/oauth/authorize`.

### Target Metrik PageSpeed Insights
- [ ] **Mobile LCP:** `< 2,5 detik` dengan *Element Render Delay* turun drastis dari `3.080 ms` ke `< 500 ms`.
- [ ] **Desktop LCP Request Discovery:** Seluruh poin centang hijau (termasuk `fetchpriority="high"`).
- [ ] **Render-Blocking Requests:** Nol resource non-kritis yang memblokir *initial render*.
- [ ] **CLS:** `0` (nol peringatan *Image elements do not have explicit width and height*).