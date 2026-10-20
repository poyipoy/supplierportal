# Single Sign-Out / Single Logout standards (OIDC, SAML), failure modes, and implications for a web app

## 1. OIDC logout family: which mechanisms need the browser/tab to stay open?

### Takeaway
RP-Initiated, Front-Channel and Session Management all ride on the user's browser (redirects, iframes, postMessage), so they only complete while the browser keeps loading pages. Only Back-Channel Logout is server-to-server and needs no tab. Closing the tab mid-chain leaves the unreached RPs signed in, with no standard recovery.

### Cited Findings
- RP-Initiated Logout 1.0: `end_session_endpoint` is the OP's logout URL, must be https; OP must support GET and POST. — [OIDC RP-Initiated Logout 1.0](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)
- `id_token_hint` is recommended; OP must validate it was the issuer; `client_id` optional (OP must check it matches when both are given); `logout_hint`, `state`, `ui_locales` optional; `state` is returned unchanged on redirect. — same spec
- `post_logout_redirect_uri` must have been previously registered and the OP must not redirect unless it exactly matches a registered value; redirect happens only after RPs have been notified. The OP should ask the user to confirm, and must ask if there is no `id_token_hint` or it does not match the session (DoS/forced-logout concern). — same spec
- Front-Channel Logout 1.0: the OP builds a page that loads each RP's `frontchannel_logout_uri` in an iframe, in parallel, via the User Agent; the OP tracks logged-in RPs (e.g. a "visited sites" cookie). RP clears its session state on receipt; response should carry `Cache-Control: no-store`. OP may add `iss` and `sid` query params (both or neither). — [OIDC Front-Channel Logout 1.0](https://openid.net/specs/openid-connect-frontchannel-1_0.html)
- Front-channel explicitly warns that browsers blocking third-party content/cookies mean the iframe may not reach the RP's login state, recommends defensive detection/user notification, and notes back-channel "is not known to be affected". — same spec
- Back-Channel Logout 1.0: OP POSTs form-encoded `logout_token` (JWT) directly to the RP's registered `backchannel_logout_uri`; requires `iss`, `aud`, `iat`, `exp`, `jti`, `events`, and `sub` and/or `sid`; `nonce` prohibited; RP validates, ends the identified session, returns 200 (204 accepted) or 400 on failure; short lifetimes (<= ~2 min) encouraged; `typ: logout+jwt` recommended. — [OIDC Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html)
- Back-channel limitations: cookies/local storage are unavailable on this path so the RP must map `sid`/`sub` to its own session store; RP endpoint must be reachable from the OP (firewall/NAT issue); retransmission only for recoverable failures. — same spec
- Session Management 1.0 (status "Final", 12 Sep 2022): RP embeds OP's `check_session_iframe`, polls via postMessage with `client_id + " " + session_state`; OP replies changed/unchanged/error; the OP state cookie usually cannot be HttpOnly since JS must read it. — [OIDC Session Management 1.0](https://openid.net/specs/openid-connect-session-1_0.html)
- Session Management warns that when third-party content is blocked, cookie-based implementations "might then return changed for every single call", causing re-auth loops. — same spec
- Auth0: back-channel sends `sub`/`sid` logout token to each app; apps should not rely on session cookies; delivery is queued asynchronously (possible delay under load, eventual consistency); available on Enterprise plan. — [Auth0 Back-Channel Logout](https://auth0.com/docs/authenticate/login/logout/back-channel-logout)
- WSO2 advises back-channel as more reliable because it does not depend on browser cookie policy. — via search summary of [WSO2 front-channel docs](https://is.docs.wso2.com/en/next/guides/authentication/oidc/add-front-channel-logout/) (not fetched in full)
- Practitioner guidance (2026 blog, secondary): app session and OP session are separate; clear local session first and make it idempotent; RP-initiated logout alone "does not prove that every application session ended"; word UI as "signed out of this application" unless confirmed. — [OneUptime](https://oneuptime.com/blog/post/2026-08-10-oidc-app-provider-session-logout/view)

### Inferences
- Why the tab must stay open: the front-channel page is the thing doing the fan-out; if the OP page unloads, in-flight iframe requests are cancelled and unstarted ones never fire. The RP-initiated redirect is also a browser hop; the spec says redirect occurs after RPs are notified, so the "signing out" screen holds the user until iframes load.
- If the tab closes mid-chain: the OP session is typically already terminated (it acts first), but RPs whose iframes had not loaded keep their sessions until their own idle/absolute timeouts. Nothing in the specs retries front-channel notifications (the specs do not define a retry; inferred from absence).
- Back-channel is the only mechanism that survives tab closure, but it introduces its own failure mode (RP unreachable, retry policy OP-specific).
- Session Management is Final but practically obsolete in third-party-cookie-restricted browsers; this is an inference from the spec's own warning plus browser policy, not an official deprecation statement. No source I found formally deprecates it.

### Gaps
- No spec text found defining front-channel iframe timeouts or retry behavior; OP-specific.
- Okta/Keycloak current official docs not fetched directly (only forum/support-level results); Okta guidance summarized in section 5 is from support articles.

## 2. SAML 2.0 Single Logout

### Takeaway
SAML SLO makes the IdP the session authority that fans LogoutRequests out to each session participant, usually via browser redirects/POSTs; the OASIS overview itself says the result "cannot be guaranteed". Sequential chains are slow and one failing SP can break the chain, hence "do not close this window" UIs and the PartialLogout status.

### Cited Findings
- OASIS technical overview, SP-initiated example: SP ends local session, sends signed LogoutRequest (HTTP-Redirect) to IdP; IdP validates the SP, ends its session, sends LogoutRequests to other participating SPs (example uses back-channel SOAP to sp2), gets signed LogoutResponses, then returns a signed response to the initiating SP. — [SAML 2.0 Technical Overview](https://docs.oasis-open.org/security/saml/Post2.0/sstc-saml-tech-overview-2.0-cd-02.html)
- Same document: "asynchronous front-channel bindings are typically preferred" because the browser must visit each participant to access its cookies, and browser interaction can interrupt the process so "the result of the logout process cannot be guaranteed." — same source (as summarized by fetch tool; section read covered SP-initiated only; IdP-initiated was not described in the part read)
- PartialLogout second-level status: used by a session authority to indicate it could not propagate logout to all other participants. — secondary: [SSOJet](https://ssojet.com/blog/single-logout-slo-saml-oidc-why-it-fails) quoting the Core spec (I could not read the OASIS PDFs, which were compressed binaries, so wording is unverified against the primary)
- Sequential model is fragile: a problem at one SP can stop the chain, leaving sessions open elsewhere. — [Nevis docs](https://docs.nevis.net/nevisauth/LTS19/Setup-and-Configuration/Authentication-Plug-Ins-and-AuthStates/SAML-AuthStates/IdentityProviderState) (via search summary)
- Shibboleth SP developers once returned Success instead of Success+PartialLogout because PartialLogout broke an ADFS flow and interrupted the RelayState redirect; SP logout UI runs in a hidden iframe (2015 Jira comment, may be outdated). — [Shibboleth IDP-224](https://issues.shibboleth.net/jira/browse/IDP-224)
- Vendor advice: use timeouts, fallbacks, do not rely on SLO alone for security; keep sensible session timeouts. — [SSOJet SLO glossary](https://ssojet.com/saml-glossary/single-logout-slo) (vendor, secondary)

### Inferences
- Slowness is additive: each hop is a full browser round trip (or SOAP call) with signature validation and an SP-side session teardown; N participants means N sequential hops, so one slow/offline SP stalls everyone behind it. Hence "Signing out… please do not close this window."
- Same tab-dependence as OIDC front-channel whenever Redirect/POST bindings are used; SOAP (back-channel) legs do not need the browser but are rarely deployed because of network reachability and mutual-TLS complexity.

### Gaps
- OASIS Core/Profiles PDFs (saml-core-2.0-os, saml-profiles-2.0-os) were unreadable via the fetch tool; precise normative text (profile 4.4, Core 3.7, timeout rules, IdP-initiated details) not verified. Writer should treat PartialLogout wording as secondary-sourced.

## 3. Token revocation, cookies, Clear-Site-Data

### Takeaway
Revocation (RFC 7009) kills tokens, not browser sessions; ending a browser session requires server-side session invalidation plus cookie expiry. `Clear-Site-Data` is a same-origin/registered-domain cleanup aid, not a cross-app SSO mechanism.

### Cited Findings
- RFC 7009: POST over HTTPS to revocation endpoint; server MUST support refresh-token revocation, SHOULD support access-token revocation; revoking a refresh token SHOULD also invalidate access tokens from the same grant; server returns 200 even for invalid tokens; self-contained access tokens need extra non-standard backend interaction or short lifetimes. — [RFC 7009](https://datatracker.ietf.org/doc/html/rfc7009)
- OneUptime (secondary): revocation does not necessarily clear the OP's SSO cookie; don't block sign-out on a network call. — [OneUptime](https://oneuptime.com/blog/post/2026-08-10-oidc-app-provider-session-logout/view)
- OWASP: must invalidate session on both client and server; server side is mandatory; deleting the cookie alone does not invalidate a stolen session ID; clear cookie with empty value and past `Expires`/`Max-Age`; return `Clear-Site-Data` on logout (caution: `"cache"` clears all stored responses); regenerate session ID on privilege change (mandatory against fixation); never accept unknown session IDs. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- Clear-Site-Data directives: `cache`, `cookies`, `storage`, `executionContexts`, `"*"`; cookies cleared across the whole registered domain; effective only over authenticated (secure) URLs; clearing is best-effort. — [W3C Clear-Site-Data](https://www.w3.org/TR/clear-site-data/) (fetched copy is a Working Draft dated 30 Nov 2017; the header is nonetheless widely implemented, browser support matrix not verified here)

### Inferences
- Clear-Site-Data helps only for the app's own origin/registered domain; it cannot log the user out of other RPs on other sites, so it complements but does not replace back-channel logout.
- Because the registered-domain cookie wipe is broad, using `"cookies"` on a shared parent domain could log users out of sibling apps (follows from the spec's stated scope).

### Gaps
- Current browser support for `Clear-Site-Data` (Safari especially) not verified.

## 4. Browser constraints 2025-2026

### Takeaway
Third-party-cookie blocking/partitioning already breaks cookie-dependent iframes in Safari/Firefox and in Chrome when users or modes block them; Chrome did not force a global phase-out. Front-channel logout and `check_session_iframe` depend on unpartitioned cookies and so are unreliable cross-site; back-channel is recommended.

### Cited Findings
- April 22, 2025: Google said it would keep Chrome's existing third-party cookie choice and not ship a standalone prompt; Incognito already blocks third-party cookies. — [Privacy Sandbox blog](https://privacysandbox.google.com/blog/privacy-sandbox-next-steps) (via search summary; page itself not fetched)
- A secondary source says on 17 Oct 2025 Google retired several Privacy Sandbox APIs while continuing CHIPS, FedCM, Private State Tokens; I could not verify against a primary page (fetch of the Oct 2025 post returned 404). — [stape.io](https://stape.io/news/google-chrome-cookie-deprecation)
- CHIPS: `Partitioned` cookie attribute (requires `Secure`); cookie keyed to host plus top-level site, so it is only sent when embedded under the same top-level site. — [Privacy Sandbox CHIPS](https://privacysandbox.google.com/3pcd/chips)
- Front-channel spec and Session Management spec both state the third-party-content problem; Keycloak/Red Hat and OpenID issue-tracker threads report failures when third-party cookies are disabled; a restrictive CSP `frame-src` can also silently block the logout iframes. — [OpenID issue 1003](https://bitbucket.org/openid/connect/issues/1003/document-possible-impacts-of-disabling), [OpenID issue 1149](https://bitbucket.org/openid/connect/issues/1149/front-channel-logout-that-doesnt-rely-on), [RHSSO-2834](https://issues.jboss.org/browse/RHSSO-2834) (via search summary only)
- Workaround suggested by WSO2: host OP and apps on subdomains of one parent so cookies are first-party. — [WSO2](https://is.docs.wso2.com/en/next/guides/authentication/oidc/add-front-channel-logout/) (search summary)

### Inferences
- A partitioned cookie only helps if the OP set it partitioned under that specific RP top-level site, i.e. a separate "session" per RP; it cannot represent the shared SSO session across RPs, so CHIPS does not rescue front-channel logout or `check_session_iframe`.
- SameSite defaults: not separately researched; iframe requests cross-site with default Lax cookies would omit the cookie, consistent with the failure reports above.

### Gaps
- No primary source fetched for Safari ITP/Firefox TCP storage partitioning or for the exact Chrome 2026 status; Chrome SameSite default details not verified.

## 5. Security guidance (OWASP, ASVS, logout CSRF, redirects)

### Takeaway
Logout must destroy server-side state, be reachable on every authenticated page, use a state-changing method with CSRF defense, and restrict post-logout redirects to exact registered values.

### Cited Findings
- ASVS 5.0 V7.4: 7.4.1 (L1) application disallows further use of the session after termination (backend invalidation for stateful; revoke list for self-contained tokens); 7.4.2 terminate sessions when account disabled/deleted; 7.4.3 (L2) option to terminate other sessions after auth factor change; 7.4.4 (L2) visible logout on every authenticated page; 7.4.5 (L2) admins can terminate sessions. — [ASVS 5.0 V7](https://raw.githubusercontent.com/OWASP/ASVS/master/5.0/en/0x16-V7-Session-Management.md)
- ASVS federated: 7.1.3 (L2) document controls coordinating session lifetime/termination across SSO systems; 7.6.1 (L2) lifetime/termination between RP and IdP behaves as documented, requiring re-authentication as needed. — same source
- OWASP: SameSite is defense in depth, not a CSRF-token replacement; logout button must end session at least server-side. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- RP-Initiated spec: exact-match registered `post_logout_redirect_uri`; user confirmation when no valid hint (anti forced-logout). — [RP-Initiated Logout 1.0](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)
- Practitioner: use state-changing method with CSRF protection for logout, one-time `state`, never accept arbitrary logout target from a query parameter. — [OneUptime](https://oneuptime.com/blog/post/2026-08-10-oidc-app-provider-session-logout/view)
- Okta (support articles): ID token for `id_token_hint` must come from the same authorization server; expired but otherwise valid token still works if subject matches the session; redirect URI must be registered under Logout Redirect URIs. — [Okta support](https://support.okta.com/help/s/article/the-purpose-of-id-token-hint-in-the-logout-api-and-how-does-it-affect-the-single-logout-process)

### Inferences
- "Use POST not GET" for logout is stated by the OWASP-style practice as logout-CSRF avoidance; I did not find a single OWASP cheat-sheet sentence naming GET explicitly in the fetched summary, so cite OneUptime/general practice rather than OWASP for that exact point.
- Note the OIDC spec requires the OP's end_session_endpoint to accept GET (cross-site logout is therefore possible at the OP; the OP's confirmation prompt is the mitigation), while the app's own logout route should be POST + CSRF token.

### Gaps
- OWASP Logout/CSRF Prevention cheat sheets not fetched; ASVS items from a tool summary of the master branch (verify numbering against the released 5.0.0 PDF).

## 6. Laravel-relevant notes and implications for a web app

### Takeaway
I found no source showing Passport or Socialite providing OIDC RP-initiated logout; treat it as something the app builds. Practical design: do local logout first, then best-effort OP/SLO, with back-channel for reliability and short session lifetimes as backstop.

### Cited Findings
- Search for Laravel Passport/Socialite RP-initiated logout returned no Laravel-specific sources; only general OIDC material (end_session_endpoint obtained via discovery; post-logout URI must be registered). — [search summary](https://oneuptime.com/blog/post/2026-08-10-oidc-app-provider-session-logout/view) (general, not Laravel)

### Inferences
- Unverified (from general knowledge, not sourced): Socialite's OAuth flow has no logout concept and Passport is an OAuth2 server, so an app consuming an external OIDC OP must build the redirect to `end_session_endpoint` itself (store `id_token` at login for `id_token_hint`, read endpoint from discovery, register post-logout URI, generate/verify `state`).
- Recommended Laravel sequence consistent with OWASP/ASVS: (1) POST + CSRF logout route; (2) `Auth::logout()`, invalidate and regenerate session, expire cookie (optionally `Clear-Site-Data` on same origin); (3) revoke tokens (RFC 7009) without blocking; (4) redirect to OP `end_session_endpoint`; (5) expose a back-channel endpoint that validates `logout_token` and kills sessions by `sid` (needs a server-side session store keyed by `sid` - database/redis sessions rather than cookie-only); (6) UI copy: "signed out of this application".
- For this portal (AGENTS.md): login is Laravel built-in auth with 2FA and session revocation (`EnforceAuthSessionSecurity`), no OIDC found in the stack description; a user closing the tab only matters if an external SSO/SLO is introduced. Local logout already completes synchronously server-side, so tab-dependence arises only for federated propagation.

### Gaps
- Not verified: current Laravel/Passport/Socialite docs and any third-party Socialite OIDC providers' logout support; no repository code was inspected for this task.
