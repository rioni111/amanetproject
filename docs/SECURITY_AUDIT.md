# Security Audit — Lexora Workspace (Phase 7)

**Scope and nature.**
- This is the development team's own security review of the application at `feature/phase-7-final-qa-delivery`,
  done in November 2026 with code review, automated tests and live checks on the local stack.
- It is **not** an independent penetration test or certification, and no external auditor was involved.
- Production hosting, DNS, TLS and the SMTP provider were not available, so they were not tested.

Findings referenced as `P7-xxx` are detailed in the [issue register](PHASE_7_ISSUE_REGISTER.md).

## 1. Methodology

1. **Threat modelling.** Assets and attackers (section 2).
2. **Code review.** The trust boundaries: Sanctum session and middleware, policies and scoped bindings, the public link
   resolvers, upload and signing services, e-mail services, notifications, exception rendering and headers.
3. **Automated evidence.**
   - 253 PHPUnit tests, including multi-process race tests and failure injection.
   - The Playwright suite (Phases 1–7), including token, cross-matter and accessibility checks.
4. **Live checks.**
   - Response headers of the web app and API.
   - Secret scans of tracked files.
   - Dependency audits.
   - A manual end-to-end run with a real SMTP outage.

## 2. Threat model

| Asset | Threat | Primary controls |
| --- | --- | --- |
| Client PDFs (originals, uploads, signed) | unauthorised download, cross-matter access, public exposure | private disk outside `public/`; admin-only API with scoped bindings (matter → document → version); SHA-256 checked on read |
| Secure links (upload / signing) | guessing, leakage, reuse, wrong purpose, resurrection | 256-bit CSPRNG tokens; SHA-256 stored only; purpose-scoped resolvers; expiry, one use, revocation, rotation; fragment + header transport; IP lockout |
| Signed documents and evidence | forgery, alteration, wrong document or signer | one signer per PDF (a partial unique index allows one active request per document; the service enforces the rest); pinned immutable source version; immutable signed version and evidence (triggers); server time; consent versioned |
| Administrator session | theft, CSRF, brute force | HTTP-only cookie, `SameSite=Lax`, `Secure` in production; CSRF on stateful API; login rate limit; session regeneration |
| E-mail | arbitrary recipients, header injection, spoofed success, token leakage | server-chosen recipients (payload fields prohibited); address validation and DB CHECK; status only from the transport; no queue, no stored token |
| Notifications and internal notes | cross-user or public access | per-user relation (404 otherwise); notes only on admin routes; tokens rejected (401) |
| Secrets (DB, APP_KEY, SMTP) | committed or logged | `.env` ignored; status endpoint omits credentials; transport errors stored as categories only |

Attackers considered:
- an anonymous internet user;
- a client holding one valid link;
- a person who receives a forwarded e-mail;
- a malicious file uploader;
- a curious administrator of another matter (single-role system, but scoping is still enforced);
- a database reader (tokens are not recoverable from hashes).

## 3. Authentication

**Session and CSRF.**
- Sanctum SPA session: the cookie is HTTP-only, `SameSite=Lax`, and `Secure` when `APP_ENV=production`.
  P7-006 removes the template line that would have disabled `Secure`.
- `SESSION_ENCRYPT=true` in the template.
- CSRF applies to every state-changing `/api` call (stateful domains only).
- Login: 5 failed attempts per email+IP per minute, plus 20 login requests per minute per IP; the session is
  regenerated after login.

**Responses and boundaries.**
- Errors are generic ("These credentials do not match our records").
- Guests get 401 JSON, never a redirect.
- The `admin` middleware and policies sit on every protected route. Authorisation is server-side; hiding a button is
  never the control.
- Session ids never appear in URLs or logs. Upload and signing tokens travel in a header, never in the URL path.

**CORS.**
- Only the configured frontend origins, with credentials allowed.
- A foreign `Origin` is answered with the configured origin, so browsers refuse it. Verified live.

**Evidence:** Phase 1/2 authentication tests, `SecurityHeadersTest`, the Journey H E2E test, and the live check in
FINAL_QA_REPORT section 5.

