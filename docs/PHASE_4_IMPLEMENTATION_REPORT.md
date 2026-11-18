# Phase 4 Implementation Report — Lexora Workspace (Amanet)

Secure client document requests, external upload links, client submissions, document review, corrections and outstanding
requirements.

| | |
| --- | --- |
| Branch | `feature/phase-4-client-document-requests` (from `main` 9e9e530 = merged Phase 3) |
| Stack | Next.js 16.4 · React 19 · TypeScript · Laravel 12 · Sanctum · PostgreSQL 16.16 · PDF.js 6 |
| Environment | Windows 11, Laragon, ports: web 3010, API 8010, PostgreSQL 5433; E2E 3011/8011 + `amanet_e2e` |
| Status | **Complete** — see the acceptance checklist (§21) |

## 1. Executive summary

**Completed and verified**

- Administrators request a specific PDF from a matter's client (title, instructions, category, optional due date). The request is
  bound to the matter's own client by the server and by a database trigger.
- A secure upload link is generated (256-bit token from the OS CSPRNG, only its SHA-256 stored), shown **once** with *Copy link*,
  replaceable (rotation) and revocable; links expire (default 7 days, 1–30 selectable), allow **one** successful upload and are
  scoped to the purpose `document_upload`.
- The client opens `/client-upload#<token>` **without an account or cookies**, sees only that request, and uploads a PDF with real
  progress feedback. The file goes through the Phase 3 pipeline (PdfInspector, private staging, SHA-256 verification, immutable
  version) and appears in the matter as a new document (`origin = client`, v1).
- Review: preview (existing PDF.js viewer), download, start review, **approve** (records the exact version, closes the request,
  revokes links) or **request a correction** (reason stored with the submission, fresh link issued); the corrected PDF becomes the
  **next version of the same document**, earlier versions untouched. The loop can repeat (v3, v4 …).
- Outstanding requirements: matter-level and dashboard counters (pending, awaiting review, corrections, overdue, approved),
  workspace list `/document-requests` with search, status groups, overdue filter, sorting and pagination in the URL.
- Archiving a matter revokes its usable links in the same transaction; uploads into archived matters are refused independently;
  restoring does not reactivate links.
- 13 new append-only activity event types (client actions recorded with `actor_type = client`), never containing secrets.
- Concurrency: one-use links, review decisions and lifecycle changes are serialised with row locks + optimistic versions; verified
  with real parallel PHP processes.

**Not in Phase 4 (by design)**

- Automatic email delivery of links, reminders and notifications (Phase 6). The UI says links must be shared by the administrator.
- Signing (Phase 5). An approval is explicitly *not* a signature; no signed records are created.

**Limitations**

- No malware scanning of uploads (structural PDF validation only, as in Phase 3).
- A `localhost` link only works on the development machine (the UI warns). `PUBLIC_APP_URL` must be set to a public HTTPS host
  for real clients.
- `verify-windows.bat` re-runs the idempotent demo seeder on the development database, which re-hashes the demo password and
  ends open browser sessions (Phase 1 behaviour, unchanged).

## 2. Original Amanet requirements mapping

| Requirement | Implementation | Status |
| --- | --- | --- |
| Client document requests — request files through secure external links | `document_requests` + Client requests tab + request form; link issued at creation or later | ✅ Verified (PHPUnit A, E2E A/B, manual) |
| Receive uploads directly within the relevant matter | `ClientSubmissionService` creates a document in the request's matter (v1) or the next version | ✅ (PHPUnit D, E2E C, manual) |
| Document review — review client submissions | Request page: latest submission, preview, download, start review, approve | ✅ (PHPUnit G, E2E E) |
| Request corrections | Reason required, stored per submission, fresh link, correction instructions on the client page, next version | ✅ (PHPUnit H, E2E F, manual) |
| Track outstanding requirements | Matter counters, dashboard card, `/document-requests` with filters, overdue logic | ✅ (PHPUnit I, E2E G) |
| Secure links — expiry | `expires_at` enforced server-side on every call; 410 `link_expired` | ✅ (PHPUnit C, E2E D) |
| Secure links — revocation | Revoke, rotate, automatic revocation on approve/cancel/archive; immediate | ✅ (PHPUnit C, E2E D/I, manual) |
| Restrict access to the intended action | Purpose-scoped tokens; upload endpoints only; minimal responses; no admin access | ✅ (PHPUnit J, E2E D/J) |
| Matter activity — dated history of document events | 13 new event types with readable labels; client actor distinguished | ✅ (PHPUnit, E2E A, manual) |

