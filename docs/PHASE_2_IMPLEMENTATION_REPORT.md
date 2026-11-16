# Phase 2 Implementation Report — Lexora Workspace (Amanet)

**Phase:** 2 of 7 — Matter Management, Client Management, Tasks, Internal Notes, Activity History & Search
**Report date:** 16 November 2026
**Branch:** `feature/phase-2-matter-client-management` (baseline `8bdd386` on `main`)
**Environment:** Windows 11 Pro 10.0.26300, Laragon, PHP 8.3.35, PostgreSQL 16.16, Node.js 22.23.5 — the target machine
**Status:** ✅ Phase 2 complete. Every acceptance criterion in §16 was executed and verified on the target machine; the full regression (`verify-windows.bat`) passed **11/11 steps**.

This report describes only what exists in the codebase and was executed. Phase 1 documentation is unchanged except for
cross-references; see [`PHASE_1_IMPLEMENTATION_REPORT.md`](PHASE_1_IMPLEMENTATION_REPORT.md).

---

## 1. Executive summary

Phase 2 turns the read-only Phase 1 lists into a working legal matter-management workspace. An administrator can now:

- create, edit, view and (only while unused) delete **clients**, with a client detail page listing the client's matters;
- create **matters** with a concurrency-safe unique number (`LEX-2026-0011` …), edit them, change **status** under a documented
  transition policy, change **priority**, manage **calendar-date deadlines**, **archive** and **restore** them;
- work inside a **matter workspace** (`/matters/{id}`) with an overview, **tasks** (create, edit, complete, reopen, delete),
  confidential **internal notes** (create, edit, delete) and a paginated **activity timeline**;
- search, filter (status, priority, deadline, client), sort and paginate matters and clients on the server, with filters in the URL;
- see the dashboard, lists and counters update after every confirmed change.

Every change is written in **one database transaction together with its append-only history event**, concurrent edits are
detected (**409**), and server-managed fields are rejected. All Phase 1 behaviour is preserved and all Phase 1 tests still pass.

**Not in Phase 2 (by design):** documents, versioning, external client links, signing and email (Phases 3–6).
Remaining limitations are listed in §11 and §16.

---

## 2. Files created and modified

### Backend (`backend/`)

| File | Purpose |
| --- | --- |
| `database/migrations/2026_11_17_100000_phase2_matter_management.php` | **New, additive.** Optional client email; `archived_from_status`; reverse lifecycle CHECKs; `lock_version` on matters/tasks/notes; `matter_number_counters`; indexes on `matters.created_at/opened_at`; back-fills |
| `app/Services/MatterService.php` | **New.** Create (numbering), update (with safe history diff), `transition()`, `archive()`, `restore()` — row locks, lock-version checks, one transaction each |
| `app/Services/MatterNumberGenerator.php` | **New.** Atomic per-year counter (`INSERT … ON CONFLICT … RETURNING`) inside the creating transaction |
| `app/Services/TaskService.php` | **New.** Task create/update/delete; `completed_at` management; completed/reopened/updated/deleted events |
| `app/Services/MatterNoteService.php` | **New.** Note create/update/delete; history without note bodies |
| `app/Services/ClientService.php` | **New.** Client create/update/delete (retention rule), client events with `matter_id = null` |
| `app/Support/LegalDate.php` | **New.** Calendar date ⇄ `timestamptz` conversion in the firm's time zone |
| `app/Models/Concerns/HasLockVersion.php` | **New.** Optimistic concurrency (`lock_version` increment + 409 assertion) |
| `app/Http/Controllers/Api/{MatterLifecycle,MatterTask,MatterNote,MatterActivity}Controller.php` | **New.** Thin controllers |
| `app/Http/Controllers/Api/{Matter,Client}Controller.php` | Extended: store/show/update(/destroy); new filters; detail payloads with counters |
| `app/Http/Requests/{Clients,Matters,Tasks,Notes}/*` | **New.** 15 Form Requests (validation, `prohibited` server fields, policy authorisation) |
| `app/Http/Resources/{Task,MatterNote,ActivityEvent}{Resource,Collection}.php` | **New** resources |
| `app/Http/Resources/{Matter,Client,ClientSummary}Resource.php` | Extended fields (dates, counters, `lock_version`, `allowed_transitions`) |
| `app/Enums/MatterStatus.php` | `allowedTransitions()`, `canTransitionTo()`, `canBeArchived()`, `initialValues()` |
| `app/Enums/ActivityEventType.php` | 11 new event types |
| `app/Models/{Matter,Task,MatterNote}.php` | Lock version, `archived_from_status` cast, `assertNotArchived()`, `Task::isOverdue()` |
| `app/Policies/{Matter,Client}Policy.php` | create/update/archive/restore/delete abilities |
| `routes/api.php` | 20 new routes (apiResource + nested scoped bindings) |
| `config/lexora.php`, `.env.example` | `LEXORA_TIMEZONE` |
| `database/seeders/DemoWorkspaceSeeder.php`, `database/factories/MatterFactory.php` | Set `archived_from_status` for archived records |
| `phpunit.xml` | Deterministic `LEXORA_TIMEZONE=UTC` |
| `tests/Feature/Phase2/*` (5 files), `tests/Support/matter-number-worker.php` | **New.** 51 tests |

