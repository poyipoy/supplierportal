# ADASI — Gate 2 Mission 01: Baseline & Architecture Feasibility

Tanggal: 19 Oktober 2026. Baseline: **origin/master 317dc0092bc8e08a8eeaca40803a7b327877b88d**.

**M01 RESULT: REVISE. Karakterisasi telah dieksekusi; tidak ada GO untuk mengimplementasikan rancangan Gate 1 apa adanya. STOP sebelum M02.**

## 1. Executive findings

Instruksi lanjutan pengguna, “Lakukan saja”, ditindaklanjuti dengan menyiapkan fixture disposable, salinan source, akun database khusus dan harness proses terpisah. Laporan read-only sebelumnya yang mencatat nol tes/blocked kini digantikan oleh hasil aktual di bawah. Docker/WSL/VM tetap tidak tersedia; laporan ini tidak menyatakan profil sandbox OS untuk kode tak tepercaya telah tercapai.

Hasil penting:

- Pending MFA A dapat diganti oleh kredensial B pada browser-session/cookie-jar yang sama. Token CSRF lama ditolak; kode A tidak menyelesaikan challenge B.
- Login non-MFA tidak membersihkan pending MFA sebelumnya. Pada fixture, setelah login C, challenge lama yang memiliki password E yang sudah benar dan OTP E yang valid dapat mengganti user menjadi E. Ini keterbatasan konteks pada browser yang sama, bukan klaim penyerang tanpa kredensial menembus MFA.
- Dalam overlap nyata, file session dapat ditulis ulang oleh GET lama setelah completion, sehingga pending MFA lama muncul kembali. Pada database session, update pada row yang telah dihapus tidak mengembalikan row dalam jadwal yang diuji.
- Respons cookie lama dapat menghilangkan kontinuitas login pada kedua driver. Session pemenang di server tetap ada; cookie yang kemudian dipilih menentukan session request berikutnya.
- Native Laravel session blocking sudah mencakup load sampai save untuk SID yang sama. Eksperimen menghasilkan satu completion dan satu HTTP 419. Old/new SID sesudah pre-MFA regeneration tetap mempunyai lock berbeda.
- Atomic cache add menghasilkan satu pemenang dari dua proses, pada database dan file cache. Cache::pull dapat mengembalikan nilai yang sama kepada dua proses, sehingga bukan atomic consume.
- Pada controlled fault sesudah guard login, OTP tetap consumed dan request berikutnya masih authenticated meskipun respons completion 500. Guard/OTP/audit/session bukan satu transaksi atomik.
- Kebutuhan custom StartSession replacement **tidak terbukti**. Native primitives menyelesaikan sebagian masalah; seluruh I1–I7 Gate 1 belum terbukti dipenuhi oleh solusi yang lebih kecil maupun oleh coordinator yang diusulkan.

**Rekomendasi:** prioritaskan review targeted fixes Option A, kemudian validasi desain minimal B/lean C terhadap invariant yang tetap utuh. Option C layak sebagai kandidat authority lintas request, tetapi paket SID aliases/tombstones/custom session replacement Gate 1 belum justified sebagai pilihan default.

## 2. Verified baseline and preserved work

| Item | Evidence |
|---|---|
| Repository | https://github.com/poyipoy/supplierportal.git |
| Branch | master |
| HEAD / local master / tracking origin/master | 317dc0092bc8e08a8eeaca40803a7b327877b88d |
| Remote verification | Live git ls-remote origin refs/heads/master, cocok saat mulai dan sesudah pengujian |
| Commit date | 2026-10-19T15:40:19+07:00 |
| Source inspection/extraction | Pinned Git blobs/archive; tidak memakai customization-update |
| Framework | Laravel v12.69.3; source ref 58ea544a2a80dc03c168e13a5dc9a1d176a88717 |
| Runtime tools | PHP 8.2.30; MySQL Community Server 8.0.30; binaries yang sudah terpasang |
| Working tree awal M01 | Gate 1 plan untracked; tanpa tracked/staged source modifications |
| Gate 1 input SHA-256 | AE04F22B50649B809D18CF973B05AA44B7714C84B55A5D478664B6C4C5E8AAD8, tetap utuh |

Sesudah tes, **891 file** dari app/bootstrap/config/routes/resources/lang pada source copy dibandingkan byte-for-byte dengan archive baseline dan tetap sama. File cache/bootstrap serta storage yang dibuat framework berada di salinan sementara. Vendor disalin dari dependency yang sudah terpasang; tidak menjalankan Composer/npm install.

