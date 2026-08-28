# Sorkos Login security assessment

Assessment date: 2026-08-08  
Scope: `auth` application, its OAuth-style authorization flow, administration area, account management, production response headers, and the local `glyph` client integration.  
Method: white-box code review, OWASP ASVS/Top 10 and STRIDE threat modelling, dependency audit, and restrained non-destructive HTTP testing. No account compromise, destructive action, credential guessing, email delivery, or load testing was attempted.

## Executive summary

No critical remote account-takeover path was demonstrated. The central authorization-code flow has several good foundations: redirect URIs are matched exactly, authorization codes are random, hashed at rest, short-lived, bound to the client and redirect URI, and redeemable once; confidential-client secrets are password-hashed; email codes are bounded, attempt-limited, and stored as hashes; SQL access is predominantly parameterized.

The project is not yet at a defensible production hardening baseline. The highest-priority gaps are:

1. Browser-authenticated state changes do not have CSRF protection, including sensitive admin changes.
2. Emailed verification links are constructed from request-controlled forwarded host/protocol headers.
3. Public OAuth clients are supported without PKCE; all currently registered local clients are confidential, which reduces the immediate exposure.
4. Admin login has no throttling, and the TOTP secret is stored in plaintext despite the database column name implying encryption.
5. A local configuration declares `env=production` while enabling framework debugging and the sensitive debug footer. Production did not visibly show the footer, but the application does not enforce this invariant itself.
6. The production header scan currently scores **D**, missing HSTS, CSP, Referrer-Policy, and Permissions-Policy.

These should be treated as a focused hardening programme, not as evidence that the authorization-code design needs to be replaced.

## Findings

### SEC-01 — Missing CSRF protection on browser state changes

Severity: **High**  
STRIDE: Tampering / Elevation of privilege  
OWASP: A01 Broken Access Control; ASVS V4 Access Control

Evidence:

- The project contains no CSRF token, Origin/Referer, or Fetch Metadata validation.
- Browser-authenticated POST handlers include display-name changes, authorization continuation/account switching, setup verification, admin logout, secret rotation, redirect-URI changes, and branding/settings changes (`app/routes.php:27`, `55`, `59`, `95`, `111`, `123-139`).
- `/logout` also mutates state over GET (`app/routes.php:71`).
- `SameSite=Lax` cookies are present, but Sorkos applications share the same registrable domain in production. A compromised or malicious sibling origin can therefore still create **same-site** requests, and GET logout remains directly forgeable.

Recommendation:

- Add a server-generated synchronizer CSRF token to every browser form that changes state and verify it with `hash_equals`.
- Also validate `Origin`, falling back to an exact `Referer` origin check. Treat sibling subdomains as untrusted origins.
- Optionally reject unsafe cross-site requests using `Sec-Fetch-Site` as defence in depth.
- Make logout POST-only. Keep `/token` and `/client/connections/revoke` protected by client authentication rather than browser CSRF tokens.
- Add negative tests for absent, invalid, cross-session, and replayed tokens.

[OWASP explicitly recommends CSRF tokens for all state-changing requests and notes that SameSite is defence in depth, not a general replacement.](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)

### SEC-02 — Request-controlled host is used in emailed login links

Severity: **High if the edge does not overwrite these headers; otherwise Medium**  
STRIDE: Spoofing / Information disclosure  
OWASP: A03 Injection / A04 Insecure Design

Evidence:

- `AuthController::absoluteUrl()` prefers `X-Forwarded-Proto` and `X-Forwarded-Host`, then `Host`, over the configured canonical base URL (`app/controllers/AuthController.php:511-533`).
- That value is used to create the emailed verification URL (`app/controllers/AuthController.php:536-547`).
- The signed flow prevents modification of its payload, but an attacker-controlled host can still place the valid link and flow token on an attacker-controlled origin, creating a phishing/token-disclosure path.

Recommendation:

- Always build external authentication links from the configured canonical `app.base_url`.
- At nginx, overwrite forwarded headers and reject unexpected `Host` values; do not merely pass client-supplied `X-Forwarded-*` headers through.
- If proxy-derived origins are ever required, trust them only when the immediate peer is on an explicit proxy allowlist.
- Add a test asserting that spoofed `Host` and `X-Forwarded-Host` cannot affect an emailed URL.

