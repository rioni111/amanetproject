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
| email | varchar(255) | indexed, **not unique** (deliberate — one contact address can serve several related clients) |
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
| closed_at | timestamptz null | `CHECK (status <> 'closed' OR closed_at IS NOT NULL)` |
| archived_at | timestamptz null | `CHECK (status <> 'archived' OR archived_at IS NOT NULL)` |
| created_at / updated_at | timestamptz | `updated_at` indexed (recent matters) |

Indexes: `(status, due_at)` serves the dashboard's active/upcoming/overdue queries; `priority`, `client_id`, `matter_type`.

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
| created_by | FK → users null | `SET NULL` |
| created_at / updated_at | timestamptz | |

Indexes: `(matter_id, status)`, `(status, due_at)`.

### `matter_notes` (internal)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| matter_id | FK → matters | `CASCADE` |
| author_id | FK → users null | `SET NULL` (note survives the author's account) |
| body | text | non-blank CHECK |
| created_at / updated_at | timestamptz | index `(matter_id, created_at)` |

Internal notes must **never** be exposed through unauthenticated client links (enforced by separate public controllers/resources in Phase 4).

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

## Seed data

`DemoWorkspaceSeeder` (development only, idempotent — keyed on `reference_number`, `matter_number`, `(matter_id, title)`,
`(matter_id, body)`): 1 admin (from `DEMO_ADMIN_*`), 8 clients, 10 matters (3 open, 3 in progress, 1 on hold, 2 closed, 1 archived;
4 deadlines within 14 days, 1 overdue), 15 tasks (11 open, 1 overdue), 6 notes and 42 activity events. Dates are relative to the
seeding time. Emails use reserved `example.*` domains and `555-01xx` phone numbers.

## Planned tables (not implemented)

| Table | Phase | Key columns & constraints |
| --- | --- | --- |
| `documents` | 3 | `matter_id` FK RESTRICT, `title`, `category`, `review_status` (`pending, approved, changes_requested`), `current_version_id`, `created_by` |
| `document_versions` | 3 | `document_id` FK, `version_number` (**unique per document**), `disk`, `path` (private, UUID name), `original_name`, `mime_type` (CHECK pdf), `size_bytes`, `sha256` (64 hex), `uploaded_by_type/id`, immutable (append-only trigger) |
| `document_requests` | 4 | `matter_id`, `client_id`, `title`, `instructions`, `status` (`open, submitted, changes_requested, accepted, cancelled`), `due_at`, `fulfilled_document_id` |
| `secure_access_links` | 4 | `token_hash` char(64) **unique** (SHA-256 of a 256-bit random token; plaintext never stored), `purpose` (`upload_document, sign_document`), `linkable_type/id` (the one resource it unlocks), `expires_at` NOT NULL, `revoked_at`, `max_uses`, `use_count`, `last_used_at`, `created_by` |
| `signing_requests` | 5 | `document_version_id` FK (the exact PDF signed), `matter_id`, **one signer** (`signer_name`, `signer_email`, optional `client_id`), `signer_type` (`admin, client`), `status` (`sent, viewed, started, signed, revoked, expired`), `sent_at, viewed_at, started_at, signed_at`, `signed_document_version_id` FK, `access_link_id`; partial unique index preventing two active requests for the same version |
| `signature_events` | 5–6 | `signing_request_id`, `event` (sent/viewed/started/signed/revoked), `occurred_at`, `ip_address`, `user_agent`, `metadata` jsonb — append-only |
| `notifications` | 6 | Laravel's notifications table (`notifiable` morph, `data` jsonb, `read_at`) for admin alerts |

The final signed PDF is a regular immutable `document_versions` row (with its SHA-256) referenced by
`signing_requests.signed_document_version_id`, so it is automatically associated with the matter and its history.