Delapan framework source files dicocokkan dengan upstream pada exact lock ref: StartSession, FileSessionHandler, DatabaseSessionHandler, Store, SessionGuard, DatabaseStore, FileStore dan Cache Repository. VerifyCsrfToken juga dicocokkan saat review tambahan. Tidak mengikuti moving framework branch untuk menyimpulkan patch behavior.

Tidak ada checkout, reset, stash, merge, commit, push, migration atau perubahan source autentikasi di repository. Hanya dokumen laporan ini yang diperbarui; Gate 1 plan dipertahankan.

## 3. Disposable environment and admission evidence

### 3.1 Fixture boundary

Temporary workspace:

    C:\Users\BAHRIALGI\AppData\Local\Temp\adasi-m01-22789f57cc6b

Database fixture:

| Property | Actual observation |
|---|---|
| Host / port | 127.0.0.1:58845, berbeda dari existing development endpoint |
| Datadir | Temporary workspace di atas, subfolder db; diperiksa melalui @@datadir |
| Server UUID | 3d4016c0-cbaa-11f1-993d-50ebf64ed7d2, instance baru milik fixture |
| Database name | adasi_portal_test **pada instance privat ini**, bukan database test/development yang sudah ada |
| Client privilege | SELECT, INSERT, UPDATE, DELETE pada schema fixture saja |
| Outside-schema probe | SELECT mysql.user ditolak untuk akun test pada instance privat |
| Connection guard | Laravel MySQL connector menolak port/schema/username/URL di luar fixture **sebelum PDO connect**; negative port-3306 probe PASS |
| Schema provisioning | Auth-only fixture tables dari definisi baseline; tidak menjalankan migration, migration ledger atau RefreshDatabase |
| Outbound dependencies | Mail array, broadcast null, fake/inert HTTP transport, queue sync |
| Credentials | Akun/kunci/password fake disposable; host .env tidak disalin/dibaca oleh aplikasi fixture |

Tables yang diprovisikan: users, sessions, cache, cache_locks, auth_audit_logs, auth_known_devices, user_preferences dan notifications. Ini cukup untuk request autentikasi yang diuji, bukan replika schema seluruh portal. User fixture memakai role admin; isolation supplier/finance/payment bukan coverage pengujian ini.

### 3.2 Admission checks executed before application boot

Dua belas admission checks PASS pada setiap phase:

1. Parent-only canary di luar allowed PHP paths tidak dapat dibaca.
2. Penulisan ke parent-only evidence path ditolak.
3. Source-copy canary tidak dapat ditulis.
4. Storage fixture dapat ditulis.
5. Percobaan chmod tidak membuat source-copy dapat ditulis.
6. Tidak ada source .env.
7. Process-execution helpers PHP dinonaktifkan.
8. Generic socket/HTTP helpers dan URL fopen dinonaktifkan.
9. PDO menunjuk port privat.
10. Database name sesuai fixture.
11. Datadir sesuai folder privat.
12. Akses schema di luar fixture ditolak.

Source/toolchain copy dibatasi DACL read/execute; hanya storage/bootstrap-cache serta writable runtime paths yang dipakai fixture. PHP memakai explicit ini/environment allowlist, open_basedir, memory_limit 192 MiB, inert Laravel HTTP transport dan database connector guard. PHP workers menunggu start gate sampai berhasil dimasukkan ke Windows Job Object.

Test-only bcrypt cost 4/dummy hash yang sesuai dan tarpit off mengikuti pola PHPUnit repo agar fixture terukur. MFA window, replay algorithm, expiry dan threshold limiter tidak dilonggarkan. Hasil ini bukan production password-hashing/tarpit timing benchmark. CSRF tetap aktif.

Configured worker resource controls: CPU hard cap 25%, maximum 10 active processes, 512 MiB committed-memory limit per process, 1536 MiB per job, 45 seconds per-process user CPU, parent wall timeout 55 seconds per worker dan 900 seconds per phase. Scratch growth 64 MiB di atas baseline **dimonitor oleh supervisor**, bukan hard disk quota OS.

**Batas isolasi:** ini isolasi regresi untuk trusted pinned source dan controlled fixtures. Tidak ada network namespace/VM/AppContainer; PHP guards/fakes bukan larangan OS bagi setiap native networking API. Tidak mengklaim profil universal hostile-code security-audit sandbox terpenuhi. Tidak menggunakan harness ini untuk menjalankan source/fixture tak tepercaya atau memprobe sistem lain.