### SEC-03 — PKCE is absent while public clients are supported

Severity: **Medium now; High if a public client is registered**  
STRIDE: Spoofing / Elevation of privilege  
OWASP: A04 Insecure Design; ASVS V10 OAuth/OIDC

Evidence:

- `ClientService::secretValid()` deliberately accepts public clients without a secret (`app/services/ClientService.php:67-70`).
- The authorization and token flows do not accept, store, or verify `code_challenge`, `code_challenge_method`, or `code_verifier`.
- The current locally registered clients are confidential, limiting the present exploitability, but the schema and service contract allow a future unsafe configuration.

Recommendation:

- Implement PKCE with `S256` and require it for every authorization-code request, including confidential clients; or, as an interim control, reject all non-confidential clients in both administration and runtime validation.
- Bind the challenge to the authorization-code row and compare the verifier at token redemption.
- Document and test downgrade rejection.

[RFC 9700 describes PKCE as protection against authorization-code injection and interception, and OWASP recommends that authorization servers support it.](https://www.rfc-editor.org/rfc/rfc9700.html) [OWASP OAuth guidance](https://cheatsheetseries.owasp.org/cheatsheets/OAuth2_Cheat_Sheet.html)

### SEC-04 — Admin authentication lacks throttling; TOTP secret is plaintext

Severity: **High**  
STRIDE: Spoofing / Elevation of privilege  
OWASP: A02 Cryptographic Failures; A07 Identification and Authentication Failures

Evidence:

- `AdminAuthService::attempt()` performs a lookup, password verification, and TOTP verification with no attempt counter, delay, lockout, IP/account throttling, or audit event (`app/services/AdminAuthService.php:20-39`).
- Setup stores the generated TOTP secret directly in `totp_secret_encrypted` (`app/services/AdminSetupService.php:47-54`), and authentication passes that same database value directly to the verifier (`app/services/AdminAuthService.php:32`).
- A database read compromise therefore defeats the independence of the second factor.

Recommendation:

- Rate-limit by account and a privacy-preserving network bucket, add exponential backoff, and alert on repeated failures. Avoid a permanent attacker-triggerable lockout.
- Encrypt TOTP secrets with authenticated encryption such as libsodium XChaCha20-Poly1305. Keep the wrapping key outside the database and separate from OAuth/email signing keys. Plan key rotation.
- Require recent reauthentication for client-secret rotation and other high-impact admin operations.
- Record success, failure, lockout/backoff, TOTP enrollment, and configuration changes without logging passwords, OTPs, or secrets.

[OWASP recommends login throttling](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), and treats OTP material as an authentication secret that should not be stored in plaintext by default ([MFA guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html)).

### SEC-05 — Debug behavior is not fail-closed for production

Severity: **High deployment risk; production footer not observed**  
STRIDE: Information disclosure  
OWASP: A05 Security Misconfiguration

Evidence:

- The ignored local configuration currently declares `env=production` while enabling both framework debug output and the debug footer.
- The checked-in defaults also enable debug and the footer (`app/config/config.php:24-26`).
- `DebugFooterService` can expose user/session/database identifiers, pending OAuth state, challenge identifiers, and hash prefixes; it is rendered globally (`app/views/_layout_end.html:29`).
- A harmless array-shaped `client_id[]` request against the local `/authorize` route produced HTTP 500 with absolute paths and a stack trace.
- The production landing page inspected on 2026-08-08 did **not** visibly contain the debug footer. Actual production configuration files were not available for review.

Recommendation:

- Refuse application startup when `env=production` and either debug flag is enabled.
- Make checked-in defaults secure (`debug=false`, `debug_footer=false`) and opt in locally.
- Use generic 4xx/5xx pages in production, with correlation IDs and detailed server-side logging.
- Never include raw session IDs or authentication-flow material in a page footer, even locally when real accounts/data are used.

### SEC-06 — Production security headers are incomplete

Severity: **Medium**  
STRIDE: Tampering / Information disclosure  
OWASP: A05 Security Misconfiguration

Evidence:

- The supplied production scan returned grade **D** on 2026-08-08.
- Present: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, and `Cache-Control: no-cache, no-store, must-revalidate`.
- Missing: `Strict-Transport-Security`, `Content-Security-Policy`, `Referrer-Policy`, and `Permissions-Policy`.
- Production advertises `X-Powered-By: Fat-Free Framework` and uses obsolete `X-XSS-Protection: 1; mode=block`.
- Local Apache additionally discloses its detailed Apache/OpenSSL/PHP version string.

Recommendation:

- Add and test a CSP. A suitable starting direction is `default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'`, then explicitly allow only the image/style/font sources actually required. Remove inline styles/scripts or use nonces/hashes before enforcing.
- Add `Strict-Transport-Security: max-age=31536000`; add `includeSubDomains` only after confirming every affected Sorkos subdomain is permanently HTTPS-capable. Add `preload` only after meeting preload requirements and accepting its operational consequences.
- Add `Referrer-Policy: no-referrer` for the authorization host, or at minimum `strict-origin-when-cross-origin`.
- Add a restrictive `Permissions-Policy`, disabling unused capabilities.
- Set `X-XSS-Protection: 0`, retain `nosniff`, and use CSP `frame-ancestors` in addition to or instead of X-Frame-Options.
- Suppress framework and detailed server-version banners.

[Production scan](https://securityheaders.com/?q=https%3A%2F%2Fauth.sorkos.net&followRedirects=on) · [OWASP HTTP header guidance](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html) · [OWASP Secure Headers Project](https://owasp.org/www-project-secure-headers/)

### SEC-07 — Non-scalar parameters cause 500 responses and local stack disclosure

Severity: **Medium**  
STRIDE: Denial of service / Information disclosure  
OWASP: A05 Security Misconfiguration

Evidence:

- A local request using `client_id[]=local-glyph` returned HTTP 500 because request values are frequently cast directly to strings.
- The debug response exposed absolute paths and a call stack.
- The same pattern exists across multiple GET and POST reads, so malformed arrays can create noisy error-log traffic and inconsistent 500 behavior.

Recommendation:

- Introduce one request accessor that accepts only scalar strings, rejects arrays/objects, enforces maximum lengths, and produces a controlled 400 error.
- Apply it consistently to OAuth, account, admin, housekeeping, and API parameters.
- Add a small parameter-shape fuzz suite covering array nesting, duplicate keys, invalid UTF-8, oversized inputs, and unexpected content types.

### SEC-08 — TRACE is enabled locally

Severity: **Low/Medium**  
STRIDE: Information disclosure  
OWASP: A05 Security Misconfiguration

Evidence:

- `TRACE /` on `auth.test` returned HTTP 200 and reflected a harmless test header and Cookie marker.

Recommendation:

- Disable TRACE at the web server (`TraceEnable off` for Apache) and verify production nginx returns 405/501.
- Restrict methods per endpoint and return an explicit `Allow` header where appropriate.

### SEC-09 — Security audit logging is designed but unused

Severity: **Medium**  
STRIDE: Repudiation  
OWASP: A09 Security Logging and Monitoring Failures

Evidence:

- `auth_audit_log` exists in the schema (`sql/schema.sql:142`), but no application code writes security events to it.
- Admin authentication/configuration, authorization failures, session revocation, client-secret rotation, and client-connection revocation therefore lack an application-level attributable trail.

Recommendation:

- Implement a single audit service and record who, what, target, outcome, timestamp, request correlation ID, and a pseudonymized source indicator.
- Never log passwords, OTPs, authorization codes, client secrets, session tokens, or full email-flow payloads.
- Define retention and access controls appropriate to GDPR data minimization; protect the audit trail from ordinary admin modification.

[OWASP recommends application-level logging of authentication, authorization, session, validation, and high-risk administrative events.](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)

### SEC-10 — Secret fallbacks and key reuse fail open

Severity: **Medium**  
STRIDE: Spoofing / Tampering  
OWASP: A02 Cryptographic Failures

Evidence:

- Email-code HMAC and signed-flow code use the general `app.auth_secret`.
- If it is empty, both fall back to the database password and ultimately the predictable service directory (`app/services/EmailAuthService.php:386-394`; `app/controllers/AuthController.php:630-639`).
- Housekeeping similarly falls back from its dedicated secret to the general auth secret (`app/controllers/TasksController.php:23-27`).

Recommendation:

- Fail startup when required cryptographic keys are absent or too short.
- Use purpose-separated keys (for example, HKDF-derived `email-code`, `email-flow`, and other contexts) and a distinct housekeeping credential.
- Document rotation and allow a short verification overlap for signing-key changes where required.

### SEC-11 — Housekeeping accepts secrets in GET URLs

Severity: **Medium**  
STRIDE: Information disclosure / Tampering

Evidence:

- `/internal/cleanup` accepts GET and POST (`app/routes.php:47`).
- Its secret may be supplied in `GET.secret` (`app/controllers/TasksController.php:46-56`), exposing it to access logs, browser history, monitoring, and referrer propagation.

Recommendation:

- Make the route POST-only and accept authentication only in an `Authorization` or dedicated header.
- Use a dedicated secret, rate-limit the endpoint, audit every invocation, and restrict it at nginx by source network or mTLS if the scheduler permits.

### SEC-12 — Token API responses need explicit cache policy

Severity: **Low/Medium**  
STRIDE: Information disclosure

Evidence:

- `ApiController::json()` sets only the JSON content type (`app/controllers/ApiController.php:111-115`).
- The token response contains user identity/profile data. Browser HTML happened to receive no-store headers through PHP session behavior, but API confidentiality should not depend on that side effect.

Recommendation:

- Explicitly set `Cache-Control: no-store` and `Pragma: no-cache` on `/token`, authentication errors, email verification responses, and other sensitive endpoints.

### SEC-13 — Stored IP/User-Agent hashes are weak pseudonyms and currently unused

Severity: **Low/Medium; privacy relevant**  
STRIDE: Information disclosure

Evidence:

- Session metadata uses plain SHA-256 for IP address and user agent (`app/services/SessionService.php:173-176`).
- The values are stored but not used to enforce sessions. IPv4 hashes are inexpensive to reverse by enumerating the small address space.

Recommendation:

- Remove the fields if there is no documented security purpose.
- If retained for fraud/audit correlation, HMAC them with a separate rotating key, shorten retention, document the lawful purpose, and do not hard-bind sessions to IP addresses.

### SEC-14 — Scope and URL policy should be explicit

Severity: **Low/Medium**  
STRIDE: Information disclosure / Elevation of privilege  
OWASP: A04 Insecure Design

Evidence:

- Arbitrary scope strings are accepted and persisted; only `email` and `profile` currently affect the returned data.
- Missing scope defaults to `profile email` (`app/controllers/AuthController.php:35`), which is not the least-privilege default.
- Client configuration can accept absolute HTTP URLs. That is useful locally but should not be possible for production clients.

Recommendation:

- Maintain a server-supported scope registry and per-client allowlist; reject unknown or unauthorized scopes.
- Prefer the minimum useful default, and show the exact data release before approval.
- Require HTTPS for production redirect, branding, and account-deletion URLs, with narrow loopback/local-development exceptions.

### SEC-15 — Session configuration relies on external PHP settings

Severity: **Low/Medium**  
STRIDE: Spoofing  
OWASP: A07 Identification and Authentication Failures

Evidence:

- `session_start()` is called before the application explicitly sets session-cookie and strict-mode policy (`index.php:28-30`).
- Observed local and production cookies were `Secure`, `HttpOnly`, and `SameSite=Lax`, which is positive, but this currently depends on PHP/web-server configuration.
- Admin login regenerates the PHP session ID; the central email-login transition should be covered by an explicit regression test as well.

Recommendation:

- Before `session_start()`, explicitly enable `session.use_strict_mode`, `session.use_only_cookies`, Secure, HttpOnly, SameSite, and an appropriate host-only cookie path/name.
- Regenerate the PHP session ID after every authentication or privilege transition and invalidate the prior ID.

[OWASP recommends strict session management and session-ID renewal after authentication or privilege changes.](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)

### SEC-16 — Vendored framework code is outside dependency auditing

Severity: **Low/Medium**  
STRIDE: Tampering / Elevation of privilege  
OWASP: A06 Vulnerable and Outdated Components; A08 Software and Data Integrity Failures

Evidence:

- `composer audit --locked` returned no advisories or abandoned Composer packages.
- Fat-Free Framework is vendored under `lib/f3`, so Composer’s audit does not cover it or automatically expose it in a Composer SBOM/update workflow.
- Swagger UI is similarly shipped as a public vendored asset.

Recommendation:

- Track exact versions and provenance for every vendored dependency, subscribe to upstream security releases, and include them in an SBOM/dependency-scanning process.
- Prefer package-managed or reproducibly pinned updates where practical.

## STRIDE summary

| Threat | Principal controls observed | Material gaps |
|---|---|---|
| Spoofing | Random server-side sessions, hashed client secrets, email verification, admin password + TOTP | PKCE absent, admin throttling absent, TOTP plaintext, implicit PHP session policy |
| Tampering | Exact redirect matching, signed email flow, prepared SQL, single-use codes | CSRF absent, request host trusted for emailed URLs, secret fallbacks |
| Repudiation | Connection timestamps and schema for audit events | Audit table unused; limited attributable security events |
| Information disclosure | Scope-filtered token output, HttpOnly/Secure cookies, no-store HTML | Missing CSP/HSTS/referrer policy, debug fail-open, framework banners, reversible IP hashes |
| Denial of service | Email challenge attempt and resend limits | Malformed parameter 500s, no admin throttling, no load/rate test performed |
| Elevation of privilege | Per-user query scoping, client-authenticated connection revocation | Admin CSRF exposure; future public clients unsafe without PKCE |

Microsoft’s original STRIDE categories and definitions are documented in its [threat modelling guidance](https://learn.microsoft.com/en-us/windows-hardware/drivers/ifs/designing-with-security-threat-models).

## Positive controls verified

- Redirect URIs are looked up by exact client/URI equality rather than prefix or substring matching.
- `state` is required for authorization requests and returned to the client.
- Authorization codes use cryptographic randomness, are SHA-256 hashed at rest, expire after ten minutes, are client/redirect-bound, and are consumed in a transaction.
- Confidential client secrets use password hashing and are required at `/token` and connection revocation.
- Email selectors and codes are not stored in plaintext; codes expire, have five attempts, and have resend/email/client limits.
- Identity email is marked verified only after successful code verification.
- SQL is generally prepared; reviewed dynamic column selection is allowlisted.
- Account and Glyph data operations apply the authenticated user identifier to ownership queries.
- Glyph validates and consumes OAuth `state`, exchanges the code server-side with a confidential secret, regenerates its session ID, and has CSRF checks on its own mutating forms/APIs.
- No exploitable reflected/stored XSS, SQL injection, open redirect, IDOR, or permissive authenticated CORS response was identified in the reviewed/tested paths.

## Test evidence and limitations

- Production SecurityHeaders scan: grade D; report timestamp 2026-08-08 10:37 UTC.
- Local harmless probes verified exact invalid-redirect rejection, unauthenticated admin redirection, GET logout, reflected TRACE, controlled callback rejection in Glyph, and the malformed-array 500 behavior.
- `composer audit --locked --format=json`: no known advisories or abandoned Composer dependencies.
- Test suite with live local database enabled: **30 passed, 176 assertions**. Test-created records use random markers and are removed by test cleanup.
- No production login, email send, credential attack, account mutation, high-volume fuzzing, denial-of-service test, TLS protocol/cipher scan, infrastructure/configuration review, database privilege review, mail deliverability/security review, or source review of other production clients was performed.
- Production nginx may already sanitize forwarded headers. That configuration must be verified before finalising SEC-02 severity.

## Recommended delivery order

1. **Immediate:** enforce production debug-off; canonicalize/reject Host and forwarded headers; add CSRF protection; remove GET logout and GET/query-secret housekeeping.
2. **Next:** add admin throttling/auditing and encrypt TOTP secrets; implement PKCE or prohibit public clients.
3. **Edge hardening:** add CSP/HSTS/Referrer-Policy/Permissions-Policy, disable TRACE, suppress banners, explicitly no-store sensitive API responses.
4. **Robustness/privacy:** scalar request validation, scope allowlists, explicit session configuration, HMAC/remove network metadata, HTTPS-only production URL policy.
5. **Operational maturity:** wire the audit table, add alerting/retention policy, track vendored dependencies, and put security regression tests into CI.

After the first two groups are implemented, repeat this assessment with authenticated browser tests and a narrowly scoped production configuration review. OWASP ASVS is the right acceptance checklist for that pass: [OWASP Application Security Verification Standard](https://owasp.org/www-project-application-security-verification-standard/).
