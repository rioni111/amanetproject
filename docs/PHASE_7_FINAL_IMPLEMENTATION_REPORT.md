# Phase 7 Final Implementation Report — Security, Quality, UI/UX and Delivery

Branch `feature/phase-7-final-qa-delivery`, created from `main` at `e770e27` (Phases 1–6). It is **not merged and not
pushed**, pending authorisation. All data used is **fictional**.

Companion documents:
- [audit plan](PHASE_7_AUDIT_PLAN.md);
- [issue register](PHASE_7_ISSUE_REGISTER.md);
- [security audit](SECURITY_AUDIT.md);
- [final QA report](FINAL_QA_REPORT.md);
- [deployment guide](DEPLOYMENT_GUIDE.md);
- [demo walkthrough](DEMO_WALKTHROUGH.md);
- [delivery checklist](DELIVERY_CHECKLIST.md).

---

## 1. Executive Summary

Phase 7 audited the complete application (Phases 1–6), fixed what the audit found, added the missing delivery material,
and verified everything again end to end.

**Findings.**
- **0 × P0.**
- **1 × P1, fixed.** An e-mail accepted by the SMTP server could carry a link that never worked, if the process or the
  database failed at that moment. The token's hash is now reserved before sending, and the link is activated when the
  client first uses it.
- **7 × P2, all fixed:**
  - an honest error after a recording failure, and sanitised logs;
  - ambiguous transport outcomes no longer lose the link;
  - API security headers;
  - a secure-cookie template default;
  - trusted-proxy support for HTTPS behind a reverse proxy;
  - archived matters left out of the working views;
  - dialogs return keyboard focus.
- **7 × P3:** five fixed, two documented (development-tool advisories without a patch; local PHP without OPcache). One
  of the fixed ones, a flaky failure category for dropped SMTP connections, was found by the first full verification
  run.

**Additions.**
- Realistic demo documents, requests and signatures.
- Accessibility automation: axe-core reports **0 WCAG A/AA violations** across all page types, dialogs and client
  pages.
- Cross-phase journey tests, 320 px client tests and Firefox/WebKit compatibility checks.
- An N+1 query-budget test, and performance measurements on more than 1,000 matters.
- The complete delivery documentation.

**Final verification.** `verify-windows.bat` on commit `5227473`: **all 14 steps passed**. Results: PHPUnit **253 tests (3,698 assertions)**; Playwright **66 tests** (Chromium desktop + Pixel 7); Pint, ESLint, TypeScript, the production build, `composer audit` and the runtime `npm audit`. The opt-in Firefox/WebKit check passed 2/2.

**Remaining limitations.**
- Signatures are application-level, not qualified or certificate-based.
- Client identity rests on bearer links.
- Some PDFs cannot be signed.
- E-mail status ends at SMTP acceptance.
- SPF/DKIM/DMARC are not configured.
- There is no malware scanning.
- Production has not been exercised.

All are documented in section 26.

## 2. Original Amanet Assignment Mapping