MySQL Windows monitor child sempat hidup di luar job milik monitor parent. Hal itu ditemukan pada cleanup; database tetap instance disposable dengan UUID/datadir yang sama, tetapi **jangan mengklaim seluruh server DB mendapat job containment**. Server aktual kemudian diidentifikasi melalui PID file, datadir, port dan UUID, dimatikan secara terarah memakai kredensial fixture, lalu exit PID/listener diperiksa. Worker PHP yang menjalankan aplikasi tetap berada di job masing-masing.

Tidak ada koneksi ke database production/development. Probe negatif pada system schema dilakukan pada instance fixture; invalid-development-port guard melempar sebelum membuka koneksi.

## 4. Executed test record — PASS, FAIL and NOT EXECUTED

Pengujian memakai **real Laravel HTTP Kernel, controllers, middleware, session handlers, guard dan OTP services** dari source copy. Setiap request berjalan dalam proses PHP baru, bukan test client yang mempertahankan guard di memory. Cookie jar menerapkan encrypted response cookies. CSRF dipaksa aktif memakai test-only binding dengan runningUnitTests=false, mengikuti teknik existing SessionSecurityTest:507–525.

Tidak ada HTTP application server atau browser yang dipublikasikan. Source Blade dirender; Vite hot-file fixture hanya menyediakan inert asset URL dan tidak mengambil CDN. Istilah “dua tab” di hasil berarti server-side two-form/cookie-jar model, bukan real browser bfcache/visual QA.

### 4.1 Raw phase totals

| Artifact | Records | PASS | FAIL | Measured phase time |
|---|---:|---:|---:|---:|
| results.json — initial baseline | 5 | 5 | 0 | Tidak diukur pada format awal |
| results-characterization.json | 27 | 24 | 3 | 349.62 s |
| results-concurrency.json | 8 | 8 | 0 | 76.05 s |
| results-latewriters.json | 9 | 9 | 0 | 79.06 s |
| results-contracts.json | 9 | 8 | 1 | 158.24 s |
| results-limit-contract.json | 2 | 2 | 0 | 45.36 s |
| **Total recorded checks** | **60** | **56** | **4** | Bukan 60 independent end-to-end tests |

“PASS” pada reproduction check berarti pengamatan berhasil dibuktikan, **bukan** berarti perilaku yang diamati memenuhi requirement baru. Contohnya, pending resurrection dan duplicate pull observation berhasil direproduksi, tetapi tidak disebut aman.

Empat raw FAIL tetap disimpan:

- Dua redirect assertions mula-mula membandingkan Location dengan relative path. Laravel mengirim absolute URL. Follow-up memeriksa origin m01.test dan path /profile atau /dashboard; keduanya PASS.
- Satu limiter assertion mula-mula mengharapkan redirect 302 untuk request keenam. Existing primary-login contract adalah dedicated HTTP 429; source/application threshold tidak berubah.
- Follow-up limiter sempat memakai email yang sudah mencapai five-attempt/900-second email budget, sehingga seluruh request dari IP baru tetap 429. Ini fixture precondition contamination; counter tidak dihapus. Dengan identitas fixture baru, hasilnya lima 302 lalu 429, Retry-After tersedia dan remaining=0; JSON lockout juga 429.

Ini koreksi oracle/fixture, bukan menaikkan threshold agar hijau. Original failures tidak diubah menjadi PASS atau dihilangkan. Final focused contract checks PASS; tidak ada unresolved unexpected application assertion failure yang ditutupi oleh koreksi tersebut.

Setup/admission iterations sebelum baseline berhasil juga dicatat terpisah: cross-job Windows assignment, quoting open_basedir dan Laravel cache bootstrap yang memerlukan chmod. Itu kegagalan harness setup, bukan authentication test failures. DACL canary kembali diuji setelah chmod tersedia.

### 4.2 Actual authentication observations