## 3. Architecture

```
Administrator (Next.js, Sanctum session) ──► /api/matters/{m}/document-requests/…   DocumentRequestController / WorkflowController
                                                      │  Form Requests (server-owned fields prohibited) · DocumentRequestPolicy
                                                      ▼
                     DocumentRequestService ─ SecureAccessLinkService ─ DocumentReviewService ─ ActivityLogger
                                                      │ (one transaction per action; lock order matter → request → link → document)
Client browser (/client-upload#token, no cookies) ──► /api/public/document-upload[/submit]   (no session/CSRF middleware)
     X-Upload-Token header                              AuthorizePublicUploadLink → PublicUploadLinkResolver
                                                      ▼
                                   ClientSubmissionService ──► DocumentService::persistUpload (Phase 3 staging/verification)
                                                      ▼
                                   PostgreSQL: document_requests · secure_access_links · document_request_submissions
                                               documents · document_versions (immutable) · activity_events (append-only)
                                   Private disk: storage/app/private/documents/{matter}/{document_uuid}/{version_uuid}.pdf
```

Reuse instead of duplication: `DocumentService` was refactored to expose its upload pipeline (`persistUpload`, `insertDocument`,
`appendVersion`, `rejectSameAsCurrent`, `versionMetadata`); admin uploads and client submissions now share the exact same staging,
verification, cleanup and version-numbering code. There is no second storage or PDF validation implementation.

New backend components: models `DocumentRequest`, `SecureAccessLink`, `DocumentRequestSubmission`; enums
`DocumentRequestStatus`, `SubmissionReviewStatus`, `SecureLinkPurpose`, `LinkRevocationReason`, `SecureLinkState`; services
`DocumentRequestService`, `SecureAccessLinkService` (+ `IssuedLink`), `ClientSubmissionService`, `DocumentReviewService`,
`PublicUploadLinkResolver` (+ `PublicLinkException`, `PublicResponseHeaders`); middleware `AuthorizePublicUploadLink`;
controllers `DocumentRequestController`, `DocumentRequestWorkflowController`, `PublicDocumentUploadController`;
`DocumentRequestPolicy`; Form Requests under `Http/Requests/DocumentRequests`; resources for requests, links and submissions.
`MatterService` revokes links on archive and refuses client changes while requests are open; `DashboardService` and
`MatterController::loadDetail` provide the counters.

## 4. Database

Migration `2026_11_19_100000_create_document_requests_tables` — **additive**. Applied to:

- `amanet` (development) with `php artisan migrate` (no reset; 267 ms) — existing matters/documents untouched;
- `amanet_test` (PHPUnit, RefreshDatabase) and `amanet_e2e` (Playwright, `migrate:fresh` on the disposable `_e2e` database only).

New tables (full column lists in [data-model.md](data-model.md)):

| Table | Purpose | Key constraints |
| --- | --- | --- |
| `document_requests` | what was requested and its lifecycle | status CHECK; approved/cancelled consistency CHECKs; document required after a submission; composite FKs `(document_id, matter_id)` → documents and `(approved_version_id, document_id)` → versions; `lock_version` |
| `secure_access_links` | hashed, purpose-scoped links | `token_hash` char(64) UNIQUE + hex CHECK; purpose CHECK; uses ≤ max; consumed/revoked consistency; `expires_at > created_at`; composite FK `(document_request_id, matter_id)`; **partial unique index: one usable upload link per request** |
| `document_request_submissions` | one row per successful client upload | unique `(request, submission_number)`; unique `document_version_id`; **unique `secure_access_link_id`** (a link can never produce two submissions); composite FK to the version's document; correction-reason CHECK |

Triggers: `document_requests_client_guard` (request client must be the matter's client; request cannot move),
`matters_client_change_guard` (no client change on a matter with open requests), `document_request_submissions_guard`
(no delete, submitted facts immutable, final decisions immutable). Additive change to an existing table: `documents` gained
`UNIQUE (id, matter_id)` as a composite-FK target.

Indexes: requests `(matter_id, status)`, `status`, `due_at`, `client_id`, `updated_at`; links `document_request_id`,
`(matter_id, revoked_at)`, `expires_at`; submissions `review_status`.

Client reassignment rule: a matter's client cannot change while it has open (pending/submitted/under review/correction
requested) requests — 422 from `MatterService`, and refused by the trigger if the service is bypassed. Closed requests keep the
client they were created for (historical record). The public API additionally refuses a link whose request's client is no longer
the matter's client.

