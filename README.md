# Lexora Workspace — Amanet Legal Matter Workspace

A legal matter-management and document-signing platform built for the **Amanet Full Stack Engineer take-home assignment**.
"Lexora Workspace" is the product identity used for the UI. All clients and matters are **fictional**.

> **Status: all 7 phases complete.** Phase 7 (final security audit, UI/UX and accessibility, performance, QA and delivery)
> is on branch `feature/phase-7-final-qa-delivery`, not yet merged. The final summary is the
> [Phase 7 report](docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md); the [documentation index](#documentation) lists every
> document.

## Reviewer quick start

1. **Windows:** run `setup-windows.bat` once, then `start-windows.bat`. On other systems follow [Manual setup](#manual-setup-any-os).
2. **Mail inbox:** start the local inbox with `docker compose up -d mailpit`. E-mails never leave the machine.
3. **Sign in:** use the [demo account](#demo-login-development-only). The workspace already contains fictional clients,
   matters, tasks, notes, documents with versions, client requests in every state and signed documents.
4. **Tour:** follow the [demo walkthrough](docs/DEMO_WALKTHROUGH.md), 20–30 minutes covering every assignment requirement.

---

## What it does (today)

| Area | Capability |
| --- | --- |
| Authentication | Administrator sign-in/out with Laravel Sanctum **session cookies** (HTTP-only), CSRF protection, login rate limiting, session regeneration |
| Dashboard | Live aggregates from PostgreSQL: active matters, clients, upcoming/overdue deadlines, open/overdue tasks, status pipeline, recent matters, upcoming deadlines — refreshed after every change |
| Clients | List (search name/email/phone/reference, type filter, sorting, pagination), create, edit, detail page with matters, delete only while a client has no matters |
| Matters | List with search, status/priority/deadline/client filters, sorting, quick actions; create (unique `LEX-YYYY-NNNN` number), edit, status transitions, priority, deadlines, archive & restore |
| Matter workspace | `/matters/{id}`: overview, **tasks** (create, edit, complete/reopen, delete), **internal notes** (create, edit, delete — admin-only), **activity timeline** |
| Documents | `/documents` library and a Documents tab per matter: upload PDFs (private storage, SHA-256), preview in the app (PDF.js), download, **immutable version history** (preview/download any version), metadata edit, archive; search by title, file name, matter, client and category |
| Client requests | Request a PDF from a matter's client through a **secure, expiring, one-use link** (256-bit token, only its hash stored; copy, replace, revoke). The client uploads on a public page **without an account**; the file arrives in the matter as a new document/version. Review: preview, approve the exact version, or request a correction (reason + fresh link → next version). `/document-requests` list and dashboard counters for pending, overdue, awaiting review, corrections and approved |
| Signatures | **One signer per PDF.** The administrator signs in the workspace ("Sign PDF"), or a client signs through a **secure, expiring, revocable, one-time link** without an account: review the PDF, draw or type a signature, place it on any page, confirm the name and the consent statement. The server stamps the signature and an "Electronically signed by …" caption into the PDF (FPDI/FPDF, no third-party platform) and stores it as a **new immutable version**; the original never changes. Real progress Ready → Sent (after the admin confirms sharing) → Viewed → Started → Signed, an immutable evidence record (signer, consent, checksums, server time), `/signatures` list, matter Signatures tab and dashboard counters |
| E-mail | **Real SMTP** through Laravel Mail (Mailpit locally, any SMTP provider in production): document request, correction and signing invitation e-mails (HTML + plain text) to the address the server derives from the request. A new secure link is registered only after the mail server **accepted** the message (tokens are never stored, queued or logged); a failed send is reported honestly and keeps the earlier link working. A signing request becomes **Sent** only on acceptance. E-mail history per request and matter, and dashboard figures. "Accepted by the mail server" is never presented as inbox delivery |
| Reminders | `php artisan lexora:communications` (scheduled every 5 minutes): document reminders around the due date (or 4 and 10 days after the link without one), signing reminders, max 3 per request, 24 h apart, idempotent per slot, firm time zone; never after completion, cancellation, archiving or a manual revoke |
| Notifications | In-app bell with unread count, recent-notifications panel and `/notifications` page (mark read / mark all read, per administrator) for client uploads, corrected uploads, client signatures, overdue requests, expired signing links and failed automatic e-mails |
| Data integrity | Each change and its history entry in one transaction; optimistic concurrency (409 on stale edits); append-only history; immutable document versions, client submissions and signature records; database triggers and composite keys guard request/link/signing/matter consistency |
| UI | Responsive desktop/tablet/mobile, accessible dialogs and tabs, loading/empty/error states, success toasts, WCAG 2.2 AA-minded |

**Phase 7 hardening**
- **E-mailed links survive failures.** A link still works if the server crashes, or the database fails, after the mail
  server accepted the message.
- **Security headers** on every API response, and a hardened browser policy for the web app.
- **Accessibility.** Automated WCAG A/AA checks (axe-core) and keyboard tests; dialogs return focus to the button that
  opened them.
- **Small screens.** The client upload and signing pages are verified at 320 px.
- **Browsers.** Firefox and WebKit compatibility checks.
- **Query budget.** A regression test makes sure list endpoints don't run one database query per row.
- **Demo data.** Realistic fictional documents, requests and signatures.

The findings and their fixes are in the [issue register](docs/PHASE_7_ISSUE_REGISTER.md).

## Documentation

| Document | Purpose |
| --- | --- |
| [Demo walkthrough](docs/DEMO_WALKTHROUGH.md) | step-by-step tour for reviewers |
| [Phase 7 final report](docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md) | requirement mapping, results, limitations, release recommendation |
| [Final QA report](docs/FINAL_QA_REPORT.md) | test environment, results, measurements, manual acceptance |
| [Security audit](docs/SECURITY_AUDIT.md) | threat model, controls, findings, remaining risks |
| [Deployment guide](docs/DEPLOYMENT_GUIDE.md) | production configuration, HTTPS, SMTP, scheduler, backups, smoke tests |
| [Delivery checklist](docs/DELIVERY_CHECKLIST.md) | handover status |
| [Architecture](docs/architecture.md) · [Data model](docs/data-model.md) · [API conventions](docs/api-conventions.md) | technical reference |
| [E-mail configuration](docs/email-configuration.md) · [Credentials review](docs/security-credentials.md) | operations |
| [Audit plan](docs/PHASE_7_AUDIT_PLAN.md) · [Issue register](docs/PHASE_7_ISSUE_REGISTER.md) · [Roadmap](docs/implementation-phases.md) | Phase 7 process |
| Phase reports [1](docs/PHASE_1_IMPLEMENTATION_REPORT.md) · [2](docs/PHASE_2_IMPLEMENTATION_REPORT.md) · [3](docs/PHASE_3_IMPLEMENTATION_REPORT.md) · [4](docs/PHASE_4_IMPLEMENTATION_REPORT.md) · [5](docs/PHASE_5_IMPLEMENTATION_REPORT.md) · [6](docs/PHASE_6_IMPLEMENTATION_REPORT.md) · [7](docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md) | history of each delivery stage |

## Technology

| Layer | Choice |
| --- | --- |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict), Tailwind CSS 4, shadcn/ui-style components on Radix, Lucide, React Hook Form + Zod, TanStack Query |
| Backend | PHP 8.2+, Laravel 12, Sanctum (SPA session auth), Form Requests, API Resources, Policies; FPDI + FPDF (MIT) for PDF signing |
| Database | PostgreSQL 16 (FKs, CHECK constraints, append-only trigger) |
| Infra | Docker Compose (PostgreSQL + Mailpit) |
| Tests | PHPUnit 11 (backend), Playwright (E2E), ESLint, `tsc`, `next build` |

```
amanet-legal-workspace/
├── backend/            Laravel API (business rules, database, auth)
├── frontend/           Next.js application (all UI)
├── docs/               Architecture, data model, API conventions, phases, e-mail guide, phase reports
├── docker/             PostgreSQL init script (creates the test database)
└── docker-compose.yml  PostgreSQL + Mailpit (development) + Mailpit (isolated E2E inbox)
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

### Windows / Laragon (one click)

Three launchers in the project root automate everything on Windows (PowerShell 5.1+, no admin rights needed):

| Launcher | What it does |
| --- | --- |
| `setup-windows.bat` | Picks Laragon's newest PHP ≥ 8.2 and enables `pdo_pgsql`/`pgsql` in its `php.ini` (backup kept as `php.ini.lexora-backup`). Uses Laragon's Composer if it is ≥ 2.7, otherwise downloads the official `composer.phar` into `.tools\` (SHA-256 checked). If Node.js ≥ 20.9 is missing, it installs the official Node 22 LTS zip into `C:\laragon\bin\nodejs` (SHA-256 checked). If PostgreSQL is missing, it installs the official EDB PostgreSQL 16 binaries into `C:\laragon\bin\postgresql` (Authenticode signatures checked) and initialises this project's own instance in `C:\laragon\data\postgresql-16`, listening on localhost only. **Credentials are generated randomly**: the `postgres` superuser password is stored in `C:\laragon\data\postgresql-16-superuser.secret` (readable by your Windows user only) and the `amanet` role's password only in `backend\.env`. Then it creates the `amanet` (development), `amanet_test` (PHPUnit) and `amanet_e2e` (Playwright, reset on every run) databases, runs `composer install`, creates `.env` and the app key, runs `migrate --seed`, `npm ci` and installs Playwright's Chromium. It is safe to run again. |
| `verify-windows.bat` | Runs the full verification: PHPUnit, Pint, ESLint, TypeScript, production build and the Playwright end-to-end suite (on its own servers and the disposable `amanet_e2e` database — your development data is never modified) |
| `start-windows.bat` | Starts PostgreSQL, the API and the web app (servers already running are reused, never duplicated), waits until both respond, then opens the browser |

**Ports.** Defaults are API `8000`, web `3000`, PostgreSQL `5432`. If another program already owns one of them
(e.g. a Docker stack or another project), setup leaves it alone and picks the next free port — API from `8010`,
web from `3010`, PostgreSQL from `5433` — and records the choice in `backend\.env` (`LEXORA_API_PORT`,
`LEXORA_WEB_PORT`, `DB_PORT`, `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`) and
`frontend\.env.local` (`NEXT_PUBLIC_API_URL`). The PostgreSQL port is also stored in the data directory's
`postgresql.conf`. `setup-windows.bat` prints the URLs to open. Environment variables `LEXORA_API_PORT`,
`LEXORA_WEB_PORT` and `LEXORA_PG_PORT` override the choice.

Logs are written to `verification-logs\` (`*-summary.txt` has the PASS/FAIL list). To run the launchers without the
final "Press any key" prompt (e.g. from a terminal or CI), set `LEXORA_NO_PAUSE=1`.

### Manual setup (any OS)

### 1. Start PostgreSQL and Mailpit

```bash
docker compose up -d
```

This creates the `amanet` database (user `amanet` / password `amanet` — a **local Docker development default only**;
never reuse it elsewhere) and the `amanet_test` database used by the test suite.

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
| Mailpit (development inbox) | http://localhost:8025 (or the port set in `LEXORA_MAIL_UI_PORT`; 8026 on the verified machine) |

`docker compose up -d` also starts `mailpit-e2e` (SMTP 1027, UI 8027), the inbox the end-to-end tests read. Ports are
configurable (`LEXORA_MAIL_SMTP_PORT`, `LEXORA_MAIL_UI_PORT`, `LEXORA_E2E_MAIL_SMTP_PORT`, `LEXORA_E2E_MAIL_UI_PORT`) and
bound to 127.0.0.1. To start only the mail containers: `docker compose up -d mailpit mailpit-e2e`.

## Demo login (development only)

> ⚠️ **Development-only credentials.** They come from `DEMO_ADMIN_*` in `backend/.env` and are created by the seeder,
> which **refuses to run in production**.

| Email | Password |
| --- | --- |
| `admin@lexora.test` | `Lexora!Demo2026` |

These demo values are committed on purpose for local development only. How to rotate them, and what must change before any
public deployment, is described in [docs/security-credentials.md](docs/security-credentials.md).

**What the demo data contains**
- 8 clients, 10 matters (all statuses and priorities), tasks and internal notes.
- PDFs with version history.
- Client requests: one waiting for the client, one awaiting review, one with a correction requested, one approved.
- An administrator-signed board resolution and a client-signed purchase agreement, with evidence.
- A signing request ready to e-mail.

All of it is created through the application's own services. Set `LEXORA_DEMO_DOCUMENTS=false` to skip the documents.

Re-running `php artisan db:seed` is safe: records are keyed on natural identifiers and are never duplicated.
To start from scratch: `php artisan migrate:fresh --seed`. **This erases the development database.**

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

# End-to-end (real Next.js → Laravel → PostgreSQL), fully isolated from your development data:
# Playwright starts its OWN API (:8011) and web server (:3011, build dir .next-e2e) against the
# disposable database amanet_e2e, which global-setup resets with migrate:fresh --seed on every run.
# Only a database whose name ends in "_e2e" is ever reset. Override ports with E2E_API_PORT / E2E_WEB_PORT.
cd frontend && npx playwright install chromium   # first time only
cd frontend && npm run test:e2e
```

Create the E2E database once (`setup-windows.bat` does it): `CREATE DATABASE amanet_e2e OWNER amanet;`
The E2E API stores uploaded PDFs in `backend/storage/app/private/e2e-documents` (wiped by global-setup), never in the
development document storage.

The end-to-end and SMTP integration tests need the `mailpit-e2e` container (`docker compose up -d mailpit-e2e`); they send
real SMTP to it and click the links inside the delivered messages. `verify-windows.bat` checks that it is reachable.

**Accessibility.** The suite runs axe-core WCAG A/AA checks (`e2e/phase7-accessibility.spec.ts`).

**Other browsers (optional).** The default suite runs Chromium. To check Firefox and WebKit:

```bash
cd frontend && npx playwright install firefox webkit   # first time only
cd frontend && set E2E_CROSS_BROWSER=1 && npx playwright test --project=firefox --project=webkit   # PowerShell: $env:E2E_CROSS_BROWSER=1
```

Latest verified results: [Final QA report](docs/FINAL_QA_REPORT.md#15-windows-verification-final). All 14
verification steps passed: 253 PHPUnit tests (3,698 assertions) and 66 Playwright tests, plus Firefox/WebKit 2/2.
Earlier phases: [Phase 6](docs/PHASE_6_IMPLEMENTATION_REPORT.md#20-windows-verification), [Phase 5](docs/PHASE_5_IMPLEMENTATION_REPORT.md#18-windows-verification),
[Phase 4](docs/PHASE_4_IMPLEMENTATION_REPORT.md), [Phase 2](docs/PHASE_2_IMPLEMENTATION_REPORT.md#12-testing-results),
[Phase 1](docs/PHASE_1_IMPLEMENTATION_REPORT.md#6-functional-verification).

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

| Saving returns **409** | Someone (or another tab) changed the record after you opened it, or the matter is archived. Reload; restore archived matters before editing. |

## Documents (Phase 3)

- PDFs are stored privately in `backend/storage/app/private/documents` (not web-accessible) and only served through
  authorised API endpoints. Maximum size: `DOCUMENTS_MAX_UPLOAD_MB` (default 20); PHP's `upload_max_filesize`/`post_max_size`
  must be larger (`setup-windows.bat` sets 25M/30M in Laragon's `php.ini`).
- After `npm install` the PDF viewer's worker and fonts are copied to `frontend/public/pdfjs` automatically (`postinstall`).
- Search covers document metadata (title, original file name, matter, client, category) — not the text inside PDFs.

## Client document requests (Phase 4)

- Open a matter → **Client requests** → *Request document*. Generate the upload link now or later; it is shown **once** with a
  *Copy link* button to send it yourself, or use **Send e-mail** (Phase 6, below).
- The link looks like `http://localhost:3010/client-upload#<token>`. A localhost link only works on the development machine;
  set `PUBLIC_APP_URL` (backend) to a public HTTPS hostname for real clients.
- Defaults: links expire after `UPLOAD_LINK_EXPIRY_DAYS` (7; 1–30 selectable), allow one upload, and stop working when replaced,
  revoked, approved, cancelled or when the matter is archived (restoring a matter does not revive old links).
- Public rate limits: `PUBLIC_LINK_VIEWS_PER_MINUTE` (60), `PUBLIC_LINK_UPLOADS_PER_MINUTE` (10, plus 5 per token),
  `PUBLIC_LINK_INVALID_ATTEMPTS` (20 failed lookups per IP → 15-minute lockout). Firm name on the client page: `LEXORA_FIRM_NAME`.
- The client page sends no cookies and keeps the token only in memory; the token never appears in a request path or server log.

## Electronic signing (Phase 5)

- **Administrator:** open a document → **Sign PDF** → draw or type, click the page to place the signature (drag or use the arrow
  keys to adjust), tick the consent statement → *Sign document*. The signed PDF becomes the next version (`Signed` badge).
- **Client:** matter → **Signatures** → *New signing request* (client signer, optional message, generate link now). Copy the link
  (shown once), send it yourself, then **Mark invitation as shared** — or use **Send signing invitation** (Phase 6). The client
  opens `http://localhost:3010/client-sign#<token>`, reviews the PDF, starts signing, signs; progress appears on the request page.
- One signer per PDF: a signed version cannot be signed again, and a document has at most one open signing request. Adding a
  version or archiving the document/matter cancels an unfinished request (restoring does not revive its link).
- Links: `SIGNING_LINK_EXPIRY_DAYS` (7; 1–30 selectable), replace or revoke any time, consumed only by a completed signature.
  Public limits: `PUBLIC_SIGNING_VIEWS_PER_MINUTE` (120) and `PUBLIC_SIGNING_COMPLETIONS_PER_MINUTE` (10) per IP, plus 5
  completions per minute per link.
- Supported PDFs: what FPDI's free parser can import. PDFs with compressed cross-reference streams, form fields, annotations,
  existing digital signatures or encryption are refused with an explanation (nothing is stored).
- **Legal note:** this is an application-level electronic signature with an evidence record — **not** a qualified or
  certificate-based (PAdES/eIDAS) digital signature, and client identity is not independently verified (possession of the link
  plus the confirmed name). Whether it suffices for a given document and jurisdiction is for the firm to decide.

## E-mail, reminders and notifications (Phase 6)

- **Send e-mail** (request page), **E-mail correction** / the option in the correction dialog, and **Send signing invitation**
  (signing page) send a real message over SMTP. The recipient is chosen by the server: the request's client, or the signer
  snapshot of the signing request. Each accepted e-mail carries a **new** link and earlier links for that request stop working.
- Results are shown honestly: *accepted by the configured mail server* (which does not prove inbox delivery), *not sent* with a
  safe reason (server unreachable, timeout, authentication, recipient refused…), or *e-mail unavailable* (no SMTP configured —
  Copy link still works). History cards show every attempt.
- Reminders: run `php artisan schedule:work` in development (or `php artisan lexora:communications`, `--dry-run` to preview);
  in production one cron entry `* * * * * php artisan schedule:run`. No queue worker is needed.
- Notifications: the bell in the header (refreshes every 30 s) and `/notifications`.
- Configuration (SMTP host, port, TLS, sender, reply-to, timeout, reminder rules, production checklist):
  [docs/email-configuration.md](docs/email-configuration.md). SMTP credentials live only in `backend/.env`. In production, sending
  is refused unless `PUBLIC_APP_URL` is a public HTTPS address.

## Known limitations

- The matter form's client picker and the matters client filter list the first 100 clients alphabetically (search-as-you-type picker planned).
- Uploaded PDFs are validated structurally (type, signature, completeness, no encryption) but **not scanned for malware**.
- The viewer does not load PDF.js's optional WebAssembly decoders, so rare JPEG 2000 / JBIG2 images may not render in the
  preview (the download is unaffected).
- E-mail status ends at **acceptance by the SMTP server**: inbox delivery, bounces and spam filtering are not tracked (no
  provider webhooks). SPF/DKIM/DMARC for a sending domain are not configured here. Sending is synchronous (bounded by
  `MAIL_TIMEOUT`), and failed e-mails are not retried automatically.
- A send interrupted by a crash is still reported as "interrupted" (outcome unknown), but the client's link now works
  if the message did arrive. See the [Phase 7 report](docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md#26-known-limitations).
- Signatures are application-level electronic signatures, not qualified/certificate-based signatures; no independent identity
  verification of clients (see the [Phase 5 report](docs/PHASE_5_IMPLEMENTATION_REPORT.md#23-legal-and-technical-limitations)).
- Some PDFs cannot be signed (compressed cross-reference streams, forms, annotations, existing digital signatures, encryption).
- Client uploads are not malware-scanned; client messages are plain text.
- One role (`admin`) exists; the `admin` middleware and policies are in place for future roles.
- Production deployment (HTTPS, secure cookies, production SMTP, cron) is configured through environment variables but not
  exercised on a real server; see the [deployment guide](docs/DEPLOYMENT_GUIDE.md).
- The web app's Content-Security-Policy keeps `'unsafe-inline'` for scripts, because Partial Prerendering is incompatible
  with per-request nonces ([security audit](docs/SECURITY_AUDIT.md#9-http-security-headers)).
- `npm audit` reports 14 high-severity entries. They are one `picomatch` advisory (GHSA-fc5q-39xc-vjpf), listed once for
  each dependent package in ESLint's toolchain. They affect development tooling only;
  runtime dependencies have none, and no patched release exists yet.
- Locally, `php artisan serve` on Laragon runs without OPcache, so each API request takes 200–380 ms. With OPcache (required
  for production) the same requests take 21–110 ms.

See the [Phase 7 report](docs/PHASE_7_FINAL_IMPLEMENTATION_REPORT.md), [Phase 6 report](docs/PHASE_6_IMPLEMENTATION_REPORT.md), [Phase 5 report](docs/PHASE_5_IMPLEMENTATION_REPORT.md), [Phase 4 report](docs/PHASE_4_IMPLEMENTATION_REPORT.md), [Phase 3 report](docs/PHASE_3_IMPLEMENTATION_REPORT.md), [Phase 2 report](docs/PHASE_2_IMPLEMENTATION_REPORT.md) and the [Phase 1 report](docs/PHASE_1_IMPLEMENTATION_REPORT.md) for the full list.