| Case family | Executed result |
|---|---|
| Normal GET, incorrect password, non-MFA login/logout | PASS; token nyata dirender, bad password tetap guest, correct password authenticated |
| Stale CSRF sesudah pre-MFA/final regeneration | HTTP 419 observed |
| MFA stage, invalid code, valid code | Guest sebelum proof; invalid tetap guest; valid login dan pending dibersihkan |
| TOTP replay | Rejected via HTTP; independent immediate service replay juga ditolak ketika code masih time-valid |
| Recovery | First use login; reuse ditolak |
| MFA expiry | 599 s: 200, 600 s: 200, 601 s: redirect; ini baseline '< now' behavior |
| Pending replacement | A → B reproduced memakai CSRF terbaru dan cookie jar yang sama |
| Old A code against current B pending | Ditolak; valid B code menyelesaikan B |
| MFA pending → non-MFA login | Pending lama bertahan; old valid pending+OTP dapat mengganti account pada same browser context |
| Account is_active false sebelum MFA | Ditolak |
| account_status-only change ke REJECTED, is_active masih true | Completion masih diterima dalam negative fixture; operational writer/exploitability belum diverifikasi |
| Remember non-MFA | Recaller cookie memulihkan user |
| Remember MFA HTML | Memerlukan challenge baru dan tetap guest |
| Remember MFA JSON | 401, session diakhiri, tanpa pending challenge |
| Different cookie jar membuka MFA URL | Tidak mewarisi pending; redirect login |
| Internal/external intended | Final focused origin/path checks PASS |
| Limiter / Turnstile | Fresh identity fifth/sixth boundary benar; invalid token ditolak; fake 503 ProviderError mempertahankan baseline fail-open |
| Splash | Post-MFA dan post-logout marker observed; bukan visual/timing/reduced-motion verification |

## 5. Reproduced concurrency and failure evidence

Barriers berada sesudah session load atau tepat pada framework operation yang hendak diuji. Parent melepas worker hanya setelah stage signals tersedia. Cache operations memakai distinct PIDs dan shared store yang sama. Serial replay tidak disebut real concurrency.

| Experiment | Actual result | Meaning |
|---|---|---|
| Two overlapping credential POST, no blocking | Keduanya 302 dan authenticated sebagai dua fixture users | Baseline permits parallel completion; resulting browser account depends cookie order |
| Apply actual responses in opposite cookie-jar order | Effective user mengikuti response-cookie terakhir | HTTP/cookie-jar order observation; bukan real browser network/bfcache test |
| Native route blocking, same incoming SID | Owner 302, waiter 419; waiter cache-lock attempt terlihat sebelum owner release | Existing StartSession lock sudah meliputi load/save untuk SID itu |
| Blocked probe requests old/new SID sesudah pre-MFA regenerate | Kedua callback mencapai barrier sebelum release | Native lock tidak otomatis menjadi logical-session-family lock |
| Database cache add, two processes | Tepat satu winner | One-key atomic claim primitive observed |
| File cache add, two processes | Tepat satu winner | Single-filesystem atomic claim primitive observed |
| Actual Cache::pull with CacheHit barrier | Dua proses menerima nilai fixture yang sama, kedua stores | get+forget bukan atomic consumption |
| Old unblocked GET overlaps MFA completion, DB sessions | Winner tetap authenticated; old deleted row tidak muncul kembali pada late save | Driver-specific result, bukan generic persistence guarantee |
| Skenario sama, file sessions | Old pending MFA muncul kembali; SQL inventory tidak memuat file session | Reproduced stale context resurrection; tidak mencoba second OTP completion |
| Late response cookie diterapkan sesudah winner | Authenticated continuity hilang pada DB dan file | Cache/lock saja tidak menyelesaikan cookie emission |
| Native Store + false-returning test handler | Handler write=false, Store::save tidak melempar dan return null | Persistence acknowledgement limitation reproduced |
| Controlled SessionInventory failure sesudah guard | Response 500; OTP consumed, pending cleared, next independent request authenticated | Tidak ada whole-login atomicity; bukan bukti failure ini terjadi alami di produksi |
| Native regenerate/invalidate probe | ID dan CSRF rotate, owner attribute bertahan; invalidate menghapusnya | Stable attribute binding feasible; raw SID binding permanen salah |

Tidak ada cross-supplier/role escalation, anonymous MFA bypass, stolen-cookie scenario atau production attack yang diuji/diklaim.

### Risk classification