## 5. Secure link architecture

| Aspect | Implementation |
| --- | --- |
| Entropy | `random_bytes(32)` (OS CSPRNG) = 256 bits, base64url → 43 characters. Nothing derived from ids, times or other data. |
| Storage | Only `SHA-256(token)` (hex) in `token_hash` (UNIQUE). With 256-bit random tokens an unsalted hash cannot be brute-forced; a database leak yields no usable link. |
| Validation | Format check → lookup by hash and purpose → `hash_equals` → link/request/matter rules. |
| Purpose | `document_upload` (Phase 4); `document_signing` reserved. Upload endpoints only accept upload links — a signing token is "unknown". |
| Expiry | `UPLOAD_LINK_EXPIRY_DAYS` (default **7**), per-link 1–30 days; enforced on every request (410). |
| Revocation | Manual revoke; rotation; automatic on approve, cancel and matter archive; reason recorded; effective immediately (row state checked per request). Restoring a matter does not reactivate links. |
| Rotation | *Generate replacement link* revokes the current link and issues a new one in one transaction; the old URL stops working at once. The system never pretends to recover an old token. |
| Usage | `max_uses = 1`; opening never consumes; invalid uploads do not count; the link is consumed in the same transaction as the committed version. |
| Concurrency | Matter → request → link row locks + re-check inside the transaction; conditional `UPDATE … WHERE consumed_at IS NULL`; unique `secure_access_link_id` on submissions as backstop. Verified: 4 parallel processes, one token → exactly 1 success. |
| Leakage prevention | Token in the URL **fragment** (never sent to servers, never in Referer) and in an `X-Upload-Token` **header** (not in API access logs); never stored server-side, never in activity metadata, never in admin resources (only once in the creating response, `Cache-Control: no-store`); frontend keeps it in component state only (no storage, no query cache, no console output); `IssuedLink` redacts itself in dumps; `dontFlash('token')`; client page `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `<meta name="referrer" content="no-referrer">`; no third-party scripts. Verified: no token in logs, DB tables, activity or admin responses (PHPUnit + manual `laravel.log` check). |

## 6. Public API security

- **Isolation**: `/api/public/document-upload*` is a separate route group with `withoutMiddleware(EnsureFrontendRequestsAreStateful)`:
  no session, no cookies read or set, no admin authority. The admin API is unchanged (`auth:sanctum` + `admin` + throttle).
- **Authentication model**: the purpose-scoped bearer link in `X-Upload-Token`. The frontend's public client sends
  `credentials: "omit"` (XHR `withCredentials = false`), so an admin session in the same browser is never used.
- **CSRF/CORS**: CSRF protects cookie-authenticated requests; the public endpoints use no cookies, so CSRF does not apply there
  (it is not disabled globally). CORS allows the configured frontend origins and the `X-Upload-Token` header; additionally a
  present `Origin` must be a configured frontend origin (403 otherwise). Neither replaces the token check.
- **Rate limiting**: views 60/min/IP; submissions 10/min/IP and 5/min/token; 20 failed lookups per IP → 15-minute lockout (429).
- **Errors**: unknown/malformed/wrong-purpose/revoked → identical 404 `link_invalid`; expired/used/approved/cancelled/archived →
  410 with a client-friendly message; validation 422; no database errors, ids or paths are ever returned.
- **Minimal responses**: firm name, request title, instructions, category, due date, expiry, size limit, correction reason (only
  through the new link). No matter, client, user, document, version or storage identifiers.
- **Order of checks**: the token is resolved in middleware **before** the uploaded file is validated, so unknown tokens never
  cause a PDF to be parsed.

## 7. Client submission workflow

1. Resolve and validate the token (middleware). 2. Validate the PDF (Phase 3 `PdfUploadRules`/`PdfInspector`) and message.
3. `persistUpload`: SHA-256, private staging copy verified byte-for-byte, move to a fresh final path.
4. One transaction: lock matter → request → link; re-check every link rule (revocation, expiry, use, lifecycle, archive, client);
   **first submission** → `insertDocument` (title = request title, category = requested category, `origin = client`,
   `uploaded_by = null`) + v1; **correction** → lock the same document, refuse an identical file, `appendVersion` (v2, v3 …);
   submission row (number, version, link, message, client snapshot); request → `submitted`; link consumed; activity event
   (`document.submitted` / `document.resubmitted`, actor = client); matter touched.
5. Any failure → rollback; the new file is deleted; staging always removed; the link stays usable.

Versions record `origin = client` and `uploaded_by_name = "<client> (client)"` (no invented administrator). The submission row
links request ↔ version; the request records the approved version.

## 8. Document review

Statuses: `pending → submitted → under_review → approved | correction_requested → submitted → …`; `cancelled` from every open
state. Transitions are enforced in `DocumentRequestStatus::allowedTransitions()` and the services (422 otherwise). Decisions must
name the latest submission (`submission_id`) and the loaded `lock_version` (409 on a newer submission or a concurrent decision).

- Start review: submission `pending → in_review`, request records who/when.
- Approve: submission `approved` (reviewer, time), request `approved` with `approved_version_id` = the exact version, links revoked.
- Request correction: reason (10–2000 chars) stored on the submission (`correction_requested`, final), request
  `correction_requested`, fresh link issued and shown once; the client page shows the reason.
- Cancel: request `cancelled`, links revoked, a pending submission closed as `cancelled`; documents stay in the matter.
- History: all submissions with version, decision, reviewer, time and reason; any version can be previewed.

## 9. Frontend

| Route / component | Purpose |
| --- | --- |
| Matter tab **Client requests** (`?tab=requests`) | counters, request list, *Request document* dialog, one-time link reveal |
| `/matters/[id]/requests/[requestId]` | request review page: status, instructions, latest submission (preview/download/start review/approve/request correction), submission history, link card (state, expiry, opened, generate/replace/revoke, history), edit, cancel |
| `/document-requests` | workspace list: search, status groups, overdue only, sorting, pagination; filters in the URL (`useUrlFilters`) |
| Dashboard | *Client document requests* card with linked figures |
| `/client-upload#token` | public page outside the dashboard layout and proxy: branding, request, correction notice, PDF picker/drag-and-drop, optional message, real progress, success and all error states |
| Navigation | "Client requests" is now a working section; "Signatures" remains the disabled Phase 5 item |

