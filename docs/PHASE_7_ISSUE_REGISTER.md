# Phase 7 Issue Register

Issues found during the Phase 7 audit ([audit plan](PHASE_7_AUDIT_PLAN.md)).

**Summary: 0 × P0, 1 × P1 (fixed), 7 × P2 (7 fixed), 7 × P3 (5 fixed, 2 documented).**

All P0/P1 findings are fixed and regression-tested. Commits are on `feature/phase-7-final-qa-delivery`.

| ID | Title | Severity | Status |
| --- | --- | --- | --- |
| P7-001 | E-mail accepted by SMTP but its link never registered (crash/DB failure window) | **P1** | Fixed |
| P7-002 | Finish-transaction failure: generic 500, SQL bindings could reach the log | P2 | Fixed |
| P7-003 | Ambiguous transport outcome (timeout, dropped connection): a delivered link could never work | P2 | Fixed (with P7-001) |
| P7-004 | API responses without security headers; `X-Powered-By` leaked the PHP version | P2 | Fixed |
| P7-005 | Web CSP without `object-src`/`frame-src`/`worker-src`; no COOP/HSTS | P3 | Fixed (`'unsafe-inline'` kept, risk accepted) |
| P7-006 | `.env.example` explicitly set `SESSION_SECURE_COOKIE=false` | P2 | Fixed |
| P7-007 | npm advisories in development tooling | P3 | 6 fixed, 14 documented (dev-only, no patch exists) |
| P7-008 | Archived matters dominated the default matter list and the dashboard | P2 | Fixed |
| P7-009 | Dialogs opened from state did not return keyboard focus on close (WCAG 2.4.3) | P2 | Fixed |
| P7-010 | E-mail history split the recipient address across lines | P3 | Fixed |
| P7-011 | Demo data had no documents, requests or signatures | P3 | Fixed |
| P7-012 | PHP OPcache disabled in the local Laragon CLI: ~220–380 ms per API request | P3 (environment) | Documented (shared `php.ini` not changed) |
| P7-013 | ESLint scanned ad-hoc `.next-*` build directories | P3 | Fixed |
| P7-014 | A dropped SMTP connection was sometimes reported as "could not be sent" instead of "could not be reached" | P3 | Fixed |
| P7-015 | Behind a TLS-terminating proxy, Laravel did not recognise HTTPS (no trusted-proxy setting) | P2 | Fixed |

Observed but not an issue:
- a one-off hydration warning on `/login` while the dev server was reloading `next.config.ts` (not reproducible on a
  stable server, see FINAL_QA_REPORT §5);
- the expected `401` console entry when the sign-in page checks for an existing session.

---

### P7-001 — E-mail accepted by SMTP but its link never registered

| | |
| --- | --- |
| Component | `DocumentRequestEmailService`, `SigningEmailService`, public link resolvers |
| Severity | **P1**: the e-mailed link is unusable for the client ("This link is not valid") |
| Reproduction | send a request or invitation e-mail; make the finish transaction fail after the SMTP server accepted the message (database error, process killed). Reproduced in PHPUnit with a real PostgreSQL trigger that rejects the link insert |
| Root cause | Phase 6 stored the link hash only in the finish transaction, after SMTP acceptance; the token existed only in memory and in the sent message. Losing that transaction lost the only record of the hash |
| User impact | the client cannot upload or sign through the e-mail they received; the delivery shows "sending" and later "interrupted"; the administrator must resend, and the client's first e-mail stays dead |
| Security / integrity | none (fail-closed); availability issue |
| Fix | **reserve the hash before sending**, in the claim transaction (`email_link_reservations`; hash only, never the token). The reservation is not a link: no index, query or resolver treats it as usable. When a public endpoint receives a token that matches no link but a **pending** reservation, possession of the token proves the e-mail arrived, and `EmailLinkActivationService` registers the link exactly as a normal acceptance would, under the same locks: the previous link is replaced; a closed request gets an already-closed link; signing goes Ready → Sent. A delivery still `sending` becomes `accepted`; a final outcome (`failed / interrupted`) is never rewritten. The event is recorded as `email.link_activated`, and the e-mail history shows "the client opened the link from this e-mail". Never activated: discarded reservations (definite refusal), superseded ones (a later e-mail or generated link) and expired ones (410 `link_expired`) |
| Regression tests | `EmailLinkRecoveryTest` (6 tests): crash with immediate client use; interrupted invitation reported honestly and still signable; newer e-mail and generated link supersede; definite refusal, expiry and cancelled request never activate; reservation holds only a hash and is immutable. Phase 4–6 suites re-run green |
| Evidence | final run: PHPUnit 253/253, Playwright 66/66, Windows verification 14/14 (FINAL_QA_REPORT §2, §15) |
| Status | **Fixed** |