- **Reproduced UX/context limitations:** pending replacement, leftover pending account switch, response-cookie continuity loss.
- **Reproduced lifecycle risk under controlled schedule:** file-session stale pending restoration.
- **Reproduced negative fixture gap:** status-only account change accepted. Writer/business preconditions and exploit severity NOT VERIFIED.
- **Reproduced controlled partial failure:** valid proof consumed while ancillary completion fault returns 500 and auth persists.
- **Still inferred/not verified:** general feasibility of I1–I7 for a new transaction design; multi-host races; total store loss; active attacker exploitation; full process-crash durability and real browser behavior.

## 6. Framework hooks and custom-coordinator review

Pinned source observations remain:

- StartSession.php:50–98 selects blocking and locks 'session:<incoming SID>'; release follows handleStatefulRequest.
- StartSession.php:109–131 starts session, runs inner request, then stores URL, prepares session cookie and saves.
- StartSession.php:199–209 makes ordinary GET a possible session writer through previous URL/route metadata. Controller read-only is insufficient evidence.
- StartSession.php:221–249 controls cookie preparation/save. Controller-only lock ends too early.
- Store.php:181–191 ignores handler write return; :605–641 distinguishes regenerate/migrate from invalidate.
- SessionGuard.php:567–604 rotates session, queues remember cookie and emits Login before CompleteLoginService finishes.
- DatabaseSessionHandler.php:130–145 and FileSessionHandler.php:72–91 explain the observed driver difference.
- Native route blocking is source-supported and now experimentally verified; it does not protect unparticipating writers or merge different SID lock keys.

**Custom replacement is not established as necessary.** Random attempt metadata, stable owner, deadlines, correlation and cache claim primitives can use existing Laravel services. An ordinary outer middleware can also hold a lock around StartSession via middleware ordering; replacing StartSession is not the only extension seam. But outer post-response observation occurs after save, and inner rejection/exception can still be rendered before outer session persistence. Neither is a proof of complete fencing.

The proposed CoordinateLoginTransactionSession includes a SID-owner registry, generations, tombstones, no-cookie bootstrap, late-write fencing, acknowledgement, cookie suppression and cleanup. That is a substantial session-family protocol. A single new filename does not make it a small change.

Keep Gate 1 I4 intact: no stale proof/owner restoration, and no overwriting winner cookies by in-boundary stale writers. Native blocking alone fails to prove all of it. The experiments do not certify the custom coordinator either. Responses already issued before enrollment cannot be retracted; they remain a separate browser-continuity limit already acknowledged in Gate 1.

Cache record transitions, OTP DB consumption, audit/notifications, eviction, cookie queue and session save must not be called one atomic transaction. Atomic add only proves one-key claim behavior under the tested conditions.

Source aplikasi yang terkait dengan temuan runtime:

| Pinned path/lines | Responsibility |
|---|---|
| app/Http/Controllers/Auth/AuthenticatedSessionController.php:37–50,70–87 | Branch MFA/non-MFA, intended, logout |
| app/Http/Requests/Auth/LoginRequest.php:71–128 | Eligibility active/ACTIVE, password, limiter/Turnstile |
| app/Services/Auth/TwoFactorService.php:31–58,133–174 | Pending/rotation dan row-locked OTP/recovery consumption |
| app/Http/Controllers/Auth/TwoFactorChallengeController.php:31–56 | Pending user checks, clear pending, event dan completion |
| app/Services/Auth/CompleteLoginService.php:22–67 | Guard, regeneration, metadata, device/cap/notification/splash ordering |
| app/Http/Middleware/EnforceAuthSessionSecurity.php:27–64 | Deactivation, recall MFA, auth version dan timeout |
| app/Services/Auth/AuthAuditLogger.php:15–52 | Existing audit metadata allowlist dan error isolation |
| app/Support/RateLimitResponse.php:31–70 | Primary-login dedicated 429 versus ordinary warning redirect |
| app/Http/Middleware/ShareAuthSplashState.php:124–130,164–197 | Named-route issuance dan pending/feedback eligibility |

