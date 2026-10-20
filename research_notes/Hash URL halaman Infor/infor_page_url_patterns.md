# Long opaque strings in Infor OS Portal / Infor Ming.le page URLs (CloudSuite multi-tenant, 2026)

Scope note: public documentation, community posts and one public urlscan.io record only. No Infor tenant was accessed. "Documented" = stated by an Infor doc page or a quoted real URL; "Inference" = my reasoning, not confirmed by a source. Infor renamed "Infor Ming.le" to "Infor OS Portal"; docs use both names, and older (12.x / on-prem) doc pages still say Ming.le. Many docs.infor.com version-specific URLs now redirect to `/latest/`; the pages cited below were read via their `latest` or listed version path on 2026-10-20.

## 1. What a Ming.le v2 / Infor OS Portal URL looks like (homepage, application, homepage/widget, favorite/bookmark) and whether there are fragment (#) routes

### Takeaway
Publicly documented Infor OS Portal links use a **query string**, not a fragment: `https://mingle-portal.<region>.inforcloudsuite.com/v2/<TENANT>_PRD/` + optional path segment + `?favoriteContext=<...>&LogicalId=lid://<...>` (or `?LogicalId=...&<app params>`). No public Infor source documents a `#` fragment route for the portal shell. The one real captured portal-style URL places a **UUID in the path** (after the tenant) and a **URL-encoded JSON object** in `favoriteContext`.