| # | Requirement | Implementation | Evidence | Status |
| --- | --- | --- | --- | --- |
| 1 | Administrator login | Sanctum session, CSRF, throttling | Phase 1 tests, `auth.spec`, keyboard sign-in (manual) | Passed |
| 2 | Client management | CRUD, search, retention-safe delete | Phase 2 tests, Journey A | Passed |
| 3 | Matter management | CRUD, `LEX-YYYY-NNNN`, archive/restore | Phase 2 tests, Journey A | Passed |
| 4 | Statuses, priorities, dates | transitions, priorities, opened/due/closed dates, firm time zone | Phase 2 tests | Passed |
| 5 | Tasks and internal notes | tasks (complete/reopen), admin-only notes | Phase 2 tests, Journey A/H | Passed |
| 6 | Search and filters | matters, clients, documents, requests, signatures; URL state | `phase2-search`, `navigation`, `WorkspaceListingTest` | Passed |
| 7 | PDF document library | private storage, preview, download, search | Phase 3 tests | Passed |
| 8 | Immutable version history | trigger-protected versions, SHA-256 | Phase 3 tests, demo data | Passed |
| 9 | Secure client document requests | hashed, expiring, revocable, purpose-scoped links | Phase 4 tests | Passed |
| 10 | Client uploads without accounts | public page, fragment token, no cookies | Phase 4 tests, 320 px test, manual phone upload | Passed |
| 11 | Review and correction requests | start review, approve exact version, correction → next version | Phase 4/6 tests, Journey B, manual | Passed |
| 12 | Outstanding requirements | request statuses, overdue, dashboard counters, `/document-requests` | Phase 4 tests, dashboard | Passed |
| 13 | Administrator electronic signing | drawn/typed, placement, consent, evidence | Phase 5 tests | Passed |
| 14 | Client signing without accounts | secure link, review, draw/type, place, consent | Phase 5 tests, cross-browser, manual phone signing | Passed |
| 15 | One designated signer per PDF | DB constraints, partial unique index | Phase 5 tests | Passed |
| 16 | Signing invitation delivery | SMTP e-mail (or Copy link); Sent only on acceptance | Phase 6 tests, manual | Passed |
| 17 | Signing progress tracking | Ready → Sent → Viewed → Started → Signed, server time | Phase 5/6 tests | Passed |
| 18 | Signed records and evidence | new immutable version, evidence record, checksums | Phase 5 tests, manual checksum check | Passed |
| 19 | Follow-ups and reminders | scheduler, caps, cooldown, idempotency, time zone | Phase 6 tests (incl. 3-process race) | Passed |
| 20 | Administrator notifications | bell, list, read state, deep links | Phase 6 tests, Journey B, manual | Passed |
| 21 | Dated activity and audit history | append-only `activity_events` with actors | throughout; Journey A/B | Passed |
| 22 | Secure access boundaries | scoped bindings, policies, purpose isolation, headers | Journey H, SECURITY_AUDIT | Passed |
| 23 | Usable demonstration environment | one-click Windows setup, start script, Mailpit | README, DEMO_WALKTHROUGH | Passed |
| 24 | Sample data | demo seeders incl. documents/requests/signatures | `DemoDocumentsSeederTest` | Passed |
| 25 | Test coverage | 253 PHPUnit, 66 Playwright (+2 Firefox/WebKit) | section 18–20 | Passed |
| 26 | Repository and installation documentation | README, docs index, deployment guide | — | Passed |
| 27 | Handover instructions | delivery checklist, walkthrough, this report | — | Passed |

## 3. Architecture Overview

```
Browser (Next.js 16 / React 19, TanStack Query)  ──HTTPS, session cookie + CSRF──►  Laravel 12 API (Sanctum, policies,
   /client-upload#token  /client-sign#token  ──header token, no cookies──►        form requests, services, resources)
                                                                                     │
PostgreSQL 16 (FKs, composite FKs, CHECKs, partial unique indexes, triggers) ◄───────┤
Private PDF storage (SHA-256, immutable versions) ◄──────────────────────────────────┤
SMTP (Mailpit locally) ◄── synchronous send after commit; link hash reserved first ──┤
Scheduler (cron → schedule:run → lexora:communications every 5 min) ─────────────────┘
```

The same layering holds throughout:
- controllers stay thin;
- **services** own transactions (lock order matter → request → link or delivery);
- every change writes its activity event in the same transaction;
- database constraints back every business rule.

Details: [architecture.md](architecture.md), [data-model.md](data-model.md), [api-conventions.md](api-conventions.md).

**Phase 7 architectural changes:**
- `email_link_reservations` and `EmailLinkActivationService` (section 10);
- a global `SecurityHeaders` middleware;
- `TRUSTED_PROXIES` support;
- the `status=current` matter filter;
- `useReturnFocus` in the shared dialogs;
- `DemoDocumentsSeeder`.

## 4. Functional Audit

Modules A–K were re-verified together, in three ways:
- the full Phase 1–6 suites, all passing;
- new cross-phase journeys;
- a manual end-to-end run on the development stack.

