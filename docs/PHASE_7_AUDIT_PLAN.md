# Phase 7 Audit Plan — Final Security, Quality and Delivery Review

Branch `feature/phase-7-final-qa-delivery`, created from `main` at `e770e27` (Phases 1–6 merged). Written at the
start of Phase 7, before any code changed. Findings and their status are tracked in
[PHASE_7_ISSUE_REGISTER.md](PHASE_7_ISSUE_REGISTER.md); results are in [FINAL_QA_REPORT.md](FINAL_QA_REPORT.md).

## 1. Starting point

- Phase 6 baseline: 236 PHPUnit tests (3,404 assertions), 59 Playwright tests, Windows verification 12/12.
- Documentation read for the audit:
  - README, architecture, data model, API conventions, roadmap, credentials review, e-mail guide;
  - Phase 1–6 reports, including the limitations each one lists.
- Code inspected:
  - migrations and their constraints and triggers;
  - security-relevant services (links, uploads, signing, e-mail);
  - middleware, exception rendering and headers;
  - the frontend routes and design system;
  - the Windows scripts and the E2E configuration.

## 2. Release-blocking definition

| Severity | Meaning | Release rule |
| --- | --- | --- |
| **P0** | unauthorised access to client documents, raw token or secret exposure, corrupted or misattributed signed PDFs, cross-client leakage, data loss | must be fixed and regression-tested |
| **P1** | a core workflow fails, an e-mailed link is unusable, a race corrupts state, wrong version association, broken primary navigation, core flow unusable on mobile | must be fixed and regression-tested |
| P2 | confusing state, minor accessibility gap, missing recovery guidance, slow queries, inconsistent labels | fix where feasible, otherwise document |
| P3 | cosmetic or documentation issues | fix or document |

## 3. What is checked, and how

| # | Area | Checks | Method / evidence |
| --- | --- | --- | --- |
| 1 | Functional modules A–K (auth, clients, matters, tasks, notes, documents, requests, signing, e-mail, notifications, activity) | every CRUD/workflow path still works together | existing 236 PHPUnit + 59 Playwright tests; new cross-phase journeys; manual run on the dev stack |
| 2 | Authentication | guest access, session expiry, logout, CSRF, CORS, cookie flags, brute force, errors | config review, response-header inspection, existing auth tests |
| 3 | Authorisation | cross-matter ids, wrong-purpose tokens, tokens on admin APIs, notification scoping, notes privacy, archived rules | route/policy/binding review; new Journey H test; existing isolation tests |
| 4 | Secure links | generation, hash storage, expiry, revocation, rotation, purpose, one use, rate limits, fragment transport, headers, logs, e-mail, history | code review of SecureAccessLinkService, resolvers, e-mail services; token scans |
| 5 | **Phase 6 crash window** (§14) | accepted by SMTP but link never registered | reproduce with a real database failure after acceptance; design a contained fix |
| 6 | PDF uploads | type/MIME/extension, corruption, size, encryption, names, paths, duplicates, storage failures | existing Phase 3/4 tests (PdfInspector, failure cleanup); storage location review |
| 7 | PDF signing | one signer, source version, placement, consent, server time, immutability, checksums, races, temp files, unsupported formats, wording | existing Phase 5 tests (races, failure injection, refusals); legal-wording review of UI, PDF caption, consent, docs |
| 8 | E-mail | credentials, sender, recipient, injection, escaping, cooldown, caps, idempotency, failures, Sent semantics | Phase 6 tests + manual SMTP outage and retry; correction cooldown regression re-run |
| 9 | HTTP headers / CSP | CSP, Referrer-Policy, nosniff, framing, HSTS, cache, Permissions-Policy, PDF headers | live header inspection of web and API; Next.js CSP guidance for this version |
| 10 | Dependencies | npm audit, composer audit, lockfile consistency | audits, classification by runtime vs dev, safe updates only |
| 11 | Secrets | `.env`, keys, passwords, tokens, PDFs, storage, bundles | git-tracked file scans |
| 12 | UI/UX | every page, empty/loading/error states, consistency, navigation | screenshots of the real app (1440/375/320 px), review |
| 13 | Responsive | 320–1440 px, client upload and signing on phones | automated overflow checks on 12 pages × 7 widths (320–1440 px); 320 px Playwright tests |
| 14 | Accessibility | WCAG 2.2 A/AA automated rules, keyboard, focus return, labels, errors | axe-core in Playwright; keyboard tests; manual keyboard sign-in |
| 15 | Performance | list/API latency, N+1, bundle, PDF preview, signing | query-count regression test; measurements on a production build with 1,000+ matters |
| 16 | Database integrity | FKs, composite FKs, CHECKs, triggers, immutability | migration review; existing invariant tests |
| 17 | Concurrency / failure | sends, schedulers, signing, archive races, file failures | existing multi-process tests (Phases 2–6) re-run in the full suite |
| 18 | Cross-browser | Firefox, WebKit | targeted Playwright spec (opt-in projects) |
| 19 | Demo and delivery | seed data, walkthrough, setup scripts, deployment guide, README | new seeder, documents, setup script review |

## 4. Rules followed during the audit

- No reset of the development database `amanet`.
  - Only `amanet_test` (PHPUnit) and `amanet_e2e` (Playwright, performance dataset) are rebuilt.
- No changes to other projects or containers.
  - The other Amanet application on 3000/8000 is untouched.
  - So is the seo-intelligence-platform stack, including its Mailpit on 1025/8025.
  - The shared Laragon `php.ini` is not changed either.
- Fixes are minimal and come with regression tests. No assertion is weakened.
- Only one existing assertion changed: the matters list default (P7-008), a deliberate behaviour change. It is documented there.
- No merge, push or deployment.
