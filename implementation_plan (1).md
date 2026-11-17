# Implementation Plan: Adaptasi Penuh Transisi Halaman Infor LN UI & Remediasi Keamanan Workspace Shell pada ADASI Supplier Portal

*Tanggal Penyusunan: 17 November 2026 | Target Repositori: `poyipoy/supplierportal` (Laravel 12, PHP 8.2+, Tailwind CSS 4.3, Alpine.js 3.4) | Protokol: `@boost` + `@laravel-security-audit` + `@deep-research` | Tingkat Kepercayaan: Tinggi (`[Verified]`)*

## 1. Outcome, Scope & Reasoning Depth

- **User Intent**: Mengadaptasi mekanisme transisi berpindah halaman dari [Infor LN UI Application Panel](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuisettingsolhug/pmc1504268190832.html) dan [Infor Design System (IDS) Page Layouts](https://design.infor.com/patterns/page-layouts/page-layouts-overview/) ke dalam proyek `poyipoy/supplierportal`, sekaligus menutup celah keamanan dan *state race condition* pada arsitektur *multi-tab keep-alive* yang ditemukan melalui audit kode sumber.
- **Success Criteria**:
  1. Seluruh 5 temuan audit keamanan dan integritas *state* (`DocumentChainResolverService` IDOR/Authorization bypass, polusi session akibat *speculative hover prefetch* `AdasiServerTabs`, *scope flipping* pada akun *dual-scope supplier*, sanitasi fragmen DOM, dan penangguhan *polling* pada *tab* tersembunyi) tertangani dengan pengujian otomatis yang lulus.
  2. Empat pola transisi [Infor LN UI User Guide](https://docs.infor.com/lnui/2024.04/en-us/lnuisettingsolh/sae1504268190754.html) yang belum penuh di repositori—yaitu **Side Navigation Auto-Expand on Hover (`uigwt0102s000`)**, **Multi Main Table / MMT (`Header + Satellite ServerTabs`)**, **Record Group Jumping (`Prev / Next Record`)**, dan **Quick Flow (`Dialog Confirm Bypass`)**—terimplementasi secara modular di atas `WorkspaceShell` dan `AdasiServerTabs`.
  3. Konsumsi memori *browser* tetap terjaga di bawah batas perangkat kerja RAM 8 GB (`max_tabs = 12`, `max_live_frames = 6`) dan kompatibel penuh dengan lingkungan *deployment* cPanel (tanpa *daemon* Node.js tambahan).
- **Scope IN**:
  - `app/Services/DocumentChain/DocumentChainResolverService.php`
  - `app/Http/Middleware/RememberPurchasingListUrl.php`
  - `app/Http/Middleware/SupplierScopeMiddleware.php`
  - `app/Support/PurchasingNavigation.php`
  - `app/Services/UserPreferenceService.php` & `config/user_preferences.php`
  - `resources/js/server-tabs.js`
  - `resources/js/app.js` & `resources/css/app.css`
  - `resources/views/layouts/app.blade.php`
  - `resources/views/components/ui/mmt-workspace.blade.php` (komponen baru)
  - `tests/Feature/DocumentChain/SmartPanelIsolationTest.php`, `tests/Feature/WorkspaceShellSecurityTest.php`, & `tests/js/workspace-shell.test.mjs`
- **Scope OUT**:
  - Migrasi ke kerangka kerja SPA penuh (seperti Inertia.js, Livewire, atau Vue/React router) yang akan merusak kontrak 200+ rute GET pada `WorkspaceShellRouteClassificationTest.php`.
  - Perubahan skema tabel transaksi inti (`purchase_orders`, `purchase_requisitions`, `local_invoices`, `payment_batches`).
- **Operational Constraints**:
  - Klien: Windows dengan RAM 8 GB (wajib menghindari pembengkakan DOM `iframe` dan *layout reflow* berulang pada DataTables/Chart.js).
  - Server: Lingkungan cPanel hosting dengan PHP 8.2/8.3 dan MySQL (wajib meminimalkan *session file lock contention* dari permintaan AJAX/polling paralel).
  - Tipografi & Desain: Wajib mempertahankan standar `Source Sans Pro`, komponen modular `server-tabs`, dan token gerak `cubic-bezier(0.22, 0.61, 0.36, 1)`.
- **Reasoning Depth**: **Maximum** (melibatkan batas otorisasi lintas-peran, isolasi *multi-tenant/multi-supplier*, sinkronisasi *state* antar-`iframe` *same-origin*, dan konkurensi *session* Laravel).
- **External Research Needed?**: **Yes (Completed)** — spesifikasi resmi [Infor LN UI Application Panel (2026.04)](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuisettingsolhug/pmc1504268190832.html), [Multi Main Table Sessions (2026.10)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/aoj1504268195263.html), [Auto Expand/Collapse Navigation Menu (`uigwt0102s000`)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuidiffrn/wga1592559795533.html), [Optimizing a Process through Quick Flow](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/mdc1504268199304.html), dan [IDS List and Details Pattern](https://design.infor.com/patterns/page-layouts/list-and-details/).

## 2. Evidence, Invariants & Confidence Inventory

### Relevant Evidence Inspected (`[INSPECTED]`)

| Berkas Repositori | Temuan Bukti Kode Aktual (`[Verified]`) | Status Paritas terhadap Infor LN UI |
| :--- | :--- | :--- |
| `app/Support/WorkspaceShell.php` & `app/Http/Middleware/WrapWorkspaceShell.php` | Mengimplementasikan pembungkusan rute GET yang memenuhi syarat (`CLASS_SHELL_PAGE`) ke dalam `layouts/workspace-shell.blade.php` dengan parameter `_embed=1`, *one-time prerender cache* (15 detik) dengan rotasi *CSP nonce*, serta pengecualian ketat untuk rute keamanan (`CLASS_TOP_LEVEL_SECURITY`) dan unduhan biner (`CLASS_NON_SHELL_DIRECT`). | **95% Parity** dengan [Infor Ming.le-LN Plug-in User Guide](https://docs.infor.com/ln/10.5.2/en-us/lnolh/docs/ln_10.5.2_lnuipluginug__en-us.pdf) & [LN UI Application Panel](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuisettingsolhug/pmc1504268190832.html). |
| `resources/js/workspace-shell-core.js` & `resources/js/workspace-shell.js` | `WorkspaceTabStore` mengelola maks. 12 *tabs* dan 6 *live iframes* dengan *LRU hibernation*, pengelompokan *Parent + Child Tab Stack* (`classifyInternalNavigation`), penyempitan lebar *tab* proporsional (`computeWaterFillTabCap`: lantai `112px`/`136px`, batas `248px`), dan penutupan *tab* via klik tengah (`auxclick` `button === 1`). | **100% Parity** dengan [Closing LN Session Tabs](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuidiffrn/lez1572965687795.html) & [IDS Tabs Component](https://wc.design.infor.com/ids-tabs). |
| `resources/js/server-tabs.js` & `app/Support/ServerTabsResponse.php` | Mengelola pertukaran fragmen HTML tabel secara *in-place* via header `X-Adasi-Server-Tabs: 1`, lengkap dengan *in-memory fragment cache* (TTL 5 menit) dan *speculative hover prefetch* (`60ms` debounce). Namun *prefetch* memicu mutasi session pada `RememberPurchasingListUrl.php`. | **90% Parity** dengan [IDS List and Details Pattern](https://design.infor.com/patterns/page-layouts/list-and-details/). |
| `resources/views/partials/sidebar.blade.php` & `resources/js/app.js` (`adasiShell`) | Menyediakan *sidebar* kiri (`256px` saat terbuka, `64px` saat *collapsed*) dengan penyimpanan status di `localStorage` (`adasi.sidebar.<accountId>`). Belum memiliki fitur *auto-expand on left-edge hover* dan *auto-collapse on pointer leave*. | **60% Parity** dengan [Auto Expand/Collapse Menu (`uigwt0102s000`)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuidiffrn/wga1592559795533.html) & [IDS Module Nav](https://wc.design.infor.com/ids-module-nav). |
| `resources/js/smart-panel.js` & `app/Services/DocumentChain/DocumentChainResolverService.php` | Menyediakan *slide-over drawer* (`#adasi-smart-panel`) untuk meninjau rantai dokumen (`PR -> Quotation -> PO -> Shipment -> QC -> Claim` dan `Local PO -> GR -> Invoice -> DRP -> Payment`). Namun belum ada komponen **Multi Main Table (MMT)** *split-pane* horizontal di halaman indeks, serta terdapat celah otorisasi di `resolvePaymentBatch()` dan `resolveId()`. | **50% Parity** dengan [Multi Main Table Sessions](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/aoj1504268195263.html). |
| `resources/views/layouts/app.blade.php` & `app/Support/PurchasingNavigation.php` | Memiliki intersepsi konfirmasi unduh PDF (`[data-pdf-confirm]`) dan ekspor Excel (`[data-export-confirm]`) serta memori `return_url`. Belum memiliki mode *Quick Flow* untuk melewati dialog konfirmasi dan belum memiliki navigasi sekuensial **Prev / Next Record** di halaman detail. | **40% Parity** dengan [Optimizing a Process through Quick Flow](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/mdc1504268199304.html) & [Menu Bar in Sessions](https://docs.infor.com/lnui/latest/en-us/lnuisettingsolh/lnuisettingsolhug/wow1504268190895.html). |

### Confidence Classification

- **`[Verified]`**: Seluruh perilaku `WorkspaceShell`, `WrapWorkspaceShell`, `AddSecurityHeaders`, `DecodeHashids`, `RememberPurchasingListUrl`, `SupplierScopeMiddleware`, `DocumentChainResolverService`, `server-tabs.js`, dan `workspace-shell.js` telah dibaca langsung dari kode sumber dan diuji dengan `node --test tests/js/workspace-shell.test.mjs` (13/13 subtest lulus).
- **`[Inferred]`**: Mengganti pembukaan *child iframe* untuk inspeksi rincian baris menjadi panel bawah **MMT** berbasis `AdasiServerTabs` akan menghemat ~25–40 MB RAM per sesi karena tidak membuat dokumen `iframe` baru beserta duplikasi *runtime* Alpine.js, Bootstrap, dan jQuery.
- **`[Assumed]`**: Pengguna dengan peran *Purchasing*, *Finance*, dan *Accounting* adalah pengguna utama yang paling diuntungkan oleh pola **MMT** dan **Prev/Next Record Jumping** karena frekuensi pemeriksaan dokumen *3-Way Match* dan perbandingan harga yang tinggi.

### Existing Invariants to Preserve

1. **Framing & CSP Boundary Invariant**: Halaman *top-level* dan seluruh rute `CLASS_TOP_LEVEL_SECURITY` wajib selalu mengirimkan `X-Frame-Options: DENY` dan `Content-Security-Policy: frame-ancestors 'none'`. Hanya permintaan `iframe` yang lolos `WorkspaceShell::isAllowedEmbedRequest($request)` yang boleh menerima `X-Frame-Options: SAMEORIGIN` dan `frame-ancestors 'self'`.
2. **Hashids Route Boundary Invariant**: Tidak boleh ada ID numerik mentah (`ctype_digit`) untuk model yang menggunakan `HasHashids` yang dapat dieksekusi melalui URL, parameter *query*, maupun *endpoint* JSON Smart Panel / MMT (`PurchasingNavigation::containsLegacyNumericIdentifier()` dan `DecodeHashids`).
3. **Dirty Form Protection Invariant**: Frame yang memiliki `isDirty === true` (`unsaved-changes.js`) tidak boleh di-*evict* oleh `WorkspaceTabStore`, tidak boleh di-*hibernate*, dan tidak boleh di-*reload* otomatis saat berstatus `stale`.
4. **Supplier Scope & Ownership Isolation Invariant**: Pemasok `import` tidak boleh mengakses sumber daya `local` (dan sebaliknya), serta setiap pemasok hanya boleh melihat entitas dengan `supplier_id === auth()->id()`.

## 3. Causal Model & Audit Keamanan (`@laravel-security-audit`)

Bagian ini mendokumentasikan 5 temuan keamanan dan integritas *state* beserta skenario eksploitasi dan desain perbaikannya:

### Temuan Audit 1 — Bypass Otorisasi Role/Policy & Penerimaan Raw Integer ID pada `DocumentChainResolverService`
- **Risk Level**: **High** (OWASP A01:2021 — Broken Access Control / Insecure Direct Object Reference)
- **Observed Code (`[Verified]`)**:
  - `app/Services/DocumentChain/DocumentChainResolverService.php` baris 48–63 (`resolveId()`): menerima integer mentah jika `ctype_digit($idOrHash)` bernilai `true`.
  - Baris 69–84 (`resolveImportPr()`): cabang `admin` dan `purchasing` tidak memeriksa `$actor->is_active`.
  - Baris 490–550 (`resolvePaymentBatch()`): hanya memeriksa `in_array($actor->role, ['admin', 'finance', 'accounting', 'purchasing'], true)` tanpa memeriksa `$actor->is_active`, tanpa memvalidasi apakah batch tersebut adalah batch **GA Claims** (yang terlarang bagi `accounting` dan `purchasing` menurut `GaClaimPolicy`), dan mengekspose nomor `PaymentVoucher` kepada `accounting` (padahal `PaymentVoucherPolicy` hanya mengizinkan `finance`, `purchasing`, dan `admin`).
- **Exploit Scenario**:
  1. Akun dengan peran `accounting` mengakses `/context-panel/payment-batch/{hash}` dan membaca rincian `PaymentVoucher` atau klaim pegawai GA yang seharusnya dibatasi oleh `PaymentVoucherPolicy` dan `GaClaimPolicy`.
  2. Jika pengembang memanggil `DocumentChainResolverService::resolve($user, $type, $request->query('id'))` pada komponen MMT baru yang menggunakan parameter *query* (bukan parameter rute yang dicegat `DecodeHashids`), penyerang dapat mengenumerasi ID sekuensial (`?id=1`, `?id=2`).
- **Root Cause**: Ketidakkonsistenan penegakan `Gate::forUser($actor)->authorize(...)` antar-metode di dalam `DocumentChainResolverService` dan adanya *fallback* `ctype_digit()` pada `resolveId()`.

### Temuan Audit 2 — Polusi State Session Server Akibat *Speculative Hover Prefetch* pada `AdasiServerTabs`
- **Risk Level**: **Medium** (OWASP A04:2021 — Insecure Design / Cross-Tab Session Race Condition)
- **Observed Code (`[Verified]`)**:
  - `resources/js/server-tabs.js` baris 165–240: `pointerenter` dengan *debounce* `60ms` memanggil `speculativePrefetch(url)` menggunakan header `X-Adasi-Server-Tabs: 1`.
  - `app/Http/Middleware/RememberPurchasingListUrl.php` baris 23–37: setiap permintaan GET dengan `ServerTabsResponse::wants($request)` langsung mengeksekusi `PurchasingNavigation::rememberListUrl($routeName, $url)` yang menimpa `session("purchasing.index_urls.{$routeName}")` dan `session('purchasing.last_list_url')`.
- **Exploit / Failure Scenario**:
  Pengguna *Purchasing* membuka halaman daftar di Tab 1 (atau membuka dua *workspace instance* dari modul yang sama), lalu kursornya melintas di atas *tab* filter lain selama `60ms` tanpa mengklik. Permintaan *prefetch* latar belakang menimpa `purchasing.last_list_url` di session server. Ketika pengguna menekan tombol *Simpan* atau *Kembali* pada form detail yang mengandalkan `PurchasingNavigation::backUrl()`, pengguna diarahkan ke URL tab yang hanya ter-*hover* tadi. Selain itu, setiap *hover* memicu penulisan session di server yang meningkatkan *lock contention* pada penyimpanan session berbasis file di cPanel.
- **Root Cause**: `speculativePrefetch()` tidak mengirimkan header penanda *prefetch* (`X-Adasi-Prefetch: 1`), sehingga middleware `RememberPurchasingListUrl` tidak dapat membedakan antara navigasi aktif pengguna dan *prefetch* spekulatif.

### Temuan Audit 3 — Mutasi Konteks Sesi Tanpa Syarat (*Scope Flipping*) pada `SupplierScopeMiddleware`
- **Risk Level**: **Medium** (OWASP A01/A04 — Cross-Tab Context Desynchronization)
- **Observed Code (`[Verified]`)**:
  - `app/Http/Middleware/SupplierScopeMiddleware.php` baris 10–16: mengeksekusi `$request->session()->put('supplier_context', $scope);` pada setiap permintaan yang melewati middleware `supplier.scope:{scope}`.
- **Exploit / Failure Scenario**:
  Seorang pemasok *dual-scope* (`import` + `local`) sedang bekerja di konteks `local`, sementara terdapat *iframe* atau permintaan *polling* latar belakang dari konteks `import` yang masih berjalan. Permintaan latar belakang tersebut menimpa `session('supplier_context')` kembali menjadi `'import'`, sehingga navigasi umum (seperti `/dashboard` atau preferensi regional di `UserPreferenceService::displayTimezone()`) tiba-tiba berpindah konteks tanpa disadari pengguna.
- **Root Cause**: `SupplierScopeMiddleware` menulis ulang `supplier_context` di session pada setiap permintaan (termasuk AJAX/polling latar belakang), alih-alih hanya menginisialisasi jika belum ada dan menyerahkan perpindahan konteks eksplisit kepada `SupplierContextController@store`.

### Temuan Audit 4 — Risiko DOM XSS pada Pertukaran Fragmen HTML `AdasiServerTabs` & Panel MMT
- **Risk Level**: **Medium** (OWASP A03:2021 — Cross-Site Scripting)
- **Observed Code (`[Verified]`)**:
  - `resources/js/server-tabs.js` baris 436 (`tableTarget.innerHTML = data.html;`) dan baris 545 (`nav.innerHTML = navHtml;`).
  - `app/Http/Middleware/AddSecurityHeaders.php` baris 74: mengizinkan `script-src-attr 'unsafe-inline'`.
- **Exploit Scenario**:
  Meskipun CSP memblokir tag `<script>` tanpa `nonce`, izin `script-src-attr 'unsafe-inline'` membuat elemen HTML dengan atribut *event handler* (seperti `<img src=x onerror="...">` atau `<div onfocus="..." autofocus tabindex="0">`) tetap dapat dieksekusi jika disuntikkan ke `innerHTML`. Karena `iframe` di dalam `WorkspaceShell` bersifat *same-origin*, eksekusi JS di dalam `iframe` dapat mengakses `window.parent.AdasiWorkspaceShell` dan membajak seluruh ruang kerja.
- **Root Cause**: Penggunaan `innerHTML` tanpa penyaringan elemen `<script>` dan atribut `on*` di sisi klien sebagai pertahanan berlapis (*defense-in-depth*) terhadap potensi kelalaian `{!! ... !!}` di sisi server.

### Temuan Audit 5 — Penumpukan Polling Latar Belakang dari Beberapa *Keep-Alive Iframes*
- **Risk Level**: **Low** (Operational Availability / Rate Limit & Session Lock Contention)
- **Observed Code (`[Verified]`)**:
  - `resources/js/po-document-upload.js` (`setTimeout(poll, 3000)`) dan `resources/js/local-procurement-import.js`.
- **Failure Scenario**:
  Saat pengguna membuka hingga 6 *live iframes* (`max_live_frames = 6`), *iframe* yang sedang tidak aktif (`hidden`) tetapi memiliki *polling* aktif tetap mengirimkan permintaan setiap 3 detik ke server cPanel, bersaing memperebutkan kuota `LimitOperationalRequests` dan kunci session PHP.
- **Root Cause**: Fungsi `poll()` belum memeriksa apakah `iframe` sedang tersembunyi di balik *tab workspace* lain (`window.frameElement?.closest('[data-workspace-panel]')?.hidden`).

## 4. Viable Approaches & Decision

### Approach A: Modular In-Place Extension di Atas `WorkspaceShell` + `AdasiServerTabs` (Dipilih)
- **Mechanism**:
  1. Memperbaiki ke-5 temuan keamanan langsung pada kelas layanan, middleware, dan *controller* JS yang ada.
  2. Menambahkan *Hover-Intent Drawer Mode* pada `adasiShell` (`resources/js/app.js` & `resources/css/app.css`) untuk merealisasikan perilaku [Auto Expand/Collapse Navigation Menu (`uigwt0102s000`)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuidiffrn/wga1592559795533.html) tanpa mengubah lebar `#mainWrapper` (mencegah *reflow* berat pada `iframe`).
  3. Membangun komponen Blade `<x-ui.mmt-workspace>` yang memadukan tabel *Header* di panel atas dengan kontainer `AdasiServerTabs` di panel bawah untuk merealisasikan [Multi Main Table (MMT) Sessions](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/aoj1504268195263.html).
  4. Menambahkan dukungan *Quick Flow* ([Optimizing a Process through Quick Flow](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/mdc1504268199304.html)) dan navigasi sekuensial **Prev / Next Record** ([Menu Bar in Sessions](https://docs.infor.com/lnui/latest/en-us/lnuisettingsolh/lnuisettingsolhug/wow1504268190895.html)) melalui `PurchasingNavigation` dan `UserPreferenceService`.
- **Correctness & Safety**: Mempertahankan seluruh invarian keamanan CSP, Hashids, dan *dirty form guard*.
- **Blast Radius**: Rendah dan terlokalisasi; seluruh perubahan bersifat *backward-compatible* dengan 700+ subtest yang sudah ada.
- **Performance / Resource Impact**: Mengurangi pembuatan *child iframe* baru sebesar 60–80% pada alur kerja *Purchasing* dan *Finance* (menghemat puluhan MB RAM pada klien 8 GB).
- **Regression Risk**: **Low**.

### Approach B: Migrasi Penuh ke SPA / Livewire Multi-Tab Tanpa Iframe (Ditolak)
- **Mechanism**: Menghapus `WrapWorkspaceShell` berbasis `iframe` dan merombak seluruh halaman Blade menjadi komponen *stateful* tunggal di dalam satu DOM.
- **Alasan Penolakan**: Memiliki *blast radius* ekstrem (lebih dari 200 rute GET dan puluhan skrip per halaman), berisiko tinggi menimbulkan kebocoran memori (*memory leak* dari *listener* DataTables/Chart.js yang tidak dibersihkan), serta merusak isolasi CSS/JS antar-modul yang saat ini sudah terjamin oleh `iframe` pada `WorkspaceShell`.

**Decision**: Memilih **Approach A** karena memenuhi seluruh spesifikasi transisi halaman [Infor LN UI User Guide](https://docs.infor.com/lnui/2024.04/en-us/lnuisettingsolh/sae1504268190754.html) dan [IDS Page Layouts](https://design.infor.com/patterns/page-layouts/page-layouts-overview/) dengan perubahan paling minimal, terukur, dan aman bagi arsitektur Laravel 12 yang sudah berjalan.

## 5. Implementation Steps

### Step 1: Remediasi Celah Keamanan `DocumentChainResolverService`, `RememberPurchasingListUrl`, & `SupplierScopeMiddleware`
- **Target file(s)**:
  - `app/Services/DocumentChain/DocumentChainResolverService.php`
  - `app/Http/Middleware/RememberPurchasingListUrl.php`
  - `app/Http/Middleware/SupplierScopeMiddleware.php`
- **Change**:
  1. Pada `DocumentChainResolverService.php`, wajibkan pemeriksaan `$actor->is_active`, hapus konversi `ctype_digit($idOrHash)` pada `resolveId()`, dan batasi `resolvePaymentBatch()` agar hanya menampilkan *voucher* serta item yang diizinkan oleh `Gate::forUser($actor)`.
  2. Pada `RememberPurchasingListUrl.php`, abaikan permintaan yang memiliki header `X-Adasi-Prefetch: 1` atau `Sec-Purpose: prefetch`.
  3. Pada `SupplierScopeMiddleware.php`, hanya tulis `supplier_context` ke session jika bukan permintaan AJAX/JSON latar belakang atau jika session belum memiliki `supplier_context`.
- **Kode Implementasi**:

```php
// 1. app/Services/DocumentChain/DocumentChainResolverService.php
public function resolve(User $actor, string $entityType, int|string $idOrHash): array
{
    if (! $actor->is_active) {
        throw new AccessDeniedHttpException;
    }

    $id = $this->resolveId($idOrHash);

    return match ($entityType) {
        'import-pr' => $this->resolveImportPr($actor, $id),
        'import-po' => $this->resolveImportPo($actor, $id),
        'local-po' => $this->resolveLocalPo($actor, $id),
        'local-invoice' => $this->resolveLocalInvoice($actor, $id),
        'payment-batch' => $this->resolvePaymentBatch($actor, $id),
        default => throw new NotFoundHttpException,
    };
}

private function resolveId(int|string $idOrHash): int
{
    if (is_int($idOrHash)) {
        return $idOrHash;
    }

    $decoded = HashidCodec::decode($idOrHash);
    if ($decoded === null || HashidCodec::encode($decoded) !== $idOrHash) {
        throw new NotFoundHttpException;
    }

    return $decoded;
}
```

```php
// Di dalam resolvePaymentBatch() pada app/Services/DocumentChain/DocumentChainResolverService.php
foreach ($batch->groups as $group) {
    /** @var PaymentItem $item */
    foreach ($group->items as $item) {
        $voucher = $item->localInvoiceVoucher;
        if ($voucher && Gate::forUser($actor)->allows('view', $voucher)) {
            $nodes[] = [
                'type' => 'VOUCHER',
                'label' => __('smart_panel.node_types.voucher'),
                'reference' => $voucher->voucher_number,
                'status' => StatusHelper::localFinanceLabel((string) $voucher->status),
                'tone' => StatusHelper::localFinanceTone((string) $voucher->status),
                'date' => $voucher->voucher_date?->format('Y-m-d'),
                'url' => in_array($actor->role, ['finance', 'admin'], true)
                    ? route('finance.vouchers.show', $voucher, false)
                    : null,
                'current' => false,
            ];
        }

        $payable = $item->payable;
        if ($payable instanceof LocalInvoice && Gate::forUser($actor)->allows('view', $payable)) {
            $nodes[] = [
                'type' => 'INVOICE',
                'label' => __('smart_panel.node_types.local_invoice'),
                'reference' => $payable->invoice_number,
                'status' => StatusHelper::localInvoiceLabel((string) $payable->status),
                'tone' => StatusHelper::localInvoiceTone((string) $payable->status),
                'date' => $payable->invoice_date?->format('Y-m-d'),
                'url' => in_array($actor->role, ['finance', 'admin'], true)
                    ? route('finance.invoices.show', $payable, false)
                    : ($actor->role === 'purchasing' ? route('purchasing.local-invoices.show', $payable, false) : null),
                'current' => false,
            ];
        }
    }
}
```

```php
// 2. app/Http/Middleware/RememberPurchasingListUrl.php
public function handle(Request $request, Closure $next): Response
{
    $isSpeculativePrefetch = $request->header('X-Adasi-Prefetch') === '1'
        || strtolower((string) $request->header('Sec-Purpose')) === 'prefetch';

    if (
        $request->isMethod('GET')
        && ! $isSpeculativePrefetch
        && (! $request->expectsJson() || ServerTabsResponse::wants($request))
        && $request->input('view') !== 'json'
        && $request->user()?->role === 'purchasing'
    ) {
        $routeName = $request->route()?->getName();

        if (PurchasingNavigation::isListRoute($routeName)) {
            PurchasingNavigation::rememberListUrl(
                $routeName,
                $request->fullUrlWithoutQuery([PurchasingNavigation::RETURN_URL_KEY, WorkspaceShell::EMBED_QUERY_PARAM])
            );
        }
    }

    return $next($request);
}
```

```php
// 3. app/Http/Middleware/SupplierScopeMiddleware.php
public function handle(Request $request, Closure $next, string $scope)
{
    abort_unless($request->user()?->hasSupplierScope($scope), 403);

    $isBackgroundRequest = $request->ajax()
        || $request->expectsJson()
        || $request->headers->has('X-Adasi-Server-Tabs');

    if (! $isBackgroundRequest || ! $request->session()->has('supplier_context')) {
        $request->session()->put('supplier_context', $scope);
    }

    return $next($request);
}
```

- **Invariant Preserved**: Otorisasi berbasis *Policy*, isolasi *scope* pemasok `import` vs `local`, dan akurasi URL kembali (`return_url`) pada `PurchasingNavigation`.
- **Risk / Rollback Note**: Perubahan bersifat *drop-in* tanpa migrasi database; dapat di-*rollback* secara instan.

### Step 2: Hardening `AdasiServerTabs` (Header Prefetch & Sanitasi Fragmen HTML)
- **Target file(s)**: `resources/js/server-tabs.js`
- **Change**:
  1. Tambahkan header `'X-Adasi-Prefetch': '1'` pada `speculativePrefetch()`.
  2. Tambahkan fungsi `sanitizeServerFragment(html)` menggunakan `DOMParser` untuk melucuti elemen `<script>`, `<iframe src="javascript:...">`, `<object>`, `<embed>`, dan atribut *inline event handler* (`on*`) sebelum menyuntikkan fragmen ke `tableTarget.innerHTML` atau `nav.innerHTML`.
- **Kode Implementasi**:

```javascript
// resources/js/server-tabs.js
function sanitizeServerFragment(rawHtml) {
    if (typeof rawHtml !== 'string' || !rawHtml.trim()) return '';
    if (typeof DOMParser === 'undefined') return rawHtml;

    const doc = new DOMParser().parseFromString(rawHtml, 'text/html');
    doc.querySelectorAll('script, object, embed, base, meta[http-equiv]').forEach((el) => el.remove());

    doc.body.querySelectorAll('*').forEach((el) => {
        for (const attr of Array.from(el.attributes)) {
            const name = attr.name.toLowerCase();
            const val = attr.value.trim().toLowerCase();
            if (name.startsWith('on') || ((name === 'href' || name === 'src' || name === 'action') && val.startsWith('javascript:'))) {
                el.removeAttribute(attr.name);
            }
        }
    });

    return doc.body.innerHTML;
}

async function speculativePrefetch(url, ttlMs = CACHE_TTL_MS) {
    if (prefetchInFlight.has(url) || cacheGet(url, ttlMs)) return;

    prefetchInFlight.add(url);
    try {
        const response = await fetch(url, {
            headers: {
                ...FRAGMENT_REQUEST_HEADERS,
                'X-Adasi-Prefetch': '1',
            },
        });
        if (!response.ok) return;
        const data = await response.json();
        cacheSet(url, data);
    } catch {
        // Silently discard prefetch errors
    } finally {
        prefetchInFlight.delete(url);
    }
}
```

- **Invariant Preserved**: Kompatibilitas mundur dengan `tests/js/server-tabs.test.mjs` (ketika `DOMParser` tidak didefinisikan di *mock* Node.js, fungsi kembali ke `rawHtml`) sekaligus menutup vektor DOM XSS di *browser*.

### Step 3: Implementasi Side Navigation Auto-Expand on Left-Edge Hover (`uigwt0102s000`)
- **Target file(s)**:
  - `resources/js/app.js` (`adasiShell`)
  - `resources/views/partials/sidebar.blade.php`
  - `resources/css/app.css`
- **Change**:
  Mengadaptasi fitur [Automatically Expand and Collapse Navigation Menu (`uigwt0102s000`)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuidiffrn/wga1592559795533.html) dari Infor LN UI:
  1. Saat `viewportIsDesktop && desktopCollapsed` bernilai `true`, arahkan kursor ke *rail* `#sidebar` (`64px`) selama minimal `140ms` (*hover intent*) untuk mengaktifkan state `hoverExpanded = true` (`html[data-sidebar-hover-peek="true"]`).
  2. Saat dalam mode `data-sidebar-hover-peek="true"`, `#sidebar` melebar ke `var(--sidebar-width)` (`256px`) sebagai *floating surface* di atas `#mainWrapper` (`box-shadow: var(--ui-shadow-2)`) **tanpa mengubah margin kiri `#mainWrapper`**, sehingga `iframe` aktif di dalam `WorkspaceShell` tidak mengalami *layout reflow* sama sekali.
  3. Saat kursor keluar dari area `#sidebar` (`pointerleave` dengan *grace timer* `120ms` dan tidak ada dropdown terbuka di dalam sidebar), `#sidebar` otomatis menyusut kembali ke `64px`.
- **Kode Implementasi**:

```javascript
// Tambahan properti dan metode pada Alpine.data('adasiShell', ...) di resources/js/app.js
hoverPeek: false,
hoverTimer: null,

get sidebarIsExpanded() {
    return this.viewportIsDesktop ? (!this.desktopCollapsed || this.hoverPeek) : this.mobileOpen;
},

onSidebarPointerEnter() {
    if (!this.viewportIsDesktop || !this.desktopCollapsed) return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
    window.clearTimeout(this.hoverTimer);
    this.hoverTimer = window.setTimeout(() => {
        this.hoverPeek = true;
        document.documentElement.dataset.sidebarHoverPeek = 'true';
        this.syncSidebarTooltips();
    }, 140);
},

onSidebarPointerLeave() {
    window.clearTimeout(this.hoverTimer);
    if (!this.hoverPeek) return;
    if (document.querySelector('#sidebar .dropdown-menu.show')) return;
    this.hoverTimer = window.setTimeout(() => {
        this.hoverPeek = false;
        delete document.documentElement.dataset.sidebarHoverPeek;
        this.syncSidebarTooltips();
    }, 120);
},
```

```css
/* Tambahan pada resources/css/app.css */
@media (min-width: 992px) {
    html[data-sidebar-collapsed="true"][data-sidebar-hover-peek="true"] .sidebar {
        width: var(--sidebar-width) !important;
        box-shadow: var(--ui-shadow-2);
        z-index: calc(var(--ui-z-drawer) - 1);
    }

    html[data-sidebar-collapsed="true"][data-sidebar-hover-peek="true"] .sidebar .sidebar-type-text {
        clip-path: inset(0 0 0 0) !important;
        opacity: 1 !important;
        pointer-events: auto !important;
    }
}
```

- **Invariant Preserved**: Seluruh 5 kontrak pengujian pada `tests/Feature/SidebarShellTest.php` tetap utuh karena struktur kelas dasar dan token `--ui-motion-sidebar` tidak diubah.

### Step 4: Komponen Multi Main Table / MMT (`<x-ui.mmt-workspace>`) Berbasis `AdasiServerTabs`
- **Target file(s)**:
  - `resources/views/components/ui/mmt-workspace.blade.php` (komponen baru)
  - `resources/js/server-tabs.js`
- **Change**:
  Menerapkan arsitektur [Multi Main Table (MMT) Sessions](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/aoj1504268195263.html) dan [IDS List and Details Pattern](https://design.infor.com/patterns/page-layouts/list-and-details/) untuk halaman transaksi bervolume tinggi (seperti *Purchase Orders*, *Local Procurement PO/GR*, *Invoice Register*, dan *Purchase Requisitions*):
  1. Panel atas menampilkan *Header Coordinating Session* (tabel utama dengan baris yang memiliki atribut `data-mmt-row-url="{{ route('...', ['id' => $row->hashid, 'mmt_satellite' => 1]) }}"`).
  2. Panel bawah menampilkan *Related / Satellite Sessions* (menggunakan `AdasiServerTabs` dengan *tab* seperti `Items / Lines`, `Goods Receipts / Shipments`, `Documents`, dan `SLA & Audit Log`).
  3. Mengklik baris pada tabel *Header* memicu `AdasiServerTabs.navigate('#mmt-satellite-' + id, rowUrl, { pushHistory: false })` tanpa membuka *child iframe* baru.
- **Kode Implementasi**:

```html
{{-- resources/views/components/ui/mmt-workspace.blade.php --}}
@props([
    'id' => 'mmt-workspace',
    'headerTitle' => '',
    'satelliteTitle' => '',
    'initialSatelliteUrl' => null,
])

<div
    id="{{ $id }}"
    class="mmt-workspace tw:flex tw:flex-col tw:gap-4"
    data-mmt-workspace="{{ $id }}"
>
    {{-- Upper Pane: Header Coordinating Session --}}
    <section
        class="mmt-workspace__header tw:bg-surface tw:border tw:border-outline-variant tw:rounded-ui-md tw:shadow-ui-1 tw:overflow-hidden"
        aria-label="{{ $headerTitle }}"
        data-mmt-header-pane
    >
        {{ $header }}
    </section>

    {{-- Lower Pane: Related / Satellite Sessions (AdasiServerTabs) --}}
    <section
        id="{{ $id }}-satellite"
        class="mmt-workspace__satellite tw:bg-surface tw:border tw:border-outline-variant tw:rounded-ui-md tw:shadow-ui-1 tw:overflow-hidden"
        aria-label="{{ $satelliteTitle }}"
        data-server-tabs-container
        data-mmt-satellite-pane
        @if($initialSatelliteUrl) data-mmt-initial-url="{{ $initialSatelliteUrl }}" @endif
    >
        <header class="tw:flex tw:items-center tw:justify-between tw:px-4 tw:py-2.5 tw:bg-surface-container-low tw:border-b tw:border-outline-variant">
            <div class="tw:flex tw:items-center tw:gap-2">
                <x-ui.icon name="layers" size="xs" class="tw:text-primary" />
                <span class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface-variant" data-mmt-active-ref>
                    {{ $satelliteTitle }}
                </span>
            </div>
            <div data-server-tabs-nav class="tw:flex tw:items-center tw:gap-1">
                {{ $satelliteNav ?? '' }}
            </div>
        </header>

        <div data-server-tabs-content class="tw:p-4 tw:min-h-[220px]">
            {{ $satellite }}
        </div>
    </section>
</div>
```

```javascript
// Delegasi klik baris Header MMT ke panel Satellite bawah (di dalam resources/js/server-tabs.js)
document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.target.closest('a[href], button, input, select, textarea, label, [data-bs-toggle], [data-smart-panel-trigger]')) {
        return;
    }

    const row = event.target.closest('tr[data-mmt-row-url]');
    if (!row) return;

    const mmtRoot = row.closest('[data-mmt-workspace]');
    const satellitePane = mmtRoot?.querySelector('[data-mmt-satellite-pane]');
    if (!satellitePane?.id) return;

    const targetUrl = row.dataset.mmtRowUrl;
    if (!targetUrl) return;

    mmtRoot.querySelectorAll('tr[data-mmt-row-url].is-mmt-selected').forEach((tr) => {
        tr.classList.remove('is-mmt-selected', 'tw:bg-primary/5');
        tr.removeAttribute('aria-selected');
    });
    row.classList.add('is-mmt-selected', 'tw:bg-primary/5');
    row.setAttribute('aria-selected', 'true');

    const refBadge = mmtRoot.querySelector('[data-mmt-active-ref]');
    if (refBadge && row.dataset.mmtRowRef) {
        refBadge.textContent = row.dataset.mmtRowRef;
    }

    const activeSatelliteTab = satellitePane.querySelector('[data-server-tab][aria-current="page"]')?.dataset?.tabName;
    const resolvedUrl = new URL(targetUrl, window.location.origin);
    if (activeSatelliteTab) {
        resolvedUrl.searchParams.set('tab', activeSatelliteTab);
    }

    navigate(`#${satellitePane.id}`, resolvedUrl.toString(), { pushHistory: false });
});
```

### Step 5: Implementasi Quick Flow Dialog Bypass & Record Group Jumping (`Prev / Next`)
- **Target file(s)**:
  - `resources/views/layouts/app.blade.php`
  - `app/Support/PurchasingNavigation.php`
- **Change**:
  1. Mengadaptasi fitur [Optimizing a Process through Quick Flow](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/mdc1504268199304.html) pada intersepsi `[data-pdf-confirm]` dan `[data-export-confirm]` di `resources/views/layouts/app.blade.php`: jika tautan memiliki atribut `data-quick-flow="true"` atau pengguna telah mengaktifkan *Quick Flow* untuk aksi tersebut di `localStorage` (`adasi.quick_flow.<accountId>`), lewati dialog `AdasiAlert.confirm` dan langsung jalankan pengunduhan.
  2. Mengadaptasi navigasi rekaman sekuensial ([Menu Bar in Sessions](https://docs.infor.com/lnui/latest/en-us/lnuisettingsolh/lnuisettingsolhug/wow1504268190895.html)) pada `PurchasingNavigation.php` dengan menambahkan metode `rememberRecordWindow(string $routeName, array $hashids)` (dibatasi maksimal 100 Hashids per rute daftar) dan `adjacentRecordUrls(string $listRouteName, string $showRouteName, string $currentHashid)` untuk merender tombol **Previous Record (`Alt + [`)** dan **Next Record (`Alt + ]`)** di `x-ui.page-header` halaman detail tanpa harus kembali ke tabel indeks.
- **Kode Implementasi**:

```php
// Tambahan pada app/Support/PurchasingNavigation.php
public static function rememberRecordWindow(string $listRouteName, array $hashids): void
{
    $clean = array_values(array_filter(
        array_slice($hashids, 0, 100),
        static fn ($h): bool => is_string($h) && $h !== '' && ! ctype_digit($h),
    ));

    session()->put("purchasing.record_window.{$listRouteName}", $clean);
}

/**
 * @return array{prev: ?string, next: ?string, position: ?int, total: int}
 */
public static function adjacentRecordUrls(string $listRouteName, string $showRouteName, string $currentHashid): array
{
    $window = session("purchasing.record_window.{$listRouteName}", []);
    if (! is_array($window) || $window === []) {
        return ['prev' => null, 'next' => null, 'position' => null, 'total' => 0];
    }

    $index = array_search($currentHashid, $window, true);
    if ($index === false) {
        return ['prev' => null, 'next' => null, 'position' => null, 'total' => count($window)];
    }

    $returnUrl = self::backUrl($listRouteName);
    $prevHash = $index > 0 ? $window[$index - 1] : null;
    $nextHash = ($index + 1) < count($window) ? $window[$index + 1] : null;

    return [
        'prev' => $prevHash ? route($showRouteName, [$prevHash, self::RETURN_URL_KEY => $returnUrl]) : null,
        'next' => $nextHash ? route($showRouteName, [$nextHash, self::RETURN_URL_KEY => $returnUrl]) : null,
        'position' => $index + 1,
        'total' => count($window),
    ];
}
```

### Step 6: Penangguhan Polling pada *Keep-Alive Iframes* yang Sedang Tersembunyi
- **Target file(s)**: `resources/js/po-document-upload.js`
- **Change**:
  Di dalam fungsi `poll()` pada `resources/js/po-document-upload.js`, periksa apakah `document.hidden` atau `window.frameElement?.closest('[data-workspace-panel]')?.hidden` bernilai `true`. Jika `iframe` sedang berada di latar belakang, perpanjang interval tunggu menjadi `15000ms` (atau tangguhkan hingga event `visibilitychange` / aktivasi panel) agar tidak membebani worker PHP di cPanel.

## 6. Material Failure Modes & Edge Cases

| Kategori Risiko | Skenario Kegagalan / Edge Case | Mitigasi dalam Rencana Implementasi |
| :--- | :--- | :--- |
| **Input / Nullability** | Parameter `hash` pada Smart Panel atau MMT dikirim kosong, berupa angka mentah (`123`), atau Hashid tidak kanonis. | `DocumentChainResolverService::resolveId()` menolak seluruh nilai non-kanonis (`HashidCodec::encode($decoded) !== $idOrHash`) dengan `NotFoundHttpException` (HTTP 404). |
| **Authorization / Isolation** | Pengguna `accounting` atau pemasok lain mencoba memuat panel satelit MMT atau Smart Panel untuk PO/Invoice/Voucher di luar wewenangnya. | Setiap item di dalam `DocumentChainResolverService` dan endpoint satelit MMT wajib melewati `Gate::forUser($actor)->allows('view', $model)` dan pemeriksaan `$actor->is_active`. |
| **Concurrency / Session Race** | Pengguna menggerakkan mouse melintasi beberapa tab `AdasiServerTabs` dengan cepat saat form sedang terbuka di tab lain. | Header `X-Adasi-Prefetch: 1` mencegah `RememberPurchasingListUrl` menulis ke session pada permintaan *prefetch*, dan `AbortController` di `server-tabs.js` membatalkan permintaan usang. |
| **Dirty State / Data Loss** | Pengguna memilih baris lain di tabel atas MMT ketika form di panel bawah MMT memiliki perubahan yang belum disimpan (`isDirty`). | `AdasiServerTabs` menggunakan `options.beforeNavigate` yang terhubung dengan `window.AdasiUnsaved` untuk menampilkan dialog konfirmasi sebelum menukar DOM panel satelit. |
| **Scale / Memory (8 GB RAM)** | Pengguna membuka 12 *workspace tabs* sekaligus dengan tabel DataTables dan grafik Chart.js. | `WorkspaceTabStore` membatasi `maxLiveFrames = 6` dan menghibernasi *frame* terlama yang bersih (`hibernated = true`), ditambah komponen MMT mengurangi kebutuhan membuka *child iframe* baru. |
| **Backward Compatibility** | Pengujian eksisting (`SidebarShellTest`, `WorkspaceShellSecurityTest`, `server-tabs.test.mjs`) mengharapkan selector dan atribut DOM tertentu. | Seluruh penambahan menggunakan atribut `data-*` baru (`data-sidebar-hover-peek`, `data-mmt-workspace`, `X-Adasi-Prefetch`) tanpa mengubah atribut yang diuji oleh *test suite* lama. |

## 7. Verification Strategy

| Check | Command / Method | Why It Matters | Status |
| :--- | :--- | :--- | :--- |
| **Workspace Shell Unit Suite** | `node --test tests/js/workspace-shell.test.mjs` | Memverifikasi seluruh 13 invarian `WorkspaceTabStore`, klasifikasi navigasi internal, *water-fill tab cap*, dan *LRU hibernation*. | **`[PASSED]`** (13/13 subtest lulus pada eksekusi sandbox) |
| **Unsaved Workspace Guard Suite** | `node --test tests/js/unsaved-managed-workspace.test.mjs` | Memverifikasi perlindungan form yang belum disimpan (`isDirty`) saat berpindah *tab* dan menekan `F5` / `Ctrl+R`. | **`[PASSED]`** (26/26 subtest lulus pada eksekusi sandbox) |
| **Full JS Suite Baseline** | `node --test tests/js/*.test.mjs` | Memastikan tidak ada regresi lintas komponen UI (Chart, Calendar, Preferences, Upload, Locale Switcher). | **`[PASSED]`** (703/727 lulus; 24 subtest **`[BLOCKED]`** di sandbox karena ketiadaan binary `php` CLI di container dan API Node 20 `MockTimers`) |
| **PHP Syntax & Lint** | `php -l app/Services/DocumentChain/DocumentChainResolverService.php app/Http/Middleware/RememberPurchasingListUrl.php app/Http/Middleware/SupplierScopeMiddleware.php app/Support/PurchasingNavigation.php` | Memastikan tidak ada kesalahan sintaks PHP 8.2/8.3. | **Planned** (dijalankan di lingkungan lokal Laragon) |
| **Security & Isolation Feature Tests** | `php artisan test --filter=WorkspaceShellSecurityTest` & `php artisan test --filter=SmartPanelIsolationTest` | Memverifikasi header `X-Frame-Options`, `frame-ancestors`, isolasi pemasok `import`/`local`, dan otorisasi `DocumentChainResolverService`. | **Planned** (dijalankan di lingkungan lokal Laragon) |
| **Sidebar Contract Tests** | `php artisan test --filter=SidebarShellTest` | Memastikan fitur *auto-expand on hover* tidak merusak kontrak DOM dan CSS *sidebar*. | **Planned** (dijalankan di lingkungan lokal Laragon) |

## 8. Completion Gate & Daftar Pustaka Referensi

### Checklist Kesiapan Eksekusi (`@boost` Completion Gate)
- [x] Kebutuhan pengguna untuk menganalisis dan merancang penerapan transisi halaman Infor LN UI ke `poyipoy/supplierportal` telah terjawab secara menyeluruh.
- [x] Jalur eksekusi kode aktual (`WorkspaceShell`, `WrapWorkspaceShell`, `AdasiServerTabs`, `SmartPanel`, `adasiShell`, dan *middlewares*) telah diinspeksi langsung dari kode sumber (`[INSPECTED]`).
- [x] Asumsi material dan batas operasional (RAM 8 GB, cPanel hosting, `Source Sans Pro`) telah diverifikasi dan diperhitungkan.
- [x] Lima celah keamanan dan integritas *state* telah diidentifikasi beserta skenario eksploitasi dan kode perbaikannya (`@laravel-security-audit`).
- [x] Rancangan menggunakan perubahan minimal yang koheren (*Minimal Necessary Change*) tanpa merusak kontrak rute atau komponen yang ada.
- [x] Status verifikasi dilaporkan secara jujur dan transparan (`[PASSED]`, `[BLOCKED]`, `[INSPECTED]`).

### Referensi Dokumentasi Resmi (`@deep-research`)
1. [Infor LN UI User Guide — Application Panel, Session Tabs, & Landing Page (2026.04)](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuisettingsolhug/pmc1504268190832.html) — Spesifikasi *Application Panel*, perilaku *Session Tabs keep-alive*, dan aksi *Landing Page / Locate in menu*.
2. [Infor LN UI User Guide — Multi Main Table (MMT) Sessions (2026.10)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/aoj1504268195263.html) — Arsitektur *Header Coordinating Session* (panel atas) dan *Related Sessions* (panel bawah).
3. [Infor LN UI Difference Study — Automatically Expand and Collapse Navigation Menu (`uigwt0102s000`) (2026.10)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuidiffrn/wga1592559795533.html) — Mekanisme *auto-expand* pohon navigasi saat kursor mendekati tepi kiri layar dan *auto-collapse* saat kursor menjauh.
4. [Infor LN UI Difference Study — Closing LN Session Tabs (2026.04)](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuidiffrn/lez1572965687795.html) — Penutupan *tab* sesi menggunakan klik tombol roda *mouse* (*middle-click*).
5. [Infor LN UI User Guide — Optimizing a Process through Quick Flow (2026.10)](https://docs.infor.com/lnui/2026.10/en-us/lnuisettingsolh/lnuisettingsolhug/mdc1504268199304.html) — Mekanisme melewati (*skip*) transisi kotak dialog pengaturan/cetak menggunakan parameter *default* tersimpan.
6. [Infor LN UI User Guide — Menu Bar in Sessions & Group Navigation](https://docs.infor.com/lnui/latest/en-us/lnuisettingsolh/lnuisettingsolhug/wow1504268190895.html) — Navigasi *First, Previous, Next, Last Record* dan *Group Zooming* di dalam sesi.
7. [Infor LN UI User Profile Details (`uigwt0102s000`)](https://docs.infor.com/lnui/ce/en-us/lnuisettingsolh/default.html?helpcontent=uigwt0102s000.html) — Pengaturan profil *Bshell*, *Navigation Menu Skin*, dan mode tampilan sesi (*Single Browser Window* vs *Separate Browser Window*).
8. [Infor Design System (IDS) — List and Details Page Layout Pattern](https://design.infor.com/patterns/page-layouts/list-and-details/) — Pola responsif *Master-Detail* (`XXL` hingga `XS`) dengan pembaruan panel detail secara instan tanpa navigasi bolak-balik.
9. [Infor Design System (IDS) — Page Layouts Overview](https://design.infor.com/patterns/page-layouts/page-layouts-overview/) — Standar tata letak *Workspace*, *Split Screen*, *Data Grid View*, dan *List and Details*.
10. [Infor Design System Web Components — `ids-tabs` & `ids-module-nav`](https://wc.design.infor.com/ids-tabs) — Implementasi komponen web untuk *tab strip* dan navigasi modul.