Official references: [Laravel session blocking](https://laravel.com/docs/12.x/session#session-blocking), [Laravel cache locks](https://laravel.com/docs/12.x/cache#atomic-locks), [pinned StartSession](https://github.com/laravel/framework/blob/58ea544a2a80dc03c168e13a5dc9a1d176a88717/src/Illuminate/Session/Middleware/StartSession.php), [pinned Store](https://github.com/laravel/framework/blob/58ea544a2a80dc03c168e13a5dc9a1d176a88717/src/Illuminate/Session/Store.php). Documentation supports mechanisms; fixture results above establish only the tested scope.

## 7. Options, minimum architecture and required decisions

| Option | Evidence-supported benefit | Remaining obligation | Decision |
|---|---|---|---|
| A — existing auth with targeted fixes | Smallest response to observed pending cleanup/correlation/final eligibility gaps | Does not claim new cross-SID transaction guarantees; proposed fixes need approval/tests | Preferred first scope for review |
| B — session attempt/context + native blocking | Metadata/stage/deadline feasible; same-SID native blocking works | Old/new IDs, outside writers and terminal replay/failure lifecycle not solved by payload alone | Candidate, not full-invariant certification |
| C — authoritative cache transaction | One-key atomic claims work on both stores; independent state could reject stale pending context | Binding, expiry, failure ordering, multi-record consistency, cookie/fencing and cleanup need a reviewed design | Lean candidate justified for investigation; Gate 1 full coordinator package not approved |

Explicit answers:

| Question | Decision |
|---|---|
| Is custom session coordinator technically necessary? | **Not proven.** Basic flow needs none; stronger full-I4 fencing needs further design. A StartSession replacement is not intrinsically required by Laravel. |
| Can smaller solution achieve required security properties? | Native primitives demonstrably achieve several properties. **No evidence yet that a smaller solution satisfies all I1–I7.** Do not relax I4 to declare equivalence. |
| Reproduced versus inferred? | See §5; source-only observations are now separated from actual schedules and controlled faults. No general exploit claim. |
| Minimum viable architecture? | Operationally A. For a transaction feature, test one bounded attempt + stable session binding + native blocking; add one independent cache authority only if required guarantees need it. Do not begin with a new session engine. |
| Is C still justified? | **Yes as a narrow candidate for cross-request authority; no as an automatic approval of aliases/tombstones/custom StartSession.** URL length or audit metadata alone cannot justify the larger protocol. |
| What must pass before production source changes? | Review reproduced cases, freeze invariant semantics, and resolve the design gates in §9. Native primitive tests passed within scope; a nonexistent transaction implementation cannot honestly have its acceptance tests PASS yet. |

No LoginTransactionService, flow route, coordinator, policy, MFA algorithm or production configuration is implemented by this M01.

## 8. Storage and compatibility findings

| Storage | Source/config evidence and executed coverage |
|---|---|
| Local declarations | SESSION_DRIVER=file, SESSION_LIFETIME=120, CACHE_STORE=file; cached config absent. These were not changed. |
| Repository defaults/template | Database session/cache supported; migration files for sessions/cache/cache_locks already exist. File presence is not production ledger proof. |
| Test database sessions | Real DatabaseSessionHandler observed; same-SID blocking, completion, stale writer and cookies tested. |
| Test file sessions | Real FileSessionHandler observed; login/MFA and stale writer tested; file sessions do not populate SQL inventory. |
| Database cache | Real locks/add/pull tested with shared private instance. |
| File cache | Real add/pull tested across separate processes on one host/filesystem. Full route blocking with file cache was not separately tested. |
| Default PHPUnit array stores | Not used as proof of process concurrency. |
| Production/multi-host | NOT VERIFIED. Independent file directories are not a common lock store. |
| Session inventory/cap | SessionInventoryService.php:75–78 reads SQL sessions regardless driver. File mode did not insert its authenticated session in SQL; production effectiveness is not certified. |

Choosing database sessions can change stale-save behavior and align inventory, but switching a live driver is an operational change requiring separate authorization. M01 did not make that switch in the actual application.

## 9. Gate before production implementation

M01 establishes useful characterization, not permission for M02. Next review must:

1. Decide which observed context problems to fix first and preserve exact credential/MFA/CSRF/limiter contracts.
2. Keep I1–I7 acceptance definitions explicit. Separate stale proof acceptance from cookie continuity; do not silently drop either in-boundary requirement.
3. Present the smallest design for cross-SID lifecycle/terminal authority, if needed, before choosing a custom session component.
4. Specify completion fault behavior using observed consumed-OTP/500/auth-persistence evidence; never restore consumed recovery/OTP proof to permit retries.
5. Confirm target driver/topology and inventory behavior; do not assume local file behavior equals database or multi-host.
6. Prove proposed cookie suppression/fencing/claim recovery through test-only mechanism experiments before editing production paths that depend on them.
7. Use a fresh independent fixture identity when testing limiter thresholds. Reusing identities must deliberately verify preserved buckets.
8. Obtain separate M02 approval. No production-code implementation proceeds merely because this report has PASS observations.

Future feature acceptance remains the Gate 1 T01–T33 matrix, including malformed/expired/reused flow, foreign cookie, lifecycle, partial storage loss, cleanup, logout/revocation and feature-disabled compatibility. These are **NOT EXECUTED** because the feature is not implemented.

Also NOT EXECUTED: real browser tabs/back/bfcache/autofill/accessibility/visual splash timing; production/staging; multi-host locks; full existing auth suite with RefreshDatabase; supplier/role isolation suites; broad redirect-parser fuzzing; true process death after OTP commit; malicious-source OS-sandbox testing. HTTP old-form snapshots, repeated GETs and cookie jars must not be relabeled browser QA.

## 10. Test artifacts, commands and cleanup

Retained parent-side evidence under temporary workspace/evidence:

- safe-summary.json: baseline, totals, per-result SHA-256, source-file count and cleanup result.
- runtime-admission.json and db-admission.json: boundary probes without application secrets.
- retained-results/: raw phase outcomes; original failures preserved.
- barriers-barrier.json, barriers-sid.json, barriers-add.json, barriers-pull.json, barriers-late.json: PID/stage evidence, no credential contents.
- scripts/ contains the final test-only harness. Harness was refined between phases; do not claim all 60 records came from one frozen script revision.

Executed phase commands, in the temporary environment:

    python scripts/run_suite.py
    python scripts/run_suite.py characterization
    python scripts/run_suite.py concurrency
    python scripts/run_suite.py latewriters
    python scripts/run_suite.py contracts
    python scripts/run_suite.py limit-contract

Preparation used existing PHP/MySQL binaries and copied installed dependencies. Auth fixture DDL was executed only on the new private datadir; no Artisan migration, seeder/import into an existing database, dependency installation or deployment occurred. The final test account remains DML-only.

The actual MySQL Windows child PID 26252 was reidentified using its own PID file and matching private UUID/datadir/port before graceful SHUTDOWN. PID exit and absence of listener 58845 were verified. No existing mysqld service was stopped. Temporary files contain synthetic fixture data and are retained locally for review; they are not production credentials or deployed code.

Reproduction must use a disposable environment and the boundary checks again; do not run the harness against an existing app/test DB. Setup failures and original failed harness assertions are part of the evidence, not hidden application PASS results.

## 11. Requirement coverage and confidence

| M01 task | Evidence / limitation |
|---|---|
| 1 latest master/worktree | Live remote SHA matched throughout; original work preserved |
| 2 Gate 1 source review | Pinned source/hook review; runtime observations replace hypotheses where tested |
| 3 multi-tab/pending/stale/rotation/concurrency | HTTP Kernel/cookie-jar characterization and real process barriers; real browser behavior not certified |
| 4 disposable isolation | Private instance, fixture privilege, path/DACL/connector admission and PHP jobs verified; explicit non-VM/DB-job limitations §3 |
| 5 focused tests/status | 60 raw records, 56 PASS, four retained harness FAIL with corrective evidence; no blanket all-green claim |
| 6 native primitives | Actual same-SID block, cache add/pull, regeneration/invalidation and false-save tests |
| 7 coordinator | Exact hooks plus driver/cookie/late-writer/failure evidence; necessity not presumed |
| 8 A/B/C comparison | §7; no substitution of a weaker guarantee for I4 |
| 9 storage | Database/file sessions and cache examined/tested within stated scope; live config untouched |
| 10 measurable benefits | Counts/schedules show context/lifecycle opportunities; no future feature latency or risk-reduction benchmark invented |

Confidence: high for the recorded fixture outcomes and pinned source ordering; limited for generalization to untested deployment, browsers and writer schedules. This is scoped M01 characterization, not a full security-audit certification or production release approval.

## 12. Final verification and status

- Latest remote/master/HEAD: 317dc0092bc8e08a8eeaca40803a7b327877b88d.
- Gate 1 input hash unchanged; original runtime/test/config source and databases untouched.
- Source snapshot verification: 891 pinned files unchanged after tests.
- Fixture service stopped; actual child PID exit verified.
- Document-only scope/encoding/whitespace/reference checks performed after this rewrite.
- No M02–M10 work or Login Transaction Flow implementation.

**M01 COMPLETED WITH REVISE RECOMMENDATION — STOP BEFORE M02; SEPARATE APPROVAL REQUIRED.**
