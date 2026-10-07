# Implementation Plan — Phase 1: User Customization

## 1. Overview

Phase 1 User Customization bertujuan menyediakan personalisasi dasar untuk setiap pengguna ADASI Supplier Portal tanpa mengubah authorization, business workflow, maupun struktur navigasi utama yang telah tersedia.

Customization harus bersifat **per-user**, tersimpan secara persisten, dan tetap mengikuti role serta portal context pengguna.

### Phase 1 Scope

Fitur yang akan diimplementasikan:

| Feature | Available Options |
|---|---|
| Theme | `light`, `dark`, `system` |
| Interface Density | `comfortable`, `compact` |
| Sidebar Default | `expanded`, `collapsed` |
| Rows per Page | `10`, `25`, `50`, `100` |
| Quick Access | Favorite menu berdasarkan role dan portal context |
| Reset Preferences | Mengembalikan seluruh customization ke system default |

Phase 1 tidak mencakup dashboard drag-and-drop, localization, timezone, accent color, maupun notification preferences.

---

# 2. Goals

Implementasi Phase 1 memiliki tujuan berikut:

1. Memberikan personal workspace kepada setiap user.
2. Menyimpan customization berdasarkan user account.
3. Mempertahankan role-based access control yang sudah tersedia.
4. Memanfaatkan design system yang sudah digunakan Supplier Portal.
5. Mengurangi konfigurasi UI yang masih hardcoded.
6. Menghindari perubahan besar pada existing sidebar dan authentication flow.
7. Menjadi foundation untuk customization lanjutan pada Phase 2.

---

# 3. Account Settings Structure

Account menu akan dipisahkan menjadi:

```text
/profile
/profile/security
/profile/customization
/notifications
```

User dropdown:

```text
User Name
user@company.com

My Profile
Security
Customization
Notifications

──────────────
Logout
```

Customization tersedia untuk seluruh authenticated user:

```text
Admin
Purchasing
Supplier
Finance
Accounting
QC
GA
```

Namun pilihan Quick Access harus mengikuti permission masing-masing user.

---

# 4. Customization Page

Route:

```text
/profile/customization
```

Page structure:

```text
Customization
Personalize how Supplier Portal looks and behaves.

Appearance
┌───────────────────────────────────────────┐
│ Theme                                     │
│ [ Light ] [ System ✓ ] [ Dark ]          │
│                                           │
│ Interface Density                         │
│ ○ Comfortable      ● Compact             │
│                                           │
│ Sidebar                                   │
│ ● Expanded         ○ Collapsed           │
└───────────────────────────────────────────┘

Data Display
┌───────────────────────────────────────────┐
│ Rows per page                             │
│ [ 25 ▼ ]                                  │
└───────────────────────────────────────────┘

Quick Access
┌───────────────────────────────────────────┐
│ ☑ Purchase Orders                         │
│ ☑ Quotations                              │
│ ☐ Shipments                               │
│ ☐ Claims                                  │
└───────────────────────────────────────────┘

                         Reset   Save Changes
```

---

# 5. Database Design

Customization tidak disimpan langsung pada tabel `users`.

Buat tabel baru:

```text
user_preferences
```

Relationship:

```text
User
 │
 │ hasOne
 ▼
UserPreference
```

## Migration

File:

```text
database/migrations/xxxx_xx_xx_xxxxxx_create_user_preferences_table.php
```

Proposed schema:

```php
Schema::create('user_preferences', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')
        ->unique()
        ->constrained()
        ->cascadeOnDelete();

    $table->string('theme', 20)->default('system');
    $table->string('density', 20)->default('comfortable');
    $table->string('sidebar_state', 20)->default('expanded');

    $table->unsignedSmallInteger('page_size')->default(25);

    $table->json('quick_access')->nullable();

    $table->timestamps();
});
```

Default configuration:

```text
theme          = system
density        = comfortable
sidebar_state  = expanded
page_size      = 25
quick_access   = []
```

Existing users tidak perlu langsung memiliki row `user_preferences`.

Preference dapat dibuat secara lazy ketika user pertama kali menyimpan customization.

---

# 6. UserPreference Model

File baru:

```text
app/Models/UserPreference.php
```

