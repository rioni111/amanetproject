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
│   ├── Controllers/Api/                      AuthController, DashboardController, MatterController, ClientController (thin)
│   ├── Middleware/EnsureUserIsAdmin.php      alias "admin"
│   ├── Requests/                             LoginRequest (+ throttling), IndexMattersRequest, IndexClientsRequest
│   └── Resources/                            User/Client/Matter resources, PaginatedCollection envelope, DashboardSummaryResource
├── Models/                                   User, Client, Matter, Task, MatterNote, ActivityEvent
├── Policies/                                 MatterPolicy, ClientPolicy
├── Providers/AppServiceProvider.php          rate limiters, morph map, strict models, password rules, gates
└── Services/                                 DashboardService (aggregation), ActivityLogger (append-only history)
```

Decisions:

- **No repository layer** — Eloquent scopes (`Matter::active()`, `dueWithin()`, `overdue()`, `Task::open()`) keep queries reusable.
- **Enums** back every status column and generate the PostgreSQL `CHECK` constraints, so PHP and the database agree on valid values.
- `Model::shouldBeStrict()` outside production catches N+1 queries and silently discarded attributes.
- `Relation::enforceMorphMap()` stores stable aliases (`matter`, `task`…) instead of class names in polymorphic columns.
- All timestamps are `timestamptz`, the app and DB session timezone are UTC, and the API emits ISO-8601. The browser formats local times.

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
│   ├── (dashboard)/             AuthGuard + AppShell → dashboard, matters, clients
│   ├── layout.tsx, providers.tsx, not-found.tsx, page.tsx (→ /dashboard)
├── components/
│   ├── ui/                      button, input, label, select, card, badge, skeleton, table, sheet, alert-dialog
│   ├── layout/                  app-shell, app-sidebar, app-header (+ mobile drawer), sidebar-nav, user-panel, nav-config
│   ├── dashboard/               stat-card, status-breakdown, recent-matters, upcoming-deadlines
│   └── shared/                  page-header, status/priority badges, deadline, data-table, pagination, search-input,
│                                empty/error/loading states, confirm-dialog, form-field, brand-mark, client-avatar
├── features/                    auth (api, hooks, guard, login form), dashboard, matters, clients
├── hooks/                       use-url-filters, use-now
├── lib/api/                     client, errors, config        lib/utils/  cn, format, labels
├── types/api.ts                 types mirroring API Resources
└── proxy.ts                     optimistic redirect for guests
```

Design system: tokens in `src/app/globals.css` (warm off-white `#FAFAF8`, charcoal `#111827`, teal accent `#0F766E`, restrained status
colours; all text pairs ≥ 4.5:1). Geist Sans/Mono/Serif are bundled locally. `prefers-reduced-motion` disables animation. Security
headers (CSP, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy) are set in `next.config.ts`.

## 6. Future document processing (Phase 3 — design)

- PDFs are stored on a **private** disk (`storage/app/private/documents/{matter_id}/{uuid}.pdf`), never under `public/`. Filenames on
  disk are random UUIDs; the original name is metadata only.
- Upload validation: MIME sniffing (`application/pdf`), extension, size limit, and a `%PDF-` header check; SHA-256 checksum stored per
  version for integrity and de-duplication.
- `documents` holds the logical document; `document_versions` holds immutable files (`version_number` unique per document). A new upload
  creates a new version; previous versions remain downloadable.
- Preview/download go through authorised controller actions that stream the file (`Content-Disposition`, `X-Content-Type-Options`).
  Possessing a document ID is never sufficient — the policy checks the matter.

## 7. Future secure-link flow (Phase 4 — design)

```
Admin creates request ──► random 256-bit token (Str::random(64)/random_bytes) ──► store SHA-256(token) in secure_access_links
                                    │                                               + purpose, resource, expires_at, max_uses
                                    └──► plaintext token only in the emailed URL: https://app/r/{token}
Client opens link ──► API hashes presented token ──► lookup by hash ──► check: not revoked, not expired, purpose matches,
                                                                          resource matches, use count ──► allow ONE operation
```

- Tokens are **single-purpose** (`upload_document`, `sign_document`) and bound to one resource; internal notes and other matter data
  are never reachable through them.
- Expiry and revocation are first-class columns; every use is recorded as an activity event with IP/user-agent metadata.
- Public endpoints get their own rate limiter and return identical 404s for unknown/expired/revoked tokens.

## 8. Future signing workflow (Phase 5–6 — design)

- **One signer per PDF** (assignment constraint): a `signing_request` references exactly one `document_version` and one signer
  (name + email snapshot, optionally a client id).
- Status machine: `sent → viewed → started → signed` (plus `revoked`, `expired`), each transition appended to `signature_events`
  with timestamp, IP and user agent. Events are append-only, like `activity_events`.
- Admin signing and client signing share the same pipeline; the client path is authorised by a `sign_document` secure link.
- Signature placement and drawing happen in the browser; the server stamps the signature image and an audit footer onto the PDF
  (PHP library, no external signing platform), computes a SHA-256 of the result and stores it as a **new immutable document version**
  linked from the signing request (`signed_document_version_id`).
- Phase 6 adds email invitations/follow-ups (queued mail, Mailpit in development) and admin notifications for each status change.
