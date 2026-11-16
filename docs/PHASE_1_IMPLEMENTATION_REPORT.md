# Phase 1 Implementation Report — Lexora Workspace (Amanet)

**Phase:** 1 of 7 — Foundation, Architecture, Database, Authentication & Premium UI
**Report date:** 16 November 2026
**Status:** ✅ Phase 1 acceptance criteria met. All automated checks listed in §6 were executed and passed.

This report documents only functionality that exists in the codebase. Planned work is labelled as such.

---

## 1. Implementation overview

### Completed

- Monorepo with a **Laravel 12 API** (`backend/`) and a **Next.js 16 frontend** (`frontend/`), plus Docker Compose for PostgreSQL 16 and Mailpit.
- **PostgreSQL schema** for users (admin role), clients, matters, tasks, internal notes and append-only activity events, with foreign keys,
  deliberate delete behaviour, indexes, `CHECK` constraints and a database trigger that blocks edits/deletes of history.
- **Idempotent demo seeder** (8 clients, 10 matters, 15 tasks, 6 notes, 42 events) and an environment-safe `lexora:create-admin` command.
- **Sanctum SPA session authentication**: CSRF cookie flow, login, current user, logout; login rate limiting (per email+IP and per IP);
  session regeneration and invalidation; HTTP-only, `SameSite=Lax`, encrypted session cookie; no tokens in web storage.
- **Authorisation** on every protected route (`auth:sanctum` + `admin` middleware) plus policies/gates.
- **Dashboard aggregation service** and endpoint returning real database figures; **read-only** paginated, filterable, sortable
  matters and clients endpoints.
- Consistent **JSON response and error envelopes**; validation through Form Requests; output through API Resources.
- **Premium responsive UI**: login page, application shell (desktop sidebar, mobile drawer, header with breadcrumb and admin identity),
  dashboard, matters and clients views, with loading skeletons, empty states, error states with retry, URL-synchronised filters.
- **Tests**: 59 PHPUnit feature tests (218 assertions) on PostgreSQL; 19 Playwright end-to-end tests on the real stack (desktop + mobile);
  ESLint, TypeScript and production build checks.
- **Documentation**: README, architecture, data model, API conventions, phase roadmap and this report.

### Partially completed (foundation only, by design)

- `ActivityLogger` service exists and is used by the seeder; controllers will use it from Phase 2.
- `ConfirmDialog`, `FormField`, `PaginatedCollection` are ready for Phase 2 forms/lists (`FormField` is already used by the login form).
- "Coming soon" navigation entries (Documents, Client requests, Signatures) are shown **disabled** and announce that they are unavailable.

### Not implemented (out of Phase 1 scope)

CRUD for matters/clients/tasks/notes, detail pages, documents, secure links, client uploads, signing, email, notifications.

---

## 2. Project structure

