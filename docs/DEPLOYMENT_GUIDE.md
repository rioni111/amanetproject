# Deployment Guide — Lexora Workspace

This guide prepares a production-like deployment. It has **not been exercised on a real server**: everything was
verified on local Windows machines (see [FINAL_QA_REPORT.md](FINAL_QA_REPORT.md)). Run the smoke tests in section 18
after any deployment.

## 1. Architecture and hosting requirements

```
Browser ──HTTPS──► reverse proxy (nginx / Caddy / IIS) ──► Next.js server (node, `next start`)      app.example.com
        ──HTTPS──►                                    ──► PHP-FPM (Laravel 12, public/index.php)     api.example.com
Laravel ──► PostgreSQL 16 · private PDF storage (local disk) · SMTP provider · cron: schedule:run every minute
```

**Long-running processes.** You need:
- a Node.js process (`next start`);
- PHP-FPM, or another FastCGI/PHP server with OPcache;
- PostgreSQL;
- a cron or task scheduler.

**Hosting fit.** A VPS, a container platform, or a managed Node host plus a managed PHP host both work.

Classic shared hosting usually fits **only partly**. It often has no persistent Node.js process and no cron at
one-minute resolution, and it usually offers MySQL rather than PostgreSQL. This application needs PostgreSQL: it relies
on CHECK constraints, partial unique indexes and triggers.

**Origins.** Serve the web app and the API from the same registrable domain (for example `app.` and `api.` of
`example.com`), so the Sanctum session cookie works with `SameSite=Lax`.

## 2. Software versions

| Component | Version |
| --- | --- |
| PHP | 8.2+ (verified 8.3), extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, **`opcache`** |
| Composer | 2.7+ |
| Node.js | 20.9+ (verified 22 LTS) |
| PostgreSQL | 16 (no extensions required) |
| SMTP | any authenticated SMTP provider with TLS |

## 3. Backend installation

```bash
git clone <private repository> lexora && cd lexora/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env            # then edit — section 6
php artisan key:generate        # a NEW key for this environment
```

## 4. Frontend installation

```bash
cd ../frontend
npm ci
```

## 5. PostgreSQL

```sql
CREATE ROLE lexora LOGIN PASSWORD '<generated, from your secret store>';
CREATE DATABASE lexora OWNER lexora;
```

The application role needs ownership of its database (migrations create tables, constraints and triggers), and
nothing else. Use TLS for the database connection when it is not on the same host.

## 6. Environment configuration

### Backend `.env` (production values)

| Variable | Value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | generated per environment (`php artisan key:generate`) |
| `APP_URL` | `https://api.example.com` |
| `FRONTEND_URL` | `https://app.example.com` (CORS) |
| `PUBLIC_APP_URL` | `https://app.example.com` — used in client links. **E-mail is refused in production unless it is public HTTPS** |
| `SANCTUM_STATEFUL_DOMAINS` | `app.example.com` |
| `SESSION_DOMAIN` | `.example.com` |
| `SESSION_SECURE_COOKIE` | leave unset (Secure in production) or `true`; **never `false`** |
| `SESSION_ENCRYPT` | `true` |
| `TRUSTED_PROXIES` | the reverse proxy's address(es), comma-separated (or `*` when the PHP server is only reachable through the proxy). Needed so Laravel recognises HTTPS behind a TLS-terminating proxy |
| `DB_*` | the database above |
| `MAIL_*`, `LEXORA_*` | see [email-configuration.md](email-configuration.md) |
| `LEXORA_TIMEZONE` | the firm's time zone, e.g. `Europe/Budapest` |
| `LEXORA_MAIL_UI_URL` | empty |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` (or `stderr` in containers) / `warning` |
| `DEMO_ADMIN_*` | remove (the demo seeders refuse to run in production anyway) |
| `QUEUE_CONNECTION` | unused (e-mail is sent synchronously); leave `database` |

### Frontend environment at **build time**

| Variable | Value |
| --- | --- |
| `NEXT_PUBLIC_API_URL` | `https://api.example.com` (also enables HSTS and `upgrade-insecure-requests` in the CSP) |
| `NEXT_PUBLIC_SESSION_COOKIE` | the backend's `SESSION_COOKIE` |
| `NEXT_PUBLIC_DEMO_EMAIL` / `NEXT_PUBLIC_DEMO_PASSWORD` | **must be unset** — `NEXT_PUBLIC_*` values are compiled into the JavaScript |

