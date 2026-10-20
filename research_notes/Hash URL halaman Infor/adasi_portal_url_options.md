# ADASI Portal Supplier: current URL identifier scheme and options for Infor-like long random URLs

Scope: read-only inspection of `/home/user/supplierportal` (working tree as of 2026-10-20, `vendor/` not installed so nothing was executed) plus vendor/framework documentation. Code facts are cited as repo paths with line numbers. No files in the repo were modified, no migrations or tests were run.

## 1. Inventory: how page URLs are identified today

### Takeaway
Every operational record URL uses a single, global Hashids encoding of the auto-increment integer primary key: 62-char alphabet, minimum length 8, one salt from `HASHIDS_SALT` shared by all 28 models. A typical URL is `/purchasing/purchase-orders/{8-char hash}`. Raw integers on hashed parameters get a 404, and links are always rendered from the model. Because the salt is global, one model's hash decodes to the same integer on any other model's route. Policies and role middleware, not the hash, provide the authorization.

### Cited Findings
- Package versions: `vinkla/hashids` `^13.0` (`composer.json:21`); lock resolves `vinkla/hashids` 13.0.0 (`composer.lock:8630-8631`) and the core `hashids/hashids` 5.0.2 (`composer.lock:2002-2003`). — [composer.json / composer.lock](file:///home/user/supplierportal/composer.json)
- Config has one connection, `main`: `'salt' => (string) env('HASHIDS_SALT')`, `'length' => env('HASHIDS_LENGTH', 8)`, and alphabet `env('HASHIDS_ALPHABET', 'a–z A–Z 1–9 0')` (62 chars). Per-model connections and per-model salts do not exist. — [config/hashids.php](file:///home/user/supplierportal/config/hashids.php) (connections → main block)
- `.env.example:78-79` ships `HASHIDS_SALT=CHANGE_ME_WITH_A_LONG_RANDOM_VALUE` and `HASHIDS_LENGTH=8`. — [.env.example](file:///home/user/supplierportal/.env.example)
- Production salt guard: `AppServiceProvider::boot()` throws a `RuntimeException` in production when the salt is empty, equals the old hardcoded value `adasiportalsupplier2026_secure` or the test salt `adasi-isolated-test-salt-never-use-in-production`, or starts with `change me` or `your `. This confirms the AGENTS.md rule "Hashids production requires a unique salt from env". The guard checks only that the salt is not a known placeholder. It does not check length or entropy. — [app/Providers/AppServiceProvider.php:62-68](file:///home/user/supplierportal/app/Providers/AppServiceProvider.php)
- Tests force the salt `adasi-isolated-test-salt-never-use-in-production` (`phpunit.xml:22`, `.env.testing.example:3`). — [phpunit.xml](file:///home/user/supplierportal/phpunit.xml)
- Trait `App\Traits\HasHashids`:
  - `getRouteKey()` returns `Hashids::encode($this->getKey())`, and `$model->hash` returns the same value.
  - `resolveRouteBinding()` returns `null` (so 404) for non-strings, all-digit strings, a decode that does not yield exactly one id, and non-canonical hashes. The last case uses the check `Hashids::encode($id) !== $value`.
  - The default `Hashids` facade connection is used. There is no model-specific salt or prefix.
  — [app/Traits/HasHashids.php](file:///home/user/supplierportal/app/Traits/HasHashids.php)
- 28 models use the trait: Attachment, Conversation, ExportJob, ExportPreset, GaClaim, GaClaimDocument, LocalGoodsReceipt, LocalInvoice, LocalInvoiceDocument, LocalInvoicePayment, LocalInvoiceVoucher, LocalPoDocumentBatch, LocalProcurementImport, LocalPurchaseOrder, MaterialClaim, PaymentBatch, PaymentGroup, PrItemAward, PurchaseOrder, PurchaseRequisition, QcInspection, Quotation, Shipment, SupplierAudit, SupplierMasterDocument, SupplierOverpaymentRefund, SupplierRegistrationAttempt, User. AGENTS.md says 27; `LocalPoDocumentBatch` appears to be the addition. This is a minor documentation drift. — `grep -rl HasHashids app/Models`
- Middleware `DecodeHashids`:
  - It is appended to the `web` group (`bootstrap/app.php:62-71`). Per its own comment, it runs after implicit binding (`DecodeHashids.php:106`).
  - `HASHED_PARAM_KEYS` (`DecodeHashids.php:29-62`) lists 25 parameter names: `id`, `pr_id`, `po_id`, `quotation_id`, `inspection_id`, `supplier_id`, `shipment_id`, `award_id`, `invoice`, `purchaseOrder`, `batch`, `requisition`, `quotation`, `claim`, `user`, `shipment`, `attempt`, and others.
  - `PLAIN_ROUTE_PREFIXES` (`:68-76`) covers notifications, announcements, exchange-rates, periods, po-documents and pr-items. These stay plain integers.
  - `PLAIN_ROUTE_NAMES` (`:79-80`) is currently empty.
  - For a listed string parameter, `ctype_digit` leads to `abort(404)` (`:111-113`). Decode and canonical re-encode must match or the request gets `abort_unless(..., 404)` (`:121-126`). The parameter is then replaced with the integer (`:128`).
  This confirms the AGENTS.md rule that integer IDs on hashed params return 404 on purpose. — [app/Http/Middleware/DecodeHashids.php](file:///home/user/supplierportal/app/Http/Middleware/DecodeHashids.php)
- Sample routes:
  - Purchasing: `Route::get('/purchase-orders/{id}', ...)->name('purchase-orders.show')` (`routes/web.php:490`) inside the purchasing group.
  - Supplier: the same path at `routes/web.php:631`.
  - `Route::resource('requisitions', ...)` (`:483`).
  - Local and Finance implicit-binding routes: `/invoices/{invoice}` (`:111`, `:163`), `/drp/{batch}` (`:198`), `/supplier-audits/{supplierAudit}` (`:122`).
  - `/attachments/{attachment}` (`:379`).
  - Counts: 47 route lines use the manual scalar keys (`{id}`, `{pr_id}`, `{po_id}`, ...); 178 lines contain some `{param}`.
  So a page URL today looks like `https://host/purchasing/purchase-orders/Xk3bQ9pL`, with an 8-character hash for normal-sized IDs. — [routes/web.php](file:///home/user/supplierportal/routes/web.php)
- Hashes outside route binding:
  - 9 direct `Hashids::encode/decode` calls in 4 files, for example DataTables action HTML in `Supplier/SupplierPurchaseOrderController.php:115-117`, `Purchasing/PurchaseOrderController.php:125-127` and `PriceComparisonController.php:527-601`.
  - `FinanceDrpController.php:240-260` decodes hashed `batch_ids` from POST input and rejects raw digits with 422.
  - Query-string filters use `resolveSupplierFilter()` (`Purchasing/PurchaseOrderController.php:170`, `Purchasing/SupplierAuditController.php:229`).
  - 53 `->hash`/`getRouteKey()` uses in `app/` and 20 `->hash` uses in Blade views.
  — repo grep
- Stored URLs: `SystemNotification` persists `'url' => $this->url` in notification data (`app/Notifications/SystemNotification.php:88,119`). For example, `SupplierRegistrationService.php:317,498,553,615,707` stores `route('supplier-registrations.show', $attempt->hash)`. `NotificationUrlResolver` later re-matches the stored URL against the route table (`app/Services/NotificationUrlResolver.php:59-72`, `:226-232`). The exception is local invoices, which store `local_invoice_id` and regenerate the route (`:39-56`). So hashes are persisted inside the `notifications` table, and changing the salt or length would orphan those links (inference below). — [NotificationUrlResolver.php](file:///home/user/supplierportal/app/Services/NotificationUrlResolver.php)
- Test guard: `tests/Feature/HashidUrlSecurityTest.php` (453 lines) asserts:
  - canonical-hash-only implicit binding for 7 models (`:136-157`);
  - manual scalar routes accept hashes and reject plain or invalid ids (`:159`);
  - hashed query filters (`:210`, `:248`);
  - rendered links, comparison, history, PDF and chat payloads use hashes (`:286`);
  - controller redirects are canonical hash URLs (`:340`).
  — [tests/Feature/HashidUrlSecurityTest.php](file:///home/user/supplierportal/tests/Feature/HashidUrlSecurityTest.php)
- Existing signed URLs:
  - `LocalInvoiceReceiptController.php:19-22` and `Ga/GaController.php:103` use `URL::signedRoute('receipts.verify-supplier'|'receipts.verify-ga', ...)`. This is a permanent signature with no expiry, and it is rendered into a receipt QR code.
  - The public routes `/verify-receipt/supplier/{receipt}` and `/verify-receipt/ga/{receipt}` are `throttle:60,1` (`routes/web.php:298-303`). They take a plain receipt number and check `$request->hasValidSignature()`, returning 403 otherwise (`app/Http/Controllers/ReceiptVerificationController.php:13,28`).
  - Finance cashier intake re-validates the scanned QR URL with `URL::hasValidSignature()`, plus a path match and a revision check (`app/Services/LocalInvoice/InvoicePhysicalReceiptService.php:41-75`).
  - No `temporarySignedRoute` and no `signed` middleware alias usage were found.
  — repo grep
- OAuth/SSO: no Socialite, Passport, OAuth, OpenID or "sso" login exists in `routes/`, `app/`, `config/` or `composer.json`. The only "oauth" hit is a Slack bot token in `config/services.php:33`. The "recently added OAuth login" is not present in this checkout. — repo grep
- Authorization is still enforced independently of the hash. Examples: role/scope middleware groups in `routes/web.php`; `Gate::authorize('view', $invoice)` in `LocalInvoiceReceiptController.php:13-18`; and the notification resolver checks `$user->can('view', $invoice)` (`NotificationUrlResolver.php:41`). — repo
- No model uses Laravel `HasUuids`/`HasUlids`. The only UUID column is `notifications.id` (`database/migrations/2026_05_08_063331_create_notifications_table.php:15`). There are 111 migration files and about 97 `Schema::create` calls. — repo grep

### Inferences
- **All models produce the same hash for the same integer.** One global salt, alphabet and min length mean `User#57`, `PurchaseOrder#57` and `LocalInvoice#57` all encode to the identical string. A hash copied from one entity's URL can therefore be "replayed" on another entity's route, where it loads that entity's row #57. This does not bypass authorization, because policies and role groups still apply, but it shows the hash carries no type binding.
- **Lengths are uniform in practice.** Hashids pads to at least 8 characters, so normal IDs give uniformly 8-character keys; much larger IDs could grow longer. The exact threshold was not computed because `vendor/` is not installed.
- **Hashes are a stable public contract.** Changing the salt, alphabet or min length changes every URL. That breaks bookmarks, URLs persisted in `notifications.data.url`, links pasted into emails or chats, browser history, and any QR or PDF that embeds hashed routes. Signed receipt QRs embed the receipt number rather than a hash, so they are unaffected by a salt change. They are affected by an `APP_KEY` rotation.
- **The current scheme already meets the "no guessable integer in the URL" goal.** What it lacks compared to Infor is length and visual randomness, plus the fact that it is reversible by anyone who recovers the salt.

### Gaps
- Exact hash output samples and the length growth threshold were not demonstrated. PHP could not load `vendor/autoload.php`, and no execution was attempted beyond that.
- Whether production `HASHIDS_SALT` is long or random cannot be known from the repo. The guard only rejects known placeholders.

## 2. Is Hashids decodable or secure? What the library authors say

### Takeaway
Hashids is explicitly not a security mechanism. Its own docs say not to use it for security or sensitive data, and a published 2015 cryptanalysis shows the salt can be largely recovered with a small chosen-plaintext attack. Treat hashed IDs as reversible obfuscation. The portal's real protection is its policies, role groups and the canonical-hash/404 checks.

### Cited Findings
- hashids.org now 301-redirects to sqids.org, which says "Hashids has been upgraded & rebranded". Under "Not Good For" it lists "Sensitive data" and says "This is not an encryption library". It also warns that IDs can be exposed if someone learns the alphabet. — [sqids.org](https://sqids.org/?hashids)
- The Hashids PHP README says: "Do not use this library as a security measure". The library is "not meant or tested to be used as a security or compression tool", and output is "only padded to fit **at least** a certain length". Hashids "will continue to be maintained", with Sqids as the successor. The page was fetched at the vinkla GitHub URL, and its content is the core hashids library README. — [GitHub vinkla/hashids](https://github.com/vinkla/hashids)
- "Cryptanalysis of hashids" (2015) describes a chosen-plaintext attack: encoding 44 integers reveals most of the shuffled alphabet. The author narrows the salt to about 200 candidates for a 3-character salt, versus about 16M by brute force. He concludes that "it is quite easy to recover the salt" and that users "should assume that id's encoded by this library are fully reversible". Full recovery of long salts was not demonstrated; candidates reach about 1.5M at 8 characters. — [carnage.github.io](https://carnage.github.io/2015/08/cryptanalysis-of-hashids)
- A Semgrep rule warns against using a framework secret as the Hashids salt, because observing enough hashids may allow reconstructing the salt. — [Semgrep registry](https://registry.semgrep.dev/rule/python.django.security.hashids-with-django-secret.hashids-with-django-secret)

### Inferences
- In this portal, any authenticated user sees many hashes of their own records, for example sequential IDs in lists. If they correlate them with other clues, they could in principle enumerate neighbouring hashes. Enumeration still only yields 403 or 404 from policies, and the canonical check rejects malformed input. So the residual risk is information leakage (existence and volume of records), not data access.
- The salt is not the app's `APP_KEY`, so salt recovery does not compromise sessions or signed URLs. That is good separation to keep.

### Gaps
- I found no newer peer-reviewed analysis of salt recovery for long random salts. The 2015 post is the main primary source.

## 3. Options for "Infor-like long random strings" in this codebase, with effort and risk

### Takeaway
- **Option (d), signed or temporary URLs for links that leave the portal, gives real security value at low cost.** It is already partly in place for receipt QRs.
- **Option (a), longer min length or per-model salts, is cheap but mostly cosmetic.** It breaks every existing URL unless a dual-decode transition is built.
- **Option (b), a separate random ULID/UUID `public_id` column, is the only option that gives truly non-reversible, non-enumerable page IDs.** It is a sizeable multi-table migration.
- **Option (c), a server-side `?ctx=` context token, mimics Infor's look.** It harms sharing, the back button, bookmarks and notifications, for little security gain.
- **Option (e), SPA fragment routing, does not fit server-rendered Blade.**

### Cited Findings
- Laravel 12 `HasUuids` generates UUIDv7 by default. The docs say these "are more efficient for indexed database storage because they can be sorted lexicographically". `uniqueIds()` lets UUIDs go to chosen columns (example `['id', 'discount_code']`), and `newUniqueId()` can override generation, for example with `Uuid::uuid4()`. `HasUlids` gives 26-character, lexicographically sortable IDs. UUIDs are 36 characters. — [Laravel 12 Eloquent: UUID and ULID Keys](https://laravel.com/docs/12.x/eloquent#uuid-and-ulid-keys)
- Laravel signed URLs:
  - `URL::signedRoute()` appends a signature "which allows Laravel to verify that the URL has not been modified since it was created", which is "especially useful for routes that are publicly accessible yet need a layer of protection against URL manipulation".
  - `temporarySignedRoute()` adds an expiry that is enforced.
  - `hasValidSignature()` or the `signed` middleware (403 on failure) validates.
  - `absolute: false` with `signed:relative` covers host-independent links.
  - `hasValidSignatureWhileIgnoring()` exists, with a warning that ignored params are modifiable.
  — [Laravel 12 URL Generation: Signed URLs](https://laravel.com/docs/12.x/urls#signed-urls)
- Codebase facts used for costing (see section 1):
  - 28 hashed models and 25 hashed param names;
  - 47 manual scalar-route lines whose controllers receive integers after `DecodeHashids`;
  - 9 direct `Hashids::` calls plus the `FinanceDrpController` batch decode;
  - 53 + 20 `->hash`/`getRouteKey()` call sites;
  - stored notification URLs;
  - `HashidUrlSecurityTest`;
  - receipt QR signed URLs already in place.

### Inferences (option-by-option, specific to this repo)

**(a) Raise `HASHIDS_LENGTH` (for example 24–32), change the alphabet, or use per-model salts (Hashids connections per model).**
- Effort for length or alphabet alone: an env/config change, roughly 0.5 day including tests. Per-model salts need trait changes (connection name per model), and `DecodeHashids` would need to know which model each param maps to. That map does not exist today, because `id` is shared by PO, Claim, QcInspection and Conversation (`DecodeHashids.php:46`). The direct `Hashids::encode()` calls would also need the right connection. Estimate 2–4 days.
- Benefit:
  - Longer strings look more like Infor, but the value is still a reversible encoding of the same integer (section 2). Padding adds no entropy.
  - Per-model salts remove the cross-model replay and the "same hash for same int" artefact. That is a modest hygiene gain.
- Risk: every existing URL changes, including bookmarks, `notifications.data.url` rows, URLs in chats and emails, and browser history. A transition needs dual-decode: try the new connection, fall back to the legacy one, then 301 to the canonical URL. The existing canonical check `encode(decode(x)) === x` would need to allow both. `HashidUrlSecurityTest` expectations would change.
- Verdict: **mostly cosmetic.**

**(b) Random public identifiers: add a `public_id` ULID/UUID column per routed model and make it the route key, keeping integer PKs and FKs.**
- Approach: keep `id` as the PK so FKs, the `po_quotations` pivot and polymorphic `attachable_id`/`payable_id` are untouched. Add a unique `public_id` column, use `uniqueIds(): ['public_id']`, and set `getRouteKeyName(): 'public_id'`. For unguessability, prefer UUIDv4 via `newUniqueId()`, or a ULID if sortable is acceptable. UUIDv7 and ULID embed the creation timestamp, so they leak record creation time and have less randomness than v4.
- Effort:
  - one additive migration per routed table (up to 28), with a backfill of existing rows in chunks and a unique index. The non-nullable column needs a backfill per AGENTS.md;
  - rewriting the 47 manual `{id}`-style routes and their controllers, which today `findOrFail((int) $id)` after middleware decoding, to implicit binding or a lookup by `public_id`;
  - replacing the 9 direct `Hashids::` calls and the hashed POST/query inputs (`batch_ids`, supplier filters);
  - updating `HashidUrlSecurityTest` and the many tests that build URLs.
  Realistic estimate: **2–4 developer-weeks** including staging migration rehearsal on MySQL.
- Risk:
  - production DDL and backfill on financial tables. The repo forbids DB changes without explicit scope and requires staging rehearsal;
  - all URLs change, so the same dual-resolution transition as option (a) is needed: keep accepting the legacy hash and 301 to `public_id`;
  - extra index storage.
- Benefit: IDs are not derivable from integers, cannot be enumerated or decoded, and give no volume leakage. **Real but incremental**, because authorization already prevents IDOR.
- Exports, PDFs and notifications: exports carry IDs as JSON args (AGENTS.md), and those can stay integers. Stored notification URLs need the fallback.

**(c) Per-navigation server-side "context token" (`?ctx=<random>` mapped in session or cache with a TTL, Infor `favoriteContext` style).**
- Effort: a middleware plus a store, and rewriting link generation for every page. Medium to high, about 2–3 weeks, and it touches every Blade link and JS that builds URLs (DataTables, AdasiServerTabs, async export polling).
- Costs:
  - links become user- and session-bound and expire, so sharing a PO link with a colleague, bookmarks, the back button after TTL, and notification deep links all break or need re-mapping;
  - it conflicts with the existing `NotificationUrlResolver` re-matching, `PurchasingNavigation::LIST_ROUTES` list-URL memory and server-tabs URL handling;
  - it adds server state per navigation.
- Benefit: obscures what record is shown. The security gain over (b) plus policies is negligible.
- Verdict: **cosmetic, with significant usability regression.** Not recommended.

**(d) Signed (temporary) URLs only for links that leave the authenticated portal.**
- Current state: receipt QR codes already use `URL::signedRoute` (permanent, no expiry) over a plain receipt number, with public throttled verification.
- Candidates:
  - any future email deep links or public document links;
  - QR codes or PDFs meant for external parties;
  - download links handed to third parties;
  - with care, export download links. These are already authenticated and owner-checked, so it is optional.
  Use `temporarySignedRoute` where the link should expire, and render a friendly page for `InvalidSignatureException`.
- Effort: small, from hours to a few days per link type. Consider whether receipts should be permanent (a printed QR must stay valid, so the current permanent choice is defensible) and remember that `APP_KEY` rotation invalidates them.
- Benefit: **real.** It provides tamper-proofing and optional expiry exactly where no session or policy protects the request.

**(e) SPA-style fragment routing (`#/po/...`).**
- The portal is server-rendered Blade with no API layer (AGENTS.md "Tech Stack"). URL fragments are never sent to the server, so routing state in fragments would need a client router plus JSON endpoints for every page. That is a rewrite, not an option.
- Fragments also give no security, because they are visible in the browser just like the path.
- Verdict: **not applicable.**

### Gaps
- No measured cost for production backfill time per table; row counts are unknown from the repo.
- Infor's actual URL and state mechanism (`favoriteContext` and similar) was not researched here. This is assumed to be covered by other researchers.
- Whether external parties ever receive deep links to authenticated pages (for example by email) could not be confirmed. No `app/Mail` directory exists, and notifications are database or broadcast.

## 4. Recommendation for this project

### Takeaway
Keep the current Hashids scheme as the in-portal URL format. Its security value comes from the policies, role/scope groups and canonical-hash 404s, and these are tested. Put real effort into option (d) for any link that leaves the portal. If the owner insists on Infor-like length, the cheapest honest path is option (a) with a dual-decode transition, labelled as cosmetic. Choose option (b) only if non-reversible, non-enumerable IDs become a stated security or compliance requirement, and then plan it as a scoped migration project with staging rehearsal.

### Cited Findings
- Hashids is explicitly not a security measure. — [sqids.org](https://sqids.org/?hashids); [GitHub hashids README](https://github.com/vinkla/hashids)
- Signed and temporary signed URLs are Laravel's built-in mechanism for publicly reachable links that need tamper protection. — [Laravel 12 URLs](https://laravel.com/docs/12.x/urls#signed-urls)
- The portal already enforces policy and role checks independent of the hash, rejects raw integers with 404, and guards production salt placeholders. — `app/Http/Middleware/DecodeHashids.php:111-126`, `app/Traits/HasHashids.php`, `app/Providers/AppServiceProvider.php:62-68`, `tests/Feature/HashidUrlSecurityTest.php`

### Inferences
- **Real benefit, ranked:**
  1. Maintain policies and authorization on every route. This is already the true control.
  2. Option (d) for externally exposed links.
  3. Option (b) for non-enumerability.
  4. Per-model salts, for hygiene against cross-model replay.
- **Mostly cosmetic:** a longer min length, alphabet tweaks, and `?ctx=` tokens.
- **Low-cost hardening without changing URLs:**
  - Extend the production guard to require a minimum salt length, for example 32 or more random characters. Today it only rejects placeholders.
  - Document that the salt must never change once production URLs exist, unless a dual-decode migration is in place.
  - Fix the AGENTS.md hashid model count (28, not 27).

### Gaps
- The business driver behind "Infor-like URLs" (security audit finding, aesthetics, or user expectation) is not stated in the repo. The recommendation depends on it.