### Frontend (`frontend/`)

| File | Purpose |
| --- | --- |
| `src/app/(dashboard)/matters/[id]/page.tsx`, `clients/[id]/page.tsx` | **New** routes (params read inside `Suspense`, as Cache Components require) |
| `src/features/matters/matter-detail-view.tsx` | **New.** Matter workspace: header, overview, tabs (Overview, Tasks, Internal notes, Activity) |
| `src/features/matters/matter-form-dialog.tsx` | **New.** Create/edit matter (React Hook Form + Zod) |
| `src/features/matters/matter-actions.tsx` | **New.** Status control (allowed transitions only, confirm on close), priority control, archive/restore with confirmation, list quick actions |
| `src/features/matters/{api.ts,matters-view.tsx,matter-columns.tsx}` | Mutations, new filters/sorts/columns, create button, linked rows |
| `src/features/clients/{client-form-dialog,client-detail-view}.tsx` | **New.** Client form and client detail page |
| `src/features/clients/{api.ts,clients-view.tsx}` | Mutations, create button, row actions, created date, null-safe email |
| `src/features/tasks/{api.ts,tasks-panel.tsx}` | **New.** Task list, filter, form dialog, completion toggle, delete confirmation |
| `src/features/notes/notes-panel.tsx` | **New.** Composer, list, inline edit, delete confirmation; plain-text rendering |
| `src/features/activity/activity-timeline.tsx` | **New.** Infinite-loading timeline with labels/icons (no raw codes) |
| `src/components/ui/{dialog,tabs,textarea}.tsx` | **New** primitives (Radix Dialog already installed; tabs implemented without a new dependency) |
| `src/components/shared/{toaster,form-alert}.tsx` | **New.** Dependency-free toasts (aria-live), form banner + required marker |
| `src/lib/{forms.ts,api/invalidate.ts}` | **New.** Server-error mapping, submit guard; cache invalidation |
| `src/lib/api/client.ts`, `src/lib/utils/{format,labels}.ts`, `src/types/api.ts` | `patch`/`delete`; date-only handling; task labels; Phase 2 types |
| `src/components/shared/{deadline,form-field}.tsx`, `components/dashboard/upcoming-deadlines.tsx` | Calendar-date deadlines; required marker |
| `next.config.ts`, `tsconfig.json`, `eslint.config.mjs`, `.gitignore` | Separate build dir for the E2E server (`NEXT_DIST_DIR`) |
| `playwright.config.ts`, `e2e/env.ts`, `e2e/global-setup.ts`, `e2e/fixtures.ts`, `e2e/auth.spec.ts` | Isolated E2E environment |
| `e2e/phase2-*.spec.ts` (5 files) | **New.** 15 end-to-end tests (scenarios A–G) |

### Scripts and docs

`scripts/windows/setup.ps1` (creates `amanet_e2e`; port-specific session cookie), `scripts/windows/verify.ps1` (dedicated E2E
ports), `README.md`, `docs/architecture.md`, `docs/data-model.md`, `docs/api-conventions.md`, `docs/implementation-phases.md`,
this report.

**Architectural changes:** a service layer for all writes (one transaction per change incl. history), optimistic concurrency,
a calendar-date convention for legal dates, and an isolated end-to-end test environment. The three-tier architecture
(Next.js → Laravel REST → PostgreSQL) and all Phase 1 conventions are unchanged. **No new npm or Composer dependencies.**

---

## 3. Backend implementation

### New endpoints

