# Anatomy of the Infor CloudSuite / Infor OS (Ming.le) login redirect URL and the OAuth 2.0 / OIDC flow behind it

Observed redirect (analysed from public documentation only; the tenant was not accessed):

```
GET https://mingle-portal.ne1.inforcloudsuite.com/v2/V9ZL2J5PMG6EVXNW_PRD
  -> 302 https://mingle-sso.ne1.inforcloudsuite.com/V9ZL2J5PMG6EVXNW_PRD/as/authorization.oauth2
       ?client_id=infor~g-LqhqFwRI302t7LUEwulql9ZhdyRrmgCVeX1VWUVTE_OIDC
       &response_type=code
       &redirect_uri=https://mingle-portal.ne1.inforcloudsuite.com/sso/callback
       &scope=openid
       &state=V9ZL2J5PMG6EVXNW_PRD~zki6OLJb...
       &code_challenge=r2_epEx7AXK3DjPQZnONkC8xM-XljiXqrNFXFJrcPuc
       &code_challenge_method=S256
```

Breakdown of the parts:
- `mingle-portal.ne1...`: the Infor OS Portal (Ming.le), which is the relying party / OAuth client.
- `mingle-sso.ne1...`: the authorization server / OpenID Provider.
- `ne1`: the regional cloud cell (the DocBits guide shows an `eu1` sibling).
- `V9ZL2J5PMG6EVXNW_PRD`: the tenant ID with its environment suffix (`_PRD` = production).
- `/as/authorization.oauth2`: the PingFederate-style authorization endpoint.

## Q1. What does each parameter mean, and what security purpose does it serve (RFC 6749, RFC 7636, OIDC Core 1.0, RFC 9700)?

### Takeaway
This is a standard **OpenID Connect Authorization Code flow with PKCE (S256)**. RFC 9700 (January 2025) names this as the current best practice. `response_type=code` keeps tokens out of the browser URL. `redirect_uri` fixes where the code may be delivered. `scope=openid` turns plain OAuth into an OIDC login that returns an ID Token. `state` correlates request and response (CSRF and context). `code_challenge` with `S256` binds the authorization code to a secret `code_verifier` that only the portal holds. None of the parameters is a secret, so the URL can safely pass through the browser.

