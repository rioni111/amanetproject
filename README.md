# Lexora Workspace — Amanet Legal Matter Workspace

A legal matter-management and document-signing platform built for the **Amanet Full Stack Engineer take-home assignment**.
"Lexora Workspace" is the product identity used for the UI. All clients and matters are **fictional**.

> **Status: Phase 1 of 7 complete** — foundation, architecture, database, authentication and premium UI.
> See [`docs/PHASE_1_IMPLEMENTATION_REPORT.md`](docs/PHASE_1_IMPLEMENTATION_REPORT.md) for the verified scope, and
> [`docs/implementation-phases.md`](docs/implementation-phases.md) for the roadmap.

---

## What it does (today)

| Area | Phase 1 capability |
| --- | --- |
| Authentication | Administrator sign-in/out with Laravel Sanctum **session cookies** (HTTP-only), CSRF protection, login rate limiting, session regeneration |
| Dashboard | Live aggregates from PostgreSQL: active matters, clients, upcoming/overdue deadlines, open/overdue tasks, status pipeline, recent matters, upcoming deadlines |
| Matters | Read-only list with search, status/priority filters, sorting and pagination |
| Clients | Read-only list with search, type filter, sorting, pagination and matter counts |
| Data model | Users (admin role), clients, matters, tasks, internal notes, append-only activity history |
| UI | Responsive app shell, mobile navigation drawer, loading/empty/error states, WCAG 2.2 AA-minded |

Planned for later phases: matter/client/task/note CRUD (2), PDF documents & versions (3), secure client upload requests (4),
in-house PDF e-signing (5), email + notifications + signing progress (6), hardening and polish (7).

## Technology

| Layer | Choice |
| --- | --- |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict), Tailwind CSS 4, shadcn/ui-style components on Radix, Lucide, React Hook Form + Zod, TanStack Query |
| Backend | PHP 8.2+, Laravel 12, Sanctum (SPA session auth), Form Requests, API Resources, Policies |
| Database | PostgreSQL 16 (FKs, CHECK constraints, append-only trigger) |
| Infra | Docker Compose (PostgreSQL + Mailpit) |
| Tests | PHPUnit 11 (backend), Playwright (E2E), ESLint, `tsc`, `next build` |

```
amanet-legal-workspace/
├── backend/            Laravel API (business rules, database, auth)
├── frontend/           Next.js application (all UI)
├── docs/               Architecture, data model, API conventions, phases, Phase 1 report
├── docker/             PostgreSQL init script (creates the test database)
└── docker-compose.yml  PostgreSQL + Mailpit
```

Architecture overview: [`docs/architecture.md`](docs/architecture.md) · Data model: [`docs/data-model.md`](docs/data-model.md) ·
API conventions: [`docs/api-conventions.md`](docs/api-conventions.md)

---

## Requirements

- **PHP 8.2+** with extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`
- **Composer 2**
- **Node.js 20.9+** and npm
- **PostgreSQL 16** — via Docker (recommended) or a local install
- Optional: Docker Desktop for PostgreSQL + Mailpit

> **Windows / Laragon:** Laragon's PHP works. Enable `extension=pdo_pgsql` and `extension=pgsql` in `php.ini`
> (Laragon → PHP → php.ini), then restart. Use Docker Desktop for PostgreSQL, or install PostgreSQL and create the
> databases shown below.

## Setup

### 1. Start PostgreSQL and Mailpit

```bash
docker compose up -d
```

This creates the `amanet` database (user `amanet` / password `amanet`) and the `amanet_test` database used by the test suite.

<details>
<summary>Without Docker</summary>

```sql
CREATE ROLE amanet LOGIN PASSWORD 'amanet' CREATEDB;
CREATE DATABASE amanet OWNER amanet;
CREATE DATABASE amanet_test OWNER amanet;
```
</details>

### 2. Backend (Laravel API) — http://localhost:8000

```bash
cd backend
composer install
cp .env.example .env          # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=localhost --port=8000
```

### 3. Frontend (Next.js) — http://localhost:3000

```bash
cd frontend
npm install
cp .env.example .env.local    # Windows PowerShell: Copy-Item .env.example .env.local
npm run dev
```

Open **http://localhost:3000** (use `localhost`, not `127.0.0.1` — see Troubleshooting).

| Service | URL |
| --- | --- |
| Web app | http://localhost:3000 |
| API | http://localhost:8000/api |
| API health | http://localhost:8000/up |
| Mailpit | http://localhost:8025 |

## Demo login (development only)

> ⚠️ **Development-only credentials.** They come from `DEMO_ADMIN_*` in `backend/.env` and are created by the seeder,
> which **refuses to run in production**.

| Email | Password |
| --- | --- |
| `admin@lexora.test` | `Lexora!Demo2026` |

Re-running `php artisan db:seed` is safe: records are keyed on natural identifiers and are never duplicated.
To start from scratch: `php artisan migrate:fresh --seed`.

To create a real administrator (any environment):

```bash
php artisan lexora:create-admin
```

## Testing

```bash
# Backend — PHPUnit against the amanet_test PostgreSQL database
cd backend && php artisan test

# Backend code style
cd backend && ./vendor/bin/pint --test

# Frontend static checks and production build
cd frontend && npm run lint && npm run typecheck && npm run build

# End-to-end (real Next.js → Laravel → PostgreSQL). Starts both servers if they are not running
# and runs the (idempotent) seeder first.
cd frontend && npx playwright install chromium   # first time only
cd frontend && npm run test:e2e
```

Latest verified results are recorded in the [Phase 1 report](docs/PHASE_1_IMPLEMENTATION_REPORT.md#6-functional-verification).

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Login returns **419 CSRF token mismatch** | Use `http://localhost:3000` (not `127.0.0.1`). `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL` in `backend/.env` must match the frontend origin. Clear cookies for `localhost` and retry. |
| Login returns **400 "only available to the Lexora web application"** | The request had no `Origin`/`Referer` from a stateful domain — call the API through the web app, not directly. |
| `could not find driver (pgsql)` | Enable `pdo_pgsql` and `pgsql` in `php.ini`. |
| `SQLSTATE[08006] connection refused` | PostgreSQL is not running: `docker compose up -d` and check `DB_*` in `backend/.env`. |
| Port 5432 already in use | Stop the local PostgreSQL service or change the port mapping in `docker-compose.yml` and `DB_PORT`. |
| Dashboard shows "We couldn't reach the server" | The API is not running on `NEXT_PUBLIC_API_URL` (default `http://localhost:8000`). |
| Backend tests fail to connect | Ensure the `amanet_test` database exists (created automatically by Docker on first start). |

## Known limitations (Phase 1)

- Matters and clients are **read-only**; create/edit/archive arrive in Phase 2. Rows are not yet clickable (no detail pages).
- Documents, client requests, signing, email and notifications are **designed and documented but not implemented**.
- The "Coming soon" navigation items are intentionally disabled.
- One role (`admin`) exists; the `admin` middleware and policies are in place for future roles.
- Production deployment (HTTPS, secure cookies, queue workers) is configured through environment variables but not exercised.
- `npm audit` reports moderate advisories in ESLint's transitive `minimatch` dependency (dev tooling only).

See [`docs/PHASE_1_IMPLEMENTATION_REPORT.md`](docs/PHASE_1_IMPLEMENTATION_REPORT.md) for the full list.