| Method | Path | Controller | Notes |
| --- | --- | --- | --- |
| POST | `/api/clients` | ClientController@store | 201 |
| GET | `/api/clients/{client}` | @show | counters active/closed/archived/total |
| PATCH | `/api/clients/{client}` | @update | |
| DELETE | `/api/clients/{client}` | @destroy | 409 if the client has matters |
| POST | `/api/matters` | MatterController@store | number generated server-side |
| GET | `/api/matters/{matter}` | @show | task/note counters, client reference |
| PATCH | `/api/matters/{matter}` | @update | details only; `status` is rejected (422) |
| POST | `/api/matters/{matter}/status` | MatterLifecycleController@status | transition policy, 422 on invalid move |
| POST | `/api/matters/{matter}/archive` · `/restore` | @archive · @restore | |
| GET | `/api/matters/{matter}/activity` | MatterActivityController | newest first, paginated |
| GET/POST | `/api/matters/{matter}/tasks` | MatterTaskController@index/@store | |
| PATCH/DELETE | `/api/matters/{matter}/tasks/{task}` | @update/@destroy | scoped binding |
| GET/POST | `/api/matters/{matter}/notes` | MatterNoteController@index/@store | |
| PATCH/DELETE | `/api/matters/{matter}/notes/{note}` | @update/@destroy | scoped binding |