### Cited Findings
- **RFC 6749 §4.1.1 (Authorization Request):** the authorization-code request carries `response_type` (REQUIRED, value `code`), `client_id` (REQUIRED), `redirect_uri` (OPTIONAL), `scope` (OPTIONAL) and `state` (RECOMMENDED). These are sent as query parameters to the authorization endpoint via a browser redirect. — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749)
- **`client_id`:** RFC 6749 §2.2 says the client identifier is a unique string issued by the authorization server. It is **not a secret** and must not be relied on alone for client authentication. — [RFC 6749 §2.2](https://www.rfc-editor.org/rfc/rfc6749#section-2.2)
- **`response_type=code`:** asks for an authorization code, a short-lived, one-time intermediate credential. RFC 6749 §4.1.2 says the code MUST expire shortly after issue (recommended maximum 10 minutes) and MUST NOT be used more than once. The client then exchanges it at the token endpoint (§4.1.3). — [RFC 6749 §4.1.2](https://www.rfc-editor.org/rfc/rfc6749#section-4.1.2)
- **RFC 9700 §2.1.2:** clients SHOULD NOT use the implicit grant (`response_type=token`) and SHOULD use the `code` response type instead. §4.1.2 explains that the implicit grant can expose access tokens in the URL. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **`redirect_uri`:** where the authorization server sends the browser back with `code` and `state` (RFC 6749 §3.1.2). If it is included in the authorization request, the same value must be sent again in the token request (§4.1.3). — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749#section-3.1.2)
- **RFC 9700 §2.1 / §4.1:** authorization servers MUST use **exact string matching** of redirect URIs against pre-registered values (only localhost ports for native apps are exempt). This prevents open-redirect and code-leak attacks. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **`scope=openid`:** OIDC Core 1.0 §3.1.2.1 says that OpenID Connect requests MUST contain the `openid` scope value. Without it, the behaviour is unspecified (it is plain OAuth, not OIDC). Including `openid` makes the token endpoint return an **ID Token**, a signed JWT that identifies the authenticated user to the client (§3.1.3.3). — [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- **`state`:** RFC 6749 §4.1.1 defines it as an opaque value the client uses to keep state between request and callback. The AS returns it unchanged (§4.1.2) and it "SHOULD be used for preventing cross-site request forgery" (§10.12). OIDC Core §3.1.2.1 adds that CSRF mitigation is typically done by cryptographically binding `state` to a browser cookie. — [RFC 6749 §10.12](https://www.rfc-editor.org/rfc/rfc6749#section-10.12); [OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html)
- **RFC 9700 §2.1:** clients MUST prevent CSRF. Clients that use PKCE may rely on it for CSRF protection; otherwise one-time-use CSRF tokens carried in `state` MUST be used. In OIDC flows, `nonce` can also provide CSRF protection. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **`code_challenge` / `code_challenge_method=S256` (PKCE):** RFC 7636 works like this.
  - The client creates a high-entropy random `code_verifier` of 43–128 characters from the unreserved character set (§4.1).
  - It sends `code_challenge = BASE64URL-ENCODE(SHA256(ASCII(code_verifier)))` with method `S256` (§4.2).
  - It later proves possession by sending the plain `code_verifier` to the token endpoint (§4.5).
  - The server recomputes the hash and rejects the request on mismatch (§4.6).
  - If a client can use S256, it MUST use S256 (§4.2).
  — [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)
- **RFC 9700 §2.1.1:**
  - Public clients MUST use PKCE.
  - PKCE is RECOMMENDED for confidential clients.
  - Authorization servers MUST support PKCE.
  - Clients SHOULD use a method that does not expose the verifier in the authorization request, and "currently, S256 is the only such method."
  — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **RFC 9700 §4.5 (authorization code injection):** an attacker tries to inject a stolen code into their own session. §4.5.3.1 says PKCE prevents this because the code cannot be redeemed "without knowledge of the code_verifier". §4.5.3.2 says `nonce` does not protect the authorization codes of public clients. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **Mix-up attacks:** RFC 9700 §2.1 / §4.4 makes mix-up defence REQUIRED when a client talks to more than one AS. It recommends the `iss` response parameter (RFC 9207), which the client compares against the expected issuer. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **Checked locally:** the observed `code_challenge` is 43 base64url characters and decodes to exactly 32 bytes, which is the size of a SHA-256 digest. This matches RFC 7636's S256 transform. (Verified by decoding the string; no network access to the tenant.)

### Inferences
- The flow observed matches RFC 9700's preferred profile: code flow, PKCE S256, `state` present, a fixed HTTPS redirect URI on the client's own origin, and no implicit grant.
- **No `nonce` is visible.** OIDC Core makes `nonce` REQUIRED only for implicit/hybrid flows and optional for the code flow. With PKCE in place, RFC 9700 treats PKCE as sufficient CSRF and code-injection protection, so leaving out `nonce` is spec-compliant (the URL was truncated, so absence cannot be fully confirmed).
- **Likely client type:** the `redirect_uri` is a server-side path on the portal (`/sso/callback`), so the portal is most likely a **confidential client** that also uses PKCE (defence in depth, as RFC 9700 recommends). This is not confirmed by Infor documentation.
- **`client_id` format:** `infor~<random>_OIDC` resembles the `<TENANT>~<random>` client-ID style seen in Infor ION API (`.ionapi`) credentials.
  - The `infor~` prefix plausibly marks an Infor-owned, system-level client (the portal itself), as opposed to tenant-registered apps.
  - The `_OIDC` suffix plausibly marks it as the portal's OIDC client registration.
  - This is an inference only. No Infor document found explains this naming.

### Gaps
- No public Infor document lists the exact parameters the Ming.le portal sends, or confirms whether the portal authenticates with a client secret, private_key_jwt or similar at the token endpoint.
- Not confirmed whether Infor returns `iss` in the callback (RFC 9207) or uses `nonce`.

## Q2. Is `/as/authorization.oauth2` the PingFederate endpoint pattern? Is there evidence Infor OS SSO is built on PingFederate or PingOne?

### Takeaway
Yes, `/as/authorization.oauth2` is PingFederate's documented authorization endpoint path. Infor's own ION API credential format uses the full PingFederate endpoint family: `…/as/` base plus `authorization.oauth2`, `token.oauth2` and `revoke_token.oauth2`. This is **strong circumstantial (fingerprint) evidence** that mingle-sso's OAuth/OIDC authorization server is PingFederate or PingFederate-derived. However, **no Infor or Ping document found states it explicitly.** Infor's own public naming is "InforSTS", "Infor Federation Services (IFS)" and "Portal Federation Hub". No evidence of PingOne (the SaaS product) was found.

### Cited Findings
- **PingFederate developer reference (13.1, the "latest" page):** the OAuth 2.0 authorization endpoint is `/as/authorization.oauth2` and the token endpoint is `/as/token.oauth2`, both relative to the runtime base URL. Example: `https://www.example.com:9031/as/authorization.oauth2`. The paths are identical across doc versions 11.3–13.1. — [PingFederate OAuth 2.0 endpoints](https://docs.pingidentity.com/pingfederate/latest/developers_reference_guide/pf_oauth_20_endpoints.html)
- **PingFederate token endpoint:** POST only, `application/x-www-form-urlencoded`. — [PingFederate token endpoint](https://docs.pingidentity.com/pingfederate/12.1/developers_reference_guide/pf_token_endpoint.html)
- **Other PingFederate paths:** token revocation `/as/revoke_token.oauth2`, introspection `/as/introspect.oauth2`, and OIDC UserInfo `/idp/userinfo.openid`. — [PingFederate revocation endpoint](https://docs.pingidentity.com/pingfederate/13.1/developers_reference_guide/pf_token_revoc_endpoint.html); [Introspection endpoint](https://docs.pingidentity.com/pingfederate/11.2/developers_reference_guide/pf_introspec_endpoint.html); [PingFederate OIDC overview](https://docs.pingidentity.com/pingfederate/12.3/introduction_to_pingfederate/pf_openid_connect.html)
- **PingFederate's SAML/OIDC logout endpoints:** `/idp/startSLO.ping` and `/sp/startSLO.ping`. — [PingFederate IdP endpoints](https://docs.pingidentity.com/pingfederate/latest/developers_reference_guide/pf_idp_endpoints.html)
- **Infor `.ionapi` credential file (Infor docs):**
  - `pu` = "base URL for calling the authorization server for this tenant/environment".
  - `oa` = path appended to `pu` to build the authorization URL.
  - `ot` = path appended to `pu` to build the access-token URL.
  - `or` = path appended to `pu` to revoke a token.
  - The example values are `"oa": "authorization.oauth2"` and `"ot": "token.oauth2"`, with a sample `pu` ending in `/<tenant>/as/`.
  — [Infor CRM: Working with the ION API download file](https://docs.infor.com/crm/9.2.x/en-us/webclient/upi1639111995644.html)
- **Hexagon EAM guide (third party integrating with Infor ION):** Access Token URL = `pu + ot`, giving a `…/<TenantID>/as/token.oauth2` path. — [Hexagon EAM ION API doc](https://docs.hexagonppm.com/r/en-US/HxGN-EAM-ION-API-Swagger/1278210)
- **Infor OS 2026 API Gateway docs:** "Combine the pu and ot values" to obtain the token URL, then request a token with `grant_type=client_credentials`. — [Infor OS 2026.10 – Using the .ionapi credential to generate a Gateway token](https://docs.infor.com/inforos/2026.10/en-us/useradminlib_cloud/apigatewayag_cloud/wxo1761727799964.html)
- **Infor's own names for the components:**
  - "Infor OS in the cloud leverages Infor Security Token Service (InforSTS) as the identity provider used for authentication."
  - "Infor Federation Services (IFS) is the identity store."
  - "The Portal Federation Hub is the interface designed to allow authentication flows between applications and InforSTS."
  - InforSTS is described as a SAML 2.0-compliant IdP.
  — [Infor Developer Portal – Security & User Management](https://developer.infor.com/?p=1692) (content as returned by search; the page itself is a JS app and did not render on direct fetch)
- **Older/on-prem Infor PLM (Optiva) docs:** describe using PingFederate for authentication in a Ming.le environment, with WS-Federation used for AD FS and PingFederate. This shows Infor products have historically worked with PingFederate, though there it is a *customer* IdP rather than Infor's cloud AS. — [Infor PLM Optiva – Configuring security](https://docs.infor.com/csplmproc/12.1.x/en-us/optolh/lla1585847489548.html)
- **Third-party SSO vendors:** AuthDigital lists PingFederate and PingOne among the IdPs it can connect to Infor CloudSuite, and miniOrange mentions Ping. This is customer-side federation, not proof of Infor's internal stack. — [AuthDigital](https://authdigital.com/infor-cloudsuite-single-sign-on); [miniOrange](https://www.miniorange.com/infor-cloudsuite-single-sign-on-sso)
- **PingFederate itself:** a federation server supporting SAML, WS-Federation, WS-Trust (STS), OAuth, OIDC and SCIM. It can act as a federation hub bridging IdPs and SPs. — [PingFederate introduction](https://docs.pingidentity.com/pingfederate/latest/introduction_to_pingfederate/pf_intro_to_pf.html)

### Inferences
- **Why the fingerprint is strong:** the `.oauth2` suffixes under an `/as/` prefix are a PingFederate convention not used by other common AS products. Keycloak uses `/protocol/openid-connect/auth`, Entra ID uses `/oauth2/v2.0/authorize`, Okta uses `/oauth2/v1/authorize`, and Auth0 uses `/authorize`. Infor's own API credential file also hard-codes the full PingFederate set (`authorization.oauth2`, `token.oauth2`, `revoke_token.oauth2`). So it is highly likely that Infor's "Federation Hub" / InforSTS cloud authorization server is built on PingFederate. The alternative is a component that deliberately mimics PingFederate's paths, which seems unlikely.
- **Feature fit:** PingFederate's built-in WS-Trust STS, SAML/OIDC federation, SCIM and "federation hub" role match Infor's described components closely (InforSTS = STS; Federation Hub; SCIM provisioning for Okta/Google). This supports the inference.
- **Tenant prefix:** standard PingFederate exposes `/as/…` at the host root. The tenant prefix `/V9ZL2J5PMG6EVXNW_PRD/as/…` implies Infor puts a tenant-routing layer (reverse proxy / gateway, or per-tenant PingFederate instances or virtual configurations) in front. This is an inference; no source documents it.
- **PingOne:** no evidence. PingOne uses different URL shapes (e.g. `auth.pingone.<region>/<envId>/as/authorize`), so the observed URL is not PingOne.

### Gaps
- No Infor statement, Ping case study or Infor community post was found that explicitly names PingFederate as Infor's cloud SSO engine. This should be presented as "strong indication / likely", not "confirmed".
- Not verified: whether `/.well-known/openid-configuration` on mingle-sso would reveal more PingFederate fingerprints (not accessed, per constraints).

## Q3. Why does the tenant ID appear in the path and as a prefix of `state`? What does `state` do, and why is it so long and random?

### Takeaway
The tenant ID in the **path** selects the tenant-specific authorization server instance or config, i.e. that tenant's users, IdP federation, clients and signing keys. Infor documents the AS base URL as per "tenant/environment". The tenant ID inside **`state`** most likely lets the shared, tenant-agnostic callback (`/sso/callback`, which has no tenant in its path) know which tenant's token endpoint and session the returning code belongs to. The random remainder is the unguessable CSRF/correlation key. It ties the response to the browser session that started the login, and server-side it is likely linked to the stored `code_verifier` and the originally requested URL. Its length reflects entropy requirements (RFC 6749 §10.10: guessing probability ≤ 2^-128, SHOULD be ≤ 2^-160).

### Cited Findings
- **Per-tenant AS base:** Infor defines `pu` as the "base URL for calling the authorization server for this tenant/environment". Examples show `/<TenantID>/as/`. — [Infor CRM ION API file](https://docs.infor.com/crm/9.2.x/en-us/webclient/upi1639111995644.html); [Hexagon EAM](https://docs.hexagonppm.com/r/en-US/HxGN-EAM-ION-API-Swagger/1278210)
- **Portal URL pattern:** the Infor OS Portal URL takes the form `https://mingle-portal.<region>.inforcloudsuite.com/<TENANT_NAME>` (example uses `eu1`). — [DocBits – Infor SSO](https://docbits.com/doc/docbits-with-infor/infor-sso/)
- **`state` definition:** an opaque value used by the client to maintain state between request and callback. The AS MUST return it unchanged. It SHOULD be used for CSRF prevention by binding it to the user-agent's authenticated state, e.g. a hash of the session cookie (RFC 6749 §4.1.1, §4.1.2, §10.12). — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749)
- **OIDC Core §3.1.2.1:** `state` is RECOMMENDED. "Typically, Cross-Site Request Forgery (CSRF, XSRF) mitigation is done by cryptographically binding the value of this parameter with a browser cookie." — [OIDC Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- **RFC 6749 §10.10:** the probability of an attacker guessing generated tokens and other credentials not intended for end users MUST be ≤ 2^-128 and SHOULD be ≤ 2^-160. — [RFC 6749 §10.10](https://www.rfc-editor.org/rfc/rfc6749#section-10.10)
- **RFC 9700 §2.1:** if PKCE is not used, `state` must carry one-time-use CSRF tokens. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **RFC 6749 §3.1.2 and RFC 9700 exact matching:** the redirect URI is registered as a fixed string, so per-request context (tenant, return URL) cannot be added to `redirect_uri` itself. It has to travel in `state` or in a server-side session. — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749#section-3.1.2); [RFC 9700 §4.1](https://www.rfc-editor.org/rfc/rfc9700.html)

### Inferences
- **Why put the tenant in `state`:** the callback is shared across all tenants (`/sso/callback`), while the token endpoint is per tenant (`/<tenant>/as/token.oauth2`). The portal must know the tenant before it can redeem the code. A readable `TENANT~` prefix is a cheap routing hint. It is not secret, which is acceptable because `state` is not a confidential parameter.
- **The random part:** this is what provides security. It is most likely stored server-side or in a cookie, keyed to:
  - the browser session (anti-CSRF / anti-login-CSRF),
  - the PKCE `code_verifier`,
  - the original deep link (e.g. `/v2/<tenant>` or a specific app page), so the user returns there after login.
  
  This is the common "state as a lookup key" pattern (Auth0/Okta guidance). The exact Infor implementation is unknown.
- **Why it is long:** base64url-encoded random bytes (e.g. 32+ bytes gives 43+ characters), possibly plus an encrypted or signed blob if the client puts context directly in `state` instead of a server lookup. It is long because of entropy and encoding, not for obscurity.
- **`_PRD` suffix:** denotes the production environment of the tenant. Infor tenants typically have `_TST`/`_DEV`/`_TRN` siblings (common in Infor naming; consistent with the "tenant/environment" wording). Each environment gets a separate AS path, i.e. separate users, clients and keys.

### Gaps
- No Infor documentation describes the internal structure of the Ming.le portal's `state` value; the structure above is inferred.
- The meaning of `/v2/` in the portal path is undocumented in the sources found. It is plausibly the newer Infor OS Portal (Ming.le) UI or route version, but this is unverified.
- The meaning of `ne1` was not confirmed from an Infor source. It is a regional cell; by analogy with `eu1` it is probably a North America (East) region.

## Q4. What is the full end-to-end redirect sequence?

### Takeaway
The sequence is: browser hits the portal without a session → portal creates `state` + `code_verifier`, stores them, and 302s to the tenant's AS → the AS (Federation Hub / InforSTS) shows a login page or sends the user on to the customer's federated IdP (Azure AD/Entra, ADFS, Okta, Google; SAML 2.0 or OIDC) → the IdP authenticates (with MFA if configured) and returns an assertion/token to the hub → the AS issues a one-time code and 302s to `/sso/callback?code=…&state=…` → the portal validates `state`, then **back-channel** POSTs code + `code_verifier` (+ client authentication) to `/<tenant>/as/token.oauth2` → it receives an ID Token (+ access/refresh tokens), validates the ID Token, creates its own session cookie, and redirects to the original page. Later CloudSuite apps reuse the AS SSO session without asking for credentials again.

### Cited Findings
1. **Authorization request via browser redirect** — RFC 6749 §4.1.1. [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749#section-4.1.1); PKCE challenge creation in RFC 7636 §4.1–4.3. [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)
2. **Authentication at the AS, possibly federated:**
   - Infor's cloud uses InforSTS as IdP and IFS as identity store. Access starts at the Infor Cloud portal (SP-initiated). — [Infor Developer Portal – Security & User Management](https://developer.infor.com/?p=1692)
   - "IFS Federated Security and the Federation Hub handles the Cloud's Federation between Cloud Portal and the ADFS Identity Provider" (SAML 2.0). — [Infor tutorial – Cloud Portal SAML Federation with ADFS](https://developer.infor.com/tutorials/security-and-user-management/cloud-portal-saml-federation-with-adfs-identity-provider)
   - OIDC federation with Okta, with SCIM provisioning. — [Infor tutorial – OIDC Federation with Okta](https://developer.infor.com/tutorials/security-and-user-management/cloud-portal-oidc-federation-with-okta-identity-provider)
   - Azure AD/Entra: register Infor CloudSuite as a Web app whose redirect URI is the "Infor CloudSuite Callback URL", then import the Azure OIDC metadata URL into Infor (attribute `preferred_username`). — [Infor OS 2026.x – Adding Infor CloudSuite to Azure AD](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/inforoscsfcg/lgs1654279533706.html); [Adding Azure AD configuration to Infor CloudSuite](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/inforoscsfcg/bda1654280245778.html)
   - Azure AD "Active Mode" (off by default; enabled via Infor Support): the Federation Hub validates username/password against Azure AD's REST API instead of redirecting. — [Infor OS 2026.x – Azure AD Active Mode](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/minceag_cloud_osm/gqw1617427344734.html)
   - Okta's integration guide for Infor CloudSuite lists **SP-initiated SSO** as the supported mode, with SP Entity ID / ACS values taken from Infor's "Security Administration > Federated Security" screen. — [Okta – Configure SAML 2.0 for Infor CloudSuite](https://saml-doc.okta.com/SAML_Docs/How-to-Configure_SAML-2.0-for-Infor-CloudSuite.html)
3. **Authorization response:** the AS redirects to `redirect_uri` with `code` and the unchanged `state` (RFC 6749 §4.1.2). The code is short-lived (≤10 min recommended) and single-use. — [RFC 6749 §4.1.2](https://www.rfc-editor.org/rfc/rfc6749#section-4.1.2)
4. **Token request (back channel):** the client POSTs `grant_type=authorization_code`, `code`, `redirect_uri` and client authentication to the token endpoint (RFC 6749 §4.1.3), plus `code_verifier` (RFC 7636 §4.5). The server verifies the hash (§4.6). PingFederate's token endpoint accepts only POST with form encoding. — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749#section-4.1.3); [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636); [PingFederate token endpoint](https://docs.pingidentity.com/pingfederate/12.1/developers_reference_guide/pf_token_endpoint.html)
5. **ID Token validation (OIDC Core §3.1.3.7):** check issuer, audience = client_id, signature, expiry, and nonce if sent. The client then establishes its own session. — [OIDC Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
6. **SSO to other apps:** "Once a user authenticates to the Infor Cloud portal they will have access to all other applications without being challenged for credentials." — [Infor Developer Portal – Security & User Management](https://developer.infor.com/?p=1692)

### Inferences
- **Sequence diagram for the report:**
  1. `GET mingle-portal/v2/<tenant>` → no portal session → 302 to `mingle-sso/<tenant>/as/authorization.oauth2?...` (the portal stores `state`↔{`code_verifier`, return URL} in a cookie or server session).
  2. The browser loads the AS. If an SSO cookie already exists for mingle-sso, the AS skips the login UI. Otherwise it shows the Infor login page, or redirects to the customer IdP (SAML AuthnRequest or OIDC auth request). The customer IdP runs its own login and MFA and posts back to the hub's ACS/callback.
  3. The AS sets or refreshes its own SSO session cookie on `mingle-sso…` and issues `code`. It 302s to `mingle-portal/sso/callback?code=…&state=V9ZL…_PRD~…`.
  4. The portal backend parses the tenant from `state`, checks the random part against the cookie/session, and POSTs to `mingle-sso/<tenant>/as/token.oauth2` with `code` + `code_verifier` + client credentials.
  5. The portal validates the ID Token, maps the user to its IFS identity, sets the `mingle-portal` session cookie, and 302s to the original deep link.
- **Federated case:** there can be **two nested protocol hops**: customer IdP → Infor hub (SAML or OIDC, with Infor as SP/RP) and Infor hub → Ming.le portal (OIDC, with the hub as OP). The browser therefore may see more redirects (e.g. `login.microsoftonline.com`) between steps 2 and 3.
- **Steps 4–5 are server-to-server.** The access/refresh tokens and the ID Token never appear in the browser URL. Only the single-use, PKCE-bound `code` does.

### Gaps
- Infor does not publicly document the portal-side callback handler, session lifetime, or whether refresh tokens are used by the portal.
- The exact customer-IdP callback URLs on the Infor side (SAML ACS / OIDC redirect URIs) were not found in public text (they are shown in-tenant). It is therefore unconfirmed whether they use PingFederate-style `/sp/ACS.saml2` or `/sp/…/cb.openid` paths, which would further confirm PingFederate.

## Q5. Why separate domains (mingle-portal vs mingle-sso)? What does a separate Authorization Server/IdP give a multi-app, multi-tenant SaaS?

### Takeaway
Infor separates the **client/relying party** (the portal, and every other CloudSuite app) from the **authorization server / federation hub** (mingle-sso). The result is that one login session, one federation configuration per tenant, one MFA/IdP policy, one token issuer and one set of signing keys serve all Infor and third-party apps: SSO across CloudSuite, ION API and partner apps. The separate hostname also isolates the AS's sensitive cookies and login UI from application code.

### Cited Findings
- **Portal Federation Hub role:** "Infor applications existing in the Infor Multi Tenant cloud will be integrated with the Portal Federation Hub in order to achieve SSO. The Portal Federation Hub is the interface designed to allow authentication flows between applications and InforSTS." — [Infor Developer Portal](https://developer.infor.com/?p=1692)
- **Ming.le as a single portal:** Ming.le gives access to many Infor and non-Infor applications through a single portal, and its SSO lets users log in once and reach many systems without being prompted again. — [Infor docs – Authentication settings (Supplier Exchange)](https://docs.infor.com/se/12.2.x/en-us/seowhelp/ownapplicationsettings.html)
- **Centralised federation per tenant:** a centralised federation layer handles ADFS, Azure AD (SAML & OIDC), Google and Okta federation for CloudSuite, with SCIM provisioning where supported. — [Infor OS 2026.x – CloudSuite Federation Configuration Guide](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/inforoscsfcg/default.html); [Infor tutorials](https://developer.infor.com/tutorials/security-and-user-management)
- **Infor's stated benefits:** "Enhanced security through centralized authentication, simplified login experience, streamlined access management with SSO." — [Infor ADFS tutorial](https://developer.infor.com/tutorials/security-and-user-management/cloud-portal-saml-federation-with-adfs-identity-provider)
- **Same AS for the API layer:** the ION API Gateway uses the same per-tenant AS (`pu` → `/<tenant>/as/`) for machine and app tokens. — [Infor ION API file](https://docs.infor.com/crm/9.2.x/en-us/webclient/upi1639111995644.html)
- **PingFederate's model:** a federation hub that bridges many IdPs and SPs, so users authenticate once with PingFederate and get centralised SSO administration. — [PingFederate – identity federation & SSO](https://docs.pingidentity.com/pingfederate/11.2/introduction_to_pingfederate/pf_about_identity_fed_sso.html)
- **Exact matching enforces the boundary:** RFC 9700's exact redirect URI matching means the AS will only ever deliver codes to the portal's registered callback. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)

### Inferences
- **Separation of concerns and least privilege:**
  - Application hosts (portal, ERP apps) never see user passwords or the customer IdP's assertions. They only receive tokens meant for them (audience-restricted ID/access tokens).
  - A compromise or XSS in an app cannot read the AS's session cookie, because that cookie is host-scoped to mingle-sso.
  - Both hosts share the registrable domain `inforcloudsuite.com`, so they are "same-site" (not "same-origin"). That keeps SameSite=Lax cookies working during top-level redirects.
- **Multi-tenant scaling:** one AS cluster per region (`ne1`, `eu1`, …) with per-tenant paths lets Infor host thousands of tenants. Each has its own federation, clients and keys, while apps use one standard OIDC integration pattern.
- **Operational benefits:**
  - centralised MFA/conditional access, usually pushed to the customer's IdP;
  - central user lifecycle (SCIM, IFS);
  - consistent logout/SLO;
  - audit in one place;
  - key rotation in one place;
  - new apps join SSO by registering a client instead of building login.
- **Standard separation in OAuth:** the AS and the client are different roles by design (RFC 6749 §1.1). Running them on different hosts is the normal deployment for SaaS IdPs (Entra `login.microsoftonline.com`, Okta `<org>.okta.com`, etc.).

### Gaps
- Infor does not publicly document why the hostnames are split. The rationale above follows OAuth role separation and general web-security practice.

## Q6. Does the URL's length add any security?

### Takeaway
No. The length simply comes from carrying protocol parameters: two hostnames, a tenant path, a long `client_id`, a URL-encoded `redirect_uri`, a high-entropy `state` and a 43-character `code_challenge`. Security comes from **entropy and cryptographic binding** (unguessable `state`; `code_challenge` = SHA-256 of a secret verifier that never leaves the portal backend), from exact redirect-URI matching and from TLS, not from length or obscurity. Every value in the URL is public by design. No client secret, `code_verifier` or token appears in it.

### Cited Findings
- `client_id` is not a secret (RFC 6749 §2.2). The PKCE challenge is safe to expose because S256 is one-way. RFC 9700 prefers S256 specifically because it "does not expose the verifier in the authorization request". — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749#section-2.2); [RFC 9700 §2.1.1](https://www.rfc-editor.org/rfc/rfc9700.html)
- RFC 7636 §7.1 recommends that the `code_verifier` have enough entropy (e.g. a 32-octet random sequence base64url-encoded into a 43-character string). This explains the 43-character lengths. — [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)
- RFC 6749 §10.10 requires generated credentials to have guessing probability ≤ 2^-128 (SHOULD ≤ 2^-160). This drives the length of `state`/codes, not "security by length" per se. — [RFC 6749 §10.10](https://www.rfc-editor.org/rfc/rfc6749#section-10.10)
- RFC 9700 still warns that values in URLs can leak (browser history, Referer headers, logs; see §4.2 on credential leakage via Referer and §4.3 on browser history). This is why the URL carries only short-lived, single-use or non-secret values, and why tokens are fetched over the back channel. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- **Checked locally:** `code_challenge` is 43 characters and decodes to 32 bytes, i.e. one SHA-256 digest.

### Inferences
- **What an observer learns from this URL:** the tenant ID, region, portal client ID and a one-time `state`/challenge. None of these lets them log in. Replaying the URL just starts a new login. A stolen `code` from the callback URL is useless without the `code_verifier` and client authentication.
- **Older behaviour, for contrast:** pre-OIDC Ming.le/Infor OS on-prem deployments used **WS-Federation / SAML with AD FS**. Those redirects carried large signed XML (`wresult`, `SAMLResponse`) usually in auto-POST forms rather than URLs. (Infor docs for on-prem Infor OS describe AD FS federation-metadata setup; Optiva docs mention WS-Federation for AD FS and PingFederate.) The current OIDC code+PKCE URL is shorter and carries no assertion at all. — [Infor OS 2023.x on-prem – Changing the AD FS identity provider](https://docs.infor.com/inforos/2023.x/en-us/useradminlib_onpremises/inforosag_on-premise/zun1543961448721.html); [Infor PLM Optiva – Configuring security](https://docs.infor.com/csplmproc/12.1.x/en-us/optolh/lla1585847489548.html)

### Gaps
- No Infor-specific statement on URL logging, Referer policy or callback-URL cleanup on mingle-portal was found.
