# Implementation phases

Seven-day delivery plan. ✅ = implemented and verified · 🟡 = foundation only · ⬜ = planned.

| # | Phase | Status |
| --- | --- | --- |
| 1 | Foundation, Architecture, Database, Authentication & UI | ✅ Complete |
| 2 | Matter, Client, Task & Notes Management | ✅ Complete ([report](PHASE_2_IMPLEMENTATION_REPORT.md)) |
| 3 | Document Management & Version Control | ✅ Complete ([report](PHASE_3_IMPLEMENTATION_REPORT.md)) |
| 4 | Secure Client Document Requests & Review | ✅ Complete ([report](PHASE_4_IMPLEMENTATION_REPORT.md)) |
| 5 | Electronic Document Signing | ✅ Complete, merged into `main` ([report](PHASE_5_IMPLEMENTATION_REPORT.md)) |
| 6 | Email, Notifications, Signing Progress & Activity | ✅ Complete, merged into `main` ([report](PHASE_6_IMPLEMENTATION_REPORT.md)) |
| 7 | Testing, Security Hardening, UI Polish & Delivery | ⬜ Planned |

## 1. Foundation, Architecture, Database, Authentication & UI — ✅

- ✅ Monorepo, Docker Compose (PostgreSQL + Mailpit), environment templates
- ✅ Tables: users (role), clients, matters, tasks, matter_notes, activity_events (append-only) with FKs, indexes, CHECKs
- ✅ Idempotent demo seeder; `lexora:create-admin` command
- ✅ Sanctum session auth (login/me/logout), CSRF, rate limiting, session regeneration, admin middleware, policies
- ✅ Dashboard aggregation service + endpoint; read-only matters and clients endpoints with filters and pagination
- ✅ Next.js app shell, login, dashboard, matters and clients views, mobile navigation, loading/empty/error states
- ✅ PHPUnit, Playwright, lint, typecheck and build passing; documentation
- 🟡 `ActivityLogger`, `ConfirmDialog`, `FormField`, `PaginatedCollection` are ready for Phase 2 usage

## 2. Matter, Client, Task & Notes Management — ✅

- ✅ Clients: create, edit, detail page (`/clients/[id]`), retention-safe delete (only without matters)
- ✅ Matters: create (concurrency-safe `LEX-YYYY-NNNN` numbering), edit, status transitions per documented policy,
  priority changes, date-only deadlines, archive and restore (to the previous status), read-only archived matters
- ✅ Matter workspace (`/matters/[id]`): overview, tasks (CRUD, complete/reopen), internal notes (CRUD), activity timeline
- ✅ Every change and its activity event in one transaction; optimistic concurrency (`lock_version`, 409 on stale edits)
- ✅ Search/filter/sort extended (client reference, matter type, deadline filter, client filter, created/opened sorts)
- ✅ 51 new PHPUnit tests (incl. a multi-process numbering race) and 15 new Playwright tests on an isolated E2E database
- Search across documents follows with documents in Phase 3

## 3. Document Management & Version Control — ✅

- ✅ `documents`, immutable `document_versions` (trigger), composite current-version FK, concurrency-safe numbering
- ✅ Private `documents` disk (not web-served), staged uploads with SHA-256 verification and failure cleanup
- ✅ Byte-level PDF validation (finfo, signature, truncation, encryption), configurable 20 MB limit, duplicate policy
- ✅ Upload (matter tab and library), PDF.js preview (pages, navigation, zoom), authorised download with integrity check
- ✅ Version history (preview/download any version), metadata edit, document archive/restore, archived matters read-only
- ✅ Library `/documents`: search (title, file name, matter, client, category), filters, sorting, pagination in the URL
- ✅ Document events in the append-only timeline (downloads audited); 30 PHPUnit + 6 Playwright tests

## 4. Secure Client Document Requests & Review — ✅

- ✅ `document_requests`, `secure_access_links` (256-bit tokens, SHA-256 stored, purpose-scoped, expiring, revocable, one use),
  `document_request_submissions`; integrity triggers (client = matter client, no client change with open requests, immutable
  submissions/decisions), one usable link per request (partial unique index)
- ✅ Admin: Client requests tab, `/document-requests` list (search, status groups, overdue, sorting, pagination in the URL),
  request page with one-time link reveal + copy, replacement, revocation, cancel, edit
