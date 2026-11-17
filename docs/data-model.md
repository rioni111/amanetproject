# Data model

PostgreSQL 16. All timestamps are `timestamptz` stored in UTC. Enumerated columns are `varchar` + `CHECK` constraints generated
from the PHP enums in `backend/app/Enums`, so PHP and the database agree on valid values.

## Entity relationships (implemented)

```
users 1───* tasks.created_by           (nullable, ON DELETE SET NULL)
users 1───* matter_notes.author_id     (nullable, ON DELETE SET NULL)

clients 1───* matters                  (ON DELETE RESTRICT)
matters 1───* tasks                    (ON DELETE CASCADE)
matters 1───* matter_notes             (ON DELETE CASCADE)
matters 1───* activity_events          (nullable FK, ON DELETE RESTRICT)
activity_events *───1 subject          (polymorphic: subject_type alias + subject_id)
```

**Cardinality assumptions**

- A matter has exactly **one primary client** (assignment scope). Related parties/co-clients would be a `matter_parties` pivot later.
- A client may have many matters; a client with any matter cannot be deleted.
- Tasks and notes always belong to one matter.
- Activity events usually belong to a matter; `matter_id` is nullable for future workspace-level events.
- Matters are **closed/archived, never hard-deleted** once they have history: the `activity_events` FK uses `RESTRICT`, and every matter
  gets a `matter.created` event. `tasks`/`matter_notes` cascade only so that a brand-new matter without history can be removed.

## Tables

### `users` (administrators)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | varchar(255) | |
| email | varchar(255) **unique** | stored lower-cased (model mutator) → case-insensitive login |
| email_verified_at | timestamptz null | |
| password | varchar(255) | bcrypt hash (`hashed` cast) |
| role | varchar(20) default `admin` | `CHECK (role IN ('admin'))`, indexed |
| remember_token | varchar(100) null | "Keep me signed in" |
| created_at / updated_at | timestamptz | |

No client accounts exist. `sessions`, `cache`, `jobs`, `password_reset_tokens` and Sanctum's `personal_access_tokens` (unused — SPA
uses sessions; kept so a stray bearer token can never cause a 500) are Laravel framework tables.

### `clients`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | varchar(200) | `CHECK (length(btrim(name)) > 0)`, indexed |
| client_type | varchar(20) | `individual` \| `organization` (CHECK), indexed |
| email | varchar(255) **null** (Phase 2) | indexed, **not unique** (deliberate — one contact address can serve several related clients); optional so a client can be recorded before an address is known |
| phone | varchar(50) null | |
| address | text null | |
| reference_number | varchar(50) null **unique** | firm's client reference, e.g. `CL-1001` |
| created_at / updated_at | timestamptz | |

### `matters`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_number | varchar(32) **unique** | e.g. `LEX-2026-0001` |
| client_id | FK → clients | `RESTRICT`, indexed |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| matter_type | varchar(100) null | free text (Contract Review, Employment Dispute…), indexed |
| status | varchar(20) default `open` | `open, in_progress, on_hold, closed, archived` |
| priority | varchar(20) default `medium` | `low, medium, high, urgent` |
| opened_at | timestamptz | |
| due_at | timestamptz null | the matter deadline |
| closed_at | timestamptz null | `CHECK (status <> 'closed' OR closed_at IS NOT NULL)`; Phase 2: `CHECK (status IN ('closed','archived') OR closed_at IS NULL)` |
| archived_at | timestamptz null | `CHECK (status <> 'archived' OR archived_at IS NOT NULL)`; Phase 2: `CHECK (status = 'archived' OR archived_at IS NULL)` |
| archived_from_status | varchar(20) null (Phase 2) | status held before archiving (restore target); only set on archived matters (CHECK) |
| lock_version | integer default 1 (Phase 2) | optimistic concurrency, incremented on every update (`CHECK >= 1`) |
| created_at / updated_at | timestamptz | `updated_at` indexed (recent matters); Phase 2 indexes `created_at`, `opened_at` |

