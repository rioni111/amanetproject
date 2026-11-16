# Implementation phases

Seven-day delivery plan. ✅ = implemented and verified · 🟡 = foundation only · ⬜ = planned.

| # | Phase | Status |
| --- | --- | --- |
| 1 | Foundation, Architecture, Database, Authentication & UI | ✅ Complete |
| 2 | Matter, Client, Task & Notes Management | ⬜ Planned |
| 3 | Document Management & Version Control | ⬜ Planned |
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

## 2. Matter, Client, Task & Notes Management — ⬜

- Create/update clients; create/update/close/archive matters (matter number generator); status transition rules
- Matter detail page: overview, tasks (create/complete), internal notes, activity timeline
- Every change wrapped in a transaction and recorded through `ActivityLogger`
- Global search across matters, clients (documents in Phase 3)

## 3. Document Management & Version Control — ⬜

- `documents`, `document_versions`; private storage; PDF validation; SHA-256 checksums
- Upload, in-app preview, authorised download, version history

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