### P7-002 — Finish-transaction failure surfaced as a generic error

| | |
| --- | --- |
| Component | e-mail finish transaction, exception reporting |
| Severity | P2 |
| Root cause | an exception after acceptance propagated as a raw `QueryException`: the administrator saw "Server error", and Laravel's report included the SQL and its bindings (including the link's hash) in `laravel.log` |
| Fix | `EmailDeliveryService::finishAccepted()` catches it, reports only the exception class and delivery id, and answers `500 email_unrecorded`. The message says "accepted by the mail server, recording failed, the link will work when the client opens it". The correction flow shows the same. The frontend shows this message (allow-listed code) |
| Regression test | `EmailLinkRecoveryTest::an_accepted_email_whose_recording_failed_still_gives_the_client_a_working_link` asserts code and message |
| Status | Fixed |

### P7-003 — Ambiguous transport outcomes

| | |
| --- | --- |
| Severity | P2 |
| Root cause | a timeout or dropped connection may happen after the server accepted the message; Phase 6 recorded `failed` and the token was lost |
| Fix | part of P7-001: reservations are discarded only for definite refusals (`authentication`, `recipient_rejected`, `sender_rejected`, `configuration`, `invalid_recipient`); for `connection`, `timeout`, `unknown` and `interrupted` they stay pending until used, superseded or expired |
| Regression test | `definite_refusals_expiry_and_closed_requests_never_activate_a_link`, `an_interrupted_signing_invitation_is_reported_honestly_and_still_signable` |
| Status | Fixed |

### P7-004 — API security headers

| | |
| --- | --- |
| Severity | P2 |
| Reproduction | `GET /api/...`: no `X-Content-Type-Options`, `X-Frame-Options` or CSP, `Cache-Control: no-cache, private`, and `X-Powered-By: PHP/8.3.35` |
| Fix | global `SecurityHeaders` middleware sets `nosniff`, `DENY`, `Referrer-Policy: no-referrer`, a deny-all CSP for JSON, `no-store, private` and HSTS (production over HTTPS only), and removes `X-Powered-By`. Headers set on purpose are kept: the PDF sandbox CSP and the public `no-referrer` |
| Regression test | `SecurityHeadersTest` (3 tests); live check on the dev API |
| Status | Fixed |

### P7-005 — Web CSP hardening

| | |
| --- | --- |
| Severity | P3 |
| Fix | adds `object-src 'none'`, `frame-src 'none'`, `worker-src 'self'`, `Cross-Origin-Opener-Policy: same-origin` and a wider `Permissions-Policy`. HSTS and `upgrade-insecure-requests` are added when the API URL is HTTPS |
| Not changed | `script-src 'unsafe-inline'`. The app uses Partial Prerendering (`cacheComponents`); this Next.js version documents nonce-based CSP as incompatible with PPR because prerendered shells carry inline bootstrap scripts. Mitigations: no `dangerouslySetInnerHTML`, `innerHTML` or `eval` in `src` (verified), no third-party scripts, everything else locked down. Revisit if PPR is dropped or SRI leaves experimental status |
| Evidence | headers verified on the dev server; the full E2E suite passes under the new policy |
| Status | Fixed (residual risk accepted, see SECURITY_AUDIT §9) |

### P7-006 — Insecure session-cookie value in the environment template

| | |
| --- | --- |
| Severity | P2 |
| Root cause | `SESSION_SECURE_COOKIE=false` in `.env.example` overrides the config default (`Secure` in production). A server set up from the template would send the session cookie over plain HTTP |
| Fix | the line is commented out with an explanation; the deployment guide requires HTTPS |
| Status | Fixed |

### P7-007 — Development-tool advisories

| | |
| --- | --- |
| Severity | P3 |
| Finding | `npm audit`: 15 advisories (6 moderate, 9 high), all in ESLint's toolchain; `npm audit --omit=dev`: 0; `composer audit`: 0 |
| Fix | `npm audit fix` (lockfile only, no major upgrades) removed the 6 moderate `typescript-eslint` advisories. The 14 remaining high ones all come from `picomatch` (ReDoS on crafted glob patterns), reached through `eslint-config-next`. The advisory covers every published version, and the "fix" npm offers is a downgrade to `eslint-config-next@14` |
| Exploitability | none at runtime: ESLint runs only on developer machines with the project's own glob patterns |
| Status | 6 fixed, 14 documented; `verify.ps1` now audits runtime dependencies (`npm audit --omit=dev`, `composer audit`) |