Implementation:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'theme',
        'density',
        'sidebar_state',
        'page_size',
        'quick_access',
    ];

    protected function casts(): array
    {
        return [
            'page_size' => 'integer',
            'quick_access' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Tambahkan relationship pada:

```text
app/Models/User.php
```

```php
public function preference(): HasOne
{
    return $this->hasOne(UserPreference::class);
}
```

---

# 7. Preference Configuration

Tambahkan configuration file:

```text
config/user_preferences.php
```

Implementation:

```php
<?php

return [

    'defaults' => [
        'theme' => 'system',
        'density' => 'comfortable',
        'sidebar_state' => 'expanded',
        'page_size' => 25,
        'quick_access' => [],
    ],

    'themes' => [
        'light',
        'dark',
        'system',
    ],

    'densities' => [
        'comfortable',
        'compact',
    ],

    'sidebar_states' => [
        'expanded',
        'collapsed',
    ],

    'page_sizes' => [
        10,
        25,
        50,
        100,
    ],

];
```

Tujuannya agar system default tidak tersebar pada controller, Blade, maupun JavaScript.

---

# 8. UserPreferenceService

Buat:

```text
app/Services/UserPreferenceService.php
```

Service bertanggung jawab terhadap:

- mengambil preference user;
- menggabungkan stored preference dengan default;
- menyediakan effective preferences;
- menghindari duplicate fallback logic;
- melakukan reset preference.

Contoh penggunaan:

```php
$preferences = $userPreferenceService->for($user);
```

Output:

```php
[
    'theme' => 'system',
    'density' => 'comfortable',
    'sidebar_state' => 'expanded',
    'page_size' => 25,
    'quick_access' => [],
]
```

Jika user belum mempunyai record preference, service harus menggunakan:

```php
config('user_preferences.defaults')
```

tanpa wajib membuat record baru.

---

# 9. UserPreferenceController

File:

```text
app/Http/Controllers/UserPreferenceController.php
```

Controller memiliki tiga action:

```php
edit()
update()
destroy()
```

Responsibilities:

### `edit()`

Menampilkan halaman Customization.

### `update()`

Melakukan validasi dan menyimpan preference.

### `destroy()`

Menghapus atau mengembalikan preference user ke system default.

---

# 10. Routes

Tambahkan pada authenticated route group:

```php
Route::get(
    '/profile/customization',
    [UserPreferenceController::class, 'edit']
)->name('profile.customization');

Route::put(
    '/profile/customization',
    [UserPreferenceController::class, 'update']
)->name('profile.customization.update');

Route::delete(
    '/profile/customization',
    [UserPreferenceController::class, 'destroy']
)->name('profile.customization.reset');
```

Semua route wajib berada di dalam:

```php
Route::middleware('auth')
```

---

# 11. Validation

Buat:

```text
app/Http/Requests/UpdateUserPreferenceRequest.php
```

Validation rules:

```php
return [

    'theme' => [
        'required',
        Rule::in(['light', 'dark', 'system']),
    ],

    'density' => [
        'required',
        Rule::in(['comfortable', 'compact']),
    ],

    'sidebar_state' => [
        'required',
        Rule::in(['expanded', 'collapsed']),
    ],

    'page_size' => [
        'required',
        'integer',
        Rule::in([10, 25, 50, 100]),
    ],

    'quick_access' => [
        'nullable',
        'array',
        'max:6',
    ],

    'quick_access.*' => [
        'string',
    ],

];
```

Quick Access membutuhkan validation tambahan karena nilai harus diverifikasi berdasarkan:

```text
User
 ↓
Role
 ↓
Portal Context
 ↓
Available Menu Registry
 ↓
Validated Quick Access
```

Client request tidak boleh menjadi sumber authorization.

---

# 12. Theme Implementation

Phase 1 menyediakan:

```text
Light
Dark
System
```

Gunakan attribute pada root document:

```html
<html data-theme="light">
```

atau:

```html
<html data-theme="dark">
```

Untuk preference:

```text
system
```

browser mendeteksi OS preference menggunakan:

```javascript
window.matchMedia('(prefers-color-scheme: dark)')
```

## Theme Tokens

Dark mode tidak boleh dibuat dengan override component satu per satu.

Gunakan semantic design tokens.

Example:

```css
:root,
[data-theme="light"] {
    --md-surface: #ffffff;
    --md-on-surface: #1a1c1e;
    --md-surface-container: #f1f5f9;
}
```

Dark mode:

```css
[data-theme="dark"] {
    --md-surface: #111318;
    --md-on-surface: #e2e2e6;
    --md-surface-container: #1d2025;
}
```

Existing component yang menggunakan:

```text
surface
surface-container
on-surface
outline
primary
```

akan otomatis mengikuti active theme.

---

# 13. Prevent Theme Flash

Theme harus ditentukan sebelum halaman selesai dirender.

Hindari kondisi:

```text
Light UI
   ↓
Page loaded
   ↓
Dark UI
```

Tambahkan theme bootstrap script pada:

```text
resources/views/layouts/app.blade.php
```

di `<head>` sebelum UI utama dirender.

Initial preference harus menentukan:

```text
light
dark
system → browser preference
```

kemudian assign:

```javascript
document.documentElement.dataset.theme = effectiveTheme;
```

Tujuannya mencegah **Flash of Incorrect Theme**.

---

# 14. Live Theme Preview

Pada halaman Customization, Theme dan Density dapat dipreview sebelum disimpan.

Ketika user memilih Dark:

```javascript
document.documentElement.dataset.theme = 'dark';
```

Ketika user memilih Compact:

```javascript
document.documentElement.dataset.density = 'compact';
```

Preference baru hanya menjadi permanent ketika user menekan:

```text
Save Changes
```

Jika user meninggalkan halaman tanpa menyimpan, persisted preference tetap menjadi sumber konfigurasi pada page load berikutnya.

---

# 15. Interface Density

Gunakan root attribute:

```html
<html data-density="comfortable">
```

atau:

```html
<html data-density="compact">
```

Default:

```text
comfortable
```

Compact mode difokuskan pada halaman yang data-heavy.

Contoh CSS:

```css
[data-density="compact"] {
    --ui-table-cell-y: 0.45rem;
    --ui-control-height: 2rem;
    --ui-section-gap: 0.75rem;
}
```

Phase 1 dapat menerapkan density terhadap:

- table rows;
- DataTable controls;
- sidebar menu;
- toolbar;
- selected form controls;
- section spacing.

Density tidak boleh membuat interactive target terlalu kecil atau mengurangi accessibility.

---

# 16. Sidebar Preference

Existing sidebar sudah mempunyai state:

```javascript
desktopCollapsed
```

serta menggunakan localStorage:

```javascript
localStorage.setItem(
    'sidebarCollapsed',
    String(this.desktopCollapsed)
);
```

Phase 1 tetap mempertahankan localStorage.

Arsitektur menjadi:

```text
Database Preference
       ↓
Cross-device default

LocalStorage
       ↓
Fast browser state
```

Database digunakan agar preference mengikuti user pada perangkat lain.

LocalStorage digunakan untuk menghindari layout shifting saat browser melakukan page load.

Flow:

```text
Login
  ↓
Load user preference
  ↓
sidebar_state
  ↓
Initialize browser state
  ↓
Alpine sidebar
```

Jika user menyimpan:

```text
Sidebar = Collapsed
```

database dan current browser state harus disinkronkan.

---

# 17. Rows per Page

Current pages masih memiliki konfigurasi seperti:

```javascript
pageLength: 25
```

Preference baru menyediakan:

```text
10
25
50
100
```

Jangan mengambil preference langsung pada setiap Blade.

Hindari:

```javascript
pageLength: {{ auth()->user()->preference->page_size }}
```

Gunakan global frontend preference.

Contoh:

```javascript
window.ADASI = {
    preferences: {
        pageSize: 25
    }
};
```

Kemudian:

```javascript
pageLength: window.ADASI.preferences.pageSize
```

Idealnya dibuat helper:

```javascript
window.AdasiPreferences.pageSize
```

atau DataTable shared defaults:

```javascript
window.AdasiDataTable.defaults()
```

Usage:

```javascript
$('#table').DataTable({
    ...AdasiDataTable.defaults(),

    processing: true,
    serverSide: true,
});
```

Default:

```text
25
```

---

# 18. Quick Access

Quick Access merupakan shortcut terhadap menu yang sudah tersedia.

Phase 1 tidak menghapus atau memindahkan original sidebar navigation.

Contoh:

```text
QUICK ACCESS

★ Purchase Orders
★ Quotation Period
★ Shipments

OVERVIEW
Dashboard

BUSINESS
Quotation Period
Purchase Order
Shipments
...
```

Maximum Quick Access:

```text
6 items
```

Quick Access bukan permission system.

---

# 19. Quick Access Registry

Tambahkan:

```text
config/quick_access.php
```

Contoh Supplier Import:

```php
return [

    'supplier.import' => [

        'quotations' => [
            'label' => 'Quotation Period',
            'route' => 'supplier.quotations.index',
            'icon' => 'calendar-days',
        ],

        'purchase-orders' => [
            'label' => 'Purchase Orders',
            'route' => 'supplier.purchase-orders.index',
            'icon' => 'receipt',
        ],

        'shipments' => [
            'label' => 'Shipments',
            'route' => 'supplier.shipments.index',
            'icon' => 'truck',
        ],

        'claims' => [
            'label' => 'Material Claim',
            'route' => 'supplier.claims.index',
            'icon' => 'shield-alert',
        ],

    ],

];
```

Registry harus mendukung:

```text
supplier.import
supplier.local
admin
purchasing
finance
accounting
qc
ga
```

---

# 20. QuickAccessService

Tambahkan:

```text
app/Services/QuickAccessService.php
```

Responsibilities:

```text
User
 ↓
Determine role
 ↓
Determine portal context
 ↓
Load allowed registry
 ↓
Filter saved preferences
 ↓
Return safe navigation items
```

Contoh:

```php
$quickAccess->availableFor($user);
```

dan:

```php
$quickAccess->selectedFor($user);
```

Jangan menghasilkan route berdasarkan request value secara langsung.

Incorrect:

```php
route($request->quick_access[0]);
```

Correct concept:

```php
$key = $request->quick_access[0];

$item = $registry[$key] ?? null;
```

Hanya route dari trusted registry yang boleh dirender.

---

# 21. Supplier Portal Context

Supplier mempunyai dua kemungkinan context:

```text
Local Supplier
Material Procurement / Import
```

Quick Access harus mengikuti active context.

Contoh:

```text
supplier.local

Dashboard
Ajukan Invoice
Daftar Invoice
Purchase Orders
Profil Vendor
Pengumuman
```

Supplier Import:

```text
supplier.import

Dashboard
Quotation Period
Purchase Orders
Shipments
Negotiation
Claims
Price History
Export History
Information
```

Menu dari context berbeda tidak boleh muncul sebagai Quick Access aktif.

---

# 22. Navbar Integration

Current:

```text
Profile & Security
```

Target:

```text
My Profile
Security
Customization
Notifications
```

Proposed menu:

```text
User Name
user@example.com

My Profile
Security
Customization
Notifications

──────────────
Logout
```

Customization menggunakan icon seperti:

```text
palette
sliders-horizontal
settings-2
```

sesuai icon system yang tersedia.

---

# 23. Customization Blade

File baru:

```text
resources/views/profile/customization.blade.php
```

Main sections:

```text
Page Header

Appearance
├── Theme
├── Interface Density
└── Sidebar

Data Display
└── Rows per Page

Quick Access
└── Favorite Menu Selection

Actions
├── Reset to Default
└── Save Changes
```

Page harus menggunakan existing UI components sebanyak mungkin.

Contoh:

```text
x-ui.page-header
x-ui.card
x-ui.icon
x-ui.alert
```

Tujuannya menjaga consistency dengan existing design system.

---

# 24. Frontend Preferences Module

Tambahkan:

```text
resources/js/preferences.js
```

Responsibilities:

```text
Theme handling
System theme listener
Density handling
Preference preview
Preference restoration
Global frontend preferences
```

Import dari:

```text
resources/js/app.js
```

Contoh:

```javascript
import './preferences';
```

Expose readonly API jika diperlukan:

```javascript
window.AdasiPreferences
```

Example:

```javascript
window.AdasiPreferences.pageSize
window.AdasiPreferences.theme
window.AdasiPreferences.density
```

---

# 25. System Theme Listener

Jika preference:

```text
system
```

UI harus merespons perubahan OS theme tanpa refresh.

Concept:

```javascript
const media = window.matchMedia('(prefers-color-scheme: dark)');

media.addEventListener('change', () => {
    if (savedTheme === 'system') {
        applySystemTheme();
    }
});
```

Jika user memilih Light atau Dark secara eksplisit, perubahan OS tidak boleh mengganti theme.

---

# 26. Reset Preferences

Button:

```text
Reset to Default
```

Reset menghasilkan:

```text
theme          → system
density        → comfortable
sidebar_state  → expanded
page_size      → 25
quick_access   → []
```

Reset dapat dilakukan dengan menghapus row `user_preferences` agar fallback kembali ke config defaults.

Route:

```text
DELETE /profile/customization
```

Sebelum reset sebaiknya gunakan confirmation modal.

---

# 27. Files to Create

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── UserPreferenceController.php
│   │
│   └── Requests/
│       └── UpdateUserPreferenceRequest.php
│
├── Models/
│   └── UserPreference.php
│
└── Services/
    ├── UserPreferenceService.php
    └── QuickAccessService.php

config/
├── user_preferences.php
└── quick_access.php

database/
└── migrations/
    └── xxxx_create_user_preferences_table.php

resources/
├── views/
│   └── profile/
│       └── customization.blade.php
│
└── js/
    └── preferences.js
```

---

# 28. Existing Files to Modify

Potential existing files:

```text
app/Models/User.php

routes/web.php

resources/views/layouts/app.blade.php
resources/views/partials/navbar.blade.php
resources/views/partials/sidebar.blade.php

resources/js/app.js
resources/css/app.css

tailwind.config.js
```

DataTable pages yang masih menggunakan:

```javascript
pageLength: 25
```

akan dimigrasikan menggunakan shared preference secara bertahap.

---

# 29. Implementation Sequence

## P1.1 — Preference Database Foundation

Implement:

```text
Migration
UserPreference model
User relationship
```

Acceptance:

```text
✓ Migration succeeds
✓ User hasOne preference
✓ Existing users continue working
✓ Delete user removes preference
```

---

## P1.2 — Preference Configuration

Implement:

```text
config/user_preferences.php
UserPreferenceService
```

Acceptance:

```text
✓ Default configuration centralized
✓ Missing preference falls back to default
✓ Existing user does not require DB row
```

---

## P1.3 — Customization Backend

Implement:

```text
UserPreferenceController
UpdateUserPreferenceRequest
Routes
```

Acceptance:

```text
✓ Authenticated user can access page
✓ Guest redirected to login
✓ Valid preference can be stored
✓ Invalid values rejected
```

---

## P1.4 — Customization UI

Implement:

```text
Customization page
Theme controls
Density controls
Sidebar controls
Page size
Quick Access
Save
Reset
```

Acceptance:

```text
✓ Consistent with ADASI design system
✓ Responsive
✓ Keyboard accessible
✓ Correct current preferences displayed
```

---

## P1.5 — Theme

Implement:

```text
Light
Dark
System
```

Acceptance:

```text
✓ Theme persists
✓ System theme follows browser/OS
✓ No significant theme flash
✓ Existing components remain readable
```

---

## P1.6 — Interface Density

Implement:

```text
Comfortable
Compact
```

Acceptance:

```text
✓ Density persists
✓ Tables and toolbars respond
✓ Accessibility maintained
```

---

## P1.7 — Sidebar Preference

Implement:

```text
Expanded
Collapsed
```

Acceptance:

```text
✓ Preference stored on server
✓ Local browser state synchronized
✓ No initial sidebar layout shift
✓ Mobile sidebar unaffected
```

---

## P1.8 — DataTable Page Size

Replace hardcoded:

```javascript
pageLength: 25
```

with shared preference.

Acceptance:

```text
✓ 10 rows works
✓ 25 rows works
✓ 50 rows works
✓ 100 rows works
✓ Existing server-side DataTables remain functional
```

---

## P1.9 — Quick Access Registry

Implement:

```text
config/quick_access.php
QuickAccessService
```

Acceptance:

```text
✓ Menus generated from trusted registry
✓ Role restrictions respected
✓ Supplier portal context respected
✓ Maximum six shortcuts
```

---

## P1.10 — Quick Access Sidebar

Render:

```text
QUICK ACCESS
```

above standard role navigation.

Acceptance:

```text
✓ Only appears when user selects shortcuts
✓ Original menu remains intact
✓ Current page state works
✓ Collapsed sidebar supports shortcut icons
```

---

## P1.11 — Navbar Integration

Replace:

```text
Profile & Security
```

with:

```text
My Profile
Security
Customization
Notifications
```

Acceptance:

```text
✓ Routes correct
✓ Existing Logout unaffected
✓ User menu remains responsive
```

---

## P1.12 — Reset Preferences

Implement reset flow.

Acceptance:

```text
✓ Confirmation required
✓ DB preference reset
✓ UI returns to default
✓ Quick Access removed
```

---

## P1.13 — Automated Tests

Implement feature and integration tests.

---

# 30. Test Plan

Minimum test coverage:

| Test | Expected Result |
|---|---|
| Authenticated user opens customization | `200 OK` |
| Guest opens customization | Redirect login |
| Save valid preferences | Success |
| Invalid theme | Rejected |
| Invalid density | Rejected |
| Invalid sidebar state | Rejected |
| Invalid page size | Rejected |
| More than 6 favorites | Rejected |
| Supplier saves Admin shortcut | Rejected |
| User A updates preference | User B unaffected |
| Reset preference | Defaults restored |
| Theme persists | Same after refresh/login |
| Sidebar persists | Correct state |
| Rows per page persists | DataTable follows preference |
| Quick Access follows role | Correct menus |
| Supplier Quick Access follows context | Local/Import separated |

---

# 31. Security Requirements

Customization must never alter authorization.

The following rule must always hold:

```text
Customization
      ≠
Authorization
```

Quick Access only controls shortcut visibility.

Actual access continues to depend on:

```text
Authentication
Role Middleware
Policies
Gates
Portal Context
Existing authorization logic
```

Manipulating customization requests must not provide access to unauthorized routes.

---

# 32. Performance Requirements

Preference data should not generate unnecessary database queries on every component.

Recommended:

```text
Load user preference once
        ↓
Resolve effective preference
        ↓
Expose to layout/frontend
        ↓
Reuse throughout request
```

Avoid:

```text
auth()->user()->preference
```

being queried independently from multiple partials.

The relationship may be eager-loaded or resolved through `UserPreferenceService`.

---

# 33. Accessibility Requirements

Customization must preserve accessibility.

Requirements:

```text
Theme contrast remains readable
Keyboard navigation works
Focus state remains visible
Compact mode does not shrink controls excessively
System theme honors user OS preference
Radio controls have proper labels
Reset confirmation is keyboard accessible
```

---

# 34. Backward Compatibility

Users who never open Customization must experience the existing UI behavior as closely as possible.

Default:

```text
Theme          System
Density        Comfortable
Sidebar        Expanded
Rows per Page  25
Quick Access   None
```

No existing account requires manual migration of preference data.

---

# 35. Out of Scope — Phase 1

The following features are intentionally excluded:

```text
Dashboard widget customization
Widget drag-and-drop
Widget visibility
Dashboard layout persistence

Language / localization
Timezone
Date format
Time format

Accent colors
Custom colors
Custom fonts
Custom CSS

Notification preferences

User-defined sidebar ordering
User-defined menu hiding

Custom homepage

Supplier-specific dashboard layouts
```

These features can be considered for Phase 2.

---

# 36. Phase 2 Candidates

Potential Phase 2:

```text
Dashboard widgets
├── Show / Hide
├── Reorder
└── Role-aware widgets

Regional
├── Language
├── Timezone
├── Date Format
└── Time Format

Appearance
└── Approved Accent Colors

Notifications
├── In-app preferences
├── Email preferences
└── Category preferences
```

---

# 37. Definition of Done

Phase 1 dianggap selesai apabila authenticated user dapat membuka:

```text
/profile/customization
```

dan melakukan konfigurasi:

```text
Theme
├── Light
├── Dark
└── System

Density
├── Comfortable
└── Compact

Sidebar
├── Expanded
└── Collapsed

Rows per Page
├── 10
├── 25
├── 50
└── 100

Quick Access
└── Maximum 6 authorized menu items
```

Final requirements:

```text
✓ Preferences stored per-user
✓ Preferences persist after logout/login
✓ Database supports cross-device preferences
✓ Theme applies before visible UI render
✓ System theme follows OS preference
✓ Sidebar preference persists
✓ Mobile sidebar remains functional
✓ DataTable page size follows user preference
✓ Quick Access respects role authorization
✓ Supplier Quick Access respects portal context
✓ Existing navigation remains available
✓ Existing authentication remains functional
✓ Existing 2FA remains functional
✓ Reset to Default works
✓ Feature tests pass
✓ Existing regression tests pass
```

---

# 38. Recommended Development Strategy

Phase 1 sebaiknya tidak melakukan rewrite besar terhadap existing sidebar.

Existing navigation tetap menjadi source utama.

Quick Access hanya menjadi layer tambahan:

```text
Quick Access
      ↓
Shortcut Layer
      ↓
Existing Navigation
```

Theme memanfaatkan existing semantic design tokens.

Rows per page digunakan sebagai langkah awal untuk mengurangi DataTable configuration yang masih hardcoded.

Preference database menjadi foundation untuk customization berikutnya tanpa membuat tabel `users` semakin besar.

Dengan pendekatan tersebut, Phase 1 memberikan personalization yang nyata dengan risiko perubahan terhadap existing business functionality yang relatif rendah.