## 4. Authorisation

**Scoped bindings.** Every nested id is resolved through its parent: matter → document → version, request,
signing request, link, history. Another matter's id is a 404.

**Verified in Phase 7** (`phase7-journeys.spec.ts`, Journey H) — all of these answer 404:
- matter A's document, its download, request, signing request and e--mail history addressed through matter B;
- a signing token on the upload API;
- an upload token on the signing API.

Upload and signing tokens on administrator, notes, notifications and dashboard endpoints answer 401, and public
responses contain no matter number, title, client name or administrator name.

**Earlier suites.**
- Notifications are scoped per user (Phase 6).
- Internal notes are admin-only (Phase 2).
- Archived matters are read-only, with links revoked (Phases 3–5).
- Signed versions are immutable through a trigger (Phases 3 and 5).

## 5. Secure links

| Property | Implementation | Evidence |
| --- | --- | --- |
| Generation | `random_bytes(32)`, base64url (43 chars) | Phase 4 `SecureLinkTest` |
| Storage | SHA-256 only (`secure_access_links.token_hash`, CHECK hex-64); Phase 7 reservations also hash-only | scans in `CommunicationSecurityTest`, `EmailLinkRecoveryTest` |
| Expiry / revocation / rotation | row state checked on every request; lifecycle closures explained, manual revocation indistinguishable from unknown | Phase 4/5 tests |
| Purpose isolation | separate resolvers per purpose | Phase 4/5 tests, Journey H |
| One use | consumed in the submission/signature transaction; partial unique index = one usable link per request | Phase 4/5 race tests |
| Rate limiting | per-IP view/submit limits, 20 failed lookups → 15-minute lockout | Phase 4/5 tests |
| Transport | URL fragment (never sent to a server) + `X-Upload-Token`/`X-Signing-Token` header; `Referrer-Policy: no-referrer`, `no-store`, `noindex` | Phase 4 tests, live headers |
| Logs / activity / e-mail records | no token or URL anywhere; hash never outside link tables (with `MAIL_MAILER=smtp` as in `.env.example`; Laravel's `log` mailer, the default when `MAIL_MAILER` is unset, would write e-mail bodies including links to the log) | `CommunicationSecurityTest` (table and log scans) |
| Crash window (Phase 6 §16) | **fixed** — reservation + activation on first use (P7-001) | `EmailLinkRecoveryTest` |

**Residual risk.** A link is a bearer credential. Anyone who has a forwarded e-mail can use it until it is used,
expires or is revoked. This is the account-free design the assignment requires.

## 6. PDF uploads and storage

**Validation and storage.**
- `PdfInspector` checks the MIME type with finfo, the `%PDF-` signature, completeness (EOF/xref), encryption and the
  size limit (`DOCUMENTS_MAX_UPLOAD_MB`, default 20).
- File names are sanitised for display only. Storage paths are generated server-side
  (`{matter}/{document-uuid}/{version-uuid}.pdf`), and the client's name never becomes a path.

**Failure handling and integrity.**
- Files are staged and the SHA-256 is verified before the database transaction.
- On failure, the new file is deleted and earlier versions are untouched.
- Versions are immutable (trigger), and `(document, matter)` composite foreign keys keep documents in their matter.

**Not implemented: antivirus scanning.**
- Uploaded PDFs are validated structurally but not scanned for malware.
- Recommendation for production: scan at the storage layer (for example ClamAV on the server, or the hosting
  provider's scanning), keep PDFs downloadable only by administrators (already the case), and open client files in a
  PDF viewer with JavaScript disabled.
- No paid scanning service was added (assignment rule).

## 7. PDF signing

**Verified by the Phase 5 tests, which still pass:**
- one signer per PDF;
- the source version is pinned and immutable;
- placement is converted to PDF points on the displayed page, including rotation;
- consent is versioned and stored verbatim, and timestamps come from the server;
- the signed output is re-parsed and verified before storage, then stored as a new version with SHA-256;
- the original is unchanged;
- a second signature of a signed version is refused, and a revoked or consumed token cannot complete a signature;
- races (two completions, signing vs archive, signing vs revocation) leave exactly one signed version;
- the temporary file name stays reserved during generation, and failure cleanup removes partial files.

**Unsupported PDFs.** These are refused with an explanation (422 `pdf_unsupported`), and nothing is flattened or
altered:
- compressed cross-reference streams;
- forms;
- annotations;
- existing digital signatures;
- encryption.

The message tells the administrator to export a compatible copy, for example "Print to PDF".

**Legal honesty.** Phase 7 reviewed every place that describes the signature:
- UI labels;
- the evidence card;
- the client page notice;
- the consent text;
- the PDF caption "Electronically signed by …";
- the README and this documentation.

None claims qualified, advanced, certificate-based, PAdES or eIDAS status. The evidence card states the identity
basis: possession of the link and the confirmed name, with no independent identity verification.

## 8. E-mail

- **Credentials and sender.** Credentials live only in the environment; the status endpoint omits the username and
  password. The sender comes from configuration only.
- **Recipient and injection.** The recipient is chosen by the server: the request's client, or the immutable signer
  snapshot. Payload recipient and delivery fields answer 422. Addresses are validated (no CR/LF or `<>,;"`) and also
  checked by a database CHECK.
- **Content.** HTML is escaped, and the plain-text part is plain text. Subjects carry no matter or document details,
  and the correction reason is never e-mailed.
- **Rate limits.** Same-type cooldown of 60 s; 20 sends per minute per administrator; reminders capped at 3 per request
  with 24 h between them and idempotent slots; the scheduler is protected by `withoutOverlapping` and `onOneServer`.
- **Honest outcomes.** "Accepted by the mail server" is never shown as delivered. A failure is never shown as Sent. A
  failed resend keeps the previous link working (Phase 6 tests). The Phase 6 correction-cooldown regression test still
  passes.
- **Phase 7.** P7-001/002/003: no lost links after a crash, an honest `email_unrecorded` response, and sanitised logs.

## 9. HTTP security headers

| Header | Web app (Next.js) | API (Laravel) |
| --- | --- | --- |
| Content-Security-Policy | `default-src 'self'`; `connect-src 'self' <API>`; `img-src 'self' data:`; `script-src 'self' 'unsafe-inline'`; `style-src 'self' 'unsafe-inline'`; `worker-src 'self'`; `object-src 'none'`; `frame-src 'none'`; `frame-ancestors 'none'`; `base-uri 'self'`; `form-action 'self'`; `upgrade-insecure-requests` over HTTPS | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` (PDF responses: their own `default-src 'none'; sandbox`) |
| X-Frame-Options | DENY | DENY |
| X-Content-Type-Options | nosniff | nosniff |
| Referrer-Policy | `strict-origin-when-cross-origin`; `no-referrer` on `/client-upload` and `/client-sign` | `no-referrer` |
| Strict-Transport-Security | when served over HTTPS | production + HTTPS |
| Permissions-Policy | camera, microphone, geolocation, payment, usb disabled | — |
| Cross-Origin-Opener-Policy | same-origin | — |
| Cache-Control | Next.js defaults (`no-store` on dynamic pages) | `no-store, private` (unless set deliberately) |
| X-Powered-By | removed (`poweredByHeader: false`) | removed |

**Why `'unsafe-inline'` remains for scripts.**
- The app uses Partial Prerendering. This Next.js version's own CSP guide states that nonce-based CSP requires dynamic
  rendering and is incompatible with PPR. Hash-based SRI is still experimental.
- Removing `'unsafe-inline'` would mean giving up PPR (static shells and prefetching).
- The residual XSS risk is limited:
  - React escapes all output;
  - no `dangerouslySetInnerHTML`, `innerHTML` or `eval` exists in `src` (verified by search);
  - there are no third-party scripts, all other sources are restricted, and the API responses themselves are deny-all.

**Accepted risk.** Revisit if PPR is dropped or SRI becomes stable.

## 10. Dependency review

| Audit | Result |
| --- | --- |
| `composer audit` | no advisories |
| `npm audit --omit=dev` (runtime) | 0 vulnerabilities |
| `npm audit` (all) | **Before:** 15 package entries (6 moderate, 9 high) from 2 advisories (`minimatch`, `picomatch`), all in ESLint's toolchain. **After** `npm audit fix` (lockfile only): the `minimatch` advisory is resolved. **Actual output:** 14 entries, all high, 0 moderate — one advisory, `picomatch` ReDoS GHSA-fc5q-39xc-vjpf, listed once for each dependent package. No patched version exists; it is not shipped to users (P7-007) |
| Lockfile | `npm ci` reproducible; `composer validate` passes |
| New dependency | `@axe-core/playwright` 4.14.0 (dev only, exact pin, published 2026-10-15) |

## 11. Secrets and configuration

Scan of all tracked files:
- no `.env` files;
- no `APP_KEY` value, private keys or token-shaped links;
- no PDFs, storage files or verification logs.

Password values in tracked files are all documented, development-only values:
- the local Docker database default (`DB_PASSWORD=amanet` in `backend/.env.example`, `POSTGRES_PASSWORD: amanet` in
  `docker-compose.yml`);
- the demo administrator password (`DEMO_ADMIN_PASSWORD` in `backend/.env.example`, `NEXT_PUBLIC_DEMO_PASSWORD` in
  `frontend/.env.example`, also shown in the README);
- the PHPUnit-only `DEMO_ADMIN_PASSWORD` in `backend/phpunit.xml`;
- `null` placeholders.

`NEXT_PUBLIC_*` values are compiled into the browser bundle, so the demo password must not be set for a production
build (see below).

Demo credentials are documented as development-only:
- the seeders refuse to run in production;
- `NEXT_PUBLIC_DEMO_*` must be unset for production builds;
- the production checklist is in [security-credentials.md](security-credentials.md) section 4 and
  [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).

## 12. Issues fixed in Phase 7

P7-001 (P1); P7-002, P7-003, P7-004, P7-006, P7-008, P7-009 and P7-015 (P2); P7-005, P7-010, P7-011, P7-013 and
P7-014 (P3). Details are in the register.

P7-015 matters for production: without `TRUSTED_PROXIES`, a server behind a TLS-terminating proxy would not recognise
HTTPS, so it would send no HSTS and generate `http://` URLs. Only the configured proxy is trusted; when the setting is
unset, a client cannot claim HTTPS by sending `X-Forwarded-Proto`.

## 13. Remaining risks

| Risk | Rating | Mitigation / recommendation |
| --- | --- | --- |
| Bearer links: a forwarded e-mail grants access | Medium (inherent to the account-free requirement) | short expiry, one use, revocation, rotation; consider an optional one-time code for sensitive matters |
| `script-src 'unsafe-inline'` | Low–medium | see section 9 |
| No malware scanning of uploads | Medium | server-side scanning in production; admin-only access |
| No independent signer identity verification | Inherent; documented | add identity checks or a qualified provider where the law requires it |
| E-mail delivery beyond SMTP acceptance not observable | Low | provider webhooks (not implemented) |
| Dev-tool advisories (`picomatch`) | Low (dev only) | update when a patched release exists |
| Production configuration not exercised | Not verified | follow DEPLOYMENT_GUIDE; run its smoke tests |

## 14. Evidence index

| Area | Evidence |
| --- | --- |
| Backend tests | `backend/tests/Feature/**` (Phase7: `EmailLinkRecoveryTest`, `SecurityHeadersTest`, `QueryBudgetTest`, `WorkspaceListingTest`, `DemoDocumentsSeederTest`) |
| E2E tests | `frontend/e2e/**` (Phase7: `phase7-journeys`, `phase7-accessibility`, `phase7-mobile`, `phase7-cross-browser`) |
| Final results | [FINAL_QA_REPORT.md](FINAL_QA_REPORT.md) and the Windows verification log listed there |