### P7-008 — Archived matters crowded the working views

| | |
| --- | --- |
| Severity | P2 (UX, listed as a known limitation since Phase 1) |
| Reproduction | the development workspace had 15 archived of 24 matters; the default matter list and "Recent matters" showed mostly archived verification matters |
| Fix | new API filter `status=current` (everything except archived). The matter list defaults to it: "All except archived", with "All, including archived" one option away. The dashboard's recent matters exclude archived. Client pages and the documents library still list archived matters. The API's unfiltered default is unchanged |
| Tests | `WorkspaceListingTest`; `navigation.spec.ts` updated deliberately: after "Clear filters" it now expects 9 of 9, and "All, including archived" shows 10 of 10 including the archived "Supplier Framework Agreement" |
| Status | Fixed |

### P7-009 — Focus not returned after closing dialogs

| | |
| --- | --- |
| Severity | P2 (WCAG 2.4.3) |
| Reproduction | open "New matter" with the keyboard, press Escape: focus lands on `<body>` |
| Root cause | Radix returns focus only to its own `<Trigger>`; most dialogs are opened from state |
| Fix | `useReturnFocus` records the focused element in `onOpenAutoFocus` and restores it on close, in the shared `DialogContent` and `AlertDialogContent` |
| Test | `phase7-accessibility.spec.ts` "dialogs …": New matter, Send e-mail, Cancel request confirmation |
| Status | Fixed |

### P7-010 — Recipient address wrapping in the e-mail history

P3. `break-all` on the whole line split "&lt;address&gt;" so `>` landed on its own line. Fixed: only the address may break.

### P7-011 — No demo documents

P3. The seed data stopped at Phase 2 content. Fixed: `DemoDocumentsSeeder` (see FINAL_QA_REPORT §9). Test:
`DemoDocumentsSeederTest` (integrity, all states, idempotency, no usable link without a known token, archived matters
untouched).

### P7-012 — OPcache disabled in the local PHP CLI

| | |
| --- | --- |
| Severity | P3 (environment, not application code) |
| Finding | every request recompiles the framework: `GET /up` 215 ms median and API endpoints 283–376 ms on `php artisan serve`. With OPcache the same requests take 21 ms and 85–110 ms (FINAL_QA_REPORT §7) |
| Decision | not changed locally: Laragon's `php.ini` is shared with other projects on this machine. The deployment guide requires OPcache in production |
| Status | Documented |

### P7-014 — Dropped SMTP connection categorised as "unknown"

| | |
| --- | --- |
| Component | `EmailDeliveryService::categorize()` |
| Severity | P3: the outcome stayed honest ("not sent"), but the reason was vague |
| Reproduction | found by the **first full Phase 7 verification run**: `phase6-communications` "a failed invitation e-mail is reported honestly and can be retried" received "The e-mail could not be sent." instead of "The mail server could not be reached." It passed in Phase 6 and in isolated runs |
| Root cause | the E2E relay's "down" mode accepts the TCP connection and closes it immediately. Depending on timing, Symfony throws a `TransportException` ("closed unexpectedly", categorised as `connection`) or an `UnexpectedResponseException` with **no reply code** ("… but got empty code", code 0), which matched no SMTP code and fell through to `unknown`. A latent Phase 6 flakiness |
| Fix | an unexpected response without any SMTP reply code (< 100) is a connection failure. Real reply codes keep their categories |
| Regression test | `TransportFailureCategoryTest` (both exceptions; 550/535/421/600 unchanged); the failing E2E test re-run 3× green; final full verification |
| Status | Fixed |

### P7-015 — No trusted-proxy configuration

| | |
| --- | --- |
| Severity | P2 (production configuration; found while writing the deployment guide) |
| Root cause | Laravel trusts no proxy by default. Behind nginx/Caddy terminating TLS, every request looks like plain HTTP: no HSTS, `http://` URLs |
| Fix | `TRUSTED_PROXIES` (comma-separated addresses or `*`) enables `X-Forwarded-*` for that proxy only. Unset (local default), nothing forwarded is trusted, so a client cannot claim HTTPS |
| Regression test | `TrustedProxiesTest` (not trusted, trusted, a proxy that is not listed) |
| Status | Fixed |

### P7-013 — ESLint scanned temporary build directories

P3. A production build into `.next-perf` (made for measurements) made ESLint report thousands of problems in
generated files. Fixed: `.next-*/**` added to the ESLint ignores and `.gitignore`.