## 7. Database migrations

```bash
cd backend
php artisan migrate --force
```

- Migrations are additive. Never run `migrate:fresh` or `db:seed` on production.
- Take a backup before every deployment (section 17).
- Create administrators interactively: `php artisan lexora:create-admin`.

## 8. Demo data (non-production only)

```bash
php artisan db:seed     # demo admin, clients, matters, tasks, notes, documents, requests, signatures
```

- The seed is idempotent and refuses `APP_ENV=production`.
- `LEXORA_DEMO_DOCUMENTS=false` skips the PDFs, requests and signatures.

## 9. Private storage and permissions

**Where PDFs live.** In `storage/app/private/documents`, or `DOCUMENTS_STORAGE_DIR` under `storage/app/private`.
- This directory is **not** web-served. Never point a web root, symlink or `storage:link` at it.

**Permissions.** The PHP-FPM user needs read/write access to `storage/` and `bootstrap/cache/`, and nothing else
writable:
```bash
chown -R www-data:www-data storage bootstrap/cache && chmod -R u+rwX,go-rwx storage
```

**Persistence.** Keep `storage/app/private` on persistent storage that survives redeployments: a volume, or a disk
outside the release directory linked into it.

**Backups.** Back it up **together with the database**: versions and signature evidence store each file's SHA-256,
and a file restored without its row (or the reverse) fails the integrity check.

**Upload limits.** PHP's `upload_max_filesize` and `post_max_size` must be above `DOCUMENTS_MAX_UPLOAD_MB` (20 by
default), for example 25M and 30M. The proxy's body limit must be too (nginx `client_max_body_size 30m`).

## 10. Production build

```bash
cd backend
php artisan config:cache && php artisan route:cache && php artisan view:cache
cd ../frontend
NEXT_PUBLIC_API_URL=https://api.example.com NEXT_PUBLIC_SESSION_COOKIE=lexora_session npm run build
npm run start -- --port 3000       # behind the proxy; run it under systemd/pm2/a container supervisor
```

- `npm install` runs `postinstall`, which copies the PDF.js worker and fonts to `public/pdfjs`.
- **Re-run the cache commands after every `.env` change.** With a cached configuration, `env()` is read only from the
  cache.

## 11. Backend web server

- PHP-FPM serves `backend/public/index.php` only. The document root is `backend/public`.
- **Enable OPcache.** Locally, requests without it took 215–376 ms; with it, 21–110 ms (FINAL_QA_REPORT §7).
  ```ini
  opcache.enable=1
  opcache.memory_consumption=192
  opcache.validate_timestamps=0     ; reload PHP-FPM on every deployment
  expose_php=Off
  ```
- The proxy terminates TLS and forwards `X-Forwarded-For` and `X-Forwarded-Proto` (set `TRUSTED_PROXIES`).

## 12. HTTPS

