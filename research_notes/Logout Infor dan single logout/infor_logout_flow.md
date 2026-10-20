# Infor OS Portal / Ming.le / CloudSuite logout flow and "do not close the tab"

Method note: public docs only; no tenant login. Several docs.infor.com URLs returned "page moved" redirect stubs through the fetch tool, so some facts rest on search-tool excerpts of those pages rather than a full page read. This is flagged per item. Community threads (community.infor.com) are behind a login wall, so only search excerpts were available.

## What does Infor document about Sign out / Log off, session timeout, and embedded applications?

### Takeaway
Infor's current Infor OS Portal user guide says Sign Out ends the session in the Portal and in all applications accessed in that session, then instructs the user to close the browser session. Session timeout is a separate admin setting, and an "Enable remote logout" option on IdP connections controls whether the external IdP is also logged out.

### Cited Findings
- User guide "Signing out" steps: User Menu > Sign Out; note "This signs you out of Infor OS Portal and all of the applications that you accessed in this session"; then "Close your Internet browser session"; a confirmation message is displayed. If the admin enabled a sign-out icon in the portal bar, it can be used too. (Fetched in full.) — [Infor OS Portal: Signing out](https://docs.infor.com/inforos/latest/en-us/useradminlib_cloud/inforospug/lsm1436532948458.html)
- The Ming.le 12.0.x user guide carries the same topic ID (lsm1436532948458) and, per search excerpt, the same "sign out via profile menu, then close your browser session" instruction. The page fetch itself returned only a redirect stub, so this is from the search excerpt. — [Ming.le 12.0.x Signing out](https://docs.infor.com/mingle/12.0.x/en-us/minolh/lsm1436532948458.html)
- Idle timeout is configured at Security Administration > Session Configuration > Time Out; 2026.x guide range 5 to 470 minutes (default 470); applies to all users and applications in the Portal; warning shown 5 minutes before auto sign-out when timeout is over 10 minutes (2 minutes otherwise) with a button to extend; Infor recommends at most 20 minutes if concurrent sessions are enabled; if a user does not log out properly they must wait the full time-out period before regaining access. Source is a search-tool summary; the page fetch returned a redirect stub, so treat exact numbers as unverified against the full page. — [Infor OS Time-out (2026.x)](https://docs.infor.com/inforos/2026.x/en-us/useradminlib_cloud/inforos_security/jho1456364611087.html)
- Infor OS admin guide, IdP connection setting "Enable remote logout": "When this check box is selected and then a logout is performed, the remote identity provider is logged out as well." and "If this is option is not selected, then the logout request is not sent to the remote identity provider." (Fetched, 2023.x on-premises guide; a 2026.06 Infor LTR guide has the same topic per search.) — [Creating an IdP connection (2023.x)](https://docs.infor.com/inforos/2023.x/en-us/useradminlib_onpremises/inforosag_on-premise/jjk1513633683433.html); [2026.06 version](https://docs.infor.com/inforosltr/2026.06/en-us/useradminlib_onpremises/inforosltrag/uim1702044712902.html)
- Embedded app example (Distribution SX.e): docs state that under certain conditions you must sign out of Infor OS to fully sign out of SX.e, and if you stay signed in to Infor OS, SX.e reopens without a password. Source is a search excerpt (page fetch was a stub). — [SX.e Sign-out process](https://docs.infor.com/sxe/2021.x/en-us/sxolh/gzo1503926186523.html)
- Infor LN UI "Signing in" page for 2026.04 mentions Infor OS Portal only for sign-in; no sign-out content. (Fetched.) — [LN UI Signing in](https://docs.infor.com/lnui/2026.04/en-us/lnuisettingsolh/lnuisettingsolhug/zsq1504268193313.html)

### Inferences
- "All applications that you accessed in this session" implies the Portal keeps a list of apps it touched and signs each out (or invalidates a shared SSO session), but the docs do not say how.
- The explicit "close your browser session" step, listed after the Sign Out click in official docs, shows Infor itself treats the browser close as part of a complete logout.

### Gaps
- No Infor page found that explicitly says "do not close the browser/tab until logout completes" or "wait until".
- No Infor documentation found describing per-application behavior for M3 H5, IDM, Birst, d/EPM on Portal sign-out; only LN UI (sign-in only) and SX.e were found.
- Full text of the 2026.x timeout page could not be read directly.

## Is Infor logout a Single Logout chain across applications and the identity provider?

### Takeaway
Infor documents SAML single logout support between Infor OS Portal (as SP) and an external IdP, controlled by "Enable remote logout" / "Enable Identity Provider Single Logoff". Whether the hosted cloud SSO tier is PingFederate is not confirmed by any Infor doc found; the PingFederate endpoint list below is background, not proof of Infor's implementation.

### Cited Findings
- Okta's Infor CloudSuite SAML guide lists "SP-initiated Single Logout (optional)". Infor-side steps: select HTTP Post and enter the IdP Logout URL, check "Enable Identity Provider Single Logoff", download the SP Primary Certificate, copy the Single Logoff Service value; Okta-side: enable Single Logout, upload the signature cert, paste the Single Logoff Service value. (Fetched.) — [Okta: Infor CloudSuite SAML](https://saml-doc.okta.com/SAML_Docs/How-to-Configure_SAML-2.0-for-Infor-CloudSuite.html)
- Infor WFM registry docs: IDP_SLO_URL is "the URL on the identify provider (IdP) server to send the SAML LogoutRequest or LogoutResponse to"; applies only to SP-initiated SSO; for Infor OS Portal 12 CE take it from the SingleLogoutService element in ServiceProviderSAMLMetadata.xml (HTTP-Redirect or HTTP-POST); for On Prem the Portal admin supplies it. (Fetched.) — [WFM IDP_SLO_URL](https://docs.infor.com/wfm/2026/en-us/wfmopolh/registry_parameter_reference_on-premise/eay1489518764749.html)
- Infor docs describe the Portal sign-in as using a federated Security Token Service (search-tool summary of the Infor sign-in docs; not verified by full read). — [Single sign on (Infor 11.1.x)](https://docs.infor.com/pub/11.1.x/en-us/useradminlib/pubsysag/sst1442623616345.html)
- PingFederate (general product docs, not Infor-specific): the Asynchronous Front-Channel Logout endpoint is /idp/startSLO.ping, also advertised as `ping_end_session_endpoint` in OIDC discovery; modes are Ping Front-Channel ("sends logout requests, using the browser, to PingAccess and additional requests to other relying parties"), OIDC Front-Channel (browser-based to each RP Front-Channel Logout URI) and OIDC Back-Channel (logout token, no browser). The page does not describe iframes, ordering, or failure behavior. (Fetched.) — [PingFederate 13.0 Async Front-Channel Logout](https://docs.pingidentity.com/pingfederate/13.0/administrators_reference_guide/pf_asynchron_front_channel_logout.html)
- Per search summary of the PingFederate developer guide: the `TargetResource` parameter sets the post-logout landing URL, `LogoutType` accepts AsyncOnly/SyncOnly, and a TargetResource pointing at another SLO URL must be in the Redirect Validation list. Not fully fetched. — [PingFederate 13.0 IdP endpoints](https://docs.pingidentity.com/pingfederate/13.0/developers_reference_guide/pf_idp_endpoints.html)

### Inferences
- Because the browser carries the logout messages (SAML Redirect/POST binding to the IdP's Single Logoff Service; Ping/OIDC front-channel modes), the chain exists only while the browser tab keeps loading each hop. Closing the tab mid-chain could leave later hops unexecuted. This is reasoning from the protocol design, not an Infor statement.
- The `/<TENANT>/as/authorization.oauth2` path is the PingFederate OAuth authorization endpoint pattern, so PingFederate is plausible for the cloud STS, but no Infor document confirming this was found. Treat as unverified.

### Gaps
- No Infor document found that lists the actual logout endpoints on mingle-sso.*.inforcloudsuite.com or confirms PingFederate.
- Microsoft Learn Infor CloudSuite Entra ID tutorial logout URL fields were not retrieved.
- Whether Infor uses OIDC end_session, SAML SLO, or proprietary calls to embedded apps in the cloud tenant is undocumented in what was found.

## Why might users be told not to close the tab, and what are the known issues?

### Takeaway
The only sourced explanation is that Infor's logout is not complete until the browser session is closed: community reports say Ming.le shows "Must close the browser to complete the sign out process" and that skipping it lets the next session sign in without credentials. No source states a "wait for the redirect chain" rationale; that remains inference.

### Cited Findings
- Community thread (Infor 10 Ming.le): users get a message "Must close the browser to complete the sign out process"; if not closed, sign-out is not completed; the next session may not ask for credentials, especially with other browser windows open; kiosk risk. Source is a search excerpt (thread behind login). — [Infor Community 5877](https://community.infor.com/discussion/5877/ming-le-infor-10-closing-browser-after-logging-out-and-system-not-asking-for-credential-when-loggi)
- LawsonGuru thread: original poster says "If you log out in Ming.le and forget to close the browser, the next session does not require credentials"; Infor support pointed to patches LSF JT-879870 and Portal JT-892368 (both about timeout behavior); another poster says the patch is not a complete fix; a poster reports Ming.le 11 did not have the issue and used IE private mode as workaround. The thread gives no technical mechanism. (Fetched.) — [LawsonGuru](https://www.lawsonguru.com/Forums/platforms/lsf/automatic-browser-closure-after-logout-in-ming-le/)
- Other related community threads surfaced but not readable: [automatic browser closure in Ming.le](https://community.infor.com/discussion/5231/automatic-browser-closure-in-ming-le), [ADFS logout issue](https://community.infor.com/discussion/9529/adfs-hrsd-enwisen-logout-issue), [session timeout](https://community.infor.com/discussion/19317/session-timeout).
- With "Enable remote logout" off, the logout request is not sent to the IdP (see first section), which is consistent with users not being prompted again at next login. — [Creating an IdP connection](https://docs.infor.com/inforos/2023.x/en-us/useradminlib_onpremises/inforosag_on-premise/jjk1513633683433.html)

### Inferences
- Candidate explanation most supported: the SSO session cookie on the SSO/IdP domain survives if the logout hops (to apps and/or IdP) are interrupted or never configured; "close the browser" is the fallback that clears session-only cookies. Supported only indirectly (docs say close browser; community says credentials are skipped otherwise).
- Candidate explanation not verified: hidden iframes or postMessage to embedded apps, token revocation calls (/as/revoke_token.oauth2), and a final "You have been signed out" landing page. No source mentions any of these for Infor. Because Infor's own wording is "close your browser" AFTER sign out, the "don't close the tab until logout completes" advice reported by users is likely an operational corollary (let redirects finish, then close) rather than documented text.
- Customer IdP round trip (ADFS/Entra/Okta) adds hops that the tab must survive; this is protocol reasoning.

### Gaps
- No Infor wording found for "do not close the browser" or "wait until logout completes".
- No public KB/community post found on redirect loops or hung "signing out" screens in the 2026 cloud Portal; found content is mostly Ming.le 10/12-era.
- Ming.le 12.x vs current Infor OS Portal differences in logout: the "Signing out" topic ID is shared across both guide lines, suggesting wording continuity, but no behavioral comparison was found.
