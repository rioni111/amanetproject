# Final QA Report — Lexora Workspace (Phase 7)

All results below come from runs on the machine described in section 1. Nothing is estimated. Where something was not
tested, the report says so.

## 1. Test environment

| Item | Value |
| --- | --- |
| Machine | Windows 11 Pro (10.0.26300), Laragon, non-elevated |
| Runtimes | PHP 8.3.35 (CLI, **no OPcache**: Laragon default), Node.js 22.23.5, PostgreSQL 16.16 (port 5433) |
| Browsers | Playwright Chromium (default suite), Firefox and WebKit (opt-in compatibility spec) |
| Mail | this project's Mailpit 1.31.1 containers: development 1026/8026, isolated E2E 1027/8027 |
| Databases | `amanet` (development, never reset), `amanet_test` (PHPUnit), `amanet_e2e` (Playwright and the performance dataset; disposable) |
| Code verified | branch `feature/phase-7-final-qa-delivery`, commit `5227473` (all executable code; the documentation commit that follows changes no code) |

## 2. Baseline and final results

| Suite | Phase 6 baseline | Phase 7 final |
| --- | --- | --- |
| PHPUnit | 236 tests, 3,404 assertions | 253 passed (3,698 assertions) |
| Playwright (Chromium, desktop + Pixel 7 projects) | 59 | 66 passed (Chromium desktop + Pixel 7, 18.5 min) |
| Playwright Firefox + WebKit (opt-in) | — | 2 passed (1 spec × 2 engines) |
| Pint · ESLint · TypeScript · production build | pass | pass |
| `composer audit` · `npm audit --omit=dev` | — | no advisories · 0 vulnerabilities |
| Windows verification | 12/12 | 14/14, ALL STEPS PASSED (`verify-20261117-200331.log`) |

All 236 Phase 1–6 PHPUnit tests and all 59 Phase 1–6 Playwright tests are still part of the suite. Only two existing
tests changed:
- `navigation.spec.ts`, for the deliberate matters-list default change (P7-008). It now asserts both the new default
  and that archived matters stay reachable.
- The Phase 6 race-test cleanup, which now also removes the new reservation rows.

## 3. New tests in Phase 7

**PHPUnit**

| Test class | Tests | Covers |
| --- | --- | --- |
| `Phase7/EmailLinkRecoveryTest` | 6 | P7-001/002/003: a real database failure after SMTP acceptance; activation on first use (document and signing); interrupted outcome kept honest; supersession by a newer e-mail or link; definite refusal, expiry and cancellation never activate; hash-only, immutable reservations |
| `Phase7/SecurityHeadersTest` | 3 | headers on JSON, errors and unknown routes; PDF/public headers kept; HSTS only over HTTPS in production |
| `Phase7/QueryBudgetTest` | 1 | the same query count for 2 and 8 rows on 9 list/detail endpoints (no N+1) |
| `Phase7/WorkspaceListingTest` | 1 | `status=current`; the unfiltered API default unchanged; dashboard recent matters exclude archived |
| `Phase7/DemoDocumentsSeederTest` | 1 | demo documents: checksums, versions, every request state, signatures with evidence, idempotency, no usable link without a known token, archived matters untouched |
| `Phase7/TransportFailureCategoryTest` | 2 | P7-014: a dropped connection is `connection` whichever exception Symfony throws; real SMTP reply codes keep their categories |
| `Phase7/TrustedProxiesTest` | 3 | P7-015: forwarded HTTPS ignored without `TRUSTED_PROXIES`, trusted for the configured proxy, ignored for a proxy that is not listed |

That is 17 new PHPUnit tests in total.

**Playwright**

| Spec | Tests | Covers |
| --- | --- | --- |
| `phase7-accessibility.spec.ts` | 3 | axe-core WCAG 2.0/2.1/2.2 A+AA on 11 page types, 6 matter tabs, 4 dialogs and states, the sign-in page and 3 client pages; keyboard: focus into dialogs, Escape, focus return (New matter, Send e-mail, confirmation, notification panel) |
| `phase7-journeys.spec.ts` | 2 | Journeys A+B through the UI (client → matter → task → note → e-mailed request → client upload from the real e-mail → review → approval → library → activity); Journey H (cross-matter ids, wrong-purpose tokens, tokens on admin APIs, no internal data in public responses) |
| `phase7-mobile.spec.ts` | 2 | 320 px: client upload and signing from a phone without horizontal scrolling, touch-target size; administrator navigation of every section |
| `phase7-cross-browser.spec.ts` | 1 (×2 engines, opt-in) | Firefox and WebKit: sign-in, navigation, dialog, PDF.js preview, client upload, pointer-drawn signature, signing |

