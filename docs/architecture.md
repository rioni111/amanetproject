# Architecture

## 1. Overview

```
 Browser (Next.js SPA, http://localhost:3000)
   │  fetch(..., { credentials: "include" })   cookies: lexora_session (HTTP-only), XSRF-TOKEN
   ▼
 Laravel API (http://localhost:8000)
   ├── /sanctum/csrf-cookie         issues XSRF-TOKEN
   └── /api/*                       JSON only (statefulApi → session + CSRF for SPA origins)
        │  Form Requests → Controllers (thin) → Services → Eloquent → API Resources
        ▼
 PostgreSQL 16 (FKs, CHECK constraints, append-only trigger)
```

**Responsibilities**

| Concern | Owner |
| --- | --- |
| UI, routing, client state, form UX | Next.js (`frontend/`) |
| Authentication, authorisation, validation, business rules, persistence | Laravel (`backend/`) |
| Data integrity (last line of defence) | PostgreSQL constraints and triggers |

Laravel renders no Blade UI. The only web route is a JSON status endpoint at `/`.

## 2. Frontend → backend communication

- The browser calls the API **directly** (cross-origin) with `credentials: "include"`. CORS (`backend/config/cors.php`) allows exactly
  `FRONTEND_URL`, with `supports_credentials: true` and a fixed header allow-list.
- `frontend/src/lib/api/client.ts` is the single HTTP entry point:
  - adds `Accept: application/json` and `X-Requested-With`;
  - for `POST/PUT/PATCH/DELETE`, ensures the `XSRF-TOKEN` cookie exists (`GET /sanctum/csrf-cookie`) and echoes it as `X-XSRF-TOKEN`;
  - retries once after refreshing the CSRF cookie on **419**;
  - normalises every failure into `ApiError { status, message, fieldErrors, retryAfter }` (`lib/api/errors.ts`). Messages from 5xx
    responses are never shown verbatim.
- Server state is managed by **TanStack Query** (`features/*/api.ts`). A global `QueryCache.onError` clears the current user on any 401,
  which makes `<AuthGuard>` redirect to `/login`.
- List filters live in the URL (`hooks/use-url-filters.ts`) so views are shareable; every URL value is validated before use.

## 3. Authentication

Laravel Sanctum **SPA (session cookie) authentication**. No bearer tokens are issued and nothing auth-related is stored in
`localStorage`/`sessionStorage`.

```
1. GET  /sanctum/csrf-cookie               → Set-Cookie: XSRF-TOKEN (readable by JS), lexora_session (HTTP-only)
2. POST /api/auth/login  + X-XSRF-TOKEN    → LoginRequest: validate → rate-limit check → Auth::attempt(email, password, role=admin)
                                             → session()->regenerate()  (prevents fixation) → 200 { data: user }
3. GET  /api/auth/me                       → 200 { data: user } | 401
4. POST /api/auth/logout + X-XSRF-TOKEN    → logout, session()->invalidate(), regenerateToken() → 204
```

| Protection | Implementation |
| --- | --- |
| Password hashing | bcrypt via the `hashed` cast (`BCRYPT_ROUNDS=12`) |
| CSRF | Sanctum `statefulApi()` → `ValidateCsrfToken` on SPA requests; never disabled |
| Brute force | 5 failed attempts per email+IP → 429 with `Retry-After` (`LoginRequest`); 20 login requests/min per IP (`throttle:login`) |
| Session fixation | `session()->regenerate()` after login; `invalidate()` + new CSRF token on logout |
| Cookies | `lexora_session`: HTTP-only, `SameSite=Lax`, encrypted, `Secure` in production (`SESSION_SECURE_COOKIE`) |
| Non-browser logins | A login request without an SPA `Origin`/`Referer` has no session and is refused with 400 |
| Enumeration | Same generic error for unknown email and wrong password |
| Authorisation | `auth:sanctum` + `admin` middleware on every protected route, **plus** policies (`MatterPolicy`, `ClientPolicy`) and the `view-dashboard` gate |