Confirmations for cancel, revoke, rotate and approve; correction requires a reason. Success is shown only after the API confirms.
Responsive (Pixel 7 verified, no horizontal overflow), keyboard-accessible Radix dialogs, labelled controls, `role=alert` errors,
`aria-live` progress. Lint required replacing `setState`-in-effect patterns (keyed link body, `useSyncExternalStore` for the URL
fragment) and `watch()` with `useWatch()`.

## 10. Outstanding requirements

- Per request: one status → no double counting. **Overdue** = waiting for the client (`pending` or `correction_requested`) and the
  due day (firm time zone, end of day) has passed. Requests waiting for the firm are not "overdue for the client".
- Dashboard: outstanding (all open), pending, awaiting review (submitted + under review), corrections, overdue, approved — one
  aggregate query with `FILTER`, joined to matters to exclude **archived** matters. The list applies the same exclusion to all
  outstanding views, so a figure and the list it links to always match.
- Matter detail: open, pending, awaiting review, corrections, approved, overdue via `withCount`.
- An expired link does not make a request overdue, and an overdue request does not invalidate a link (separate concepts).

## 11. Activity events

| Event | Actor | Safe metadata |
| --- | --- | --- |
| `document.requested` | admin | request id, category, due date |
| `document_request.updated` | admin | changed fields (instructions only as `changed: true`) |
| `document_request.cancelled` | admin | from/to, links revoked |
| `link.created` / `link.rotated` | admin | request id, link id, expires_at, links revoked |
| `link.revoked` | admin | request id, link id, was_expired — or on archive: count + reason |
| `document.submitted` / `document.resubmitted` | **client** (actor_id = request id, label = client name) | request/submission/document/version ids, version number, file name, size, SHA-256, from/to, `source: secure_link`, `has_client_message` |
| `document.review_started` / `document.approved` / `document.correction_requested` | admin | submission/document/version ids, version number, from/to (`reason_recorded: true`) |

Never stored: tokens, token hashes, URLs, storage paths, PDF content, client messages, correction text. Each event is written in
the same transaction as its change (verified: an activity failure rolls back the upload and deletes the new file).

## 12. Security audit (threat model)