Indexes: `(status, due_at)` serves the dashboard's active/upcoming/overdue queries; `priority`, `client_id`, `matter_type`.

**Date semantics (Phase 2).** `opened_at` / `due_at` stay `timestamptz` (UTC). The API exchanges them as calendar dates in the
firm's time zone (`LEXORA_TIMEZONE`): a deadline date is stored as the **end** of that day, an opening date as its start
(`App\Support\LegalDate`). A matter is overdue only after its whole deadline day has passed, and dates never shift by a day
between server and browser time zones.

#### Matter lifecycle (Phase 2 transition policy)

```
            ┌──────────── in_progress ◄────────┐
            │      ▲            │              │
            ▼      │            ▼              │
  open ◄──────► on_hold      closed ──reopen──►(open | in_progress)
    │  ▲           │            ▲
    │  └───────────┘            │
    └──────────── any active ───┘
  any non-archived ──archive──► archived ──restore──► (status held before archiving)
```

| From | Allowed (status endpoint) |
| --- | --- |
| open | in_progress, on_hold, closed |
| in_progress | open, on_hold, closed |
| on_hold | open, in_progress, closed |
| closed | open, in_progress (reopen — `closed_at` cleared) |
| archived | none — use **restore** |

Archive is allowed from every non-archived status (also from active states, e.g. a matter opened in error); it keeps
`closed_at`, sets `archived_at` and `archived_from_status`. Restore returns to `archived_from_status` (legacy rows: closed if
`closed_at` is set, otherwise open). Archived matters are **read-only** (details, status, tasks, notes → 409). Matters are never
hard-deleted. Rules live in `App\Enums\MatterStatus::allowedTransitions()` and `App\Services\MatterService`; the database CHECKs
above guarantee the timestamps stay consistent even if application code were bypassed.

### `matter_number_counters` (Phase 2)

| Column | Type | Notes |
| --- | --- | --- |
| year | smallint PK | `CHECK (year BETWEEN 2000 AND 2999)` |
| last_value | integer | last number issued for that year (`CHECK >= 1`) |
| updated_at | timestamptz | |

`MatterNumberGenerator` issues `LEX-{year}-{0000}` with one atomic `INSERT … ON CONFLICT (year) DO UPDATE SET last_value =
GREATEST(last_value, highest existing number) + 1 RETURNING last_value`, executed **inside the transaction that creates the
matter**. The row lock serialises concurrent creators on that single row (no table lock), a rolled-back creation also rolls back
the counter (no gaps from failures), and `GREATEST` keeps the counter ahead of numbers inserted without it (e.g. the seeder).
`matters.matter_number` stays `UNIQUE` as the final guarantee. Verified with 4 concurrent PHP processes drawing 100 numbers.

### `tasks`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters | `CASCADE` |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| status | varchar(20) default `todo` | `todo, in_progress, completed` |
| priority | varchar(20) default `medium` | shared `Priority` enum |
| due_at | timestamptz null | |
| completed_at | timestamptz null | `CHECK ((status = 'completed') = (completed_at IS NOT NULL))` |
| created_by | FK → users null | `SET NULL`; set from the session, never from the request |
| lock_version | integer default 1 (Phase 2) | optimistic concurrency |
| created_at / updated_at | timestamptz | |

Indexes: `(matter_id, status)`, `(status, due_at)`.

Phase 2 behaviour: completing sets `completed_at` (now); reopening (to `todo`/`in_progress`) clears it — the CHECK above makes
any other combination impossible. Tasks may be deleted (operational items); the deletion is recorded as `task.deleted` with the
task's id and title.