The coverage matrix is in [FINAL_QA_REPORT §4](FINAL_QA_REPORT.md#4-functional-coverage). Functional defects found:
- P7-008: archived matters crowded the working views;
- P7-009: dialog focus;
- P7-010: e-mail history wrapping.

The P1 (P7-001) is a reliability defect in the e-mail module. No business-rule defect was found in authentication,
clients, matters, tasks, notes, documents, requests, signing or notifications.

## 5. Security Audit

Full report: [SECURITY_AUDIT.md](SECURITY_AUDIT.md). It covers:
- methodology and threat model;
- authentication, authorisation and links;
- uploads, signing and e-mail;
- headers, dependencies and secrets;
- the remaining risks.

**Fixed in Phase 7:**
- P7-001/002/003 (e-mail link reliability, log hygiene);
- P7-004 (API headers, `X-Powered-By`);
- P7-005 (web CSP/COOP/HSTS);
- P7-006 (secure-cookie template);
- P7-015 (trusted proxies for HTTPS detection behind a TLS-terminating proxy);
- P7-014 (consistent failure category for dropped connections).

**Accepted.** `script-src 'unsafe-inline'`, required by Partial Prerendering, with the mitigations described in the
audit.

**Scope.** This is a self-review, not an independent penetration test.

## 6. Authentication and Authorization

**Authentication.**
- Session cookies are HTTP-only, `SameSite=Lax`, `Secure` in production and encrypted.
- CSRF applies on the stateful API.
- Login is limited to 5 failed attempts per email+IP per minute, plus 20 requests per minute per IP, with generic
  errors and session regeneration.
- Guests get 401 JSON.

**Authorisation.**
- Every protected route uses the `admin` middleware and policies.
- Nested resources are resolved through their parent, so another matter's id is a 404.
- Notifications are per user.
- Internal notes exist only on administrator routes.

**Phase 7 evidence (Journey H):**
- cross-matter document, download, request, signing and history ids → 404;
- a signing token on the upload API, and an upload token on the signing API → 404;
- both token types on administrator, notes, notifications and dashboard endpoints → 401;
- public responses contain no internal names.

## 7. Secure Links

**Phase 4/5 design, unchanged.**
- 256-bit CSPRNG tokens, with only their SHA-256 stored.
- Purpose-scoped resolvers; expiry, one use, revocation and rotation.
- Fragment and header transport, `no-referrer` and `no-store` headers, and an IP lockout.

**Phase 7 changes.**
- The hash of an e-mailed token is reserved before sending. That reservation is never a usable link by itself.
- Activation on first use follows the same rules and locks as a normal registration.
- A newer link supersedes an unconfirmed one, and expired reservations answer 410.
- Scans confirm that tokens never appear in tables (other than as hashes in the two link tables), logs, activity,
  notifications or e-mail records.

## 8. PDF Upload and Storage Integrity

**Re-verified.** All Phase 3/4 tests still pass:
- the type, signature, completeness, encryption and size checks;
- server-generated paths on a private disk;
- staged writes with SHA-256 verification;
- cleanup on failure;
- immutable versions;
- composite foreign keys that keep documents inside their matter.

**Phase 7 checks.**
- The demo seeder's 8 documents and 10 versions (2 of them signed) all match their stored checksums
  (`DemoDocumentsSeederTest`).
- Manual: a downloaded signed PDF and its unchanged source match the evidence checksums.

**Malware scanning** is not implemented. The audit gives the recommendation (SECURITY_AUDIT §6).

## 9. PDF Signing Integrity

Re-verified by the Phase 5 suite:
- one signer per PDF, a pinned source version, and placement in PDF points;
- versioned consent and server time;
- a verified, immutable signed version, with the original unchanged;
- no second signature, and no completion with a revoked or consumed link;
- race tests leave exactly one signed version; the temp-file reservation and failure cleanup work.

**Unsupported PDFs.** Compressed xref streams, forms, annotations, existing signatures and encryption are refused with
a clear explanation and export guidance. Nothing is flattened.

**Measured.** The client signature step takes 158 ms median with OPcache.

**Legal wording.** Reviewed everywhere; there are no overclaims (section 26).

## 10. Email Reliability

**Semantics.**
- "Accepted by the mail server" is never presented as delivered.
- Failures are never shown as Sent.
- Failed resends keep the previous link.
- The same-type cooldown regression test still passes.

**Phase 7 change: activation on first use.** This closes the Phase 6 window in which the server had accepted a message
but the process stopped first.

```
claim tx: delivery 'sending' + reservation(hash, pending) ─► SMTP ─► accepted ─► finish tx: link + reservation 'registered'
                                                              │                 └ fails ─► 500 email_unrecorded (honest)
                                                              └ refused (definite) ─► failed + reservation 'discarded'
client presents a token with no link but a pending reservation ─► activation tx: link registered as on acceptance,
   delivery 'sending'→'accepted' (or kept 'failed/interrupted'), reservation 'activated', event email.link_activated
```

**Claims.** Exactly-once delivery across SMTP and PostgreSQL is **not** claimed:
- an interrupted send is still reported as outcome unknown;
- a resend can duplicate a message that had in fact arrived.

The difference is that the client's link now works whenever the message did arrive.

**Evidence:** `EmailLinkRecoveryTest` (6 tests), the Phase 6 suites, and the manual outage/retry.

## 11. Notifications and Activity

Unchanged and re-verified:
- notifications are per administrator and persistent;
- they hold no links or tokens;
- `href` values are sanitised;
- each notification is written in the transaction of its event.

**Phase 7 additions.**
- Activity gains `email.link_activated` ("E-mailed link opened by the client").
- The e-mail history notes "the client opened the link from this e-mail".
- The activity history stays append-only (trigger), and Journey A/B checks the complete, ordered chain of events.

## 12. UI/UX Improvements

**Review.** Screenshots of 12 page types at seven widths, plus the acceptance steps. The design system was already
consistent (charcoal, teal, off-white, Geist), so changes were targeted rather than a redesign.

**Changes:**
- **Working views show current work** (P7-008).
  - Matters default to "All except archived", with a clear "All, including archived" option.
  - The dashboard's recent matters show current work only.
  - Archived matters stay one click away; client pages and the documents library still list them.
- **Dialogs return focus** (P7-009): no lost place for keyboard users.
- **E-mail history** (P7-010):
  - recipient addresses no longer break mid-token;
  - a note shows when the client used the link of an unconfirmed send.
- **Honest feedback.** A recording failure after acceptance gets an honest administrator message: a toast in the
  correction flow, and the dialog error elsewhere.
- **Realistic demo content.** The dashboard, requests, signatures and documents look like a working practice
  (P7-011).

**Considered, not changed.** Breadcrumbs on detail pages show the section only; every detail page already has a "←
back to matter · section" link. There were no dead buttons, "coming soon" items or broken routes: the existing
navigation test asserts this, and every page was captured.

## 13. Accessibility

- **axe-core** (WCAG 2.0/2.1/2.2 A+AA): **0 violations** on 11 page types, 6 matter tabs, 4 dialogs and states, the
  sign-in page and 3 client pages.
- **Keyboard:**
  - sign-in with the keyboard only;
  - skip link;
  - focus into dialogs, Escape to close, and focus return (fixed);
  - the Phase 5 keyboard signing test still passes.
- **Not done:** a screen-reader session (none available). No formal conformance claim.

Details: [FINAL_QA_REPORT §6](FINAL_QA_REPORT.md#6-accessibility).

## 14. Responsive Design

- **Overflow.** 0 px horizontal overflow on 12 pages × 7 widths (320, 375, 390, 430, 768, 1024, 1440 px).
- **320 px tests:** client upload, client signing (draw and place, touch targets ≥ 40 px), administrator navigation.
- **Pixel 7:** the existing mobile specs.
- **Manual:** phone upload and signing opened from real e-mails.

No layout defects were found, so no responsive code changed.

## 15. Performance

Measured on a production build with 1,012 matters, 3,015 tasks, 10,214 activity events and 154 PDFs
(FINAL_QA_REPORT §7):
- **Queries.** Every list endpoint runs a constant 2–12 queries. No N+1, and a regression test now guards this.
- **API latency.** About 300 ms median without OPcache (PHP bootstrap dominates); 85–110 ms with OPcache. The deployment
  guide requires OPcache.
- **Pages.** TTFB ≤ 8 ms; data shown in 1.4–2.4 s against the single-threaded development PHP server; CLS 0.
- **JavaScript.** 629 KB gzip in total; PDF.js (137 KB gzip) is lazy-loaded.
- **PDF and signing.** PDF preview ≈ 1.9 s to the first page; client signature 158 ms median.

The only optimisation is the OPcache requirement, a configuration change. No code bottleneck was found.

## 16. Database Integrity

**Reviewed in all Phase 1–7 migrations:**
- composite foreign keys keep documents, requests, links, signing requests, e-mail deliveries and reservations inside
  their matter;
- CHECK constraints cover statuses, timestamps and consistency;
- partial unique indexes allow one usable link per request, one active signing request per document, and one send in
  progress per request;
- triggers enforce immutable versions, signature records, submissions, delivery outcomes, reservations and append-only
  activity.

**Phase 7 additions:**
- `email_link_reservations`, with composite foreign keys, CHECKs (purpose and target, hash format, link ⇔ status,
  pending ⇔ unresolved) and a guard trigger (no delete, no rewrite, resolve once).
- One additive migration, applied to the development database without a reset.

**Process.** No constraint was dropped or weakened.

## 17. Dependency Audit

| Audit | Result |
| --- | --- |
| `composer audit` | no advisories |
| `npm audit --omit=dev` | 0 vulnerabilities |
| `npm audit` | 15 entries (6 moderate, 9 high; 2 advisories) → **14 entries, 14 high, 0 moderate** after a lockfile-only `npm audit fix`. The `minimatch` advisory is resolved; the remaining one is the `picomatch` ReDoS (GHSA-fc5q-39xc-vjpf) in ESLint's toolchain, listed once for each dependent package, with no patched version (P7-007) |
| Added | `@axe-core/playwright` 4.14.0 (dev, exact pin) |

Both audits now run in `verify-windows.bat`.

## 18. PHPUnit

**Final run: 253 passed, 3,698 assertions, 0 failed, 0 skipped (116 s).**
- All **236** Phase 1–6 tests still pass.
- **17** new Phase 7 tests:

| Class | Tests |
| --- | --- |
| `EmailLinkRecoveryTest` | 6 |
| `SecurityHeadersTest` | 3 |
| `TrustedProxiesTest` | 3 |
| `TransportFailureCategoryTest` | 2 |
| `QueryBudgetTest` | 1 |
| `WorkspaceListingTest` | 1 |
| `DemoDocumentsSeederTest` | 1 |

What each covers: [FINAL_QA_REPORT §3](FINAL_QA_REPORT.md#3-new-tests-in-phase-7). Two existing tests were adjusted,
without weakening any assertion: the Phase 6 race cleanup now also removes the new reservation rows, and the security
scan's comment now names the reservation table.

## 19. Playwright

**Final run: 66 passed, 0 failed, 0 flaky, 0 retries (18.5 min)**, Chromium desktop (1440×900) and Pixel 7 projects.
- All **59** Phase 1–6 tests still pass. `navigation.spec.ts` was updated deliberately for P7-008; it now asserts the
  new default and that archived matters stay reachable.
- **7** new tests:

| Spec | Tests |
| --- | --- |
| `phase7-accessibility` | 3 |
| `phase7-journeys` | 2 |
| `phase7-mobile` | 2 (320 px) |

**Cross-browser (opt-in).** `phase7-cross-browser.spec.ts` passed in **Firefox** and **WebKit** (2/2).

**First full run.** Playwright passed 65 of 66 in the first full Phase 7 run. The one failure was a latent Phase 6
timing issue, fixed as **P7-014**; that test then passed 3× in isolation and in the final run.

**Timeouts.** Unchanged: 90 s default, 180–240 s for multi-page workflows, as in earlier phases.

## 20. Windows Verification

`verify-windows.bat` on 2026-11-17 at 20:03:31 (host RIONI), at code commit `5227473`. Log:
`verification-logs/verify-20261117-200331.log` (gitignored). Toolchain: PHP 8.3.35, Composer 2.11.2, Node.js 22.23.5,
npm 10.9.9, PostgreSQL 16.16.

| Step | Result | Duration | Output |
| --- | --- | --- | --- |
| PHP `pdo_pgsql` loaded | PASS | — | |
| E2E Mailpit (isolated SMTP inbox) | PASS | — | reachable on 127.0.0.1:8027 |
| `composer validate` | PASS | 4 s | valid |
| `composer audit` *(new in Phase 7)* | PASS | 3 s | no advisories |
| `php artisan migrate` (status) | PASS | 1 s | additive Phase 7 migration already applied; no reset |
| `php artisan db:seed` (idempotent) | PASS | 1 s | demo admin, workspace and demo documents; nothing duplicated |
| `php artisan route:list` | PASS | 0 s | 82 routes (no new endpoints in Phase 7) |
| PHPUnit | PASS | 116 s | **253 passed (3,698 assertions)** |
| Pint | PASS | 1 s | passed |
| `npm audit --omit=dev` *(new in Phase 7)* | PASS | 1 s | found 0 vulnerabilities |
| ESLint | PASS | 30 s | no problems |
| TypeScript | PASS | 6 s | no errors |
| Production build | PASS | 13 s | 19 pages |
| Playwright E2E (Phase 1–7, desktop + mobile) | PASS | 1,114 s | **66 passed (18.5 min)**, 0 failed, 0 flaky |
| **Summary** | **ALL STEPS PASSED** | | **14/14** |

Separately, opt-in, at the same code: Firefox and WebKit, 2 passed (`phase7-cross-browser.spec.ts`).

## 21. Manual Acceptance

All steps passed on the development stack: web 3010, API 8010, development Mailpit, fictional data.

**Steps:**
- keyboard-only sign-in;
- the matters default and "all";
- the demo signed PDF's checksums;
- a request e-mail opened and uploaded on a phone profile (fragment-only token, 0 cookies), then the notification,
  review and approval;
- a signing invitation e-mailed and signed on a phone, with the evidence checksum;
- a real SMTP outage with an honest message, and a retry that was accepted after restart.

**First attempt.** It failed three checks, all because of faults in the check script itself. Details and the table are
in [FINAL_QA_REPORT §13](FINAL_QA_REPORT.md#13-manual-acceptance-development-stack). The fictional matters were
archived afterwards.

## 22. Issues Found and Fixed

| ID | Severity | Title | Status |
| --- | --- | --- | --- |
| P7-001 | P1 | E-mail accepted but link never registered | Fixed |
| P7-002 | P2 | Recording failure: generic 500, SQL bindings in log | Fixed |
| P7-003 | P2 | Ambiguous transport outcome lost the link | Fixed |
| P7-004 | P2 | API security headers, `X-Powered-By` | Fixed |
| P7-005 | P3 | Web CSP hardening | Fixed (`'unsafe-inline'` accepted) |
| P7-006 | P2 | Insecure cookie line in `.env.example` | Fixed |
| P7-007 | P3 | Dev-tool npm advisories | `minimatch` fixed; `picomatch` documented (14 high entries, dev-only) |
| P7-008 | P2 | Archived matters in working views | Fixed |
| P7-009 | P2 | Dialog focus not returned | Fixed |
| P7-010 | P3 | Recipient address wrapping | Fixed |
| P7-011 | P3 | No demo documents | Fixed |
| P7-012 | P3 | Local PHP without OPcache | Documented |
| P7-013 | P3 | ESLint scanned temporary build dirs | Fixed |
| P7-014 | P3 | Dropped SMTP connection sometimes reported as "unknown" (found by the first full verification run) | Fixed |
| P7-015 | P2 | No trusted-proxy configuration (HTTPS detection behind a proxy) | Fixed |

Details: [PHASE_7_ISSUE_REGISTER.md](PHASE_7_ISSUE_REGISTER.md).

## 23. Production Readiness

**Ready in the code and documented:**
- production cookie and HTTPS behaviour, with HSTS on both servers;
- trusted proxies;
- security headers;
- a production guard on e-mail (public HTTPS links required);
- seeders refuse production;
- a scheduler without a queue;
- OPcache and cache commands;
- backups of the database and PDFs **together**;
- rollback;
- smoke tests.

All of it is in [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).

**Not verified,** because no environment existed:
- a real server, TLS and DNS;
- a production SMTP provider, with SPF/DKIM/DMARC;
- cron on a server;
- malware scanning.

## 24. Demo Readiness

- **Setup.** `setup-windows.bat` installs everything; `start-windows.bat` starts it.
- **Data.** The seed creates a realistic fictional practice: 8 clients, 10 matters, tasks and notes, 5 firm documents
  (one with two versions), 4 requests in every state with 3 client uploads, an administrator-signed and a client-signed
  document, and an invitation ready to send.
- **Walkthrough.** [DEMO_WALKTHROUGH.md](DEMO_WALKTHROUGH.md) covers every requirement in 20–30 minutes with real
  actions, including e-mail through Mailpit and phone signing.

## 25. Git State

- Branch `feature/phase-7-final-qa-delivery`, based on `main` at `e770e27`.
- Commits, oldest first:

  | Commit | Subject |
  | --- | --- |
  | `0fcdf6a` | Phase 7: e-mailed links survive a crash after SMTP acceptance (P7-001, P7-002) |
  | `5de5c44` | Phase 7: HTTP security headers, CSP hardening, secure cookie default, dependency fixes (P7-004..P7-007) |
  | `80a63f7` | Phase 7: dialogs return focus, archived matters out of the default views, e-mail history wrapping (P7-008..P7-010) |
  | `4436c8c` | Phase 7: realistic demo documents, requests and signatures |
  | `6d9aacf` | Phase 7: final regression tests, accessibility, cross-browser and verification steps |
  | `687dd89` | Phase 7: a dropped SMTP connection is always reported as "could not be reached" (P7-014) |
  | `5227473` | Phase 7: trust only the configured reverse proxy (TRUSTED_PROXIES) (**code verified by the final run**) |
  | *(next)* | Phase 7 documentation, final reports and handover — documentation only |

- **HEAD** is the documentation commit on top of `5227473`. Its hash is given in the final response, because a commit
  cannot contain its own hash. All executable code at HEAD equals `5227473`.
- **Working tree:** clean after the documentation commit.
- **Not committed (gitignored):** `.env`, verification logs, test results, build directories, Mailpit data, screenshots
  and measurement scripts (kept in a temporary folder).
- **Merge and push status:** not merged into `main` and not pushed, pending explicit authorisation. The repository
  stays private.

## 26. Known Limitations

- **Electronic signatures.** Application-level electronic signatures with an evidence record. They are not qualified,
  advanced or certificate-based (PAdES/eIDAS) signatures, and there is no trusted timestamp. Whether this suffices for a
  document and jurisdiction is the firm's decision.
- **Client identity.** Clients are authenticated by possession of a secure bearer link plus the confirmed name. There
  is no independent identity verification, and a forwarded e-mail grants access until the link is used, expires or is
  revoked.
- **PDF compatibility.** The free FPDI parser cannot import compressed cross-reference streams (many PDF 1.5+ files).
  Forms, annotations, existing digital signatures and encrypted PDFs are refused by design. A clear explanation and
  "export a compatible copy" guidance are shown.
- **E-mail delivery.** Status ends at acceptance by the SMTP server. There are no provider webhooks, so delivered,
  bounced and spam outcomes are not tracked. Failed e-mails are not retried automatically.
- **E-mail crash window (Phase 7 outcome).** If the process stops between SMTP acceptance and recording, the delivery
  is reported as "interrupted" (outcome unknown), but the client's link **works** when first opened (P7-001).
  Exactly-once delivery is not claimed: an administrator who resends after an interruption may send a second e-mail,
  and the newest link then wins.
- **Domain authentication.** SPF, DKIM and DMARC need a real sending domain and are not configured or verified.
- **Malware scanning.** None. Uploads are validated structurally only.
- **Content-Security-Policy.** `script-src 'unsafe-inline'` is kept, because of Partial Prerendering.
- **Accessibility.** Automated checks and keyboard tests only. No screen-reader session was performed, and no formal
  WCAG conformance is claimed.
- **Browsers.** The full suite runs on Chromium. Firefox and WebKit have a targeted compatibility check; real Safari
  and iOS were not tested.
- **Local performance.** Laragon's PHP runs without OPcache; production requires it.
- **Production.** Everything was verified locally on Windows; no real deployment was exercised.
- **Other.** One administrator role. The client picker lists the first 100 clients. PDF text is not searchable. Rare
  JPEG 2000/JBIG2 images may not preview (the download is unaffected).

## 27. Final Acceptance Checklist

**Functionality** — all Passed:
- administrator authentication, clients, matters, tasks, notes;
- search and filters, PDF library, version history;
- client requests, client uploads, review and correction;
- administrator signing, client signing, signing progress, signed PDF evidence;
- e-mail sending, reminders, notifications, activity history.

Evidence: sections 2 and 4.

**Security**

| Criterion | Result |
| --- | --- |
| Authentication, authorisation, cross-matter isolation checked | Passed |
| Secure links, token leakage checked | Passed |
| Private storage, PDF checksums, signature immutability | Passed |
| Concurrency checked | Passed |
| SMTP security checked | Passed |
| CSRF and CORS reviewed | Passed |
| Security headers reviewed | Passed (one accepted risk) |
| Dependencies audited | Passed (dev-tool advisories documented) |
| No secrets committed | Passed |
| No unresolved P0/P1 | Passed |

**UI/UX**

| Criterion | Result |
| --- | --- |
| Dashboard, client, matter and document pages polished | Passed |
| Signing UX, notifications, e-mail states polished | Passed |
| Responsive design checked | Passed |
| Keyboard interaction checked | Passed |
| Automated accessibility checked | Passed (0 violations) |
| No dead buttons, broken routes or misleading status messages | Passed |
| Screen-reader test | **Not verified** (no screen reader available) |

**Performance**

| Criterion | Result |
| --- | --- |
| Critical APIs reviewed, N+1 reviewed, pagination verified | Passed |
| PDF performance assessed | Passed |
| Frontend build reviewed (PDF.js lazy) | Passed |
| Representative measurements recorded | Passed |

**Quality assurance**

| Criterion | Result |
| --- | --- |
| All 236 existing PHPUnit tests pass | Passed (236 of 253) |
| All 59 existing Playwright tests pass | Passed (59 of 66) |
| New Phase 7 tests pass | Passed |
| Pint, ESLint, TypeScript, production build | Passed |
| Real SMTP test | Passed |
| Full Windows verification | Passed (14/14) |
| Manual acceptance | Passed |
| Important regressions have automated tests | Passed |

**Delivery**

| Criterion | Result |
| --- | --- |
| README, setup, demo credentials procedure, sample data, walkthrough | Passed |
| Architecture, API, deployment, security, QA, limitations, this report | Passed |
| Git commits completed, working tree clean | Passed |
| Feature branch not merged without authorisation | Passed |
| Repository remains private | Passed (visibility not changed) |

## 28. Remaining Risks and Release Recommendation

**Release-blocking findings:** none open.

**Non-blocking risks:** see section 26 and SECURITY_AUDIT §13. In brief:
- bearer-link access;
- no malware scanning;
- `'unsafe-inline'`;
- application-level signatures;
- SMTP-acceptance semantics;
- unverified production environment.

**Recommendation:**
- **READY FOR REVIEWER DEMO.** The application is complete, verified and demonstrable locally with the documented
  setup.
- **Production.** Before any public deployment, follow the deployment guide in a controlled staging environment:
  HTTPS, a real SMTP provider with SPF/DKIM/DMARC, cron, OPcache, backups and malware scanning. Then run its smoke tests.
  Only after that is the application ready for controlled staging and, later, production.

## 29. Final Delivery Files

| File | Content |
| --- | --- |
| `README.md` | purpose, features, quick start, setup, demo login and data, testing, troubleshooting, limitations, documentation index |
| `docs/architecture.md` · `docs/data-model.md` · `docs/api-conventions.md` | technical reference (updated for Phase 7) |
| `docs/implementation-phases.md` | roadmap, all phases ✅ |
| `docs/security-credentials.md` · `docs/email-configuration.md` | credentials review, e-mail operations |
| `docs/PHASE_1…6_IMPLEMENTATION_REPORT.md` | earlier phases |
| `docs/PHASE_7_AUDIT_PLAN.md` | audit scope, method, severity rules |
| `docs/PHASE_7_ISSUE_REGISTER.md` | findings, root causes, fixes, tests |
| `docs/SECURITY_AUDIT.md` | threat model, controls, residual risks |
| `docs/FINAL_QA_REPORT.md` | environment, results, measurements, manual acceptance |
| `docs/DEPLOYMENT_GUIDE.md` | production deployment and operations |
| `docs/DEMO_WALKTHROUGH.md` | reviewer tour |
| `docs/DELIVERY_CHECKLIST.md` | handover status and a submission overview |
| `docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md` | this report |

## 30. Engineering Conclusion

**Demo readiness.** Lexora Workspace fulfils all 27 requirements of the Amanet assignment as one coherent product.
It is ready for the reviewer demonstration:
- it installs with one command on Windows, or manually anywhere;
- the realistic fictional data shows every workflow;
- the walkthrough exercises real e-mail, uploads and signatures;
- every claim in the documentation is backed by a test or a recorded check.

**Strengths:**
- **Integrity.** Database-enforced invariants, immutable records, honest states, and race-tested transactions.
- **Security.** Security controls proportionate to a legal practice.
- **Interface.** Accessible and responsive.
- **Test suite.** It now spans real SMTP, multiple processes, accessibility and two additional browser engines.

**Before production:**
- a staging deployment that follows the deployment guide;
- the firm's decision on the electronic-signature limits;
- malware scanning;
- an e-mail domain with SPF/DKIM/DMARC;
- ideally, an independent security review.

None of these requires redesigning the application.