| Threat | Result | Evidence |
| --- | --- | --- |
| Token theft | A leaked link allows exactly one PDF upload for one request; no read access to matters, documents, notes, other requests; no admin API | PHPUnit `ClientUploadTest`, `DocumentRequestSecurityTest`; E2E D/J |
| Token replay | Used links → 410; unique link per submission | `SecureLinkTest`, E2E D |
| Expiration | Server-side, 410, no file stored | `SecureLinkTest`, E2E D |
| Revocation | Immediate, identical 404 to unknown tokens | `SecureLinkTest`, E2E D, manual |
| Parallel requests | 4 processes, one token → 1 submission | `DocumentRequestConcurrencyTest` |
| Cross-matter access | Scoped bindings + composite FKs + triggers → 404/refused | `DocumentRequestSecurityTest`, `DocumentRequestCreationTest` |
| Privilege escalation | Token is not an admin credential (401 everywhere) | PHPUnit + E2E D/J |
| Storage abuse | Size limit, PDF validation, per-IP/per-token limits, one upload per link | `ClientUploadTest` |
| File integrity | Staging + SHA-256 + rollback/cleanup; immutable versions | `ClientUploadTest`, `ReviewAndCorrectionTest` |
| Races with approval/cancel/archive/revoke | Consistent end states; never a usable link left | `DocumentRequestConcurrencyTest` |
| Token leakage | Not in logs, DB, activity, admin responses, URLs sent to servers | `SecureLinkTest`, `DocumentRequestSecurityTest`, manual log check |
| CSRF/CORS | Public group has no cookie auth; foreign Origin 403; admin API unchanged | `ClientUploadTest` |
| Outdated correction links | Revoked/closed on approval/cancellation | `ReviewAndCorrectionTest`, E2E F |