**Frontend route protection** has two layers, neither of which is the security boundary:

1. `src/proxy.ts` (Next 16's replacement for middleware) redirects to `/login?next=…` when no session cookie exists — this avoids
   rendering the shell for obvious guests.
2. `<AuthGuard>` calls `/api/auth/me` and renders nothing private until the API confirms the session.

Laravel authorises every data request regardless.

## 4. Backend structure

```
app/
├── Console/Commands/CreateAdminCommand.php   php artisan lexora:create-admin
├── Enums/                                    UserRole, ClientType, MatterStatus, Priority, TaskStatus, ActorType, ActivityEventType
├── Http/
│   ├── Controllers/Api/                      Auth, Dashboard, Matter, MatterLifecycle (status/archive/restore), MatterTask,
│   │                                         MatterNote, MatterActivity, Client (all thin)
│   ├── Middleware/EnsureUserIsAdmin.php      alias "admin"
│   ├── Requests/                             LoginRequest; Clients/, Matters/, Tasks/, Notes/ (store/update/index/status/lifecycle)
│   └── Resources/                            User/Client/Matter/Task/MatterNote/ActivityEvent resources + PaginatedCollection envelope
├── Models/                                   User, Client, Matter, Task, MatterNote, ActivityEvent; Concerns/HasLockVersion
├── Policies/                                 MatterPolicy (view/create/update/archive/restore), ClientPolicy (+create/update/delete)
├── Providers/AppServiceProvider.php          rate limiters, morph map, strict models, password rules, gates
├── Services/                                 DashboardService, ActivityLogger, MatterService (lifecycle), MatterNumberGenerator,
│                                             TaskService, MatterNoteService, ClientService,
│                                             Documents/ (DocumentService, DocumentStorage, PdfInspector)
└── Support/LegalDate.php                     calendar-date ⇄ timestamptz conversion in the firm's time zone
```

Phase 2 write path (every mutation):

```
Form Request (authorize via policy, validate, reject server-managed fields)
  → Service: DB::transaction {
        SELECT … FOR UPDATE (matter, then task/note)      ← serialises concurrent changes per matter
        assert lock_version (409) · assert not archived (409) · validate transition (422)
        write change
        ActivityLogger::record(...)                       ← same transaction: no change without its history
     }
  → API Resource (reloaded with counters)
```

Tasks and notes are nested routes with **scoped bindings** (`/matters/{matter}/tasks/{task}` is 404 unless the task belongs to that
matter); the creator/author always comes from the session.

Decisions:

- **No repository layer** — Eloquent scopes (`Matter::active()`, `dueWithin()`, `overdue()`, `Task::open()`) keep queries reusable.
- **Enums** back every status column and generate the PostgreSQL `CHECK` constraints, so PHP and the database agree on valid values.
- `Model::shouldBeStrict()` outside production catches N+1 queries and silently discarded attributes.
- `Relation::enforceMorphMap()` stores stable aliases (`matter`, `task`…) instead of class names in polymorphic columns.
- All timestamps are `timestamptz`, the app and DB session timezone are UTC, and the API emits ISO-8601. The browser formats local times.
- Legal dates (deadlines, opening dates) are calendar dates in the firm's time zone (`LEXORA_TIMEZONE`) — see data-model.md.
- Matter status changes are an explicit action with a documented transition policy (data-model.md, "Matter lifecycle").

### Dashboard definitions

Defined once in `MatterStatus::active()` / `TaskStatus::openValues()` and `DashboardService`:

| Figure | Definition |
| --- | --- |
| Active matter | status ∈ {`open`, `in_progress`, `on_hold`} |
| Upcoming deadline | active matter with `due_at` between now and the end of day *now + N*; N = `LEXORA_UPCOMING_WINDOW_DAYS` (default **14**) |
| Overdue matter | active matter with `due_at` < now |
| Open task | status ∈ {`todo`, `in_progress`} |
| Overdue task | open task with `due_at` < now |
| Recent matters | 6 most recently updated matters |

## 5. Frontend structure

```
src/
├── app/
│   ├── (auth)/login/            sign-in page
│   ├── (dashboard)/             AuthGuard + AppShell → dashboard, matters, matters/[id], clients, clients/[id]
│   ├── layout.tsx, providers.tsx (TanStack Query + toasts), not-found.tsx, page.tsx (→ /dashboard)
├── components/
│   ├── ui/                      button, input, textarea, label, select, card, badge, skeleton, table, sheet, dialog, tabs, alert-dialog
│   ├── layout/                  app-shell, app-sidebar, app-header (+ mobile drawer), sidebar-nav, user-panel, nav-config
│   ├── dashboard/               stat-card, status-breakdown, recent-matters, upcoming-deadlines
│   └── shared/                  page-header, status/priority badges, deadline, data-table, pagination, search-input, toaster,
│                                empty/error/loading states, confirm-dialog, form-field, form-alert, brand-mark, client-avatar
├── features/                    auth, dashboard, clients (list, form dialog, detail), matters (list, form dialog, actions,
│                                detail workspace), tasks (panel), notes (panel), activity (timeline), documents (library,
│                                matter tab, detail + version history, upload dialog, PDF.js viewer)
├── hooks/                       use-url-filters, use-now
├── lib/api/                     client (get/post/patch/delete), errors, config, invalidate
├── lib/                         forms (server errors → fields, submit guard)   lib/utils/  cn, format, labels
├── types/api.ts                 types mirroring API Resources
└── proxy.ts                     optimistic redirect for guests
```

State (Phase 2): server state lives only in TanStack Query. Mutations never update the UI optimistically; after the API confirms,
`invalidateWorkspace()` refreshes the affected lists, the matter (detail, tasks, notes, activity) and the dashboard, and success toasts
are shown only then. Forms use React Hook Form + Zod mirroring the Form Requests; 422 errors are mapped onto fields, 409 conflicts
shown as a banner; a synchronous submit guard prevents double submissions. Edit forms reset only when opened and send the
`lock_version` they loaded.

Design system: tokens in `src/app/globals.css` (warm off-white `#FAFAF8`, charcoal `#111827`, teal accent `#0F766E`, restrained status
colours; all text pairs ≥ 4.5:1). Geist Sans/Mono/Serif are bundled locally. `prefers-reduced-motion` disables animation. Security
headers (CSP, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy) are set in `next.config.ts`.

## 6. Document management (Phase 3 — implemented)

```
Browser ──multipart (XHR, real progress)──► POST /api/matters/{m}/documents      (Form Request: size, .pdf, PdfInspector)
                                              │
                                    DocumentService
                                      1. SHA-256 of the upload
                                      2. stage → _staging/{uuid}.part, verify size + SHA-256   (DocumentStorage)
                                      3. move → {matter}/{document_uuid}/{version_uuid}.pdf      (never overwritten)
                                      4. DB transaction: lock matter (→ document), duplicate check, version number,
                                         documents + document_versions rows, activity event
                                      5. failure → delete only this request's new file; staging always removed
Browser ◄── fetch (session cookie) ── GET …/versions/{v}/preview|download  (scoped bindings, policy, exists, SHA-256 re-check)
   └─ PDF.js 6 renders pages to <canvas> only (no annotation/script layer) inside the app's CSP
```

- **Storage**: Laravel disk `documents` = `storage/app/private/documents` (local driver, `serve => false`; Laravel's
  `local` disk also had serving switched off). Never under `public/`. `DOCUMENTS_STORAGE_DIR` selects the folder (the E2E
  server uses `e2e-documents`, PHPUnit fakes the disk). Paths are server-generated and constrained by a database CHECK.
- **Integrity**: versions are immutable (trigger), unique per document, current version enforced by a composite FK; every
  file read re-verifies the stored SHA-256.
- **Concurrency**: version numbers come from `documents.latest_version_number`, incremented under the document's row lock
  (same lock order as every other matter write: matter, then child); the unique constraint is the backstop.
- **Validation**: byte-level (`PdfInspector`): libmagic MIME, `%PDF-` signature, `%%EOF`/`startxref` (truncation),
  `/Encrypt` (unsupported), size limit (`DOCUMENTS_MAX_UPLOAD_MB`, PHP limits raised to 25M/30M by setup). Not a malware scanner.
- **Audit**: uploads, versions, metadata changes, archive/restore and downloads are recorded in the same transaction pattern
  as Phase 2 (downloads are appended after authorisation); inline previews are not, to keep the timeline meaningful.

`origin` (`admin`/`client`) and `kind` (`upload`/`signed`) on versions: client submissions (Phase 4) arrive as new
documents/versions with `origin = client`, and signed PDFs (Phase 5) are appended as `kind = signed` versions through the same
service and storage; signing requests reference an immutable `document_versions.id`.
## 7. Client document requests and secure links (Phase 4 — implemented)

```
Admin (Sanctum session) ──► POST /api/matters/{m}/document-requests [issue_link]          DocumentRequestService
                              token = base64url(random_bytes(32))  (256 bits, CSPRNG)    SecureAccessLinkService
                              store SHA-256(token) only ─► secure_access_links (purpose document_upload, expires_at, max_uses 1)
                              response ONCE: { link: { url: https://<public app>/client-upload#<token> } }   (never stored/logged)
Admin copies the URL and sends it to the client (Phase 6 will email it)

Client browser (no account, no cookies) ──► /client-upload#<token>        (static page; the fragment never reaches a server)
   page reads the fragment ──► GET  /api/public/document-upload           header X-Upload-Token: <token>, credentials: omit
                           ──► POST /api/public/document-upload/submit    multipart PDF (+ optional message), real XHR progress
API public group: no session/CSRF middleware ─► throttle ─► AuthorizePublicUploadLink (Origin allow-list, token → link,
                  purpose/expiry/revocation/use/lifecycle/archive checks) ─► PdfInspector rules ─► ClientSubmissionService:
                  DocumentService::persistUpload (stage, verify, move) + ONE transaction: lock matter → request → link (→ document),
                  re-check the link, new document (v1) or next version of the same document, submission row, request → submitted,
                  link consumed, activity event (actor: client) ─► failure: new file deleted, link untouched
Admin reviews ──► start-review ─► approve (records the exact version; revokes links) | request-correction (reason + new link)
```

- **Public boundary**: `routes/api.php` registers `/api/public/document-upload*` with `withoutMiddleware(EnsureFrontendRequestsAreStateful)`:
  no session is started, no admin cookie is read, and CSRF (a defence for cookie-authenticated requests) does not apply. The only
  authority is the purpose-scoped token. The Origin header, when present, must be a configured frontend origin (extra browser-side
  defence, not a substitute). Responses carry `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`.
- **Token transport**: in the URL *fragment* (`#…`) for the page and in the `X-Upload-Token` header for the API — never in a request
  path or query, so it cannot appear in `php artisan serve`/Next.js request logs, proxies, Referer headers or server caches.
  The frontend keeps it in memory only (no storage, no TanStack Query cache) and calls the API with `credentials: "omit"`.
- **One usable link per request** (partial unique index); rotation revokes the previous link in the same transaction; archiving a
  matter revokes all its usable links in the archive transaction (restoring never reactivates them); approval and cancellation revoke
  too. The public API independently re-checks the matter, the request status and the client association on every call.
- **Errors**: unknown, malformed, wrong-purpose and manually revoked/replaced tokens give one identical 404 (`link_invalid`);
  holders of a real token get 410 explanations (`link_expired`, `link_used`, `request_approved`, `request_cancelled`,
  `request_unavailable`). Repeated failed lookups lock an IP out for 15 minutes.
- **Rate limits** (config `lexora.public_links`): page/API views 60/min per IP, submissions 10/min per IP and 5/min per token,
  20 failed lookups per IP → lockout. The Playwright environment raises the per-IP values (everything runs from 127.0.0.1).
- **Purpose separation**: `secure_access_links.purpose` is `document_upload` or `document_signing` (Phase 5); each public API only
  accepts its own purpose, so a signing token is "unknown" to the upload endpoints and vice versa.
- **Email boundary**: Phase 4 never claims a link was emailed. Links are copied by the administrator; Phase 6 will send them through
  queued mail using the same one-time URL returned by `IssuedLink`.

## 8. Electronic PDF signing (Phase 5 — implemented)

```
Admin "Sign PDF" (document page) ─► GET  …/documents/{d}/versions/{v}/signing-eligibility   (page sizes + consent text, or why not)
                                 ─► POST …/documents/{d}/sign          SigningService::signNowAsAdmin (create + sign, one transaction)
Admin Signatures tab ─► POST …/signing-requests [issue_link] ─► link shown ONCE ─► copy ─► POST …/mark-shared  (Ready → Sent)
Client /client-sign#<token> (no account, credentials: omit, header X-Signing-Token)
   GET /api/public/signing ─► GET …/document (pinned source, SHA-checked) ─► POST …/view (rendered → Viewed)
   ─► POST …/start ("Start signing" → Started) ─► POST …/complete (signature + placement + confirmed name + consent)

SigningService::complete()  (admin and client share it)
  1 validate: SignatureAppearance (drawn strokes | typed name), SignaturePlacement (page, x, y, width → points), consent version,
    confirmed name = designated signer (case/space/accent-insensitive); admin name always from the session
  2 source file exists and matches its SHA-256; PdfSigningService::inspect → displayed page sizes
  3 PdfSigningService::sign (FPDI imports every page, FPDF draws vector strokes or Times-Italic text + caption) → temp file
    → verifyOutput (re-parse from memory: page count, sizes, caption)
  4 DocumentService::persistLocalFile (private staging → SHA-256 → final path) + ONE transaction:
    lock matter → document → request (→ link); re-check archive, source still current, status, link usable;
    appendVersion(kind = signed) ; signature_records insert ; request → signed ; link consumed (conditional) ;
    activity signing.completed + document.signed
  5 failure anywhere → rollback, delete only this attempt's file, temp removed → error, never success
```

- **One signer per PDF**: one `signer_type` with exactly one of `signer_user_id`/`client_id` (CHECKs); one active request per
  document (partial unique index); signed versions are never signing sources (insert trigger); one signature record per request.
- **Progress** lives on `signing_requests` (`status` + `sent_at/viewed_at/started_at/signed_at`, server time, forward-only, CHECK
  ordered). "Sent" is reached only by the administrator confirming they shared the link (`delivery_channel = manual`); delivery is
  kept separate from progress so Phase 6 can add e-mail outcomes without changing the signing state machine.
- **Lifecycle** (`SigningLifecycle`): archiving a matter or document, or adding a newer version, cancels unfinished requests with a
  reason and revokes their links in the same transaction; restoring revives nothing.
- **Coordinates**: the browser sends fractions of the *displayed* page (PDF.js applies `/Rotate`); FPDI imports pages with the same
  rotation, so the stored rectangle (`placement.rect_pt`) is exactly where the signature appears.
- **Public boundary**: identical to Phase 4 (no session/CSRF middleware, Origin allow-list, `no-store`/`no-referrer`/`noindex`,
  token only in the fragment and a header, IP lockout after failed lookups) with its own rate limiters
  (`public-signing`, `public-signing-complete`) and its own resolver/middleware (`PublicSigningLinkResolver`,
  `AuthorizePublicSigningLink`).
- **Evidence**: `signature_records` (immutable) holds the signer snapshot, confirmed name, method, page and placement, source and
  signed version ids + SHA-256, the versioned consent text, server time, IP and user agent. It is not a certificate-based signature.
- Phase 6 adds e-mail invitations, reminders and expiry notices (queued mail, Mailpit in development) and admin notifications.
