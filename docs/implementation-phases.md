# Implementation phases

Seven-day delivery plan. ✅ = implemented and verified · 🟡 = foundation only · ⬜ = planned.

| # | Phase | Status |
| --- | --- | --- |
| 1 | Foundation, Architecture, Database, Authentication & UI | ✅ Complete |
| 2 | Matter, Client, Task & Notes Management | ✅ Complete ([report](PHASE_2_IMPLEMENTATION_REPORT.md)) |
| 3 | Document Management & Version Control | ✅ Complete ([report](PHASE_3_IMPLEMENTATION_REPORT.md)) |
| 4 | Secure Client Document Requests & Review | ⬜ Planned |
| 5 | Electronic Document Signing | ⬜ Planned |
| 6 | Email, Notifications, Signing Progress & Activity | ⬜ Planned |
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

## 4. Secure Client Document Requests & Review — ⬜

- `document_requests`, `secure_access_links` (hashed, expiring, revocable, single-purpose tokens)
- Public upload page (no account); admin review: accept or request corrections

## 5. Electronic Document Signing — ⬜

- `signing_requests`, `signature_events`; one signer per PDF
- Admin signing and client signing via secure link; server-side PDF stamping; immutable signed version + audit trail

## 6. Email, Notifications, Signing Progress & Activity — ⬜

- Queued emails (invitations, upload requests, follow-ups) via Mailpit in development
- Sent → Viewed → Started → Signed tracking; admin notifications; full matter activity timeline

## 7. Testing, Security Hardening, UI Polish & Delivery — ⬜

- Broader test coverage, security review (headers, rate limits, token handling), accessibility audit
- Final README, demo script, known limitations, optional hosted demo