```
amanet-legal-workspace/
├── README.md                         Setup, demo login, testing, troubleshooting, limitations
├── docker-compose.yml                PostgreSQL 16 (+ test DB init) and Mailpit
├── docker/postgres/init/             01-create-test-database.sql
├── docs/                             architecture, data-model, api-conventions, implementation-phases, this report
├── backend/                          Laravel 12 API
│   ├── app/Console/Commands/         CreateAdminCommand
│   ├── app/Enums/                    UserRole, ClientType, MatterStatus, Priority, TaskStatus, ActorType, ActivityEventType, HasValues
│   ├── app/Http/Controllers/Api/     AuthController, DashboardController, MatterController, ClientController
│   ├── app/Http/Middleware/          EnsureUserIsAdmin
│   ├── app/Http/Requests/            Auth/LoginRequest, Matters/IndexMattersRequest, Clients/IndexClientsRequest
│   ├── app/Http/Resources/           User/Client/ClientSummary/Matter resources, Matter/ClientCollection, PaginatedCollection, DashboardSummaryResource
│   ├── app/Models/                   User, Client, Matter, Task, MatterNote, ActivityEvent
│   ├── app/Policies/                 MatterPolicy, ClientPolicy
│   ├── app/Services/                 DashboardService, ActivityLogger
│   ├── bootstrap/app.php             API routing, statefulApi, middleware alias, JSON exception rendering
│   ├── config/cors.php, lexora.php   CORS for the SPA; dashboard window, pagination, demo admin
│   ├── database/migrations/          users (+role), clients, matters, tasks, matter_notes, activity_events (+trigger)
│   ├── database/factories/, seeders/ factories for every model; DatabaseSeeder, DemoAdminSeeder, DemoWorkspaceSeeder
│   ├── routes/api.php, web.php
│   └── tests/Feature/                Auth/, Api/, Database/
└── frontend/                         Next.js 16 application
    ├── src/app/                      (auth)/login, (dashboard)/{dashboard,matters,clients}, layout, providers, not-found
    ├── src/components/ui/            button, input, label, select, card, badge, skeleton, table, sheet, alert-dialog
    ├── src/components/layout/        app-shell, app-sidebar, app-header, sidebar-nav, user-panel, nav-config
    ├── src/components/dashboard/     stat-card, status-breakdown, recent-matters, upcoming-deadlines
    ├── src/components/shared/        page-header, status-badge, priority-badge, deadline, data-table, pagination, search-input,
    │                                 empty-state, error-state, loading-skeleton, confirm-dialog, form-field, brand-mark, client-avatar
    ├── src/features/                 auth (api, hooks, auth-guard, login-form, redirect helpers), dashboard, matters, clients
    ├── src/hooks/                    use-url-filters, use-now
    ├── src/lib/api/                  client (fetch + CSRF), errors (ApiError), config
    ├── src/lib/utils/                cn, format (dates, urgency), labels
    ├── src/types/api.ts              Types mirroring the API Resources
    ├── src/proxy.ts                  Optimistic guest redirect (Next 16 "proxy", formerly middleware)
    ├── e2e/                          Playwright specs, fixtures, global setup
    └── next.config.ts                Security headers (CSP etc.)
```

---

## 3. Frontend implementation

### Pages

| Route | Description |
| --- | --- |
| `/login` | Split layout: brand panel (desktop) + sign-in form. Email, password with show/hide toggle (`aria-pressed`), "Keep me signed in" (Laravel remember-me), loading state, client-side Zod validation, server error banner (`role="alert"`), 429 messaging. Development-only demo credentials box (driven by `NEXT_PUBLIC_DEMO_*`; hidden when unset). |
| `/dashboard` | Time-of-day greeting with the admin's first name and date; four stat cards (active matters, clients, upcoming deadlines + overdue count, open tasks + past-due count), three of which link to filtered lists; matter pipeline stacked bar; recent matters table (cards on mobile); upcoming deadlines list with urgency chips and an overdue banner. |
| `/matters` | Search (debounced), status (incl. "Active"), priority and sort filters persisted in the URL; table with number, title, client, type, status, priority, deadline (absolute + relative/overdue), open tasks; mobile cards; pagination; "no results" vs "no data" empty states with *Clear filters*. |
| `/clients` | Search, type filter, sort; client avatar, reference, type badge, email (`mailto:`), phone, active/total matter counts; mobile cards; pagination. |
| `/` | Redirects to `/dashboard`. |
| not-found | Branded 404 with a link back to the dashboard. |

### UI/UX and design system

- Original **Lexora** identity: custom SVG monogram, warm off-white `#FAFAF8` canvas, charcoal `#111827` sidebar, muted teal `#0F766E` accent,
  restrained status colours, Geist Sans/Mono/Serif bundled locally, subtle shadows, 10–14 px radii.
- Tokens defined once in `globals.css` and exposed to Tailwind 4 via `@theme`.
- Priority shown as label + 4-step meter, status as label + dot — meaning never relies on colour alone.
- Skeletons match final row heights (no layout shift). Background refetches dim the table instead of replacing it.

### Responsive design and accessibility (WCAG 2.2 AA practices)

- Desktop sidebar from `lg`; below that a Radix Dialog drawer (focus trap, Escape, labelled). Tables become stacked lists below `md`.
  E2E verifies **no horizontal overflow** on a Pixel 7 for all three pages.
- Semantic landmarks, "Skip to content" link (E2E-verified), `aria-current="page"` on navigation, breadcrumb `nav`, table captions,
  labelled form controls with `aria-invalid`/`aria-describedby`, `role="alert"` errors, `aria-live` pagination summary.
- Single high-contrast `:focus-visible` style; text colour pairs ≥ 4.5:1; `prefers-reduced-motion` disables animation.