Journeys C–G of the assignment are already covered end to end by earlier specs:
- correction workflow: `phase4-requests`, `phase6-communications`;
- administrator signing: `phase5-signing`;
- client signing via e-mail: `phase6-communications`;
- follow-ups and reminders: `phase6-communications` (scheduler), `ReminderSchedulerTest`;
- failure and recovery: `phase6-communications` (real SMTP outage through the relay), `EmailLinkRecoveryTest`.

## 4. Functional coverage

| Module | Automated evidence | Manual (section 13) |
| --- | --- | --- |
| A Authentication | Phase 1 auth tests (login, logout, session, CSRF, throttling), `auth.spec.ts` | keyboard sign-in |
| B Clients | Phase 2 client tests, `phase2-clients.spec.ts`, Journey A | ✓ |
| C Matters | Phase 2 matter/lifecycle tests, `phase2-matters`, `navigation`, `WorkspaceListingTest` | ✓ |
| D Tasks | `phase2-tasks-notes`, Journey A | — |
| E Internal notes | Phase 2 note tests (privacy), Journey A/H | — |
| F Documents | Phase 3 tests (upload, validation, versions, integrity, archive), `phase3-documents` | demo signed PDF checksums |
| G Client requests | Phase 4 tests and races, `phase4-requests`, Journey B | ✓ e-mail → phone upload → approve |
| H PDF signing | Phase 5 tests (drawn/typed, admin/client, placement, consent, evidence, races, refusals), `phase5-signing`, `phase5-mobile`, cross-browser | ✓ phone signing from e-mail |
| I E-mail | Phase 6 tests incl. real SMTP, `phase6-communications`, `EmailLinkRecoveryTest` | ✓ outage + retry |
| J Notifications | Phase 6 `NotificationTest`, `phase6-communications`, Journey B | ✓ |
| K Activity | activity assertions throughout Phases 2–7, Journey A/B | ✓ |

## 5. Security checks

| Check | Result |
| --- | --- |
| Live headers, web (dev server) | CSP with `object-src`/`frame-src 'none'`, `worker-src 'self'`; COOP same-origin; nosniff; DENY; Permissions-Policy |
| Live headers, API (dev server) | nosniff, DENY, `no-referrer`, deny-all CSP, `no-store, private`; no `X-Powered-By` |
| CORS with a foreign `Origin` | answered with the configured origin only (browser refuses) |
| Secrets scan of tracked files | no `.env`, `APP_KEY` value, private key, token-shaped link, PDF, storage file or log; only the documented local Docker default and `null` placeholders |
| Token and link scans | `CommunicationSecurityTest` (tables and log), `EmailLinkRecoveryTest` (reservations hold hashes only) |
| Cross-matter / wrong-purpose / token-as-credential | Journey H: all 404/401 as designed |
| Legal wording | no qualified, PAdES, eIDAS or "legally binding" claim anywhere (search over `src`, `app`, `resources`, README) |
| Hydration warning on `/login` | seen once while the dev server reloaded `next.config.ts`; not reproducible in later runs (`p7-hydration` check: only the expected 401 session probe) |

## 6. Accessibility

**Automated.** axe-core 4.14 with WCAG 2.0/2.1/2.2 A and AA rules.
- Scope: 11 workspace page types, 6 matter tabs, the New matter dialog (empty and with field errors), the Send e-mail
  dialog, a confirmation dialog, the notification panel, the sign-in page, the client upload page (valid and invalid
  link) and the client signing page.
- Result: **0 violations**.

**Keyboard (automated and manual).**
- Sign-in with Tab and Enter only.
- Skip link (existing test).
- Focus moves into dialogs, and Escape closes them.
- Focus returns to the opener. This was **fixed in Phase 7** (P7-009).
- The signature method radio group and arrow-key placement are covered by the existing Phase 5 keyboard test.

**Not performed.** A screen-reader session (NVDA/JAWS/VoiceOver) was not available in this environment, so no
screen-reader claim is made. Formal WCAG conformance is not claimed either: automated rules find only part of the
possible issues.

## 7. Performance

**How it was measured**
- A production build (`next build` + `next start`) and the API, both on this machine, with a dataset in the disposable
  `amanet_e2e`: 1,012 matters, 224 clients, 3,015 tasks, 10,214 activity events, 154 PDFs plus the seed.
- Timings come from a Playwright script, 20 requests per endpoint after 2 warm-ups, or 5 page loads after 1 warm-up.

**API latency (ms), `php artisan serve` without OPcache (the local default)**