- ✅ Public `/client-upload#token` page (no account, no cookies, token never in a URL path or log) and isolated public API
- ✅ Client uploads reuse the Phase 3 pipeline: first submission = new document (origin client, v1), corrections = next version of
  the same document; links consumed only with a committed version; archive revokes links
- ✅ Review: start review, approve (exact version recorded, links revoked), request correction (reason + fresh link), history
- ✅ Dashboard and matter counters (pending, awaiting review, corrections, overdue, approved); 13 new activity event types
- ✅ 33 PHPUnit tests (incl. multi-process races) + 7 Playwright tests (desktop scenarios A–G, I, J and Pixel 7 scenario H)
- ✅ Email delivery of links added in Phase 6 (Copy link remains the manual alternative)

## 5. Electronic Document Signing — ✅

- ✅ `signing_requests` (one designated signer, pinned immutable source version, status + server timestamps, delivery kept
  separate from progress) and immutable `signature_records`; `secure_access_links.signing_request_id` (purpose `document_signing`)
- ✅ Database guards: composite FKs, exactly one signer, status/timestamp consistency and order, one active request per document,
  only original (`upload`) versions signable, signer/source never reassigned, finished requests final, evidence immutable
- ✅ In-house PDF engine (FPDI 2.6.10 + FPDF 1.8.2, MIT): vector drawn or Times-Italic typed signature + "Electronically signed by"
  caption on the chosen page, all pages preserved, output re-parsed and verified; unsupported PDFs refused cleanly
- ✅ Signed PDF = new immutable version (`kind = signed`, SHA-256) on the private disk; originals never change; failure cleanup
- ✅ Admin signing ("Sign PDF" / "Sign now") and passwordless client signing (`/client-sign#token`): review, draw or type, place,
  confirm name, explicit consent; Ready → Sent (admin-confirmed sharing) → Viewed → Started → Signed, Cancelled on archive/new version
- ✅ Secure links: hashed 256-bit tokens, expiry, rotation, revocation, purpose isolation, consumed only by a completed signature
- ✅ `/signatures`, matter Signatures tab, request page (progress, link card, evidence), dashboard counters, 10 activity event types
- ✅ 28 PHPUnit tests (incl. multi-process races, failure injection, every PDF compatibility refusal) + 7 Playwright tests
  (desktop scenarios A–I, K, keyboard-only L and Pixel 7 J); final Windows verification 201 PHPUnit / 53 Playwright, all passed
- ⬜ Not a qualified/certificate-based signature; no independent identity verification (e-mail invitations added in Phase 6)

## 6. Email, Notifications, Signing Progress & Activity — ✅

- ✅ Real SMTP through Laravel Mail (Mailpit in development, isolated `mailpit-e2e` inbox for tests, any SMTP provider in
  production via environment variables); `MAIL_TIMEOUT`, reply-to, readiness endpoint without secrets, production HTTPS guard
- ✅ Document request, correction, reminder and signing invitation e-mails (responsive HTML + plain text, escaped content,
  no matter details in subjects); recipient always derived by the server
- ✅ Synchronous send after commit with deferred link registration: the token exists only in memory and in the message; its
  hash is stored only after SMTP acceptance; failures keep the earlier link; no queue, no token in any table or log
- ✅ `email_deliveries` (honest statuses `sending/accepted/failed/skipped`, safe failure categories, server acceptance ID,
  idempotency keys, one in-flight send per request, guard trigger); signing Ready → Sent only on acceptance (`email_accepted`)
- ✅ Scheduler `lexora:communications` every 5 minutes: due-date and fallback document reminders, signing reminders, cap 3,
  24 h cooldown, slot idempotency, firm time zone, stale-send recovery, one-time overdue and expired-link notifications
- ✅ Administrator notifications (database channel): bell with unread count, panel, `/notifications`, per-user read state
- ✅ E-mail history (request, signing, matter), `email.accepted` / `email.failed` activity, dashboard e-mail figures
- ✅ 35 PHPUnit tests (incl. real SMTP integration and multi-process races) + 6 Playwright tests that click the links in
  delivered messages; final Windows verification 236 PHPUnit / 59 Playwright, all passed
- ⬜ No inbox-delivery/bounce tracking (needs provider webhooks); SPF/DKIM/DMARC not configured; no automatic retry

## 7. Testing, Security Hardening, UI Polish & Delivery — ⬜

- Broader test coverage, security review (headers, rate limits, token handling), accessibility audit
- Final README, demo script, known limitations, optional hosted demo