### Authentication and navigation flow

1. `proxy.ts` redirects requests for protected pages without a session cookie to `/login?next=…` (no flash of the app shell).
2. `<AuthGuard>` calls `GET /api/auth/me`; it renders only a loader until the API confirms the session, and redirects on 401.
3. Login: `GET /sanctum/csrf-cookie` → `POST /api/auth/login` with `X-XSRF-TOKEN` → the current-user cache is seeded → redirect to a
   **validated** `next` path (open-redirect safe), default `/dashboard`.
4. Any 401 during use clears the identity (global QueryCache handler) → redirect to login. A 419 is retried once with a fresh CSRF cookie.
5. Logout: `POST /api/auth/logout` → all cached query data cleared → `/login`.

---

## 4. Backend implementation

### Endpoints

| Method | Path | Middleware | Behaviour |
| --- | --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | Sanctum | 204, sets `XSRF-TOKEN` |
| POST | `/api/auth/login` | `throttle:login` | 200 user · 422 bad credentials/validation · 429 lockout (+`Retry-After`) · 419 no CSRF · 400 non-SPA |
| GET | `/api/auth/me` | `auth:sanctum`, `admin` | 200 user · 401 |
| POST | `/api/auth/logout` | `auth:sanctum`, `admin` | 204, session invalidated, CSRF token rotated |
| GET | `/api/dashboard/summary` | `auth:sanctum`, `admin`, `throttle:api` | stats, status breakdown, recent matters, upcoming deadlines |
| GET | `/api/matters` | same | paginated; `search,status,priority,client_id,sort,direction,page,per_page` |
| GET | `/api/clients` | same | paginated; `search,type,sort,direction,page,per_page` |
| GET | `/` , `/up` | – | JSON status, health check |

### Components

- **Controllers** are thin: validated input → query/service → resource.
- **Services:** `DashboardService` (grouped status counts, `FILTER` aggregates for tasks, scoped upcoming/overdue queries);
  `ActivityLogger` (single write path for history, actor snapshot, morph-mapped subject, metadata).
- **Models:** enum casts, immutable datetime casts, relationships, query scopes (`active`, `dueWithin`, `overdue`, `open`),
  lower-cased emails, append-only guards on `ActivityEvent`.
- **Middleware:** `EnsureUserIsAdmin` (alias `admin`); Sanctum `statefulApi()`.
- **Validation:** Form Requests with enum-based rules; unknown filter values rejected with 422; search length capped; LIKE wildcards escaped.
- **Authorisation:** route middleware + `MatterPolicy`/`ClientPolicy` (via `FormRequest::authorize`) + `view-dashboard` gate.
- **Error handling** (`bootstrap/app.php`): JSON for every `api/*` request; 404s without model names; password fields never flashed.
- **Provider:** rate limiters, `Model::shouldBeStrict()` outside production, enforced morph map, password policy defaults.
- **Command:** `php artisan lexora:create-admin` (interactive, hidden password input, validated against the password policy).

---

## 5. Database documentation

Full column-level documentation: [`data-model.md`](data-model.md).