| Endpoint | median | p95 | response |
| --- | --- | --- | --- |
| `/api/dashboard/summary` | 333 | 428 | 9.1 KB |
| `/api/matters?per_page=10` | 342 | 476 | 7.6 KB |
| `/api/matters?per_page=10&page=50` | 328 | 406 | 7.2 KB |
| `/api/matters?…&search=performance` | 300 | 520 | 0.3 KB |
| `/api/clients?per_page=10` | 313 | 399 | 3.5 KB |
| `/api/documents?per_page=10` | 357 | 433 | 8.7 KB |
| `/api/document-requests?per_page=10` | 343 | 506 | 3.5 KB |
| `/api/signing-requests?per_page=10` | 364 | 519 | 3.1 KB |
| `/api/notifications/unread-count` | 313 | 356 | 27 B |
| `/api/notifications?per_page=20` | 295 | 343 | 1.7 KB |
| `/api/matters/{id}` | 328 | 448 | 1.0 KB |
| `/api/matters/{id}/activity` | 305 | 362 | 2.8 KB |
| `/api/matters/{id}/tasks` | 301 | 371 | 1.5 KB |
| `/api/matters/{id}/communications` | 295 | 387 | 0.6 KB |

The latency is almost flat across endpoints: a 27-byte count costs as much as a 9 KB dashboard. The time is PHP
bootstrapping, not queries.

**With OPcache** (same code, same data, same machine)

| Endpoint | no OPcache median / p95 | OPcache median / p95 |
| --- | --- | --- |
| `GET /up` | 215 / 263 | 21 / 30 |
| `/api/notifications/unread-count` | 283 / 399 | 85 / 183 |
| `/api/dashboard/summary` | 326 / 385 | 110 / 118 |
| `/api/matters?per_page=10` | 376 / 629 | 89 / 95 |
| `/api/documents?per_page=10` | 320 / 405 | 87 / 198 |
| `/api/matters/{id}` | 313 / 382 | 95 / 197 |

That is about 3–4× faster. The deployment guide therefore requires OPcache (P7-012). The remaining measurements were
stopped by the API rate limiter (240 requests/min per user), which worked as configured.

**Queries per request** (`QueryBudgetTest`, the same for 2 and 8 rows)

| Endpoint | Queries |
| --- | --- |
| matters list | 3 |
| clients list | 2 |
| documents list | 5 |
| document requests list | 7 |
| signing requests list | 7 |
| notifications | 3 |
| dashboard | 12 |
| matter detail | 3 |
| matter activity | 3 |

No N+1 was found, so no query change was needed.

**Page loads** (production build, no OPcache API, median of 5)

| Page | TTFB | DOMContentLoaded | data shown | LCP | CLS |
| --- | --- | --- | --- | --- | --- |
| Dashboard | 5 ms | 17 ms | 1,412 ms | 1,000 ms | 0.000 |
| Matters | 5 | 17 | 1,411 | 1,356 | 0.000 |
| Matter detail | 8 | 48 | 1,934 | 1,508 | 0.000 |
| Clients | 4 | 15 | 1,385 | 1,032 | 0.000 |
| Documents | 4 | 17 | 2,402 | 1,952 | 0.000 |
| Document detail | 5 | 41 | 2,431 | 1,916 | 0.000 |
| Signatures | 4 | 17 | 1,400 | 920 | 0.000 |

"Data shown" is dominated by the API calls above, which `php artisan serve` handles one at a time. There is no layout
shift on any page.

**PDF and signing**
- PDF preview, click to first page rendered: first 1,995 ms, then 1,903 ms median. PDF.js (137 KB gzip) loads only on
  first use.
- Client signature step (PDF generation, verification, storage, transaction) on the OPcache server: **158 ms median,
  263 ms max** (n = 5).

**JavaScript.** 40 chunks, 2,068 KB raw / **629 KB gzip** in total. PDF.js is the largest chunk (137 KB gzip) and is
lazy-loaded. The sign-in page loads 685 KB raw (11 files) and the client upload page 558 KB raw.

**Optimisations.** Only configuration-level: OPcache for production. No speculative code optimisation was made,
because no bottleneck was found in the application code.

**Caveat.** These are single-machine development measurements, not production guarantees.

## 8. Responsive design

**Overflow checks.** 12 workspace pages (dashboard, clients, client, matters, matter, documents, document, client
requests, request, signatures, signing request, notifications) × 7 widths (320, 375, 390, 430, 768, 1024 and 1440 px)
on the development data: horizontal overflow **0 px** on all 84 combinations (capture script; screenshots reviewed).

**Automated phone tests:**
- Pixel 7: existing phase 3–6 mobile specs.
- 320 px: `phase7-mobile`, covering client upload, client signing (draw and place) and administrator navigation.

**Manual.** Client upload and signing on a Pixel 7 profile, opened from the real e-mails (section 13).