### Cited Findings
- Base portal URL form: Infor says users must always access the portal through the Infor OS Portal URL, "similar to this example: https://mingleportal.mingle.infor.com/<company name>-prd"; going through this URL ensures the portal libraries load, otherwise "Shortcuts and Context Sharing" do not work properly. — [Infor OS Portal User Guide, "Accessing Infor OS Portal"](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/inforospug/lsm1436532946807.html)
- CloudSuite multi-tenant form: `https://mingle-portal.inforcloudsuite.com/<tenant>_<type>/` (type = PRD/TRN/TST, etc.). — [Infor CloudSuite HCM Configuration Guide 11.0.x (PDF)](https://support.infor.com/esknowbase/root/DLPublic/56401/csstlwsn_11.0.x_cshcmcg__en-us.pdf) (via search result summary); regional variants such as `eu1`, `se1` exist — [Microsoft Entra ID tutorial: Infor CloudSuite SSO](https://learn.microsoft.com/en-us/entra/identity/saas-apps/infor-cloud-suite-tutorial) (via search result summary)
- A real `/v2/` link shared in a CloudSuite user community post: `https://mingle-portal.us2.prd3.inforcloudsuite.com/v2/CONCIERGE_PRD/?LogicalId=lid://infor.cxp.1&Tab=ERS&ERId=126648` — i.e. `/v2/<TENANT>_PRD/` followed by a **query string** that names the target application by Logical ID plus app-specific parameters. — [InforUsers CloudSuite Community (WorkOutLoud) message 1496690](https://lgug.workoutloud.com/message/1496690) (seen only in the search-engine snippet; the page itself returned HTTP 403 to my fetch, so not independently verified)
- A real captured Infor portal URL (Infor Concierge on the older `icp.cloud.infor.com` host, scanned 2022-02-08): `https://icp.cloud.infor.com/infor/0c859ff0-1676-4dd6-8712-2641ed1ee870?favoriteContext={%22type%22:%22page%22...` — structure: `/<tenant>/<UUID>` in the **path**, then `?favoriteContext=` with a **URL-encoded JSON object** whose first key is `"type":"page"`. No `#` fragment appears anywhere in the URL or its redirect chain. — [urlscan.io result f8b4322d…](https://urlscan.io/result/f8b4322d-ee5b-41e2-9fbf-57f246a0986b)
- Infor's own instruction for linking to applications: "Links to an application should be done through the format of favorite context (?favoriteContext=) or from a link with logicalId (?logicalId=lid://infor.ln)." — [Infor OS / Ming.le Homepages administration, "Types of variables"](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- The Logical ID "can later be found in the application URL in the browser. The LID is the end part of that URL." Examples: Homepages `lid://infor.homepages.1`, M3 `lid://infor.m3.m3`. — [same "Types of variables" page](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- Portable link template example for the Menu widget: `{lid://infor.m3}?favoriteContext=CRS610&LogicalId={LogicalId}` resolves at runtime to `?favoriteContext=CRS610&LogicalId=lid://infor.m3.m3`. — [same page](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- Bookmarks: the top navigation has a Bookmark icon with "Bookmark This Page", "Manage Bookmarks" and a list of bookmarks; a Share icon "is available on pages that can be shared". — [Infor OS Portal top navigation panel](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/idmug_cloud_osm/qup1504255984842.html)
- Bookmarks are represented in the browser URL: "The bookmark directs you to a specific dashboard, including its parameters. If a bookmark is created, you can copy the link from the URL and use it as a direct link to a specific dashboard." — [Infor d/EPM, "Bookmarking dashboards in Infor Ming.le"](https://docs.infor.com/depm/latest/en-us/depmolh/dashboards_on-premise/rwj1574766647503.html)
- Homepages / workspaces: role-based pages come from a Page Catalog; users can create private homepages. (No URL format given.) — [CloudSuite Distribution docs (search summary)](https://docs.infor.com/csdis/2026.x/en-us/useradminlib_csd_cloud/sxug/big1529420659106.html)

### Inferences
- Most likely shape of a CloudSuite portal page URL after login (combining the `/v2/<TENANT>_PRD/` community link with the urlscan path+query shape): `https://mingle-portal.<region>.inforcloudsuite.com/v2/<TENANT>_PRD/<UUID>?favoriteContext=<URL-encoded value>&LogicalId=lid://infor.<product>.<instance>`. The `<UUID>` path segment is the most probable "long random hash on every page": it is an 8-4-4-4-12 hex GUID that most plausibly identifies the portal application/page entry (not a session). This specific v2 path+UUID combination is **not documented** publicly; treat it as inference.
- Because Infor tells admins to copy bookmark links "from the URL", and favorites/portable links are built as `?favoriteContext=...&LogicalId=...`, the favorite/bookmark state is carried in the **query string**, which explains why the URL changes as the user navigates inside an app (the portal rewrites the address bar to reflect the current favorite context so it can be bookmarked or shared).
- For the homepage, the URL is likely the shorter `/v2/<TENANT>_PRD/` form, or a page identified by a GUID and/or `favoriteContext={"type":"page",...}` JSON (the urlscan example begins exactly that way, but the rest of that JSON — e.g. a page id — was truncated in the capture).

### Gaps
- No public Infor document shows a complete Infor OS Portal v2 URL for LN, M3, CSD, IDM or Birst after navigation, or defines what the UUID path segment is. Confirm in the user's own tenant.
- No public source confirms or rules out `#` fragment (SPA hash) routes in the v2 shell. None of the quoted URLs contain one.
- The full JSON schema of `favoriteContext` for Homepages pages (`{"type":"page", ...}`) is not publicly documented; only its first key is visible in the urlscan record.

## 2. Documented parameters (`favoriteContext`, `LogicalId`, `inforCurrentLocale`, drillback, Share/Copy link) and how each is encoded

### Takeaway
`LogicalId` is a readable `lid://provider.product.instance` string (URL-encoded in practice). `favoriteContext` is an application-specific value: a plain program name (M3 `CRS610`), an `mp://` app link with its own nested parameters, or URL-encoded JSON (`{"type":"page",...}`). Drillbacks are long query strings with percent-encoded key lists. Standard launcher parameters (`inforCurrentLocale`, `inforThemeName`, `TenantId`, ...) are appended to embedded app URLs. Nothing documented is a signed/encrypted token; JSON appears **URL-encoded, not base64** in the one observed example.

### Cited Findings
- Logical ID structure: `lid://[provider].[productcode].[applicationinstanceid]:[Connection]/[path]`; connection/path are optional; only lowercase letters, digits, dots, underscores, hyphens allowed. — [Infor ION docs "Logical Id" (search summary)](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/ioncedg_cloud_osm/zaz1652797458967.html)
- LID has a two-part prefix plus instanceId (e.g. `lid://infor.m3.m3`); multiple installations share the prefix and differ by instanceId. — [Types of variables](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- LN LIDs have the format `lid://infor.ln.<environment>`, environment "free-to-choose"; LN UI can be tested with `https://<server>:8443/webui/servlet/fslogin?LogicalId=<Logical ID>`. — [Infor LN UI Administration Guide 2022.08 (PDF), p. ~13](https://docs.infor.com/ln/10.7/en-us/useradminlist/lnui_2022.08_lnuiag__en-us_60667.pdf)
- The LID "is crucial for Infor OS Portal-internal management, but also for functions such as drillback and sharing of page links." — [Infor d/EPM "Adding custom applications to Infor OS Portal"](https://docs.infor.com/depm/latest/en-us/depmolh_cloud/administration_cloud/zkw1678890890603.html)
- Standard parameters Ming.le adds "to all Infor applications, or non-infor applications, that are opened through the Application Launcher": `LogicalId`, `TenantId`, `inforThemeName`, `inforCurrentLocale`, `inforCurrentLanguage`, `inforTimeZone` (example values `lid://infor.m3aeqp-birst.m3aeqpbirst`, `Infor`, `HighContrast`, `en-US`, `en-US`, `UTC+01:00`). — [Types of variables](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- Mongoose/Factory Track confirms `InforCurrentLocale` and `InforCurrentLanguage` are "supplied by Infor Ming.le as a URL parameter"; other Ming.le-related params include `forceSSO`, `preventNav`, `tenant`. — [Factory Track (LN) "URL Parameters for Specialized Use"](https://docs.infor.com/factorytrack/6.01.10/en-us/ftlnolh/lsh1489182792399.html)
- M3 Experience Designer link forms (three types): `mp://ShortName?title=Title&Component.SearchField=<Variable>`; `?favoriteContext=mp://ShortName&title=Title&Component.SearchField=<Variable>&LogicalId=lid://infor.m3.m3`; `?LogicalId=lid://infor.m3.m3&program=mp://ShortName&title=...`. Example: `?favoriteContext=mp://ITEMOV&title=Item Overview&IndexList_Items.ITNO=<ITNO>&IndexList_Items.F_STAT=<STAT>&IndexList_Items.T_STAT=<STAT>&LogicalId=lid://infor.m3.m3`. Workspace widgets can only use the `?favoriteContext` and `?LogicalId` forms. — [M3 "Start the application using a link, bring start values" (2024.x archive)](https://archive.docs.infor.com/m3core/2024.x/en-us/useradminlib_cloud/m3coretechug_cloud/kwy1727690044364.html)
- Real M3 drillback URL from the Context Viewer: `?LogicalId=lid://infor.m3.m3&program=CRS610&tableName=OCUSMA&keys=OKCONO%2C770%2COKCUNO%2CDMTEST%2B%2B%2B%2B&option=2&name=CRS610%2FE&description=CRS610%20Customer.%20Open&panel=E`. Advice in the thread: "You can find the drillback URL from the context viewer widget". — [Infor Community "Drillback URL" #28526](https://community.infor.com/discussion/28526/drillback-url)
  - Decoded locally: `keys=OKCONO,770,OKCUNO,DMTEST++++` → a comma-separated list of key field/value pairs (company 770, customer DMTEST padded with `+`). This is plain percent-encoding, not a hash. (Decoding done by me with Python `urllib.parse.unquote`.)
- ION/OS drillback model: required parameters Logical ID, View ID, Accounting Entity, ID1 (further IDs optional); example values `lid://erpln.mycompany.1`, `SalesOrderView`, `011`, `SO-1001`, optional ID2 `SOL-001`. — [Infor d/EPM "Drillback from a dashboard to an application in Infor OS Portal" 2025.x](https://docs.infor.com/depm/2025.x/en-us/depmolh_cloud/applications_in_infor_os_portal/mey1634933090160.html)
- OS Workflow drillback for M3 is defined with LogicalId, Accounting Entity, Program, Table, KeyNames (comma separated), KeyFields, Option, Panel, and a combined AccountingEntity `CONO_DIVI`. — [Infor Community #29381](https://community.infor.com/discussion/29381/how-do-we-create-a-custom-drillback-and-use-it-in-workflows)
- LN generates drillback URLs in extensions via `icm.getDrillbackURL("tdsls100")`, shown in the Context Viewer as "DrillbackURL". — [Infor Community #24960](https://community.infor.com/discussion/24960/how-to-get-the-drillbackurl-in-infor-ln-ce)
- Share: posts created via Infor OS Share "include drillback links that the recipients can use to view the information" plus hashtag links. — [CloudSuite Distribution SX.e "Infor OS Share"](https://docs.infor.com/csdis/latest/en-us/useradminlib_csd_cloud/sxug/znb1503926009732.html)
- `favoriteContext` as URL-encoded JSON: `favoriteContext={%22type%22:%22page%22` (= `{"type":"page"` after decoding). In the login redirect the same value appears **double/triple percent-encoded** inside ADFS `wctx` → `ru=` (`%252finfor%252f...%253ffavoriteContext%253d%257b%252522type%252522...`). — [urlscan.io record](https://urlscan.io/result/f8b4322d-ee5b-41e2-9fbf-57f246a0986b) (decoding of the nested value done by me: three rounds of `unquote` give `/infor/0c859ff0-1676-4dd6-8712-2641ed1ee870?favoriteContext={"type":"page"`)
- In-Context URL Viewer displays a URL "sent in context as a context message" — context passing between apps happens as messages, not only via URLs. — [Infor OS "Using the In-Context URL Viewer"](https://docs.infor.com/inforos/2023.x/en-us/useradminlib_onpremises/minug/lsm1436532952838.html)

### Inferences
- If the long string in the user's address bar starts with `%7B%22` (or `{"` after decoding) it is URL-encoded JSON favorite context; if it starts with `lid%3A%2F%2F` it is a Logical ID; if it contains `program=`, `tableName=`, `keys=`, `panel=` it is an M3 drillback; if it contains `ViewId`/`AccountingEntity`/`ID1` it is an ION drillback.
- I found no documentation that `favoriteContext`, `LogicalId` or drillback values are base64-encoded, encrypted, or signed. They are readable once percent-decoded. A string that cannot be decoded into readable text is more likely a GUID (path segment / page id) or something produced by the embedded application itself.

### Gaps
- No public doc gives the LN or CSD `favoriteContext` value format inside the cloud portal (e.g. whether LN uses a `bookmark?...` string or JSON).
- No public doc on a portal-level "Copy link" button; only the Bookmark and Share icons are documented.

## 3. URLs of embedded applications inside the portal iframe (LN UI, M3 H5, IDM, Mongoose/CSI) — session IDs, bookmark IDs, drillback strings, per-request tokens

### Takeaway
Embedded app URLs are separate from the portal address bar and carry their own parameters: LN UI `.../webui/servlet/fslogin?LogicalId=...&startupform=...&startupwhere=...`, M3 drillback-style parameters, Mongoose `form=`/`page=portal` plus Ming.le-supplied locale/tenant parameters. Documented parameters are readable identifiers, not session tokens. Session-like tokens, if any, would be in the iframe `src` or in cookies, which the user only sees through DevTools.

### Cited Findings
- LN UI extended URL parameters: `startupArgument` (environment), `profileName`, `startupform` (session code), `startupcompany`, `startupindex`, `startupwhere` (record query, "must be properly percent-encoded according to RFC3986"), `startupSession`; example `https://server1.initrode.com:8443/webui/servlet/fslogin?startupArgument=lnprod&startupform=ttaad2500m000&startupwhere=(ttaad200.user=%27jdoe%27)`. Starting from an extended URL creates "a new instance of LN UI ... and a new bshell is started on the LN server". — [LN UI Administration Guide 2022.08 (PDF), p. 33](https://docs.infor.com/ln/10.7/en-us/useradminlist/lnui_2022.08_lnuiag__en-us_60667.pdf)
- LN UI Enterprise Modeler links: `...fslogin?startupArgument=lnprod&demPath=EUPU10;DCO007`. — [same PDF, p. 34](https://docs.infor.com/ln/10.7/en-us/useradminlist/lnui_2022.08_lnuiag__en-us_60667.pdf)
- LN UI ODM servlet template used when registering with the portal: `{Hostname}:{Port}/{Context}/servlet/odm?LogicalId={LogicalId}&Tenant={Tenant}`. — [same PDF](https://docs.infor.com/ln/10.7/en-us/useradminlist/lnui_2022.08_lnuiag__en-us_60667.pdf)
- M3 apps run on a separate M3 host (e.g. `https://m3prduse1.m3.inforcloudsuite.com/mne/apps/iorders`), not on `mingle-portal...`; reply in thread: inspect the browser Network tab to find the M3 host, and the app "would require an xfo parameter on the URL that is set to the value of the Mingle URL". — [Infor Community "url for utility apps" #19135](https://community.infor.com/discussion/19135/url-for-utility-apps) (the `xfo` statement is a community member's belief, "I also think", not documented)
- Mongoose-based apps (Factory Track / CSI): `form=FormName(launchParams)`, `page=portal` ("the app will be opened and hosted within an iFrame", redirects to `PortalPage.aspx`), `tenant=`, `forceSSO=`, `preventNav=`. — [Factory Track "URL Parameters for General Use"](https://docs.infor.com/factorytrack/6.01.10/en-us/ftm3olh/zjc1489182399481.html); [Specialized Use](https://docs.infor.com/factorytrack/6.01.10/en-us/ftlnolh/lsh1489182792399.html)
- IDM drillback URLs returned by the ION API `GET /items/search/item` exist; a user reported they did not open the expected document in Cloud (unresolved in the public thread). — [Infor Community #22125](https://community.infor.com/discussion/22125/idm-drillback-issue-cloud)

### Inferences
- The long string a user sees in the address bar is far more likely a portal-level item (UUID path segment or encoded `favoriteContext`) than an LN/M3 iframe parameter, because iframe URLs do not appear in the address bar.
- LN `startupwhere` and M3 `keys=` values are percent-encoded record keys — they look "random" when encoded but decode to readable field=value text.

### Gaps
- No public documentation of session-ID or anti-forgery parameters in LN UI, M3 H5, or IDM iframe URLs in the cloud. Cannot confirm either way without a tenant.

## 4. Session token / per-page anti-forgery token, or stable IDs?

### Takeaway
Everything documented for the portal's page URLs is a **stable, bookmarkable identifier** (Logical ID, favorite context, drillback keys, page/app GUID). Infor explicitly tells users to copy these URLs as bookmarks/drillbacks, which would not work if they contained per-session tokens. Per-request/one-time values appear only in the **login/SSO redirect URLs** (SAML/ADFS), which are transient.

### Cited Findings
- Bookmarks can be copied "from the URL and use[d] ... as a direct link". — [d/EPM bookmarking dashboards](https://docs.infor.com/depm/latest/en-us/depmolh/dashboards_on-premise/rwj1574766647503.html)
- Drillback URLs are copied from the Context Viewer and parameterised with placeholders (e.g. `{id1}`) for reuse in widgets. — [Infor Community #28526](https://community.infor.com/discussion/28526/drillback-url); [M3 Drillback Actions widget doc (search summary; page now 404)](https://docs.infor.com/m3csfsh/2026.x/en-us/csfshlib/industry_content_fashion/uxn1705996589635.html)
- Login redirect chain for an unauthenticated portal link contains transient values: ADFS `wa=wsignin1.0`, `wtrealm=urn:Infor_icp_...:portal`, `wctx` (carries the original return URL `ru=` multiply encoded), `wct=<timestamp>`, `client-request-id=<GUID>`, then `https://inforsso.infor.com/idp/SSO.saml2?SAMLRequest=<long base64 string>&RelayState=<GUID>`. — [urlscan.io record](https://urlscan.io/result/f8b4322d-ee5b-41e2-9fbf-57f246a0986b)
- Mongoose's `passcode=` parameter puts a password in plain text in the URL and Infor "strongly discourage[s] its use" — showing that credentials in URLs are an exception, not the portal norm. — [Factory Track General Use](https://docs.infor.com/factorytrack/6.01.10/en-us/ftm3olh/zjc1489182399481.html)

### Inferences
- `SAMLRequest` values are base64 (normally DEFLATE-compressed per the SAML HTTP-Redirect binding) and change on every login; `RelayState` and `client-request-id` are fresh GUIDs per login attempt. If the user only sees the long string during sign-in, it is one of these, not a page ID.
- A GUID in the portal path that is identical every time the same app/page is opened is a stable ID; a value that changes on each visit/refresh is session/request state.

### Gaps
- Not verified whether the v2 portal adds any per-session value to the address bar after login (none observed in public sources).

## 5. Why the URLs are long

### Takeaway
Length comes from: (a) multi-tenant prefix (`/v2/<TENANT>_PRD/`), (b) a GUID path segment, (c) the `favoriteContext` carrying the full app navigation state (program, title, component search fields, or JSON), (d) the `LogicalId` naming the target app instance, (e) percent-encoding (and nested re-encoding when a URL is wrapped in another URL), and (f) cross-app drillback keys.

### Cited Findings
- Portal hosts multiple applications launched through an Application Launcher that appends standard parameters (tenant, theme, locale, language, time zone, LID). — [Types of variables](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- Links must go through the portal URL for Shortcuts and Context Sharing to work. — [Accessing Infor OS Portal](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/inforospug/lsm1436532946807.html)
- Portable links let favorites/menus move between on-prem and cloud because the LID is resolved at runtime. — [Types of variables](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/minceag_cloud_osm/kce1636121937696.html)
- Drillbacks require LID + View ID + Accounting Entity + ID1 (+ optional IDs). — [d/EPM drillback 2025.x](https://docs.infor.com/depm/2025.x/en-us/depmolh_cloud/applications_in_infor_os_portal/mey1634933090160.html)
- Nested encoding example (`%252522` = `"` encoded three times) in the SSO return URL. — [urlscan.io record](https://urlscan.io/result/f8b4322d-ee5b-41e2-9fbf-57f246a0986b)

### Inferences
- The design goal is that the address bar always reflects "which app, which instance, which screen/record" so that the page can be bookmarked, shared and drilled back to; that state is carried as data in the URL rather than as a short server-side link ID.

### Gaps
- None beyond those listed in section 1.

## 6. Practical identification guide (fragment vs path vs query; base64/JSON vs UUID vs opaque ID vs hash)

### Takeaway
Split the URL at `?` and `#`, percent-decode each piece, then classify by shape. Check whether the value is stable across reloads and logins.

### Cited Findings
- UUID text format is 32 hex digits in 8-4-4-4-12 groups (e.g. `0c859ff0-1676-4dd6-8712-2641ed1ee870`); the version nibble is the first hex digit of the third group (`4` = random). — [RFC 9562 (UUIDs)](https://www.rfc-editor.org/rfc/rfc9562)
- JWTs are three base64url segments separated by dots; the header is JSON, so it usually starts `eyJ`. — [RFC 7519 (JWT)](https://www.rfc-editor.org/rfc/rfc7519)
- Base64 of a string starting `{"` followed by a letter begins with `eyJ` (e.g. `{"type":"page"}` → `eyJ0eXBlIjoicGFnZSJ9`); URL-encoded JSON begins `%7B%22` (`{"type":"page"}` → `%7B%22type%22%3A%22page%22%7D`). — verified by local computation with Python (no external source)
- Infor community advice to use the browser Network tab to see the real embedded-app host/URLs. — [Infor Community #19135](https://community.infor.com/discussion/19135/url-for-utility-apps)

### Inferences (practical checklist)
1. **Where is it?** In the browser console run `location.pathname`, `location.search`, `location.hash`. Non-empty `location.hash` → fragment (client-side route). A GUID in `location.pathname` after `/v2/<TENANT>_PRD/` → portal path ID. Long text in `location.search` → `favoriteContext`/`LogicalId`/drillback.
2. **Decode it:** `decodeURIComponent(location.search)` (repeat until it stops changing). Readable `lid://...`, `program=`, `{"type":...}` → documented, non-secret navigation data.
3. **Classify the remaining string:**
   - `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (hex) → UUID/GUID: an identifier (app/page/request id).
   - Starts `eyJ`, `[A-Za-z0-9_-]` only, possibly dots → base64(url) JSON or a JWT; decode with `atob()` (replace `-`/`_` first). A three-part dotted value would be a token — do not share it.
   - 32/40/64 hex chars without dashes → probably a hash or opaque id (MD5/SHA-1/SHA-256 length).
   - Long base64 with `+`/`/`/`%2B`/`%2F` inside `SAMLRequest=` → SSO request, transient.
4. **Stable or per-session?** Open the same page twice, in a new tab, and after re-login. Same value → stable ID (safe to bookmark). Different each time → session/request state.
5. **Embedded app URL:** DevTools → Elements, find the `<iframe>` and read its `src`; or Network tab filtered by the app host (e.g. `*.m3.inforcloudsuite.com`, LN `webui/servlet`). That is where app-specific parameters (`startupform`, `program`, `keys`, `inforCurrentLocale`) live.
6. **Sharing caution:** query strings are sent to the server and appear in logs/referrers; fragments are not sent to the server. Before pasting a URL into tickets/chat, decode it and confirm it has no `SAMLRequest`, `code=`, `token`, `access_token` or JWT-shaped value.

### Gaps
- Whether the Infor OS Portal v2 shell itself uses fragment routing could not be determined from public sources; step 1 above is the definitive check in the user's browser.
- Older Ming.le 12.x vs current Infor OS Portal v2 URL differences: public docs only show the older `mingleportal.mingle.infor.com/<company>-prd` example and the current `mingle-portal.<region>.inforcloudsuite.com/<TENANT>_<TYPE>/` (with `/v2/` seen in one community link); no doc describes what changed in the URL scheme between versions.
