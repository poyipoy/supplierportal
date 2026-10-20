# URL Identifier / Token Techniques: Security and Usability Value (for an "Infor-like long random string on every page" decision)

Scope note: researched 2026-10-20. Sources fetched in this session are cited inline. Where a claim relies on a standard that was not fetched in this session it is marked "(not fetched this session)". Repo context checked read-only: this portal already uses Hashids (`config/hashids.php`: salt from `HASHIDS_SALT`, default min length 8, 62-char alphabet) and sends `Referrer-Policy: strict-origin-when-cross-origin` (`app/Http/Middleware/AddSecurityHeaders.php`).

## Q1. Technique catalog: what each technique is, what it mitigates, what it does not, and its costs

### Takeaway
No URL-identifier format is an access-control mechanism. Random or obfuscated IDs only reduce *guessing/enumeration* and leak less business metadata; signed URLs add *tamper-evidence + expiry*; only capability URLs deliberately make possession of the URL equal authorization, and the W3C TAG says that should not be the default design for a web application.

### Cited Findings

**Sequential integer IDs**
- OWASP suggests replacing "enumerable numeric identifiers with more complex, random identifiers" (UUIDs or other long random values) as defense in depth, but says "even with complex identifiers, access control checks are essential." — [OWASP IDOR Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)
- Stripe-ecosystem commentary: consecutive numbers "have security issues because a malicious attacker can guess other IDs" — [Gigazine summary of Stripe engineers](https://gigazine.net/gsc_news/en/20240616-design-api-for-human) (secondary source).

**Hashids / Sqids (reversible encoding)**
- Sqids FAQ: alphabet shuffling is "by no means a technique to hide sensitive data"; a determined person could reverse-engineer a shuffled alphabet. — [Sqids FAQ](https://sqids.org/faq)
- On the Hashids salt: "There is no encryption of any kind"; the salt was "never meant to be associated with security or safety"; Hashids "used the salt parameter to shuffle the alphabet", which the authors call confusing because the library "has nothing to do with encryption." — [Sqids FAQ](https://sqids.org/faq)
- "Generated IDs are not hashes and could be decoded back into numbers"; decoded IDs could expose internal figures such as "your app's user count." Sqids lists "any data that's sensitive" and user IDs as poor fits. — [Sqids FAQ](https://sqids.org/faq)
- Sqids is the successor of Hashids (first released ~2013); Sqids output is "not compatible with Hashids." — [Sqids FAQ](https://sqids.org/faq)
- Semgrep rule warns that "by observing sufficient HashIDs, the salt used to construct them can be recovered", and flags using Django's SECRET_KEY as the Hashids salt as insecure; it cites a 2015 "cryptanalysis of hashids" write-up (carnage.github.io, not retrieved). — [Semgrep registry rule](https://registry.semgrep.dev/rule/python.django.security.hashids-with-django-secret.hashids-with-django-secret); same reasoning in [Symbiotic vulnerability entry](https://vulnerabilities.symbioticsec.ai/vulnerabilities/sym_py_0173/)

**UUIDv4 / UUIDv7 / ULID (RFC 9562, 2024; ULID spec)**
- RFC 9562 §8: "Implementations SHOULD NOT assume that UUIDs are hard to guess"; UUIDs "MUST NOT be used as security capabilities (identifiers whose mere possession grants access)"; "Discovery of predictability in a random number source will result in a vulnerability"; if UUIDs are needed for security operations, UUIDv4 "SHOULD be utilized." — [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562.html)
- RFC 9562 §8: "Timestamps embedded in the UUID do pose a very small attack surface." — [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562.html)
- UUIDv4 has 122 random bits; UUIDv7 = 48-bit Unix-ms timestamp in the most significant bits + 74 random bits. — [RFC 9562 §5.4, §5.7](https://www.rfc-editor.org/rfc/rfc9562.html)
- ULID: 48-bit ms timestamp + 80-bit randomness, 26-char Crockford Base32 (excludes I, L, O, U), lexicographically sortable; "Within the same millisecond, sort order is not guaranteed" unless the monotonic mode is used, in which the random component is incremented by one within the same ms; randomness should come from a "Cryptographically secure source of randomness, if possible." — [ULID spec](https://github.com/ulid/spec)
- Laravel 12 `HasUuids` generates UUIDv7 by default ("more efficient for indexed database storage because they can be sorted lexicographically"); `newUniqueId()` can override (e.g., return UUIDv4) and `uniqueIds()` can apply UUIDs to non-PK columns (e.g., `['id', 'discount_code']`); `HasUlids` gives 26-char lexicographically sortable ULIDs. — [Laravel 12 Eloquent docs, "UUID and ULID Keys"](https://laravel.com/docs/12.x/eloquent)

**Random opaque public IDs (Stripe-style prefixed IDs)**
- Stripe's rationale is primarily developer ergonomics: "adding a prefix makes the ID human readable" (`pi_` = PaymentIntent); prefixes enable polymorphic lookup ("we'd have no way of knowing what kind of object the ID represents" otherwise) and make mistakes visible (a `cus_` passed where `acct_` expected). Secret-key prefixes (`sk_live_`) make leaked keys trivially detectable by regex ("writing a regex to filter out accidental leaks is trivial"). — [Paul Asjes (Stripe), "Designing APIs for humans: Object IDs", 2022, edited 2025](https://dev.to/stripe/designing-apis-for-humans-object-ids-3o5a)
- Stripe has not published its internal ID-generation scheme; Patrick Collison reportedly said they are "randomly generated by our Ruby application code." — [Gigazine summary](https://gigazine.net/gsc_news/en/20240616-design-api-for-human) (secondary).
- Clerk describes generating sortable Stripe-like IDs with KSUIDs (prefix + time-sortable random). — [Clerk blog](https://clerk.com/blog/generating-sortable-stripe-like-ids-with-segment-ksuids.md)

**Signed URLs (HMAC + optional expiry; Laravel)**
- Laravel signed URLs append a "signature" hash so Laravel can "verify that the URL has not been modified since it was created"; they are "especially useful for routes that are publicly accessible yet need a layer of protection against URL manipulation" (example: emailed unsubscribe link). `URL::temporarySignedRoute()` adds an expiration timestamp that is checked on validation. — [Laravel 12 URL Generation docs](https://laravel.com/docs/12.x/urls)
- Validation via `$request->hasValidSignature()` or the `signed` middleware (returns 403); `absolute: false` + `signed:relative` excludes the domain; `hasValidSignatureWhileIgnoring([...])` — "ignoring parameters allows anyone to modify those parameters on the request." Expired links raise `InvalidSignatureException`, renderable as a custom "link expired" page. — [Laravel 12 URL Generation docs](https://laravel.com/docs/12.x/urls)

**Encrypted query parameters / encrypted IDs**
- OWASP advises against encrypting identifiers as a primary approach because doing it securely "can be challenging." — [OWASP IDOR Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)

**Capability URLs (W3C TAG)**
- "Capability URLs grant access to a resource to anyone who has the URL." Suitable for: acting without login (one-time password resets), letting shared access spread through a user's network, reducing auth overhead in APIs. — [W3C TAG, Good Practices for Capability URLs](https://www.w3.org/TR/capability-urls/)
- They "should not be the default choice in the design of a web application"; consider account-based access control first; they work poorly for multi-page applications because each page needs its own capability URL. — [W3C TAG](https://www.w3.org/TR/capability-urls/)
- Requirements: unique and not guessable; `https`; "Capability URLs should expire" (single-use or time-limited; expired → 410/404); allow multiple URLs per resource so one can be revoked; avoid third-party links or use `rel="noreferrer"`; disallow the path prefix in `robots.txt` (never list individual URLs); GET must not have side effects; offer a canonical URL; `history.replaceState()` can hide the token from the address bar but breaks bookmarking. — [W3C TAG](https://www.w3.org/TR/capability-urls/)

**SPA fragment routing (`#/...`)**
- The fragment (`#section`) and userinfo are not included in the `Referer` header; origin, path and query may be, depending on referrer policy. — [MDN Referer](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referer)
- RFC 3986 §3.5 defines the fragment as dereferenced by the user agent, i.e., not sent in the HTTP request (not fetched this session; standard knowledge) — [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986#section-3.5)

**Per-page / per-navigation server-side state tokens (e.g., enterprise ERP UIs)**
- No authoritative primary source was retrieved in this session documenting Infor's URL token design (see Gaps). The W3C TAG note that capability URLs work poorly for multi-page apps because every page needs its own token is the closest cited guidance. — [W3C TAG](https://www.w3.org/TR/capability-urls/)

### Inferences
- Comparison matrix (synthesized from the citations above):

| Technique | Mitigates | Does NOT mitigate | Main costs |
|---|---|---|---|
| Sequential int | nothing | enumeration, volume/count leakage | none (simplest, best index locality) |
| Hashids/Sqids | casual enumeration; hides raw counts from non-determined observers | determined decoding, salt recovery from many samples, IDOR | extra middleware/decoding layer; not a secret; must never be treated as auth |
| UUIDv4 | guessing (122 random bits) | IDOR if authZ missing; leakage of the ID itself | random InnoDB inserts, 16–36 bytes per key and per secondary index |
| UUIDv7 / ULID | guessing mostly (74/80 random bits) | reveals creation time (ms); monotonic ULID within same ms is predictable; RFC says don't treat as capability | larger keys than int; ok locality |
| Prefixed opaque IDs | type confusion, debugging, leak detection | IDOR | extra column/lookup, design discipline |
| Signed URL (HMAC + expiry) | tampering with params; link reuse after expiry | leakage of a still-valid URL (it is a bearer token until expiry) | URL length; APP_KEY rotation invalidates links; ignored params are attacker-modifiable |
| Encrypted params | hides values | IDOR, replay; OWASP says hard to do right | key management, length, ciphertext malleability risks if done ad hoc |
| Capability URL | lets unauthenticated holder access exactly one resource | any leak = access; hard to detect/revoke | expiry, revocation, robots, Referer hygiene |
| Fragment routing | keeps the fragment out of server logs/Referer | nothing about authZ; data still fetched via XHR URLs that are logged | requires SPA; no server-rendered deep links without JS |
| Per-navigation state token | can bind a page to server-side session state / CSRF-like flow integrity | IDOR (still needs authZ); creates bearer-like tokens in URLs | breaks bookmarks, back button, sharing, caching; server state storage |

- For a server-rendered Laravel portal with mandatory login, an "every page carries a long random string" scheme would behave like per-navigation state tokens or capability URLs; both are explicitly discouraged as a default by the W3C TAG and add no protection beyond what authorization already provides.

### Gaps
- Infor's actual URL-token mechanism (whether it is session state, CSRF, a ViewState-like handle, or an SPA route) was not researched here; it is presumably covered by a sibling research thread.
- The 2015 "cryptanalysis of hashids" write-up was not retrieved; the salt-recovery claim rests on the Semgrep rule and a vulnerability database entry.

## Q2. Do these techniques prevent IDOR / enumeration? (authorization still mandatory)

### Takeaway
Every authoritative source agrees: unpredictable IDs reduce guessability only; object-level authorization on every request remains mandatory. RFC 9562 explicitly forbids treating UUIDs as security capabilities.

### Cited Findings
- "To mitigate IDOR, implement access control checks for each object that users try to access"; if attackers obtain URLs for unauthorized objects "the application should still block their access attempts"; "Avoid exposing identifiers in URLs and POST bodies if possible"; check permissions on every access using the framework's recommended approach. — [OWASP IDOR Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)
- "In some cases, using more complex identifiers like GUIDs can make it practically impossible for attackers to guess valid values. However, even with complex identifiers, access control checks are essential." — [OWASP IDOR Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)
- UUIDs "MUST NOT be used as security capabilities" and implementations "SHOULD NOT assume that UUIDs are hard to guess." — [RFC 9562 §8](https://www.rfc-editor.org/rfc/rfc9562.html)
- Hashids/Sqids are decodable encodings, not encryption. — [Sqids FAQ](https://sqids.org/faq)

### Inferences
- Ranking by enumeration resistance: sequential int < Hashids/Sqids (obfuscation; decodable by a determined party) < UUIDv7/ULID (≈74–80 random bits, time prefix known) < UUIDv4 / 128-bit+ CSPRNG opaque IDs. None of these changes the outcome when authorization is enforced correctly; they only matter as defense in depth when an authorization bug exists.
- For this portal, ownership checks (`supplier_id = auth()->id()`, policies) are the real control; hashids remain defense in depth, and a longer random token on every page would not change IDOR exposure.

### Gaps
- No quantitative data found on how often random IDs have actually prevented exploitation of a real authorization bug.

## Q3. Information leakage (Referer, logs, history, analytics, screenshots, shared links) and usability (bookmarks, back button, sharing, caching)

### Takeaway
Anything placed in the path or query string leaks through server/proxy logs, browser history/cache, Referer (subject to policy), shoulder surfing and shared links, even over HTTPS. Long random tokens therefore become a liability the moment they carry authority. Per-navigation tokens also break bookmarks, the back button, link sharing and caching.

### Cited Findings
- OWASP: query-string data is exposed via the Referer header, web logs, shared systems, browser history, browser cache and shoulder surfing — regardless of HTTP/HTTPS; server logs capture it even over TLS. Treat OTPs "as a secret credential equivalent to a password"; HTTPS alone does not solve this. — [OWASP Community: Information exposure through query strings in URL](https://community.owasp.org/vulnerabilities/Information_exposure_through_query_strings_in_url)
- W3C TAG: capability URLs leak via Referer to linked sites, address bar, browser history, synced history, server/app logs, third-party scripts on the page, email providers, ISPs, URL shorteners and search indexing; "the risk of exposure of capability URLs is very high, particularly when they are accessed through a browser"; compromise is hard to detect and revocation affects every holder. — [W3C TAG](https://www.w3.org/TR/capability-urls/)
- `strict-origin-when-cross-origin` is the browser default since the Nov 2020 spec revision (previously `no-referrer-when-downgrade`): full URL for same-origin requests, origin only for cross-origin HTTPS→HTTPS, nothing for HTTPS→HTTP; `same-origin` sends no Referer cross-origin; `no-referrer` sends none. — [MDN Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)
- Referer never contains the fragment or userinfo. — [MDN Referer](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referer)
- Hiding a token with `replaceState()` prevents bookmarking; capability URLs should redirect to a canonical URL. — [W3C TAG](https://www.w3.org/TR/capability-urls/)
- Laravel's `hasValidSignatureWhileIgnoring` exists precisely because frontends need to append parameters (e.g., pagination) to signed URLs; ignored params become attacker-modifiable. — [Laravel 12 URL docs](https://laravel.com/docs/12.x/urls)

### Inferences
- With the default/current `strict-origin-when-cross-origin`, a token in this portal's path/query does not leak to third-party origins via Referer, but it still lands in same-origin Referer headers, access logs, browser history, screenshots and copied links.
- Stable opaque IDs (hashid/UUID) keep bookmarks, back button, deep links, and HTTP caching working. Per-navigation or expiring tokens break them: a bookmarked or emailed link stops working, back/forward may hit expired state, and every URL becomes uncacheable/unique. Signed temporary URLs break only after expiry, which is acceptable for one-off emailed/shared links but not for ordinary navigation.
- Fragment routing reduces leakage of the fragment only; an SPA still sends IDs in API request URLs (logged server-side), and a server-rendered Blade app would need a rewrite to use it.

### Gaps
- No measured data found on analytics/RUM tools capturing full URLs; the W3C TAG mention of third-party scripts is the cited basis.

## Q4. Database / index performance (UUIDv4 vs UUIDv7/ULID in MySQL InnoDB)

### Takeaway
Random UUIDv4 primary keys hurt InnoDB (clustered index) through page splits, low page fill and bloated secondary indexes; time-ordered UUIDv7/ULID largely restore insert locality. A public opaque ID can live in a separate indexed column while the integer PK stays internal.

### Cited Findings
- RFC 9562: non-time-ordered UUIDs have "poor database-index locality"; time-ordered UUIDs "benefit from greater database-index locality." — [RFC 9562 §2.1, §6.11](https://www.rfc-editor.org/rfc/rfc9562.html)
- PlanetScale (Brian Morrison II, 2024-03-19): UUID = 128 bits binary (4× a 32-bit int) or 288 bits as CHAR(36) (9×); BINARY(16) is better but still larger; InnoDB fills pages ~94% with sequential keys, random keys can drop utilization to ~50%; secondary indexes store the PK, so large PKs inflate every secondary index; v6/v7 time-ordered UUIDs keep values "as close to sequential as possible"; MySQL's native `UUID()` is v1 and `UUID_TO_BIN(..., swap)` reorders it. The article contains no benchmark numbers (qualitative). — [PlanetScale blog](https://planetscale.com/blog/the-problem-with-using-a-uuid-primary-key-in-mysql)
- PostgreSQL (not InnoDB) benchmark: UUIDv4 PK index consumed a large share of buffer cache and slowed the whole workload; v7 came close to bigint. — [Ardent Performance, 2024](https://ardentperf.com/2024/02/03/uuid-benchmark-war/)
- A Sept 2026 blog reports 1.6–2.3× faster inserts and 11.7–15.8× faster time-range scans for v7 vs v4 over 200,000 rows, and says the oft-repeated ~25% smaller index claim did not reproduce; engine not confirmed from snippet. — [DevToolLab](https://devtoollab.com/blog/uuidv7-vs-uuidv4) (low-confidence, not fetched in full)
- Laravel `uniqueIds()` lets a UUID be assigned to a non-PK column. — [Laravel 12 Eloquent docs](https://laravel.com/docs/12.x/eloquent)

### Inferences
- For an existing MySQL schema with integer PKs and many FKs, the cheapest path to opaque public IDs is either (a) the current reversible encoding of the int (zero schema change), or (b) an additional `public_id` (UUIDv7/ULID BINARY(16) or CHAR(26), unique index) used only for routing. Converting PKs/FKs to UUIDs is a large migration with no added authorization value.

### Gaps
- No rigorous, InnoDB-specific published benchmark (sysbench etc.) comparing v4 vs v7 was found in this session; performance magnitudes above are engine-mismatched or low-confidence.

## Q5. When is a long random URL token genuinely beneficial vs cosmetic / security-through-obscurity?

### Takeaway
Long random tokens are genuinely valuable when the URL itself must carry authority or bind a flow to a session for someone who is not (yet) authenticated — password reset, email verification, unsubscribe, one-time downloads, public receipt verification, OAuth `state`. In an authenticated, server-rendered portal with per-object authorization, adding them to every page is cosmetic and imposes usability and leakage costs.

### Cited Findings
- Legitimate capability-URL uses: one-time password resets/acting without login, sharing within a user's network, reducing auth overhead in APIs; must be unguessable, HTTPS, expiring, revocable. — [W3C TAG](https://www.w3.org/TR/capability-urls/)
- Laravel signed/temporary signed routes target "publicly accessible" routes needing protection "against URL manipulation" (emailed unsubscribe link example). — [Laravel 12 URL docs](https://laravel.com/docs/12.x/urls)
- Capability URLs "should not be the default choice in the design of a web application." — [W3C TAG](https://www.w3.org/TR/capability-urls/)
- If UUIDs must serve a security purpose, use v4; never as capabilities alone. — [RFC 9562 §8](https://www.rfc-editor.org/rfc/rfc9562.html)
- OAuth 2.0 uses a non-guessable `state` value bound to the user-agent's session to prevent CSRF on the redirect (RFC 6749 §10.12; not fetched this session) — [RFC 6749 §10.12](https://www.rfc-editor.org/rfc/rfc6749#section-10.12)

### Inferences
- Beneficial (keep/adopt): one-time links (password reset, registration status access key, email verification), QR/receipt verification (this repo already uses signed QR verification), time-limited file downloads, OAuth/SSO `state`/`nonce`.
- Cosmetic for an authenticated portal: long random strings on list/detail pages behind login + role + policy. They do not stop an authorized-but-curious user (authorization does), do not stop a stolen session (session controls do), and add bookmark/back/share breakage and log/history exposure of token-like strings that look like credentials to auditors.
- If the motivation is "look like Infor / look enterprise", that is a perception goal, not a security control; it should be justified as UX/branding and weighed against the usability costs above.

### Gaps
- No source found quantifying user perception that long random URLs signal "more secure" software.

## Q6. What OWASP ASVS 5.0 and OWASP cheat sheets say about sensitive data in URLs and unpredictable IDs

### Takeaway
ASVS 5.0 V14.2.1 (Level 1) requires that sensitive data is sent only in the HTTP body or headers, never in the URL; OWASP treats random IDs as defense in depth, never a substitute for object-level access control.

### Cited Findings
- ASVS 5.0 **14.2.1 (L1)**: "Verify that sensitive data is only sent to the server in the HTTP message body or header fields" (i.e., not in URL/query string). — [OWASP ASVS 5.0.0 V14 Data Protection](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x23-V14-Data-Protection.md)
- ASVS 5.0 **14.2.2 (L2)**: prevent sensitive data from being cached in server components (load balancers, app caches) or purge after use; **14.2.5 (L3)**: caches only store expected content types; **14.3.1 (L1)**: clear authenticated data from client storage/DOM at session end; **14.3.2 (L2)**: sufficient anti-caching headers (e.g., `no-store`); **14.3.3 (L2)**: no sensitive data in localStorage/sessionStorage/IndexedDB/cookies except session tokens. — [OWASP ASVS 5.0.0 V14](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x23-V14-Data-Protection.md)
- The fetched V14 chapter contains no Referrer-Policy requirement. — [OWASP ASVS 5.0.0 V14](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x23-V14-Data-Protection.md)
- OWASP IDOR Cheat Sheet: per-object access checks; avoid exposing IDs where possible; random IDs as defense in depth; avoid encrypting IDs. — [OWASP IDOR Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)
- OWASP Community: query-string exposure through Referer, logs, history, cache, shoulder surfing; HTTPS does not fix it. — [OWASP Community page](https://community.owasp.org/vulnerabilities/Information_exposure_through_query_strings_in_url)

### Inferences
- A per-page random token that functions as an authorization or session handle would itself be "sensitive data in the URL" and arguably conflict with ASVS 14.2.1. A non-authoritative opaque object ID (hashid/UUID) is not sensitive in that sense, provided authorization is enforced.

### Gaps
- ASVS 5.0 V8 (Authorization) wording on object-level authorization was not fetched in this session; only V14 was verified.

## Q7. UX / phishing perspective: long unreadable URLs and user trust

### Takeaway
Users are poor URL readers and mostly key on recognizable brand names; long opaque strings add noise that makes URLs harder to read and check, without improving trust calibration.

### Cited Findings
- Albakry, Vaniea & Wolters, "What is this URL's Destination? Empirical Evaluation of Users' URL Reading" (CHI 2020, DOI 10.1145/3313831.3376168): survey of 1,929 participants (MTurk + Prolific), each shown 23 of 46 crafted URLs; participants were strongly biased toward answering that the URL leads to the organization whose name appears in it "regardless of its position in the URL structure"; the highest-tech-use group was only minorly better; users find it hard to predict a URL's real destination. — [Edinburgh Research Explorer PDF](https://www.pure.ed.ac.uk/ws/files/137060891/What_is_this_URL_s_ALBAKRY_DOA09122019_AFV.pdf) (findings via search summary; PDF not fully read); [dataset](https://datashare.ed.ac.uk/handle/10283/3548)
- A KIT publication studies the influence of URL formatting on users' phishing URL detection. — [KIT: Influence of URL Formatting on Users' Phishing URL Detection](https://publikationen.bibliothek.kit.edu/1000163039/151499531) (not read)
- W3C TAG recommends telling users what a capability URL allows and how to share it safely. — [W3C TAG](https://www.w3.org/TR/capability-urls/)

### Inferences
- Short, readable, predictable paths (`/purchasing/requisitions/{short-id}`) help users and support staff verify where they are and share links; long random strings resemble phishing/tracking links and hinder that check. Stripe's prefixed IDs show a middle ground: opaque but self-describing (type prefix) for humans.

### Gaps
- Exact per-condition accuracy numbers and any URL-length effect from Albakry et al. were not retrieved (ACM and CORE returned 403). The KIT study's results were not read.