**Tablet (768 px) and 1024 px.** Covered by the overflow check above. No dedicated Playwright test runs at these widths.

## 9. Demo data

`DemoDocumentsSeeder` adds, through the real services:
- 5 firm documents (one with 2 versions);
- 4 client requests (pending, under review, correction requested, approved) with 3 client uploads;
- 1 administrator-signed and 1 client-signed document, with evidence;
- 1 signing request ready to e-mail.

Run twice on the development database: the second run added nothing. Demo evidence identifies itself in the user agent
("Lexora demo data seeder (fictional signature)"), and every demo PDF carries a "Fictional demonstration document"
footer.

## 10. Cross-browser

`phase7-cross-browser.spec.ts` passed in **Firefox** and **WebKit** (Playwright builds), covering:
- sign-in with the session cookie and CSRF;
- sidebar navigation, and a dialog with Escape;
- PDF.js preview;
- client upload through the file input;
- client signing with pointer-drawn strokes, placement by click, consent and signature.

The full suite runs in Chromium only. Real Safari on macOS/iOS and real mobile Firefox were not tested.

## 11. Concurrency and failure recovery

These run in every full PHPUnit run as separate processes against PostgreSQL:
- matter numbering race (Phase 2);
- concurrent uploads and version numbering (Phase 3);
- submission vs revocation and approval (Phase 4);
- two signing completions, signing vs archive, signing vs revocation, temp-file reservation (Phase 5);
- four simultaneous e-mail sends, three parallel schedulers, send vs cancellation, invitation vs signature (Phase 6).

Failure injection:
- storage write failures and checksum mismatches (Phase 3);
- PDF generation and verification failures (Phase 5);
- an unreachable SMTP server (Phase 6);
- a database failure after SMTP acceptance (Phase 7).

All pass in the final run.

## 12. SMTP integration

- `SmtpIntegrationTest` delivers real SMTP to the isolated Mailpit, and the delivered link performs the action.
- The Phase 6 and Phase 7 Playwright journeys open the delivered message and click its button.
- In the manual run, the real development Mailpit was stopped and restarted (section 13).

## 13. Manual acceptance (development stack)

**Setup.**
- Web 3010, API 8010, development Mailpit, fictional client "Northgate Mills Cooperative (fictional)".
- Matter LEX-2026-0026, archived afterwards. LEX-2026-0025 was left by an interrupted first attempt and archived too.
- Driven through the real UI by a script. Links were opened by clicking them inside the delivered e-mails; the password
  stayed in memory.

| Step | Result |
| --- | --- |
| Sign-in using only Tab and Enter | PASS |
| Matter list: archived hidden by default ("1–10 of 10"); "All, including archived" shows 25 | PASS |
| Demo data: client-signed purchase agreement; downloaded signed PDF and unchanged source match the evidence checksums | PASS |
| Request e-mail accepted by the dev Mailpit | PASS |
| Link opened the client page on a phone profile; token only in the fragment; no query string | PASS |
| Phone upload without an account, 0 cookies; administrator notification (+1) | PASS |
| Review and approval in the workspace | PASS |
| Signing invitation e-mailed, signed on a phone (draw, tap to place, consent); status Signed, delivery `email_accepted`, signed PDF checksum = evidence | PASS |
| Dev Mailpit stopped: "E-mail could not be sent. No successful delivery has been recorded. The mail server could not be reached." Restarted: retry accepted; history accepted + failed | PASS |
| Console errors on admin pages | only the expected 502 of the simulated outage |

**First attempt.** The first run failed on three checks, all faults in the check script, not in the application:
- it read the list count before the list refreshed;
- it used the wrong evidence field names;
- it drew the signature before scrolling the pad into view.

All three were fixed in the script and the whole run repeated (results above).

**Visual review.** Screenshots of every page at 1440, 375 and 320 px, and of the acceptance steps (kept locally, not
committed). Findings P7-008 and P7-010 came from this review.

## 14. Known failures and regression fixes

**First full verification** (`verify-20261117-193705.log`, code `6d9aacf`): 13 of 14 steps passed.
- PHPUnit: 248 passed (3,684 assertions).
- Playwright: 65 of 66 passed.
- One existing Phase 6 test failed, `phase6-communications` "a failed invitation e-mail is reported honestly and can be
  retried". It received the reason "could not be sent" instead of "could not be reached".
- Cause: a latent, timing-dependent categorisation of dropped connections (**P7-014**). It was fixed, regression-tested,
  and the E2E test was re-run 3× green before the final run.

**Final run.** No known failures (section 15). Development tooling still carries the `picomatch` advisories (P7-007).

## 15. Windows verification (final)

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