Limitations: no antivirus scanning; the per-IP limits assume a direct connection (behind a proxy, configure trusted proxies so
`$request->ip()` is the client's address).

## 13. Backend testing (PHPUnit)

New tests in `backend/tests/Feature/Phase4` (33 tests), helpers `tests/Support/RequestWorkflow.php`,
`tests/Support/request-race-worker.php`, `tests/Support/e2e-link-fixture.php` (E2E-only, refuses non-`_e2e` databases):

| File | Tests | Covers |
| --- | --- | --- |
| `DocumentRequestCreationTest` | 6 | A — creation, client association, validation, server fields, auth, archived, client-change rule + triggers, edit |
| `SecureLinkTest` | 8 | B/C — entropy, hashing, one-time URL, expiry, rotation, revocation, used, wrong purpose, closed/archived, unique usable link |
| `ClientUploadTest` | 6 | D/E — upload, minimal responses, rejection paths with no leftovers, token-before-file, rollback, isolation, rate limits |
| `ReviewAndCorrectionTest` | 4 | G/H — transitions, stale decisions, approval of exact version, correction loop v1→v2→v3, immutability triggers |
| `DocumentRequestCountersTest` | 2 | I — dashboard/matter counters, overdue (firm time zone), filters, search, sorting, pagination |
| `DocumentRequestSecurityTest` | 4 | J — all admin endpoints 401, nested scoping, server-field rejection, no tokens in logs/DB |
| `DocumentRequestConcurrencyTest` | 3 | F — real multi-process races (same token ×4; upload vs cancel/revoke/archive; two reviewers) |

Changed existing test: `tests/Feature/Api/DashboardSummaryTest` — the exact `stats` arrays now include the six new request
counters (all 0 in those fixtures); nothing was removed or weakened.

## 14. Playwright testing

New specs: `e2e/phase4-requests.spec.ts` (6 desktop tests) and `e2e/phase4-mobile.spec.ts` (Pixel 7), helpers `e2e/requests.ts`.

| Scenario | Test |
| --- | --- |
| A, B, C, E | request via UI with validation, link copied to the clipboard, anonymous context upload, admin sees it, PDF preview, downloaded bytes = uploaded SHA-256, start review, approve, Documents tab, activity labels |
| D | expired (fixture), revoked (UI), replaced (UI), used, invalid/incomplete, signing-purpose token; token is not an admin credential |
| F | correction with reason → correction link → corrected upload → v1 still previewable → approve v2; both checksums verified |
| G | dashboard deltas, overdue link from the dashboard, status filter in the URL across reload, server pagination |
| H | Pixel 7: layout, control sizes, accessible error, upload, confirmation, admin pages without overflow |
| I | archive → link refused → restore → still refused → new link works |
| J | public responses contain no notes/matter data; notes, downloads, previews, activity 401 with a token; admin pages redirect to login |

Changed existing test: `e2e/navigation.spec.ts` — "Client requests" is now a working link, so the disabled-item check moved to
"Signatures" (equivalent coverage) and the new link's `href` is asserted. The E2E API runs with higher public rate limits
(all requests come from 127.0.0.1); the limits themselves are covered by PHPUnit.

## 15. Windows verification

Final run: `verify-windows.bat` → `verification-logs/verify-20261117-105310.log`, summary 2026-11-17 11:03:04 — **ALL STEPS PASSED (11/11)**.

| Step | Result |
| --- | --- |
| PHP pdo_pgsql loaded | PASS |
| composer validate | PASS |
| php artisan migrate (status) | PASS (Phase 4 migration present) |
| php artisan db:seed (idempotent) | PASS |
| php artisan route:list | PASS (15 new routes: 13 administrator, 2 public — see `api-conventions.md`) |
| PHPUnit | PASS — **173 tests, 2,153 assertions** (140 previous + 33 Phase 4) |
| Pint | PASS |
| ESLint | PASS |
| TypeScript | PASS |
| Production build | PASS (`/client-upload` and `/document-requests` prerendered, request page partially prerendered) |
| Playwright (Phase 1–4, desktop + mobile) | PASS — **46 passed** (39 previous + 7 Phase 4) |

Baseline comparison: Phase 3 ended with 140 PHPUnit tests / 975 assertions and 39 Playwright tests; every one of them still
passes. An earlier run in this phase failed only at the Playwright login set-up step (cold compilation while the manual check
ran concurrently, see §17); the repeated run above is the result of record.

## 16. Manual verification (development stack)

Log: `verification-logs/phase4-manual-check-20261117.log` (no secrets). On http://localhost:3010 with the demo administrator in
the built-in browser; the client side in a separate, private Chromium context (Playwright, no cookies/storage), the link taken
from the clipboard after pressing *Copy link* and never printed:

1. Matter **LEX-2026-0013 "Phase 4 manual verification"** (fictional client Harborline Freight Ltd.); request "Signed Client
   Service Agreement" with link (7 days) → one-time dialog, localhost warning, copied.
2. Client page: only the request title/instructions; upload v1 (889 B) with a message → success. The public API calls sent **no
   cookie and no Referer**; the client context ended with 0 cookies.
3. Admin: status Submitted, SHA-256 matches; preview rendered ("Page 1 of 2"); start review; request correction with a reason →
   correction link copied.
4. Client: correction notice with the reason; upload v2 (1,418 B, 4 pages) → success.
5. Admin: start review → approve v2 → "version v2 was accepted … not a signature"; history shows v1 *Correction requested* (reason)
   and v2 *Approved*.
6. Correction link after approval → "Nothing more is needed"; unknown and malformed links → "This link is not valid"; second
   request's link worked, then *Revoke link* → "This link is not valid".
7. PostgreSQL + disk: request approved with `approved_version_id` = v2; v1/v2 `origin = client`, checksums equal to the files on
   disk under `storage/app/private/documents/13/…`; 0 staging files; 0 PDFs under `backend/public`; only 64-char hashes stored;
   13 activity events with client actors for the submissions; `laravel.log` contains no link or token header.
8. The verification matter was archived afterwards. The OS clipboard was cleared.

## 17. Bugs and fixes

| Symptom | Root cause | Fix | Evidence |
| --- | --- | --- | --- |
| New PHP enum files failed to parse | Windows PowerShell 5.1 `Set-Content -Encoding utf8` writes a BOM before `<?php` | Re-wrote the files without BOM; all later files written with the editor tool | PHPUnit/Pint pass |
| Phase 2 tests: `ArgumentCountError` in `MatterService` | Tests construct the service by hand with two arguments; the new link service was a required third | Optional third parameter resolved from the container | 140 existing tests pass unchanged |
| `DashboardSummaryTest` failed | Exact `stats` arrays did not include the new counters | Expected arrays extended with the six counters (0) | passes |
| Trigger test aborted the transaction (`25P02`) | A failing statement inside the test transaction | Wrapped in a savepoint (`DB::transaction`) | passes |
| Upload rejection test received 429 | The per-token limit (5/min) worked as designed across 6 cases | Limiter reset between cases; the limit has its own test | passes |
| Overdue list (4) ≠ dashboard (3) | List included a request on an archived matter | Outstanding list views exclude archived matters, like the dashboard | `DocumentRequestCountersTest` |
| ESLint: `set-state-in-effect`, `incompatible-library` | Effect-driven state resets; RHF `watch()` | Keyed component, `useSyncExternalStore` for the fragment, derived loading state, `useWatch` | lint passes |
| E2E `requestDetail` got 401 | Helper omitted the SPA Origin/Referer headers (Sanctum stateless) | Use `SPA_HEADERS` | E2E pass |
| E2E Phase 4 tests timed out at 30 s | Multi-context flows on development servers | `test.describe.configure({ timeout: 180_000 })` for the Phase 4 specs | 7/7 pass |
| First full verification: Playwright setup login timed out | The E2E dev server was still compiling `/dashboard` while the manual check ran in parallel (screenshot "Rendering…") | Re-ran the full verification with nothing else running | §15 |

## 18. Git state

Branch `feature/phase-4-client-document-requests`, created from `main` at `9e9e530` (Phase 3 merge, verified: Phase 1–3 code
present, clean tree). Commits:

| Commit | Content |
| --- | --- |
| `9cd7bb0` | Phase 4 backend: migration, models, enums, services, controllers, public API, archive/dashboard integration |
| `9c2ad3d` | Phase 4 backend tests (33) + dashboard test counters |
| `29ba250` | Phase 4 frontend: Client requests tab, request page, `/document-requests`, dashboard card, `/client-upload` |
| `a6ec470` | Phase 4 end-to-end tests, guarded E2E fixture helper, navigation test update, verify label |
| *(this commit)* | Documentation: this report, README, architecture, data model, API conventions, phases |

Not committed (gitignored or never created in the repository): `.env` files, `vendor/`, `node_modules/`, `verification-logs/`,
private PDF storage (`backend/storage/app/private/*`), Playwright artefacts (`test-results/`, `playwright-report/`,
`e2e/.auth/`), `.next*`. No plaintext tokens anywhere.

**Merge status at report time:** no remote was configured, nothing had been pushed, and the branch was not yet merged into
`main` (awaiting authorisation).

**Later:** after explicit authorisation, the branch was merged into `main` with `--no-ff` as `8443455` (parents `9e9e530`
and `748cf23`). `main` and `feature/phase-4-client-document-requests` were then pushed to the private repository. All
seven phases are now on `main`; see [implementation-phases.md](implementation-phases.md).

## 19. Phase 5 readiness

- **Secure signing invitations**: `secure_access_links` already supports `purpose = document_signing`; Phase 5 adds its own
  target column (e.g. `signing_request_id`), endpoints and rules. Upload endpoints reject signing tokens (tested), so permissions
  stay separate. Hashing, expiry, revocation, rotation and the one-time `IssuedLink` are reusable as is.
- **Administrator and client signing**: the public boundary pattern (no session, header token, minimal responses, rate limits)
  is ready for a client signing page; administrators sign inside the authenticated app.
- **One signer per PDF / signed PDF storage**: an approved request records the exact immutable `approved_version_id`; a signing
  request can reference it, and the signed output becomes a new immutable version (`kind = signed`) through
  `DocumentService::persistUpload`/`appendVersion`.
- **Signer information and audit events**: `ActivityLogger::recordClient` already records external actors without inventing
  users; signature events (`signing.*`) are catalogued.

## 20. Phase 6 dependencies

- Email delivery of new request links and correction links (the one-time URL is available only at issue time — Phase 6 must send
  it from the same request that creates the link, via queued mail).
- Follow-up reminders before due dates / link expiry; expiration notices; administrator notifications for submissions.
- Signing progress communications (sent/viewed/started/signed) for Phase 5 requests.
- No email is sent or claimed in Phase 4; the UI states that links must be shared by the administrator.

## 21. Final acceptance checklist

| Criterion | Result | Evidence |
| --- | --- | --- |
| Admin creates a real client document request | Passed | PHPUnit A, E2E A, manual |
| Request belongs to the correct matter and client | Passed | service + trigger; `DocumentRequestCreationTest` |
| Request details persist in PostgreSQL | Passed | E2E A (reload), manual psql |
| Request statuses enforced server-side | Passed | `ReviewAndCorrectionTest` (422/409) |
| Due dates handled correctly | Passed | `LegalDate`, `DocumentRequestCountersTest` (firm time zone) |
| Admin can cancel a request | Passed | PHPUnit, concurrency test |
| Tokens are cryptographically secure | Passed | `SecureLinkTest` (32 bytes CSPRNG) |
| Only token hashes are stored | Passed | `SecureLinkTest`, manual psql |
| Links have a defined purpose | Passed | CHECK + resolver; signing token refused |
| Links expire | Passed | PHPUnit C, E2E D |
| Admin can revoke links | Passed | PHPUnit C, E2E D, manual |
| Admin can rotate links | Passed | PHPUnit C, E2E D |
| Used links cannot upload again | Passed | PHPUnit C, E2E D |
| Invalid links expose no private information | Passed | identical 404 bodies; E2E D/J |
| Parallel requests cannot bypass one-use limits | Passed | `DocumentRequestConcurrencyTest` |
| Raw tokens not in logs or persistent metadata | Passed | `DocumentRequestSecurityTest`, manual log check |
| Public upload page works without login | Passed | E2E A/C/H, manual |
| Client sees only authorised request information | Passed | `ClientUploadTest`, E2E A/J, manual |
| Client can upload a real PDF | Passed | E2E, manual |
| PDF validation enforced | Passed | `ClientUploadTest` (6 invalid file types) |
| File stored privately | Passed | path/disk assertions, manual disk check |
| Correct document and matter association | Passed | PHPUnit D, composite FKs, `DocumentRequestSecurityTest` |
| Request status updates correctly | Passed | PHPUnit, E2E |
| Upload succeeds without an administrator cookie | Passed | `ClientUploadTest` (no session/CSRF), manual (no cookie sent) |
| Failed uploads leave no inconsistent files or records | Passed | `ClientUploadTest` (rollback + cleanup), concurrency storage check |
| Administrator can preview the submitted PDF | Passed | E2E E/F, manual |
| Administrator can start review | Passed | PHPUnit, E2E, manual |
| Administrator can approve a submission | Passed | PHPUnit, E2E, manual |
| Administrator can request corrections | Passed | PHPUnit, E2E F, manual |
| Correction reason stored securely | Passed | on the submission only; not in history (tested) |
| Corrected submissions work through fresh links | Passed | PHPUnit H, E2E F, manual |
| Corrected PDFs create new immutable versions | Passed | v1→v2→v3 test, manual psql |
| Previous versions remain available | Passed | byte checks, E2E F preview v1 |
| Approval references the correct PDF version | Passed | `approved_version_id` assertions, manual |
| Pending requests counted correctly | Passed | `DocumentRequestCountersTest`, E2E G |
| Overdue requests counted correctly | Passed | same |
| Submitted requests awaiting review counted | Passed | same |
| Correction requests tracked | Passed | same |
| Approval and cancellation update counters | Passed | same |
| Search, filters and pagination work | Passed | PHPUnit + E2E G (URL state across reload) |
| Meaningful events recorded | Passed | 13 event types; tests + manual |
| Timeline displays readable labels | Passed | E2E A, manual |
| Audit events remain append-only | Passed | existing trigger; unchanged |
| Internal notes remain private | Passed | E2E J, `ClientUploadTest` |
| Cross-matter requests blocked | Passed | `DocumentRequestSecurityTest` |
| Expired and revoked links rejected | Passed | PHPUnit, E2E, manual |
| Archived matters reject uploads | Passed | PHPUnit (incl. inconsistent state), E2E I |
| No upload token authorises signing | Passed | purpose separation; no signing endpoint exists; signing token refused by upload endpoints |
| No private storage paths exposed | Passed | response assertions |
| Concurrency and rollback tests pass | Passed | `DocumentRequestConcurrencyTest`, rollback test |
| Administrator request-management UI functional | Passed | E2E A–G, I |
| Link-management UI functional | Passed | E2E B/D/I, manual |
| Client upload page professional and responsive | Passed | E2E H (Pixel 7), manual |
| Review and correction UI works | Passed | E2E E/F, manual |
| Error, loading and success states exist | Passed | E2E D (all blocked states), H (validation), A (success) |
| Mobile layout verified | Passed | E2E H |
| Keyboard accessibility preserved | Passed | Radix dialogs, labelled controls, existing a11y tests pass |
| All Phase 1–3 tests still pass | Passed | 140 PHPUnit + 39 Playwright in the final run |
| All new Phase 4 tests pass | Passed | 33 PHPUnit + 7 Playwright |
| PHPUnit / Playwright / ESLint / TypeScript / Pint / build | Passed | §15 |
| Windows verification passes | Passed | 11/11 |
| Manual end-to-end verification succeeds | Passed | §16 |
| Git commits created | Passed | §18 |
| Documentation accurate; Phase 4 report complete | Passed | this report, README and `docs/*` updated |
| Automatic email delivery | Not in scope | Phase 6 (stated in the UI and docs) |