### `matter_notes` (internal)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters | `CASCADE` |
| author_id | FK → users null | `SET NULL` (note survives the author's account) |
| body | text | non-blank CHECK |
| created_at / updated_at | timestamptz | index `(matter_id, created_at)` |

Internal notes must **never** be exposed through unauthenticated client links (enforced by separate public controllers/resources in Phase 4).

Phase 2: `lock_version` added. Note bodies are plain text, never copied into activity metadata or logs. **Deletion policy:**
a deleted note is hard-deleted (its confidential text is not retained) and a `note.deleted` event records the fact, actor and time.

### `activity_events` (append-only history)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters null | `RESTRICT` |
| actor_type | varchar(20) | `admin, client, system` (CHECK) |
| actor_id | bigint null | users.id for admins; a signer/request id for clients later (no FK — polymorphic by `actor_type`) |
| actor_label | varchar(200) null | display-name snapshot at event time |
| event_type | varchar(60) | e.g. `matter.created`, `task.completed`, later `signing.completed` — see `ActivityEventType` |
| subject_type / subject_id | varchar / bigint null | morph-map alias (`matter`, `task`, `matter_note`…) |
| description | varchar(500) | human-readable line for the timeline |
| metadata | jsonb null | structured details (e.g. `{from, to}` for status changes) |
| occurred_at | timestamptz | when it happened (may differ from insertion time) |
| created_at | timestamptz default now() | no `updated_at` |

Append-only is enforced twice: the Eloquent model throws on `updating`/`deleting`, and a PostgreSQL trigger
(`activity_events_no_update_delete`) raises on any `UPDATE`/`DELETE`. Indexes: `(matter_id, occurred_at)`, `event_type`, `occurred_at`.

Event types emitted from Phase 2 (always in the same transaction as the change): `matter.created`, `matter.updated`
(metadata `changes` with before/after for title, type, client, dates; description only as `changed: true`),
`matter.status_changed`, `matter.priority_changed`, `matter.archived`, `matter.restored`, `task.created`, `task.updated`,
`task.completed`, `task.reopened`, `task.deleted`, `note.added`, `note.updated`, `note.deleted`, and client events
`client.created`, `client.updated` (changed field names only), `client.deleted` with `matter_id = null`.

Phase 3 adds `document.uploaded`, `document.version_added` (metadata: document/version ids, version number, category, file
name, size, SHA-256 — never paths or content), `document.updated` (changed fields; description only as `changed: true`),
`document.archived`, `document.restored` and `document.downloaded` (audit of file access; inline previews are not recorded).
Catalogued for Phase 4–6: `document.requested`, `document.submitted`, `document.reviewed`, `document.correction_requested`,
`signing.requested|viewed|started|completed`, `link.revoked`.

### `documents` (Phase 3)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| uuid | uuid **unique** | storage directory name (server-generated) |
| matter_id | FK → matters | `RESTRICT` (documents are part of the legal record); index `(matter_id, status)` |
| title | varchar(255) | non-blank CHECK |
| description | text null | |
| category | varchar(30) | `contracts, agreements, correspondence, evidence, court_documents, identification, other` (CHECK), indexed |
| status | varchar(20) default `active` | lifecycle only: `active, archived` (CHECK); `CHECK ((status='archived') = (archived_at IS NOT NULL))` |
| origin | varchar(20) default `admin` | `admin, client` (CHECK) — server-assigned; `client` arrives with Phase 4 requests |
| current_version_id | bigint null | composite FK `(current_version_id, id) → document_versions (id, document_id)`: the current version always belongs to this document |
| latest_version_number | integer default 0 | counter incremented under the document row lock; `CHECK ((current_version_id IS NULL) = (latest_version_number = 0))` |
| uploaded_by | FK → users null | `SET NULL` |
| archived_at | timestamptz null | |
| lock_version | integer default 1 | optimistic concurrency for metadata edits |
| created_at / updated_at | timestamptz | both indexed (library sorts) |

Review status (Phase 4) and signing status (Phase 5) are deliberately **not** columns here: they belong to requests and signing
requests, which reference specific versions. A document never becomes "signed" because of how its PDF looks.

### `document_versions` (Phase 3, immutable)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | `UNIQUE (id, document_id)` — target of the composite current-version FK |
| uuid | uuid **unique** | stored file name |
| document_id | FK → documents | `RESTRICT` |
| version_number | integer | `CHECK >= 1`, **`UNIQUE (document_id, version_number)`** |
| kind | varchar(20) default `upload` | `upload, signed` (CHECK) — Phase 5 adds signed outputs as new versions |
| origin | varchar(20) | `admin, client` (CHECK) |
| original_filename | varchar(255) | sanitised client name (metadata only), non-blank, indexed for search |
| storage_disk | varchar(50) | `documents` |
| storage_path | varchar(500) **unique** | `CHECK (storage_path ~ '^[0-9]+/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$')` — only the server-generated layout, no traversal |
| mime_type | varchar(100) | `CHECK (mime_type = 'application/pdf')` |
| file_size | bigint | `CHECK > 0` |
| sha256_checksum | char(64) | `CHECK (~ '^[0-9a-f]{64}$')`, indexed (duplicate detection) |
| uploaded_by / uploaded_by_name | bigint null / varchar(200) | uploader snapshot **without FK** (an `ON DELETE SET NULL` would be an UPDATE, which immutability forbids) |
| created_at | timestamptz | no `updated_at` |

Immutability: trigger `document_versions_no_update_delete` rejects every `UPDATE`/`DELETE`; the model throws too. Versions are
only ever added. Phase 5 `signing_requests.document_version_id` can therefore reference the exact bytes that were signed, and the
signed result becomes a further version (`kind = signed`) without overwriting the source.
## Seed data

`DemoWorkspaceSeeder` (development only, idempotent — keyed on `reference_number`, `matter_number`, `(matter_id, title)`,
`(matter_id, body)`): 1 admin (from `DEMO_ADMIN_*`), 8 clients, 10 matters (3 open, 3 in progress, 1 on hold, 2 closed, 1 archived;
4 deadlines within 14 days, 1 overdue), 15 tasks (11 open, 1 overdue), 6 notes and 42 activity events. Dates are relative to the
seeding time. Emails use reserved `example.*` domains and `555-01xx` phone numbers.

## Planned tables (not implemented)

| Table | Phase | Key columns & constraints |
| --- | --- | --- |
| `document_requests` | 4 | `matter_id`, `client_id`, `title`, `instructions`, `status` (`open, submitted, changes_requested, accepted, cancelled`), `due_at`, `fulfilled_document_id` |
| `secure_access_links` | 4 | `token_hash` char(64) **unique** (SHA-256 of a 256-bit random token; plaintext never stored), `purpose` (`upload_document, sign_document`), `linkable_type/id` (the one resource it unlocks), `expires_at` NOT NULL, `revoked_at`, `max_uses`, `use_count`, `last_used_at`, `created_by` |
| `signing_requests` | 5 | `document_version_id` FK (the exact PDF signed), `matter_id`, **one signer** (`signer_name`, `signer_email`, optional `client_id`), `signer_type` (`admin, client`), `status` (`sent, viewed, started, signed, revoked, expired`), `sent_at, viewed_at, started_at, signed_at`, `signed_document_version_id` FK, `access_link_id`; partial unique index preventing two active requests for the same version |
| `signature_events` | 5–6 | `signing_request_id`, `event` (sent/viewed/started/signed/revoked), `occurred_at`, `ip_address`, `user_agent`, `metadata` jsonb — append-only |
| `notifications` | 6 | Laravel's notifications table (`notifiable` morph, `data` jsonb, `read_at`) for admin alerts |

The final signed PDF is a regular immutable `document_versions` row (with its SHA-256) referenced by
`signing_requests.signed_document_version_id`, so it is automatically associated with the matter and its history.
