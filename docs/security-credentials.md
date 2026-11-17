# Credentials: review and safe rotation

Review date: 2026-11-17 (during the Phase 4 merge). Scope: everything committed to `main` and
`feature/phase-4-client-document-requests`, pushed to the **private** repository `github.com/rioni111/amanetproject`.

## 1. What is (and is not) committed

| Item | Committed? | Where | Notes |
| --- | --- | --- | --- |
| PostgreSQL application password (`DB_PASSWORD`) | **No** | `backend/.env` only (gitignored) | Generated randomly by `setup-windows.bat` (32 characters). History audit: absent from every commit. |
| PostgreSQL superuser secret | **No** | `C:\laragon\data\postgresql-16-superuser.secret` (outside the repository) | Absent from every commit. |
| `APP_KEY` | **No** | `backend/.env` only | Generated per installation; absent from every commit. |
| Secure-link tokens (Phase 4) | **No** | never stored anywhere | Only SHA-256 hashes in the database (not committed either). |
| **Demo administrator password** `Lexora!Demo2026` | **Yes** | `backend/.env.example` (`DEMO_ADMIN_PASSWORD`), `frontend/.env.example` (`NEXT_PUBLIC_DEMO_PASSWORD`), `README.md` (Demo login), `frontend/e2e/fixtures.ts` (default when `E2E_ADMIN_PASSWORD` is unset) | Development fixture for the fictional demo workspace. |
| Test-only password `Test-Password-123` | Yes | `backend/phpunit.xml`, `DemoSeederTest` | Exists only inside the disposable `amanet_test` database during PHPUnit runs. |
| Your Gmail address | Yes (commit metadata only) | author/committer of every commit | Not in any file. |

## 2. Risk assessment of the demo credentials

- The demo account (`admin@lexora.test`) exists only in the **local** databases `amanet` (development) and `amanet_e2e`
  (Playwright), created by `DemoAdminSeeder` from `DEMO_ADMIN_*` in `backend/.env`. The password is **not hard-coded in
  application code**.
- `DatabaseSeeder` and `DemoAdminSeeder` **refuse to run when `APP_ENV=production`**; production administrators are created with
  `php artisan lexora:create-admin` (production password policy: ≥ 12 characters, mixed case, numbers, symbols, not found in known
  breaches).
- The development API listens on `localhost` only, and the data is fictional, so the committed value currently gives no access
  to anything outside the developer's own machine.
- **Residual risks**
  1. Anyone with access to the repository knows the demo password. It must never be the password of a reachable server.
  2. `NEXT_PUBLIC_*` variables are **inlined into the JavaScript bundle at build time**. A production build made while
     `NEXT_PUBLIC_DEMO_EMAIL` / `NEXT_PUBLIC_DEMO_PASSWORD` are set would ship the credentials to every visitor and show the
     "Demo access" box on the login page.
  3. A development database copied to a server would carry the demo account with it.

**Decision:** no rotation and no history rewrite during the Phase 4 merge. There is no demonstrated need: the repository is
private, the account exists only on localhost development/test databases, and no real data is involved. Rotate before any of the
triggers in §5.

## 3. Rotate the local demo password (development)

1. Choose a new password (PowerShell; nothing is printed to logs):
   ```powershell
   $new = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 20 | ForEach-Object { [char]$_ }) + '!a1'
   ```
2. Put it in `backend/.env` → `DEMO_ADMIN_PASSWORD="…"`.
3. In `frontend/.env.local`, either set `NEXT_PUBLIC_DEMO_PASSWORD` to the same value, or **remove both** `NEXT_PUBLIC_DEMO_EMAIL`
   and `NEXT_PUBLIC_DEMO_PASSWORD` to hide the demo box.
4. Apply it to the development database (updates the stored hash; other sessions of that account end):
   ```bash
   cd backend && php artisan db:seed --class=DemoAdminSeeder
   ```
5. Restart the Next.js server (`NEXT_PUBLIC_*` values are read at start/build).
6. For the Playwright suite, provide the new value in the shell before `verify-windows.bat` / `npx playwright test`, because the
   fixture falls back to the old literal: `set E2E_ADMIN_PASSWORD=…` (the E2E database is seeded from `backend/.env`).
7. Optionally replace the example values in `backend/.env.example`, `frontend/.env.example`, `README.md` and the fixture default
   with a placeholder in a **normal commit**. The old value stays in history but is useless once it is no longer valid anywhere —
   no history rewrite is needed.

## 4. Before any public deployment

1. `APP_ENV=production`, `APP_DEBUG=false`, a **new** `APP_KEY` (`php artisan key:generate`) and new database credentials.
   Rotating `APP_KEY` signs everyone out; Phase 4 upload-link hashes are unaffected (plain SHA-256, no key).
2. Never run `db:seed` against production (it refuses anyway) and never copy the development database. If a database was ever
   seeded, remove the demo account before going live.
3. Create real administrators with `php artisan lexora:create-admin` (interactive password prompt; nothing on the command line).
4. Build the frontend with **no** `NEXT_PUBLIC_DEMO_*` variables, then check the build output does not contain the demo email:
   ```powershell
   Select-String -Path frontend/.next/static -Pattern 'admin@lexora.test' -SimpleMatch -List   # must return nothing
   ```
5. HTTPS everywhere: `SESSION_SECURE_COOKIE=true`, correct `SANCTUM_STATEFUL_DOMAINS` / `FRONTEND_URL`, and
   `PUBLIC_APP_URL=https://…` for client upload links.
6. Keep all secrets in the hosting platform's secret store or server `.env` files — never in the repository.

## 5. When to rotate, and what to do if a real secret leaks

Rotate the demo password (§3) **before**: making the repository public, adding collaborators you do not fully trust, exposing a
development server on a network, or recording/sharing demos.

If a real secret is ever committed or pushed: **rotate it first** (new database password, new `APP_KEY`, revoke tokens), which
makes the leaked copy useless. Rewriting Git history (and force-pushing) is a separate, deliberate decision that must be
coordinated with everyone who has cloned the repository; it is not part of normal work.

Before making the repository public, also consider switching your Git commit email to GitHub's private `noreply` address for
future commits (existing commits keep the current address unless history is rewritten).