Full contract: [`api-conventions.md`](api-conventions.md#endpoints-phase-2). The suggested endpoint set was implemented as
given, with one deliberate addition: **`POST /matters/{matter}/status`**. Status changes have rules, timestamp side effects and
their own history event, so they are an explicit action rather than a PATCH field.

### Components

- **Controllers** stay thin: Form Request → service → resource (reloaded with counters).
- **Services** own every write (§10 for the transaction pattern). No repository layer was added, consistent with Phase 1.
- **Form Requests** validate every input, authorise through policies, and **reject** (not ignore) server-managed attributes
  (`matter_number`, `closed_at`, `archived_at`, `opened_at`, `due_at`, `created_by`, `author_id`, `matter_id`, `completed_at`, `id`).
- **Resources** whitelist output. Note bodies appear only in the admin-only notes endpoints.
- **Policies**: `MatterPolicy` (view, create, update, archive, restore — tasks and notes are authorised as `update` on the
  parent matter), `ClientPolicy` (view, create, update, delete). Business rules (archived read-only, client retention) answer
  409 from the services; authorisation answers 403.
- **Scoped bindings** make `/matters/A/tasks/B` a 404 unless task B belongs to matter A (same for notes).

---

## 4. Database changes

One additive migration: `2026_11_17_100000_phase2_matter_management`. It was applied with `php artisan migrate` to the
development database **without data loss** (no `migrate:fresh`, `db:wipe` or resets of `amanet`).

| Change | Detail |
| --- | --- |
| `clients.email` | `DROP NOT NULL` (optional contact data) |
| `matters.archived_from_status` | varchar(20) null; `CHECK (archived_from_status IS NULL OR (status = 'archived' AND archived_from_status IN (open, in_progress, on_hold, closed)))`; back-filled for existing archived rows |
| Lifecycle CHECKs (reverse direction) | `matters_archived_at_only_when_archived`, `matters_closed_at_only_when_closed` (verified against existing data before adding) |
| `lock_version` | integer default 1 on `matters`, `tasks`, `matter_notes`, `CHECK >= 1` |
| `matter_number_counters` | `year` PK (2000–2999 CHECK), `last_value` (≥ 1 CHECK), `updated_at`; seeded from existing numbers (2026 → 10) |
| Indexes | `matters(created_at)`, `matters(opened_at)` for the new sorts |

**Relations unchanged:** client → matters (`RESTRICT`), matter → tasks/notes (`CASCADE`), matter → activity events (`RESTRICT`).
Foreign keys were never disabled.

**Concurrency:** matter numbers come from an atomic upsert on one counter row per year, executed inside the matter's creating
transaction. Concurrent creators serialise on that row only, failures roll the counter back, and `matters.matter_number UNIQUE`
remains the backstop. A test spawns **4 independent PHP processes** drawing **100 numbers concurrently**: all are unique and
gap-free. State changes use `SELECT … FOR UPDATE` per matter (consistent lock order: matter, then task/note) plus
`lock_version` for stale edits.

Details and the lifecycle diagram: [`data-model.md`](data-model.md#matter-lifecycle-phase-2-transition-policy).

---

## 5. Frontend implementation

### New pages

| Route | Content |
| --- | --- |
| `/matters/{id}` | Header (number, title, client link, status, priority, deadline, Edit, Archive/Restore), archived banner, tabs: **Overview** (description, outstanding/overdue/completed task stats, status & priority controls, details card), **Tasks**, **Internal notes**, **Activity**. Tab state is kept in `?tab=`. Proper 404 state. |
| `/clients/{id}` | Identity header (type, reference, client since), contact card (mailto/tel links), matter counters, record-management card (delete only when allowed), paginated matters table, New matter (pre-selected client), Edit client. Proper 404 state. |

### Forms

Client form, matter form (create/edit), task form and note composer/inline editor. All have labels with required markers,
helpful placeholders and hints, inline Zod validation mirroring the server rules, server 422 errors mapped onto fields
(first error focused), a form banner for 409 and network errors, loading indicators, disabled state and Cancel. A
**synchronous submit guard** prevents duplicate submissions; double-clicking "Add task" creates exactly one task (E2E-verified).

### Lists

- **Matters:** New matter, linked titles and clients, Opened / Deadline / Open tasks / Updated columns, a "Manage" quick-action
  dialog per row (status, priority, edit, archive/restore, open), filters for status, priority, **deadline** (overdue, next 14
  days, none) and **client**, sorts including newest and opening date. Stacked cards with actions on phones.
- **Clients:** New client, linked names, Added column, Edit/Open row actions, search across name, email, phone and reference.

### UI/UX

The Lexora identity is unchanged (charcoal `#111827`, off-white `#FAFAF8`, teal `#0F766E`, Geist, existing tokens and
components). New elements follow the same card, badge and table language. Loading skeletons, empty states with next actions,
error states with retry, success toasts (only after API confirmation), and confirmation dialogs for close, archive, restore and
delete. Priority is shown as label plus meter, status as label plus dot, and the timeline uses icons with readable labels (no
raw event codes).

### Responsive behaviour and accessibility

- Dialogs become bottom sheets on phones, with scrollable content and a sticky action bar. Tables become cards below `md`.
  Long unbroken text wraps. The E2E suite checks for horizontal overflow on the matter tabs and client detail on a Pixel 7.
- WAI-ARIA tabs with roving tabindex and arrow/Home/End keys; Radix dialogs (focus trap, Escape, focus return).
- `role="checkbox"` / `aria-checked` on the task completion toggle, labelled icon buttons, and errors linked via `aria-describedby`.
- Toasts use `aria-live` (polite for success, assertive for errors). Existing focus styles and reduced-motion support are kept.

---

## 6. Client management

| Item | Result |
| --- | --- |
| Create / edit / detail / list | ✅ real API and PostgreSQL; E2E scenario A |
| Validation | name required (≤ 200, not blank), type enum, email RFC (optional), phone pattern + ≥ 5 digits (optional), address ≤ 1000, reference pattern + **unique** (ignores itself on edit) — frontend Zod and backend Form Request |
| Record-management rule | delete **only** if the client has no matters (service check, plus the FK `RESTRICT`); otherwise 409 and the button is disabled with an explanation |
| History | `client.created`, `client.updated` (changed field names only, no contact values), `client.deleted` — `matter_id = null`, no invented matter |
| After creation | toast, redirect to the client page, client picker / lists / dashboard refreshed |
| Tests | 12 PHPUnit tests (`ClientManagementTest`), 4 Playwright tests (`phase2-clients.spec.ts`) — all passing |

---

## 7. Matter management

- **Creation:** title, description, client, type (free text with suggestions), initial status (open / in progress / on hold),
  priority, opening date (default today), deadline. Returns 201 with the generated number. One `matter.created` event. Redirects
  to the workspace and refreshes the dashboard and client counts.
- **Numbering:** existing format `LEX-{year}-{0000}` kept; per-year counter (§4). Seeded `LEX-2026-0001…0010` continue at `0011`.
- **Editing:** title, description, client, type, priority, opening date, deadline. The number is immutable. History records
  before/after values for title, type, client and dates; a description change is recorded only as "changed". A priority change
  adds `matter.priority_changed`.
- **Status transitions:** documented policy (open ⇄ in progress ⇄ on hold; any active → closed; closed → open/in progress).
  `closed_at` is set on close and cleared on reopen. Invalid moves return 422 (`errors.status`). The UI offers only
  `allowed_transitions` and asks for confirmation before closing.
- **Deadlines:** calendar dates in the firm's time zone, stored as end of day. A matter is overdue only after the whole day.
  No day shifts between time zones (tested with `America/New_York`).
- **Priority:** low / medium / high / urgent, from the detail page and list quick actions. Label plus 4-step meter.
- **Archiving:** confirmation dialog, allowed from any non-archived state, sets `archived_at` and `archived_from_status`.
  Excluded from active totals, still listed under the Archived filter, read-only (409 on writes). Tasks, notes and history
  are preserved. Never hard-deleted.
- **Restore:** returns to the exact status held before archiving (a closed matter keeps its original `closed_at`). Recorded.

Tests: 21 PHPUnit tests (`MatterManagementTest`, incl. 5 transition cases) + 2 concurrency tests; 3 + 1 Playwright tests
(`phase2-matters.spec.ts`, archive scenario E).

---

## 8. Task management

- **CRUD:** create (title, description, status, priority, due date; creator from the session), edit, complete, reopen,
  priority and due-date changes, delete with confirmation (recorded as `task.deleted`, with title and id).
- **Completion:** `completed_at` is set on completion and cleared on reopen. The `tasks_completion_consistency` CHECK makes any
  other state impossible. Events: `task.completed`, `task.reopened`, and `task.updated` (changed fields).
- **Scoping:** tasks of another matter return 404. Nonexistent matter returns 404. `matter_id` and `created_by` cannot be supplied.
- **Dashboard integration:** outstanding / overdue / completed counters on the matter, open tasks on the dashboard, and the
  timeline all refresh after each change. The E2E test checks the dashboard `open_tasks` delta through create → complete → reopen.
- **UI:** status filter (all, outstanding, to do, in progress, completed); open work first, by deadline; overdue indicator;
  accessible completion toggle; empty, loading and error states.

Tests: 9 PHPUnit tests (`TaskManagementTest`), Playwright scenario C (including the double-click duplicate check).

---

## 9. Internal notes

- **CRUD:** add (author from the session), inline edit (marked "edited" with the time), delete with confirmation.
- **Privacy:** admin-only endpoints (401 without a session, verified in PHPUnit and E2E with an explicitly empty browser
  context). Scoped to their matter. Never included in matter or list payloads. Bodies rendered as **plain text** (React
  escaping, `white-space: pre-wrap`); an E2E test proves an `<img onerror>` payload is shown as text and not executed.
- **Audit:** `note.added`, `note.updated`, `note.deleted` with actor and time. **Note bodies are never written to activity
  metadata, descriptions or logs** (asserted in PHPUnit and E2E). Deleted notes are hard-deleted (confidential text not
  retained); the event preserves the fact.

Tests: part of `NoteAndActivityTest` (4 note tests) and Playwright scenario D.

---

## 10. Activity timeline

- **Recorded events:** `matter.created`, `matter.updated`, `matter.status_changed`, `matter.priority_changed`, `matter.archived`,
  `matter.restored`, `task.created`, `task.updated`, `task.completed`, `task.reopened`, `task.deleted`, `note.added`,
  `note.updated`, `note.deleted`, plus client events without a matter.
- **Append-only:** unchanged Phase 1 guarantees (model guard + PostgreSQL trigger), re-tested in Phase 2.
- **Transactions:** each service method wraps the change and `ActivityLogger::record()` in one `DB::transaction`. A test
  injects a failing logger into six different operations (status change, edit, archive, task create, task complete, note
  create). Every change rolls back: no matter, task or note row changes and no event is stored. A failed matter creation also
  does not consume a number.
- **Display:** newest first, 20 per page with "Load older activity". Each entry shows an icon, a readable label, the
  description, the administrator, the date (once per day) and time, and a "from → to" chip for status and priority changes.

---

## 11. Security review

| Area | Status |
| --- | --- |
| Authentication | All 20 new routes sit behind `auth:sanctum` + `admin` + `throttle:api`. 401 verified for every new endpoint family in PHPUnit and for notes in E2E. No client accounts. |
| Authorisation | Policies on every action (Form Request `authorize()` or `Gate::authorize()`); scoped bindings for nested resources |
| Input validation | Form Requests for every write; length caps; enum rules; date format and range; unique reference; server-managed fields → 422 `prohibited`; services re-check business rules (transitions, archived state, dates) |
| Mass assignment | Services assign explicit attributes from validated data only (`forceFill` of whitelisted keys) |
| Concurrency | Row locks, `lock_version` → 409, atomic numbering |
| Sensitive data | No hashes, tokens or credentials in resources. 5xx messages hidden. Note bodies and matter descriptions never enter history metadata or logs. |
| CSRF | Unchanged Sanctum SPA flow; all mutations send `X-XSRF-TOKEN` (E2E 419 test still passes) |
| XSS | All user text rendered by React (escaped); no `dangerouslySetInnerHTML`; CSP unchanged |
| **Cookie collision** (Phase 1 limitation) | **Resolved for the session.** Browsers share cookies across ports of one host, and both local Lexora copies used `lexora_session`. This project now uses a port-specific name (`lexora_session_3010`, set by `setup-windows.bat` in `backend/.env` and `frontend/.env.local`), and E2E uses `lexora_e2e_session`. The other installation was not modified. **Remaining:** Laravel's `XSRF-TOKEN` cookie name is fixed by the framework. If the other app overwrites it, the first write answers 419 and the API client refreshes the token and retries once (existing behaviour). |
| Known limitations | single `admin` role (403 path is policy-ready but not exercised by a real non-admin); CSP still allows `'unsafe-inline'` (Phase 7); client picker limited to 100 clients |

---

## 12. Testing results

All commands below were **executed** on 16 November 2026 on the Windows/Laragon target machine.

### Final full regression — `verify-windows.bat` (`verification-logs/verify-20261116-181057.log`, `verify-summary.txt`)

| Step | Command | Result |
| --- | --- | --- |
| PHP `pdo_pgsql` loaded | `php -m` | ✅ PASS |
| Composer manifest | `composer validate --no-check-publish` | ✅ PASS |
| Migrations (dev DB, additive) | `php artisan migrate --force` | ✅ PASS — Phase 2 migration applied, no data loss |
| Seeder idempotency (dev DB) | `php artisan db:seed --force` | ✅ PASS — no duplicates |
| Routes | `php artisan route:list --except-vendor` | ✅ PASS (25 API routes) |
| Backend tests | `php artisan test` (database `amanet_test`) | ✅ **110 passed, 597 assertions** |
| Code style | `vendor/bin/pint --test` | ✅ PASS |
| Lint | `npm run lint` | ✅ PASS (0 problems) |
| Types | `npm run typecheck` (`next typegen && tsc --noEmit`) | ✅ PASS (0 errors) |
| Production build | `npm run build` | ✅ PASS (routes incl. `/matters/[id]`, `/clients/[id]`) |
| End-to-end | `npx playwright test` (own servers :8011/:3011, database `amanet_e2e` reset by global-setup) | ✅ **34 passed** (4.3 min) |

After a final one-line fix found in the manual check (§13 item 10), PHPUnit was run again: **110 passed**; Pint passed.

### Comparison with the Phase 1 baseline

| Suite | Phase 1 | Phase 2 | Phase 1 tests still passing |
| --- | --- | --- | --- |
| PHPUnit | 59 tests / 218 assertions | **110 tests / 597 assertions** (+51) | ✅ 59/59 — none removed, skipped or weakened |
| Playwright | 19 | **34** (+15) | ✅ 19/19 — the only change is reading the session cookie name and URLs from `e2e/env.ts` (isolated environment); assertions unchanged |
| ESLint / TypeScript / build | pass | pass | — |

### New backend tests (`tests/Feature/Phase2`)

| File | Tests | Covers |
| --- | --- | --- |
| `ClientManagementTest` | 12 | create (normalised email, history without matter), optional fields, invalid payloads (all fields), prohibited server fields, duplicate reference, details + counts, 404, update without duplicates (own reference allowed), delete unused, refuse delete with matters (409), search incl. phone, 401 for every client endpoint |
| `MatterManagementTest` | 21 | create + `LEX-2026-0001/0002`, numbering after existing numbers and per-year restart, **failed creation does not consume a number** (rollback), invalid/prohibited input, detail counters, edit + safe history diff (description not logged), status/number rejected in PATCH, **stale edit → 409**, 5 allowed transitions (data set), reopen clears `closed_at`, invalid transitions (422/409), archive preserves tasks/notes + read-only + archive filter + restore to previous status, closed → archived → closed keeps `closed_at`, dashboard counts, **time-zone-safe deadlines** (America/New_York), new filters/sorts, 401 for every matter endpoint |
| `MatterNumberConcurrencyTest` | 2 | **4 concurrent PHP processes × 25 numbers → 100 unique, gap-free**, DB unique constraint |
| `TaskManagementTest` | 9 | create (creator from session), validation + prohibited fields + nonexistent matter, complete/reopen with exact `completed_at`, edit history + stale 409, **cross-matter access → 404**, delete recorded, ordering + status filter, dashboard/matter counters, 401 |
| `NoteAndActivityTest` | 7 | note CRUD + stale 409 + events, **note bodies never in history**, validation/scoping/prohibited author, **notes private (401)** and absent from other payloads, timeline newest-first + pagination, **atomicity: 6 operations roll back when history fails**, append-only trigger |

### New end-to-end tests (`frontend/e2e`)

| Spec | Tests | Scenario |
| --- | --- | --- |
| `phase2-clients.spec.ts` | 4 | **A** create client (validation, success toast, list, detail, edit, persists after reload, no duplicate); server duplicate-reference error on the field; client with matters cannot be deleted (UI disabled + API 409); 404 page |
| `phase2-matters.spec.ts` | 3 | **B** create matter (unique number, dashboard +1), edit title/priority/deadline (persisted, history); status transitions (allowed options only, confirm close, history); 404 page |
| `phase2-tasks-notes.spec.ts` | 3 | **C** task create (double-click → one task), complete, reopen, dashboard deltas, reload; **D** note add/edit/persist, HTML shown as text (no script execution), not in history, 401 without session; **E** archive active matter (dashboard −1, archive filter, read-only), restore to In progress, history |
| `phase2-search.spec.ts` | 3 | **F** rapid status/priority/search changes keep all filters (URL + values), deadline + client filters, reload restores view; sorting + pagination + reset to page 1; navigation matter → client → dashboard |
| `phase2-mobile.spec.ts` | 2 | **G** (Pixel 7) no horizontal overflow on all matter tabs and client detail; dialog fits viewport; Escape closes; task created on phone; list quick action |

### Manual verification

`verification-logs/phase2-manual-check-20261116.log`: `start-windows.bat` (services reused, no duplicates), login, matter
created through the UI on the development database (`LEX-2026-0011`), task added and completed, note added, timeline
checked, matter archived, every API call 2xx after login, and all rows confirmed directly in PostgreSQL. The verification
matter is kept, archived, so the active totals are unchanged (7 active matters, 8 clients).

---

## 13. Bugs and fixes

| # | Issue | Root cause | Fix | Verified by |
| --- | --- | --- | --- | --- |
| 1 | Activity metadata assertions failed in PHPUnit | PostgreSQL `jsonb` normalises object key order | Order-insensitive assertions for metadata | full PHPUnit run |
| 2 | ESLint `react-hooks/refs` errors in two forms | `handleSubmit(callback)` was created during render with a callback that reads a ref | Call `handleSubmit` inside the submit event | lint + E2E |
| 3 | Edit forms could lose typed input | The reset effect depended on the record object, which background refetches (window focus) replace | Reset only when the dialog opens; submit the `lock_version` loaded at that moment | code review + E2E |
| 4 | Client select showed no selection when editing | Options load after the form resets, so the native select dropped the value | Re-apply the value once options arrive | E2E scenario B |
| 5 | Quick actions could send a stale `lock_version` | List rows refresh asynchronously after a change | Quick-actions dialog reads the matter's detail cache, which mutations update synchronously | E2E mobile quick action |
| 6 | Phase 2 E2E would break Phase 1 E2E and pollute dev data | Phase 1 specs assert seeded counts; Phase 2 specs create records; both ran on the developer's servers and `amanet` | Isolated E2E stack: own servers (:8011/:3011, `.next-e2e`), disposable `amanet_e2e` reset per run (guarded to `*_e2e` names), Phase 2 specs run after Phase 1 and assert deltas | 34/34 E2E |
| 7 | First E2E run timed out at login | Cold `next dev` compile of `/dashboard` in a fresh build directory exceeded the 5 s assertion timeout | Route warm-up in global-setup; 15 s expect timeout for dev-server E2E | E2E |
| 8 | E2E test "notes are private" saw 200 | Test bug: in `@playwright/test`, `browser.newContext()` inherits the project `storageState` (the admin session). The API itself returned 401 (checked with a raw request). | Explicitly empty storage state and an assertion that the context has no cookies | E2E scenario D |
| 9 | Strict-mode selector violations | Link names and labels also appear inside longer text | `exact: true` | E2E |
| 10 | History text "opened for Harborline Freight Ltd.." (manual check) | Description appended a period to client names that already end with one | Trim a trailing period before appending | PHPUnit 110/110 |

Before relying on environment overrides for the E2E API, I verified that `php artisan serve` passes `DB_DATABASE` through to its
worker. Pointing it at a nonexistent database produced 500, so the override is effective and E2E can never touch `amanet`.

---

## 14. Requirements compliance

Against the Amanet Full Stack Engineer assignment (as summarised in the Phase 1 report):

| Requirement | Status |
| --- | --- |
| Secure administrator access | ✅ Completed (Phase 1, preserved) |
| Centralised matter and client management | ✅ Completed — CRUD, detail pages, archive/restore, retention rules |
| Matter status, priority, important dates and deadlines | ✅ Completed — transition policy, priority control, opening date and deadline |
| Matter-specific tasks and internal notes | ✅ Completed — full CRUD, completion logic, confidential notes |
| Search and filtering across matters, clients and documents | 🟡 Partially — matters and clients complete; documents arrive in Phase 3 |
| Dated matter activity history | ✅ Completed — append-only, transactional, timeline UI |
| Track outstanding actions | ✅ Completed — outstanding/overdue task counters per matter and on the dashboard |
| Responsive, accessible premium UI | ✅ Completed for Phase 2 screens (desktop, tablet, mobile verified) |
| Automated tests | ✅ 110 PHPUnit, 34 Playwright |
| PDF upload/preview/versions | ⬜ Planned (Phase 3) |
| Secure client upload requests, review | ⬜ Planned (Phase 4) |
| Administrator and client PDF signing, tracking | ⬜ Planned (Phases 5–6) |
| Email invitations and notifications | ⬜ Planned (Phase 6) |
| No client accounts | ✅ Enforced |
| Fictional data only | ✅ (seed data; E2E data on its own database) |

---

## 15. Phase 3 readiness

**Ready.** Documents can attach to the Phase 2 foundation without refactoring:

- **PDF storage / private access:** matters have stable ids, policies (`view`/`update`) and an archived read-only rule that
  extends to documents. Laravel's local private disk and authorised streaming fit the existing controller → service → resource
  pattern.
- **Versioning:** the transactional service pattern, `lock_version` optimistic concurrency and the append-only history (with
  `document.uploaded` / `document.version_added` already catalogued) cover version creation and audit.
- **Authorised upload/download:** nested, scoped routes (`/matters/{matter}/documents/{document}`) follow the task/note precedent;
  Form Requests handle file validation (MIME, size, PDF magic bytes).
- **Preview:** the matter workspace tabs leave room for a "Documents" tab. The sidebar already reserves the section as
  disabled "Coming soon".
- **Search across documents:** the server-side, allow-listed filter pattern is in place.

Decisions to confirm for Phase 3: storage path layout, maximum file size, whether archived matters may still receive documents
(proposed: no, consistent with Phase 2), and checksum/duplicate policy.

---

## 16. Final completion checklist

**Client management**
- [x] Client creation works (UI + API + DB) · [x] editing works · [x] detail page works · [x] list uses real records
- [x] Search (name, email, phone, reference) and type filter work · [x] client–matter relationships remain valid (RESTRICT + retention rule)

**Matter management**
- [x] Creation works · [x] unique numbers generated safely (multi-process test) · [x] editing works · [x] detail page works
- [x] Status changes (policy enforced in Laravel) · [x] priority changes · [x] deadlines (calendar dates) · [x] archiving
- [x] Restore per documented rules · [x] archived matters preserved (tasks, notes, history)

**Tasks**
- [x] Create · [x] edit · [x] status changes · [x] correct completion dates · [x] accurate outstanding counts

**Internal notes**
- [x] Create · [x] edit · [x] stored correctly (author from session) · [x] administrators only (401 otherwise)

**Activity history**
- [x] Matter, task, note and status events recorded · [x] append-only (model + trigger) · [x] atomic with the change · [x] timeline UI

**Frontend**
- [x] Professional matter and client detail pages · [x] functional forms · [x] loading, error, empty states · [x] success feedback after API confirmation
- [x] Responsive desktop, tablet and mobile (E2E overflow and dialog checks) · [x] accessibility practices (labels, tabs, dialogs, live regions, focus)

**Backend**
- [x] Authentication · [x] authorisation (policies, scoped bindings) · [x] validation (Form Requests + service rules)
- [x] Transactions · [x] FKs and constraints valid (new CHECKs) · [x] no unnecessary sensitive data exposed

**Testing and delivery**
- [x] All Phase 1 tests pass (59 PHPUnit, 19 E2E) · [x] Phase 2 backend tests pass (51) · [x] Phase 2 E2E pass (15)
- [x] No TypeScript errors · [x] no ESLint errors · [x] production build succeeds
- [x] Application starts on Windows (`start-windows.bat`) · [x] manual check confirms database persistence
- [x] Development data preserved (additive migration only; E2E isolated in `amanet_e2e`)
- [x] Documentation: this report, README, architecture, data model, API conventions, phases

**Pending (later phases, not Phase 2 scope):** documents and versions (3), secure client requests (4), signing (5), email,
notifications and tracking (6), nonce-based CSP, accessibility audit and optional hosted demo (7).
Phase 2 follow-ups: searchable client picker for firms with more than 100 clients.