- Obtain certificates (e.g. Let's Encrypt) for both hosts and redirect HTTP to HTTPS at the proxy.
- The API then sends HSTS (production, HTTPS). The web app sends HSTS when it was built with an HTTPS
  `NEXT_PUBLIC_API_URL`.

## 13. SMTP

Follow [email-configuration.md](email-configuration.md):
- provider credentials go in the secret store;
- `MAIL_FROM_ADDRESS` is on your domain, with **SPF, DKIM and DMARC** published (not done or verified by this
  project);
- `MAIL_TIMEOUT=10`.

Before the first client e-mail, test with `POST /api/communications/test-email` (signed in; it goes to your own
address).

## 14. Scheduler

Reminders, stale-send recovery, and the overdue and expired-link notifications all run from:
```cron
* * * * * cd /srv/lexora/backend && php artisan schedule:run >> /dev/null 2>&1
```
- Windows: Task Scheduler, every minute, running `php.exe artisan schedule:run`.
- With several application servers, use a shared cache store (database or Redis) so `onOneServer()` works.

## 15. Logging and monitoring

- Laravel logs to `storage/logs` (`daily`) or `stderr`. Logs never contain link tokens, PDF content or SMTP errors
  (only safe categories).
- Monitor these:
  - HTTP 5xx rates;
  - `email.failed` activity events and `email_failed` notifications;
  - disk usage of the private storage;
  - the cron heartbeat (the last `schedule:run`).

## 16. Security configuration checklist

- [ ] `APP_DEBUG=false`, a new `APP_KEY`, new database credentials
- [ ] HTTPS everywhere; `SESSION_SECURE_COOKIE` not `false`; `SESSION_DOMAIN` and `SANCTUM_STATEFUL_DOMAINS` set
- [ ] `TRUSTED_PROXIES` limited to your proxy
- [ ] `NEXT_PUBLIC_DEMO_*` unset at build time, then check that the build does not contain the demo address:
  `grep -r "admin@lexora.test" frontend/.next/static` (must return nothing)
- [ ] no demo administrator in the production database; real administrators via `lexora:create-admin`
- [ ] `storage/app/private` not web-reachable (`curl https://api.example.com/storage/...` → 404)
- [ ] OPcache on, `expose_php=Off`
- [ ] SPF/DKIM/DMARC for the sender domain; test e-mail received
- [ ] review link lifetimes (`UPLOAD_LINK_EXPIRY_DAYS`, `SIGNING_LINK_EXPIRY_DAYS`) and public rate limits
- [ ] malware scanning of uploads at the storage layer (not built in; see SECURITY_AUDIT §6)
- [ ] the firm accepts the electronic-signature limits (application-level signature; no qualified/certificate-based
  signature; no independent identity verification)

## 17. Backup and restoration

**What to back up:**
- PostgreSQL (`pg_dump -Fc`);
- `backend/storage/app/private` (PDFs);
- the `.env` from the secret store.

**When:** before each deployment and at least daily. Keep off-site copies, encrypted at rest.

**Restoring:**
1. Restore the database and the files from the **same point in time**.
2. Run `php artisan migrate --force` (no-op if the schema is current).
3. Verify a sample: download a few documents and signed PDFs. The API re-checks SHA-256 on every download and refuses
   a mismatch.

Links issued before the backup stay valid only until they expire.

## 18. Smoke tests after deployment

1. `GET https://api.example.com/up` → 200.
2. Sign in at `https://app.example.com/login`, open the dashboard, matters and documents.
3. Response headers: the API sends `X-Content-Type-Options`, `X-Frame-Options`, HSTS and `Cache-Control: no-store`;
   the web app sends CSP and HSTS.
4. `GET /api/communications/status` → `available: true`; send the test e-mail.
5. Upload a PDF, preview and download it.
6. Create a document request for a test client with your own address, e-mail it, open the link in a private window,
   upload, then approve.
7. Create a signing request, e-mail it to yourself, sign on a phone, then download the signed PDF.
8. Wait one minute and check that the scheduler ran (`php artisan schedule:list`, log entries, or a due test reminder).

## 19. Common deployment errors

| Symptom | Cause |
| --- | --- |
| 419 on login | `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` do not match the web host; cookies blocked by mixed HTTP/HTTPS |
| CORS error in the browser | `FRONTEND_URL` differs from the web origin (scheme and port included) |
| "E-mail unavailable … public HTTPS address" | `PUBLIC_APP_URL` is not `https://` or points at localhost |
| Client links point to localhost | `PUBLIC_APP_URL` missing; configuration cache not rebuilt |
| 413 on uploads | PHP `post_max_size`/`upload_max_filesize` or the proxy body limit below 25–30 MB |
| PDF preview blank | `public/pdfjs` missing (re-run `npm ci`), or a CSP edited without `worker-src 'self'` |
| No reminders | cron not running `schedule:run`; `LEXORA_REMINDERS_ENABLED=false` |
| HSTS / secure URLs missing behind the proxy | `TRUSTED_PROXIES` not set |
| Slow API (200+ ms per request) | OPcache disabled |

## 20. Rollback

1. Keep the previous release directory and the pre-deployment backup.
2. Code-only rollback: switch the release symlink back, then `php artisan config:cache` and a PHP-FPM reload.
3. With a schema change: the migrations are additive, so the previous code normally runs on the newer schema. Use
   `php artisan migrate:rollback` only for a migration that has not stored production data. Otherwise restore the
   pre-deployment backup of **both** the database and the PDFs.
4. Never delete the private storage directory as part of a rollback.