| Table | Key relationships / constraints |
| --- | --- |
| `users` | `email` unique (lower-cased); `role` CHECK (`admin`) |
| `clients` | `client_type` CHECK; non-blank name; `reference_number` unique; email indexed, intentionally not unique |
| `matters` | `client_id` → clients **RESTRICT**; `matter_number` unique; status/priority CHECKs; closed ⇒ `closed_at`, archived ⇒ `archived_at`; indexes `(status, due_at)`, priority, type, updated_at |
| `tasks` | `matter_id` → matters **CASCADE**; `created_by` → users **SET NULL**; status/priority CHECKs; `completed` ⇔ `completed_at` |
| `matter_notes` | `matter_id` **CASCADE**; `author_id` **SET NULL**; non-blank body |
| `activity_events` | `matter_id` **RESTRICT** (matters with history can't be hard-deleted); actor_type CHECK; jsonb metadata; **trigger blocks UPDATE/DELETE** |
| framework | `sessions`, `cache`, `jobs`, `password_reset_tokens`, `personal_access_tokens` |

Migrations: `backend/database/migrations/` (9 files). Seeders: `DatabaseSeeder` → `DemoAdminSeeder` + `DemoWorkspaceSeeder`
(refuses to run in production; idempotent). Sample data figures: 3 open, 3 in progress, 1 on hold, 2 closed, 1 archived matters;
4 deadlines within 14 days; 1 overdue matter; 11 open tasks (1 overdue).

---

## 6. Functional verification

All commands below were **executed** on 16 November 2026 in a Linux environment (PHP 8.3.6, PostgreSQL 16.15, Node 22, Chromium via
Playwright 1.63) against the real PostgreSQL database.

| Check | Command | Result |
| --- | --- | --- |
| Composer manifest | `composer validate --no-check-publish` | ✅ valid |
| Migrations + seed | `php artisan migrate:fresh --seed` | ✅ 9 migrations, seed OK; re-running `db:seed` creates no duplicates |
| Backend tests | `php artisan test` | ✅ **59 passed (218 assertions)** |
| Backend style | `./vendor/bin/pint --test` | ✅ clean |
| Frontend lint | `npm run lint` | ✅ 0 problems |
| TypeScript | `npm run typecheck` (`next typegen && tsc --noEmit`, strict) | ✅ 0 errors |
| Production build | `npm run build` | ✅ compiled; routes `/`, `/login`, `/dashboard`, `/matters`, `/clients` |
| E2E vs production build | `next start` + `npx playwright test` | ✅ **19 passed** |
| E2E vs dev server | `npx playwright test` (auto-starts servers) | ✅ **19 passed** |
| Manual smoke test | curl cookie flow + browser screenshots (desktop 1440 px, iPhone 13) | ✅ login, dashboard, lists, mobile drawer render correctly |

### Backend test coverage (PHPUnit, `backend/tests/Feature`)

| Suite | Verifies |
| --- | --- |
| `Auth/LoginTest` (11) | valid login + exact payload; case-insensitive email; **session id regenerated**; wrong password and unknown email → same generic 422; validation errors shape; **lockout after 5 failures → 429 + Retry-After**; per-IP route throttle; counter reset after success; non-SPA login refused (400); no password/remember token in output |
| `Auth/SessionTest` (4) | `/me` 401 for guests, 200 for admin; **logout invalidates session** (subsequent `/me` and dashboard → 401); guests can't log out |
| `Api/ProtectedRoutesTest` (14) | every protected endpoint → JSON 401 (SPA and non-SPA); JSON 404 without internals; CORS preflight allows only the frontend origin with credentials; CSRF cookie endpoint |
| `Api/DashboardSummaryTest` (5) | exact aggregates from fixture data (window edges, inactive matters excluded, overdue, open/overdue tasks, breakdown); recent-matters ordering; configurable window; empty workspace; **seeded workspace figures** |
| `Api/MatterIndexTest` (7) | pagination envelope; status/active/priority filters; search across title/number/client; literal `%`; deadline sort with nulls last; open-task counts; 422 for invalid params |
| `Api/ClientIndexTest` (3) | ordering and counts; type filter and search; 422 for invalid filters |
| `Database/IntegrityTest` (12) | relationships; enum CHECKs; lifecycle timestamp CHECKs; task completion CHECK; unique matter number; client delete RESTRICT; FK orphan prevention; cascade; history RESTRICT; SET NULL on admin removal; **append-only in model and DB**; normalised unique emails |
| `Database/DemoSeederTest` (3) | documented counts and hashed demo password; **idempotent re-seed**; refused in production |

### End-to-end coverage (Playwright, `frontend/e2e`)

Login page renders with accessible controls and show/hide password · required-field and email validation without calling the API ·
incorrect credentials show an alert and clear the password · protected route → login → return to the original URL ·
**no data in localStorage/sessionStorage, session cookie HTTP-only + Lax** · logout invalidates the server session and re-protects pages ·
**POST without CSRF token → 419** · protected endpoints → 401 without session · **dashboard figures equal `/api/dashboard/summary`**
and the seeded values · stat card links to a filtered list · API 500 → error state → retry recovers · sidebar navigation with
`aria-current` · matters filter/search/clear against the API · clients type filter · skip link moves focus to main content ·
**mobile drawer** open/navigate/close and Escape · **no horizontal scrolling** on a phone.

### Issues found and fixed during verification

- Mobile dashboard overflowed horizontally (grid items' min-content width) → explicit `grid-cols-1`; covered by an E2E test.
- In the production build the login form was duplicated in a hidden streamed boundary (caused by `useSearchParams` inside `Suspense`
  under Cache Components), so labels pointed at a hidden input → `next` is now read from `window.location`; E2E passes on `next start`.
- Next 16 dev-time "instant navigation" validation errors → protected segments declare `export const instant = false` (they wait for
  the session check by design).

### Remaining bugs

None known in Phase 1 scope.

---

## 7. Security review

| Area | Status |
| --- | --- |
| Authentication | Session cookies only; bcrypt hashing; remember-me via Laravel's token; generic credential errors |
| Session | Regenerated on login; invalidated + CSRF token rotated on logout; HTTP-only, `SameSite=Lax`, encrypted payload, `Secure` in production by default; DB-backed sessions |
| CSRF | Enforced for SPA requests via Sanctum `statefulApi()`; verified by E2E (419); never disabled |
| CORS | Single allowed origin from `FRONTEND_URL`, credentials, fixed header and method lists; verified by tests |
| Brute force | 5 failures per email+IP per minute → 429; 20 login requests per IP per minute; 240 API req/min per user |
| Authorisation | `auth:sanctum` + `admin` middleware on all protected routes, policies and a gate; backend is the authority (frontend guard is UX only) |
| Input validation | Form Requests with enum rules, length caps, integer bounds; LIKE wildcards escaped; Eloquent bindings only (no SQL concatenation) |
| Output | API Resources whitelist fields; no hashes/tokens; 5xx messages hidden from users; 404s without model names |
| Data integrity | FKs with explicit delete rules, CHECK constraints, unique keys, append-only trigger |
| Secrets | `.env` files git-ignored; `.env.example` only; no hard-coded production passwords; demo seeding refused in production |
| Headers (frontend) | CSP (`default-src 'self'`, API in `connect-src`, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy, `poweredByHeader: false` |
| Open redirect | `next` parameter restricted to same-app relative paths |

**Identified limitations**

- CSP allows `'unsafe-inline'` scripts (required by Next.js without a nonce setup); a nonce-based CSP is a Phase 7 item.
- Only one role exists, so the 403 path of the `admin` middleware is not exercised by a real non-admin user (a non-admin cannot be
  created — the DB constraint forbids it).
- CSRF rejection is verified end-to-end; Laravel skips CSRF middleware inside PHPUnit by design.
- HTTPS/secure cookies are configured but not exercised locally. No audit log of login attempts yet (Phase 6/7).
- `npm audit`: 8 moderate advisories in ESLint's transitive `minimatch` (dev-only tooling, not shipped).

---

## 8. Requirements compliance

Compared with the Amanet Full Stack Engineer assignment as described in the project brief (the original PDF was not available in the
workspace; requirements were taken from the brief's project context).

| Requirement | Status |
| --- | --- |
| Secure administrator access | ✅ Completed |
| Centralised matter and client management | 🟡 Partially — read-only lists, search, filters (CRUD in Phase 2) |
| Matter status, priority, important dates and deadlines | 🟡 Partially — modelled, displayed, filtered, aggregated; editing in Phase 2 |
| Matter-specific tasks and internal notes | 🟡 Partially — schema, seed data, open-task counts; UI/CRUD in Phase 2 |
| Search and filtering across matters, clients and documents | 🟡 Partially — matters and clients done; documents in Phase 3 |
| PDF upload, preview, download, version history | ⬜ Planned (Phase 3) — schema designed |
| Secure document-upload requests for external clients | ⬜ Planned (Phase 4) — token design documented |
| Document review and correction requests | ⬜ Planned (Phase 4) |
| Dated matter activity history | 🟡 Partially — append-only table, logger, seeded events; timeline UI in Phase 2/6 |
| Administrator PDF signing | ⬜ Planned (Phase 5) |
| Client PDF signing without accounts | ⬜ Planned (Phase 5) — design documented |
| Email invitations, upload requests and follow-ups | ⬜ Planned (Phase 6) — Mailpit provisioned |
| Signature tracking Sent/Viewed/Started/Signed | ⬜ Planned (Phase 5–6) — status machine designed |
| Administrator notifications | ⬜ Planned (Phase 6) |
| Signed PDF records, signer details, timestamps | ⬜ Planned (Phase 5) — schema designed |
| Link expiration, revocation, action restrictions | ⬜ Planned (Phase 4) — schema designed |
| Responsive, accessible premium UI | ✅ Completed for Phase 1 screens |
| Automated tests | ✅ Completed for Phase 1 (PHPUnit + Playwright) |
| Sample matters | ✅ Completed (fictional) |
| Demo login | ✅ Completed (development only) |
| README with setup and limitations | ✅ Completed |
| One signer per PDF | ⬜ Planned — enforced in the designed `signing_requests` schema |
| No client accounts | ✅ Enforced — only `admin` role exists (DB CHECK) |
| No external signing platforms | ✅ Adhered — none used or planned |
| Fictional data only | ✅ Reserved `example.*` domains, `555-01xx` numbers |
| Hosted demo (optional) | ⬜ Not implemented |

---

## 9. Remaining work & Phase 2 preparation

### Ready to build on without refactoring

- Matter/client/task/note **CRUD**: models, enums, constraints, factories and Form Request/Resource/Policy patterns exist; add
  `Store…`/`Update…` requests, policy methods and routes.
- **Activity timeline**: `ActivityLogger` + `activity_events` with morph map and metadata; record inside the same DB transaction.
- **Matter detail page**: `MatterResource`, `Deadline`, badges, `DataTable`, `EmptyState`/`ErrorState` reusable as-is.
- **Forms**: `FormField`, `Input`, `Select`, React Hook Form + Zod pattern from the login form; `ApiError.field()` maps 422s.
- **Destructive actions**: `ConfirmDialog` (Radix AlertDialog).
- **Mutations**: `api.post` with CSRF handling; add `put/patch/delete` helpers in `lib/api/client.ts`.

### Dependencies / decisions for Phase 2

- Matter number generation strategy (proposed: `LEX-{year}-{seq}` with a PostgreSQL sequence or locked counter inside a transaction).
- Allowed status transitions (e.g. archived only from closed) — enforce in a service and test.
- Whether clients can be edited while they have closed matters (proposed: yes; deletion stays restricted).

### Recommended next steps

1. Add `api.put/patch/delete` helpers and mutation hooks with cache invalidation.
2. Client create/edit (dialog or page) → matter create/edit with client picker → status changes with activity events.
3. Matter detail page with tasks, notes and timeline tabs.
4. Extend PHPUnit and Playwright suites for each new action.

---

## 10. Final completion checklist

**Infrastructure**
- [x] Next.js runs (`npm run dev`, `npm run start`)
- [x] Laravel runs (`php artisan serve`)
- [x] PostgreSQL connects; migrations succeed
- [x] Seeders create fictional demo data idempotently
- [x] Environment configuration documented (`.env.example` ×2, README)
- [x] Docker Compose for PostgreSQL + Mailpit (`docker compose config` validated; containers not started in the verification environment, which used a native PostgreSQL 16)

**Authentication**
- [x] Admin login works
- [x] Incorrect credentials produce appropriate errors
- [x] Admin logout works and invalidates the session
- [x] Protected backend routes reject unauthenticated requests
- [x] Protected frontend pages handle unauthenticated sessions
- [x] No authentication tokens in localStorage/sessionStorage
- [x] CSRF and session configuration verified end-to-end

**Dashboard and interface**
- [x] Polished login page
- [x] Working sidebar and mobile navigation
- [x] Dashboard retrieves real data; statistics verified against the API and fixtures
- [x] Recent matters and upcoming deadlines display correctly
- [x] Loading, empty and error states
- [x] No active control leads to non-existent functionality

**Backend and architecture**
- [x] Consistent API responses and errors
- [x] Valid relationships, foreign keys and constraints
- [x] Business logic in services/scopes; thin controllers
- [x] Future document, secure-link and signing architecture documented

**Testing and delivery**
- [x] Backend tests pass (59/59)
- [x] TypeScript check passes
- [x] Lint passes
- [x] Production build passes
- [x] End-to-end tests pass (19/19, dev and production builds)
- [x] README with setup and demo instructions
- [x] Known limitations documented

**Pending (later phases):** CRUD and detail pages (2) · documents (3) · client requests & secure links (4) · signing (5) ·
email, notifications, tracking (6) · hardening, nonce CSP, accessibility audit, optional hosted demo (7).
